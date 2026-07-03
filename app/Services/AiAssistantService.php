<?php

namespace App\Services;

use App\Services\AI\GoogleLlmService;
use App\Services\AI\GoogleTtsService;
use App\Services\Kwame\KwameToolsService;
use App\Models\SavedPlace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

class AiAssistantService
{
    const SESSION_TTL     = 1800;
    const ROUTE_CACHE_TTL = 600;
    /** Max tool-call rounds per request (place search → route plan = 2). */
    const MAX_TOOL_ROUNDS = 3;

    public function __construct(
        private GeocodingService     $geoService,
        private TransitEngineService $transitEngine,
        private GoogleLlmService     $llm,
        private GoogleTtsService     $tts,
        private KwameToolsService    $kwameTools,
    ) {}

    /**
     * Build the dynamic prompt based on personality style, language code, and
     * device context (calendar events).
     */
    private function systemPrompt(?string $responseStyle = null, string $languageCode = 'en-US', ?array $context = null): string
    {
        $base = <<<'PROMPT'
            You are Kwame, the smart travel companion inside Navigo, a public-transport navigation platform for Nairobi.
            You are a warm, friendly, knowledgeable local guide — but you are more than a route planner: you help people
            decide WHERE to go (places, food, errands) and HOW to get there (matatu/bus routes, stops).
            Speak casually and empathetically, like a helpful local friend.

            YOUR TOOLS:
            - get_route: calculate transit journeys. Call ONLY when both origin and destination are known or confirmed.
            - find_places: search real places (restaurants, malls, hospitals, banks, coffee shops...). Use it when the user
              asks for suggestions or recommendations, or when their destination is vague ("somewhere to eat", "a good chemist").
            - find_nearby_stops: list the transit stops closest to the user. Use for "where's the nearest stage/stop?".
            - get_stop_routes: list which matatu routes serve a given stop/stage.
            - search_transit_routes: look up a matatu route by number or name (e.g. "46", "Kikuyu route").
            - get_weather: current weather at the user's location or a named place. Use when asked about weather,
              or proactively mention rain when it affects a trip you just planned (e.g. suggest carrying an umbrella).
            - suggest_replies: when the user's request is unclear or open-ended, call this with 2-4 short tappable reply
              options (e.g. ["Coffee nearby", "Route to Westlands", "Nearest stage"]) BEFORE or INSTEAD of guessing.

            CRITICAL ROUTING RULES:
            - If the user gives a destination without an origin, assume they start from "current location".
            - If the user says "here", "my location", or similar, pass "current location".
            - For general questions with no travel or place intent, reply conversationally without tools.

            CRITICAL NARRATION RULES:
            - When get_route returns journeys, DO NOT describe any single journey's legs, stops or transfers in detail.
              The app already renders interactive route cards below your message. Instead say something brief like:
              "To get to [destination], you can use the following journeys — the quickest takes about [N] minutes."
            - When find_places returns results, do not read out addresses or coordinates; the app shows place cards.
              Give a one-sentence intro (e.g. "Here are a few great coffee spots near you") and offer to plan a route.
            - Keep spoken responses brief, natural, highly conversational (1 to 3 sentences maximum).
            - Use local Nairobi phrasing. Reference stages (Commercial, Kencom, Khoja) and Matatu route numbers naturally.
        PROMPT;

        $suffix = match ($responseStyle) {
            'professional' => 'Use formal, precise language. Avoid colloquialisms.',
            'brief'        => 'Keep every response to one sentence maximum.',
            default        => '',
        };

        // ── Language Translation Enforcement ──
        $langInstruction = match ($languageCode) {
            'sw-KE' => 'CRITICAL: You MUST respond entirely in Swahili.',
            'fr-FR' => 'CRITICAL: You MUST respond entirely in French.',
            'en-KE' => 'CRITICAL: You MUST respond in English, but naturally sprinkle in Kenyan phrasing and vocabulary (like "Sasa", "Matatu", etc).',
            default => 'CRITICAL: You MUST respond entirely in English.',
        };

        $finalPrompt = $base;
        if ($suffix) {
            $finalPrompt .= "\n\n" . $suffix;
        }
        $finalPrompt .= "\n\n" . $langInstruction;

        $finalPrompt .= "\n\nCurrent Nairobi time: " . now()->timezone('Africa/Nairobi')->format('l, H:i') . '.';

        // ── Device context: upcoming calendar events ──
        if (!empty($context['calendar_events']) && is_array($context['calendar_events'])) {
            $lines = [];
            foreach (array_slice($context['calendar_events'], 0, 5) as $ev) {
                if (!is_array($ev)) continue;
                $lines[] = sprintf(
                    '- "%s" at %s%s',
                    $ev['title'] ?? 'Untitled',
                    $ev['start'] ?? 'unknown time',
                    !empty($ev['location']) ? " (location: {$ev['location']})" : ''
                );
            }
            if ($lines) {
                $finalPrompt .= "\n\nUSER'S UPCOMING CALENDAR EVENTS (next 24 hours):\n" . implode("\n", $lines)
                    . "\nIf the user mentions a meeting or event (\"get me to my meeting\"), use the matching event's location as the destination and factor in its start time. If the event has no location, ask for one.";
            }
        }

        return $finalPrompt;
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
                            'from'           => ['type' => 'string', 'description' => 'Origin location name or "current location"'],
                            'to'             => ['type' => 'string', 'description' => 'Destination location name or "current location"'],
                            'holding_phrase' => ['type' => 'string', 'description' => 'A short, warm text sentence to show the user while the route loads.'],
                            'walkReluctance' => ['type' => 'number', 'description' => 'Walk reluctance factor.'],
                        ],
                        'required' => ['from', 'to', 'holding_phrase'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'find_places',
                    'description' => 'Search for real-world places (restaurants, cafes, malls, hospitals, banks...) near the user or in a named area. Use when the user asks for suggestions or their destination is vague.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'What to search, e.g. "coffee shop in Westlands" or "chemist near CBD".'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'find_nearby_stops',
                    'description' => 'List the transit stops/stages closest to the user\'s current location.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'radius_m' => ['type' => 'number', 'description' => 'Search radius in metres (default 1200).'],
                        ],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_stop_routes',
                    'description' => 'List which matatu/bus routes serve a given stop or stage, by stop name.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'stop_name' => ['type' => 'string', 'description' => 'Name of the stop/stage, e.g. "Kencom".'],
                        ],
                        'required' => ['stop_name'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'search_transit_routes',
                    'description' => 'Search matatu/bus routes by route number or name, e.g. "46" or "Kikuyu".',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Route number or partial name.'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_weather',
                    'description' => 'Get current weather conditions. Defaults to the user\'s location; pass a place name to check somewhere else.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'location' => ['type' => 'string', 'description' => 'Optional place name (e.g. "Westlands"). Omit for the user\'s current location.'],
                        ],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'suggest_replies',
                    'description' => 'Offer the user 2-4 short tappable reply chips when their request is unclear or open-ended. The chips appear as buttons under your message.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'suggestions' => [
                                'type'        => 'array',
                                'items'       => ['type' => 'string'],
                                'description' => '2-4 short reply options, each under 30 characters.',
                            ],
                        ],
                        'required' => ['suggestions'],
                    ],
                ],
            ],
        ];
    }

    public function chat(
        string  $sessionId,
        ?string $text          = null,
        ?array  $audioFile     = null,
        ?float  $userLat       = null,
        ?float  $userLng       = null,
        array   $aliases       = [],
        ?array  $voiceSettings = null,
        ?array  $context       = null,
    ): ?array {
        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }

        if (empty(config('services.google_cloud.project_id'))) {
            throw new Exception('GCP_PROJECT_ID is not set.');
        }

        $hasCalendar = !empty($context['calendar_events']);

        // ── Text cache (skip audio turns and calendar-dependent turns) ─────────
        $textCacheKey = null;
        if ($text && empty($audioFile['base64']) && !$hasCalendar) {
            $textCacheKey = 'navigo_text_cache:' . md5(strtolower(trim($text)));
            if (Cache::has($textCacheKey)) return Cache::get($textCacheKey);
        }

        $history  = Cache::get("kwame:{$sessionId}", []);
        $hasAudio = !empty($audioFile['base64']);

        // ── Gemini accepts audio natively — no STT step needed ────────────────
        if (!$hasAudio && empty($text)) return null;

        $history[] = ['role' => 'user', 'content' => $text ?? '[Voice message]'];

        $responseStyle = $voiceSettings['response_style'] ?? null;
        $languageCode  = $voiceSettings['language_code'] ?? 'en-US';
        $systemPrompt  = $this->systemPrompt($responseStyle, $languageCode, $context);

        // Accumulated across tool rounds
        $holdingPhrase = null;
        $routes        = [];
        $places        = [];
        $suggestions   = [];
        $geoCacheKey   = null;
        $finalText     = '';

        // ── Tool loop: LLM may chain tools (e.g. find_places → get_route) ─────
        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            // Force a plain narration turn once the round budget is spent.
            $allowTools = $round < self::MAX_TOOL_ROUNDS;

            try {
                $response = $this->llm->chat(
                    $history,
                    $systemPrompt,
                    $allowTools ? $this->tools() : [],
                    $allowTools,
                    ($round === 0 && $hasAudio) ? $audioFile : null,
                );
            } catch (\RuntimeException $e) {
                $msg = match ($e->getMessage()) {
                    'VERTEX_QUOTA_EXCEEDED' => "I'm getting a lot of requests right now — please try again in a moment!",
                    'VERTEX_TIMEOUT'        => "That took too long on my end. Please try again!",
                    default                 => null,
                };
                if ($msg !== null) {
                    $this->saveLightweightHistory($sessionId, $history, $msg);
                    return $this->buildOutput($msg, null, null, [], [], []);
                }
                throw $e;
            }

            if (!$response) return null;

            if (empty($response['functionCall'])) {
                $finalText = $response['text'] ?? '';
                break;
            }

            $toolName = $response['functionCall']['name'];
            $args     = $response['functionCall']['args'] ?? [];

            // ── Execute the requested tool ─────────────────────────────────────
            switch ($toolName) {
                case 'get_route':
                    $holdingPhrase  = $args['holding_phrase'] ?? 'Checking routes for you...';
                    $resolvedCoords = $this->extractCoordinates($args, $userLat, $userLng, $aliases);

                    // Unresolved location → return action UI payload immediately
                    if (isset($resolvedCoords['actionRequired'])) {
                        $actionRequired = $resolvedCoords['actionRequired'];
                        $transcript = match ($languageCode) {
                            'sw-KE' => $actionRequired['isAuthenticated']
                                ? "Sikuweza kupata '{$actionRequired['unresolvedName']}'. Je, ungependa kuchagua moja ya maeneo uliyohifadhi?"
                                : "Sikuweza kupata '{$actionRequired['unresolvedName']}'. Tafadhali ingia katika akaunti yako ili kupata maeneo yako yaliyohifadhiwa.",
                            'fr-FR' => $actionRequired['isAuthenticated']
                                ? "Je n'ai pas pu situer '{$actionRequired['unresolvedName']}'. Souhaitez-vous sélectionner l'un de vos lieux enregistrés ?"
                                : "Je n'ai pas pu situer '{$actionRequired['unresolvedName']}'. Veuillez vous connecter pour accéder à vos lieux enregistrés.",
                            default => $actionRequired['isAuthenticated']
                                ? "I couldn't locate '{$actionRequired['unresolvedName']}'. Would you like to select one of your saved places instead?"
                                : "I couldn't locate '{$actionRequired['unresolvedName']}'. Please sign in to access your custom saved places.",
                        };

                        $this->saveLightweightHistory($sessionId, $history, $transcript);
                        $out = $this->buildOutput($transcript, null, null, [], $places, $suggestions);
                        $out['actionRequired'] = $actionRequired;
                        return $out;
                    }

                    if (isset($resolvedCoords['error'])) {
                        $toolContent = json_encode(['success' => false, 'message' => $resolvedCoords['error']]);
                        break;
                    }

                    // ── Geo-route cache ────────────────────────────────────────
                    $geoCacheKey = sprintf(
                        'navigo_geo_route:%s:%s',
                        round($resolvedCoords['from']['lat'], 3) . ',' . round($resolvedCoords['from']['lng'], 3),
                        round($resolvedCoords['to']['lat'],   3) . ',' . round($resolvedCoords['to']['lng'],   3)
                    );

                    if (Cache::has($geoCacheKey)) {
                        $cached = Cache::get($geoCacheKey);
                        $this->saveLightweightHistory($sessionId, $history, $cached['spoken_response']);
                        return $this->buildOutput(
                            $cached['spoken_response'],
                            $cached['tts_audio'],
                            $holdingPhrase,
                            $cached['routes'] ?? [],
                            $places,
                            $suggestions,
                        );
                    }

                    $routes = $this->executeTransitPlan($resolvedCoords['from'], $resolvedCoords['to'], $args['walkReluctance'] ?? 13.5);

                    // Compact per-option summaries for the LLM — the full route
                    // objects go straight to the app; narration only needs the gist.
                    $toolContent = !empty($routes)
                        ? json_encode([
                            'success'      => true,
                            'routes_found' => count($routes),
                            'destination'  => $resolvedCoords['to']['name'] ?? $args['to'],
                            'options'      => $this->summarizeRoutesForLlm($routes),
                            'note'         => 'The app displays full route cards. Introduce the options collectively; do not detail any single journey.',
                        ])
                        : json_encode(['success' => false, 'message' => 'No transit routes found right now.']);
                    break;

                case 'find_places':
                    $found  = $this->kwameTools->findPlaces((string) ($args['query'] ?? ''), $userLat, $userLng);
                    $places = $found;
                    $toolContent = json_encode([
                        'success' => !empty($found),
                        'places_found' => count($found),
                        'places'  => array_map(fn ($p) => [
                            'name'     => $p['name'],
                            'category' => $p['category'],
                            'rating'   => $p['rating'],
                            'open_now' => $p['open_now'],
                        ], $found),
                        'note' => 'The app displays place cards with addresses and a Directions button. Keep your intro to one sentence.',
                    ]);
                    break;

                case 'find_nearby_stops':
                    $stops = $this->kwameTools->findNearbyStops($userLat, $userLng, (int) ($args['radius_m'] ?? 1200));
                    // Surface stops as place cards too, so the user can tap Directions.
                    $places = array_map(fn ($s) => [
                        'name'     => $s['name'],
                        'address'  => round($s['distance_m']) . ' m away',
                        'lat'      => $s['lat'],
                        'lng'      => $s['lng'],
                        'rating'   => null,
                        'ratings_count' => null,
                        'category' => 'Transit stop',
                        'open_now' => null,
                    ], $stops);
                    $toolContent = json_encode(['success' => !empty($stops), 'stops' => $stops]);
                    break;

                case 'get_stop_routes':
                    $result      = $this->kwameTools->getStopRoutes((string) ($args['stop_name'] ?? ''));
                    $toolContent = json_encode(['success' => $result['stop'] !== null] + $result);
                    break;

                case 'search_transit_routes':
                    $found       = $this->kwameTools->searchTransitRoutes((string) ($args['query'] ?? ''));
                    $toolContent = json_encode(['success' => !empty($found), 'routes' => $found]);
                    break;

                case 'get_weather':
                    $wLat = $userLat;
                    $wLng = $userLng;
                    if (!empty($args['location'])) {
                        $coords = $this->geoService->getCoordinates((string) $args['location'], $userLat, $userLng);
                        if ($coords) { $wLat = $coords['lat']; $wLng = $coords['lng']; }
                    }
                    $weather     = $this->kwameTools->getWeather($wLat, $wLng);
                    $toolContent = json_encode(['success' => !isset($weather['error'])] + $weather);
                    break;

                case 'suggest_replies':
                    $suggestions = collect($args['suggestions'] ?? [])
                        ->filter(fn ($s) => is_string($s) && trim($s) !== '')
                        ->map(fn ($s) => mb_substr(trim($s), 0, 40))
                        ->take(4)
                        ->values()
                        ->all();
                    $toolContent = json_encode(['success' => true, 'note' => 'Chips are now shown to the user. Write one short sentence inviting them to pick one (or answer freely).']);
                    break;

                default:
                    $toolContent = json_encode(['success' => false, 'message' => "Unknown tool: {$toolName}"]);
            }

            // ── Append tool turns for the next LLM round ───────────────────────
            $history[] = [
                'role'       => 'assistant',
                'tool_calls' => [[
                    'function' => [
                        'name'      => $toolName,
                        'arguments' => json_encode($args),
                    ],
                ]],
            ];
            $history[] = [
                'role'         => 'tool',
                'name'         => $toolName,
                'tool_call_id' => 'call_' . ($round + 1),
                'content'      => $toolContent,
            ];
        }

        if (empty(trim($finalText))) {
            $finalText = match ($languageCode) {
                'sw-KE' => "Sina uhakika jinsi ya kusaidia na hilo. Je, unaweza kueleza tena?",
                'fr-FR' => "Je ne sais pas comment vous aider. Pourriez-vous reformuler ?",
                default => "I'm not sure how to help with that. Could you rephrase?",
            };
        }

        // ── TTS — text → audio ─────────────────────────────────────────────────
        $audioData = $this->tts->synthesize(
            $finalText,
            (float) ($voiceSettings['speaking_rate'] ?? 1.05),
            $voiceSettings['voice_name']    ?? 'en-US-Neural2-D',
            (float) ($voiceSettings['pitch'] ?? 0.0),
            $languageCode,
        );

        $this->saveLightweightHistory($sessionId, $history, $finalText);

        $output = $this->buildOutput($finalText, $audioData, $holdingPhrase, $routes, $places, $suggestions);

        if ($textCacheKey && empty($routes) && empty($places) && empty($suggestions)) {
            Cache::put($textCacheKey, $output, self::ROUTE_CACHE_TTL);
        }
        if (!empty($routes) && $geoCacheKey) {
            Cache::put($geoCacheKey, $output, self::ROUTE_CACHE_TTL);
        }

        return $output;
    }

    /** Uniform response payload shape across every return path. */
    private function buildOutput(
        string  $spokenResponse,
        ?string $ttsAudio,
        ?string $holdingPhrase,
        array   $routes,
        array   $places,
        array   $suggestions,
    ): array {
        return [
            'spoken_response' => $spokenResponse,
            'tts_audio'       => $ttsAudio,
            'holding_phrase'  => $holdingPhrase,
            'routes'          => $routes,
            'places'          => $places,
            'suggestions'     => $suggestions,
        ];
    }

    /** Compact one-line-per-option digest so the LLM never sees full leg JSON. */
    private function summarizeRoutesForLlm(array $routes): array
    {
        return collect($routes)->take(4)->values()->map(function ($r, $i) {
            $legs  = $r['legs'] ?? $r['segments'] ?? [];
            $chain = collect($legs)->map(function ($l) {
                $mode = strtoupper($l['mode'] ?? '');
                return $mode === 'WALK'
                    ? 'Walk'
                    : trim('Matatu ' . ($l['route_name'] ?? $l['routeNumber'] ?? $l['route_short_name'] ?? ''));
            })->implode(' → ');

            return [
                'option'       => $i + 1,
                'summary'      => $r['summary'] ?? '',
                'duration_min' => (int) round(($r['total_duration'] ?? 0) / 60),
                'legs'         => $chain,
            ];
        })->all();
    }

    private function extractCoordinates(array $args, ?float $userLat, ?float $userLng, array $aliases): array
    {
        $fromNorm = strtolower(trim($args['from'] ?? 'current location'));
        $toNorm   = strtolower(trim($args['to']   ?? ''));

        if (empty($toNorm)) {
            return ['error' => "I couldn't figure out where you want to go. Could you tell me your destination?"];
        }

        $fromCoords = $this->resolveCoordinate($fromNorm, $userLat, $userLng, $aliases);
        if (!$fromCoords) return ['actionRequired' => $this->handleResolutionFailure($fromNorm, 'from')];

        $toCoords = $this->resolveCoordinate($toNorm, $userLat, $userLng, $aliases);
        if (!$toCoords) return ['actionRequired' => $this->handleResolutionFailure($toNorm, 'to')];

        return ['from' => $fromCoords, 'to' => $toCoords];
    }

    private function resolveCoordinate(string $locationName, ?float $userLat, ?float $userLng, array $aliases): ?array
    {
        if (array_key_exists($locationName, $aliases)) {
            return $aliases[$locationName];
        }

        if ($this->isContextualLocation($locationName)) {
            if (!$userLat || !$userLng) return null;
            return ['lat' => $userLat, 'lng' => $userLng, 'name' => 'Current Location'];
        }

        $user = auth('sanctum')->user();
        if ($user) {
            $savedPlace = SavedPlace::where('user_id', $user->id)
                ->where(function ($query) use ($locationName) {
                    $query->where('name', 'LIKE', $locationName)
                          ->orWhere('category', 'LIKE', $locationName);
                })
                ->first();

            if ($savedPlace) {
                return [
                    'lat'  => (float) $savedPlace->lat,
                    'lng'  => (float) $savedPlace->lng,
                    'name' => $savedPlace->name,
                ];
            }
        }

        return $this->geoService->getCoordinates($locationName, $userLat, $userLng);
    }

    private function handleResolutionFailure(string $unresolvedName, string $field): array
    {
        $user            = auth('sanctum')->user();
        $isAuthenticated = !is_null($user);

        return [
            'errorType'       => 'unresolved_location',
            'field'           => $field,
            'unresolvedName'  => $unresolvedName,
            'isAuthenticated' => $isAuthenticated,
            'savedPlaces'     => $isAuthenticated
                ? SavedPlace::where('user_id', $user->id)->get(['id', 'name', 'lat', 'lng', 'pin', 'category'])->toArray()
                : [],
        ];
    }

    private function isContextualLocation(string $name): bool
    {
        return in_array($name, ['current location', 'here', 'my location', 'where i am']);
    }

    private function executeTransitPlan(array $from, array $to, float $walkReluctance): array
    {
        $routes = $this->transitEngine->findJourney(
            (float) $from['lat'],
            (float) $from['lng'],
            (float) $to['lat'],
            (float) $to['lng'],
            null, null, $walkReluctance
        );

        foreach ($routes as &$route) {
            $segments = &$route['segments'];
            if (!empty($segments)) {
                if (isset($from['name'])) $segments[0]['from']['name']                  = $from['name'];
                if (isset($to['name']))   $segments[count($segments) - 1]['to']['name'] = $to['name'];
            }
        }

        return $routes;
    }

    private function saveLightweightHistory(string $sessionId, array $history, string $finalTranscript): void
    {
        $clean = [];
        foreach ($history as $msg) {
            if (in_array($msg['role'], ['user', 'assistant']) && !isset($msg['tool_calls'])) {
                $clean[] = [
                    'role'    => $msg['role'],
                    'content' => is_array($msg['content']) ? '[Voice message]' : ($msg['content'] ?? ''),
                ];
            }
        }
        $clean[] = ['role' => 'assistant', 'content' => $finalTranscript];
        Cache::put("kwame:{$sessionId}", array_slice($clean, -20), self::SESSION_TTL);
    }
}
