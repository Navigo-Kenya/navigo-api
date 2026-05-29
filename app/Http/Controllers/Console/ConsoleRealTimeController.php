<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\OnTimePerformance;
use App\Models\Trip;
use App\Models\VehiclePosition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsoleRealTimeController extends Controller
{
    // ── Live Positions ────────────────────────────────────────────────────────

    public function livePositions(Request $request): JsonResponse
    {
        // Latest position per vehicle — subquery to pick MAX(recorded_at) per vehicle
        $subquery = VehiclePosition::select('vehicle_id', DB::raw('MAX(recorded_at) as latest'))
            ->groupBy('vehicle_id');

        $positions = VehiclePosition::joinSub($subquery, 'latest', function ($join) {
                $join->on('vehicle_positions.vehicle_id', '=', 'latest.vehicle_id')
                     ->on('vehicle_positions.recorded_at', '=', 'latest.latest');
            })
            ->with(['vehicle:id,plate,agency_id,route_id,status'])
            ->when($request->filled('route_id'), fn ($q) => $q->where('vehicle_positions.trip_id', 'like', $request->route_id.'%'))
            ->get();

        $ghost = $this->resolveGhostTrips($positions->pluck('trip_id')->filter()->values()->toArray());

        return response()->json([
            'positions'            => $positions,
            'ghost_trips'          => $ghost,
            'active_vehicle_count' => $positions->count(),
        ]);
    }

    public function ghostTrips(): JsonResponse
    {
        $recentTripIds = VehiclePosition::where('recorded_at', '>=', now()->subMinutes(10))
            ->distinct()
            ->pluck('trip_id')
            ->filter()
            ->values();

        $ghost = $this->resolveGhostTrips($recentTripIds->toArray());
        return response()->json($ghost);
    }

    public function liveStats(): JsonResponse
    {
        $activeCount = VehiclePosition::select('vehicle_id')
            ->where('recorded_at', '>=', now()->subMinutes(10))
            ->distinct()
            ->count('vehicle_id');

        $today = OnTimePerformance::where('date', today())
            ->selectRaw('AVG(avg_delay_s) as avg_delay, AVG(CASE WHEN total_trips > 0 THEN on_time_trips::float / total_trips ELSE 0 END) as on_time_pct')
            ->first();

        return response()->json([
            'active_vehicles' => $activeCount,
            'avg_delay_s'     => round($today->avg_delay ?? 0),
            'on_time_pct'     => round(($today->on_time_pct ?? 0) * 100, 1),
        ]);
    }

    // ── Delay / On-Time Performance ───────────────────────────────────────────

    public function delayDashboard(Request $request): JsonResponse
    {
        $days = match ($request->input('period', '7d')) {
            '30d' => 30,
            '90d' => 90,
            default => 7,
        };

        $summary = OnTimePerformance::where('date', '>=', now()->subDays($days))
            ->selectRaw('
                SUM(total_trips) as total_trips,
                SUM(on_time_trips) as on_time_trips,
                AVG(avg_delay_s) as avg_delay_s
            ')
            ->first();

        $onTimePct = $summary->total_trips > 0
            ? round(($summary->on_time_trips / $summary->total_trips) * 100, 1)
            : 0;

        // Worst routes — add sparkline (last 7 days, daily on-time %)
        $worst = OnTimePerformance::where('date', '>=', now()->subDays($days))
            ->groupBy('route_id')
            ->selectRaw('route_id, SUM(total_trips) as total_trips, SUM(on_time_trips) as on_time_trips, AVG(avg_delay_s) as avg_delay_s')
            ->having('total_trips', '>', 0)
            ->orderByRaw('AVG(avg_delay_s) DESC')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                $sparkline = OnTimePerformance::where('route_id', $row->route_id)
                    ->where('date', '>=', now()->subDays(7))
                    ->orderBy('date')
                    ->pluck('avg_delay_s')
                    ->toArray();

                return [
                    'route_id'       => $row->route_id,
                    'avg_delay_s'    => round($row->avg_delay_s),
                    'on_time_pct'    => $row->total_trips > 0 ? round(($row->on_time_trips / $row->total_trips) * 100, 1) : 0,
                    'sparkline'      => $sparkline,
                ];
            });

        return response()->json([
            'on_time_pct'   => $onTimePct,
            'avg_delay_s'   => round($summary->avg_delay_s ?? 0),
            'trips_tracked' => (int) ($summary->total_trips ?? 0),
            'worst_routes'  => $worst,
        ]);
    }

    public function delayHeatmap(): JsonResponse
    {
        // 7×24 grid using on_time_performance + trip_updates for current 90-day window
        $cells = DB::table('trip_updates')
            ->where('recorded_at', '>=', now()->subDays(90))
            ->selectRaw("
                EXTRACT(DOW FROM recorded_at)::int  AS day_of_week,
                EXTRACT(HOUR FROM recorded_at)::int AS hour_of_day,
                AVG(delay_seconds)::int             AS avg_delay_s,
                COUNT(*)                            AS sample_count
            ")
            ->groupByRaw('day_of_week, hour_of_day')
            ->orderBy('day_of_week')
            ->orderBy('hour_of_day')
            ->get();

        return response()->json($cells);
    }

    public function worstRoutes(): JsonResponse
    {
        $routes = OnTimePerformance::where('date', '>=', now()->subDays(7))
            ->groupBy('route_id')
            ->selectRaw('route_id, AVG(avg_delay_s) as avg_delay_s, SUM(total_trips) as total_trips')
            ->having('total_trips', '>', 0)
            ->orderByRaw('AVG(avg_delay_s) DESC')
            ->limit(5)
            ->get();

        return response()->json($routes);
    }

    // ── Position History & Playback ───────────────────────────────────────────

    public function positionHistory(Request $request): JsonResponse
    {
        $request->validate([
            'vehicle_id' => 'required|integer|exists:vehicles,id',
            'date'       => 'required|date',
        ]);

        $positions = VehiclePosition::where('vehicle_id', $request->vehicle_id)
            ->whereDate('recorded_at', $request->date)
            ->orderBy('recorded_at')
            ->get(['id', 'lat', 'lng', 'bearing', 'speed_kmh', 'trip_id', 'recorded_at']);

        return response()->json($positions);
    }

    public function availableDates(int $vehicleId): JsonResponse
    {
        $dates = VehiclePosition::where('vehicle_id', $vehicleId)
            ->selectRaw("DATE(recorded_at) as date")
            ->groupByRaw("DATE(recorded_at)")
            ->orderByRaw("DATE(recorded_at) DESC")
            ->limit(30)
            ->pluck('date');

        return response()->json($dates);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resolveGhostTrips(array $activeTripIds): array
    {
        // Find trips that are "active now" per schedule but have no recent position
        $now = now();
        $ghostQuery = Trip::with(['route:route_id,route_short_name,route_color'])
            ->whereNotIn('trip_id', $activeTripIds)
            ->limit(50);

        // Ideally join with stop_times to check schedule — simplified here
        return $ghostQuery->get()->map(function (Trip $trip) {
            return [
                'trip_id'         => $trip->trip_id,
                'headsign'        => $trip->trip_headsign,
                'route_id'        => $trip->route_id,
                'route_short_name'=> $trip->route?->route_short_name,
                'route_color'     => $trip->route?->route_color,
                // first stop coords omitted without stop_times join — frontend uses route shape fallback
                'first_stop_lat'  => null,
                'first_stop_lng'  => null,
            ];
        })->toArray();
    }
}
