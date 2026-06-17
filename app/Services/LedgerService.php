<?php

namespace App\Services;

use App\Models\PaymentSplit;
use App\Models\SplitConfig;
use App\Models\Vehicle;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LedgerService
{
    // ── Payment Split ─────────────────────────────────────────────────────────

    /**
     * Record a payment against a vehicle, distributing to wallets based on the
     * agency's SplitConfig (percentage mode, lengo mode, or no split).
     *
     * Percentage mode  → vehicle_pct + sacco_pct + platform_pct per transaction.
     * Lengo mode       → platform takes platform_pct per transaction; the rest
     *                    accumulates in the vehicle wallet. The SACCO flat daily
     *                    levy is applied separately via applyDailyLevy().
     * No config / split disabled → platform takes 3% fixed; rest to vehicle wallet.
     */
    public function recordSplit(
        float $amount,
        int $vehicleId,
        ?string $routeId = null,
        ?string $externalRef = null,
        ?string $createdBy = 'system',
    ): PaymentSplit {
        $vehicle = Vehicle::findOrFail($vehicleId);

        $config = SplitConfig::where('agency_id', $vehicle->agency_id)
            ->where('is_active', true)
            ->first();

        // Platform always takes its configured % (default 3% when no config)
        $platformPct    = $config?->platform_pct ?? 3.0;
        $platformAmount = round($amount * ($platformPct / 100), 2);

        return DB::transaction(function () use (
            $amount, $vehicleId, $routeId, $externalRef, $createdBy,
            $config, $platformAmount, $vehicle,
        ) {
            $vehicleEntityId  = (string) $vehicleId;
            $saccoEntityId    = $vehicle->agency_id ?? 'unassigned';

            $vehicleWallet  = $this->getOrCreateWallet('vehicle',  $vehicleEntityId);
            $platformWallet = $this->getOrCreateWallet('platform', 'platform');

            // ── No config or split disabled ────────────────────────────────────
            if (!$config || !$config->split_enabled) {
                $vehicleAmount = round($amount - $platformAmount, 2);

                $split = PaymentSplit::create([
                    'external_ref'       => $externalRef,
                    'amount_total'       => $amount,
                    'vehicle_wallet_id'  => $vehicleWallet->id,
                    'sacco_wallet_id'    => null,
                    'platform_wallet_id' => $platformWallet->id,
                    'vehicle_amount'     => $vehicleAmount,
                    'sacco_amount'       => 0.00,
                    'platform_amount'    => $platformAmount,
                    'split_config_id'    => $config?->id,
                    'route_id'           => $routeId,
                    'vehicle_id'         => $vehicleId,
                    'status'             => 'completed',
                ]);

                $this->creditWallet($vehicleWallet,  $vehicleAmount,  $split->id, 'Vehicle revenue', $createdBy);
                $this->creditWallet($platformWallet, $platformAmount, $split->id, 'Platform commission', $createdBy);

                return $split;
            }

            // ── Lengo mode ─────────────────────────────────────────────────────
            // Platform takes its cut per transaction; SACCO levy is applied daily.
            // The vehicle wallet accumulates gross-minus-platform for the owner.
            if ($config->isLengoMode()) {
                $vehicleAmount = round($amount - $platformAmount, 2);
                $saccoWallet   = $this->getOrCreateWallet('agency', $saccoEntityId);

                $split = PaymentSplit::create([
                    'external_ref'       => $externalRef,
                    'amount_total'       => $amount,
                    'vehicle_wallet_id'  => $vehicleWallet->id,
                    'sacco_wallet_id'    => $saccoWallet->id,
                    'platform_wallet_id' => $platformWallet->id,
                    'vehicle_amount'     => $vehicleAmount,
                    'sacco_amount'       => 0.00, // settled by daily levy, not per-transaction
                    'platform_amount'    => $platformAmount,
                    'split_config_id'    => $config->id,
                    'route_id'           => $routeId,
                    'vehicle_id'         => $vehicleId,
                    'status'             => 'completed',
                ]);

                $this->creditWallet($vehicleWallet,  $vehicleAmount,  $split->id, 'Vehicle revenue (lengo)', $createdBy);
                $this->creditWallet($platformWallet, $platformAmount, $split->id, 'Platform commission', $createdBy);

                return $split;
            }

            // ── Percentage mode (default) ──────────────────────────────────────
            $vehicleAmount  = round($amount * ($config->vehicle_pct / 100), 2);
            $saccoAmount    = round($amount * ($config->sacco_pct   / 100), 2);
            $platformAmount = round($amount - $vehicleAmount - $saccoAmount, 2); // absorbs rounding drift

            $saccoWallet = $this->getOrCreateWallet('agency', $saccoEntityId);

            $split = PaymentSplit::create([
                'external_ref'       => $externalRef,
                'amount_total'       => $amount,
                'vehicle_wallet_id'  => $vehicleWallet->id,
                'sacco_wallet_id'    => $saccoWallet->id,
                'platform_wallet_id' => $platformWallet->id,
                'vehicle_amount'     => $vehicleAmount,
                'sacco_amount'       => $saccoAmount,
                'platform_amount'    => $platformAmount,
                'split_config_id'    => $config->id,
                'route_id'           => $routeId,
                'vehicle_id'         => $vehicleId,
                'status'             => 'completed',
            ]);

            $this->creditWallet($vehicleWallet,  $vehicleAmount,  $split->id, 'Vehicle owner share', $createdBy);
            $this->creditWallet($saccoWallet,    $saccoAmount,    $split->id, 'SACCO management levy', $createdBy);
            $this->creditWallet($platformWallet, $platformAmount, $split->id, 'Platform commission', $createdBy);

            return $split;
        });
    }

    // ── Daily SACCO Levy (Lengo mode) ─────────────────────────────────────────

    /**
     * Deduct the flat daily SACCO levy from each vehicle wallet and transfer it
     * to the agency (SACCO) wallet. Only applies when split_type = 'lengo'.
     * Returns a per-vehicle result array.
     */
    public function applyDailyLevy(string $agencyId, ?string $createdBy = 'system'): array
    {
        $config = SplitConfig::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->where('split_type', 'lengo')
            ->where('split_enabled', true)
            ->firstOrFail();

        $levy = (float) ($config->daily_sacco_levy ?? 0);
        if ($levy <= 0) {
            throw new \RuntimeException('No daily SACCO levy configured for this agency.');
        }

        $vehicles    = Vehicle::where('agency_id', $agencyId)->get(['id']);
        $saccoWallet = $this->getOrCreateWallet('agency', $agencyId);
        $results     = [];

        DB::transaction(function () use ($vehicles, $levy, $saccoWallet, &$results, $createdBy) {
            foreach ($vehicles as $vehicle) {
                $vehicleWallet = $this->getOrCreateWallet('vehicle', (string) $vehicle->id);

                if ($vehicleWallet->balance >= $levy) {
                    $this->debitWallet($vehicleWallet,  $levy, null, 'Daily SACCO levy', $createdBy);
                    $this->creditWallet($saccoWallet,   $levy, null, "Daily levy — vehicle #{$vehicle->id}", $createdBy);
                    $results[] = ['vehicle_id' => $vehicle->id, 'amount' => $levy, 'status' => 'applied'];
                } else {
                    $results[] = ['vehicle_id' => $vehicle->id, 'amount' => $levy, 'status' => 'insufficient_balance'];
                }
            }
        });

        return $results;
    }

    // ── Reversal ──────────────────────────────────────────────────────────────

    public function reversePaymentSplit(int $splitId, string $reason, string $createdBy = 'system'): void
    {
        $split = PaymentSplit::findOrFail($splitId);

        if ($split->status === 'reversed') {
            throw new \RuntimeException("Split #{$splitId} already reversed.");
        }

        DB::transaction(function () use ($split, $reason, $createdBy) {
            if ($split->vehicle_wallet_id && $split->vehicle_amount > 0) {
                $this->debitWallet(
                    Wallet::findOrFail($split->vehicle_wallet_id),
                    $split->vehicle_amount,
                    null,
                    "Reversal: {$reason}",
                    $createdBy,
                );
            }
            if ($split->sacco_wallet_id && $split->sacco_amount > 0) {
                $this->debitWallet(
                    Wallet::findOrFail($split->sacco_wallet_id),
                    $split->sacco_amount,
                    null,
                    "Reversal: {$reason}",
                    $createdBy,
                );
            }
            if ($split->platform_wallet_id && $split->platform_amount > 0) {
                $this->debitWallet(
                    Wallet::findOrFail($split->platform_wallet_id),
                    $split->platform_amount,
                    null,
                    "Reversal: {$reason}",
                    $createdBy,
                );
            }
            $split->update(['status' => 'reversed']);
        });
    }

    // ── Wallet helpers ────────────────────────────────────────────────────────

    public function getOrCreateWallet(string $entityType, string $entityId): Wallet
    {
        return Wallet::firstOrCreate(
            ['entity_type' => $entityType, 'entity_id' => $entityId],
            ['balance' => 0.00, 'currency' => 'KES'],
        );
    }

    // ── Revenue summaries ─────────────────────────────────────────────────────

    public function getFleetRevenueSummary(string $agencyId, string $period = '30d'): Collection
    {
        $days = match ($period) {
            '7d'  => 7,
            '90d' => 90,
            default => 30,
        };

        return DB::table('payment_splits as ps')
            ->join('vehicles as v', 'v.id', '=', 'ps.vehicle_id')
            ->where('v.agency_id', $agencyId)
            ->where('ps.created_at', '>=', now()->subDays($days))
            ->where('ps.status', 'completed')
            ->groupBy('ps.vehicle_id', 'v.plate', 'v.route_id')
            ->select([
                'ps.vehicle_id',
                'v.plate',
                'v.route_id',
                DB::raw('SUM(ps.vehicle_amount) as total_revenue'),
                DB::raw('COUNT(*) as split_count'),
                DB::raw('MAX(ps.created_at) as last_split_at'),
            ])
            ->orderByDesc('total_revenue')
            ->get();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function creditWallet(Wallet $wallet, float $amount, ?int $splitId, string $description, ?string $createdBy): void
    {
        $newBalance = $wallet->balance + $amount;
        $wallet->update(['balance' => $newBalance, 'last_credited_at' => now()]);
        WalletTransaction::create([
            'wallet_id'     => $wallet->id,
            'type'          => 'credit',
            'amount'        => $amount,
            'balance_after' => $newBalance,
            'payment_id'    => $splitId,
            'description'   => $description,
            'created_by'    => $createdBy,
        ]);
    }

    private function debitWallet(Wallet $wallet, float $amount, ?int $splitId, string $description, ?string $createdBy): void
    {
        $newBalance = $wallet->balance - $amount;
        $wallet->update(['balance' => $newBalance]);
        WalletTransaction::create([
            'wallet_id'     => $wallet->id,
            'type'          => 'debit',
            'amount'        => $amount,
            'balance_after' => $newBalance,
            'payment_id'    => $splitId,
            'description'   => $description,
            'created_by'    => $createdBy,
        ]);
    }
}
