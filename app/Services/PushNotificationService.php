<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushNotificationService
{
    private const EXPO_PUSH_URL = 'https://exp.host/--/api/v2/push/send';

    public function send(array $tokens, string $title, string $body, array $data = []): void
    {
        if (empty($tokens)) return;

        $messages = array_map(fn ($token) => [
            'to'    => $token,
            'title' => $title,
            'body'  => $body,
            'data'  => $data,
            'sound' => 'default',
        ], array_values($tokens));

        try {
            Http::withHeaders(['Accept-Encoding' => 'gzip, deflate'])
                ->timeout(15)
                ->post(self::EXPO_PUSH_URL, $messages);
        } catch (\Throwable $e) {
            Log::warning('[Push] Expo delivery failed', ['error' => $e->getMessage()]);
        }
    }

    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        $tokens = $user->deviceTokens()->pluck('token')->toArray();
        $this->send($tokens, $title, $body, $data);
    }
}
