<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
            'from'     => 'required|array',
            'from.lat' => 'required|numeric',
            'from.lng' => 'required|numeric',
            'to'       => 'required|array',
            'to.lat'   => 'required|numeric',
            'to.lng'   => 'required|numeric',
            'date'     => 'nullable|string',
            'time'     => 'nullable|string',
        ]);

        // Short-circuit: origin and destination are the same point (4 d.p. ≈ 11 m precision).
        if (
            round($validated['from']['lat'], 4) === round($validated['to']['lat'], 4) &&
            round($validated['from']['lng'], 4) === round($validated['to']['lng'], 4)
        ) {
            Log::info('Origin and destination are the same point.');
            return response()->json(['data' => []]);
        }

        $route = $this->transitService->findJourney(
            (float) $validated['from']['lat'],
            (float) $validated['from']['lng'],
            (float) $validated['to']['lat'],
            (float) $validated['to']['lng'],
            $validated['date'] ?? null,
            $validated['time'] ?? null,
        );

        if (!$route) {
            Log::info('No route found.');
            return response()->json(['data' => []]);
        }

        // Log a lightweight summary rather than the full payload.
        Log::info("Calculated Route: " . json_encode($route));
        // Log::info('Route calculated', [
        //     'type'     => $route['type'],
        //     'summary'  => $route['summary'],
        //     'duration' => $route['total_duration'],
        // ]);

        return response()->json(['data' => [$route]]);
    }
}
