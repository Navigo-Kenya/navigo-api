<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Stop;
use App\Models\StopTime;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsoleTripController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Trip::withCount('stopTimes')
            ->with('route:route_id,route_short_name,route_long_name,route_type');

        if ($search = $request->input('search')) {
            $q->where(function ($sub) use ($search) {
                $sub->where('trip_id', 'ilike', "%{$search}%")
                    ->orWhere('trip_headsign', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('route_id')) {
            $q->where('route_id', $request->input('route_id'));
        }

        $scope = $this->agencyScope($request);

        if ($scope !== null) {
            // Operator-scoped users: only trips whose route is in their operated routes.
            $q->whereIn('route_id', function ($sub) use ($scope) {
                $sub->select('route_id')
                    ->from('route_operators')
                    ->whereIn('agency_id', $scope);
            });
        } else {
            if ($request->filled('agency_id')) {
                $q->whereHas('route', fn ($r) => $r->where('agency_id', $request->input('agency_id')));
            }
        }

        if ($request->filled('service_id')) {
            $q->where('service_id', $request->input('service_id'));
        }

        if ($request->filled('direction_id')) {
            $q->where('direction_id', (int) $request->input('direction_id'));
        }

        $sortable = ['trip_id', 'updated_at', 'stop_times_count'];
        $sort  = in_array($request->input('sort'), $sortable) ? $request->input('sort') : 'updated_at';
        $order = $request->input('order', 'desc') === 'asc' ? 'asc' : 'desc';
        $q->orderBy($sort, $order);

        return response()->json($q->paginate((int) $request->input('per_page', 30)));
    }

    public function show(string $id): JsonResponse
    {
        $trip = Trip::with([
            'route:route_id,route_short_name,route_long_name,route_type',
            'shape',
            'stopTimes' => fn ($q) => $q->with([
                'stop' => fn ($sq) => $sq->selectRaw('id, name, ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng'),
            ])->orderBy('stop_sequence'),
        ])->findOrFail($id);

        return response()->json($trip);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'trip_id'           => 'required|string|unique:trips,trip_id',
            'route_id'          => 'required|string|exists:routes,route_id',
            'service_id'        => 'required|string|exists:service_calendars,service_id',
            'trip_headsign'     => 'nullable|string|max:100',
            'direction_id'      => 'nullable|integer|in:0,1',
            'shape_id'          => 'nullable|string|exists:shapes,shape_id',
            'scheduling_type'   => 'nullable|string|in:scheduled,frequency',
            'route_pattern_id'  => 'nullable|string|exists:route_patterns,id',
        ]);

        $trip = Trip::create($data);

        return response()->json($trip, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $trip = Trip::findOrFail($id);

        $data = $request->validate([
            'service_id'        => 'sometimes|string|exists:service_calendars,service_id',
            'trip_headsign'     => 'nullable|string|max:100',
            'direction_id'      => 'nullable|integer|in:0,1',
            'shape_id'          => 'nullable|string|exists:shapes,shape_id',
            'scheduling_type'   => 'nullable|string|in:scheduled,frequency',
            'route_pattern_id'  => 'nullable|string|exists:route_patterns,id',
            'block_id'          => 'sometimes|nullable|string|max:50',
        ]);

        $trip->update($data);

        return response()->json($trip);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if ($request->user()->isOperator()) {
            return response()->json(['message' => 'Operators cannot delete trips.'], 403);
        }

        $trip = Trip::findOrFail($id);
        StopTime::where('trip_id', $trip->trip_id)->delete();
        $trip->delete();

        return response()->json(['message' => 'Trip deleted.']);
    }

    public function saveShape(Request $request, string $id): JsonResponse
    {
        $trip = Trip::findOrFail($id);

        $data = $request->validate([
            'points'     => 'required|array|min:2',
            'points.*.0' => 'required|numeric|between:-180,180',
            'points.*.1' => 'required|numeric|between:-90,90',
        ]);

        $lineParts = implode(',', array_map(fn ($p) => "{$p[0]} {$p[1]}", $data['points']));
        $lineWkt   = "LINESTRING({$lineParts})";
        $shapeId   = "{$trip->trip_id}_shape";

        DB::statement(
            "INSERT INTO shapes (shape_id, path, created_at, updated_at)
             VALUES (?, ST_GeomFromText(?, 4326), NOW(), NOW())
             ON CONFLICT (shape_id) DO UPDATE SET path = EXCLUDED.path, updated_at = NOW()",
            [$shapeId, $lineWkt]
        );

        $trip->update(['shape_id' => $shapeId]);

        return response()->json(['shape_id' => $shapeId]);
    }

    public function saveStopTimes(Request $request, string $id): JsonResponse
    {
        $trip = Trip::findOrFail($id);

        $data = $request->validate([
            'stops'                  => 'required|array',
            'stops.*.stop_id'        => 'required|string|exists:stops,id',
            'stops.*.arrival_time'   => ['required', 'string', 'regex:/^\d{1,2}:\d{2}:\d{2}$/'],
            'stops.*.departure_time' => ['required', 'string', 'regex:/^\d{1,2}:\d{2}:\d{2}$/'],
        ]);

        DB::transaction(function () use ($data, $trip) {
            StopTime::where('trip_id', $trip->trip_id)->delete();

            if (!empty($data['stops'])) {
                $rows = array_map(fn ($stop, $seq) => [
                    'trip_id'        => $trip->trip_id,
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

        return response()->json(['message' => 'Stop times saved.']);
    }

    public function submitForReview(string $id): JsonResponse
    {
        $trip = Trip::findOrFail($id);
        $trip->update([
            'draft_status'  => 'pending_review',
            'submitted_by'  => auth()->id(),
        ]);
        return response()->json($trip);
    }

    public function approveDraft(Request $request, string $id): JsonResponse
    {
        $trip = Trip::findOrFail($id);
        $trip->update([
            'draft_status' => 'published',
            'reviewed_by'  => auth()->id(),
            'reviewed_at'  => now(),
            'review_notes' => $request->input('notes'),
        ]);
        return response()->json($trip);
    }

    public function rejectDraft(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['notes' => 'required|string|max:1000']);
        $trip = Trip::findOrFail($id);
        $trip->update([
            'draft_status' => 'rejected',
            'reviewed_by'  => auth()->id(),
            'reviewed_at'  => now(),
            'review_notes' => $data['notes'],
        ]);
        return response()->json($trip);
    }

    public function pendingReview(Request $request): JsonResponse
    {
        $q = Trip::with('route:route_id,route_short_name,route_long_name')
            ->withCount('stopTimes')
            ->where('draft_status', 'pending_review');

        if ($request->filled('agency_id')) {
            $q->whereHas('route', fn ($r) => $r->where('agency_id', $request->agency_id));
        }

        return response()->json($q->latest('updated_at')->paginate(30));
    }

    public function propagateShape(string $id): JsonResponse
    {
        $trip = Trip::findOrFail($id);

        if (!$trip->shape_id) {
            return response()->json(['message' => 'This trip has no saved shape.'], 422);
        }

        $row = DB::selectOne(
            "SELECT ST_AsText(path) as wkt FROM shapes WHERE shape_id = ?",
            [$trip->shape_id]
        );

        if (!$row?->wkt) {
            return response()->json(['message' => 'Shape geometry not found.'], 422);
        }

        $shapeIds = DB::table('trips')
            ->where('route_id', $trip->route_id)
            ->where('direction_id', $trip->direction_id)
            ->whereNotNull('shape_id')
            ->pluck('shape_id')
            ->unique()
            ->values()
            ->all();

        $affectedTrips = DB::table('trips')
            ->where('route_id', $trip->route_id)
            ->where('direction_id', $trip->direction_id)
            ->whereNotNull('shape_id')
            ->count();

        foreach ($shapeIds as $shapeId) {
            DB::statement(
                "UPDATE shapes SET path = ST_GeomFromText(?, 4326), updated_at = NOW() WHERE shape_id = ?",
                [$row->wkt, $shapeId]
            );
        }

        return response()->json([
            'updated_shapes' => count($shapeIds),
            'affected_trips' => $affectedTrips,
        ]);
    }

    public function stopsNearLine(Request $request): JsonResponse
    {
        $data = $request->validate([
            'points'     => 'required|array|min:2',
            'points.*.0' => 'required|numeric|between:-180,180',
            'points.*.1' => 'required|numeric|between:-90,90',
            'radius'     => 'nullable|integer|min:50|max:2000',
        ]);

        $radius    = $data['radius'] ?? 200;
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
}
