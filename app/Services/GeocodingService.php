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

        // ── 1. LOCAL MATATU DATABASE (Tiered Search) ──

        $localStop = Stop::select('name')
            ->selectRaw("ST_Y(location::geometry) as extracted_lat, ST_X(location::geometry) as extracted_lng")
            ->where('name', 'ILIKE', $query)
            ->first();

        if (!$localStop) {
            $localStop = Stop::select('name')
                ->selectRaw("ST_Y(location::geometry) as extracted_lat, ST_X(location::geometry) as extracted_lng")
                ->where('name', 'ILIKE', "{$query}%")
                ->first();
        }

        if (!$localStop) {
            $localStop = Stop::select('name')
                ->selectRaw("ST_Y(location::geometry) as extracted_lat, ST_X(location::geometry) as extracted_lng")
                ->where('name', 'ILIKE', "%{$query}%")
                ->first();
        }

        if ($localStop?->extracted_lat) {
            Log::info("Found location in Local DB: {$query}");
            return [
                'lat'    => (float) $localStop->extracted_lat,
                'lng'    => (float) $localStop->extracted_lng,
                'name'   => $localStop->name,
                'source' => 'local_db',
            ];
        }

        // ── 2. FALLBACK TO GOOGLE MAPS GEOCODING ──
        Log::info("Not in DB, falling back to Google Maps for: {$query}");

        $apiKey    = config('services.google.maps_key');
        $mapsQuery = $query;
        if (stripos($mapsQuery, 'Nairobi') === false) {
            $mapsQuery .= ', Nairobi';
        }

        // Build proximity bias params in outer scope so static analysis can track them
        $proximityParams = [];
        if ($proxLat && $proxLng) {
            $proximityParams['location'] = "{$proxLat},{$proxLng}";
            $proximityParams['radius']   = 30000;
        }

        $cacheKey = 'geocode:' . md5($mapsQuery);

        return Cache::remember($cacheKey, 86400, function () use ($mapsQuery, $apiKey, $query, $proximityParams) {
            try {
                $params = array_merge([
                    'address'    => $mapsQuery,
                    'key'        => $apiKey,
                    'region'     => 'ke',
                    'components' => 'country:KE',
                ], $proximityParams);

                $response = Http::withoutVerifying()->timeout(5)->get(
                    'https://maps.googleapis.com/maps/api/geocode/json',
                    $params
                );

                if ($response->successful() && !empty($response->json('results'))) {
                    $result   = $response->json('results')[0];
                    $location = $result['geometry']['location'];
                    $name     = $result['address_components'][0]['long_name']
                        ?? str_ireplace(', Nairobi', '', $result['formatted_address']);

                    return [
                        'lat'  => (float) $location['lat'],
                        'lng'  => (float) $location['lng'],
                        'name' => $name,
                    ];
                }

                return null;
            } catch (\Exception $e) {
                Log::error("Google Geocoding Error for '{$query}': " . $e->getMessage());
                return null;
            }
        });
    }
}
