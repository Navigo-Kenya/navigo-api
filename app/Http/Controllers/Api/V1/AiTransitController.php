<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\AiAssistantService;
use App\Services\TransitEngineService;
use App\Services\GeocodingService;
use Illuminate\Support\Facades\Log;

class AiTransitController extends Controller
{
    public function __construct(
        protected AiAssistantService $aiService,
        protected TransitEngineService $transitService,
        protected GeocodingService $geoService
    ) {}

    public function planRouteWithAi(Request $request)
    {
        $textQuery = $request->input('text');
        $audioData = $request->input('audio'); 
        $userLat   = $request->input('lat');
        $userLng   = $request->input('lng');

        // 1. Get Intent
        $intent = $this->aiService->extractTransitIntent($textQuery, $audioData);
        Log::info("Kwame Intent Extracted:", $intent ?? []); // Debugging Goldmine!

        if (!$intent || empty($intent['to'])) {
            return response()->json(['error' => 'Could not understand destination.'], 400);
        }

        // 2. Resolve 'From' Coordinates (Use GPS for contextual keywords)
        $fromText = strtolower(trim($intent['from'] ?? 'current location'));
        $contextualKeywords = ['current location', 'here', 'my location', 'home', 'work'];
        
        if (in_array($fromText, $contextualKeywords) && $userLat && $userLng) {
            // TODO: If "home" or "work", fetch from Auth::user()->home_lat in the future!
            // For now, map it to their physical GPS location.
            $fromCoords = ['lat' => $userLat, 'lng' => $userLng, 'name' => 'Current Location'];
        } else {
            $fromCoords = $this->geoService->getCoordinates($intent['from'], $userLat, $userLng);
        }

        // 3. Resolve 'To' Coordinates
        $toCoords = $this->geoService->getCoordinates($intent['to'], $userLat, $userLng);

        // STRICT NULL CHECK
        if (!$fromCoords || !isset($fromCoords['lat']) || !$toCoords || !isset($toCoords['lat'])) {
            Log::warning("Geocoding failed or returned invalid coordinates", ['from' => $fromCoords, 'to' => $toCoords]);
            return response()->json(['error' => 'Could not find those exact locations on the map.'], 404);
        }

        // 4. Hit OTP
        $route = $this->transitService->findJourney(
            (float) $fromCoords['lat'], 
            (float) $fromCoords['lng'],
            (float) $toCoords['lat'], 
            (float) $toCoords['lng'],
            $intent['date'] ?? null,
            $intent['time'] ?? null,
            $intent['walkReluctance'] ?? 13.5
        );

        Log::info("Route found by OTP:", $route ?? []); // More debugging goldmine!
        return response()->json([
            'spoken_response' => $intent['spoken_response'] ?? "I found a route for you. Let's go!",
            'route' => $route
        ]);
    }
}