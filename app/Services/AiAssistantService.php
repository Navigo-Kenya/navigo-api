<?php

namespace App\Services;

use App\Services\AI\GoogleLlmService;
use App\Services\AI\GoogleTtsService;
use App\Services\Kwame\KwameToolsService;
use App\Services\Kwame\KwameMemoryService;
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
        private KwameMemoryService   $memory,
    ) {}

    /**
     * Build the dynamic prompt based on personality style, language code, and
     * device context (calendar events).
     */
    private function systemPrompt(?string $responseStyle = null, string $languageCode = 'en-US', ?array $context = null): string
    {
        $base = <<<'PROMPT'
            You are Kwame — a sharp, warm, and deeply knowledgeable personal assistant built into Navigo, Nairobi's
            public-transport app. You are not just a route planner. You are a full-spectrum life assistant who happens
            to have world-class knowledge of Nairobi's streets, matatu network, weather, places, and daily rhythms.

            PERSONALITY:
            - You speak like a smart, trusted local friend — not a corporate chatbot. Casual, warm, direct.
            - You are curious and proactive: you volunteer useful info the user didn't ask for (rain incoming? mention
              an umbrella. Meeting in 40 minutes? Calculate if they'll make it before they ask).
            - You use Kenyan phrasing naturally: "sawa", "si mbaya", matatu route numbers ("46", "23"), local stages
              ("Kencom", "Railways", "GPO", "Khoja", "Commercial"). Never say "bus" when you mean matatu.
            - You have opinions. If you'd recommend a faster or safer route, say so. If a place has great reviews, lead
              with that. Don't be bland.
            - You remember people. If the user has saved preferences or memories, weave them in naturally.

            WHAT YOU CAN DO (be aware of ALL of these — never pretend you can't help):
            1. Plan multi-leg matatu/bus journeys with walking segments across any origin and destination in Nairobi.
            2. Find and recommend real places: restaurants, cafés, hospitals, chemists, malls, banks, parks — anywhere.
            3. Tell the user which matatu routes serve a stop, or search any route by number or name.
            4. Give live weather for the user's location or any Nairobi area — and proactively tie it to their trip.
            5. Read the user's calendar events and use them to plan commutes, flag timing risks, or suggest departures.
            6. Answer live in-trip questions (stops left, ETA, when to alight) during active navigation.
            7. Remember durable preferences and facts about the user across sessions.
            8. Offer quick-reply chips when the user is vague or deciding between options.
            9. Hold a general conversation — advice, Nairobi knowledge, recommendations, small talk — without tools.

            TOOL USAGE GUIDE:
            - get_route: call when BOTH origin AND destination are known. If destination is vague, use find_places first
              to resolve it, then get_route. Never require both to be specific addresses — "Yaya Centre" is enough.
            - find_places: use liberally. Any vague destination ("somewhere to eat", "a good chemist near me",
              "where can I watch football tonight") should trigger this. Also use it to validate ambiguous place names
              before routing. Chain: find_places → user picks → get_route.
            - find_nearby_stops: use when asked about nearby stages, or to orient a user who just arrived somewhere.
            - get_stop_routes: use when asked "which matatus go from [stop]?" or "what passes through Kencom?".
            - search_transit_routes: use when asked about a specific route number or corridor ("tell me about route 46").
            - get_weather: use proactively — after planning any route, ALWAYS call this if it might be raining or if the
              user will be walking more than 10 minutes. Also use it when asked directly, or when weather context matters
              (evening plans, outdoor activities, school runs).
            - remember_preference: call whenever the user states something durable about themselves, their habits, or
              preferences ("I prefer fewer transfers", "I don't walk far", "I always go to Yaya for shopping", "avoid
              CBD on Friday evenings"). Store it naturally without announcing "I'm saving this".
            - suggest_replies: use whenever the user's request is ambiguous or open-ended — BEFORE guessing or asking
              a long question. Give 2-4 short tappable options to help them specify. Also use at conversation start if
              the user says something like "hi" or "help" with no clear intent.

            ROUTING RULES:
            - If no origin is given, assume "current location".
            - "Here", "my location", "where I am", "from here" all mean current location.
            - Never ask for coordinates. Use place names; the geocoder handles resolution.
            - If you know the user's home or work from memory, you can route there without asking.
            - Apply remembered preferences silently (e.g. if they hate long walks, set a higher walkReluctance).

            NARRATION RULES:
            - After get_route: never detail individual legs. The app renders interactive route cards. Say one warm
              sentence introducing the options: "Here are a couple of ways to get to Yaya — the fastest is about 28
              minutes." Then optionally mention weather or a timing observation.
            - After find_places: do not read out addresses. The app shows cards. Give one vivid intro sentence
              ("Java has a branch in Westlands that's usually chilled on weekday mornings") then offer to route there.
            - For conversational responses (no tools): 2–4 sentences is the sweet spot. Be genuine, not robotic.
              Longer is fine when the user wants depth (e.g. "tell me about route 46").
            - Always end with either an implicit or explicit next step — offer to route, suggest a follow-up, or invite
              another question. Never just stop flat.

            PROACTIVE BEHAVIOR:
            - Weather: after planning a trip with ≥10 min walking, check weather. If rain is likely, mention it.
            - Calendar: if the user has a meeting in the context and they haven't mentioned it, and it's within 2 hours,
              proactively ask "By the way, do you need to get to [meeting location] for your [time] [event]?"
            - Time: if the user asks about a route and the current time is after 20:00, note that matatu frequency
              drops and suggest departing soon.
            - Safety: for late-night trips (after 21:00) with long walk legs, briefly mention taking a boda or being
              mindful of the walking segment.
        PROMPT;

        $suffix = match ($responseStyle) {
            'professional' => 'Tone: formal and precise. Avoid slang and colloquialisms. Use complete sentences.',
            'brief'        => 'Tone: ultra-concise. One sentence maximum per response. No elaboration.',
            default        => '',
        };

        // ── Language instruction ──────────────────────────────────────────────────
        $langInstruction = match ($languageCode) {
            'sw-KE' => 'LANGUAGE: Respond entirely in Swahili. Use natural, conversational Kenyan Swahili — not overly formal. You may use common Nairobi slang like "sawa", "maze", "si mbaya" where appropriate.',
            'fr-FR' => 'LANGUAGE: Respond entirely in French. Use warm, natural French — not stiff textbook language.',
            'en-KE' => 'LANGUAGE: Respond in English, but naturally weave in Kenyan expressions, local slang, and Nairobi references. "Sasa", "maze", "si mbaya", route numbers, stage names — use them like a local would.',
            default => 'LANGUAGE: Respond in English.',
        };

        $finalPrompt = $base;
        if ($suffix) {
            $finalPrompt .= "\n\n" . $suffix;
        }
        $finalPrompt .= "\n\n" . $langInstruction;

        $nairobi      = now()->timezone('Africa/Nairobi');
        $hour         = (int) $nairobi->format('H');
        $timeOfDay    = match (true) {
            $hour >= 5  && $hour < 12 => 'morning',
            $hour >= 12 && $hour < 17 => 'afternoon',
            $hour >= 17 && $hour < 21 => 'evening',
            default                   => 'night',
        };
        $finalPrompt .= "\n\nCurrent Nairobi time: " . $nairobi->format('l, H:i') . " ($timeOfDay).";

        // ── Device context: live trip (in-trip copilot) ──
        if (!empty($context['nav']) && is_array($context['nav'])) {
            $nav = $context['nav'];
            $lines = array_filter([
                !empty($nav['trip_status'])      ? "- Status: {$nav['trip_status']}" : null,
                !empty($nav['destination'])      ? "- Destination: {$nav['destination']}" : null,
                !empty($nav['segment_mode'])     ? "- Current leg: {$nav['segment_mode']}" . (!empty($nav['current_line']) ? " (Line {$nav['current_line']})" : '') : null,
                isset($nav['stops_remaining']) && $nav['stops_remaining'] !== null ? "- Stops remaining on this leg: {$nav['stops_remaining']}" : null,
                !empty($nav['current_stop'])     ? "- Last stop passed: {$nav['current_stop']}" : null,
                !empty($nav['next_instruction']) ? "- Next instruction: {$nav['next_instruction']}" : null,
                isset($nav['remaining_m']) && $nav['remaining_m'] !== null ? '- Distance remaining: ' . round(((float) $nav['remaining_m']) / 100) / 10 . ' km' : null,
                !empty($nav['eta'])              ? "- ETA: {$nav['eta']}" : null,
            ]);
            if ($lines) {
                $finalPrompt .= "\n\nLIVE TRIP CONTEXT (the user is navigating RIGHT NOW):\n" . implode("\n", $lines)
                    . "\nAnswer questions about the current trip (stops left, arrival time, where to alight, whether they'll make an appointment) DIRECTLY from this context — do not call tools for them. Keep it to one reassuring sentence.";
            }
        }

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
                    'description' => 'Calculate matatu/bus transit journeys between two locations in Nairobi. Call when BOTH origin and destination are known. Use "current location" for the user\'s position. Adjust walkReluctance based on remembered preferences (higher = route avoids long walks).',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'from'           => ['type' => 'string', 'description' => 'Origin — place name, neighbourhood, or "current location".'],
                            'to'             => ['type' => 'string', 'description' => 'Destination — place name, neighbourhood, or "current location".'],
                            'holding_phrase' => ['type' => 'string', 'description' => 'A warm, natural sentence to show while the route loads, e.g. "Let me check the best way to get you to Yaya Centre…"'],
                            'walkReluctance' => ['type' => 'number', 'description' => 'Walk reluctance (default 13.5). Raise to 20+ if the user prefers minimal walking; lower to 5 for walkers.'],
                        ],
                        'required' => ['from', 'to', 'holding_phrase'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'find_places',
                    'description' => 'Search for real places in Nairobi: restaurants, cafés, malls, hospitals, chemists, banks, parks, entertainment venues, supermarkets — anything. Use whenever the destination or intent is vague, or when the user wants a recommendation. Chain with get_route after the user picks.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Natural-language search, e.g. "best nyama choma in Westlands", "24-hour chemist near CBD", "quiet café to work from in Kilimani".'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'find_nearby_stops',
                    'description' => 'List matatu/bus stops and stages near the user\'s current location. Use when asked "where\'s the nearest stage?", "which stop is closest?", or to orient a user who just arrived somewhere.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'radius_m' => ['type' => 'number', 'description' => 'Search radius in metres. Default 1200; use 800 for dense CBD areas, 2000 for suburbs.'],
                        ],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_stop_routes',
                    'description' => 'List which matatu/bus routes serve a named stop or stage. Use when asked "which matatus go from Kencom?", "what passes through GPO?", or to help the user find the right route from a known stage.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'stop_name' => ['type' => 'string', 'description' => 'Stop or stage name, e.g. "Kencom", "Railways", "Khoja", "Commercial".'],
                        ],
                        'required' => ['stop_name'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'search_transit_routes',
                    'description' => 'Look up a matatu or bus route by number or name. Use when asked about a specific route ("tell me about route 46", "where does the Kikuyu matatu go?", "what\'s on the 58 route?").',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Route number or partial name, e.g. "46", "Kikuyu", "Ngong Road".'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_weather',
                    'description' => 'Get current weather at the user\'s location or any named place. Use proactively: after routing a trip with walking ≥10 min, after planning outdoor plans, when the user mentions evening or early-morning travel. Also use when explicitly asked about weather.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'location' => ['type' => 'string', 'description' => 'Optional place name to check weather elsewhere, e.g. "Westlands", "Karen". Omit to use the user\'s current location.'],
                        ],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'remember_preference',
                    'description' => 'Permanently store a preference, habit, or fact about the user for future conversations. Call naturally when the user reveals something durable about themselves — without announcing you\'re saving it. Examples: prefers fewer transfers, avoids CBD on Friday evenings, always uses route 46, works in Upperhill, hates long walks, picks up kids from school at 4pm.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'content' => ['type' => 'string', 'description' => 'The preference or fact, phrased in third person, under 150 characters. E.g. "Prefers routes with fewer transfers even if slightly slower."'],
                        ],
                        'required' => ['content'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'suggest_replies',
                    'description' => 'Show the user 2-4 short tappable reply chips. Use when: (1) the request is vague and you need to narrow intent, (2) the user says "hi" or "help" with no specific request, (3) you just answered something and want to offer natural next steps. Chips appear as tap buttons — make them action-oriented and specific.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'suggestions' => [
                                'type'        => 'array',
                                'items'       => ['type' => 'string'],
                                'description' => '2-4 short reply options, each under 35 characters. Make them specific and actionable, e.g. "Route to Westlands", "Weather near me", "Nearest matatu stage".',
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

        // Context-dependent turns (calendar, live trip) are never cacheable —
        // "how many stops left?" has a different answer every minute.
        $hasVolatileContext = !empty($context['calendar_events']) || !empty($context['nav']);

        // ── Text cache (skip audio turns and context-dependent turns) ─────────
        $textCacheKey = null;
        if ($text && empty($audioFile['base64']) && !$hasVolatileContext) {
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

        // ── Persistent user memory (authenticated users only) ─────────────────
        if ($memoryUser = auth('sanctum')->user()) {
            try {
                $memoryBlock = $this->memory->buildMemoryBlock($memoryUser);
                if ($memoryBlock) {
                    $systemPrompt .= "\n\n" . $memoryBlock;
                }
            } catch (\Throwable $e) {
                Log::warning('KwameMemory: buildMemoryBlock failed: ' . $e->getMessage());
            }
        }

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

                case 'remember_preference':
                    $memUser = auth('sanctum')->user();
                    $saved   = $memUser
                        ? $this->memory->rememberPreference($memUser, (string) ($args['content'] ?? ''))
                        : false;
                    $toolContent = json_encode([
                        'success' => $saved,
                        'note'    => $memUser
                            ? ($saved
                                ? 'Saved. Acknowledge this naturally and briefly — like a friend who just noted something down ("Got it", "I\'ll keep that in mind"). Do NOT say "I have saved" or "I have stored". Then continue the conversation.'
                                : 'Already known — do not mention memory at all, just continue naturally.')
                            : 'User is not signed in so memory is unavailable. Do not mention this unless they ask why you don\'t remember them.',
                    ]);
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
                'sw-KE' => "Maze, sijapata vizuri. Niambie tena — unataka nifanye nini hasa?",
                'fr-FR' => "Hmm, je n'ai pas bien saisi. Pouvez-vous reformuler — qu'est-ce que vous cherchez exactement ?",
                default => "Hmm, I didn't quite get that — could you say it a different way? I can help with routes, places, weather, and more.",
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
