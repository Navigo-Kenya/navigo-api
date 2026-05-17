<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StopResource;
use App\Services\LocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LocationController extends Controller
{
    public function __construct(private LocationService $locationService) {}

    public function nearby(Request $request)
    {
        $validated = $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'radius' => 'nullable|integer|max:5000',
        ]);

        $stops = $this->locationService->getNearbyStops(
            $validated['lat'],
            $validated['lng'],
            $validated['radius'] ?? 1500
        );

        return StopResource::collection($stops);
    }

    public function all()
    {
        return response()->json(['data' => $this->locationService->getAllStops()]);
    }

    public function show(string $id)
    {
        $stop = $this->locationService->getStop($id);
        if (!$stop) return response()->json(['error' => 'Stop not found'], 404);
        return response()->json(['data' => $stop]);
    }

    public function search(Request $request)
    {
        $validated = $request->validate(['q' => 'required|string|min:2']);

        $stops = $this->locationService->searchStops($validated['q']);

        Log::info("Searched for stops with query: '{$validated['q']}' - Found " . count($stops) . " results.");

        return StopResource::collection($stops);
    }
}
