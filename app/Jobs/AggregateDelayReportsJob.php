<?php

namespace App\Jobs;

use App\Models\Contribution;
use App\Models\ServiceAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class AggregateDelayReportsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $routeId)
    {
    }

    public function handle(): void
    {
        $count = Contribution::where('type', 'delay_report')
            ->where('created_at', '>', now()->subMinutes(15))
            ->whereRaw("data->>'route_id' = ?", [$this->routeId])
            ->count();

        if ($count < 3) {
            return;
        }

        // Avoid duplicate draft alerts for the same route within 30 minutes
        $recentExists = ServiceAlert::where('auto_generated', true)
            ->where('affected_type', 'route')
            ->where('affected_id', $this->routeId)
            ->where('created_at', '>', now()->subMinutes(30))
            ->exists();

        if ($recentExists) {
            return;
        }

        ServiceAlert::create([
            'title'         => "Crowdsourced delay reported on route {$this->routeId}",
            'description'   => "{$count} delay reports received in the last 15 minutes.",
            'severity'      => 'warning',
            'effect'        => 'other',
            'status'        => 'draft',
            'affected_type' => 'route',
            'affected_id'   => $this->routeId,
            'starts_at'     => now(),
            'created_by'    => 'system',
            'auto_generated'=> true,
        ]);

        // Clear OTP cache for this route
        Cache::forget("otp_cache_{$this->routeId}");
    }
}
