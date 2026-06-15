<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class SmsService
{
    private Client $client;
    private string $apiKey;
    private string $username;
    private string $senderId;
    private bool $sandbox;

    public function __construct()
    {
        $this->sandbox  = (bool) config('services.africastalking.sandbox');
        $this->apiKey   = config('services.africastalking.api_key', '');
        $this->username = config('services.africastalking.username', 'sandbox');
        $this->senderId = config('services.africastalking.sender_id', '');

        $baseUri = $this->sandbox
            ? 'https://api.sandbox.africastalking.com/'
            : 'https://api.africastalking.com/';

        $this->client = new Client(['base_uri' => $baseUri]);
    }

    public function send(string $to, string $message): bool
    {
        if (empty($this->apiKey)) {
            Log::info('SMS skipped (no API key configured)', ['to' => $to]);
            return true;
        }

        try {
            $response = $this->client->post('version1/messaging', [
                'headers' => [
                    'apiKey'       => $this->apiKey,
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    'username' => $this->username,
                    'to'       => $to,
                    'message'  => $message,
                    // 'from'     => $this->senderId, // Optional: set a sender ID if configured in AfricasTalking account
                ],
            ]);

            $body      = json_decode($response->getBody()->getContents(), true);
            $recipient = $body['SMSMessageData']['Recipients'][0] ?? [];
            $status    = $recipient['status'] ?? 'Unknown';
            $code      = $recipient['statusCode'] ?? null;

            // AT success statusCode is 101
            if ($code === 101 || $status === 'Success') {
                Log::info('SMS sent', ['to' => $to, 'sandbox' => $this->sandbox]);
                return true;
            }

            Log::warning('SMS delivery failed', [
                'to'         => $to,
                'status'     => $status,
                'statusCode' => $code,
                'sandbox'    => $this->sandbox,
            ]);
            return false;

        } catch (\Throwable $e) {
            Log::error('SMS send error', ['to' => $to, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
