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

        $voiceParams = ['languageCode' => $languageCode];

        // ── The "Persona Illusion" Fix ──
        // Since Google only has ONE male and ONE female voice for regional dialects,
        // we create the distinct personas by dynamically shifting the pitch and speed.
        
        if (str_starts_with($voiceName, $languageCode)) {
            // Natively matches (e.g., US English requesting US English)
            $voiceParams['name'] = $voiceName;
        } else {
            // Drop the explicit name to prevent 422 errors. Let Google pick the default valid voice.
            $isMale = in_array($voiceName, ['en-US-Neural2-D', 'en-US-Neural2-J']);
            $voiceParams['ssmlGender'] = $isMale ? 'MALE' : 'FEMALE';

            // Apply Persona Modifiers so Devon doesn't sound exactly like Marcus
            if ($voiceName === 'en-US-Neural2-J') { // Devon (Deep)
                $pitch -= 4.0;
                $speakingRate -= 0.05;
            } elseif ($voiceName === 'en-US-Neural2-H') { // Zara (Bright)
                $pitch += 3.0;
                $speakingRate += 0.05;
            }
            // Marcus (D) and Amara (F) remain at baseline (0.0 offset)
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(15)
                ->withToken($this->getAccessToken())
                ->post(self::ENDPOINT, [
                    'input'       => ['text' => $text],
                    'voice'       => $voiceParams,
                    'audioConfig' => [
                        'audioEncoding' => 'MP3',
                        // Clamp limits to prevent invalid Google TTS parameters
                        'speakingRate'  => max(0.25, min(4.0, $speakingRate)), 
                        'pitch'         => max(-20.0, min(20.0, $pitch)), 
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