<?php

namespace App\Services;

use App\Models\Stop;
use Illuminate\Support\Facades\DB;

class TransitEngineService
{
    /**
     * Finds physical stops within a specific radius of a coordinate.
     * Uses PostGIS Bounding Box operator (&&) for high performance with GIST indexes.
     */
    public function getStopsNearLocation(float $lat, float $lng, int $radiusMeters = 800)
    {
        return Stop::select('id')
            ->selectRaw("ST_DistanceSphere(location, ST_MakePoint(?, ?)) as distance", [$lng, $lat])
            /* 
               The && operator uses the spatial index. 
               We cast to geography for accurate meter-based expansion in Nairobi.
            */
            ->whereRaw("location::geography && ST_Expand(ST_MakePoint(?, ?)::geography, ?)", [$lng, $lat, $radiusMeters])
            ->orderBy('distance', 'asc')
            ->pluck('id')
            ->toArray();
    }


    
    /**
     * Finds direct trips between two sets of stops.
     * Limits results to one representative trip per route to avoid redundant data.
     */
    public function findValidRoutes(array $originStopIds, array $destStopIds)
    {
        if (empty($originStopIds) || empty($destStopIds)) return [];

        $placeholdersOrigin = implode(',', array_fill(0, count($originStopIds), '?'));
        $placeholdersDest = implode(',', array_fill(0, count($destStopIds), '?'));

        $query = "
            SELECT DISTINCT ON (r.route_id)
                'direct' as type,
                r.route_id, r.agency_id, r.route_short_name, r.route_long_name, r.route_type,
                t.trip_id,
                st_origin.stop_id as board_stop_id,
                st_dest.stop_id as alight_stop_id,
                ST_AsGeoJSON(ST_Simplify(s.path, 0.000015)) as shape_geojson
            FROM routes r
            JOIN trips t ON t.route_id = r.route_id
            JOIN shapes s ON s.shape_id = t.shape_id
            JOIN stop_times st_origin ON st_origin.trip_id = t.trip_id
            JOIN stop_times st_dest ON st_dest.trip_id = t.trip_id
            WHERE st_origin.stop_id IN ($placeholdersOrigin)
              AND st_dest.stop_id IN ($placeholdersDest)
              AND st_origin.stop_sequence < st_dest.stop_sequence
            ORDER BY r.route_id, t.trip_id
        ";

        return DB::select($query, array_merge($originStopIds, $destStopIds));
    }

    /**
     * Finds routes involving one transfer (Two different Matatus).
     * Connects two trips at a shared stop.
     */
    public function findTransferRoutes(array $originStopIds, array $destStopIds)
    {
        if (empty($originStopIds) || empty($destStopIds)) return [];

        $placeholdersOrigin = implode(',', array_fill(0, count($originStopIds), '?'));
        $placeholdersDest = implode(',', array_fill(0, count($destStopIds), '?'));

        $query = "
            SELECT DISTINCT ON (r1.route_id, r2.route_id)
                'transfer' as type,
                -- First Leg
                r1.route_short_name as leg1_route_name,
                r1.route_id as leg1_route_id,
                t1.trip_id as leg1_trip_id,
                st1_start.stop_id as board_stop_id,
                st1_end.stop_id as transfer_stop_id,
                ST_AsGeoJSON(ST_Simplify(sh1.path, 0.000015)) as leg1_shape_geojson,
                
                -- Second Leg
                r2.route_short_name as leg2_route_name,
                r2.route_id as leg2_route_id,
                t2.trip_id as leg2_trip_id,
                st2_end.stop_id as alight_stop_id,
                ST_AsGeoJSON(ST_Simplify(sh2.path, 0.000015)) as leg2_shape_geojson
                
            FROM stop_times st1_start
            JOIN trips t1 ON t1.trip_id = st1_start.trip_id
            JOIN routes r1 ON r1.route_id = t1.route_id
            JOIN shapes sh1 ON sh1.shape_id = t1.shape_id
            JOIN stop_times st1_end ON st1_end.trip_id = t1.trip_id
            
            -- Transfer Point: Finding a stop where another trip departs
            JOIN stop_times st2_start ON st2_start.stop_id = st1_end.stop_id
            JOIN trips t2 ON t2.trip_id = st2_start.trip_id
            JOIN routes r2 ON r2.route_id = t2.route_id
            JOIN shapes sh2 ON sh2.shape_id = t2.shape_id
            JOIN stop_times st2_end ON st2_end.trip_id = t2.trip_id
            
            WHERE st1_start.stop_id IN ($placeholdersOrigin)
              AND st2_end.stop_id IN ($placeholdersDest)
              AND r1.route_id <> r2.route_id -- Ensure it's a real transfer
              AND st1_start.stop_sequence < st1_end.stop_sequence
              AND st2_start.stop_sequence < st2_end.stop_sequence
            ORDER BY r1.route_id, r2.route_id, t1.trip_id, t2.trip_id
            LIMIT 15
        ";

        return DB::select($query, array_merge($originStopIds, $destStopIds));
    }
}
