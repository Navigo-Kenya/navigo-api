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

        // ── Voice Persona Mapping Matrix ──
        // Maps the US English personas to distinct regional voices where available.
        $voiceMatrix = [
            'en-US-Neural2-D' => [ // Marcus (Male 1 - Warm)
                'en-KE' => 'en-KE-Standard-B',
                'sw-KE' => 'sw-KE-Standard-B',
                'fr-FR' => 'fr-FR-Neural2-B',
            ],
            'en-US-Neural2-J' => [ // Devon (Male 2 - Deep)
                'en-KE' => 'en-KE-Standard-C',
                'sw-KE' => 'sw-KE-Standard-B', // Swahili only has 1 male voice
                'fr-FR' => 'fr-FR-Neural2-D',
            ],
            'en-US-Neural2-F' => [ // Amara (Female 1 - Warm)
                'en-KE' => 'en-KE-Standard-A',
                'sw-KE' => 'sw-KE-Standard-A',
                'fr-FR' => 'fr-FR-Neural2-A',
            ],
            'en-US-Neural2-H' => [ // Zara (Female 2 - Bright)
                'en-KE' => 'en-KE-Standard-D',
                'sw-KE' => 'sw-KE-Standard-A', // Swahili only has 1 female voice
                'fr-FR' => 'fr-FR-Neural2-C',
            ]
        ];

        // If the voice natively matches the requested language, use it directly.
        if (str_starts_with($voiceName, $languageCode)) {
            $voiceParams['name'] = $voiceName;
        } else {
            // Look up the explicit local voice for this persona
            if (isset($voiceMatrix[$voiceName][$languageCode])) {
                $voiceParams['name'] = $voiceMatrix[$voiceName][$languageCode];
            } else {
                // Absolute Fallback: if a random language is added later, fall back to gender
                $isMale = in_array($voiceName, ['en-US-Neural2-D', 'en-US-Neural2-J']);
                $voiceParams['ssmlGender'] = $isMale ? 'MALE' : 'FEMALE';
            }
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