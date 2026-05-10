<?php

namespace App\Services;

use App\Models\Stop;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TransitEngineService
{
    protected string $otpBaseUrl = 'http://127.0.0.1:8080/otp/routers/default';

    // How long (seconds) to cache an identical OTP query.
    // OTP results are deterministic for the same inputs, so 5 min is safe.
    protected int $otpCacheTtl = 300;

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

    public function findJourney( float $fromLat, float $fromLng, float $toLat, float $toLng, ?string $date = null, ?string $time = null, float $walkReluctance = 13.5): ?array
    {
        // $date = $date ?? now()->format('Y-m-d');
        
        // If the AI didn't provide a date/time, use 'now'
        $resolvedDate = $date ?? now()->timezone('Africa/Nairobi')->format('Y-m-d');
        $resolvedTime = $time ?? now()->timezone('Africa/Nairobi')->format('h:ia');
        
        // NIGHTTIME DEV HACK: Only apply if the AI/User didn't request a specific future time
        if (!$date && !$time) {
            $hour = (int) now()->timezone('Africa/Nairobi')->format('H');
            if ($hour >= 20 || $hour <= 4) {
                $resolvedTime = '02:00pm'; // Fake it so OTP returns routes late at night
            }
        }

        $cacheKey = 'otp:journey:' . md5("{$fromLat},{$fromLng},{$toLat},{$toLng},{$resolvedDate},{$resolvedTime},{$walkReluctance}");

        return Cache::remember($cacheKey, $this->otpCacheTtl, function () use (
            $fromLat, $fromLng, $toLat, $toLng, $resolvedDate, $resolvedTime, $walkReluctance
        ) {
            return $this->fetchFromOtp($fromLat, $fromLng, $toLat, $toLng, $resolvedDate, $resolvedTime, $walkReluctance);
        });
    }

    private function fetchFromOtp( float $fromLat, float $fromLng, float $toLat, float $toLng, string $date, string $time, float $walkReluctance): ?array
    {
        try {
            $response = Http::timeout(45)->get("{$this->otpBaseUrl}/plan", [
                'fromPlace'        => "{$fromLat},{$fromLng}",
                'toPlace'          => "{$toLat},{$toLng}",
                'mode'             => 'TRANSIT,WALK',
                'maxWalkDistance'  => 1500,
                'walkReluctance'   => $walkReluctance,
                'transferPenalty'  => 120,
                'arriveBy'         => 'false',
                'numItineraries'   => 2,
                'date'             => $date,
                'time'             => $time,
            ]);

            if (!$response->successful()) {
                Log::warning('OTP returned non-2xx response', ['status' => $response->status()]);
                return null;
            }

            $body        = $response->json();
            $itineraries = $body['plan']['itineraries'] ?? [];

            if (empty($itineraries)) {
                return null;
            }

            return $this->parseItinerary($itineraries[0]);

        } catch (Exception $e) {
            Log::error('OTP Engine Error', ['message' => $e->getMessage()]);
            return null;
        }
    }

    private function parseItinerary(array $itinerary): array
    {
        $segments      = [];
        $routeNames    = [];
        $totalDistance = 0;

        $transitModes = ['BUS', 'TRAM', 'SUBWAY', 'RAIL', 'FERRY'];

        foreach ($itinerary['legs'] as $leg) {
            $mode       = $leg['mode'];
            $isTransit  = in_array($mode, $transitModes, true);
            $routeName  = $leg['routeShortName'] ?? $leg['route'] ?? $leg['routeLongName'] ?? null;
            $distance   = $leg['distance'] ?? 0;

            $totalDistance += $distance;

            if ($isTransit && $routeName) {
                $routeNames[] = $routeName;
            }

            $fromName = $leg['from']['name'] ?? 'Unknown';
            $toName   = $leg['to']['name']   ?? 'Unknown';

            // Extract turn-by-turn walk steps
            $walkSteps = [];
            if (isset($leg['steps'])) {
                foreach ($leg['steps'] as $step) {
                    $direction = $step['relativeDirection'] ?? 'Continue';
                    $street = $step['streetName'] ?? 'path';
                    if ($street === 'OpenStreetMap') $street = 'pedestrian path';

                    $walkSteps[] = [
                        'instruction' => "{$direction} on {$street}",
                        'distance' => round($step['distance'] ?? 0),
                        'location' => [(float)$step['lon'], (float)$step['lat']],
                    ];
                }
            }

            Log::info("Walk steps for leg from '{$fromName}' to '{$toName}'", ['steps' => $walkSteps]);

            $segments[] = [
                'mode'       => $mode,
                'duration'   => (int) round($leg['duration'] ?? 0),
                'distance'   => (int) round($distance),
                'route_name' => $routeName,
                'polyline'   => $leg['legGeometry']['points'] ?? '',
                'walk_steps' => $walkSteps, // Include the walk steps in the segment
                'from'       => [
                    'name' => $fromName === 'Origin'      ? 'Current Location' : $fromName,
                    'lat'  => $leg['from']['lat'],
                    'lng'  => $leg['from']['lon'],
                ],
                'to'         => [
                    'name' => $toName === 'Destination' ? 'Destination' : $toName,
                    'lat'  => $leg['to']['lat'],
                    'lng'  => $leg['to']['lon'],
                ],
            ];
        }

        $uniqueRouteNames = array_values(array_unique($routeNames));

        return [
            'type'               => count($uniqueRouteNames) > 1 ? 'transfer' : 'direct',
            'summary'            => count($uniqueRouteNames) > 0
                ? 'Via ' . implode(' → ', $uniqueRouteNames)
                : 'Walk only',
            'total_duration'     => (int) round($itinerary['duration']),
            'total_walk_distance'=> (int) round($itinerary['walkDistance']),
            'total_distance'     => (int) round($totalDistance),
            'segments'           => $segments,
        ];
    }
}
