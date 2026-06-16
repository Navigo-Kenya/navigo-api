<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleTtsService
{
    use GoogleCloudAuth;

    private const ENDPOINT = 'https://texttospeech.googleapis.com/v1/text:synthesize';

    /**
     * Synthesize text to speech and return base64-encoded MP3.
     *
     * Non-fatal: callers should handle null gracefully (frontend falls back to expo-speech).
     *
     * @param  string $text         Text to speak (truncated to 500 chars internally)
     * @param  float  $speakingRate Playback speed multiplier (0.25–4.0)
     * @return string|null          Base64-encoded MP3, or null on failure
     */
    public function synthesize(
        string $text,
        float  $speakingRate = 1.05,
        string $voiceName    = 'en-US-Neural2-D',
        float  $pitch        = 0.0,
        string $languageCode = 'en-US',
    ): ?string {
        // Strip Markdown formatting — TTS engines read asterisks aloud literally
        $text = preg_replace('/\*\*([^*]+)\*\*/', '$1', $text);
        $text = preg_replace('/\*([^*]+)\*/',     '$1', $text);
        $text = trim(mb_substr($text, 0, 500));

        if (empty($text)) {
            return null;
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(15)
                ->withToken($this->getAccessToken())
                ->post(self::ENDPOINT, [
                    'input'       => ['text' => $text],
                    'voice'       => [
                        'languageCode' => $languageCode,
                        'name'         => $voiceName,
                    ],
                    'audioConfig' => [
                        'audioEncoding' => 'MP3',
                        'speakingRate'  => $speakingRate,
                        'pitch'         => $pitch,
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('Google TTS error', [
                    'status' => $response->status(),
                    'body'   => $response->json(),
                ]);
                return null;
            }

            $audioContent = $response->json('audioContent');

            if (empty($audioContent)) {
                Log::warning('Google TTS: empty audioContent in response.');
                return null;
            }

            return $audioContent; // Already base64-encoded MP3

        } catch (\Throwable $e) {
            Log::error('Google TTS exception: ' . $e->getMessage());
            return null;
        }
    }
}
