<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendPushNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int    $userId,
        public readonly string $type,
        public readonly string $title,
        public readonly string $body,
        public readonly array  $data = [],
    ) {}

    public function handle(PushNotificationService $push): void
    {
        $user = User::with('deviceTokens')->find($this->userId);
        if (! $user) return;

        // Respect user notification preferences
        $prefs = $user->settings['notifications'] ?? [];
        if (($prefs['master'] ?? true) === false) return;
        if (($prefs[$this->type] ?? true) === false) return;

        // Store in inbox
        Notification::create([
            'user_id' => $this->userId,
            'type'    => $this->type,
            'title'   => $this->title,
            'body'    => $this->body,
            'data'    => $this->data,
        ]);

        // Push to devices
        $push->sendToUser($user, $this->title, $this->body, $this->data);
    }
}
