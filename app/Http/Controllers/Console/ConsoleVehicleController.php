<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Models\Wallet;
use App\Services\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConsoleVehicleController extends Controller
{
    private const IMPORT_COLUMN_MAP = [
        'Plate'                       => 'plate',
        'Model'                       => 'model',
        'Capacity'                    => 'capacity',
        'Status'                      => 'status',
        'Route'                       => 'route_short_name',
        'Insurance Expiry'            => 'insurance_expiry',
        'Inspection Due'              => 'inspection_due',
        'Road Service License Expiry' => 'road_service_license_expiry',
        'Speed Limiter Cert Expiry'   => 'speed_limiter_cert_expiry',
        'Notes'                       => 'notes',
    ];
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

    // ── Import ────────────────────────────────────────────────────────────────

    public function importSample(): StreamedResponse
    {
        $service = app(ImportService::class);
        $content = $service->sampleCsvContent(
            array_keys(self::IMPORT_COLUMN_MAP),
            [
                ['KAB 123A', 'Isuzu NQR',       '33', 'active',   '23A', '2025-12-31', '2025-09-30', '2025-12-31', '2025-06-30', ''],
                ['KBC 456B', 'Toyota Coaster',   '25', 'active',   '',    '',           '',           '',           '',           'Spare vehicle'],
                ['KCD 789C', 'Nissan Civilian',  '29', 'inactive', '14B', '2024-03-01', '',           '',           '',           ''],
            ]
        );

        return response()->stream(function () use ($content) {
            echo $content;
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="vehicles-import-sample.csv"',
        ]);
    }

    public function importPreview(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:10240']);

        $ext = strtolower($request->file('file')->getClientOriginalExtension());
        abort_if(! in_array($ext, ['csv', 'xlsx'], true), 422, 'Only CSV and XLSX files are supported.');

        $service = app(ImportService::class);
        $tempId  = $service->storeTempFile($request->file('file'));
        $result  = $service->preview($tempId, self::IMPORT_COLUMN_MAP, $this->vehicleRowValidator());

        return response()->json($result);
    }

    public function importConfirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'temp_id'   => 'required|string',
            'agency_id' => 'required|string|exists:agencies,agency_id',
        ]);

        $this->assertAgencyAllowed($request, $data['agency_id']);

        // Preload route short-name → route_id map to avoid N+1 on route lookup
        $routeMap = \App\Models\Route::pluck('route_id', 'route_short_name')->toArray();

        $service  = app(ImportService::class);
        $agencyId = $data['agency_id'];

        $result = $service->confirm(
            $data['temp_id'],
            self::IMPORT_COLUMN_MAP,
            $this->vehicleRowValidator(),
            function (array $row) use ($agencyId, $routeMap) {
                $status = in_array($row['status'], ['active', 'inactive', 'suspended'], true)
                    ? $row['status'] : 'active';

                $routeId = null;
                if (! empty(trim($row['route_short_name']))) {
                    $routeId = $routeMap[$row['route_short_name']] ?? null;
                }

                $vehicle = Vehicle::updateOrCreate(
                    ['plate' => strtoupper(trim($row['plate']))],
                    [
                        'agency_id'                   => $agencyId,
                        'model'                       => $row['model'] ?: null,
                        'capacity'                    => $row['capacity'] !== '' ? (int) $row['capacity'] : null,
                        'status'                      => $status,
                        'route_id'                    => $routeId,
                        'insurance_expiry'            => $row['insurance_expiry'] ?: null,
                        'inspection_due'              => $row['inspection_due'] ?: null,
                        'road_service_license_expiry' => $row['road_service_license_expiry'] ?: null,
                        'speed_limiter_cert_expiry'   => $row['speed_limiter_cert_expiry'] ?: null,
                        'notes'                       => $row['notes'] ?: null,
                    ]
                );

                Wallet::firstOrCreate(
                    ['entity_type' => 'vehicle', 'entity_id' => (string) $vehicle->id],
                    ['balance' => 0, 'currency' => 'KES'],
                );
            }
        );

        return response()->json($result);
    }

    private function vehicleRowValidator(): \Closure
    {
        return function (array $row): array {
            $errors = [];

            if (empty(trim($row['plate'] ?? ''))) {
                $errors[] = 'Plate is required';
            }

            if (! empty($row['status']) && ! in_array($row['status'], ['active', 'inactive', 'suspended'], true)) {
                $errors[] = 'Status must be active, inactive, or suspended';
            }

            if (! empty($row['capacity'])) {
                if (! is_numeric($row['capacity']) || (int) $row['capacity'] < 1 || (int) $row['capacity'] > 200) {
                    $errors[] = 'Capacity must be a number between 1 and 200';
                }
            }

            $dateFields = [
                'insurance_expiry'            => 'Insurance Expiry',
                'inspection_due'              => 'Inspection Due',
                'road_service_license_expiry' => 'Road Service License Expiry',
                'speed_limiter_cert_expiry'   => 'Speed Limiter Cert Expiry',
            ];

            foreach ($dateFields as $field => $label) {
                if (! empty(trim($row[$field] ?? ''))) {
                    try {
                        \Carbon\Carbon::parse($row[$field]);
                    } catch (\Throwable) {
                        $errors[] = "{$label} must be a valid date (YYYY-MM-DD)";
                    }
                }
            }

            return $errors;
        };
    }
}
