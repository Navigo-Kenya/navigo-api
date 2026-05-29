<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DesireLineService
{
    public function desireLines(int $limit = 200): array
    {
        // Group journey_logs by origin/destination name, join stop coordinates via name match
        $rows = DB::select("
            SELECT
                jl.origin_name      AS from_name,
                jl.destination_name AS to_name,
                COUNT(*)            AS pair_count,
                sa.lat              AS from_lat,
                sa.lng              AS from_lng,
                sb.lat              AS to_lat,
                sb.lng              AS to_lng
            FROM journey_logs jl
            JOIN (
                SELECT name,
                       ST_Y(location::geometry) AS lat,
                       ST_X(location::geometry) AS lng
                FROM stops
            ) sa ON LOWER(sa.name) = LOWER(jl.origin_name)
            JOIN (
                SELECT name,
                       ST_Y(location::geometry) AS lat,
                       ST_X(location::geometry) AS lng
                FROM stops
            ) sb ON LOWER(sb.name) = LOWER(jl.destination_name)
            WHERE jl.origin_name IS NOT NULL
              AND jl.destination_name IS NOT NULL
              AND jl.origin_name <> jl.destination_name
            GROUP BY jl.origin_name, jl.destination_name, sa.lat, sa.lng, sb.lat, sb.lng
            ORDER BY pair_count DESC
            LIMIT ?
        ", [$limit]);

        return array_map(fn ($r) => [
            'from_name' => $r->from_name,
            'to_name'   => $r->to_name,
            'from_lat'  => (float) $r->from_lat,
            'from_lng'  => (float) $r->from_lng,
            'to_lat'    => (float) $r->to_lat,
            'to_lng'    => (float) $r->to_lng,
            'count'     => (int) $r->pair_count,
        ], $rows);
    }
}
