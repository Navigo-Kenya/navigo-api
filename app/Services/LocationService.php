<?php

namespace App\Services;

use App\Models\Stop;
use Illuminate\Support\Facades\DB;

class LocationService
{
    /**
     * Finds stops nearby with distance and coordinates in ONE query.
     */
    public function getNearbyStops(float $lat, float $lng, int $radiusMeters = 1500)
    {
        return Stop::select('*')
            // Get coordinates and distance directly in the main query
            ->selectRaw("ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng")
            ->selectRaw("ST_DistanceSphere(location, ST_MakePoint(?, ?)) as distance", [$lng, $lat])
            ->whereRaw("ST_DistanceSphere(location, ST_MakePoint(?, ?)) <= ?", [$lng, $lat, $radiusMeters])
            ->orderBy('distance', 'asc')
            ->limit(20)
            ->get();
    }


    /**
     * Search with Fuzzy Matching (pg_trgm) for Nairobi stages.
     */
    public function searchStops(string $query, int $limit = 15)
    {
        // The <-> operator is pg_trgm's "distance" operator. 
        // Lower distance means a closer text match.
        return Stop::select('*')
            ->selectRaw("ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng")
            // Create a combined search string of official name + aliases
            ->selectRaw("name || ' ' || COALESCE(aliases, '') as search_vector")
            // Calculate the text match quality
            ->selectRaw("similarity((name || ' ' || COALESCE(aliases, '')), ?) as match_score", [$query])
            // Only return things that somewhat match
            ->whereRaw("(name || ' ' || COALESCE(aliases, '')) % ?", [$query])
            // ORDER BY: Best text match first, then break ties using popularity
            ->orderByRaw("match_score DESC")
            ->orderBy('popularity_score', 'desc')
            ->limit($limit)
            ->get();
    }

}
