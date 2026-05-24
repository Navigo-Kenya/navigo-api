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

    public function __construct()
    {
        $this->client   = new Client(['base_uri' => 'https://api.africastalking.com/']);
        $this->apiKey   = config('services.africastalking.api_key', '');
        $this->username = config('services.africastalking.username', '');
        $this->senderId = config('services.africastalking.sender_id', 'HOPLN');
    }

    public function send(string $to, string $message): bool
    {
        // Sandbox mode: explicit flag OR no API key configured
        if (config('services.africastalking.sandbox') || empty($this->apiKey)) {
            Log::info('SMS (sandbox)', ['to' => $to, 'message' => $message]);
            return true;
        }

        try {
            $response = $this->client->post('version1/messaging', [
                'headers' => [
                    'apiKey'       => $this->apiKey,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept'       => 'application/json',
                ],
                'form_params' => [
                    'username' => $this->username,
                    'to'       => $to,
                    'message'  => $message,
                    'from'     => $this->senderId,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $status = $body['SMSMessageData']['Recipients'][0]['status'] ?? 'Unknown';

            if ($status === 'Success') {
                return true;
            }

            Log::warning('SMS delivery failed', ['to' => $to, 'status' => $status]);
            return false;

        } catch (\Throwable $e) {
            Log::error('SMS send error', ['to' => $to, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
