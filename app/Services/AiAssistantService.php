<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class AiAssistantService
{
    protected string $model = 'gpt-4o-mini';
    const SESSION_TTL = 1800; // 30 minutes

    public function __construct(
        private GeocodingService     $geoService,
        private TransitEngineService $transitEngine,
    ) {}

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are Kwame, a warm, friendly, and knowledgeable transit assistant for Nairobi's public transport network.
Speak casually and empathetically — like a helpful local friend, not a robot.
You can chat about transport topics: matatu routes, fares, traffic, safety tips, general Nairobi knowledge, and small talk.

CRITICAL RULE: You MUST always respond by calling exactly ONE of these two tools:
- `calculate_route` — ONLY when you have BOTH an origin AND a destination confirmed
- `chat_or_clarify` — for EVERYTHING else (greetings, clarifying questions, tips, chitchat, error messages)

You MUST NEVER produce a plain-text response. Every response must be a tool call.

ROUTING RULES:

1. CALL `calculate_route` IMMEDIATELY when you have BOTH an origin AND a destination.
   - If both are in ONE message: call `calculate_route` right now — do NOT wait.
   - If origin was established earlier and user now gives a destination: call `calculate_route` NOW.
   - Include a warm `holding_phrase` in the tool args (shown to the user while the route loads).

2. IF ONLY A DESTINATION IS GIVEN and no origin is in the conversation history:
   call `chat_or_clarify` with message: "Where are you starting from?"

3. CURRENT LOCATION: If the user says "here", "my location", "current location", "where I am",
   "from here", or any similar phrase, pass "current location" as the `from` parameter.

4. SAVED LOCATIONS ("home", "work", "school", "office"): These are personal alias keywords.
   - Pass them as-is to `calculate_route` — the backend resolves them to real coordinates automatically from the user's profile.

HOLDING PHRASES for `calculate_route`:
- "Let me check the best matatu routes from [origin] to [destination] real quick..."
- "Looking up departures from [origin] to [destination], just a second..."
- "On it! Checking live traffic from [origin] to [destination]..."
- "Checking routes for you now..."

RESPONSE STYLE:
- Short and conversational — 1 to 3 sentences maximum.
- Plain text only. No markdown, no bullet points.
- If no route is found, suggest alternatives warmly.
PROMPT;
    }

    private function tools(): array
    {
        return [
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'calculate_route',
                    'description' => 'Calculate the best matatu/bus transit route between two Nairobi locations. Call ONLY when BOTH origin and destination are confirmed.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'from' => [
                                'type'        => 'string',
                                'description' => 'Origin location name or "current location"',
                            ],
                            'to' => [
                                'type'        => 'string',
                                'description' => 'Destination location name',
                            ],
                            'holding_phrase' => [
                                'type'        => 'string',
                                'description' => 'Short warm sentence displayed to the user while the route is loading.',
                            ],
                            'walkReluctance' => [
                                'type'        => 'number',
                                'description' => 'Walk reluctance factor (default 13.5). Increase to 20–25 if user mentions heavy bags, rain, or minimal walking.',
                            ],
                        ],
                        'required' => ['from', 'to', 'holding_phrase'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'chat_or_clarify',
                    'description' => 'Send a conversational reply, ask a clarifying question, or respond to small talk. Use for everything that is NOT a confirmed route calculation.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'message' => [
                                'type'        => 'string',
                                'description' => 'The message to send the user. Plain text, 1–3 sentences.',
                            ],
                        ],
                        'required' => ['message'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Main conversational entry point — one turn of a persistent session.
     */
    public function chat(
        string  $sessionId,
        ?string $text      = null,
        ?array  $audioFile = null,
        ?float  $userLat   = null,
        ?float  $userLng   = null,
        array   $aliases   = [],   // ['home' => ['lat' => x, 'lng' => y], ...]  — today: GPS fallback; with auth: real saved addresses
    ): ?array {
        $apiKey     = env('OPENAI_API_KEY');
        $transcript = null;

        // Transcribe audio → text first
        if ($audioFile && !empty($audioFile['base64'])) {
            $transcript = $this->transcribeWithWhisper($apiKey, $audioFile['base64'], $audioFile['mime'] ?? 'audio/m4a');
            $text       = $transcript ?: $text;
        }

        if (!$text) return null;

        // Load conversation history and append the new user turn
        $history   = Cache::get("kwame:{$sessionId}", []);
        $history[] = ['role' => 'user', 'content' => $text];

        // First GPT call — tool_choice: required forces a tool call on every turn
        $messages = $this->buildMessages($history, $userLat, $userLng);
        $first    = $this->callChat($apiKey, $messages, 'required');
        if (!$first) return null;

        $assistantMsg   = $first['choices'][0]['message'];
        $holdingPhrase  = null;
        $route          = null;
        $spokenResponse = '';

        if (!empty($assistantMsg['tool_calls'])) {
            $toolCall = $assistantMsg['tool_calls'][0];
            $toolName = $toolCall['function']['name'];
            $args     = json_decode($toolCall['function']['arguments'], true) ?? [];

            if ($toolName === 'calculate_route') {
                // Extract the holding phrase the model bundled into the tool args
                $holdingPhrase = !empty($args['holding_phrase'])
                    ? $args['holding_phrase']
                    : $this->randomHoldingPhrase($args['from'] ?? '', $args['to'] ?? '');

                // Resolve alias keywords (home / work / school / office) before geocoding.
                // Coords come from the client's UserContext — today: GPS; with auth: saved profile addresses.
                $fromNorm    = strtolower(trim($args['from'] ?? ''));
                $toNorm      = strtolower(trim($args['to']   ?? ''));
                $fromIsAlias = $this->isAliasKeyword($fromNorm);
                $toIsAlias   = $this->isAliasKeyword($toNorm);

                $fromOverride = $fromIsAlias ? ($aliases[$fromNorm] ?? null) : null;
                $toOverride   = $toIsAlias   ? ($aliases[$toNorm]   ?? null) : null;

                // If an alias keyword has no resolved coords, ask the user for the address
                $missingAlias = null;
                if ($fromIsAlias && !$fromOverride) $missingAlias = $args['from'];
                if ($toIsAlias   && !$toOverride)   $missingAlias = $args['to'];

                if ($missingAlias !== null) {
                    $route       = null;
                    $toolContent = json_encode([
                        'success' => false,
                        'reason'  => 'unresolved_alias',
                        'message' => "'{$missingAlias}' is a saved-location keyword but no address is on file yet. Ask the user to share their actual {$missingAlias} address or street name.",
                    ]);
                    Log::info('Alias keyword has no coords — asking user', ['keyword' => $missingAlias]);
                } else {
                    $route       = $this->resolveAndPlanRoute($args, $userLat, $userLng, $fromOverride, $toOverride);
                    $toolContent = $route
                        ? json_encode([
                            'success'              => true,
                            'summary'              => $route['summary'],
                            'duration_seconds'     => $route['total_duration'],
                            'walk_distance_meters' => $route['total_walk_distance'],
                          ])
                        : json_encode(['success' => false, 'message' => 'No routes found for those locations right now.']);
                    if ($fromIsAlias || $toIsAlias) {
                        Log::info('Alias keyword resolved via user context', [
                            'from' => $args['from'], 'to' => $args['to'],
                        ]);
                    }
                }

                // Feed the tool result back and force a chat_or_clarify spoken response
                $history[] = $assistantMsg;
                $history[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content'      => $toolContent,
                ];

                $secondMessages = $this->buildMessages($history, $userLat, $userLng);
                $second         = $this->callChat($apiKey, $secondMessages, [
                    'type'     => 'function',
                    'function' => ['name' => 'chat_or_clarify'],
                ]);

                $secondToolCalls = $second['choices'][0]['message']['tool_calls'] ?? [];
                $secondArgs      = !empty($secondToolCalls)
                    ? (json_decode($secondToolCalls[0]['function']['arguments'] ?? '{}', true) ?? [])
                    : [];
                $spokenResponse  = $secondArgs['message']
                    ?? ($route
                        ? "Found a great route! Let's get you moving."
                        : "I couldn't find a route right now — try a nearby stop or a different time.");

                $history[] = ['role' => 'assistant', 'content' => $spokenResponse];

            } else {
                // chat_or_clarify — extract the message directly
                $spokenResponse = $args['message'] ?? '';
                $history[]      = ['role' => 'assistant', 'content' => $spokenResponse];
            }
        } else {
            // Fallback: model produced plain text despite tool_choice: required
            $spokenResponse = $assistantMsg['content'] ?? '';
            $history[]      = $assistantMsg;
        }

        $this->saveHistory($sessionId, $history);

        // Generate TTS for both the holding phrase and the final response
        $holdingTts = $holdingPhrase ? $this->generateTTS($holdingPhrase, $apiKey) : null;
        $ttsAudio   = $this->generateTTS($spokenResponse, $apiKey);

        return [
            'spoken_response' => $spokenResponse,
            'holding_phrase'  => $holdingPhrase,
            'holding_tts'     => $holdingTts,
            'tts_audio'       => $ttsAudio,
            'route'           => $route,
            'transcript'      => $transcript,
        ];
    }

    private function buildMessages(array $history, ?float $userLat, ?float $userLng): array
    {
        $systemContent = $this->systemPrompt();

        if ($userLat !== null && $userLng !== null) {
            $systemContent .= "\n\nUSER GPS: The user's current coordinates are ({$userLat}, {$userLng}). When they say 'here', 'my location', 'from here', etc., pass 'current location' as the `from` parameter — the backend will resolve the coords automatically.";
        }

        return array_merge(
            [['role' => 'system', 'content' => $systemContent]],
            $history,
        );
    }

    private function saveHistory(string $sessionId, array $history): void
    {
        if (count($history) > 24) {
            $history = array_slice($history, -24);
        }
        Cache::put("kwame:{$sessionId}", $history, self::SESSION_TTL);
    }

    private function isAliasKeyword(string $text): bool
    {
        return in_array($text, ['home', 'work', 'office', 'school'], true);
    }

    private function isContextualLocation(string $text): bool
    {
        $patterns = [
            'current location', 'current loc', 'my location', 'my position',
            'here', 'from here', 'where i am', 'where i\'m', 'my current',
        ];
        foreach ($patterns as $p) {
            if (str_contains($text, $p)) return true;
        }
        return false;
    }

    private function resolveAndPlanRoute(
        array  $args,
        ?float $userLat,
        ?float $userLng,
        ?array $fromCoordsOverride = null,  // pre-resolved coords (alias or GPS); skips geocoding
        ?array $toCoordsOverride   = null,
    ): ?array {
        $fromText = strtolower(trim($args['from'] ?? ''));
        $toText   = strtolower(trim($args['to']   ?? ''));

        // Hard block — alias keywords without an override must never reach the geocoding service
        if (($this->isAliasKeyword($fromText) && !$fromCoordsOverride) ||
            ($this->isAliasKeyword($toText)   && !$toCoordsOverride)) {
            Log::warning('Alias keyword reached geocoding without a coords override', [
                'from' => $args['from'], 'to' => $args['to'],
            ]);
            return null;
        }

        // Resolve origin
        if ($fromCoordsOverride) {
            $fromCoords = $fromCoordsOverride;
        } elseif ($this->isContextualLocation($fromText)) {
            if (!$userLat || !$userLng) {
                Log::warning('Current location requested but no GPS coords provided');
                return null;
            }
            $fromCoords = ['lat' => $userLat, 'lng' => $userLng, 'name' => 'Current Location'];
        } else {
            $fromCoords = $this->geoService->getCoordinates($args['from'], $userLat, $userLng);
        }

        // Resolve destination
        $toCoords = $toCoordsOverride ?? $this->geoService->getCoordinates($args['to'], $userLat, $userLng);

        if (!$fromCoords || !$toCoords) {
            Log::warning('Geocoding failed inside tool execution', [
                'from' => $args['from'], 'to' => $args['to'],
            ]);
            return null;
        }

        $routes = $this->transitEngine->findJourney(
            (float) $fromCoords['lat'],
            (float) $fromCoords['lng'],
            (float) $toCoords['lat'],
            (float) $toCoords['lng'],
            null,
            null,
            (float) ($args['walkReluctance'] ?? 13.5),
        );

        return $routes[0] ?? null;
    }

    private function randomHoldingPhrase(string $from, string $to): string
    {
        $phrases = [
            "Let me check the best matatu routes from {$from} to {$to} real quick...",
            "Looking up departures from {$from} to {$to}, just a second...",
            "On it! Checking live traffic from {$from} to {$to}...",
            "Checking the routes for you now...",
        ];
        return $phrases[array_rand($phrases)];
    }

    private function callChat(string $apiKey, array $messages, string|array $toolChoice = 'required'): ?array
    {
        try {
            $response = Http::withoutVerifying()
                ->withToken($apiKey)
                ->timeout(45)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'       => $this->model,
                    'messages'    => $messages,
                    'tools'       => $this->tools(),
                    'tool_choice' => $toolChoice,
                    'temperature' => 0.7,
                    'max_tokens'  => 300,
                ]);

            if (!$response->successful()) {
                Log::error('OpenAI Chat failed', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            return $response->json();

        } catch (Exception $e) {
            Log::error('OpenAI Chat error: ' . $e->getMessage());
            return null;
        }
    }

    public function transcribeWithWhisper(string $apiKey, string $base64Audio, string $mime = 'audio/m4a'): ?string
    {
        $ext = match (true) {
            str_contains($mime, 'mp3'), str_contains($mime, 'mpeg') => 'mp3',
            str_contains($mime, 'wav')  => 'wav',
            str_contains($mime, 'webm') => 'webm',
            str_contains($mime, 'ogg')  => 'ogg',
            default                     => 'm4a',
        };

        $tmpFile = sys_get_temp_dir() . '/' . uniqid('kwame_', true) . '.' . $ext;
        file_put_contents($tmpFile, base64_decode($base64Audio));

        try {
            $response = Http::withoutVerifying()
                ->withToken($apiKey)
                ->attach('file', file_get_contents($tmpFile), 'audio.' . $ext)
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model'           => 'whisper-1',
                    'response_format' => 'json',
                    'language'        => 'en',
                ]);

            if (!$response->successful()) {
                Log::warning('Whisper failed', ['status' => $response->status()]);
                return null;
            }

            return trim($response->json('text') ?? '');

        } catch (Exception $e) {
            Log::warning('Whisper error: ' . $e->getMessage());
            return null;
        } finally {
            @unlink($tmpFile);
        }
    }

    public function generateTTS(string $text, ?string $apiKey = null): ?string
    {
        $apiKey ??= env('OPENAI_API_KEY');
        if (!$apiKey) return null;
        if (strlen($text) > 4096) $text = substr($text, 0, 4096);

        try {
            $response = Http::withoutVerifying()
                ->withToken($apiKey)
                ->withBody(json_encode([
                    'model'           => 'tts-1',
                    'input'           => $text,
                    'voice'           => 'nova',
                    'response_format' => 'mp3',
                    'speed'           => 0.95,
                ]), 'application/json')
                ->timeout(10)
                ->post('https://api.openai.com/v1/audio/speech');

            if (!$response->successful()) {
                Log::warning('TTS failed', ['status' => $response->status()]);
                return null;
            }

            return base64_encode($response->body());

        } catch (Exception $e) {
            Log::warning('TTS error: ' . $e->getMessage());
            return null;
        }
    }
}
