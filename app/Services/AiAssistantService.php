<?php

namespace App\Services;

use App\Services\AI\GoogleLlmService;
use App\Services\AI\GoogleTtsService;
use App\Models\SavedPlace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

class AiAssistantService
{
    const SESSION_TTL   = 1800;
    const ROUTE_CACHE_TTL = 600;

    public function __construct(
        private GeocodingService     $geoService,
        private TransitEngineService $transitEngine,
        private GoogleLlmService     $llm,
        private GoogleTtsService     $tts,
    ) {}

    /**
     * Build the dynamic prompt based on personality style and language code.
     */
    private function systemPrompt(?string $responseStyle = null, string $languageCode = 'en-US'): string
    {
        $base = <<<'PROMPT'
            You are Kwame, the voice assistant for Navigo, a public transit navigation platform.
            You are a warm, friendly, and knowledgeable guide for Nairobi's public transport network.
            Speak casually and empathetically, like a helpful local friend.

            CRITICAL INSTRUCTIONS:
            - You have access to a tool called `get_route`. Use it whenever a destination is known or confirmed.
            - If the user specifies a destination but does not mention where they are starting from, assume they are starting from their "current location".
            - If the user asks a general question or doesn't mention travel intent, DO NOT call a tool. Reply conversationally.
            - When explaining route results, use local Nairobi phrasing. Reference major stages (e.g., Commercial, Kencom, Khoja) and Matatu route numbers.
            - Keep spoken responses brief, natural, and highly conversational (1 to 3 sentences maximum).

            ROUTING RULES:
            1. CURRENT LOCATION: If the user says "here", "my location", or omits a start point, pass "current location" as the `from` parameter.
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
    ): ?array {
        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }

        if (empty(config('services.google_cloud.project_id'))) {
            throw new Exception('GCP_PROJECT_ID is not set.');
        }

        // ── Text cache (skip audio turns) ──────────────────────────────────────
        if ($text && empty($audioFile['base64'])) {
            $textCacheKey = 'navigo_text_cache:' . md5(strtolower(trim($text)));
            if (Cache::has($textCacheKey)) return Cache::get($textCacheKey);
        }

        $history  = Cache::get("kwame:{$sessionId}", []);
        $hasAudio = !empty($audioFile['base64']);

        // ── Gemini accepts audio natively — no STT step needed ────────────────
        if (!$hasAudio && empty($text)) return null;

        $history[] = ['role' => 'user', 'content' => $text ?? '[Voice message]'];

        // Extract settings payload variables
        $responseStyle = $voiceSettings['response_style'] ?? null;
        $languageCode  = $voiceSettings['language_code'] ?? 'en-US';

        // ── Step 1: LLM turn 1 — Gemini transcribes + detects intent ──────────
        try {
            $firstResponse = $this->llm->chat(
                $history,
                $this->systemPrompt($responseStyle, $languageCode),
                $this->tools(),
                true,
                $hasAudio ? $audioFile : null,
            );
        } catch (\RuntimeException $e) {
            $msg = match ($e->getMessage()) {
                'VERTEX_QUOTA_EXCEEDED' => "I'm getting a lot of requests right now — please try again in a moment!",
                'VERTEX_TIMEOUT'        => "That took too long on my end. Please try again!",
                default                 => null,
            };
            if ($msg !== null) {
                $this->saveLightweightHistory($sessionId, $history, $msg);
                return ['spoken_response' => $msg, 'tts_audio' => null, 'holding_phrase' => null, 'routes' => []];
            }
            throw $e;
        }

        if (!$firstResponse) return null;

        $holdingPhrase  = null;
        $routes         = [];
        $actionRequired = null;
        $finalText      = '';

        if ($firstResponse['functionCall'] && $firstResponse['functionCall']['name'] === 'get_route') {
            $args          = $firstResponse['functionCall']['args'];
            $holdingPhrase = $args['holding_phrase'] ?? 'Checking routes for you...';

            $resolvedCoords = $this->extractCoordinates($args, $userLat, $userLng, $aliases);

            // ── Unresolved location → return action UI payload ─────────────────
            if (isset($resolvedCoords['actionRequired'])) {
                $actionRequired = $resolvedCoords['actionRequired'];
                
                // Locally handle clarification prompt messages matching the user's active language choice
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
                return [
                    'spoken_response' => $transcript,
                    'tts_audio'       => null,
                    'holding_phrase'  => null,
                    'routes'          => [],
                    'actionRequired'  => $actionRequired,
                ];
            }

            // ── Geocoding error ────────────────────────────────────────────────
            if (isset($resolvedCoords['error'])) {
                $toolContent = json_encode(['success' => false, 'message' => $resolvedCoords['error']]);
            } else {
                // ── Geo-route cache ────────────────────────────────────────────
                $geoCacheKey = sprintf(
                    'navigo_geo_route:%s:%s',
                    round($resolvedCoords['from']['lat'], 3) . ',' . round($resolvedCoords['from']['lng'], 3),
                    round($resolvedCoords['to']['lat'],   3) . ',' . round($resolvedCoords['to']['lng'],   3)
                );

                if (Cache::has($geoCacheKey)) {
                    $cached = Cache::get($geoCacheKey);
                    $this->saveLightweightHistory($sessionId, $history, $cached['spoken_response']);
                    return [
                        'spoken_response' => $cached['spoken_response'],
                        'tts_audio'       => $cached['tts_audio'],
                        'holding_phrase'  => $holdingPhrase,
                        'routes'          => $cached['routes'] ?? [],
                    ];
                }

                $routes      = $this->executeTransitPlan($resolvedCoords['from'], $resolvedCoords['to'], $args['walkReluctance'] ?? 13.5);
                $toolContent = !empty($routes)
                    ? json_encode(['success' => true, 'routes_found' => count($routes), 'options' => $routes])
                    : json_encode(['success' => false, 'message' => 'No transit routes found right now.']);
            }

            // ── Append tool turns to in-memory history for LLM turn 2 ─────────
            $history[] = [
                'role'       => 'assistant',
                'tool_calls' => [[
                    'function' => [
                        'name'      => 'get_route',
                        'arguments' => json_encode($args),
                    ],
                ]],
            ];
            $history[] = [
                'role'         => 'tool',
                'tool_call_id' => 'call_1',
                'content'      => $toolContent,
            ];

            // ── Step 2b: LLM turn 2 — narrate route results (no tools) ────────
            $secondResponse = $this->llm->chat($history, $this->systemPrompt($responseStyle, $languageCode), [], false);
            $finalText      = $secondResponse['text'] ?? '';

        } else {
            // No tool call — plain conversational reply
            $finalText = $firstResponse['text'] ?? '';
        }

        if (empty(trim($finalText))) {
            $finalText = match ($languageCode) {
                'sw-KE' => "Sina uhakika jinsi ya kusaidia na hilo. Je, unaweza kueleza tena?",
                'fr-FR' => "Je ne sais pas comment vous aider. Pourriez-vous reformuler ?",
                default => "I'm not sure how to help with that. Could you rephrase?",
            };
        }

        // ── Step 3: TTS — text → audio ─────────────────────────────────────────
        $audioData = $this->tts->synthesize(
            $finalText,
            (float) ($voiceSettings['speaking_rate'] ?? 1.05),
            $voiceSettings['voice_name']    ?? 'en-US-Neural2-D',
            (float) ($voiceSettings['pitch'] ?? 0.0),
            $languageCode,
        );

        $this->saveLightweightHistory($sessionId, $history, $finalText);

        $output = [
            'spoken_response' => $finalText,
            'tts_audio'       => $audioData,
            'holding_phrase'  => $holdingPhrase,
            'routes'          => $routes,
        ];

        if ($text && !$hasAudio && empty($routes)) {
            Cache::put($textCacheKey, $output, self::ROUTE_CACHE_TTL);
        }
        if (!empty($routes) && isset($geoCacheKey)) {
            Cache::put($geoCacheKey, $output, self::ROUTE_CACHE_TTL);
        }

        return $output;
    }

    // ... (Keep extractCoordinates, resolveCoordinate, handleResolutionFailure, isContextualLocation, executeTransitPlan, and saveLightweightHistory exactly as they were) ...
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