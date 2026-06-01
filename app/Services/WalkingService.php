<?php

namespace App\Services;

use App\Models\CachedWalkingRoute;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WalkingService
{
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('transit.google_maps_key', '');
    }

    /**
     * Returns a road-snapped walking route between two points.
     *
     * Caching layers (cheapest first):
     *   1. DB table `cached_walking_routes` keyed by coords rounded to 4dp, permanent
     *   2. Google Directions API (walking), called at most once per unique O/D pair
     *   3. OTP fallback, used when walk < 100 m, API key missing, or API fails
     *
     * @param  array $otpCoords     [[lat,lng],...] from OTP legGeometry
     * @param  array $otpSteps      walk steps from OTP
     * @param  int   $otpDistanceM  distance in metres from OTP
     * @param  int   $otpDurationS  duration in seconds from OTP
     * @return array{coordinates:array, walk_steps:array, distance:int, duration:int}
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

        // Very short walks or missing key, OTP geometry is precise enough
        if ($otpDistanceM < 100 || empty($this->apiKey)) {
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

        $result = $this->fetchFromGoogle($fromLat, $fromLng, $toLat, $toLng);

        if (!$result) {
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
            // Duplicate insert from a race condition, not critical
            Log::debug('WalkingService cache write skipped', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    private function fetchFromGoogle(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        try {
            $response = Http::timeout(8)->withoutVerifying()->get('https://maps.googleapis.com/maps/api/directions/json', [
                'origin'      => "{$fromLat},{$fromLng}",
                'destination' => "{$toLat},{$toLng}",
                'mode'        => 'walking',
                'region'      => 'ke',
                'key'         => $this->apiKey,
            ]);
        } catch (\Exception $e) {
            Log::warning('Google Directions API timeout', ['error' => $e->getMessage()]);
            return null;
        }

        if (!$response->ok()) {
            return null;
        }

        $data = $response->json();

        if (($data['status'] ?? '') !== 'OK' || empty($data['routes'])) {
            Log::warning('Google Directions returned no routes', ['status' => $data['status'] ?? 'unknown']);
            return null;
        }

        $route = $data['routes'][0];
        $leg   = $route['legs'][0];

        $coordinates = $this->decodePolyline($route['overview_polyline']['points']);

        $walkSteps = [];
        foreach ($leg['steps'] as $step) {
            $html = $step['html_instructions'];
            // Split the primary instruction from the secondary "Pass by..." note
            // before stripping tags, so block elements become spaces not word merges.
            $cleaned = preg_replace('/<(div|br|span|p)[^>]*>/i', ' ', $html);
            $cleaned = trim(strip_tags($cleaned));
            $cleaned = preg_replace('/\s{2,}/', ' ', $cleaned);

            // Separate "Pass by ..." note into its own field for richer UI display
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
            'coordinates' => $coordinates,
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
        $lat    = 0;
        $lng    = 0;

        while ($index < $len) {
            foreach (['lat', 'lng'] as $axis) {
                $shift  = 0;
                $result = 0;
                do {
                    $b       = \ord($encoded[$index++]) - 63;
                    $result |= ($b & 0x1f) << $shift;
                    $shift  += 5;
                } while ($b >= 0x20 && $index < $len);
                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                if ($axis === 'lat') { $lat += $delta; } else { $lng += $delta; }
            }
            $coords[] = [round($lat / 1e5, 6), round($lng / 1e5, 6)];
        }

        return $coords;
    }
}
