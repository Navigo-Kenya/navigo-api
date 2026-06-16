<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleLlmService
{
    use GoogleCloudAuth;

    private string $projectId;

    // Vertex AI Gemini 2.5 Flash
    private const MODEL    = 'gemini-2.5-flash';
    private const REGION   = 'us-central1';
    private const ENDPOINT = 'https://' . self::REGION . '-aiplatform.googleapis.com/v1/projects/%s/locations/'
                           . self::REGION . '/publishers/google/models/' . self::MODEL . ':generateContent';

    public function __construct()
    {
        $this->projectId = config('services.google_cloud.project_id', '');
    }

    /**
     * Single chat turn against Gemini 2.5 Flash via Vertex AI.
     *
     * When $audioFile is provided it is injected as an inlineData part in the last user turn,
     * letting Gemini transcribe and respond in one step (no separate STT call needed).
     *
     * @param  array       $history       Neutral history — see buildContents() for supported shapes.
     * @param  string      $systemPrompt  Full system instruction text.
     * @param  array       $tools         OpenAI-format tool declarations (converted internally).
     * @param  bool        $includeTools  Pass false on the narration turn to suppress function calling.
     * @param  array|null  $audioFile     ['base64' => string, 'mime' => string] — optional voice input.
     * @return array|null                 ['text' => string|null, 'functionCall' => ['name', 'args']|null]
     */
    public function chat(
        array  $history,
        string $systemPrompt,
        array  $tools        = [],
        bool   $includeTools = true,
        ?array $audioFile    = null,
    ): ?array {
        if (empty($this->projectId)) {
            Log::error('Vertex AI: GCP_PROJECT_ID is not set.');
            return null;
        }

        $contents = $this->buildContents($history, $audioFile);
        if (empty($contents)) {
            Log::warning('Vertex AI: buildContents produced an empty array — skipping call.');
            return null;
        }

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => $contents,
        ];

        if ($includeTools && !empty($tools)) {
            $payload['tools']      = [['functionDeclarations' => $this->convertTools($tools)]];
            $payload['toolConfig'] = ['functionCallingConfig' => ['mode' => 'AUTO']];
        }

        try {
            $url   = sprintf(self::ENDPOINT, $this->projectId);
            $token = $this->getAccessToken();

            $response = Http::withoutVerifying()
                ->timeout(45)
                ->withToken($token)
                ->post($url, $payload);

            if (!$response->successful()) {
                Log::error('Vertex AI API error', [
                    'status' => $response->status(),
                    'body'   => $response->json(),
                ]);
                return null;
            }

            $candidate = $response->json('candidates.0');
            if (!$candidate) {
                Log::warning('Vertex AI: no candidates returned.', ['body' => $response->json()]);
                return null;
            }

            $parts = $candidate['content']['parts'] ?? [];

            foreach ($parts as $part) {
                if (isset($part['functionCall'])) {
                    return [
                        'text'         => null,
                        'functionCall' => [
                            'name' => $part['functionCall']['name'],
                            'args' => $part['functionCall']['args'] ?? [],
                        ],
                    ];
                }
            }

            $text = '';
            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    $text .= $part['text'];
                }
            }

            return [
                'text'         => trim($text),
                'functionCall' => null,
            ];

        } catch (\Throwable $e) {
            Log::error('Vertex AI exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Convert neutral history format to Vertex AI / Gemini `contents` array.
     *
     * Supported input shapes:
     *   ['role' => 'user',      'content' => 'string']
     *   ['role' => 'assistant', 'content' => 'string']
     *   ['role' => 'assistant', 'tool_calls' => [...openai shape...]]
     *   ['role' => 'tool',      'tool_call_id' => '...', 'content' => 'json']
     *
     * When $audioFile is given, it is appended as an inlineData part on the last user turn,
     * replacing an empty/placeholder text part so Gemini hears the actual voice input.
     */
    private function buildContents(array $history, ?array $audioFile = null): array
    {
        $contents = [];

        foreach ($history as $msg) {
            $role = $msg['role'];

            if ($role === 'assistant' && isset($msg['tool_calls'])) {
                $tc   = $msg['tool_calls'][0];
                $name = $tc['function']['name'];
                $args = json_decode($tc['function']['arguments'], true) ?? [];

                $contents[] = [
                    'role'  => 'model',
                    'parts' => [['functionCall' => ['name' => $name, 'args' => $args]]],
                ];
                continue;
            }

            if ($role === 'tool') {
                $result     = json_decode($msg['content'], true) ?? ['raw' => $msg['content']];
                $contents[] = [
                    'role'  => 'user',
                    'parts' => [[
                        'functionResponse' => [
                            'name'     => 'get_route',
                            'response' => ['result' => $result],
                        ],
                    ]],
                ];
                continue;
            }

            $geminiRole   = ($role === 'assistant') ? 'model' : 'user';
            $contentValue = $msg['content'] ?? '';

            if (is_array($contentValue)) {
                $text = '[User Audio Input]';
                foreach ($contentValue as $part) {
                    if (isset($part['type'], $part['text']) && $part['type'] === 'text' && !empty($part['text'])) {
                        $text = $part['text'];
                        break;
                    }
                }
                $contentValue = $text;
            }

            if (empty(trim((string) $contentValue))) {
                continue;
            }

            $contents[] = [
                'role'  => $geminiRole,
                'parts' => [['text' => (string) $contentValue]],
            ];
        }

        // Inject audio into the last user turn so Gemini processes voice input natively.
        if (!empty($audioFile['base64'])) {
            // Normalize MIME: Expo's HIGH_QUALITY preset records M4A/AAC regardless of what
            // the frontend declares. Map any WAV/M4A aliases to audio/mp4 which Vertex AI accepts.
            $rawMime = $audioFile['mime'] ?? 'audio/mp4';
            $mime    = match (true) {
                in_array($rawMime, ['audio/wav', 'audio/x-wav', 'audio/wave']) => 'audio/mp4',
                in_array($rawMime, ['audio/m4a', 'audio/x-m4a'])              => 'audio/mp4',
                default                                                         => $rawMime,
            };

            $audioPart = ['inlineData' => ['mimeType' => $mime, 'data' => $audioFile['base64']]];

            // Find the last user turn and replace its parts with just the audio —
            // placeholder text like "[Voice message]" is noise; the system prompt is context enough.
            $lastIdx = count($contents) - 1;
            while ($lastIdx >= 0 && $contents[$lastIdx]['role'] !== 'user') {
                $lastIdx--;
            }

            if ($lastIdx >= 0) {
                $contents[$lastIdx]['parts'] = [$audioPart];
            } else {
                $contents[] = ['role' => 'user', 'parts' => [$audioPart]];
            }
        }

        // Vertex AI / Gemini requires the first content turn to be 'user'
        if (!empty($contents) && $contents[0]['role'] === 'model') {
            array_unshift($contents, [
                'role'  => 'user',
                'parts' => [['text' => '(start of conversation)']],
            ]);
        }

        return $contents;
    }

    /**
     * Convert OpenAI-format tool declarations to Gemini functionDeclarations.
     *
     * OpenAI:  [['type' => 'function', 'function' => ['name', 'description', 'parameters']]]
     * Gemini:  [['name', 'description', 'parameters']]
     */
    private function convertTools(array $openAiTools): array
    {
        return array_map(fn ($tool) => [
            'name'        => $tool['function']['name'],
            'description' => $tool['function']['description'],
            'parameters'  => $tool['function']['parameters'],
        ], $openAiTools);
    }
}
