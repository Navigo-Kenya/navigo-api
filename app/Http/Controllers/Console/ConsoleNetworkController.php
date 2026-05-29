<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\NetworkSnapshot;
use App\Services\DesireLineService;
use App\Services\IsochroneService;
use App\Services\NetworkAnalysisService;
use App\Services\NetworkSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ConsoleNetworkController extends Controller
{
    public function __construct(
        private NetworkAnalysisService $analysis,
        private IsochroneService $isochrone,
        private DesireLineService $desireLines,
    ) {}

    // F1: Nodes (stops) + edges (route shapes); optional ?agency_id= filter
    public function graph(Request $request): JsonResponse
    {
        $agencyId = $request->query('agency_id');

        $nodes = DB::select("
            SELECT
                s.id,
                s.name,
                ST_Y(s.location::geometry) AS lat,
                ST_X(s.location::geometry) AS lng,
                COUNT(DISTINCT st.trip_id)              AS trip_count,
                COUNT(DISTINCT t.route_id)              AS route_count
            FROM stops s
            LEFT JOIN stop_times st ON st.stop_id = s.id
            LEFT JOIN trips t ON t.trip_id = st.trip_id
            GROUP BY s.id, s.name, s.location
        ");

        $agencyWhere = $agencyId ? "AND r.agency_id = ?" : '';
        $bindings    = $agencyId ? [$agencyId] : [];

        $edges = DB::select("
            SELECT
                r.route_id,
                r.route_short_name,
                r.route_color,
                s.shape_id,
                ST_AsGeoJSON(s.path) AS geojson
            FROM routes r
            JOIN trips t  ON t.route_id = r.route_id
            JOIN shapes s ON s.shape_id = t.shape_id
            WHERE s.path IS NOT NULL {$agencyWhere}
            GROUP BY r.route_id, r.route_short_name, r.route_color, s.shape_id, s.path
        ", $bindings);

        $nodesOut = array_map(fn ($n) => [
            'id'          => $n->id,
            'name'        => $n->name,
            'lat'         => (float) $n->lat,
            'lng'         => (float) $n->lng,
            'trip_count'  => (int) $n->trip_count,
            'route_count' => (int) $n->route_count,
        ], $nodes);

        $edgesOut = array_map(function ($e) {
            $geo    = json_decode($e->geojson, true);
            $points = $geo['coordinates'] ?? [];
            return [
                'id'               => $e->shape_id,
                'route_id'         => $e->route_id,
                'route_short_name' => $e->route_short_name,
                'route_color'      => $e->route_color,
                'points'           => $points,
            ];
        }, $edges);

        return response()->json(['nodes' => $nodesOut, 'edges' => $edgesOut]);
    }

    // F4: All stop coordinates for heatmap
    public function coverage(): JsonResponse
    {
        return response()->json($this->analysis->stopCoverageData());
    }

    // F3+F5: Walkshed / Reachability isochrone
    public function isochrone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat'     => 'required|numeric',
            'lng'     => 'required|numeric',
            'mode'    => 'required|in:walk,transit',
            'date'    => 'nullable|date_format:Y-m-d',
            'time'    => 'nullable|string',
            'cutoffs' => 'nullable|array',
            'cutoffs.*' => 'integer|min:60|max:7200',
        ]);

        $cutoffs = $data['cutoffs'] ?? ($data['mode'] === 'walk' ? [300, 600, 900] : [900, 1800, 2700, 3600]);

        $result = $data['mode'] === 'walk'
            ? $this->isochrone->walkShed($data['lat'], $data['lng'], $cutoffs)
            : $this->isochrone->reachabilityMap($data['lat'], $data['lng'], $data['date'] ?? now()->toDateString(), $data['time'] ?? '12:00:00', $cutoffs);

        return response()->json($result);
    }

    // F6: OD desire lines
    public function desireLines(): JsonResponse
    {
        return response()->json($this->desireLines->desireLines());
    }

    // F9: Transfer graph
    public function transferGraph(Request $request): JsonResponse
    {
        $radius = (int) $request->query('radius', 400);
        $radius = max(50, min(2000, $radius));

        return response()->json($this->analysis->transferGraph($radius));
    }

    // F7: List snapshots
    public function snapshots(Request $request): JsonResponse
    {
        $query = NetworkSnapshot::query()->orderByDesc('created_at');

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->entity_type);
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }

        $perPage = min((int) $request->query('per_page', 25), 100);
        return response()->json($query->paginate($perPage));
    }

    // F7: Manual snapshot with label
    public function createSnapshot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_type' => 'required|in:route,stop,trip,shape,corridor',
            'entity_id'   => 'required|string',
            'action'      => 'required|in:created,updated,deleted,manual',
            'label'       => 'nullable|string|max:255',
        ]);

        NetworkSnapshotService::record(
            $data['entity_type'],
            $data['entity_id'],
            $data['action'],
            array_merge($data, ['label' => $data['label'] ?? null])
        );

        return response()->json(['message' => 'Snapshot recorded.'], 201);
    }

    // F7: Single snapshot
    public function showSnapshot(int $id): JsonResponse
    {
        return response()->json(NetworkSnapshot::findOrFail($id));
    }

    // F29: All modal layers (GTFS route_type groups + cached OSM GeoJSON)
    public function modalLayers(): JsonResponse
    {
        $modeGroups = [
            'bus'        => ['types' => [3, 11, 700, 701, 702, 703], 'label' => 'Matatu / Bus',     'color' => 'FF6F00'],
            'brt'        => ['types' => [800],                        'label' => 'BRT',               'color' => '2563EB'],
            'rail'       => ['types' => [1, 2],                       'label' => 'Rail / Metro',      'color' => '16A34A'],
            'tram'       => ['types' => [0],                          'label' => 'Tram',              'color' => 'F59E0B'],
            'ferry'      => ['types' => [4],                          'label' => 'Ferry',             'color' => '0891B2'],
        ];

        $layers = [];

        foreach ($modeGroups as $mode => $cfg) {
            $cacheKey = "network:modal:{$mode}";
            $layers[$mode] = Cache::remember($cacheKey, 3600, function () use ($cfg, $mode) {
                $placeholders = implode(',', array_fill(0, count($cfg['types']), '?'));
                $rows = DB::select("
                    SELECT
                        r.route_id, r.route_short_name,
                        COALESCE(r.route_color, ?) as route_color,
                        ST_AsGeoJSON(s.path) as geojson
                    FROM routes r
                    JOIN trips t  ON t.route_id = r.route_id
                    JOIN shapes s ON s.shape_id = t.shape_id
                    WHERE r.route_type IN ({$placeholders})
                      AND s.path IS NOT NULL
                    GROUP BY r.route_id, r.route_short_name, r.route_color, s.path
                ", array_merge([$cfg['color']], $cfg['types']));

                $features = array_map(function ($row) {
                    $geo = json_decode($row->geojson, true);
                    return [
                        'type'       => 'Feature',
                        'geometry'   => $geo,
                        'properties' => [
                            'route_id'   => $row->route_id,
                            'route_name' => $row->route_short_name,
                            'color'      => '#' . $row->route_color,
                        ],
                    ];
                }, $rows);

                return [
                    'label'    => $cfg['label'],
                    'color'    => $cfg['color'],
                    'count'    => count($features),
                    'features' => $features,
                    'osm'      => false,
                ];
            });
        }

        // OSM layers — served from pre-stored GeoJSON files
        foreach (['cycling', 'pedestrian'] as $osmMode) {
            $osmLabels = ['cycling' => 'Cycling paths', 'pedestrian' => 'Pedestrian areas'];
            $osmColors = ['cycling' => '84CC16',        'pedestrian' => 'A855F7'];

            $filePath    = storage_path("app/osm/{$osmMode}.geojson");
            $geojsonData = file_exists($filePath) ? json_decode(file_get_contents($filePath), true) : null;
            $features    = $geojsonData['features'] ?? [];

            $layers[$osmMode] = [
                'label'    => $osmLabels[$osmMode],
                'color'    => $osmColors[$osmMode],
                'count'    => count($features),
                'features' => $features,
                'osm'      => true,
            ];
        }

        return response()->json([
            'layers'           => $layers,
            'osm_refreshed_at' => $this->osmRefreshedAt(),
        ]);
    }

    // F29: Refresh an OSM layer from Overpass API (admin only)
    public function refreshOsmLayer(Request $request): JsonResponse
    {
        $data  = $request->validate(['layer' => 'required|in:cycling,pedestrian']);
        $layer = $data['layer'];

        $bbox = '-1.5,36.6,-1.0,37.1'; // Nairobi bounding box
        $queries = [
            'cycling'    => "[out:json];way[\"highway\"=\"cycleway\"]({$bbox});out geom;",
            'pedestrian' => "[out:json];way[\"highway\"=\"pedestrian\"]({$bbox});out geom;",
        ];

        $response = Http::timeout(30)->get('https://overpass-api.de/api/interpreter', ['data' => $queries[$layer]]);

        if ($response->failed()) {
            return response()->json(['error' => 'Overpass API request failed.'], 502);
        }

        $osmData  = $response->json();
        $features = [];

        foreach ($osmData['elements'] ?? [] as $element) {
            if ($element['type'] !== 'way' || empty($element['geometry'])) continue;
            $coords = array_map(fn ($pt) => [$pt['lon'], $pt['lat']], $element['geometry']);
            $features[] = [
                'type'       => 'Feature',
                'geometry'   => ['type' => 'LineString', 'coordinates' => $coords],
                'properties' => ['osm_id' => $element['id']],
            ];
        }

        $geojson = ['type' => 'FeatureCollection', 'features' => $features];
        Storage::disk('local')->put("osm/{$layer}.geojson", json_encode($geojson));
        Cache::forget("network:modal:{$layer}");

        return response()->json(['message' => "OSM layer '{$layer}' refreshed.", 'count' => count($features)]);
    }

    // F31: Agency stats
    public function agencies(): JsonResponse
    {
        $rows = DB::select("
            SELECT
                a.agency_id,
                a.agency_name,
                COUNT(DISTINCT r.route_id)  AS route_count,
                COUNT(DISTINCT st.stop_id)  AS stop_count,
                COUNT(DISTINCT t.trip_id)   AS trip_count
            FROM agencies a
            LEFT JOIN routes r     ON r.agency_id  = a.agency_id
            LEFT JOIN trips t      ON t.route_id   = r.route_id
            LEFT JOIN stop_times st ON st.trip_id  = t.trip_id
            GROUP BY a.agency_id, a.agency_name
            ORDER BY a.agency_name
        ");

        return response()->json(array_map(fn ($r) => [
            'agency_id'   => $r->agency_id,
            'agency_name' => $r->agency_name,
            'route_count' => (int) $r->route_count,
            'stop_count'  => (int) $r->stop_count,
            'trip_count'  => (int) $r->trip_count,
        ], $rows));
    }

    // F31: Stops served by ≥2 agencies + transfer quality score
    public function crossAgencyTransfers(): JsonResponse
    {
        return response()->json(Cache::remember('network:cross_agency_transfers', 3600, function () {
            $multiStops = DB::select("
                SELECT
                    st.stop_id,
                    COUNT(DISTINCT r.agency_id)            AS agency_count,
                    array_agg(DISTINCT r.agency_id)        AS agencies_arr
                FROM stop_times st
                JOIN trips  t ON st.trip_id  = t.trip_id
                JOIN routes r ON t.route_id  = r.route_id
                GROUP BY st.stop_id
                HAVING COUNT(DISTINCT r.agency_id) >= 2
            ");

            $result = [];

            foreach ($multiStops as $ms) {
                $stop = DB::selectOne(
                    "SELECT id, name, ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng
                     FROM stops WHERE id = ?",
                    [$ms->stop_id]
                );
                if (!$stop) continue;

                // Get departure time windows per agency at this stop
                $departures = DB::select("
                    SELECT r.agency_id, st.departure_time
                    FROM stop_times st
                    JOIN trips  t ON st.trip_id = t.trip_id
                    JOIN routes r ON t.route_id = r.route_id
                    WHERE st.stop_id = ?
                    ORDER BY r.agency_id, st.departure_time
                ", [$ms->stop_id]);

                $byAgency = [];
                foreach ($departures as $dep) {
                    $byAgency[$dep->agency_id][] = $dep->departure_time;
                }

                $agencies = array_keys($byAgency);
                $minGaps  = [];

                for ($i = 0; $i < count($agencies); $i++) {
                    for ($j = $i + 1; $j < count($agencies); $j++) {
                        $timesA = $byAgency[$agencies[$i]];
                        $timesB = $byAgency[$agencies[$j]];
                        $minGap = PHP_INT_MAX;
                        foreach ($timesA as $ta) {
                            foreach ($timesB as $tb) {
                                $secA = $this->timeToSeconds($ta);
                                $secB = $this->timeToSeconds($tb);
                                $gap  = abs($secA - $secB);
                                if ($gap < $minGap) $minGap = $gap;
                            }
                        }
                        $minGaps[] = $minGap / 60; // convert to minutes
                    }
                }

                $avgMinGap = count($minGaps) > 0 ? array_sum($minGaps) / count($minGaps) : 60;
                $score     = max(0, min(100, (int) round(100 - $avgMinGap * 5)));

                // Parse PostgreSQL array string "{agency1,agency2}" → PHP array
                $agenciesArr = str_getcsv(trim((string) $ms->agencies_arr, '{}'));

                $result[] = [
                    'stop_id'                => $stop->id,
                    'stop_name'              => $stop->name,
                    'lat'                    => (float) $stop->lat,
                    'lng'                    => (float) $stop->lng,
                    'agencies'               => $agenciesArr,
                    'transfer_quality_score' => $score,
                    'min_transfer_gap_min'   => round($avgMinGap, 1),
                ];
            }

            return $result;
        }));
    }

    private function timeToSeconds(string $time): int
    {
        [$h, $m, $s] = explode(':', $time);
        return ((int) $h) * 3600 + ((int) $m) * 60 + ((int) $s);
    }

    private function osmRefreshedAt(): ?string
    {
        $path = storage_path('app/osm/cycling.geojson');
        return file_exists($path) ? date('Y-m-d H:i:s', filemtime($path)) : null;
    }
}
