<?php

namespace App\Jobs;

use App\Models\OnTimePerformance;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ComputeOnTimePerformanceJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $date = '')
    {
    }

    public function handle(): void
    {
        $date = $this->date ?: now()->subDay()->toDateString();

        // Aggregate trip_updates for the given date grouped by route
        // A trip is on-time if max delay across its stops is < 120 seconds
        $rows = DB::table('trip_updates as tu')
            ->join('trips as t', 'tu.trip_id', '=', 't.trip_id')
            ->whereDate('tu.recorded_at', $date)
            ->groupBy('t.route_id', 'tu.trip_id')
            ->select(
                't.route_id',
                'tu.trip_id',
                DB::raw('MAX(tu.delay_seconds) as max_delay'),
                DB::raw('AVG(tu.delay_seconds) as avg_delay'),
            )
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        // Roll up by route
        $byRoute = $rows->groupBy('route_id');

        foreach ($byRoute as $routeId => $trips) {
            $totalTrips  = $trips->count();
            $onTimeTrips = $trips->where('max_delay', '<', 120)->count();

            $delays      = $trips->pluck('avg_delay')->sort()->values();
            $avgDelay    = (int) round($delays->avg());
            $p90Index    = (int) floor($delays->count() * 0.9);
            $p90Delay    = (int) ($delays->get($p90Index) ?? $avgDelay);

            OnTimePerformance::updateOrInsert(
                ['route_id' => $routeId, 'date' => $date],
                [
                    'total_trips'  => $totalTrips,
                    'on_time_trips'=> $onTimeTrips,
                    'avg_delay_s'  => $avgDelay,
                    'p90_delay_s'  => $p90Delay,
                    'computed_at'  => now(),
                ]
            );
        }
    }
}
