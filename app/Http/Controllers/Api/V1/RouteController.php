<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\LogJourneyJob;
use App\Services\TransitEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RouteController extends Controller
{
    public function __construct(private TransitEngineService $transitService) {}

    public function calculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from'              => 'required|array',
            'from.lat'          => 'required|numeric',
            'from.lng'          => 'required|numeric',
            'to'                => 'required|array',
            'to.lat'            => 'required|numeric',
            'to.lng'            => 'required|numeric',
            'date'              => 'nullable|string',
            'time'              => 'nullable|string',
            'max_walk_distance' => 'nullable|integer|min:100|max:5000',
        ]);

        // Short-circuit: origin and destination are the same point (4 d.p. ≈ 11 m precision).
        if (
            round($validated['from']['lat'], 4) === round($validated['to']['lat'], 4) &&
            round($validated['from']['lng'], 4) === round($validated['to']['lng'], 4)
        ) {
            Log::info('Origin and destination are the same point.');
            return response()->json(['data' => []]);
        }

        $routes = $this->transitService->findJourney(
            (float) $validated['from']['lat'],
            (float) $validated['from']['lng'],
            (float) $validated['to']['lat'],
            (float) $validated['to']['lng'],
            $validated['date'] ?? null,
            $validated['time'] ?? null,
            maxWalkDistance: (int) ($validated['max_walk_distance'] ?? 1500),
        );

        if (empty($routes)) {
            Log::info('No route found.');
            return response()->json(['data' => []]);
        }

        Log::info('Routes calculated', ['count' => \count($routes), 'summaries' => array_column($routes, 'summary')]);

        LogJourneyJob::dispatch([
            ...$this->extractJourneyMeta($routes[0]),
            'type'    => 'standard',
            'user_id' => auth('sanctum')->user()?->getAuthIdentifier(),
        ])->onQueue('default');

        return response()->json(['data' => $routes]);
    }

    private function extractJourneyMeta(array $itinerary): array
    {
        $segments = $itinerary['segments'] ?? [];
        $transit  = array_values(array_filter($segments, fn ($s) => $s['mode'] !== 'WALK'));

        $origin      = $transit[0]['from']['name'] ?? null;
        $destination = \count($transit) > 0 ? array_reverse($transit)[0]['to']['name'] : null;

        $summary = $itinerary['summary'] ?? '';
        $route   = str_starts_with($summary, 'Via ') ? substr($summary, 4) : null;

        return [
            'origin_name'      => $origin,
            'destination_name' => $destination,
            'primary_route'    => $route,
        ];
    }
}
