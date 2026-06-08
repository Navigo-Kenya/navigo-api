<?php

namespace App\Events;

use App\Models\TransitReport;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// ShouldBroadcastNow ensures immediate pushing (no queue delay for live traffic)
class TransitReportCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public TransitReport $report) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        // For a city-wide app, you can broadcast to a general channel.
        // For larger scale (Nairobi + Kinshasa), you'd dynamically set this 
        // using Geohashes or City IDs based on the report's coordinates.
        return [
            new Channel('transit-reports.nairobi'),
        ];
    }

    /**
     * The data to broadcast.
     * We format it exactly how the frontend expects it.
     */
    public function broadcastWith(): array
    {
        return [
            'id'         => $this->report->id,
            'type'       => $this->report->type,
            // The service returns lat/lng, but the Eloquent model natively holds the raw geometry.
            // We parse it here or rely on the service returning a DTO. 
            // For simplicity, assuming the controller/service passes the parsed coords:
            'lat'        => $this->report->lat ?? null, 
            'lng'        => $this->report->lng ?? null,
            'expires_at' => $this->report->expires_at->toIso8601String(),
        ];
    }
}