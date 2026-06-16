<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\LogJourneyJob;
use App\Services\AiAssistantService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiTransitController extends Controller
{
    public function __construct(
        protected AiAssistantService $aiService
    ) {}

    public function planRouteWithAi(Request $request): JsonResponse
    {
        set_time_limit(60);

        $validated = $request->validate([
            'session_id' => ['nullable', 'string', 'max:255'],
            'text'       => ['nullable', 'string', 'max:1000'],
            'audio'        => ['nullable', 'array'],
            'audio.base64' => ['nullable', 'string'],
            'audio.mime'   => ['nullable', 'string', 'max:50'],
            'lat'        => ['nullable', 'numeric', 'between:-90,90'],
            'lng'        => ['nullable', 'numeric', 'between:-180,180'],
            'aliases'    => ['nullable', 'array'],
        ]);

        $sessionId = $validated['session_id'] ?? 'default';
        $audioFile = $validated['audio'] ?? null;

        try {
            $result = $this->aiService->chat(
                sessionId: $sessionId,
                text:      $validated['text'] ?? null,
                audioFile: $audioFile,
                userLat:   $validated['lat'] ?? null,
                userLng:   $validated['lng'] ?? null,
                aliases:   $validated['aliases'] ?? [],
            );

            if (!$result) {
                return response()->json(['error' => 'Could not process your transit request.'], 422);
            }
        } catch (Throwable $e) {
            Log::error('AI Transit processing failed', ['session_id' => $sessionId, 'exception' => $e->getMessage()]);
            return response()->json(['error' => 'An upstream scheduling error occurred.'], 500);
        }

        // Extract transit elements cleanly for analytics tracking
        if (!empty($result['routes']) && is_array($result['routes']) && isset($result['routes'][0])) {
            $routeData = $result['routes'][0];
            $legs = $routeData['legs'] ?? $routeData['segments'] ?? [];

            $transitLegs = array_values(array_filter($legs, fn ($leg) => isset($leg['mode']) && strtoupper($leg['mode']) !== 'WALK'));
            $totalTransitLegs = count($transitLegs);

            if ($totalTransitLegs > 0) {
                $origin = $transitLegs[0]['from']['name'] ?? null;
                $destination = $transitLegs[$totalTransitLegs - 1]['to']['name'] ?? null;
                $summary = $routeData['summary'] ?? '';

                LogJourneyJob::dispatch([
                    'origin_name'      => $origin,
                    'destination_name' => $destination,
                    'primary_route'    => str_starts_with($summary, 'Via ') ? substr($summary, 4) : null,
                    'type'             => 'ai',
                    'user_id'          => auth('sanctum')->user()?->getAuthIdentifier(),
                ])->onQueue('default');
            }
        }

        return response()->json($result);
    }
}
