<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use App\Models\StopTime;
use App\Services\DataQualityService;
use App\Services\RoadSnapperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ConsoleDataQualityController extends Controller
{
    public function __construct(private DataQualityService $qualityService) {}

    // ── Feature 23: Score ─────────────────────────────────────────────────────

    public function score(Request $request): JsonResponse
    {
        $force  = $request->boolean('refresh', false);
        $result = $this->qualityService->compute($force);

        return response()->json($result);
    }

    public function drillDown(Request $request): JsonResponse
    {
        $request->validate(['metric' => 'required|string']);

        $metric  = $request->input('metric');
        $results = $this->qualityService->drillDown($metric);

        return response()->json(['metric' => $metric, 'data' => $results]);
    }

    // ── Feature 27: Shape Inspector ───────────────────────────────────────────

    public function shapeInspector(Request $request): JsonResponse
    {
        $request->validate(['trip_id' => 'required|string|exists:trips,trip_id']);

        $tripId = $request->input('trip_id');

        $trip = DB::table('trips')
            ->where('trip_id', $tripId)
            ->select('trip_id', 'shape_id')
            ->first();

        if (!$trip || !$trip->shape_id) {
            return response()->json(['message' => 'Trip has no shape assigned.'], 422);
        }

        // Load shape points as [[lng, lat], ...]
        $geojson = DB::selectOne(
            "SELECT ST_AsGeoJSON(path) as geojson FROM shapes WHERE shape_id = ?",
            [$trip->shape_id]
        );

        if (!$geojson) {
            return response()->json(['message' => 'Shape geometry not found.'], 404);
        }

        $shapeCoords = json_decode($geojson->geojson, true)['coordinates'] ?? [];

        // Load stop times with stop locations
        $stopTimes = DB::table('stop_times')
            ->join('stops', 'stop_times.stop_id', '=', 'stops.id')
            ->where('stop_times.trip_id', $tripId)
            ->orderBy('stop_times.stop_sequence')
            ->selectRaw("
                stops.id as stop_id,
                stops.name as stop_name,
                ST_Y(stops.location::geometry) as lat,
                ST_X(stops.location::geometry) as lng,
                stop_times.stop_sequence
            ")
            ->get();

        $shapeLineWkt = DB::selectOne(
            "SELECT ST_AsText(path) as wkt FROM shapes WHERE shape_id = ?",
            [$trip->shape_id]
        )->wkt ?? null;

        // For each stop, compute distance to shape
        $stopGaps = [];
        foreach ($stopTimes as $st) {
            $gapRow = DB::selectOne("
                SELECT round(
                    ST_Distance(
                        ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                        (SELECT path::geography FROM shapes WHERE shape_id = ?)
                    )::numeric, 1
                ) as gap_m
            ", [$st->lng, $st->lat, $trip->shape_id]);

            $gapM = $gapRow ? (float) $gapRow->gap_m : 0.0;

            $stopGaps[] = [
                'stop_id'   => $st->stop_id,
                'stop_name' => $st->stop_name,
                'lat'       => (float) $st->lat,
                'lng'       => (float) $st->lng,
                'gap_m'     => $gapM,
                'flagged'   => $gapM > 100,
            ];
        }

        // Detect teleports: consecutive shape points > 500m apart
        $teleports = [];
        for ($i = 0; $i < count($shapeCoords) - 1; $i++) {
            [$lng1, $lat1] = $shapeCoords[$i];
            [$lng2, $lat2] = $shapeCoords[$i + 1];
            $dist = $this->haversineMeters((float) $lat1, (float) $lng1, (float) $lat2, (float) $lng2);
            if ($dist > 500) {
                $teleports[] = [
                    'from_idx'   => $i,
                    'to_idx'     => $i + 1,
                    'distance_m' => round($dist, 1),
                ];
            }
        }

        // Detect reversals: bearing change > 120° between 3 consecutive points
        $reversals = [];
        for ($i = 1; $i < count($shapeCoords) - 1; $i++) {
            [$lngA, $latA] = $shapeCoords[$i - 1];
            [$lngB, $latB] = $shapeCoords[$i];
            [$lngC, $latC] = $shapeCoords[$i + 1];

            $bearing1 = $this->bearing((float) $latA, (float) $lngA, (float) $latB, (float) $lngB);
            $bearing2 = $this->bearing((float) $latB, (float) $lngB, (float) $latC, (float) $lngC);
            $change   = abs(fmod(abs($bearing2 - $bearing1), 360));
            if ($change > 180) {
                $change = 360 - $change;
            }

            if ($change > 120) {
                $reversals[] = [
                    'point_idx'        => $i,
                    'bearing_change_deg' => round($change, 1),
                ];
            }
        }

        $flaggedStops = count(array_filter($stopGaps, fn ($s) => $s['flagged']));
        $maxGap       = $flaggedStops > 0 ? max(array_column($stopGaps, 'gap_m')) : 0.0;

        return response()->json([
            'trip_id'             => $tripId,
            'shape_id'            => $trip->shape_id,
            'stop_gaps'           => $stopGaps,
            'teleports'           => $teleports,
            'reversals'           => $reversals,
            'max_gap_m'           => $maxGap,
            'flagged_stops_count' => $flaggedStops,
        ]);
    }

    // ── Feature 28: Duplicate Stops ───────────────────────────────────────────

    public function duplicateStops(Request $request): JsonResponse
    {
        $radius = (int) $request->input('radius', 50);
        $radius = min(max($radius, 10), 500);

        $pairs = $this->qualityService->drillDuplicateStops($radius);

        return response()->json(['pairs' => $pairs, 'radius_m' => $radius]);
    }

    public function mergeStops(Request $request): JsonResponse
    {
        $data = $request->validate([
            'canonical_id'  => 'required|string|exists:stops,id',
            'duplicate_id'  => 'required|string|exists:stops,id|different:canonical_id',
        ]);

        $stopTimesCount   = 0;
        $patternStopsCount = 0;

        DB::transaction(function () use ($data, &$stopTimesCount, &$patternStopsCount) {
            $stopTimesCount    = StopTime::where('stop_id', $data['duplicate_id'])
                ->update(['stop_id' => $data['canonical_id']]);
            $patternStopsCount = RoutePatternStop::where('stop_id', $data['duplicate_id'])
                ->update(['stop_id' => $data['canonical_id']]);
            Stop::findOrFail($data['duplicate_id'])->delete();
        });

        // Bust quality cache so the duplicate count refreshes
        Cache::forget('quality:score');

        return response()->json([
            'message'                   => 'Stops merged.',
            'stop_times_redirected'     => $stopTimesCount,
            'pattern_stops_redirected'  => $patternStopsCount,
        ]);
    }

    // ── Feature 26: Snap Stop ─────────────────────────────────────────────────

    public function snapStop(Request $request, string $id): JsonResponse
    {
        $stop = Stop::findOrFail($id);

        $result = RoadSnapperService::make()->snap($stop->lat, $stop->lng);

        if ($result['snapped']) {
            DB::statement(
                "UPDATE stops SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326), updated_at = NOW() WHERE id = ?",
                [$result['snapped_lng'], $result['snapped_lat'], $id]
            );
        }

        return response()->json($result);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R  = 6371000;
        $φ1 = deg2rad($lat1);
        $φ2 = deg2rad($lat2);
        $Δφ = deg2rad($lat2 - $lat1);
        $Δλ = deg2rad($lng2 - $lng1);
        $a  = sin($Δφ / 2) ** 2 + cos($φ1) * cos($φ2) * sin($Δλ / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function bearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $φ1 = deg2rad($lat1);
        $φ2 = deg2rad($lat2);
        $Δλ = deg2rad($lng2 - $lng1);
        $x  = sin($Δλ) * cos($φ2);
        $y  = cos($φ1) * sin($φ2) - sin($φ1) * cos($φ2) * cos($Δλ);
        return fmod(rad2deg(atan2($x, $y)) + 360, 360);
    }
}
