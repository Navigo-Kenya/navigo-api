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

        // ── Google TTS Auto-Resolve Fix ──
        $voiceParams = [
            'languageCode' => $languageCode,
        ];

        // If the exact voice name matches the requested language code, request it directly.
        if (str_starts_with($voiceName, $languageCode)) {
            $voiceParams['name'] = $voiceName;
        } else {
            // Otherwise, drop the explicit name and let Google automatically select 
            // the best available voice (Standard, Wavenet, etc.) for that region based on gender.
            $isMale = in_array($voiceName, ['en-US-Neural2-D', 'en-US-Neural2-J']);
            $voiceParams['ssmlGender'] = $isMale ? 'MALE' : 'FEMALE';
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