<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\StopTime;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsoleSchedulingController extends Controller
{
    // ── Helper ────────────────────────────────────────────────────────────────

    private function timeToMins(string $time): int
    {
        [$h, $m, $s] = explode(':', $time);
        return (int)$h * 60 + (int)$m + (int)round((int)$s / 60);
    }

    private function minsToTime(int $mins): string
    {
        $h = intdiv($mins, 60);
        $m = $mins % 60;
        return sprintf('%02d:%02d:00', $h, $m);
    }

    // ── Feature 11: Timetable ────────────────────────────────────────────────

    public function timetable(Request $request, string $routeId): JsonResponse
    {
        $route = Route::findOrFail($routeId);
        $directionId = (int) $request->input('direction_id', 0);

        // Canonical stop sequence from route pattern
        $pattern = RoutePattern::where('route_id', $route->route_id)
            ->where('direction_id', $directionId)
            ->where('is_canonical', true)
            ->with(['patternStops' => fn ($q) => $q->with('stop:id,name')])
            ->first();

        if ($pattern && $pattern->patternStops->isNotEmpty()) {
            $stops = $pattern->patternStops->map(fn ($ps) => [
                'stop_id'       => $ps->stop_id,
                'stop_name'     => $ps->stop->name ?? $ps->stop_id,
                'stop_sequence' => $ps->stop_sequence,
            ])->values()->toArray();
        } else {
            // Fallback: derive stop order from existing stop_times
            $stops = DB::select("
                SELECT st.stop_id, s.name as stop_name, MIN(st.stop_sequence) as stop_sequence
                FROM stop_times st
                JOIN trips t ON t.trip_id = st.trip_id
                JOIN stops s ON s.id = st.stop_id
                WHERE t.route_id = ? AND t.direction_id = ?
                GROUP BY st.stop_id, s.name
                ORDER BY stop_sequence ASC
            ", [$route->route_id, $directionId]);

            $stops = array_map(fn ($r) => [
                'stop_id'       => $r->stop_id,
                'stop_name'     => $r->stop_name,
                'stop_sequence' => (int) $r->stop_sequence,
            ], $stops);
        }

        // Load trips ordered by their first departure time
        $trips = Trip::where('route_id', $route->route_id)
            ->where('direction_id', $directionId)
            ->with(['stopTimes' => fn ($q) => $q->orderBy('stop_sequence')])
            ->get()
            ->sortBy(fn ($trip) => $trip->stopTimes->first()?->departure_time ?? '99:99:99')
            ->values();

        $tripData = $trips->map(function ($trip) {
            $times = [];
            foreach ($trip->stopTimes as $st) {
                $times[$st->stop_id] = $st->departure_time;
            }
            return [
                'trip_id'         => $trip->trip_id,
                'trip_headsign'   => $trip->trip_headsign,
                'direction_id'    => $trip->direction_id,
                'service_id'      => $trip->service_id,
                'scheduling_type' => $trip->scheduling_type,
                'stop_times_count'=> count($times),
                'times'           => $times,
            ];
        })->values()->toArray();

        return response()->json([
            'stops' => $stops,
            'trips' => $tripData,
        ]);
    }

    public function saveTimetable(Request $request, string $routeId): JsonResponse
    {
        $route = Route::findOrFail($routeId);

        $data = $request->validate([
            'trips'              => 'required|array',
            'trips.*.trip_id'    => 'required|string',
            'trips.*.times'      => 'required|array',
        ]);

        // Validate trip ownership and time format
        $tripIds    = array_column($data['trips'], 'trip_id');
        $validTrips = Trip::where('route_id', $route->route_id)
            ->whereIn('trip_id', $tripIds)
            ->pluck('trip_id')
            ->toArray();

        $invalid = array_diff($tripIds, $validTrips);
        if (!empty($invalid)) {
            return response()->json([
                'message' => 'Some trip_ids do not belong to this route.',
                'invalid' => array_values($invalid),
            ], 422);
        }

        $timePattern = '/^\d{1,2}:\d{2}:\d{2}$/';
        foreach ($data['trips'] as $tripRow) {
            foreach ($tripRow['times'] as $time) {
                if ($time !== null && !preg_match($timePattern, $time)) {
                    return response()->json(['message' => "Invalid time format: {$time}"], 422);
                }
            }
        }

        // Get stop sequences from stop_times or pattern for ordering
        $stopSequences = DB::table('stop_times')
            ->whereIn('trip_id', $tripIds)
            ->groupBy('stop_id')
            ->select('stop_id', DB::raw('MIN(stop_sequence) as stop_sequence'))
            ->pluck('stop_sequence', 'stop_id')
            ->toArray();

        $stopTimesCount = 0;

        DB::transaction(function () use ($data, $stopSequences, &$stopTimesCount) {
            foreach ($data['trips'] as $tripRow) {
                $tripId = $tripRow['trip_id'];
                StopTime::where('trip_id', $tripId)->delete();

                $rows = [];
                $seq  = 1;
                foreach ($tripRow['times'] as $stopId => $time) {
                    if ($time === null || $time === '') {
                        continue;
                    }
                    $rows[] = [
                        'trip_id'        => $tripId,
                        'stop_id'        => $stopId,
                        'stop_sequence'  => $stopSequences[$stopId] ?? $seq,
                        'arrival_time'   => $time,
                        'departure_time' => $time,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ];
                    $seq++;
                }

                if (!empty($rows)) {
                    // Sort by stop_sequence before inserting
                    usort($rows, fn ($a, $b) => $a['stop_sequence'] <=> $b['stop_sequence']);
                    // Re-assign sequential stop_sequence values
                    foreach ($rows as $i => &$row) {
                        $row['stop_sequence'] = $i + 1;
                    }
                    unset($row);
                    StopTime::insert($rows);
                    $stopTimesCount += count($rows);
                }
            }
        });

        return response()->json([
            'message'          => 'Timetable saved.',
            'trip_count'       => count($data['trips']),
            'stop_time_count'  => $stopTimesCount,
        ]);
    }

    // ── Feature 12: Headway Optimizer ────────────────────────────────────────

    public function optimizeHeadway(Request $request): JsonResponse
    {
        $data = $request->validate([
            'base_trip_id'          => 'required|string|exists:trips,trip_id',
            'layover_mins'          => 'required|integer|min:1|max:120',
            'windows'               => 'required|array|min:1',
            'windows.*.start'       => ['required', 'string', 'regex:/^\d{2}:\d{2}:\d{2}$/'],
            'windows.*.end'         => ['required', 'string', 'regex:/^\d{2}:\d{2}:\d{2}$/'],
            'windows.*.headway_mins'=> 'required|integer|min:1|max:120',
        ]);

        $baseTimes = StopTime::where('trip_id', $data['base_trip_id'])
            ->orderBy('stop_sequence')
            ->get();

        if ($baseTimes->isEmpty()) {
            return response()->json(['message' => 'Base trip has no stop times.'], 422);
        }

        $firstDepartureMins = $this->timeToMins($baseTimes->first()->departure_time);
        $lastArrivalMins    = $this->timeToMins($baseTimes->last()->arrival_time);
        $oneWayMins         = max(1, $lastArrivalMins - $firstDepartureMins);
        $cycleMins          = $oneWayMins * 2 + (int) $data['layover_mins'];

        $peakHeadway = min(array_column($data['windows'], 'headway_mins'));
        $fleetSize   = (int) ceil($cycleMins / $peakHeadway);

        $generatedTrips = [];
        foreach ($data['windows'] as $window) {
            $cursor  = $this->timeToMins($window['start']);
            $end     = $this->timeToMins($window['end']);
            $headway = (int) $window['headway_mins'];
            $label   = $headway <= 8 ? 'peak' : ($headway <= 15 ? 'shoulder' : 'off-peak');

            while ($cursor < $end) {
                $generatedTrips[] = [
                    'departure'    => $this->minsToTime($cursor),
                    'window_label' => $label,
                    'headway_mins' => $headway,
                ];
                $cursor += $headway;
            }
        }

        $totalTrips    = count($generatedTrips);
        $vehicleHours  = round($totalTrips * $oneWayMins / 60, 1);

        return response()->json([
            'fleet_size'       => $fleetSize,
            'total_trips'      => $totalTrips,
            'vehicle_hours'    => $vehicleHours,
            'one_way_mins'     => $oneWayMins,
            'cycle_mins'       => $cycleMins,
            'generated_trips'  => $generatedTrips,
        ]);
    }

    // ── Feature 14: Layover Analysis ─────────────────────────────────────────

    public function layoverAnalysis(Request $request): JsonResponse
    {
        $request->validate([
            'route_id'    => 'required|string|exists:routes,route_id',
            'direction_id'=> 'nullable|integer|in:0,1',
        ]);

        $routeId     = $request->input('route_id');
        $directionId = $request->filled('direction_id') ? (int)$request->input('direction_id') : 0;

        // Load trips with first + last stop times via subquery
        $trips = Trip::where('route_id', $routeId)
            ->where('direction_id', $directionId)
            ->where('scheduling_type', 'scheduled')
            ->with(['stopTimes' => fn ($q) => $q->orderBy('stop_sequence')])
            ->get()
            ->filter(fn ($t) => $t->stopTimes->isNotEmpty())
            ->sortBy(fn ($t) => $t->stopTimes->first()->departure_time)
            ->values();

        $rows        = [];
        $minRecovery = null;

        foreach ($trips as $i => $trip) {
            $firstDep  = $trip->stopTimes->first()->departure_time;
            $lastArr   = $trip->stopTimes->last()->arrival_time;
            $duration  = $this->timeToMins($lastArr) - $this->timeToMins($firstDep);

            $recovery = null;
            if ($i < $trips->count() - 1) {
                $nextTrip    = $trips[$i + 1];
                $nextFirstDep = $nextTrip->stopTimes->first()->departure_time;
                $recovery = $this->timeToMins($nextFirstDep) - $this->timeToMins($lastArr);

                if ($minRecovery === null || $recovery < $minRecovery) {
                    $minRecovery = $recovery;
                }
            }

            $rows[] = [
                'trip_id'        => $trip->trip_id,
                'first_departure'=> $firstDep,
                'last_arrival'   => $lastArr,
                'duration_mins'  => $duration,
                'recovery_mins'  => $recovery,
                'flagged'        => $recovery !== null && $recovery < 3,
            ];
        }

        $flaggedCount = count(array_filter($rows, fn ($r) => $r['flagged']));

        return response()->json([
            'trips'             => $rows,
            'flagged_count'     => $flaggedCount,
            'min_recovery_mins' => $minRecovery,
        ]);
    }

    // ── Feature 15: Blocks ───────────────────────────────────────────────────

    public function blocks(Request $request): JsonResponse
    {
        $request->validate([
            'route_id' => 'required|string|exists:routes,route_id',
        ]);

        $routeId = $request->input('route_id');

        $trips = Trip::where('route_id', $routeId)
            ->with(['stopTimes' => fn ($q) => $q->orderBy('stop_sequence')])
            ->get()
            ->filter(fn ($t) => $t->stopTimes->isNotEmpty())
            ->map(function ($trip) {
                return [
                    'trip_id'         => $trip->trip_id,
                    'trip_headsign'   => $trip->trip_headsign,
                    'direction_id'    => $trip->direction_id,
                    'block_id'        => $trip->block_id,
                    'first_departure' => $trip->stopTimes->first()->departure_time,
                    'last_arrival'    => $trip->stopTimes->last()->arrival_time,
                    'duration_mins'   => $this->timeToMins($trip->stopTimes->last()->arrival_time)
                                       - $this->timeToMins($trip->stopTimes->first()->departure_time),
                ];
            });

        $grouped  = $trips->groupBy('block_id');
        $blocks   = [];
        $unblocked = [];

        foreach ($grouped as $blockId => $blockTrips) {
            if ($blockId === '' || $blockId === null) {
                $unblocked = $blockTrips->sortBy('first_departure')->values()->toArray();
                continue;
            }

            $sorted    = $blockTrips->sortBy('first_departure')->values();
            $conflicts = [];

            for ($i = 0; $i < $sorted->count() - 1; $i++) {
                $curr = $sorted[$i];
                $next = $sorted[$i + 1];
                if ($this->timeToMins($curr['last_arrival']) > $this->timeToMins($next['first_departure'])) {
                    $conflicts[] = [
                        'trip1_id' => $curr['trip_id'],
                        'trip2_id' => $next['trip_id'],
                        'message'  => "{$curr['trip_id']} arrives {$curr['last_arrival']} but {$next['trip_id']} departs {$next['first_departure']}",
                    ];
                }
            }

            $totalHours = round($sorted->sum('duration_mins') / 60, 2);

            $blocks[] = [
                'block_id'    => $blockId,
                'trips'       => $sorted->toArray(),
                'total_hours' => $totalHours,
                'conflicts'   => $conflicts,
            ];
        }

        // Sort blocks by first departure of their first trip
        usort($blocks, fn ($a, $b) =>
            strcmp($a['trips'][0]['first_departure'] ?? '', $b['trips'][0]['first_departure'] ?? '')
        );

        return response()->json([
            'blocks'           => $blocks,
            'unblocked'        => $unblocked,
            'total_fleet_size' => count($blocks),
        ]);
    }
}
