<?php

namespace App\Jobs;

use App\Models\RouteSla;
use App\Models\VehiclePosition;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class CheckHeadwaySlaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function handle(): void
    {
        $activeSlas = RouteSla::with('route:route_id,route_short_name')
            ->where('active', true)
            ->get();

        if ($activeSlas->isEmpty()) {
            return;
        }

        foreach ($activeSlas as $sla) {
            $this->checkSla($sla);
        }
    }

    private function checkSla(RouteSla $sla): void
    {
        $cutoff = now()->subMinutes(15);

        // Get the most recent positions for vehicles on this route
        // ordered by timestamp ascending so we can compute gaps
        $positions = VehiclePosition::where('route_id', $sla->route_id)
            ->where('recorded_at', '>=', $cutoff)
            ->orderBy('recorded_at', 'asc')
            ->get(['vehicle_id', 'recorded_at']);

        if ($positions->count() < 2) {
            return; // Not enough data to detect a gap
        }

        // Find the largest gap between consecutive position timestamps
        $maxGapMinutes = 0;
        $prev = null;
        foreach ($positions as $pos) {
            if ($prev !== null) {
                $gapMinutes = Carbon::parse($prev->recorded_at)
                    ->diffInMinutes($pos->recorded_at);
                if ($gapMinutes > $maxGapMinutes) {
                    $maxGapMinutes = $gapMinutes;
                }
            }
            $prev = $pos;
        }

        $threshold = $sla->target_headway_minutes + $sla->alert_threshold_minutes;
        if ($maxGapMinutes < $threshold) {
            return;
        }

        Log::info("SLA breach: route {$sla->route_id}, gap {$maxGapMinutes}min (threshold {$threshold}min)");

        // Notify ops coordinators and owners for this agency
        $notifyRoles = ['operator_owner', 'operator_ops_coordinator'];
        $users = User::whereHas('roles', fn ($q) => $q->whereIn('name', $notifyRoles))
            ->whereHas('agencyScopes', fn ($q) => $q->where('agency_id', $sla->agency_id))
            ->get();

        $routeName = $sla->route->route_short_name ?? $sla->route_id;
        $message   = "Headway alert: Route {$routeName} has a {$maxGapMinutes}-minute gap (target: {$sla->target_headway_minutes} min).";

        foreach ($users as $user) {
            try {
                $user->notify(new \App\Notifications\HeadwaySlaBreachNotification(
                    $sla->route_id,
                    $routeName,
                    $maxGapMinutes,
                    $sla->target_headway_minutes,
                    $message,
                ));
            } catch (\Throwable $e) {
                Log::warning("Failed to notify user {$user->id} for SLA breach: {$e->getMessage()}");
            }
        }
    }
}
