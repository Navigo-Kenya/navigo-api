<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Route;
use App\Models\Shape;
use App\Models\Stop;
use App\Models\StopTime;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsoleRouteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $scope = $this->agencyScope($request);

        $q = Route::withCount('trips');

        if ($scope !== null) {
            // Operator-scoped users only see routes they have claimed via route_operators.
            $q->whereIn('route_id', function ($sub) use ($scope) {
                $sub->select('route_id')
                    ->from('route_operators')
                    ->whereIn('agency_id', $scope);
            });
        } else {
            // Global staff: standard agency_id filter.
            if ($request->filled('agency_id')) {
                $q->where('agency_id', $request->input('agency_id'));
            }
        }

        if ($search = $request->input('search')) {
            $q->where(function ($sub) use ($search) {
                $sub->where('route_short_name', 'ilike', "%{$search}%")
                    ->orWhere('route_long_name', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('route_type')) {
            $q->where('route_type', (int) $request->input('route_type'));
        }

        $sortable = ['route_short_name', 'route_long_name', 'trips_count'];
        $sort     = in_array($request->input('sort'), $sortable) ? $request->input('sort') : 'route_short_name';
        $order    = $request->input('order', 'asc') === 'desc' ? 'desc' : 'asc';
        $q->orderBy($sort, $order);

        $routes = $q->paginate((int) $request->input('per_page', 30));

        return response()->json($routes);
    }

    public function show(string $id): JsonResponse
    {
        $route = Route::with([
            'trips' => fn ($q) => $q->select('trip_id', 'route_id', 'service_id', 'trip_headsign', 'direction_id', 'shape_id')->limit(100),
            'trips.shape',
            'trips.stopTimes' => fn ($q) => $q->select('id', 'trip_id', 'stop_id', 'stop_sequence', 'arrival_time', 'departure_time')->orderBy('stop_sequence'),
            'trips.stopTimes.stop' => fn ($q) => $q->select('id', 'name')->selectRaw('ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng'),
        ])->findOrFail($id);

        $data = $route->toArray();

        // The editor saves a shape keyed as "{route_id}_shape" via saveShape().
        // Surface that shape first so the editor always reads back what it wrote,
        // even when saveTripStops() was never called (no stops yet) or when
        // GTFS-imported trips carry their own shape_ids that sort before ours.
        $editorShape = \App\Models\Shape::find("{$route->route_id}_shape");
        $tripShapes  = $route->trips->pluck('shape')->filter()->unique('shape_id');

        $data['shapes'] = collect($editorShape ? [$editorShape] : [])
            ->concat($tripShapes)
            ->unique('shape_id')
            ->values()
            ->toArray();

        return response()->json($data);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->user()->isOperator()) {
            return response()->json(['message' => 'Operators cannot create routes.'], 403);
        }

        $data = $request->validate([
            'route_id'         => 'required|string|unique:routes,route_id',
            'agency_id'        => 'required|string|exists:agencies,agency_id',
            'route_short_name' => 'required|string|max:10',
            'route_long_name'  => 'required|string|max:255',
            'route_type'       => 'integer|in:0,1,2,3,4,5,6,7',
            'route_color'      => 'nullable|string|max:6',
        ]);

        $this->assertAgencyAllowed($request, $data['agency_id']);

        $route = Route::create($data);

        return response()->json($route, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if ($request->user()->isOperator()) {
            return response()->json(['message' => 'Operators cannot edit routes.'], 403);
        }

        $route = Route::findOrFail($id);
        $this->assertAgencyAllowed($request, $route->agency_id);

        $data = $request->validate([
            'route_short_name' => 'sometimes|string|max:10',
            'route_long_name'  => 'sometimes|string|max:255',
            'route_type'       => 'integer|in:0,1,2,3,4,5,6,7',
            'route_color'      => 'nullable|string|max:6',
        ]);

        $route->update($data);

        return response()->json($route);
    }

    public function updateStopSequence(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'trip_id'  => 'required|string|exists:trips,trip_id',
            'stops'    => 'required|array',
            'stops.*.stop_id'       => 'required|string|exists:stops,stop_id',
            'stops.*.arrival_time'  => 'required|string',
            'stops.*.departure_time' => 'required|string',
        ]);

        foreach ($data['stops'] as $seq => $stop) {
            StopTime::updateOrCreate(
                ['trip_id' => $data['trip_id'], 'stop_id' => $stop['stop_id']],
                [
                    'stop_sequence'   => $seq + 1,
                    'arrival_time'    => $stop['arrival_time'],
                    'departure_time'  => $stop['departure_time'],
                ]
            );
        }

        return response()->json(['message' => 'Stop sequence updated.']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if ($request->user()->isOperator()) {
            return response()->json(['message' => 'Operators cannot delete routes.'], 403);
        }

        $route = Route::findOrFail($id);
        $this->assertAgencyAllowed($request, $route->agency_id);

        $route->delete();

        return response()->json(['message' => 'Route deleted.']);
    }

    // Find stops ordered by their position along a drawn polyline
    public function stopsNearLine(Request $request): JsonResponse
    {
        $data = $request->validate([
            'points'     => 'required|array|min:2',
            'points.*.0' => 'required|numeric|between:-180,180',
            'points.*.1' => 'required|numeric|between:-90,90',
            'radius'     => 'nullable|integer|min:50|max:2000',
        ]);

        $radius   = $data['radius'] ?? 200;
        $lineParts = implode(',', array_map(fn ($p) => "{$p[0]} {$p[1]}", $data['points']));
        $lineWkt   = "LINESTRING({$lineParts})";

        $stops = Stop::selectRaw(
            "*, ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng, " .
            "ST_LineLocatePoint(ST_GeomFromText(?, 4326), location::geometry) as line_position",
            [$lineWkt]
        )
        ->whereRaw(
            "ST_DWithin(location::geography, ST_GeomFromText(?, 4326)::geography, ?)",
            [$lineWkt, $radius]
        )
        ->orderByRaw("line_position ASC")
        ->limit(100)
        ->get();

        return response()->json($stops);
    }

    // Upsert the PostGIS shape for a route
    public function saveShape(Request $request, string $id): JsonResponse
    {
        if ($request->user()->isOperator()) {
            return response()->json(['message' => 'Operators cannot edit route shapes.'], 403);
        }

        Route::findOrFail($id);

        $data = $request->validate([
            'points'     => 'required|array|min:2',
            'points.*.0' => 'required|numeric|between:-180,180',
            'points.*.1' => 'required|numeric|between:-90,90',
        ]);

        $lineParts = implode(',', array_map(fn ($p) => "{$p[0]} {$p[1]}", $data['points']));
        $lineWkt   = "LINESTRING({$lineParts})";
        $shapeId   = "{$id}_shape";

        \Illuminate\Support\Facades\DB::statement(
            "INSERT INTO shapes (shape_id, path, created_at, updated_at)
             VALUES (?, ST_GeomFromText(?, 4326), NOW(), NOW())
             ON CONFLICT (shape_id) DO UPDATE SET path = EXCLUDED.path, updated_at = NOW()",
            [$shapeId, $lineWkt]
        );

        return response()->json(['shape_id' => $shapeId]);
    }

    // Replace all stop-times for the route's main trip
    public function saveTripStops(Request $request, string $id): JsonResponse
    {
        if ($request->user()->isOperator()) {
            return response()->json(['message' => 'Operators cannot edit route stop sequences.'], 403);
        }

        $route = Route::findOrFail($id);

        $data = $request->validate([
            'stops'                  => 'present|array',   // empty array is valid (shape-only save)
            'stops.*.stop_id'        => 'required|string|exists:stops,id',
            'stops.*.arrival_time'   => ['required', 'string', 'regex:/^\d{1,2}:\d{2}:\d{2}$/'],
            'stops.*.departure_time' => ['required', 'string', 'regex:/^\d{1,2}:\d{2}:\d{2}$/'],
            'shape_id'               => 'nullable|string|exists:shapes,shape_id',
            'headsign'               => 'nullable|string|max:100',
        ]);

        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $route) {
            $tripId = "{$route->route_id}_trip_1";

            Trip::updateOrCreate(
                ['trip_id' => $tripId],
                [
                    'route_id'      => $route->route_id,
                    'service_id'    => 'default',
                    'shape_id'      => $data['shape_id'] ?? null,
                    'trip_headsign' => $data['headsign'] ?? $route->route_short_name,
                    'direction_id'  => 0,
                ]
            );

            StopTime::where('trip_id', $tripId)->delete();

            if (!empty($data['stops'])) {
                $rows = array_map(fn ($stop, $seq) => [
                    'trip_id'        => $tripId,
                    'stop_id'        => $stop['stop_id'],
                    'stop_sequence'  => $seq + 1,
                    'arrival_time'   => $stop['arrival_time'],
                    'departure_time' => $stop['departure_time'],
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ], $data['stops'], array_keys($data['stops']));

                StopTime::insert($rows);
            }
        });

        return response()->json(['message' => 'Trip stops saved.']);
    }
}
