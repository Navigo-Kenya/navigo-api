<?php

namespace App\Services;

use App\Models\CachedSnappedPolyline;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SnapToRoadsService
{
    private string $apiKey;
    private const MAX_POINTS = 100; // Google Roads API hard limit

    public function __construct()
    {
        $this->apiKey = config('transit.google_maps_key', '');
    }

    /**
     * Snaps [[lat,lng],...] coords to the nearest drivable roads.
     *
     * Caching: permanent, keyed by $cacheKey (caller decides granularity).
     * Fallback: returns original $coords untouched if the API is unavailable.
     *
     * @param  array  $coords    [[lat, lng], ...]
     * @param  string $cacheKey  caller-supplied deterministic key
     * @return array             [[lat, lng], ...]
     */
    public function snap(array $coords, string $cacheKey): array
    {
        if (count($coords) < 2 || empty($this->apiKey)) {
            return $coords;
        }

        $cached = CachedSnappedPolyline::where('cache_key', $cacheKey)->first();
        if ($cached) {
            return $cached->coordinates;
        }

        $snapped = $this->fetchFromGoogle($coords);

        if ($snapped && count($snapped) >= 2) {
            try {
                CachedSnappedPolyline::create(['cache_key' => $cacheKey, 'coordinates' => $snapped]);
            } catch (\Exception $e) {
                Log::debug('SnapToRoadsService cache write skipped', ['error' => $e->getMessage()]);
            }
            return $snapped;
        }

        return $coords;
    }

    private function fetchFromGoogle(array $coords): ?array
    {
        // Downsample to fit within the API's point limit
        if (count($coords) > self::MAX_POINTS) {
            $coords = $this->downsample($coords, self::MAX_POINTS);
        }

        $path = implode('|', array_map(fn($c) => "{$c[0]},{$c[1]}", $coords));

        try {
            $response = Http::timeout(8)->withoutVerifying()->get(
                'https://roads.googleapis.com/v1/snapToRoads',
                ['path' => $path, 'interpolate' => 'true', 'key' => $this->apiKey]
            );
        } catch (\Exception $e) {
            Log::warning('Google Roads API timeout', ['error' => $e->getMessage()]);
            return null;
        }

        if (!$response->ok()) {
            Log::warning('Google Roads API error', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 300),
            ]);
            return null;
        }

        $points = $response->json()['snappedPoints'] ?? [];
        if (empty($points)) {
            return null;
        }

        return array_map(fn($p) => [
            round((float) $p['location']['latitude'],  6),
            round((float) $p['location']['longitude'], 6),
        ], $points);
    }

    /**
     * Evenly subsample $coords to $max points, always keeping first and last.
     */
    private function downsample(array $coords, int $max): array
    {
        $n      = count($coords);
        $result = [];
        $step   = ($n - 1) / ($max - 1);

        for ($i = 0; $i < $max - 1; $i++) {
            $result[] = $coords[(int) round($i * $step)];
        }
        $result[] = $coords[$n - 1];

        return $result;
    }
}
