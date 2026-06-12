<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\SavedPlace;
use Exception;

class AiAssistantService
{
    protected string $audioModel = 'gpt-audio-mini';
    protected string $textModel  = 'gpt-4o-mini';

    const SESSION_TTL = 1800;
    const ROUTE_CACHE_TTL = 600;

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
            - If the user asks a general question or doesn't mention travel intent, DO NOT call a tool. Reply conversationally.
            - When explaining route results, use local Nairobi phrasing. Reference major stages (e.g., Commercial, Kencom, Khoja) and Matatu route numbers.
            - Keep spoken responses brief, natural, and highly conversational (1 to 3 sentences maximum).

            ROUTING RULES:
            1. CURRENT LOCATION: If the user says "here", "my location", or omits a start point, pass "current location" as the `from` parameter.
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
                            'from' => ['type' => 'string', 'description' => 'Origin location name or "current location"'],
                            'to'   => ['type' => 'string', 'description' => 'Destination location name or "current location"'],
                            'holding_phrase' => ['type' => 'string', 'description' => 'A short, warm text sentence to show the user while the route loads.'],
                            'walkReluctance' => ['type' => 'number', 'description' => 'Walk reluctance factor.'],
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
        if (!$apiKey) throw new Exception("OpenAI API Key is missing.");

        if ($text && empty($audioFile['base64'])) {
            $textCacheKey = 'navigo_text_cache:' . md5(strtolower(trim($text)));
            if (Cache::has($textCacheKey)) return Cache::get($textCacheKey);
        }

        $history = Cache::get("kwame:{$sessionId}", []);
        $hasAudio = !empty($audioFile['base64']);

        if ($hasAudio) {
            $userMessage = ['role' => 'user', 'content' => [['type' => 'input_audio', 'input_audio' => ['data' => $audioFile['base64'], 'format' => 'wav']]]];
        } else if ($text) {
            $userMessage = ['role' => 'user', 'content' => $text];
        } else {
            return null;
        }

        $history[] = $userMessage;
        $messages = $this->buildMessages($history);
        $firstResponse = $this->callAudioChat($messages, $hasAudio);

        if (!$firstResponse) return null;

        $assistantMsg = $firstResponse['choices'][0]['message'];
        $holdingPhrase = null;
        $routes = [];
        $actionRequired = null;

        if (!empty($assistantMsg['tool_calls'])) {
            $toolCall = $assistantMsg['tool_calls'][0];

            if ($toolCall['function']['name'] === 'get_route') {
                $args = json_decode($toolCall['function']['arguments'], true) ?? [];
                $holdingPhrase = $args['holding_phrase'] ?? "Checking routes for you...";

                $resolvedCoords = $this->extractCoordinates($args, $userLat, $userLng, $aliases);

                // Intercept structural UI Actions (Unresolved locations)
                if (isset($resolvedCoords['actionRequired'])) {
                    $actionRequired = $resolvedCoords['actionRequired'];
                    $transcript = $actionRequired['isAuthenticated']
                        ? "I couldn't locate '{$actionRequired['unresolvedName']}'. Would you like to select one of your saved places instead?"
                        : "I couldn't locate '{$actionRequired['unresolvedName']}'. Please sign in to access your custom saved places.";

                    $this->saveLightweightHistory($sessionId, $history, $transcript);
                    return [
                        'spoken_response' => $transcript,
                        'tts_audio'       => null,
                        'holding_phrase'  => null,
                        'routes'          => [],
                        'actionRequired'  => $actionRequired
                    ];
                }

                if (isset($resolvedCoords['error'])) {
                    $toolContent = json_encode(['success' => false, 'message' => $resolvedCoords['error']]);
                } else {
                    $geoCacheKey = sprintf(
                        "navigo_geo_route:%s:%s",
                        round($resolvedCoords['from']['lat'], 3) . ',' . round($resolvedCoords['from']['lng'], 3),
                        round($resolvedCoords['to']['lat'], 3) . ',' . round($resolvedCoords['to']['lng'], 3)
                    );

                    if (Cache::has($geoCacheKey)) {
                        $cachedData = Cache::get($geoCacheKey);
                        $this->saveLightweightHistory($sessionId, $history, $cachedData['spoken_response']);
                        return [
                            'spoken_response' => $cachedData['spoken_response'],
                            'tts_audio'       => $cachedData['tts_audio'],
                            'holding_phrase'  => $holdingPhrase,
                            'routes'          => $cachedData['routes'] ?? [],
                        ];
                    }

                    $routes = $this->executeTransitPlan($resolvedCoords['from'], $resolvedCoords['to'], $args['walkReluctance'] ?? 13.5);
                    $toolContent = !empty($routes)
                        ? json_encode(['success' => true, 'routes_found' => count($routes), 'options' => $routes])
                        : json_encode(['success' => false, 'message' => 'No transit routes found right now.']);
                }

                $history[] = $assistantMsg;
                $history[] = ['role' => 'tool', 'tool_call_id' => $toolCall['id'], 'content' => $toolContent];

                $secondMessages = $this->buildMessages($history);
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

        if ($text && !$hasAudio && empty($routes)) Cache::put($textCacheKey, $output, self::ROUTE_CACHE_TTL);
        if (!empty($routes) && isset($geoCacheKey)) Cache::put($geoCacheKey, $output, self::ROUTE_CACHE_TTL);

        return $output;
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
        // Accept dynamic UI alias injection
        if (array_key_exists($locationName, $aliases)) {
            return $aliases[$locationName];
        }

        if ($this->isContextualLocation($locationName)) {
            if (!$userLat || !$userLng) return null;
            return ['lat' => $userLat, 'lng' => $userLng, 'name' => 'Current Location'];
        }

        return $this->geoService->getCoordinates($locationName, $userLat, $userLng);
    }

    private function handleResolutionFailure(string $unresolvedName, string $field): array
    {
        // Safely check if a Sanctum API token authenticates the current request
        $user = auth('sanctum')->user();
        $isAuthenticated = !is_null($user);

        return [
            'errorType'       => 'unresolved_location',
            'field'           => $field,
            'unresolvedName'  => $unresolvedName,
            'isAuthenticated' => $isAuthenticated,
            'savedPlaces'     => $isAuthenticated
                ? SavedPlace::where('user_id', $user->id)->get(['id', 'name', 'lat', 'lng', 'pin', 'category'])->toArray()
                : []
        ];
    }

    private function isContextualLocation(string $name): bool
    {
        return in_array($name, ['current location', 'here', 'my location', 'where i am']);
    }

    private function executeTransitPlan(array $from, array $to, float $walkReluctance): array
    {
        return $this->transitEngine->findJourney((float)$from['lat'], (float)$from['lng'], (float)$to['lat'], (float)$to['lng'], null, null, $walkReluctance);
    }

    private function callAudioChat(array $messages, bool $needsAudio = true): ?array
    {
        try {
            $payload = [
                'model'       => $needsAudio ? $this->audioModel : $this->textModel,
                'messages'    => $messages,
                'tools'       => $this->tools(),
                'tool_choice' => 'auto',
            ];

            if ($needsAudio) {
                $payload['modalities'] = ['text', 'audio'];
                $payload['audio']      = ['voice' => 'alloy', 'format' => 'wav'];
            }

            $response = Http::withoutVerifying()
                ->withToken(config('services.openai.key'))
                ->timeout(45)
                ->post('https://api.openai.com/v1/chat/completions', $payload);

            if (!$response->successful()) {
                Log::error('OpenAI API Rejected Request', ['status' => $response->status(), 'body' => $response->json()]);
                return null;
            }

            return $response->json();
        } catch (Exception $e) {
            Log::error('OpenAI Call Failure: ' . $e->getMessage());
            return null;
        }
    }

    private function buildMessages(array $history): array
    {
        return array_merge([['role' => 'system', 'content' => $this->systemPrompt()]], $history);
    }

    private function saveLightweightHistory(string $sessionId, array $history, string $finalTranscript): void
    {
        foreach ($history as &$msg) {
            if ($msg['role'] === 'user' && is_array($msg['content'])) $msg['content'] = '[User Audio Input]';
        }
        $history[] = ['role' => 'assistant', 'content' => $finalTranscript];
        Cache::put("kwame:{$sessionId}", array_slice($history, -20), self::SESSION_TTL);
    }
}
