<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class IsochroneService
{
    private string $otpBase;

    public function __construct()
    {
        $this->otpBase = config('transit.otp.base_url', 'http://127.0.0.1:8080/otp/routers/default');
    }

    public function walkShed(float $lat, float $lng, array $cutoffSeconds = [300, 600, 900]): array
    {
        return $this->fetchIsochrone($lat, $lng, $cutoffSeconds, 'WALK', null, null);
    }

    public function reachabilityMap(
        float $lat,
        float $lng,
        string $date,
        string $time,
        array $cutoffSeconds = [900, 1800, 2700, 3600]
    ): array {
        return $this->fetchIsochrone($lat, $lng, $cutoffSeconds, 'TRANSIT,WALK', $date, $time);
    }

    private function fetchIsochrone(
        float $lat,
        float $lng,
        array $cutoffSeconds,
        string $mode,
        ?string $date,
        ?string $time
    ): array {
        // OTP expects repeated cutoffSec params (cutoffSec=300&cutoffSec=600),
        // not array notation (cutoffSec[0]=300). Build the query string manually.
        $parts = [
            'fromPlace=' . urlencode("{$lat},{$lng}"),
            'mode='      . urlencode($mode),
        ];

        foreach ($cutoffSeconds as $seconds) {
            $parts[] = 'cutoffSec=' . (int) $seconds;
        }

        if ($date) {
            $parts[] = 'date=' . urlencode($date);
        }
        if ($time) {
            $parts[] = 'time=' . urlencode($time);
        }

        $url      = "{$this->otpBase}/isochrone?" . implode('&', $parts);
        $response = Http::timeout(30)->get($url);

        if ($response->failed()) {
            return ['type' => 'FeatureCollection', 'features' => []];
        }

        return $response->json() ?? ['type' => 'FeatureCollection', 'features' => []];
    }
}
