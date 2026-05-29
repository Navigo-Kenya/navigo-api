<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsoleVehicleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Vehicle::with(['agency:agency_id,agency_name', 'route:route_id,route_short_name', 'drivers:id,name,vehicle_id']);

        if ($request->filled('agency_id')) {
            $q->where('agency_id', $request->agency_id);
        }
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $q->where('plate', 'ilike', '%'.$request->search.'%');
        }

        return response()->json($q->orderBy('plate')->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plate'      => 'required|string|max:20|unique:vehicles,plate',
            'agency_id'  => 'nullable|string|exists:agencies,agency_id',
            'route_id'   => 'nullable|string|exists:routes,route_id',
            'model'      => 'nullable|string|max:100',
            'capacity'   => 'nullable|integer|min:1|max:200',
            'status'     => 'in:active,inactive,suspended',
            'notes'      => 'nullable|string',
        ]);

        $vehicle = Vehicle::create($data);
        return response()->json($vehicle->load(['agency:agency_id,agency_name', 'route:route_id,route_short_name']), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);

        $data = $request->validate([
            'plate'     => 'sometimes|string|max:20|unique:vehicles,plate,'.$id,
            'agency_id' => 'nullable|string|exists:agencies,agency_id',
            'route_id'  => 'nullable|string|exists:routes,route_id',
            'model'     => 'nullable|string|max:100',
            'capacity'  => 'nullable|integer|min:1|max:200',
            'status'    => 'in:active,inactive,suspended',
            'notes'     => 'nullable|string',
        ]);

        $vehicle->update($data);
        return response()->json($vehicle->load(['agency:agency_id,agency_name', 'route:route_id,route_short_name']));
    }

    public function destroy(int $id): JsonResponse
    {
        Vehicle::findOrFail($id)->delete();
        return response()->json(null, 204);
    }
}
