<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloud Speech-to-Text v1 service.
 *
 * Not used in the main Kwame pipeline — Gemini 2.5 Flash accepts raw audio directly,
 * making a dedicated STT step redundant. This service is retained for:
 *
 *  1. Stop/route search by voice — lightweight keyword extraction without a full LLM round-trip
 *     (e.g. user says "Westlands" → fast stop lookup, no AI needed).
 *  2. Admin transcription — transcribe driver/conductor feedback voice notes submitted via the app.
 *  3. Accessibility captions — real-time subtitles on the map screen for users who record audio
 *     annotations at stops (future community contribution feature).
 *  4. Language detection — use `results[].languageCode` to auto-detect en-KE vs sw-KE and route
 *     the request to the correct Gemini system prompt variant.
 *  5. Confidence filtering — STT returns a confidence score per alternative; use it as a pre-check
 *     before sending low-confidence audio to the more expensive Gemini endpoint.
 */
class GoogleSttService
{
    use GoogleCloudAuth;

    private const ENDPOINT = 'https://speech.googleapis.com/v1/speech:recognize';

    /**
     * Transcribe base64-encoded audio to text.
     *
     * Uses ENCODING_UNSPECIFIED so the API auto-detects the container format
     * from the audio header (M4A/AAC from native Expo recorder).
     *
     * @param  string $base64     Raw base64 — no data-URI prefix
     * @param  int    $sampleRate Recording sample rate in Hz
     * @return string|null        Transcript, or null on failure / empty result
     */
    public function transcribe(string $base64, int $sampleRate = 44100): ?string
    {
        try {
            $response = Http::withoutVerifying()
                ->timeout(20)
                ->withToken($this->getAccessToken())
                ->post(self::ENDPOINT, [
                    'config' => [
                        'encoding'                   => 'ENCODING_UNSPECIFIED',
                        'sampleRateHertz'            => $sampleRate,
                        'languageCode'               => 'en-KE',
                        'alternativeLanguageCodes'   => ['sw-KE'],
                        'model'                      => 'latest_short',
                        'enableAutomaticPunctuation' => true,
                    ],
                    'audio' => [
                        'content' => $base64,
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('Google STT error', [
                    'status' => $response->status(),
                    'body'   => $response->json(),
                ]);
                return null;
            }

            $transcript = $response->json('results.0.alternatives.0.transcript');

            if (empty(trim($transcript ?? ''))) {
                Log::info('Google STT: empty transcript returned.');
                return null;
            }

            return trim($transcript);

        } catch (\Throwable $e) {
            Log::error('Google STT exception: ' . $e->getMessage());
            return null;
        }
    }
}
