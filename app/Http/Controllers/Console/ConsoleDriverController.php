<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsoleDriverController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Driver::with(['vehicle:id,plate,agency_id']);

        $scope = $this->agencyScope($request);
        if ($scope !== null) {
            $q->whereHas('vehicle', fn ($v) => $v->whereIn('agency_id', $scope));
        }

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
            'name'                 => 'required|string|max:255',
            'phone'                => 'nullable|string|max:30',
            'license_no'           => 'nullable|string|max:50',
            'vehicle_id'           => 'nullable|integer|exists:vehicles,id',
            'status'               => 'in:active,inactive',
            'notes'                => 'nullable|string',
            'psv_badge_expiry'     => 'nullable|date',
            'licence_expiry'       => 'nullable|date',
            'good_conduct_expiry'  => 'nullable|date',
            'medical_cert_expiry'  => 'nullable|date',
        ]);

        if (!empty($data['vehicle_id'])) {
            $vehicle = Vehicle::findOrFail($data['vehicle_id']);
            $this->assertAgencyAllowed($request, $vehicle->agency_id);
        }

        $driver = Driver::create($data);
        return response()->json($driver->load('vehicle:id,plate'), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $driver = Driver::findOrFail($id);

        if ($driver->vehicle_id) {
            $vehicle = Vehicle::find($driver->vehicle_id);
            $this->assertAgencyAllowed($request, $vehicle?->agency_id);
        }

        $data = $request->validate([
            'name'                 => 'sometimes|string|max:255',
            'phone'                => 'nullable|string|max:30',
            'license_no'           => 'nullable|string|max:50',
            'vehicle_id'           => 'nullable|integer|exists:vehicles,id',
            'status'               => 'in:active,inactive',
            'notes'                => 'nullable|string',
            'psv_badge_expiry'     => 'nullable|date',
            'licence_expiry'       => 'nullable|date',
            'good_conduct_expiry'  => 'nullable|date',
            'medical_cert_expiry'  => 'nullable|date',
        ]);

        if (array_key_exists('vehicle_id', $data) && $data['vehicle_id']) {
            $newVehicle = Vehicle::findOrFail($data['vehicle_id']);
            $this->assertAgencyAllowed($request, $newVehicle->agency_id);
        }

        $driver->update($data);
        return response()->json($driver->load('vehicle:id,plate'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $driver = Driver::with('vehicle:id,plate,agency_id')->findOrFail($id);
        $this->assertAgencyAllowed($request, $driver->vehicle?->agency_id);
        $driver->delete();
        return response()->json(null, 204);
    }
}
