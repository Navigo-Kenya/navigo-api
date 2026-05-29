<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DataQualityService
{
    private const CACHE_KEY = 'quality:score';
    private const CACHE_TTL = 3600; // 1 hour

    public function compute(bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = Cache::get(self::CACHE_KEY);
            if ($cached) {
                return $cached;
            }
        }

        $metrics = [
            $this->metricRoutesWithShapes(),
            $this->metricTripsWithFullStopTimes(),
            $this->metricStopsWithinBounds(),
            $this->metricValidServiceRefs(),
            $this->metricOrphanShapes(),
            $this->metricDuplicateStopPairs(),
        ];

        // Weighted average of metrics 1–4 (positive metrics)
        $positiveMetrics = array_slice($metrics, 0, 4);
        $weightedSum = array_sum(array_column($positiveMetrics, 'score'));
        $base = count($positiveMetrics) > 0 ? $weightedSum / count($positiveMetrics) : 100;

        // Penalties from inverse metrics
        $orphanPenalty    = $metrics[4]['value'] * 0.5;
        $duplicatePenalty = $metrics[5]['value'] * 1.0;

        $overall = max(0, min(100, round($base - $orphanPenalty - $duplicatePenalty, 1)));

        $result = [
            'overall'     => $overall,
            'computed_at' => now()->toIso8601String(),
            'metrics'     => $metrics,
        ];

        Cache::put(self::CACHE_KEY, $result, self::CACHE_TTL);

        return $result;
    }

    public function drillDown(string $metric): array
    {
        return match ($metric) {
            'routes_with_shapes'         => $this->drillRoutesWithoutShapes(),
            'trips_with_full_stop_times' => $this->drillTripsWithoutStopTimes(),
            'stops_within_bounds'        => $this->drillStopsOutOfBounds(),
            'valid_service_refs'         => $this->drillTripsWithInvalidServiceRefs(),
            'orphan_shapes'              => $this->drillOrphanShapes(),
            'duplicate_stop_pairs'       => $this->drillDuplicateStops(50),
            default                      => [],
        };
    }

    public function drillDuplicateStops(int $radiusMeters = 50): array
    {
        $rows = DB::select("
            SELECT
                a.id          AS stop_a_id,
                a.name        AS stop_a_name,
                ST_Y(a.location::geometry) AS stop_a_lat,
                ST_X(a.location::geometry) AS stop_a_lng,
                b.id          AS stop_b_id,
                b.name        AS stop_b_name,
                ST_Y(b.location::geometry) AS stop_b_lat,
                ST_X(b.location::geometry) AS stop_b_lng,
                round(ST_Distance(a.location::geography, b.location::geography)::numeric, 1) AS distance_m,
                round(similarity(a.name, b.name)::numeric, 2) AS name_similarity
            FROM stops a
            JOIN stops b ON a.id < b.id
            WHERE ST_DWithin(a.location::geography, b.location::geography, ?)
            ORDER BY distance_m ASC
            LIMIT 200
        ", [$radiusMeters]);

        return array_map(fn($r) => [
            'stop_a' => [
                'id'  => $r->stop_a_id,
                'name'=> $r->stop_a_name,
                'lat' => (float) $r->stop_a_lat,
                'lng' => (float) $r->stop_a_lng,
            ],
            'stop_b' => [
                'id'  => $r->stop_b_id,
                'name'=> $r->stop_b_name,
                'lat' => (float) $r->stop_b_lat,
                'lng' => (float) $r->stop_b_lng,
            ],
            'distance_m'       => (float) $r->distance_m,
            'name_similarity'  => (float) $r->name_similarity,
        ], $rows);
    }

    // ── Positive metrics ──────────────────────────────────────────────────────

    private function metricRoutesWithShapes(): array
    {
        $total = (int) DB::table('routes')->count();
        $with  = (int) DB::table('routes')
            ->whereExists(fn ($q) => $q->from('trips')
                ->whereColumn('trips.route_id', 'routes.route_id')
                ->whereNotNull('trips.shape_id'))
            ->count();

        return $this->positiveMetric('routes_with_shapes', 'Routes with shapes', $with, $total);
    }

    private function metricTripsWithFullStopTimes(): array
    {
        $total = (int) DB::table('trips')
            ->where('scheduling_type', 'scheduled')
            ->orWhereNull('scheduling_type')
            ->count();

        $with = (int) DB::table('trips')
            ->leftJoin(DB::raw('(SELECT trip_id, COUNT(*) as cnt FROM stop_times GROUP BY trip_id) st'), 'trips.trip_id', '=', 'st.trip_id')
            ->where(fn ($q) => $q->where('trips.scheduling_type', 'scheduled')->orWhereNull('trips.scheduling_type'))
            ->where('st.cnt', '>=', 2)
            ->count();

        return $this->positiveMetric('trips_with_full_stop_times', 'Trips with stop times', $with, $total);
    }

    private function metricStopsWithinBounds(): array
    {
        $total    = (int) DB::table('stops')->count();
        $inBounds = (int) DB::table('stops')
            ->whereRaw("location && ST_MakeEnvelope(33.9, -4.7, 41.9, 5.0, 4326)")
            ->count();

        return $this->positiveMetric('stops_within_bounds', 'Stops within bounds', $inBounds, $total);
    }

    private function metricValidServiceRefs(): array
    {
        $total = (int) DB::table('trips')->whereNotNull('service_id')->count();

        $valid = (int) DB::table('trips')
            ->whereNotNull('service_id')
            ->whereExists(fn ($q) => $q->from('service_calendars')
                ->whereColumn('service_calendars.service_id', 'trips.service_id'))
            ->count();

        return $this->positiveMetric('valid_service_refs', 'Valid service refs', $valid, $total);
    }

    // ── Inverse metrics (lower = better) ─────────────────────────────────────

    private function metricOrphanShapes(): array
    {
        $count = (int) DB::table('shapes')
            ->leftJoin('trips', 'shapes.shape_id', '=', 'trips.shape_id')
            ->whereNull('trips.trip_id')
            ->count();

        return [
            'key'     => 'orphan_shapes',
            'label'   => 'Orphan shapes',
            'value'   => $count,
            'total'   => (int) DB::table('shapes')->count(),
            'score'   => 0,
            'inverse' => true,
        ];
    }

    private function metricDuplicateStopPairs(): array
    {
        $count = (int) DB::selectOne("
            SELECT COUNT(*) as cnt
            FROM stops a
            JOIN stops b ON a.id < b.id
            WHERE ST_DWithin(a.location::geography, b.location::geography, 50)
        ")->cnt;

        return [
            'key'     => 'duplicate_stop_pairs',
            'label'   => 'Duplicate stop pairs',
            'value'   => $count,
            'total'   => (int) DB::table('stops')->count(),
            'score'   => 0,
            'inverse' => true,
        ];
    }

    // ── Drill-down queries ────────────────────────────────────────────────────

    private function drillRoutesWithoutShapes(): array
    {
        return DB::table('routes')
            ->whereNotExists(fn ($q) => $q->from('trips')
                ->whereColumn('trips.route_id', 'routes.route_id')
                ->whereNotNull('trips.shape_id'))
            ->select('route_id', 'route_short_name', 'route_long_name')
            ->get()
            ->toArray();
    }

    private function drillTripsWithoutStopTimes(): array
    {
        return DB::table('trips')
            ->where(fn ($q) => $q->where('scheduling_type', 'scheduled')->orWhereNull('scheduling_type'))
            ->leftJoin(DB::raw('(SELECT trip_id, COUNT(*) as cnt FROM stop_times GROUP BY trip_id) st'), 'trips.trip_id', '=', 'st.trip_id')
            ->where(fn ($q) => $q->where('st.cnt', '<', 2)->orWhereNull('st.cnt'))
            ->select('trips.trip_id', 'trips.route_id', 'trips.trip_headsign', DB::raw('COALESCE(st.cnt, 0) as stop_times_count'))
            ->limit(100)
            ->get()
            ->toArray();
    }

    private function drillStopsOutOfBounds(): array
    {
        return DB::table('stops')
            ->whereRaw("NOT (location && ST_MakeEnvelope(33.9, -4.7, 41.9, 5.0, 4326))")
            ->selectRaw("id, name, ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng")
            ->limit(100)
            ->get()
            ->toArray();
    }

    private function drillTripsWithInvalidServiceRefs(): array
    {
        return DB::table('trips')
            ->whereNotNull('service_id')
            ->whereNotExists(fn ($q) => $q->from('service_calendars')
                ->whereColumn('service_calendars.service_id', 'trips.service_id'))
            ->select('trip_id', 'route_id', 'service_id', 'trip_headsign')
            ->limit(100)
            ->get()
            ->toArray();
    }

    private function drillOrphanShapes(): array
    {
        return DB::table('shapes')
            ->leftJoin('trips', 'shapes.shape_id', '=', 'trips.shape_id')
            ->whereNull('trips.trip_id')
            ->select('shapes.shape_id')
            ->limit(100)
            ->get()
            ->toArray();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function positiveMetric(string $key, string $label, int $value, int $total): array
    {
        $score = $total > 0 ? round(($value / $total) * 100, 1) : 100.0;

        return [
            'key'     => $key,
            'label'   => $label,
            'value'   => $value,
            'total'   => $total,
            'score'   => $score,
            'inverse' => false,
        ];
    }
}
