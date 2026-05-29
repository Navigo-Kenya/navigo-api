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
use Illuminate\Support\Facades\DB;

class ConsoleNetworkController extends Controller
{
    public function __construct(
        private NetworkAnalysisService $analysis,
        private IsochroneService $isochrone,
        private DesireLineService $desireLines,
    ) {}

    // F1: Nodes (stops) + edges (route shapes)
    public function graph(): JsonResponse
    {
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
            WHERE s.path IS NOT NULL
            GROUP BY r.route_id, r.route_short_name, r.route_color, s.shape_id, s.path
        ");

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
}
