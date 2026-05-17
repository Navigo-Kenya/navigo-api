<?php

namespace App\Services;

use App\Models\Route;
use App\Models\Stop;
use Illuminate\Support\Facades\Cache;
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
     * Returns all stops as a slim array [id, name, lat, lng].
     * Cached for 1 hour — stops dataset rarely changes.
     */
    public function getAllStops(): array
    {
        return Cache::remember('stops:all:slim', 3600, function () {
            return Stop::select('id', 'name', 'route_nams', 'location_t')
                ->selectRaw("ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng")
                ->get()
                ->map(fn($s) => [
                    'id'         => $s->id,
                    'name'       => $s->name,
                    'lat'        => (float) $s->lat,
                    'lng'        => (float) $s->lng,
                    'route_nams' => $s->route_nams,
                    'location_t' => (int) $s->location_t,
                ])
                ->all();
        });
    }

    /**
     * Returns full stop detail + resolved routes for a single stop.
     * Cached 30 min per stop — route assignments rarely change.
     */
    public function getStop(string $id): ?array
    {
        return Cache::remember("stop:{$id}:detail", 1800, function () use ($id) {
            $stop = Stop::select('id', 'name', 'location_t', 'route_ids')
                ->selectRaw("ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng")
                ->where('id', $id)
                ->first();

            if (!$stop) return null;

            $routeIds = array_values(
                array_filter(array_map('trim', explode(',', $stop->route_ids ?? '')))
            );

            $routes = [];
            if (!empty($routeIds)) {
                $routes = Route::whereIn('route_id', $routeIds)
                    ->select('route_id', 'route_short_name', 'route_long_name', 'route_type')
                    ->orderBy('route_short_name')
                    ->get()
                    ->map(fn($r) => [
                        'id'         => $r->route_id,
                        'short_name' => $r->route_short_name,
                        'long_name'  => $r->route_long_name,
                        'route_type' => (int) $r->route_type,
                    ])
                    ->all();
            }

            return [
                'id'         => $stop->id,
                'name'       => $stop->name,
                'lat'        => (float) $stop->lat,
                'lng'        => (float) $stop->lng,
                'location_t' => (int) $stop->location_t,
                'routes'     => $routes,
            ];
        });
    }

    /**
     * Search stops by name/aliases.
     *
     * Matching strategy (OR of two conditions):
     *   1. ILIKE substring  — "ngong" matches "Ngong Road Stage" instantly, score = 0.9
     *   2. pg_trgm fuzzy    — handles misspellings, score = similarity()
     *
     * Hard minimum of 0.15 on the blended score so truly unrelated stops are
     * excluded and the frontend geocoding fallback kicks in instead.
     */
    public function searchStops(string $query, int $limit = 15)
    {
        return Stop::select('*')
            ->selectRaw("ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng")
            ->selectRaw(
                "GREATEST(
                    similarity((name || ' ' || COALESCE(aliases, '')), ?),
                    CASE WHEN name ILIKE ? THEN 0.9 ELSE 0.0 END
                ) AS match_score",
                [$query, '%' . $query . '%']
            )
            ->where(function ($q) use ($query) {
                $q->whereRaw("name ILIKE ?", ['%' . $query . '%'])
                  ->orWhereRaw("(name || ' ' || COALESCE(aliases, '')) % ?", [$query]);
            })
            ->whereRaw(
                "GREATEST(
                    similarity((name || ' ' || COALESCE(aliases, '')), ?),
                    CASE WHEN name ILIKE ? THEN 0.9 ELSE 0.0 END
                ) > 0.15",
                [$query, '%' . $query . '%']
            )
            ->orderByRaw("match_score DESC")
            ->orderBy('popularity_score', 'desc')
            ->limit($limit)
            ->get();
    }

}
