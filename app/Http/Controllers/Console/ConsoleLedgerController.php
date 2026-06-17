<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\PaymentSplit;
use App\Models\SplitConfig;
use App\Models\Vehicle;
use App\Models\Wallet;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ConsoleLedgerController extends Controller
{
    public function __construct(private LedgerService $ledger) {}

    // ── Split Configs ─────────────────────────────────────────────────────────

    /**
     * Return the split config for the active agency (operators) or a specific
     * agency (admins). Returns 404 when none is configured yet.
     */
    public function splitConfigs(Request $request): JsonResponse
    {
        $scope = $this->agencyScope($request);

        if ($scope !== null) {
            // Operator: return their own config (one row or empty)
            $configs = SplitConfig::whereIn('agency_id', $scope)->get();
        } elseif ($request->filled('agency_id')) {
            $configs = SplitConfig::where('agency_id', $request->input('agency_id'))->get();
        } else {
            // Admin with no filter: all configs
            $configs = SplitConfig::orderBy('agency_id')->get();
        }

        return response()->json($configs);
    }

    /**
     * Create or update the split config for an agency (upsert by agency_id).
     * Both percentage and lengo modes are accepted.
     */
    public function saveSplitConfig(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id'        => 'required|string|exists:agencies,agency_id',
            'split_enabled'    => 'boolean',
            'split_type'       => ['required', Rule::in(['percentage', 'lengo'])],
            // Percentage-mode fields
            'vehicle_pct'      => 'required_if:split_type,percentage|numeric|min:0|max:100',
            'sacco_pct'        => 'required_if:split_type,percentage|numeric|min:0|max:100',
            'platform_pct'     => 'required|numeric|min:3|max:3',
            // Lengo-mode fields
            'daily_target'     => 'nullable|numeric|min:0',
            'daily_sacco_levy' => 'nullable|numeric|min:0',
            'notes'            => 'nullable|string',
            'is_active'        => 'boolean',
        ]);

        $this->assertAgencyAllowed($request, $data['agency_id']);

        // Validate percentage total when in percentage mode
        if (($data['split_type'] ?? 'percentage') === 'percentage' && ($data['split_enabled'] ?? true)) {
            $sum = ($data['vehicle_pct'] ?? 0) + ($data['sacco_pct'] ?? 0) + ($data['platform_pct'] ?? 3);
            if (abs($sum - 100) > 0.01) {
                return response()->json(['message' => "Percentages must sum to 100 (got {$sum})."], 422);
            }
        }

        $config = SplitConfig::updateOrCreate(
            ['agency_id' => $data['agency_id']],
            $data,
        );

        return response()->json($config, $config->wasRecentlyCreated ? 201 : 200);
    }

    // ── Daily Levy (Lengo mode) ───────────────────────────────────────────────

    /**
     * Deduct the configured flat daily SACCO levy from every vehicle wallet
     * that belongs to the agency and transfer it to the SACCO wallet.
     * Only valid when split_type = 'lengo'.
     */
    public function applyDailyLevy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id' => 'required|string|exists:agencies,agency_id',
        ]);

        $this->assertAgencyAllowed($request, $data['agency_id']);

        try {
            $results = $this->ledger->applyDailyLevy($data['agency_id'], (string) $request->user()?->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $applied    = collect($results)->where('status', 'applied')->count();
        $skipped    = collect($results)->where('status', 'insufficient_balance')->count();

        return response()->json([
            'message'  => "{$applied} vehicle(s) levied, {$skipped} skipped (insufficient balance).",
            'results'  => $results,
        ]);
    }

    // ── Wallets ───────────────────────────────────────────────────────────────

    public function wallets(Request $request): JsonResponse
    {
        $q = Wallet::query();

        if ($request->filled('entity_type')) {
            $q->where('entity_type', $request->entity_type);
        }

        // Agency-scoped: operators see only their agency wallet + their vehicles' wallets
        $scope = $this->agencyScope($request);
        if ($scope !== null) {
            $agencyIds  = $scope;
            $vehicleIds = Vehicle::whereIn('agency_id', $agencyIds)
                ->pluck('id')
                ->map(fn ($id) => (string) $id);

            $q->where(function ($sub) use ($agencyIds, $vehicleIds) {
                $sub->where(fn ($q) => $q->where('entity_type', 'agency')->whereIn('entity_id', $agencyIds))
                    ->orWhere(fn ($q) => $q->where('entity_type', 'vehicle')->whereIn('entity_id', $vehicleIds));
            });
        } elseif ($request->filled('agency_id')) {
            $agencyId   = $request->input('agency_id');
            $vehicleIds = Vehicle::where('agency_id', $agencyId)->pluck('id')->map(fn ($id) => (string) $id);

            $q->where(function ($sub) use ($agencyId, $vehicleIds) {
                $sub->where(fn ($q) => $q->where('entity_type', 'agency')->where('entity_id', $agencyId))
                    ->orWhere(fn ($q) => $q->where('entity_type', 'vehicle')->whereIn('entity_id', $vehicleIds));
            });
        }

        $rows = $q->orderByDesc('balance')->get();

        $vehicleIds = $rows->where('entity_type', 'vehicle')->pluck('entity_id')->map(fn ($id) => (int) $id);
        $plates     = Vehicle::whereIn('id', $vehicleIds)->pluck('plate', 'id');

        $wallets = $rows->map(function (Wallet $w) use ($plates) {
            $label = match ($w->entity_type) {
                'vehicle'  => $plates[(int) $w->entity_id] ?? 'Vehicle #'.$w->entity_id,
                'agency'   => $w->entity_id,
                'platform' => 'Platform',
                default    => $w->entity_id,
            };
            return array_merge($w->toArray(), ['label' => $label]);
        });

        return response()->json($wallets);
    }

    public function walletTransactions(Request $request, string $id): JsonResponse
    {
        $wallet = Wallet::findOrFail($id);

        $scope = $this->agencyScope($request);
        if ($scope !== null) {
            $allowed = false;
            if ($wallet->entity_type === 'agency') {
                $allowed = in_array($wallet->entity_id, $scope);
            } elseif ($wallet->entity_type === 'vehicle') {
                $allowed = Vehicle::whereIn('agency_id', $scope)
                    ->where('id', (int) $wallet->entity_id)
                    ->exists();
            }
            if (!$allowed) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $q = $wallet->transactions()->latest('created_at');

        if ($request->filled('type'))  { $q->where('type', $request->type); }
        if ($request->filled('from'))  { $q->where('created_at', '>=', $request->from); }
        if ($request->filled('to'))    { $q->where('created_at', '<=', $request->to); }

        return response()->json([
            'wallet'       => $wallet,
            'transactions' => $q->paginate(100),
        ]);
    }

    // ── Revenue ───────────────────────────────────────────────────────────────

    public function fleetRevenue(Request $request): JsonResponse
    {
        $days = $this->periodDays($request);

        $q = DB::table('payment_splits as ps')
            ->join('vehicles as v', 'v.id', '=', 'ps.vehicle_id')
            ->where('ps.status', 'completed')
            ->where('ps.created_at', '>=', now()->subDays($days))
            ->groupBy('ps.vehicle_id', 'v.plate', 'v.route_id')
            ->select([
                'ps.vehicle_id', 'v.plate', 'v.route_id',
                DB::raw('SUM(ps.amount_total) as total_revenue'),
                DB::raw('COUNT(*) as split_count'),
                DB::raw('MAX(ps.created_at) as last_split_at'),
            ])
            ->orderByDesc('total_revenue');

        $scope = $this->agencyScope($request);
        if ($scope !== null)                      { $q->whereIn('v.agency_id', $scope); }
        elseif ($request->filled('agency_id'))    { $q->where('v.agency_id', $request->input('agency_id')); }

        return response()->json($q->get());
    }

    public function vehicleRevenue(Request $request, int $id): JsonResponse
    {
        $days   = $this->periodDays($request);
        $splits = PaymentSplit::where('vehicle_id', $id)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays($days))
            ->orderByDesc('created_at')
            ->get(['id', 'amount_total', 'vehicle_amount', 'route_id', 'external_ref', 'created_at']);

        $wallet = Wallet::where('entity_type', 'vehicle')->where('entity_id', (string) $id)->first();

        return response()->json([
            'wallet'        => $wallet,
            'total_revenue' => $splits->sum('vehicle_amount'),
            'total_gross'   => $splits->sum('amount_total'),
            'split_count'   => $splits->count(),
            'splits'        => $splits,
        ]);
    }

    public function routeRevenue(Request $request): JsonResponse
    {
        $days  = $this->periodDays($request);
        $scope = $this->agencyScope($request);

        $q = DB::table('payment_splits as ps')
            ->join('vehicles as v', 'v.id', '=', 'ps.vehicle_id')
            ->where('ps.status', 'completed')
            ->where('ps.created_at', '>=', now()->subDays($days))
            ->whereNotNull('ps.route_id')
            ->groupBy('ps.route_id')
            ->select([
                'ps.route_id',
                DB::raw('SUM(ps.amount_total) as total_gross'),
                DB::raw('SUM(ps.vehicle_amount) as vehicle_total'),
                DB::raw('SUM(ps.sacco_amount) as sacco_total'),
                DB::raw('SUM(ps.platform_amount) as platform_total'),
                DB::raw('COUNT(*) as split_count'),
            ])
            ->orderByDesc('total_gross');

        if ($scope !== null)                   { $q->whereIn('v.agency_id', $scope); }
        elseif ($request->filled('agency_id')) { $q->where('v.agency_id', $request->input('agency_id')); }

        return response()->json($q->get());
    }

    public function revenueTrend(Request $request): JsonResponse
    {
        $days  = $this->periodDays($request);
        $scope = $this->agencyScope($request);

        $q = DB::table('payment_splits as ps')
            ->join('vehicles as v', 'v.id', '=', 'ps.vehicle_id')
            ->where('ps.status', 'completed')
            ->where('ps.created_at', '>=', now()->subDays($days))
            ->groupBy(DB::raw('DATE(ps.created_at)'))
            ->select([
                DB::raw('DATE(ps.created_at) as date'),
                DB::raw('SUM(ps.amount_total) as gross'),
                DB::raw('SUM(ps.vehicle_amount) as vehicle_total'),
                DB::raw('SUM(ps.sacco_amount) as sacco_total'),
                DB::raw('COUNT(*) as transactions'),
            ])
            ->orderBy(DB::raw('DATE(ps.created_at)'));

        if ($scope !== null)                   { $q->whereIn('v.agency_id', $scope); }
        elseif ($request->filled('agency_id')) { $q->where('v.agency_id', $request->input('agency_id')); }

        return response()->json($q->get());
    }

    // ── Test Split (dry-run) ──────────────────────────────────────────────────

    public function testSplit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount'     => 'required|numeric|min:1',
            'vehicle_id' => 'required|integer|exists:vehicles,id',
            'route_id'   => 'nullable|string',
        ]);

        $split = $this->ledger->recordSplit(
            amount:      (float) $data['amount'],
            vehicleId:   (int) $data['vehicle_id'],
            routeId:     $data['route_id'] ?? null,
            externalRef: 'TEST-'.now()->timestamp,
            createdBy:   'test',
        );

        return response()->json([
            'message'         => 'Test split recorded successfully.',
            'split'           => $split,
            'vehicle_amount'  => $split->vehicle_amount,
            'sacco_amount'    => $split->sacco_amount,
            'platform_amount' => $split->platform_amount,
        ], 201);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function periodDays(Request $request): int
    {
        return match ($request->input('period', '30d')) {
            '7d'  => 7,
            '90d' => 90,
            default => 30,
        };
    }
}
