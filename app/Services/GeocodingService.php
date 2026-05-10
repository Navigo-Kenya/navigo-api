<?php

namespace App\Services;

use App\Models\Stop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class GeocodingService
{
    public function getCoordinates(string $query, ?float $proxLat = null, ?float $proxLng = null): ?array
    {
        if (strtolower(trim($query)) === 'current location') return null;

        // ── 1. CHECK YOUR LOCAL MATATU DATABASE (Tiered Search) ──
        
        // Tier 1: Exact Match (e.g., user says exactly "Kencom")
        $localStop = Stop::select('name')
            ->selectRaw("ST_Y(location::geometry) as extracted_lat, ST_X(location::geometry) as extracted_lng")
            ->where('name', 'ILIKE', $query)
            ->first();

        // Tier 2: Starts With (e.g., user says "Cabanas", matches "Cabanas Stage")
        if (!$localStop) {
            $localStop = Stop::select('name')
                ->selectRaw("ST_Y(location::geometry) as extracted_lat, ST_X(location::geometry) as extracted_lng")
                ->where('name', 'ILIKE', "{$query}%")
                ->first();
        }

        // Tier 3: Contains (Fallback)
        if (!$localStop) {
            $localStop = Stop::select('name')
                ->selectRaw("ST_Y(location::geometry) as extracted_lat, ST_X(location::geometry) as extracted_lng")
                ->where('name', 'ILIKE', "%{$query}%")
                ->first();
        }

        if ($localStop && $localStop->extracted_lat) {
            Log::info("Found location in Local DB: {$query}");
            return [
                'lat' => (float) $localStop->extracted_lat,
                'lng' => (float) $localStop->extracted_lng,
                'name' => $localStop->name,
                'source' => 'local_db'
            ];
        }

        // ── 2. FALLBACK TO MAPBOX FOR GENERAL PLACES ──
        Log::info("Not in DB, falling back to Mapbox for: {$query}");
        $token = env('MAPBOX_API_KEY');
        
        $mapboxQuery = $query;
        if (stripos($mapboxQuery, 'Nairobi') === false) {
            $mapboxQuery .= ' Nairobi';
        }

        $url = "https://api.mapbox.com/geocoding/v5/mapbox.places/" . urlencode($mapboxQuery) . ".json";
        $cacheKey = "geocode:" . md5($mapboxQuery);

        return Cache::remember($cacheKey, 86400, function () use ($url, $token, $query, $proxLat, $proxLng) {
            try {
                $params = [
                    'access_token' => $token,
                    'country'      => 'ke', 
                    'limit'        => 1,
                    'bbox'         => '36.6,-1.45,37.1,-1.15' 
                ];

                $response = Http::withoutVerifying()->timeout(5)->get($url, $params);

                if ($response->successful() && !empty($response->json('features'))) {
                    $feature = $response->json('features')[0];
                    return [
                        'lng' => (float) $feature['center'][0],
                        'lat' => (float) $feature['center'][1],
                        'name' => str_ireplace(' Nairobi', '', $feature['text']),
                    ];
                }
                return null;
            } catch (\Exception $e) {
                Log::error("Geocoding Error for '{$query}': " . $e->getMessage());
                return null;
            }
        });
    }
}