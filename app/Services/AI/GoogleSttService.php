<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleSttService
{
    use GoogleCloudAuth;

    private const MODEL  = 'gemini-2.5-flash';
    private const REGION = 'europe-west3'; // Match this to your GCP region

    /**
     * Transcribe base64-encoded audio to text using Gemini Flash.
     * Gemini handles M4A/AAC natively and auto-detects languages perfectly.
     */
    public function transcribe(string $base64): ?string
    {
        $projectId = config('services.google_cloud.project_id');
        if (empty($projectId)) return null;

        $endpoint = 'https://' . self::REGION . '-aiplatform.googleapis.com/v1/projects/' . $projectId . '/locations/' . self::REGION . '/publishers/google/models/' . self::MODEL . ':generateContent';

        try {
            $response = Http::withoutVerifying()
                ->timeout(20)
                ->withToken($this->getAccessToken())
                ->post($endpoint, [
                    'systemInstruction' => [
                        'parts' => [
                            ['text' => 'You are a highly accurate transcription engine. Listen to the audio and transcribe exactly what is said. Auto-detect the language (English, Swahili, or French). Respond ONLY with the pure transcription text. Do not add quotes, markdown, or any conversational filler.']
                        ]
                    ],
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [
                            // Gemini perfectly accepts the native Expo M4A as audio/mp4
                            ['inlineData' => ['mimeType' => 'audio/mp4', 'data' => $base64]],
                            ['text' => 'Please transcribe this audio exactly.']
                        ]
                    ]]
                ]);

            if (!$response->successful()) {
                // If this fails, your log will now correctly say "Gemini STT error"
                Log::error('Gemini STT error', ['status' => $response->status(), 'body' => $response->json()]);
                return null;
            }

            $transcript = $response->json('candidates.0.content.parts.0.text');

            if (empty(trim($transcript ?? ''))) {
                return null;
            }

            return trim($transcript);

        } catch (\Throwable $e) {
            Log::error('Gemini STT exception: ' . $e->getMessage());
            return null;
        }
    }
}