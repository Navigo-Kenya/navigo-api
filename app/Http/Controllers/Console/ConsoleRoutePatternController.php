<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsoleRoutePatternController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = RoutePattern::withCount('trips')
            ->with('patternStops.stop:id,name');

        if ($request->filled('route_id')) {
            $q->where('route_id', $request->input('route_id'));
        }

        return response()->json($q->orderBy('route_id')->orderBy('direction_id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id'           => 'required|string|max:100|unique:route_patterns,id',
            'route_id'     => 'required|string|exists:routes,route_id',
            'name'         => 'required|string|max:200',
            'direction_id' => 'required|integer|in:0,1',
            'is_canonical' => 'boolean',
        ]);

        return response()->json(RoutePattern::create($data), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $pattern = RoutePattern::findOrFail($id);

        $data = $request->validate([
            'name'         => 'sometimes|string|max:200',
            'direction_id' => 'sometimes|integer|in:0,1',
            'is_canonical' => 'sometimes|boolean',
        ]);

        $pattern->update($data);

        return response()->json($pattern);
    }

    public function destroy(string $id): JsonResponse
    {
        $pattern = RoutePattern::withCount('trips')->findOrFail($id);

        if ($pattern->trips_count > 0) {
            return response()->json(['message' => 'Cannot delete a pattern that has associated trips.'], 422);
        }

        $pattern->delete();

        return response()->json(['message' => 'Pattern deleted.']);
    }

    public function saveStops(Request $request, string $id): JsonResponse
    {
        $pattern = RoutePattern::findOrFail($id);

        $data = $request->validate([
            'stops'                      => 'required|array',
            'stops.*.stop_id'            => 'required|string|exists:stops,id',
            'stops.*.timepoint'          => 'boolean',
            'stops.*.pickup_type'        => 'integer|in:0,1,2,3',
            'stops.*.drop_off_type'      => 'integer|in:0,1,2,3',
            'stops.*.distance_traveled'  => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($data, $pattern) {
            RoutePatternStop::where('route_pattern_id', $pattern->id)->delete();

            if (!empty($data['stops'])) {
                $rows = array_map(fn ($stop, $seq) => [
                    'route_pattern_id'  => $pattern->id,
                    'stop_id'           => $stop['stop_id'],
                    'stop_sequence'     => $seq + 1,
                    'timepoint'         => $stop['timepoint'] ?? true,
                    'pickup_type'       => $stop['pickup_type'] ?? 0,
                    'drop_off_type'     => $stop['drop_off_type'] ?? 0,
                    'distance_traveled' => $stop['distance_traveled'] ?? null,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ], $data['stops'], array_keys($data['stops']));

                RoutePatternStop::insert($rows);
            }
        });

        return response()->json(['message' => 'Pattern stops saved.']);
    }
}
