<?php

namespace App\Services;

use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Route;
use App\Models\Stop;

class TransitEngineService
{
    protected string $otpBaseUrl;
    protected int    $otpCacheTtl;

    public function __construct(
        private WalkingService $walkingService,
    ) {
        $this->otpBaseUrl  = config('transit.otp.base_url');
        $this->otpCacheTtl = config('transit.otp.cache_ttl');
    }

    // ─────────────────────────────────────────────────────────────────────
    // STOP LOOKUP
    // ─────────────────────────────────────────────────────────────────────

    public function getStopsNearLocation(float $lat, float $lng, int $radiusMeters = 800, int $fallbackLimit = 3): array
    {
        // Primary: stops within radius, sorted by distance
        $stops = Stop::select('id')
            ->selectRaw("ST_DistanceSphere(location, ST_MakePoint(?, ?)) as distance", [$lng, $lat])
            ->whereRaw(
                "ST_DWithin(location::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)",
                [$lng, $lat, $radiusMeters]
            )
            ->orderBy('distance', 'asc')
            ->pluck('id')
            ->toArray();

        // Fallback: nearest N stops regardless of radius
        if (empty($stops)) {
            $stops = Stop::select('id')
                ->selectRaw("ST_DistanceSphere(location, ST_MakePoint(?, ?)) as distance", [$lng, $lat])
                ->orderByRaw(
                    "location::geometry <-> ST_SetSRID(ST_MakePoint(?, ?), 4326)::geometry",
                    [$lng, $lat]
                )
                ->limit($fallbackLimit)
                ->pluck('id')
                ->toArray();
        }

        return $stops;
    }

    // ─────────────────────────────────────────────────────────────────────
    // JOURNEY PLANNING
    // ─────────────────────────────────────────────────────────────────────

    public function findJourney( float $fromLat, float $fromLng, float $toLat, float $toLng, ?string $date = null, ?string $time = null, float $walkReluctance = 13.5, int $maxWalkDistance = 1500): array
    {
        $resolvedDate = $date ?? now()->timezone('Africa/Nairobi')->format('Y-m-d');
        $resolvedTime = $time ?? now()->timezone('Africa/Nairobi')->format('h:ia');

        // NIGHTTIME DEV HACK: Only apply if the AI/User didn't request a specific future time
        if (!$date && !$time) {
            $hour = (int) now()->timezone('Africa/Nairobi')->format('H');
            if ($hour >= 20 || $hour <= 4) {
                $resolvedTime = '02:00pm';
            }
        }

        $cacheKey = 'otp:journey:v2:' . md5("{$fromLat},{$fromLng},{$toLat},{$toLng},{$resolvedDate},{$resolvedTime},{$walkReluctance},{$maxWalkDistance}");

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->fetchFromOtp($fromLat, $fromLng, $toLat, $toLng, $resolvedDate, $resolvedTime, $walkReluctance, $maxWalkDistance);

        // Only cache a real response (including empty []). Do not cache null — null means
        // OTP is unreachable and the instance may recover before the TTL expires.
        if ($result !== null) {
            Cache::put($cacheKey, $result, $this->otpCacheTtl);
        }

        return $result;
    }

    private function fetchFromOtp( float $fromLat, float $fromLng, float $toLat, float $toLng, string $date, string $time, float $walkReluctance, int $maxWalkDistance = 1500): ?array
    {
        try {
            $response = Http::timeout(45)->get("{$this->otpBaseUrl}/plan", [
                'fromPlace'             => "{$fromLat},{$fromLng}",
                'toPlace'               => "{$toLat},{$toLng}",
                'mode'                  => 'TRANSIT,WALK',
                'maxWalkDistance'       => $maxWalkDistance,
                'walkReluctance'        => $walkReluctance,
                'transferPenalty'       => 120,
                'arriveBy'              => 'false',
                'numItineraries'        => 2,
                'date'                  => $date,
                'time'                  => $time,
                'showIntermediateStops' => 'true',
            ]);

            if (!$response->successful()) {
                Log::warning('OTP returned non-2xx response', ['status' => $response->status()]);
                // Treat a bad response as unavailable — caller will surface a 503.
                return null;
            }

            $body        = $response->json();
            $itineraries = $body['plan']['itineraries'] ?? [];

            // Empty itineraries means OTP is up but found no valid route — not the same as down.
            if (empty($itineraries)) {
                return [];
            }

            $parsed = array_map(fn ($it) => $this->parseItinerary($it), $itineraries);

            // OTP sometimes returns the same itinerary twice when only one route exists.
            // Deduplicate by summary + rounded total_duration (within 60 s = same route).
            $seen   = [];
            $unique = [];
            foreach ($parsed as $route) {
                $bucket = ($route['summary'] ?? '') . '|' . (int) round(($route['total_duration'] ?? 0) / 60);
                if (!isset($seen[$bucket])) {
                    $seen[$bucket] = true;
                    $unique[]      = $route;
                }
            }

            return array_values($unique);

        } catch (ConnectionException $e) {
            // OTP container is down, restarting, or unreachable.
            Log::error('OTP unreachable', ['message' => $e->getMessage()]);
            return null;
        } catch (Exception $e) {
            Log::error('OTP engine error', ['message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Decodes a Google Polyline5-encoded string into [[lat, lng], ...] pairs.
     * OTP emits this format in legGeometry.points, no external library required.
     */
    private function decodePolyline(string $encoded): array
    {
        $coords = [];
        $index  = 0;
        $len    = \strlen($encoded);
        $lat    = 0;
        $lng    = 0;

        while ($index < $len) {
            foreach (['lat', 'lng'] as $axis) {
                $shift  = 0;
                $result = 0;
                do {
                    $b      = \ord($encoded[$index++]) - 63;
                    $result |= ($b & 0x1f) << $shift;
                    $shift  += 5;
                } while ($b >= 0x20 && $index < $len);
                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                if ($axis === 'lat') {
                    $lat += $delta;
                } else {
                    $lng += $delta;
                }
            }
            $coords[] = [round($lat / 1e5, 6), round($lng / 1e5, 6)];
        }

        return $coords;
    }

    private function parseItinerary(array $itinerary): array
    {
        $segments      = [];
        $routeNames    = [];
        $totalDistance = 0;

        $transitModes = ['BUS', 'TRAM', 'SUBWAY', 'RAIL', 'FERRY'];

        foreach ($itinerary['legs'] as $leg) {
            $mode      = $leg['mode'];
            $isTransit = \in_array($mode, $transitModes, true);
            $routeName = $leg['routeShortName'] ?? $leg['route'] ?? $leg['routeLongName'] ?? null;
            $distance  = $leg['distance'] ?? 0;
            $duration  = (int) round($leg['duration'] ?? 0);

            $totalDistance += $distance;

            // Extract the route color
            $routeColor = null;
            if ($isTransit) {
                $rawRouteId = $leg['routeId'] ?? null;
                if ($rawRouteId) {
                    $cleanRouteId = \str_contains($rawRouteId, ':') ? explode(':', $rawRouteId, 2)[1] : $rawRouteId;
                    try {
                        // Check both the Route ID AND the Route Name!
                        $dbRoute = Route::where('route_id', $cleanRouteId)
                                        ->orWhere('route_short_name', $routeName) // Ensure your DB column name is correct here
                                        ->orWhere('name', $routeName)             // Or whatever your name column is
                                        ->first();

                        if ($dbRoute && !empty($dbRoute->route_color)) {
                            $routeColor = str_starts_with($dbRoute->route_color, '#')
                                ? $dbRoute->route_color
                                : '#' . $dbRoute->route_color;
                        }
                    } catch (Exception $e) {
                        // Silently fallback if the model fails
                    }
                }
            }

            if ($isTransit && $routeName) {
                $routeNames[] = $routeName;
            }

            $fromName = $leg['from']['name'] ?? 'Unknown';
            $toName   = $leg['to']['name']   ?? 'Unknown';
            $encoded  = $leg['legGeometry']['points'] ?? '';
            $otpCoords = $encoded ? $this->decodePolyline($encoded) : [];

            if ($isTransit) {
                // Use the authoritative GTFS shape sliced between the two stops.
                // Falls back to OTP's legGeometry polyline (also derived from the GTFS shape).
                // No additional road-snapping: shapes are already drawn snapped to roads.
                $coordinates = $this->gtfsCoordinates($leg) ?? $otpCoords;
                $walkSteps   = [];

                // Build ordered stop list: boarding + intermediate + alighting
                $legStops = [];
                $legStops[] = [
                    'name' => $leg['from']['name'] ?? 'Unknown',
                    'lat'  => (float) ($leg['from']['lat'] ?? 0),
                    'lng'  => (float) ($leg['from']['lon'] ?? 0),
                ];
                foreach ($leg['intermediateStops'] ?? [] as $ist) {
                    $legStops[] = [
                        'name' => $ist['name'] ?? 'Unknown',
                        'lat'  => (float) ($ist['lat'] ?? 0),
                        'lng'  => (float) ($ist['lon'] ?? 0),
                    ];
                }
                $legStops[] = [
                    'name' => $leg['to']['name'] ?? 'Unknown',
                    'lat'  => (float) ($leg['to']['lat'] ?? 0),
                    'lng'  => (float) ($leg['to']['lon'] ?? 0),
                ];
            } else {
                // ── Walking: Google Directions API (road-snapped, DB-cached) ──────
                // Falls back to OTP geometry when walk < 100 m or API key is absent.
                $otpSteps = [];
                if (isset($leg['steps'])) {
                    foreach ($leg['steps'] as $step) {
                        $street = $step['streetName'] ?? 'path';
                        if ($street === 'OpenStreetMap') $street = 'pedestrian path';
                        $otpSteps[] = [
                            'instruction' => ($step['relativeDirection'] ?? 'Continue') . " on {$street}",
                            'distance'    => (int) round($step['distance'] ?? 0),
                            'lat'         => (float) ($step['lat'] ?? 0),
                            'lng'         => (float) ($step['lon'] ?? 0),
                        ];
                    }
                }

                $walking = $this->walkingService->getRoute(
                    (float) $leg['from']['lat'], (float) $leg['from']['lon'],
                    (float) $leg['to']['lat'],   (float) $leg['to']['lon'],
                    $otpCoords,
                    $otpSteps,
                    (int) round($distance),
                    $duration
                );

                $coordinates = $walking['coordinates'];
                $walkSteps   = $walking['walk_steps'];
                $distance    = $walking['distance'];
                $duration    = $walking['duration'];
            }

            $segments[] = [
                'mode'        => $mode,
                'duration'    => $duration,
                'distance'    => (int) round($distance),
                'route_name'  => $routeName,
                'route_color' => $routeColor,
                'coordinates' => $coordinates,
                'walk_steps'  => $walkSteps,
                'stops'       => $legStops ?? [],
                'from'        => [
                    'name' => $fromName === 'Origin'      ? 'Current Location' : $fromName,
                    'lat'  => $leg['from']['lat'],
                    'lng'  => $leg['from']['lon'],
                ],
                'to'          => [
                    'name' => $toName === 'Destination' ? 'Destination' : $toName,
                    'lat'  => $leg['to']['lat'],
                    'lng'  => $leg['to']['lon'],
                ],
            ];
        }

        $uniqueRouteNames = array_values(array_unique($routeNames));

        return [
            'polyline_encoding'   => 'google',
            'type'                => \count($uniqueRouteNames) > 1 ? 'transfer' : 'direct',
            'summary'             => \count($uniqueRouteNames) > 0
                ? 'Via ' . implode(' → ', $uniqueRouteNames)
                : 'Walk only',
            'total_duration'      => (int) round($itinerary['duration']),
            'total_walk_distance' => (int) round($itinerary['walkDistance']),
            'total_distance'      => (int) round($totalDistance),
            'segments'            => $segments,
        ];
    }

    /**
     * Slices the GTFS shape LineString for a transit leg between the two stops
     * using PostGIS ST_LineLocatePoint + ST_LineSubstring.
     *
     * Returns [[lat, lng], ...] or null if the trip/shape is not in our DB.
     */
    private function gtfsCoordinates(array $leg): ?array
    {
        $tripId     = $leg['tripId']        ?? null;
        $fromStopId = $leg['from']['stopId'] ?? null;
        $toStopId   = $leg['to']['stopId']   ?? null;

        if (!$tripId || !$fromStopId || !$toStopId) {
            return null;
        }

        // OTP prefixes IDs with the feed/agency name: "agency:TRIP001" → "TRIP001"
        $strip = fn (string $id) => \str_contains($id, ':') ? explode(':', $id, 2)[1] : $id;
        $tripId     = $strip($tripId);
        $fromStopId = $strip($fromStopId);
        $toStopId   = $strip($toStopId);

        try {
            $row = DB::selectOne("
                SELECT ST_AsGeoJSON(
                    ST_LineSubstring(
                        s.path,
                        LEAST(
                            ST_LineLocatePoint(s.path, fs.location::geometry),
                            ST_LineLocatePoint(s.path, ts.location::geometry)
                        ),
                        GREATEST(
                            ST_LineLocatePoint(s.path, fs.location::geometry),
                            ST_LineLocatePoint(s.path, ts.location::geometry)
                        )
                    )
                ) AS geojson
                FROM  trips  t
                JOIN  shapes s  ON s.shape_id  = t.shape_id
                JOIN  stops  fs ON fs.id       = ?
                JOIN  stops  ts ON ts.id       = ?
                WHERE t.trip_id = ?
            ", [$fromStopId, $toStopId, $tripId]);

            if ($row && $row->geojson) {
                $geojson = json_decode($row->geojson, true);
                // GeoJSON coords are [lng, lat], convert to our [lat, lng] convention
                return \array_map(fn ($c) => [$c[1], $c[0]], $geojson['coordinates'] ?? []);
            }
        } catch (\Exception $e) {
            Log::warning('GTFS shape lookup failed', [
                'trip_id' => $tripId,
                'error'   => $e->getMessage(),
            ]);
        }

        return null;
    }
}
