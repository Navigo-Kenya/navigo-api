<?php

namespace App\Services\Kwame;

use App\Models\Stop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Executable tools Kwame (the AI assistant) can call beyond route planning:
 * place discovery (Google Places), and lookups against Navigo's own GTFS data
 * (nearby stops, routes serving a stop, route search).
 *
 * Every method returns plain arrays shaped for two consumers at once:
 * a compact form for the LLM tool-result turn, and a richer form the app
 * renders as cards. Keep payloads small — they ride inside the LLM context.
 */
class KwameToolsService
{
    private const PLACES_CACHE_TTL = 3600;
    private const NAIROBI_LAT = -1.2921;
    private const NAIROBI_LNG = 36.8219;

    /**
     * Text-search places via Google Places API (New), biased around the user.
     * Returns [] when the API key is missing or the search fails — the LLM
     * narrates the miss gracefully.
     */
    public function findPlaces(string $query, ?float $lat, ?float $lng, int $limit = 5): array
    {
        $apiKey = env('GOOGLE_MAPS_API_KEY');
        if (empty($apiKey)) {
            Log::warning('KwameTools: GOOGLE_MAPS_API_KEY missing — find_places disabled.');
            return [];
        }

        $biasLat = $lat ?? self::NAIROBI_LAT;
        $biasLng = $lng ?? self::NAIROBI_LNG;

        $cacheKey = 'kwame_places:' . md5(strtolower($query) . round($biasLat, 2) . round($biasLng, 2));

        return Cache::remember($cacheKey, self::PLACES_CACHE_TTL, function () use ($query, $biasLat, $biasLng, $limit, $apiKey) {
            try {
                $response = Http::withoutVerifying()
                    ->timeout(8)
                    ->withHeaders([
                        'X-Goog-Api-Key'    => $apiKey,
                        'X-Goog-FieldMask'  => implode(',', [
                            'places.displayName',
                            'places.formattedAddress',
                            'places.location',
                            'places.rating',
                            'places.userRatingCount',
                            'places.primaryTypeDisplayName',
                            'places.currentOpeningHours.openNow',
                        ]),
                    ])
                    ->post('https://places.googleapis.com/v1/places:searchText', [
                        'textQuery'    => $query,
                        'maxResultCount' => $limit,
                        'locationBias' => [
                            'circle' => [
                                'center' => ['latitude' => $biasLat, 'longitude' => $biasLng],
                                'radius' => 30000.0,
                            ],
                        ],
                        'regionCode' => 'KE',
                    ]);

                if (!$response->successful()) {
                    Log::error('KwameTools: Places API error', ['status' => $response->status(), 'body' => $response->json()]);
                    return [];
                }

                return collect($response->json('places') ?? [])
                    ->take($limit)
                    ->map(fn ($p) => [
                        'name'     => $p['displayName']['text'] ?? 'Unknown',
                        'address'  => $p['formattedAddress'] ?? null,
                        'lat'      => (float) ($p['location']['latitude'] ?? 0),
                        'lng'      => (float) ($p['location']['longitude'] ?? 0),
                        'rating'   => isset($p['rating']) ? (float) $p['rating'] : null,
                        'ratings_count' => $p['userRatingCount'] ?? null,
                        'category' => $p['primaryTypeDisplayName']['text'] ?? null,
                        'open_now' => $p['currentOpeningHours']['openNow'] ?? null,
                    ])
                    ->values()
                    ->all();
            } catch (\Throwable $e) {
                Log::error("KwameTools: findPlaces failed for '{$query}': " . $e->getMessage());
                return [];
            }
        });
    }

    /** Nearest stops with names, coordinates and distance (m). */
    public function findNearbyStops(?float $lat, ?float $lng, int $radiusMeters = 1200, int $limit = 5): array
    {
        if ($lat === null || $lng === null) return [];

        try {
            $stops = Stop::select('id', 'name')
                ->selectRaw("ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng")
                ->selectRaw("ST_DistanceSphere(location, ST_MakePoint(?, ?)) as distance_m", [$lng, $lat])
                ->whereRaw(
                    "ST_DWithin(location::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)",
                    [$lng, $lat, $radiusMeters]
                )
                ->orderBy('distance_m')
                ->limit($limit)
                ->get();

            return $stops->map(fn ($s) => [
                'name'       => $s->name,
                'lat'        => (float) $s->lat,
                'lng'        => (float) $s->lng,
                'distance_m' => (int) round($s->distance_m),
            ])->all();
        } catch (\Throwable $e) {
            Log::error('KwameTools: findNearbyStops failed: ' . $e->getMessage());
            return [];
        }
    }

    /** Which matatu/bus routes serve a stop (by fuzzy stop name). */
    public function getStopRoutes(string $stopName, int $limit = 10): array
    {
        try {
            $stop = Stop::select('id', 'name')
                ->selectRaw("ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng")
                ->where('name', 'ILIKE', $stopName)
                ->orWhere('name', 'ILIKE', "%{$stopName}%")
                ->first();

            if (!$stop) return ['stop' => null, 'routes' => []];

            $routes = DB::table('stop_times')
                ->join('trips', 'trips.trip_id', '=', 'stop_times.trip_id')
                ->join('routes', 'routes.route_id', '=', 'trips.route_id')
                ->where('stop_times.stop_id', $stop->id)
                ->distinct()
                ->limit($limit)
                ->get(['routes.route_short_name', 'routes.route_long_name']);

            return [
                'stop' => [
                    'name' => $stop->name,
                    'lat'  => (float) $stop->lat,
                    'lng'  => (float) $stop->lng,
                ],
                'routes' => $routes->map(fn ($r) => [
                    'number' => $r->route_short_name,
                    'name'   => $r->route_long_name,
                ])->all(),
            ];
        } catch (\Throwable $e) {
            Log::error('KwameTools: getStopRoutes failed: ' . $e->getMessage());
            return ['stop' => null, 'routes' => []];
        }
    }

    /** Search transit routes by number or name (e.g. "46", "Kikuyu"). */
    public function searchTransitRoutes(string $query, int $limit = 8): array
    {
        try {
            return DB::table('routes')
                ->where('route_short_name', 'ILIKE', "%{$query}%")
                ->orWhere('route_long_name', 'ILIKE', "%{$query}%")
                ->limit($limit)
                ->get(['route_short_name', 'route_long_name'])
                ->map(fn ($r) => [
                    'number' => $r->route_short_name,
                    'name'   => $r->route_long_name,
                ])
                ->all();
        } catch (\Throwable $e) {
            Log::error('KwameTools: searchTransitRoutes failed: ' . $e->getMessage());
            return [];
        }
    }
}
