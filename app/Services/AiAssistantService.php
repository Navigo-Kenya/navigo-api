<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class AiAssistantService
{
    // Define both models for dynamic swapping
    protected string $audioModel = 'gpt-audio-mini';
    protected string $textModel  = 'gpt-4o-mini';

    const SESSION_TTL = 1800; // 30 minutes
    const ROUTE_CACHE_TTL = 600; // 10 minutes for live transit variations

    public function __construct(
        private GeocodingService     $geoService,
        private TransitEngineService $transitEngine,
    ) {}

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            You are Kwame, the voice assistant for Navigo, a public transit navigation platform.
            You are a warm, friendly, and knowledgeable guide for Nairobi's public transport network.
            Speak casually and empathetically, like a helpful local friend.

            CRITICAL INSTRUCTIONS:
            - You have access to a tool called `get_route`. Use it whenever a destination is known or confirmed.
            - If the user specifies a destination but does not mention where they are starting from, assume they are starting from their "current location".
            - If the user asks a general question, greets you, or doesn't mention any destination or travel intent, DO NOT call a tool. Simply reply directly in a conversational tone.
            - When explaining route results from OpenTripPlanner, you MUST use local Nairobi phrasing. Reference major stages (e.g., Commercial, Kencom, Khoja, Archives, Afya Centre) and explicitly mention Matatu route numbers.
            - Keep spoken responses brief, natural, and highly conversational (1 to 3 sentences maximum).

            ROUTING RULES:
            1. CURRENT LOCATION: If the user says "here", "my location", "where I am", or omits their starting point entirely, pass "current location" as the parameter.
            2. SAVED LOCATIONS: Words like "home", "work", "school", or "office" are alias keywords. Pass them exactly as-is to `get_route`.
        PROMPT;
    }

    private function tools(): array
    {
        return [
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_route',
                    'description' => 'Calculate the transit route between two locations. Call ONLY when BOTH origin and destination are known.',
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
                                'description' => 'A short, warm text sentence to show the user while the route loads (e.g., "Checking matatus from Westlands to Kencom...").',
                            ],
                            'walkReluctance' => [
                                'type'        => 'number',
                                'description' => 'Walk reluctance factor (default 13.5). Increase to 20-25 if user mentions heavy bags or minimal walking.',
                            ],
                        ],
                        'required' => ['from', 'to', 'holding_phrase'],
                    ],
                ],
            ]
        ];
    }

    public function chat(
        string  $sessionId,
        ?string $text      = null,
        ?array  $audioFile = null,
        ?float  $userLat   = null,
        ?float  $userLng   = null,
        array   $aliases   = [],
    ): ?array {

        $apiKey = config('services.openai.key');

        if (!$apiKey) {
            throw new Exception("OpenAI API Key is missing. Check your .env file.");
        }

        // Strategy 1: Global Text Response Cache (Bypass OpenAI completely for simple text queries)
        if ($text && empty($audioFile['base64'])) {
            $textCacheKey = 'navigo_text_cache:' . md5(strtolower(trim($text)));
            if (Cache::has($textCacheKey)) {
                Log::info('Conversational text cache hit', ['session' => $sessionId]);
                return Cache::get($textCacheKey);
            }
        }

        $history = Cache::get("kwame:{$sessionId}", []);

        if (!empty($audioFile['base64'])) {
            $userMessage = [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_audio',
                        'input_audio' => [
                            'data'   => $audioFile['base64'],
                            'format' => 'wav',
                        ]
                    ]
                ]
            ];
        } else if ($text) {
            $userMessage = ['role' => 'user', 'content' => $text];
        } else {
            return null;
        }

        $history[] = $userMessage;

        $hasAudio = !empty($audioFile['base64']);

        // Call 1: Intent Extraction or Direct Chat
        $messages = $this->buildMessages($history, $userLat, $userLng);
        $firstResponse = $this->callAudioChat($messages, $hasAudio);

        if (!$firstResponse) return null;

        $assistantMsg = $firstResponse['choices'][0]['message'];
        $holdingPhrase = null;
        $routes = [];

        if (!empty($assistantMsg['tool_calls'])) {
            $toolCall = $assistantMsg['tool_calls'][0];

            if ($toolCall['function']['name'] === 'get_route') {
                $args = json_decode($toolCall['function']['arguments'], true) ?? [];
                $holdingPhrase = $args['holding_phrase'] ?? "Checking routes for you...";

                // Resolve coordinates and check for missing personal aliases
                $resolvedCoords = $this->extractCoordinates($args, $userLat, $userLng, $aliases);
                if (isset($resolvedCoords['error'])) {
                    $toolContent = json_encode(['success' => false, 'reason' => 'unresolved_alias', 'message' => $resolvedCoords['error']]);
                } else {
                    // Strategy 2: Geospatial Grid Caching
                    $geoCacheKey = sprintf(
                        "navigo_geo_route:%s:%s",
                        round($resolvedCoords['from']['lat'], 3) . ',' . round($resolvedCoords['from']['lng'], 3),
                        round($resolvedCoords['to']['lat'], 3) . ',' . round($resolvedCoords['to']['lng'], 3)
                    );

                    if (Cache::has($geoCacheKey)) {
                        Log::info('Geospatial route cache hit (Saved OTP + OpenAI Call 2)', ['session' => $sessionId]);
                        $cachedData = Cache::get($geoCacheKey);

                        $this->saveLightweightHistory($sessionId, $history, $cachedData['spoken_response']);

                        return [
                            'spoken_response' => $cachedData['spoken_response'],
                            'tts_audio'       => $cachedData['tts_audio'],
                            'holding_phrase'  => $holdingPhrase,
                            'routes'          => $cachedData['routes'] ?? [],
                        ];
                    }

                    // Cache Miss: Query OpenTripPlanner
                    $routes = $this->executeTransitPlan($resolvedCoords['from'], $resolvedCoords['to'], $args['walkReluctance'] ?? 13.5);
                    $toolContent = !empty($routes)
                        ? json_encode(['success' => true, 'routes_found' => count($routes), 'options' => $routes])
                        : json_encode(['success' => false, 'message' => 'No transit routes found right now.']);
                }

                $history[] = $assistantMsg;
                $history[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content'      => $toolContent,
                ];

                // Call 2: Generate final vocal summary from OTP data
                $secondMessages = $this->buildMessages($history, $userLat, $userLng);
                $secondResponse = $this->callAudioChat($secondMessages, $hasAudio);
                $finalAssistantMsg = $secondResponse['choices'][0]['message'] ?? [];
            }
        } else {
            $finalAssistantMsg = $assistantMsg;
        }

        $audioData = $finalAssistantMsg['audio']['data'] ?? null;
        $transcript = $finalAssistantMsg['audio']['transcript'] ?? $finalAssistantMsg['content'] ?? '';

        $this->saveLightweightHistory($sessionId, $history, $transcript);

        $output = [
            'spoken_response' => $transcript,
            'tts_audio'       => $audioData,
            'holding_phrase'  => $holdingPhrase,
            'routes'          => $routes,
        ];

        if ($text && empty($audioFile['base64']) && empty($routes)) {
            Cache::put($textCacheKey, $output, self::ROUTE_CACHE_TTL);
        }

        if (!empty($routes) && isset($geoCacheKey)) {
            Cache::put($geoCacheKey, $output, self::ROUTE_CACHE_TTL);
        }

        return $output;
    }

/**
     * Extracts and validates both origin and destination coordinates.
     */
    private function extractCoordinates(array $args, ?float $userLat, ?float $userLng, array $aliases): array
    {
        // Fallback to 'current location' if the AI or payload omits the 'from' key
        $fromNorm = strtolower(trim($args['from'] ?? 'current location'));
        $toNorm   = strtolower(trim($args['to']   ?? ''));

        // If the AI completely failed to capture a destination, return an error immediately
        if (empty($toNorm)) {
            return ['error' => "I couldn't figure out where you want to go. Could you tell me your destination?"];
        }

        // Resolve both identically using our clean coordinate handler
        $fromCoords = $this->resolveCoordinate($fromNorm, $userLat, $userLng, $aliases);
        $toCoords   = $this->resolveCoordinate($toNorm, $userLat, $userLng, $aliases);

        // Granular error handling for missing data
        if (!$fromCoords || !$toCoords) {
            if ($this->isAliasKeyword($fromNorm) && !isset($aliases[$fromNorm])) {
                return ['error' => "Origin saved location is missing."];
            }
            if ($this->isAliasKeyword($toNorm) && !isset($aliases[$toNorm])) {
                return ['error' => "Destination saved location is missing."];
            }
            if (($this->isContextualLocation($fromNorm) || $this->isContextualLocation($toNorm)) && (!$userLat || !$userLng)) {
                return ['error' => "GPS is unavailable. Please check your device permissions."];
            }

            return ['error' => "Could not resolve the addresses on the map."];
        }

        return ['from' => $fromCoords, 'to' => $toCoords];
    }

    /**
     * Resolves a single location string into verified coordinates.
     */
    private function resolveCoordinate(string $locationName, ?float $userLat, ?float $userLng, array $aliases): ?array
    {
        // 1. Check if it's an alias keyword (home, work, etc.)
        if ($this->isAliasKeyword($locationName)) {
            return $aliases[$locationName] ?? null;
        }

        // 2. Check if it's a semantic GPS keyword (here, current location, etc.)
        if ($this->isContextualLocation($locationName)) {
            if (!$userLat || !$userLng) return null; // Triggers the GPS missing error downstream
            return ['lat' => $userLat, 'lng' => $userLng, 'name' => 'Current Location'];
        }

        // 3. Fallback: Query the database/geocoding service for literal strings (e.g., "Westlands")
        return $this->geoService->getCoordinates($locationName, $userLat, $userLng);
    }

    private function executeTransitPlan(array $from, array $to, float $walkReluctance): array
    {
        return $this->transitEngine->findJourney(
            (float) $from['lat'],
            (float) $from['lng'],
            (float) $to['lat'],
            (float) $to['lng'],
            null,
            null,
            $walkReluctance
        );
    }

    private function callAudioChat(array $messages, bool $needsAudio = true): ?array
    {
        try {
            // 1. Dynamically swap models to save costs and prevent 400 errors
            $targetModel = $needsAudio ? $this->audioModel : $this->textModel;

            $payload = [
                'model'       => $targetModel,
                'messages'    => $messages,
                'tools'       => $this->tools(),
                'tool_choice' => 'auto',
            ];

            // 2. Only append audio configuration if we are using the audio model
            if ($needsAudio) {
                $payload['modalities'] = ['text', 'audio'];
                $payload['audio']      = ['voice' => 'alloy', 'format' => 'wav'];
            }

            $response = Http::withoutVerifying()
                ->withToken(config('services.openai.key'))
                ->timeout(45)
                ->post('https://api.openai.com/v1/chat/completions', $payload);

            if (!$response->successful()) {
                Log::error('OpenAI API Rejected Request', [
                    'status' => $response->status(),
                    'body'   => $response->json()
                ]);
                return null;
            }

            return $response->json();
        } catch (Exception $e) {
            Log::error('OpenAI Call Failure: ' . $e->getMessage());
            return null;
        }
    }

    private function buildMessages(array $history, ?float $userLat, ?float $userLng): array
    {
        $systemContent = $this->systemPrompt();
        if ($userLat !== null && $userLng !== null) {
            $systemContent .= "\n\nUSER GPS: Coordinates ({$userLat}, {$userLng}).";
        }
        return array_merge([['role' => 'system', 'content' => $systemContent]], $history);
    }

    private function saveLightweightHistory(string $sessionId, array $history, string $finalTranscript): void
    {
        foreach ($history as &$msg) {
            if ($msg['role'] === 'user' && is_array($msg['content'])) {
                $msg['content'] = '[User Audio Input]';
            }
        }
        $history[] = ['role' => 'assistant', 'content' => $finalTranscript];
        Cache::put("kwame:{$sessionId}", array_slice($history, -20), self::SESSION_TTL);
    }

    private function isAliasKeyword(string $text): bool { return in_array($text, ['home', 'work', 'office', 'school'], true); }

/**
     * Helper to identify contextual text representations of live GPS.
     */
    private function isContextualLocation(string $name): bool
    {
        return in_array($name, ['current location', 'here', 'my location', 'where i am']);
    }
}
