<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsoleDriverController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Driver::with(['vehicle:id,plate,agency_id']);

        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }
        if ($request->filled('vehicle_id')) {
            $q->where('vehicle_id', $request->vehicle_id);
        }
        if ($request->filled('search')) {
            $q->where(function ($sq) use ($request) {
                $sq->where('name', 'ilike', '%'.$request->search.'%')
                   ->orWhere('phone', 'ilike', '%'.$request->search.'%')
                   ->orWhere('license_no', 'ilike', '%'.$request->search.'%');
            });
        }

        return response()->json($q->orderBy('name')->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'       => 'required|string|max:255',
            'phone'      => 'nullable|string|max:30',
            'license_no' => 'nullable|string|max:50',
            'vehicle_id' => 'nullable|integer|exists:vehicles,id',
            'status'     => 'in:active,inactive',
            'notes'      => 'nullable|string',
        ]);

        $driver = Driver::create($data);
        return response()->json($driver->load('vehicle:id,plate'), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $driver = Driver::findOrFail($id);

        $data = $request->validate([
            'name'       => 'sometimes|string|max:255',
            'phone'      => 'nullable|string|max:30',
            'license_no' => 'nullable|string|max:50',
            'vehicle_id' => 'nullable|integer|exists:vehicles,id',
            'status'     => 'in:active,inactive',
            'notes'      => 'nullable|string',
        ]);

        $driver->update($data);
        return response()->json($driver->load('vehicle:id,plate'));
    }

    public function destroy(int $id): JsonResponse
    {
        Driver::findOrFail($id)->delete();
        return response()->json(null, 204);
    }
}
