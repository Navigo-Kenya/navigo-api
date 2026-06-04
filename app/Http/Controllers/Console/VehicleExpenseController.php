<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceWindow;
use App\Models\VehicleExpense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleExpenseController extends Controller
{
    // ── Expenses ──────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $q = VehicleExpense::query()->with('vehicle:id,plate');

        $this->scopeQuery($q, $request);

        if ($vehicleId = $request->query('vehicle_id')) {
            $q->where('vehicle_id', $vehicleId);
        }
        if ($type = $request->query('type')) {
            $q->where('expense_type', $type);
        }
        if ($from = $request->query('from')) {
            $q->where('expense_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->where('expense_date', '<=', $to);
        }

        return response()->json($q->orderByDesc('expense_date')->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id'    => 'required|string|exists:agencies,agency_id',
            'vehicle_id'   => 'required|integer|exists:vehicles,id',
            'expense_type' => 'required|string|in:fuel,service,insurance,inspection,tyres,other',
            'amount'       => 'required|numeric|min:0',
            'litres'       => 'nullable|numeric|min:0',
            'odometer_km'  => 'nullable|integer|min:0',
            'description'  => 'nullable|string',
            'receipt_ref'  => 'nullable|string|max:100',
            'expense_date' => 'required|date',
        ]);

        $this->assertAgencyAllowed($request, $data['agency_id']);

        $data['recorded_by'] = $request->user()?->id;

        return response()->json(
            VehicleExpense::create($data)->load('vehicle:id,plate'),
            201
        );
    }

    public function destroy(Request $request, VehicleExpense $expense): JsonResponse
    {
        $this->assertAgencyAllowed($request, $expense->agency_id);
        $expense->delete();

        return response()->json(null, 204);
    }

    public function summary(Request $request): JsonResponse
    {
        $q = VehicleExpense::query();

        if ($scope = $this->agencyScope($request)) {
            $q->whereIn('agency_id', $scope);
        } elseif ($agencyId = $request->query('agency_id')) {
            $q->where('agency_id', $agencyId);
        }

        if ($from = $request->query('from')) {
            $q->where('expense_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->where('expense_date', '<=', $to);
        }

        $byType = (clone $q)
            ->selectRaw('expense_type, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('expense_type')
            ->get();

        $byVehicle = (clone $q)
            ->selectRaw('vehicle_id, SUM(amount) as total')
            ->with('vehicle:id,plate')
            ->groupBy('vehicle_id')
            ->get();

        return response()->json([
            'by_type'    => $byType,
            'by_vehicle' => $byVehicle,
            'total'      => $byType->sum('total'),
        ]);
    }

    // ── Maintenance Windows ───────────────────────────────────────────────────

    public function maintenanceIndex(Request $request): JsonResponse
    {
        $q = MaintenanceWindow::query()->with('vehicle:id,plate,agency_id');

        if ($scope = $this->agencyScope($request)) {
            $q->whereHas('vehicle', fn ($vq) => $vq->whereIn('agency_id', $scope));
        }

        if ($vehicleId = $request->query('vehicle_id')) {
            $q->where('vehicle_id', $vehicleId);
        }

        return response()->json($q->orderByDesc('scheduled_from')->paginate(50));
    }

    public function maintenanceStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicle_id'     => 'required|integer|exists:vehicles,id',
            'scheduled_from' => 'required|date',
            'scheduled_to'   => 'required|date|after:scheduled_from',
            'service_type'   => 'nullable|string|max:100',
            'garage_name'    => 'nullable|string|max:255',
            'estimated_cost' => 'nullable|numeric|min:0',
            'notes'          => 'nullable|string',
        ]);

        $data['created_by'] = $request->user()?->id;

        return response()->json(
            MaintenanceWindow::create($data)->load('vehicle:id,plate'),
            201
        );
    }

    public function maintenanceUpdate(Request $request, MaintenanceWindow $window): JsonResponse
    {
        $data = $request->validate([
            'scheduled_from' => 'sometimes|date',
            'scheduled_to'   => 'sometimes|date',
            'actual_to'      => 'nullable|date',
            'service_type'   => 'nullable|string|max:100',
            'garage_name'    => 'nullable|string|max:255',
            'estimated_cost' => 'nullable|numeric|min:0',
            'actual_cost'    => 'nullable|numeric|min:0',
            'notes'          => 'nullable|string',
        ]);

        $window->update($data);

        return response()->json($window->fresh()->load('vehicle:id,plate'));
    }

    public function maintenanceDestroy(MaintenanceWindow $window): JsonResponse
    {
        $window->delete();

        return response()->json(null, 204);
    }
}
