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

    private function mapsKey(): ?string
    {
        // config() (not env()) so the key survives `php artisan config:cache`.
        return config('services.google.maps_key') ?: null;
    }

    /**
     * Text-search places, biased around the user. Tries Places API (New)
     * first, then falls back to the legacy Places Text Search API (which
     * works with classic Maps keys that don't have "Places API (New)"
     * enabled). Only successful, non-empty results are cached.
     */
    public function findPlaces(string $query, ?float $lat, ?float $lng, int $limit = 5): array
    {
        $apiKey = $this->mapsKey();
        if (empty($apiKey)) {
            Log::warning('KwameTools: GOOGLE_MAPS_API_KEY missing — find_places disabled.');
            return [];
        }

        $biasLat = $lat ?? self::NAIROBI_LAT;
        $biasLng = $lng ?? self::NAIROBI_LNG;

        $cacheKey = 'kwame_places:v2:' . md5(strtolower($query) . round($biasLat, 2) . round($biasLng, 2));
        $cached   = Cache::get($cacheKey);
        if (!empty($cached)) return $cached;

        $places = $this->searchPlacesV1($query, $biasLat, $biasLng, $limit, $apiKey);
        if (empty($places)) {
            $places = $this->searchPlacesLegacy($query, $biasLat, $biasLng, $limit, $apiKey);
        }

        // Never cache misses — a temporary API hiccup shouldn't block results for an hour.
        if (!empty($places)) {
            Cache::put($cacheKey, $places, self::PLACES_CACHE_TTL);
        }

        return $places;
    }

    /** Places API (New) — richer data when the key has it enabled. */
    private function searchPlacesV1(string $query, float $biasLat, float $biasLng, int $limit, string $apiKey): array
    {
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
                    'textQuery'      => $query,
                    'maxResultCount' => $limit,
                    'locationBias'   => [
                        'circle' => [
                            'center' => ['latitude' => $biasLat, 'longitude' => $biasLng],
                            'radius' => 30000.0,
                        ],
                    ],
                    'regionCode' => 'KE',
                ]);

            if (!$response->successful()) {
                Log::warning('KwameTools: Places (New) error — will try legacy API', [
                    'status' => $response->status(),
                    'body'   => $response->json(),
                ]);
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
            Log::error("KwameTools: Places (New) failed for '{$query}': " . $e->getMessage());
            return [];
        }
    }

    /** Legacy Places Text Search — same key as classic Geocoding. */
    private function searchPlacesLegacy(string $query, float $biasLat, float $biasLng, int $limit, string $apiKey): array
    {
        try {
            $response = Http::withoutVerifying()
                ->timeout(8)
                ->get('https://maps.googleapis.com/maps/api/place/textsearch/json', [
                    'query'    => $query,
                    'location' => "{$biasLat},{$biasLng}",
                    'radius'   => 30000,
                    'region'   => 'ke',
                    'key'      => $apiKey,
                ]);

            if (!$response->successful() || $response->json('status') === 'REQUEST_DENIED') {
                Log::error('KwameTools: legacy Places error', [
                    'status'     => $response->status(),
                    'api_status' => $response->json('status'),
                    'message'    => $response->json('error_message'),
                ]);
                return [];
            }

            return collect($response->json('results') ?? [])
                ->take($limit)
                ->map(fn ($p) => [
                    'name'     => $p['name'] ?? 'Unknown',
                    'address'  => $p['formatted_address'] ?? null,
                    'lat'      => (float) ($p['geometry']['location']['lat'] ?? 0),
                    'lng'      => (float) ($p['geometry']['location']['lng'] ?? 0),
                    'rating'   => isset($p['rating']) ? (float) $p['rating'] : null,
                    'ratings_count' => $p['user_ratings_total'] ?? null,
                    'category' => isset($p['types'][0]) ? str_replace('_', ' ', $p['types'][0]) : null,
                    'open_now' => $p['opening_hours']['open_now'] ?? null,
                ])
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::error("KwameTools: legacy Places failed for '{$query}': " . $e->getMessage());
            return [];
        }
    }

    /**
     * Current conditions from the Google Maps Platform Weather API.
     * Returns a compact array for LLM narration, or ['error' => ...].
     */
    public function getWeather(?float $lat, ?float $lng): array
    {
        $apiKey = $this->mapsKey();
        if (empty($apiKey)) return ['error' => 'Weather is not configured.'];

        $lat = $lat ?? self::NAIROBI_LAT;
        $lng = $lng ?? self::NAIROBI_LNG;

        $cacheKey = 'kwame_weather:' . round($lat, 2) . ':' . round($lng, 2);
        $cached   = Cache::get($cacheKey);
        if (!empty($cached)) return $cached;

        try {
            $response = Http::withoutVerifying()
                ->timeout(8)
                ->get('https://weather.googleapis.com/v1/currentConditions:lookup', [
                    'key'                => $apiKey,
                    'location.latitude'  => $lat,
                    'location.longitude' => $lng,
                    'unitsSystem'        => 'METRIC',
                ]);

            if (!$response->successful()) {
                Log::error('KwameTools: Weather API error', ['status' => $response->status(), 'body' => $response->json()]);
                return ['error' => 'Weather is unavailable right now.'];
            }

            $j = $response->json();
            $weather = [
                'condition'      => $j['weatherCondition']['description']['text'] ?? null,
                'temperature_c'  => $j['temperature']['degrees'] ?? null,
                'feels_like_c'   => $j['feelsLikeTemperature']['degrees'] ?? null,
                'humidity_pct'   => $j['relativeHumidity'] ?? null,
                'rain_chance_pct'=> $j['precipitation']['probability']['percent'] ?? null,
                'wind_kmh'       => $j['wind']['speed']['value'] ?? null,
                'is_daytime'     => $j['isDaytime'] ?? null,
            ];

            Cache::put($cacheKey, $weather, 600); // conditions barely move in 10 min
            return $weather;
        } catch (\Throwable $e) {
            Log::error('KwameTools: getWeather failed: ' . $e->getMessage());
            return ['error' => 'Weather is unavailable right now.'];
        }
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
