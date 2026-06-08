<?php

namespace App\Services;

use App\Models\TransitReport;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Fetch active reports strictly within the user's current map viewport.
     * * @param float $north Max Latitude
     * @param float $south Min Latitude
     * @param float $east  Max Longitude
     * @param float $west  Min Longitude
     */
    public function getReportsInViewport(float $north, float $south, float $east, float $west): array
    {
        // PostGIS ST_MakeEnvelope expects: minLng, minLat, maxLng, maxLat, SRID
        $envelope = "ST_MakeEnvelope(?, ?, ?, ?, 4326)";

        return TransitReport::select('id', 'type', 'upvotes', 'expires_at')
            // Extract lat/lng for the frontend
            ->selectRaw("ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng")
            // Only get reports inside the bounding box
            ->whereRaw("location::geometry && $envelope", [$west, $south, $east, $north])
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->limit(50) // Prevent map clutter if a region is heavily reported
            ->get()
            ->map(fn($r) => [
                'id'         => $r->id,
                'type'       => $r->type,
                'lat'        => (float) $r->lat,
                'lng'        => (float) $r->lng,
                'upvotes'    => $r->upvotes,
                'expires_at' => $r->expires_at->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Store a new crowdsourced report
     */
    public function createReport(array $data): TransitReport
    {
        // Define TTL based on report type. Accidents last longer than stage queues.
        $ttlMinutes = match ($data['type']) {
            'accident', 'flooded_route' => 120,
            'police_check'              => 90,
            'stage_queue'               => 45,
            default                     => 60,
        };

        return TransitReport::create([
            'user_id'    => $data['user_id'] ?? null,
            'type'       => $data['type'],
            // Insert geometry using PostGIS ST_MakePoint (Lng, Lat)
            'location'   => DB::raw("ST_SetSRID(ST_MakePoint({$data['lng']}, {$data['lat']}), 4326)"),
            'expires_at' => now()->addMinutes($ttlMinutes),
            'status'     => 'active',
        ]);
    }
}