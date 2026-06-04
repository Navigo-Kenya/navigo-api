<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\DailyBanking;
use App\Models\Vehicle;
use App\Models\VehicleTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DailyBankingController extends Controller
{
    // ── Vehicle Targets ───────────────────────────────────────────────────────

    public function targets(Request $request): JsonResponse
    {
        $q = VehicleTarget::with('vehicle:id,plate');
        $this->scopeQuery($q, $request);

        if ($request->filled('vehicle_id')) {
            $q->where('vehicle_id', $request->vehicle_id);
        }

        // By default return only currently-active targets (effective_to IS NULL)
        if (!$request->boolean('include_historical')) {
            $q->whereNull('effective_to');
        }

        return response()->json($q->orderBy('created_at', 'desc')->get());
    }

    public function storeTarget(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id'      => 'required|string|exists:agencies,agency_id',
            'vehicle_id'     => 'nullable|integer|exists:vehicles,id',
            'vehicle_class'  => 'nullable|string|max:50',
            'daily_target'   => 'required|numeric|min:0',
            'effective_from' => 'required|date',
        ]);

        $this->assertAgencyAllowed($request, $data['agency_id']);
        $data['created_by'] = $request->user()?->id;

        // Close any existing open target for this vehicle / agency
        if (!empty($data['vehicle_id'])) {
            VehicleTarget::where('vehicle_id', $data['vehicle_id'])
                ->whereNull('effective_to')
                ->update(['effective_to' => Carbon::parse($data['effective_from'])->subDay()->toDateString()]);
        }

        $target = VehicleTarget::create($data);
        return response()->json($target->load('vehicle:id,plate'), 201);
    }

    // ── Daily Banking Records ─────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $q = DailyBanking::with('vehicle:id,plate');
        $this->scopeQuery($q, $request);

        if ($request->filled('date')) {
            $q->where('banking_date', $request->date);
        }
        if ($request->filled('vehicle_id')) {
            $q->where('vehicle_id', $request->vehicle_id);
        }

        return response()->json($q->orderBy('banking_date', 'desc')->paginate(50));
    }

    public function record(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id'       => 'required|string|exists:agencies,agency_id',
            'vehicle_id'      => 'nullable|integer|exists:vehicles,id',
            'shift_id'        => 'nullable|integer|exists:shifts,id',
            'banking_date'    => 'required|date',
            'banked_amount'   => 'required|numeric|min:0',
            'expected_amount' => 'nullable|numeric|min:0',
            'm_pesa_ref'      => 'nullable|string|max:100',
            'notes'           => 'nullable|string',
        ]);

        $this->assertAgencyAllowed($request, $data['agency_id']);
        $data['recorded_by'] = $request->user()?->id;

        // Resolve expected_amount from vehicle target if not provided
        if (empty($data['expected_amount']) && !empty($data['vehicle_id'])) {
            $target = VehicleTarget::where('vehicle_id', $data['vehicle_id'])
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>=', $data['banking_date'])
                ->orderByDesc('effective_from')
                ->first();
            $data['expected_amount'] = $target?->daily_target;
        }

        $banking = DailyBanking::updateOrCreate(
            ['vehicle_id' => $data['vehicle_id'], 'banking_date' => $data['banking_date']],
            $data
        );

        return response()->json($banking->load('vehicle:id,plate'), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $entry = DailyBanking::findOrFail($id);
        $this->assertAgencyAllowed($request, $entry->agency_id);

        $data = $request->validate([
            'banked_amount'   => 'sometimes|numeric|min:0',
            'expected_amount' => 'nullable|numeric|min:0',
            'm_pesa_ref'      => 'nullable|string|max:100',
            'notes'           => 'nullable|string',
        ]);

        $entry->update($data);
        return response()->json($entry->load('vehicle:id,plate'));
    }

    // Daily summary: total expected vs banked + per-vehicle status for one date
    public function summary(Request $request): JsonResponse
    {
        $date  = $request->input('date', today()->toDateString());
        $scope = $this->agencyScope($request);

        // All active vehicles for the agency scope
        $vehicles = Vehicle::where('status', 'active')
            ->when($scope !== null, fn ($q) => $q->whereIn('agency_id', $scope))
            ->select(['id', 'plate', 'agency_id'])
            ->get();

        // Banking entries for that date
        $bankingMap = DailyBanking::where('banking_date', $date)
            ->when($scope !== null, fn ($q) => $q->whereIn('agency_id', $scope))
            ->get()
            ->keyBy('vehicle_id');

        // Active targets
        $targetMap = VehicleTarget::whereNull('effective_to')
            ->when($scope !== null, fn ($q) => $q->whereIn('agency_id', $scope))
            ->get()
            ->keyBy('vehicle_id');

        $entries    = [];
        $totalBanked   = 0;
        $totalExpected = 0;

        foreach ($vehicles as $v) {
            $banking  = $bankingMap->get($v->id);
            $target   = $targetMap->get($v->id);
            $expected = $target?->daily_target;
            $banked   = $banking?->banked_amount;

            if ($banked !== null) $totalBanked   += $banked;
            if ($expected !== null) $totalExpected += $expected;

            $status = 'missing';
            if ($banked !== null) {
                $status = ($expected && $banked >= $expected * 0.9) ? 'banked' : 'partial';
            }

            $entries[] = [
                'vehicle_id'      => $v->id,
                'plate'           => $v->plate,
                'expected'        => $expected,
                'banked'          => $banked,
                'm_pesa_ref'      => $banking?->m_pesa_ref,
                'banking_id'      => $banking?->id,
                'status'          => $status,
            ];
        }

        return response()->json([
            'date'             => $date,
            'total_expected'   => $totalExpected,
            'total_banked'     => $totalBanked,
            'vehicles_banked'  => collect($entries)->whereIn('status', ['banked', 'partial'])->count(),
            'vehicles_total'   => count($entries),
            'entries'          => $entries,
        ]);
    }

    // Per-vehicle trend: banked vs expected over a date range
    public function trends(Request $request): JsonResponse
    {
        $this->assertAgencyAllowed($request, $request->input('agency_id'));
        $scope = $this->agencyScope($request);

        $days = min((int) $request->input('days', 30), 90);
        $from = today()->subDays($days - 1)->toDateString();
        $to   = today()->toDateString();

        $rows = DailyBanking::whereBetween('banking_date', [$from, $to])
            ->when($scope !== null, fn ($q) => $q->whereIn('agency_id', $scope))
            ->selectRaw('banking_date, SUM(banked_amount) as total_banked, SUM(expected_amount) as total_expected')
            ->groupBy('banking_date')
            ->orderBy('banking_date')
            ->get();

        return response()->json($rows);
    }
}
