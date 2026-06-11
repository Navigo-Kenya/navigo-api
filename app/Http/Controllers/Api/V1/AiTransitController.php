<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\LogJourneyJob;
use App\Services\AiAssistantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AiTransitController extends Controller
{
    public function __construct(protected AiAssistantService $aiService) {}

    public function planRouteWithAi(Request $request)
    {
        // Reduced max execution window because native audio processing executes significantly faster
        set_time_limit(60);

        $result = $this->aiService->chat(
            sessionId: $request->input('session_id', 'default'),
            text:      $request->input('text'),
            audioFile: $request->input('audio'),
            userLat:   $request->input('lat'),
            userLng:   $request->input('lng'),
            aliases:   $request->input('aliases', []),
        );

        if (!$result) {
            return response()->json(['error' => 'Could not process your request.'], 400);
        }

        Log::info('Kwame conversational turn processed', [
            'session'   => $request->input('session_id'),
            'has_route' => !empty($result['route']),
            'has_hold'  => !empty($result['holding_phrase']),
        ]);

        // Asynchronously process analytics tracking when a route is active
        if (!empty($result['route']) && is_array($result['route'])) {
            $routeData = $result['route'];
            
            // OpenTripPlanner tracks route components through transit "legs"
            $legs = $routeData['legs'] ?? $routeData['segments'] ?? [];
            $transitLegs = array_values(array_filter($legs, fn ($leg) => ($leg['mode'] ?? '') !== 'WALK'));

            $origin = $transitLegs[0]['from']['name'] ?? null;
            $destination = count($transitLegs) > 0 ? end($transitLegs)['to']['name'] : null;
            $summary = $routeData['summary'] ?? '';

            LogJourneyJob::dispatch([
                'origin_name'      => $origin,
                'destination_name' => $destination,
                'primary_route'    => str_starts_with($summary, 'Via ') ? substr($summary, 4) : null,
                'type'             => 'ai',
                'user_id'          => auth('sanctum')->user()?->getAuthIdentifier(),
            ])->onQueue('default');
        }

        return response()->json($result);
    }
}