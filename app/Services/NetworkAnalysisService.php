<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class NetworkAnalysisService
{
    public function stopCoverageData(): array
    {
        $rows = DB::select("
            SELECT
                s.name,
                ST_Y(s.location::geometry) AS lat,
                ST_X(s.location::geometry) AS lng,
                COUNT(DISTINCT st.trip_id) AS trip_count
            FROM stops s
            LEFT JOIN stop_times st ON st.stop_id = s.id
            GROUP BY s.id, s.name, s.location
            ORDER BY trip_count DESC
        ");

        return array_map(fn ($r) => [
            'name'       => $r->name,
            'lat'        => (float) $r->lat,
            'lng'        => (float) $r->lng,
            'trip_count' => (int) $r->trip_count,
        ], $rows);
    }

    public function transferGraph(int $radiusMeters = 400): array
    {
        // All stops with coordinates
        $stops = DB::select("
            SELECT id, name,
                   ST_Y(location::geometry) AS lat,
                   ST_X(location::geometry) AS lng
            FROM stops
        ");

        $nodes = array_map(fn ($s) => [
            'id'   => $s->id,
            'name' => $s->name,
            'lat'  => (float) $s->lat,
            'lng'  => (float) $s->lng,
        ], $stops);

        // Pairs within radius using PostGIS
        $pairs = DB::select("
            SELECT
                a.id AS from_id,
                b.id AS to_id,
                ROUND(ST_DistanceSphere(a.location::geometry, b.location::geometry)::numeric, 1) AS distance_m
            FROM stops a
            JOIN stops b ON b.id > a.id
            WHERE ST_DWithin(
                a.location::geography,
                b.location::geography,
                ?
            )
        ", [$radiusMeters]);

        $edges = array_map(fn ($p) => [
            'from_id'    => $p->from_id,
            'to_id'      => $p->to_id,
            'distance_m' => (float) $p->distance_m,
        ], $pairs);

        // Build adjacency list for BFS
        $adjacency = [];
        foreach ($nodes as $node) {
            $adjacency[$node['id']] = [];
        }
        foreach ($pairs as $p) {
            $adjacency[$p->from_id][] = $p->to_id;
            $adjacency[$p->to_id][]   = $p->from_id;
        }

        // Find largest connected component via BFS
        $visited   = [];
        $components = [];
        foreach (array_keys($adjacency) as $startId) {
            if (isset($visited[$startId])) {
                continue;
            }
            $component = $this->bfsReachable($adjacency, $startId, $visited);
            foreach ($component as $id) {
                $visited[$id] = true;
            }
            $components[] = $component;
        }

        $stopCount       = count($nodes);
        $largestSize     = $stopCount > 0 ? max(array_map('count', $components)) : 0;
        $isolatedCount   = count(array_filter($components, fn ($c) => count($c) === 1));
        $score           = $stopCount > 0 ? round(($largestSize / $stopCount) * 100, 1) : 0;

        return [
            'nodes'                   => $nodes,
            'edges'                   => $edges,
            'connectivity_score'      => $score,
            'largest_component_size'  => $largestSize,
            'isolated_stops'          => $isolatedCount,
        ];
    }

    private function bfsReachable(array $adjacency, string $startId, array $visited): array
    {
        $queue     = [$startId];
        $component = [$startId];
        $seen      = [$startId => true];

        while (!empty($queue)) {
            $current = array_shift($queue);
            foreach ($adjacency[$current] ?? [] as $neighbor) {
                if (!isset($seen[$neighbor]) && !isset($visited[$neighbor])) {
                    $seen[$neighbor] = true;
                    $component[]     = $neighbor;
                    $queue[]         = $neighbor;
                }
            }
        }

        return $component;
    }
}
