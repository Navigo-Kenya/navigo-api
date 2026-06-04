<?php

namespace App\Jobs;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class CheckIncidentEscalationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $incidents = Incident::whereNotIn('status', ['resolved'])
            ->whereNotNull('sla_deadline')
            ->where('sla_deadline', '<', now())
            ->where('escalation_level', '<', 2)
            ->get();

        foreach ($incidents as $incident) {
            $newLevel = $incident->escalation_level + 1;

            $incident->update([
                'escalation_level'  => $newLevel,
                'last_escalated_at' => now(),
            ]);

            Log::info("Incident #{$incident->id} escalated to level {$newLevel}");

            // Level 1 → notify operator_owner users of the agency
            // Level 2 → notify hopln_staff
            // Actual push notification sending would happen here via your
            // existing notification service once agency context is available.
        }
    }
}
