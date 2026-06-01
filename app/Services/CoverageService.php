<?php

namespace App\Services;

use App\Models\Corridor;
use Illuminate\Support\Facades\DB;

class CoverageService
{
    private const CORRIDOR_COLORS = [
        '#FF6F00', '#F97316', '#FB923C', '#FDBA74', '#F97316', '#FF8C30',
    ];

    private const FALLBACK_CORRIDORS = [
        [
            'id' => 'cbd', 'name' => 'CBD', 'color' => '#FF6F00',
            'lat_min' => -1.310, 'lat_max' => -1.265, 'lng_min' => 36.795, 'lng_max' => 36.850,
        ],
        [
            'id' => 'thika-road', 'name' => 'Thika Road', 'color' => '#F97316',
            'lat_min' => -1.265, 'lat_max' => -1.100, 'lng_min' => 36.820, 'lng_max' => 36.980,
        ],
        [
            'id' => 'mombasa-road', 'name' => 'Mombasa Road', 'color' => '#FB923C',
            'lat_min' => -1.400, 'lat_max' => -1.260, 'lng_min' => 36.820, 'lng_max' => 37.050,
        ],
        [
            'id' => 'ngong-road', 'name' => 'Ngong Road', 'color' => '#FDBA74',
            'lat_min' => -1.340, 'lat_max' => -1.275, 'lng_min' => 36.700, 'lng_max' => 36.800,
        ],
        [
            'id' => 'westlands', 'name' => 'Westlands', 'color' => '#F97316',
            'lat_min' => -1.275, 'lat_max' => -1.220, 'lng_min' => 36.780, 'lng_max' => 36.845,
        ],
        [
            'id' => 'karen-langata', 'name' => "Karen / Lang'ata", 'color' => '#FF8C30',
            'lat_min' => -1.400, 'lat_max' => -1.295, 'lng_min' => 36.695, 'lng_max' => 36.795,
        ],
    ];

    public function getCoverageData(): array
    {
        return [
            'corridors'           => $this->buildCorridors(),
            'graph'               => $this->getGraphData(),
            'total_routes'        => DB::table('routes')->count(),
            'total_stops'         => DB::table('stops')->count(),
            'total_trips'         => DB::table('trips')->count(),
            'total_contributions' => DB::table('contributions')->count(),
        ];
    }

    // ── Graph: nodes (stops) + edges (route shapes) ───────────────
    // Mirrors ConsoleNetworkController::graph but public + cached.
    private function getGraphData(): array
    {
        // Use denormalized columns to avoid the expensive stop_times join
        $nodes = DB::select("
            SELECT
                id,
                name,
                ST_Y(location::geometry)                                         AS lat,
                ST_X(location::geometry)                                         AS lng,
                trip_count,
                COALESCE(array_length(string_to_array(NULLIF(route_ids,''), ','), 1), 0) AS route_count
            FROM stops
            WHERE trip_count > 0
        ");

        $edges = DB::select("
            SELECT
                r.route_id,
                r.route_short_name,
                r.route_color,
                s.shape_id,
                ST_AsGeoJSON(s.path) AS geojson
            FROM routes r
            JOIN trips  t ON t.route_id  = r.route_id
            JOIN shapes s ON s.shape_id  = t.shape_id
            WHERE s.path IS NOT NULL
            GROUP BY r.route_id, r.route_short_name, r.route_color, s.shape_id, s.path
        ");

        return [
            'nodes' => array_map(fn (object $n) => [
                'id'          => $n->id,
                'name'        => $n->name,
                'lat'         => (float) $n->lat,
                'lng'         => (float) $n->lng,
                'trip_count'  => (int) $n->trip_count,
                'route_count' => (int) $n->route_count,
            ], $nodes),

            'edges' => array_map(function (object $e) {
                $geo = json_decode($e->geojson, true);
                return [
                    'id'               => $e->shape_id,
                    'route_id'         => $e->route_id,
                    'route_short_name' => $e->route_short_name,
                    'route_color'      => $e->route_color,
                    'points'           => $geo['coordinates'] ?? [],
                ];
            }, $edges),
        ];
    }

    // ── Corridor stats ────────────────────────────────────────────
    private function buildCorridors(): array
    {
        $dbCorridors = Corridor::withCount('corridorRoutes')->get();

        if ($dbCorridors->isNotEmpty()) {
            return $dbCorridors->values()->map(function (Corridor $c, int $i) {
                return [
                    'id'          => $c->corridor_id,
                    'name'        => $c->name,
                    'route_count' => $c->corridor_routes_count,
                    'stop_count'  => $this->stopCountNearCorridor($c->corridor_id),
                    'color'       => self::CORRIDOR_COLORS[$i % count(self::CORRIDOR_COLORS)],
                ];
            })->all();
        }

        return array_map(fn (array $def) => [
            'id'          => $def['id'],
            'name'        => $def['name'],
            'route_count' => $this->routeCountInBbox($def),
            'stop_count'  => $this->stopCountInBbox($def),
            'color'       => $def['color'],
        ], self::FALLBACK_CORRIDORS);
    }

    private function stopCountNearCorridor(string $corridorId): int
    {
        $row = DB::selectOne("
            SELECT COUNT(DISTINCT s.id) AS cnt
            FROM stops s, corridors c
            WHERE c.corridor_id = ?
              AND c.path IS NOT NULL
              AND ST_DWithin(s.location::geography, c.path::geography, 600)
        ", [$corridorId]);

        return $row ? (int) $row->cnt : 0;
    }

    private function routeCountInBbox(array $def): int
    {
        $row = DB::selectOne("
            SELECT COUNT(DISTINCT u.route_id) AS cnt
            FROM stops s
            CROSS JOIN LATERAL unnest(string_to_array(NULLIF(s.route_ids,''), ',')) AS u(route_id)
            WHERE s.route_ids IS NOT NULL
              AND ST_Within(
                  s.location::geometry,
                  ST_MakeEnvelope(:lng_min, :lat_min, :lng_max, :lat_max, 4326)
              )
        ", [
            'lng_min' => $def['lng_min'], 'lat_min' => $def['lat_min'],
            'lng_max' => $def['lng_max'], 'lat_max' => $def['lat_max'],
        ]);

        return $row ? (int) $row->cnt : 0;
    }

    private function stopCountInBbox(array $def): int
    {
        $row = DB::selectOne("
            SELECT COUNT(*) AS cnt
            FROM stops
            WHERE ST_Within(
                location::geometry,
                ST_MakeEnvelope(:lng_min, :lat_min, :lng_max, :lat_max, 4326)
            )
        ", [
            'lng_min' => $def['lng_min'], 'lat_min' => $def['lat_min'],
            'lng_max' => $def['lng_max'], 'lat_max' => $def['lat_max'],
        ]);

        return $row ? (int) $row->cnt : 0;
    }
}
