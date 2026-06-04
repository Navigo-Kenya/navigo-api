<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsoleShiftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Shift::with([
            'vehicle:id,plate',
            'driver:id,name',
            'conductor:id,name',
        ]);

        $this->scopeQuery($q, $request);

        if ($request->filled('date')) {
            $q->where('shift_date', $request->date);
        } elseif ($request->filled('from') && $request->filled('to')) {
            $q->whereBetween('shift_date', [$request->from, $request->to]);
        }

        if ($request->filled('vehicle_id')) {
            $q->where('vehicle_id', $request->vehicle_id);
        }
        if ($request->filled('driver_id')) {
            $q->where('driver_id', $request->driver_id);
        }
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }

        return response()->json(
            $q->orderBy('shift_date', 'desc')->orderBy('start_time')->paginate(50)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id'    => 'required|string|exists:agencies,agency_id',
            'vehicle_id'   => 'nullable|integer|exists:vehicles,id',
            'driver_id'    => 'nullable|integer|exists:drivers,id',
            'conductor_id' => 'nullable|integer|exists:conductors,id',
            'shift_date'   => 'required|date',
            'start_time'   => 'required|date_format:H:i',
            'end_time'     => 'required|date_format:H:i',
            'notes'        => 'nullable|string',
        ]);

        $this->assertAgencyAllowed($request, $data['agency_id']);
        $data['created_by'] = $request->user()?->id;

        $shift = Shift::create($data);
        return response()->json($shift->load(['vehicle:id,plate', 'driver:id,name', 'conductor:id,name']), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);
        $this->assertAgencyAllowed($request, $shift->agency_id);

        $data = $request->validate([
            'vehicle_id'   => 'nullable|integer|exists:vehicles,id',
            'driver_id'    => 'nullable|integer|exists:drivers,id',
            'conductor_id' => 'nullable|integer|exists:conductors,id',
            'shift_date'   => 'sometimes|date',
            'start_time'   => 'sometimes|date_format:H:i',
            'end_time'     => 'sometimes|date_format:H:i',
            'status'       => 'in:scheduled,active,completed,missed',
            'banked_amount' => 'nullable|numeric|min:0',
            'notes'        => 'nullable|string',
        ]);

        $shift->update($data);
        return response()->json($shift->load(['vehicle:id,plate', 'driver:id,name', 'conductor:id,name']));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);
        $this->assertAgencyAllowed($request, $shift->agency_id);
        $shift->delete();
        return response()->json(null, 204);
    }

    public function start(Request $request, int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);
        $this->assertAgencyAllowed($request, $shift->agency_id);

        $shift->update([
            'status'            => 'active',
            'actual_start_time' => now(),
        ]);

        return response()->json($shift->load(['vehicle:id,plate', 'driver:id,name', 'conductor:id,name']));
    }

    public function end(Request $request, int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);
        $this->assertAgencyAllowed($request, $shift->agency_id);

        $data = $request->validate([
            'banked_amount' => 'required|numeric|min:0',
            'notes'         => 'nullable|string',
        ]);

        $shift->update([
            'status'          => 'completed',
            'actual_end_time' => now(),
            'banked_amount'   => $data['banked_amount'],
            'notes'           => $data['notes'] ?? $shift->notes,
        ]);

        return response()->json($shift->load(['vehicle:id,plate', 'driver:id,name', 'conductor:id,name']));
    }

    // Returns vehicles that have no shift scheduled for a given date.
    public function uncovered(Request $request): JsonResponse
    {
        $date  = $request->input('date', today()->toDateString());
        $scope = $this->agencyScope($request);

        $coveredIds = Shift::where('shift_date', $date)
            ->when($scope !== null, fn ($q) => $q->whereIn('agency_id', $scope))
            ->whereNotNull('vehicle_id')
            ->pluck('vehicle_id')
            ->unique();

        $vehicles = Vehicle::where('status', 'active')
            ->when($scope !== null, fn ($q) => $q->whereIn('agency_id', $scope))
            ->whereNotIn('id', $coveredIds)
            ->select(['id', 'plate', 'agency_id'])
            ->orderBy('plate')
            ->get();

        return response()->json(['date' => $date, 'vehicles' => $vehicles]);
    }
}
