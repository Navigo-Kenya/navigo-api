<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Corridor;
use App\Models\CorridorRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsoleCorridorController extends Controller
{
    public function index(): JsonResponse
    {
        $corridors = Corridor::with('agency')
            ->withCount('corridorRoutes')
            ->orderBy('name')
            ->get();

        return response()->json($corridors);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => 'required|string|max:200',
            'agency_id' => 'nullable|string|exists:agencies,agency_id',
        ]);

        $data['corridor_id'] = $data['corridor_id'] ?? Str::uuid();

        $corridor = Corridor::create($data);

        return response()->json($corridor, 201);
    }

    public function show(string $id): JsonResponse
    {
        $corridor = Corridor::with(['agency', 'routes'])->findOrFail($id);
        return response()->json($corridor);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $corridor = Corridor::findOrFail($id);

        $data = $request->validate([
            'name'      => 'sometimes|string|max:200',
            'agency_id' => 'nullable|string|exists:agencies,agency_id',
        ]);

        $corridor->update($data);

        return response()->json($corridor);
    }

    public function destroy(string $id): JsonResponse
    {
        Corridor::findOrFail($id)->delete();
        return response()->json(['message' => 'Corridor deleted.']);
    }

    // Save the geometric path of a corridor
    public function saveShape(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'points'   => 'required|array|min:2',
            'points.*' => 'array|size:2',
        ]);

        $corridor = Corridor::findOrFail($id);

        $wkt = $this->pointsToLineStringWkt($data['points']);
        DB::statement(
            "UPDATE corridors SET path = ST_GeomFromText(?, 4326) WHERE corridor_id = ?",
            [$wkt, $corridor->corridor_id]
        );

        // Refresh and return with updated points
        return response()->json(Corridor::find($id));
    }

    // Attach a route to this corridor
    public function attachRoute(Request $request, string $id): JsonResponse
    {
        $corridor = Corridor::findOrFail($id);

        $data = $request->validate([
            'route_id'     => 'required|string|exists:routes,route_id',
            'direction_id' => 'nullable|integer|in:0,1',
        ]);

        CorridorRoute::firstOrCreate([
            'corridor_id'  => $corridor->corridor_id,
            'route_id'     => $data['route_id'],
            'direction_id' => $data['direction_id'] ?? null,
        ]);

        return response()->json(['message' => 'Route attached.']);
    }

    // Detach a route from this corridor
    public function detachRoute(string $id, string $routeId): JsonResponse
    {
        CorridorRoute::where('corridor_id', $id)
            ->where('route_id', $routeId)
            ->delete();

        return response()->json(['message' => 'Route detached.']);
    }

    // Routes whose shapes pass within 200m of the corridor path
    public function routesNearCorridor(string $id): JsonResponse
    {
        $corridor = Corridor::findOrFail($id);

        $rows = DB::select("
            SELECT DISTINCT r.route_id, r.route_short_name, r.route_long_name, r.route_color
            FROM routes r
            JOIN trips t ON t.route_id = r.route_id
            JOIN shapes s ON s.shape_id = t.shape_id
            WHERE s.path IS NOT NULL
              AND ST_DWithin(
                s.path::geography,
                (SELECT path::geography FROM corridors WHERE corridor_id = ?),
                200
              )
            ORDER BY r.route_short_name
        ", [$id]);

        return response()->json($rows);
    }

    private function pointsToLineStringWkt(array $points): string
    {
        $coords = array_map(fn ($p) => "{$p[0]} {$p[1]}", $points);
        return 'LINESTRING(' . implode(', ', $coords) . ')';
    }
}
