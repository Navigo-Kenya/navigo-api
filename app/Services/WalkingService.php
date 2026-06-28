<?php

namespace App\Services;

use App\Models\CachedWalkingRoute;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WalkingService
{
    private string $mapboxKey;
    private string $googleKey;

    public function __construct()
    {
        $this->mapboxKey = config('transit.mapbox_key', '');
        $this->googleKey = config('transit.google_maps_key', '');
    }

    /**
     * The Chimera Pipeline: 
     * Merges Mapbox's perfect visual geometry with Google's rich text instructions.
     */
    public function getRoute(
        float $fromLat, float $fromLng,
        float $toLat,   float $toLng,
        array $otpCoords,
        array $otpSteps,
        int   $otpDistanceM,
        int   $otpDurationS
    ): array {
        $fallback = [
            'coordinates' => $otpCoords,
            'walk_steps'  => $otpSteps,
            'distance'    => $otpDistanceM,
            'duration'    => $otpDurationS,
        ];

        if ($otpDistanceM < 100 || empty($this->mapboxKey) || empty($this->googleKey)) {
            return $fallback;
        }

        // Round to 4 decimal places (~11 m bucket) for the cache key
        $fLat = round($fromLat, 4);
        $fLng = round($fromLng, 4);
        $tLat = round($toLat, 4);
        $tLng = round($toLng, 4);

        $cached = CachedWalkingRoute::where([
            'from_lat' => $fLat, 'from_lng' => $fLng,
            'to_lat'   => $tLat, 'to_lng'   => $tLng,
        ])->first();

        if ($cached) {
            return [
                'coordinates' => $cached->coordinates,
                'walk_steps'  => $cached->walk_steps,
                'distance'    => $cached->distance_m,
                'duration'    => $cached->duration_s,
            ];
        }

        // Fetch from both providers
        $mapbox = $this->fetchFromMapbox($fromLat, $fromLng, $toLat, $toLng);
        $google = $this->fetchFromGoogle($fromLat, $fromLng, $toLat, $toLng);

        // the Chimera Pipeline: Merge Mapbox's perfect geometry with Google's rich text instructions
        if ($mapbox && $google) {
            $result = [
                'coordinates' => $mapbox['coordinates'], // Mapbox Body (Visuals & Snapping)
                'walk_steps'  => $google['walk_steps'],  // Google Brain (Rich text & Landmarks)
                'distance'    => $mapbox['distance'],
                'duration'    => $google['duration'],
            ];
        } elseif ($mapbox) {
            $result = $mapbox;
        } elseif ($google) {
            $result = $google;
        } else {
            return $fallback;
        }

        try {
            CachedWalkingRoute::create([
                'from_lat'    => $fLat,
                'from_lng'    => $fLng,
                'to_lat'      => $tLat,
                'to_lng'      => $tLng,
                'coordinates' => $result['coordinates'],
                'walk_steps'  => $result['walk_steps'],
                'distance_m'  => $result['distance'],
                'duration_s'  => $result['duration'],
            ]);
        } catch (\Exception $e) {
            Log::debug('WalkingService cache write skipped', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    private function fetchFromMapbox(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        try {
            $coords = "{$fromLng},{$fromLat};{$toLng},{$toLat}";
            $response = Http::timeout(8)->get("https://api.mapbox.com/directions/v5/mapbox/walking/{$coords}", [
                'geometries'   => 'polyline',
                'steps'        => 'true',
                'overview'     => 'full',
                'access_token' => $this->mapboxKey,
            ]);
        } catch (\Exception $e) {
            return null;
        }

        if (!$response->ok() || ($response->json('code') !== 'Ok')) return null;

        $route = $response->json('routes')[0];
        
        // We only care about Mapbox's perfect geometry and total distance
        return [
            'coordinates' => $this->decodePolyline($route['geometry']),
            'distance'    => (int) round($route['distance']),
            'walk_steps'  => [], // Dropped in favor of Google
            'duration'    => 0,
        ];
    }

    private function fetchFromGoogle(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        try {
            $response = Http::timeout(8)->withoutVerifying()->get('https://maps.googleapis.com/maps/api/directions/json', [
                'origin'      => "{$fromLat},{$fromLng}",
                'destination' => "{$toLat},{$toLng}",
                'mode'        => 'walking',
                'region'      => 'ke',
                'key'         => $this->googleKey,
            ]);
        } catch (\Exception $e) {
            return null;
        }

        if (!$response->ok() || ($response->json('status') !== 'OK')) return null;

        $leg = $response->json('routes')[0]['legs'][0];
        $walkSteps = [];

        foreach ($leg['steps'] as $step) {
            $html = $step['html_instructions'];
            $cleaned = preg_replace('/<(div|br|span|p)[^>]*>/i', ' ', $html);
            $cleaned = preg_replace('/\s{2,}/', ' ', trim(strip_tags($cleaned)));

            $note = null;
            if (preg_match('/\bPass by\b(.+)$/i', $cleaned, $m)) {
                $note    = trim($m[0]);
                $cleaned = trim(preg_replace('/\s*\bPass by\b.+$/i', '', $cleaned));
            }

            $walkSteps[] = [
                'instruction' => $cleaned,
                'note'        => $note,
                'distance'    => $step['distance']['value'],
                'duration'    => $step['duration']['value'],
                'lat'         => $step['start_location']['lat'],
                'lng'         => $step['start_location']['lng'],
            ];
        }

        return [
            'coordinates' => [], // Dropped in favor of Mapbox
            'walk_steps'  => $walkSteps,
            'distance'    => $leg['distance']['value'],
            'duration'    => $leg['duration']['value'],
        ];
    }

    private function decodePolyline(string $encoded): array
    {
        $coords = [];
        $index  = 0;
        $len    = \strlen($encoded);
        $lat = 0; $lng = 0;

        while ($index < $len) {
            foreach (['lat', 'lng'] as $axis) {
                $shift = 0; $result = 0;
                do {
                    $b = \ord($encoded[$index++]) - 63;
                    $result |= ($b & 0x1f) << $shift;
                    $shift += 5;
                } while ($b >= 0x20 && $index < $len);
                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                if ($axis === 'lat') $lat += $delta; else $lng += $delta;
            }
            $coords[] = [round($lat / 1e5, 6), round($lng / 1e5, 6)];
        }
        return $coords;
    }
}