<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RoadSnapperService
{
    public static function make(): self
    {
        return new self();
    }

    /**
     * Snap a single stop coordinate to the nearest road.
     * Returns an array with original + snapped coordinates and metadata.
     */
    public function snap(float $lat, float $lng): array
    {
        $driver = config('otp.road_snapper_driver', 'none');

        return match ($driver) {
            'mapbox' => $this->snapViaMapbox($lat, $lng),
            'google' => $this->snapViaGoogle($lat, $lng),
            default  => $this->nullSnap($lat, $lng),
        };
    }

    private function snapViaMapbox(float $lat, float $lng): array
    {
        $token = config('services.mapbox.api_key', '');
        if (empty($token)) {
            return $this->nullSnap($lat, $lng);
        }

        try {
            $response = Http::timeout(8)->get(
                "https://api.mapbox.com/matching/v5/mapbox/driving/{$lng},{$lat}",
                [
                    'geometries'   => 'geojson',
                    'radiuses'     => 50,
                    'access_token' => $token,
                ]
            );
        } catch (\Exception $e) {
            Log::warning('RoadSnapperService: Mapbox timeout', ['error' => $e->getMessage()]);
            return $this->nullSnap($lat, $lng);
        }

        if (!$response->ok()) {
            Log::warning('RoadSnapperService: Mapbox error', ['status' => $response->status()]);
            return $this->nullSnap($lat, $lng);
        }

        $body     = $response->json();
        $matching = $body['matchings'][0] ?? null;

        if (!$matching) {
            return $this->nullSnap($lat, $lng);
        }

        $coords    = $matching['geometry']['coordinates'][0] ?? null;
        $roadName  = $body['tracepoints'][0]['name'] ?? null;
        $confidence = $matching['confidence'] ?? 0;

        if (!$coords || $confidence < 0.5) {
            return $this->nullSnap($lat, $lng);
        }

        $snappedLng = (float) $coords[0];
        $snappedLat = (float) $coords[1];
        $distanceM  = $this->haversineMeters($lat, $lng, $snappedLat, $snappedLng);

        return [
            'snapped'      => true,
            'original_lat' => $lat,
            'original_lng' => $lng,
            'snapped_lat'  => round($snappedLat, 6),
            'snapped_lng'  => round($snappedLng, 6),
            'road_name'    => $roadName,
            'distance_m'   => round($distanceM, 1),
        ];
    }

    private function snapViaGoogle(float $lat, float $lng): array
    {
        $key = config('services.google.roads_api_key', '');
        if (empty($key)) {
            return $this->nullSnap($lat, $lng);
        }

        try {
            $response = Http::timeout(8)->get(
                'https://roads.googleapis.com/v1/nearestRoads',
                ['points' => "{$lat},{$lng}", 'key' => $key]
            );
        } catch (\Exception $e) {
            Log::warning('RoadSnapperService: Google timeout', ['error' => $e->getMessage()]);
            return $this->nullSnap($lat, $lng);
        }

        if (!$response->ok()) {
            Log::warning('RoadSnapperService: Google error', ['status' => $response->status()]);
            return $this->nullSnap($lat, $lng);
        }

        $points = $response->json()['snappedPoints'] ?? [];
        if (empty($points)) {
            return $this->nullSnap($lat, $lng);
        }

        $location  = $points[0]['location'];
        $snappedLat = (float) $location['latitude'];
        $snappedLng = (float) $location['longitude'];
        $distanceM  = $this->haversineMeters($lat, $lng, $snappedLat, $snappedLng);

        return [
            'snapped'      => true,
            'original_lat' => $lat,
            'original_lng' => $lng,
            'snapped_lat'  => round($snappedLat, 6),
            'snapped_lng'  => round($snappedLng, 6),
            'road_name'    => null,
            'distance_m'   => round($distanceM, 1),
        ];
    }

    private function nullSnap(float $lat, float $lng): array
    {
        return [
            'snapped'      => false,
            'original_lat' => $lat,
            'original_lng' => $lng,
            'snapped_lat'  => $lat,
            'snapped_lng'  => $lng,
            'road_name'    => null,
            'distance_m'   => 0.0,
        ];
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R    = 6371000;
        $φ1   = deg2rad($lat1);
        $φ2   = deg2rad($lat2);
        $Δφ   = deg2rad($lat2 - $lat1);
        $Δλ   = deg2rad($lng2 - $lng1);
        $a    = sin($Δφ / 2) ** 2 + cos($φ1) * cos($φ2) * sin($Δλ / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
