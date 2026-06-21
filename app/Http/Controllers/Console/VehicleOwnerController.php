<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\VehicleOwner;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VehicleOwnerController extends Controller
{
    public function __construct(private StorageService $storage) {}

    public function index(Request $request): JsonResponse
    {
        $q = VehicleOwner::query()->withCount('vehicles');

        $this->scopeQuery($q, $request);

        if ($search = $request->query('search')) {
            $q->where('name', 'ilike', "%{$search}%");
        }

        return response()->json($q->orderBy('name')->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id'     => 'required|string|exists:agencies,agency_id',
            'name'          => 'required|string|max:255',
            'phone'         => 'nullable|string|max:30',
            'email'         => 'nullable|email|max:255',
            'national_id'   => 'nullable|string|max:50',
            'm_pesa_number' => 'nullable|string|max:30',
            'notes'         => 'nullable|string',
        ]);

        $this->assertAgencyAllowed($request, $data['agency_id']);

        return response()->json(VehicleOwner::create($data), 201);
    }

    public function update(Request $request, VehicleOwner $owner): JsonResponse
    {
        $this->assertAgencyAllowed($request, $owner->agency_id);

        $data = $request->validate([
            'name'          => 'sometimes|string|max:255',
            'phone'         => 'nullable|string|max:30',
            'email'         => 'nullable|email|max:255',
            'national_id'   => 'nullable|string|max:50',
            'm_pesa_number' => 'nullable|string|max:30',
            'notes'         => 'nullable|string',
        ]);

        $owner->update($data);

        return response()->json($owner->fresh());
    }

    public function destroy(Request $request, VehicleOwner $owner): JsonResponse
    {
        $this->assertAgencyAllowed($request, $owner->agency_id);
        $owner->delete();

        return response()->json(null, 204);
    }

    public function uploadPhoto(Request $request, VehicleOwner $owner): JsonResponse
    {
        $this->assertAgencyAllowed($request, $owner->agency_id);

        $request->validate(['photo' => 'required|image|max:4096']);

        $this->storage->delete($owner->getRawOriginal('photo_url'));
        $url = $this->storage->upload($request->file('photo'), "owner-photos/{$owner->id}");

        $owner->update(['photo_url' => $url]);

        return response()->json(['photo_url' => $url]);
    }

    public function summary(Request $request, VehicleOwner $owner): JsonResponse
    {
        $this->assertAgencyAllowed($request, $owner->agency_id);

        $vehicles = $owner->vehicles()
            ->with('route:route_id,route_short_name')
            ->get(['id', 'plate', 'status', 'route_id', 'owner_id']);

        $from = now()->startOfMonth()->toDateString();
        $to   = now()->toDateString();

        $vehicleIds = $vehicles->pluck('id');

        $banking = DB::table('daily_banking')
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereBetween('banking_date', [$from, $to])
            ->selectRaw('vehicle_id, SUM(banked_amount) as total_banked, COUNT(*) as days_banked')
            ->groupBy('vehicle_id')
            ->get()
            ->keyBy('vehicle_id');

        $expenses = DB::table('vehicle_expenses')
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereBetween('expense_date', [$from, $to])
            ->selectRaw('vehicle_id, SUM(amount) as total_expenses')
            ->groupBy('vehicle_id')
            ->get()
            ->keyBy('vehicle_id');

        $vehicleSummaries = $vehicles->map(fn ($v) => [
            'id'             => $v->id,
            'plate'          => $v->plate,
            'route'          => $v->route?->route_short_name,
            'status'         => $v->status,
            'total_banked'   => (float) ($banking[$v->id]?->total_banked ?? 0),
            'total_expenses' => (float) ($expenses[$v->id]?->total_expenses ?? 0),
            'days_banked'    => (int) ($banking[$v->id]?->days_banked ?? 0),
        ]);

        return response()->json([
            'owner'        => $owner,
            'vehicles'     => $vehicleSummaries,
            'period'       => ['from' => $from, 'to' => $to],
            'total_banked' => $vehicleSummaries->sum('total_banked'),
            'total_expenses' => $vehicleSummaries->sum('total_expenses'),
            'net_revenue'  => $vehicleSummaries->sum('total_banked') - $vehicleSummaries->sum('total_expenses'),
        ]);
    }
}
