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

        // ── Google TTS Strict Locale Match Fix ──
        // Google TTS rejects requests where voiceName and languageCode don't share the same locale.
        // If the frontend requests a specific language (e.g., sw-KE) but the user's saved voice 
        // persona is US English, map it dynamically to a matching local voice of the same gender.
        if (!str_starts_with($voiceName, $languageCode)) {
            // Determine gender from the default voices used in the app (D and J are Male)
            $isMale = in_array($voiceName, ['en-US-Neural2-D', 'en-US-Neural2-J', 'en-KE-Standard-B', 'sw-KE-Standard-B']);
            
            // In Google Cloud TTS Standard voices, 'A' is typically Female, 'B' is Male.
            $voiceName = $languageCode . '-Standard-' . ($isMale ? 'B' : 'A');
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

            return $audioContent;

        } catch (\Throwable $e) {
            Log::error('Google TTS exception: ' . $e->getMessage());
            return null;
        }
    }
}