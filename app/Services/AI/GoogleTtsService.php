<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleTtsService
{
    use GoogleCloudAuth;

    private const ENDPOINT = 'https://texttospeech.googleapis.com/v1/text:synthesize';

    public function synthesize(
        string $text,
        float  $speakingRate = 1.05,
        string $voiceName    = 'en-US-Neural2-D',
        float  $pitch        = 0.0,
        string $languageCode = 'en-US',
    ): ?string {
        $text = preg_replace('/\*\*([^*]+)\*\*/', '$1', $text);
        $text = preg_replace('/\*([^*]+)\*/',     '$1', $text);
        $text = trim(mb_substr($text, 0, 500));

        if (empty($text)) return null;

        // ── Persona Illusion (Pitch/Speed shifting) ──
        if ($voiceName === 'en-US-Neural2-J') { // Devon (Deep)
            $pitch -= 4.0; $speakingRate -= 0.05;
        } elseif ($voiceName === 'en-US-Neural2-H') { // Zara (Bright)
            $pitch += 3.0; $speakingRate += 0.05;
        }

        $isMale = in_array($voiceName, ['en-US-Neural2-D', 'en-US-Neural2-J']);

        // Helper function to attempt API calls cleanly
        $attemptApi = function($voiceConfig) use ($text, $speakingRate, $pitch) {
            return Http::withoutVerifying()
                ->timeout(10)
                ->withToken($this->getAccessToken())
                ->post(self::ENDPOINT, [
                    'input'       => ['text' => $text],
                    'voice'       => $voiceConfig,
                    'audioConfig' => [
                        'audioEncoding' => 'MP3',
                        'speakingRate'  => max(0.25, min(4.0, $speakingRate)),
                        'pitch'         => max(-20.0, min(20.0, $pitch)),
                    ],
                ]);
        };

        try {
            // ATTEMPT 1: Exact Name (Works for native US English)
            if (str_starts_with($voiceName, $languageCode)) {
                $res = $attemptApi(['languageCode' => $languageCode, 'name' => $voiceName]);
                if ($res->successful()) return $res->json('audioContent');
            }

            // ATTEMPT 2: Fallback to Gender + Language (Works for Swahili/French)
            $res = $attemptApi(['languageCode' => $languageCode, 'ssmlGender' => $isMale ? 'MALE' : 'FEMALE']);
            if ($res->successful()) return $res->json('audioContent');

            // ATTEMPT 3: Ultimate Fallback (Just the language code, let Google pick)
            $res = $attemptApi(['languageCode' => $languageCode]);
            if ($res->successful()) return $res->json('audioContent');

            Log::error('Google TTS completely failed all fallbacks.', ['status' => $res->status(), 'body' => $res->json()]);
            return null;

        } catch (\Throwable $e) {
            Log::error('Google TTS exception: ' . $e->getMessage());
            return null;
        }
    }
}