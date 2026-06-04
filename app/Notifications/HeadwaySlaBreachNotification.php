<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class HeadwaySlaBreachNotification extends Notification
{
    public function __construct(
        public readonly string $routeId,
        public readonly string $routeName,
        public readonly int    $gapMinutes,
        public readonly int    $targetMinutes,
        public readonly string $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'           => 'headway_sla_breach',
            'route_id'       => $this->routeId,
            'route_name'     => $this->routeName,
            'gap_minutes'    => $this->gapMinutes,
            'target_minutes' => $this->targetMinutes,
            'message'        => $this->message,
        ];
    }
}
