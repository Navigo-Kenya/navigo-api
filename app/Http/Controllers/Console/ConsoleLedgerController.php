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

class ConsoleLedgerController extends Controller
{
    public function __construct(private LedgerService $ledger) {}

    // ── Split Configs ─────────────────────────────────────────────────────────

    public function splitConfigs(): JsonResponse
    {
        return response()->json(SplitConfig::orderByRaw('agency_id IS NOT NULL, agency_id')->get());
    }

    public function saveSplitConfig(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id'    => 'nullable|string|exists:agencies,agency_id',
            'vehicle_pct'  => 'required|numeric|min:0|max:100',
            'sacco_pct'    => 'required|numeric|min:0|max:100',
            'platform_pct' => 'required|numeric|min:0|max:100',
            'notes'        => 'nullable|string',
            'is_active'    => 'boolean',
        ]);

        $sum = $data['vehicle_pct'] + $data['sacco_pct'] + $data['platform_pct'];
        if (abs($sum - 100) > 0.01) {
            return response()->json(['message' => "Percentages must sum to 100 (got {$sum})."], 422);
        }

        // Upsert: one config per agency (or global)
        $config = SplitConfig::updateOrCreate(
            ['agency_id' => $data['agency_id'] ?? null],
            $data,
        );

        return response()->json($config, $config->wasRecentlyCreated ? 201 : 200);
    }

    // ── Wallets ───────────────────────────────────────────────────────────────

    public function wallets(Request $request): JsonResponse
    {
        $q = Wallet::query();

        if ($request->filled('entity_type')) {
            $q->where('entity_type', $request->entity_type);
        }

        $rows = $q->orderByDesc('balance')->get();

        $vehicleIds = $rows->where('entity_type', 'vehicle')->pluck('entity_id')->map(fn($id) => (int) $id);
        $plates = Vehicle::whereIn('id', $vehicleIds)->pluck('plate', 'id');

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

    public function walletTransactions(Request $request, int $id): JsonResponse
    {
        $wallet = Wallet::findOrFail($id);

        $q = $wallet->transactions()->latest('created_at');

        if ($request->filled('type')) {
            $q->where('type', $request->type);
        }
        if ($request->filled('from')) {
            $q->where('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $q->where('created_at', '<=', $request->to);
        }

        return response()->json([
            'wallet'       => $wallet,
            'transactions' => $q->paginate(100),
        ]);
    }

    // ── Revenue ───────────────────────────────────────────────────────────────

    public function fleetRevenue(Request $request): JsonResponse
    {
        $agencyId = $request->input('agency_id');
        $period   = $request->input('period', '30d');

        $days = match ($period) {
            '7d'  => 7,
            '90d' => 90,
            default => 30,
        };

        $q = DB::table('payment_splits as ps')
            ->join('vehicles as v', 'v.id', '=', 'ps.vehicle_id')
            ->where('ps.status', 'completed')
            ->where('ps.created_at', '>=', now()->subDays($days))
            ->groupBy('ps.vehicle_id', 'v.plate', 'v.route_id')
            ->select([
                'ps.vehicle_id',
                'v.plate',
                'v.route_id',
                DB::raw('SUM(ps.amount_total) as total_revenue'),
                DB::raw('COUNT(*) as split_count'),
                DB::raw('MAX(ps.created_at) as last_split_at'),
            ])
            ->orderByDesc('total_revenue');

        if ($agencyId) {
            $q->where('v.agency_id', $agencyId);
        }

        return response()->json($q->get());
    }

    public function vehicleRevenue(Request $request, int $id): JsonResponse
    {
        $days = match ($request->input('period', '30d')) {
            '7d'  => 7,
            '90d' => 90,
            default => 30,
        };

        $splits = PaymentSplit::where('vehicle_id', $id)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays($days))
            ->orderByDesc('created_at')
            ->get(['id', 'amount_total', 'vehicle_amount', 'route_id', 'external_ref', 'created_at']);

        $wallet = Wallet::where('entity_type', 'vehicle')->where('entity_id', (string) $id)->first();

        return response()->json([
            'wallet'         => $wallet,
            'total_revenue'  => $splits->sum('vehicle_amount'),
            'total_gross'    => $splits->sum('amount_total'),
            'split_count'    => $splits->count(),
            'splits'         => $splits,
        ]);
    }

    public function routeRevenue(Request $request): JsonResponse
    {
        $days = match ($request->input('period', '30d')) {
            '7d'  => 7,
            '90d' => 90,
            default => 30,
        };

        $data = DB::table('payment_splits')
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('route_id')
            ->groupBy('route_id')
            ->select([
                'route_id',
                DB::raw('SUM(amount_total) as total_gross'),
                DB::raw('SUM(vehicle_amount) as vehicle_total'),
                DB::raw('SUM(sacco_amount) as sacco_total'),
                DB::raw('SUM(platform_amount) as platform_total'),
                DB::raw('COUNT(*) as split_count'),
            ])
            ->orderByDesc('total_gross')
            ->get();

        return response()->json($data);
    }

    // ── Test Split (dry-run, no real money) ───────────────────────────────────

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
            'message'          => 'Test split recorded successfully.',
            'split'            => $split,
            'vehicle_amount'   => $split->vehicle_amount,
            'sacco_amount'     => $split->sacco_amount,
            'platform_amount'  => $split->platform_amount,
        ], 201);
    }
}
