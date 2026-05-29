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
    /**
     * Record a payment split across vehicle-owner, SACCO, and platform wallets.
     * This is the single call-site for M-Pesa payment receipt.
     */
    public function recordSplit(
        float $amount,
        int $vehicleId,
        ?string $routeId = null,
        ?string $externalRef = null,
        ?string $createdBy = 'system',
    ): PaymentSplit {
        $vehicle = Vehicle::findOrFail($vehicleId);

        // Resolve split config: agency-specific → global default
        $config = SplitConfig::where('agency_id', $vehicle->agency_id)
            ->where('is_active', true)
            ->first()
            ?? SplitConfig::whereNull('agency_id')->where('is_active', true)->firstOrFail();

        $vehicleAmount  = round($amount * ($config->vehicle_pct  / 100), 2);
        $saccoAmount    = round($amount * ($config->sacco_pct    / 100), 2);
        $platformAmount = round($amount - $vehicleAmount - $saccoAmount, 2); // avoids rounding drift

        // Ensure entity_id for vehicle wallet is its string id
        $vehicleEntityId  = (string) $vehicleId;
        $saccoEntityId    = $vehicle->agency_id ?? 'unassigned';
        $platformEntityId = 'platform';

        return DB::transaction(function () use (
            $amount, $vehicleId, $routeId, $externalRef, $createdBy,
            $config, $vehicleAmount, $saccoAmount, $platformAmount,
            $vehicleEntityId, $saccoEntityId, $platformEntityId,
        ) {
            $vehicleWallet  = $this->getOrCreateWallet('vehicle',  $vehicleEntityId);
            $saccoWallet    = $this->getOrCreateWallet('agency',   $saccoEntityId);
            $platformWallet = $this->getOrCreateWallet('platform', $platformEntityId);

            $split = PaymentSplit::create([
                'external_ref'      => $externalRef,
                'amount_total'      => $amount,
                'vehicle_wallet_id' => $vehicleWallet->id,
                'sacco_wallet_id'   => $saccoWallet->id,
                'platform_wallet_id'=> $platformWallet->id,
                'vehicle_amount'    => $vehicleAmount,
                'sacco_amount'      => $saccoAmount,
                'platform_amount'   => $platformAmount,
                'split_config_id'   => $config->id,
                'route_id'          => $routeId,
                'vehicle_id'        => $vehicleId,
                'status'            => 'completed',
            ]);

            $this->creditWallet($vehicleWallet,  $vehicleAmount,  $split->id, "Vehicle owner share", $createdBy);
            $this->creditWallet($saccoWallet,    $saccoAmount,    $split->id, "SACCO management share", $createdBy);
            $this->creditWallet($platformWallet, $platformAmount, $split->id, "Platform commission", $createdBy);

            return $split;
        });
    }

    public function reversePaymentSplit(int $splitId, string $reason, string $createdBy = 'system'): void
    {
        $split = PaymentSplit::findOrFail($splitId);
        if ($split->status === 'reversed') {
            throw new \RuntimeException("Split #{$splitId} already reversed.");
        }

        DB::transaction(function () use ($split, $reason, $createdBy) {
            $this->debitWallet(Wallet::find($split->vehicle_wallet_id),  $split->vehicle_amount,  null, "Reversal: {$reason}", $createdBy);
            $this->debitWallet(Wallet::find($split->sacco_wallet_id),    $split->sacco_amount,    null, "Reversal: {$reason}", $createdBy);
            $this->debitWallet(Wallet::find($split->platform_wallet_id), $split->platform_amount, null, "Reversal: {$reason}", $createdBy);
            $split->update(['status' => 'reversed']);
        });
    }

    public function getOrCreateWallet(string $entityType, string $entityId): Wallet
    {
        return Wallet::firstOrCreate(
            ['entity_type' => $entityType, 'entity_id' => $entityId],
            ['balance' => 0.00, 'currency' => 'KES'],
        );
    }

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

    private function creditWallet(Wallet $wallet, float $amount, int $splitId, string $description, ?string $createdBy): void
    {
        $newBalance = $wallet->balance + $amount;
        $wallet->update(['balance' => $newBalance, 'last_credited_at' => now()]);
        WalletTransaction::create([
            'wallet_id'    => $wallet->id,
            'type'         => 'credit',
            'amount'       => $amount,
            'balance_after'=> $newBalance,
            'payment_id'   => $splitId,
            'description'  => $description,
            'created_by'   => $createdBy,
        ]);
    }

    private function debitWallet(Wallet $wallet, float $amount, ?int $splitId, string $description, ?string $createdBy): void
    {
        $newBalance = $wallet->balance - $amount;
        $wallet->update(['balance' => $newBalance]);
        WalletTransaction::create([
            'wallet_id'    => $wallet->id,
            'type'         => 'debit',
            'amount'       => $amount,
            'balance_after'=> $newBalance,
            'payment_id'   => $splitId,
            'description'  => $description,
            'created_by'   => $createdBy,
        ]);
    }
}
