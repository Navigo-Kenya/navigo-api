<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ConsoleVehicleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Vehicle::with(['agency:agency_id,agency_name', 'route:route_id,route_short_name', 'drivers:id,name,vehicle_id']);

        $this->scopeQuery($q, $request);

        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $q->where('plate', 'ilike', '%'.$request->search.'%');
        }

        return response()->json($q->orderBy('plate')->paginate(50));
    }

    public function show(int $id): JsonResponse
    {
        $vehicle = Vehicle::with([
            'agency:agency_id,agency_name',
            'route:route_id,route_short_name,route_long_name',
            'drivers:id,name,phone,status',
            'owner',
        ])->findOrFail($id);

        return response()->json($vehicle);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plate'                        => 'required|string|max:20|unique:vehicles,plate',
            'agency_id'                    => 'nullable|string|exists:agencies,agency_id',
            'route_id'                     => 'nullable|string|exists:routes,route_id',
            'model'                        => 'nullable|string|max:100',
            'capacity'                     => 'nullable|integer|min:1|max:200',
            'status'                       => 'in:active,inactive,suspended',
            'notes'                        => 'nullable|string',
            'insurance_expiry'             => 'nullable|date',
            'inspection_due'               => 'nullable|date',
            'road_service_license_expiry'  => 'nullable|date',
            'speed_limiter_cert_expiry'    => 'nullable|date',
        ]);

        $this->assertAgencyAllowed($request, $data['agency_id'] ?? null);

        $vehicle = Vehicle::create($data);

        Wallet::firstOrCreate(
            ['entity_type' => 'vehicle', 'entity_id' => (string) $vehicle->id],
            ['balance' => 0, 'currency' => 'KES'],
        );

        return response()->json($vehicle->load(['agency:agency_id,agency_name', 'route:route_id,route_short_name']), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);

        $data = $request->validate([
            'plate'                        => 'sometimes|string|max:20|unique:vehicles,plate,'.$id,
            'agency_id'                    => 'nullable|string|exists:agencies,agency_id',
            'route_id'                     => 'nullable|string|exists:routes,route_id',
            'owner_id'                     => 'nullable|integer|exists:vehicle_owners,id',
            'model'                        => 'nullable|string|max:100',
            'capacity'                     => 'nullable|integer|min:1|max:200',
            'status'                       => 'in:active,inactive,suspended',
            'notes'                        => 'nullable|string',
            'insurance_expiry'             => 'nullable|date',
            'inspection_due'               => 'nullable|date',
            'road_service_license_expiry'  => 'nullable|date',
            'speed_limiter_cert_expiry'    => 'nullable|date',
        ]);

        $vehicle->update($data);
        return response()->json($vehicle->load(['agency:agency_id,agency_name', 'route:route_id,route_short_name', 'owner']));
    }

    public function destroy(int $id): JsonResponse
    {
        Vehicle::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    public function complianceExpiry(Request $request): JsonResponse
    {
        $agencyId = $request->input('agency_id');

        $vehicleQuery = Vehicle::query();
        $driverQuery  = Driver::query();

        if ($agencyId) {
            $vehicleQuery->where('agency_id', $agencyId);
            $driverQuery->whereHas('vehicle', fn ($v) => $v->where('agency_id', $agencyId));
        } else {
            $scope = $this->agencyScope($request);
            if ($scope !== null) {
                $vehicleQuery->whereIn('agency_id', $scope);
                $driverQuery->whereHas('vehicle', fn ($v) => $v->whereIn('agency_id', $scope));
            }
        }

        $today = now()->startOfDay();

        $vehicleFields = ['insurance_expiry', 'inspection_due', 'road_service_license_expiry', 'speed_limiter_cert_expiry'];
        $driverFields  = ['psv_badge_expiry', 'licence_expiry', 'good_conduct_expiry', 'medical_cert_expiry'];

        $expiringSoon = [];
        $vehiclesExpired = 0;
        $vehiclesWarning = 0;
        $driversExpired  = 0;
        $driversWarning  = 0;

        foreach ($vehicleQuery->get() as $vehicle) {
            $status = $vehicle->complianceStatus();
            if ($status === 'expired') $vehiclesExpired++;
            if ($status === 'warning') $vehiclesWarning++;

            foreach ($vehicleFields as $field) {
                if (!$vehicle->$field) continue;
                $daysLeft = (int) $today->diffInDays($vehicle->$field, false);
                if ($daysLeft <= 30) {
                    $expiringSoon[] = [
                        'type'        => 'vehicle',
                        'entity_id'   => $vehicle->id,
                        'plate'       => $vehicle->plate,
                        'field'       => $field,
                        'expiry_date' => $vehicle->$field->toDateString(),
                        'days_left'   => $daysLeft,
                    ];
                }
            }
        }

        foreach ($driverQuery->with('vehicle:id,plate')->get() as $driver) {
            $status = $driver->complianceStatus();
            if ($status === 'expired') $driversExpired++;
            if ($status === 'warning') $driversWarning++;

            foreach ($driverFields as $field) {
                if (!$driver->$field) continue;
                $daysLeft = (int) $today->diffInDays($driver->$field, false);
                if ($daysLeft <= 30) {
                    $expiringSoon[] = [
                        'type'        => 'driver',
                        'entity_id'   => $driver->id,
                        'name'        => $driver->name,
                        'field'       => $field,
                        'expiry_date' => $driver->$field->toDateString(),
                        'days_left'   => $daysLeft,
                    ];
                }
            }
        }

        usort($expiringSoon, fn ($a, $b) => $a['days_left'] <=> $b['days_left']);

        return response()->json([
            'vehicles_expired' => $vehiclesExpired,
            'vehicles_warning' => $vehiclesWarning,
            'drivers_expired'  => $driversExpired,
            'drivers_warning'  => $driversWarning,
            'expiring_soon'    => $expiringSoon,
        ]);
    }
}
