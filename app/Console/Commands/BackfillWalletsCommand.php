<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\Vehicle;
use App\Models\Wallet;
use Illuminate\Console\Command;

class BackfillWalletsCommand extends Command
{
    protected $signature   = 'wallets:backfill {--dry-run : Show what would be created without writing}';
    protected $description = 'Create wallets for any agency or vehicle that does not already have one';

    public function handle(): int
    {
        $dry = $this->option('dry-run');

        // ── Agencies ──────────────────────────────────────────────────────────
        $agencies = Agency::all(['agency_id']);
        $this->info("Checking {$agencies->count()} agencies…");

        $agencyCreated = 0;
        foreach ($agencies as $agency) {
            $exists = Wallet::where('entity_type', 'agency')
                            ->where('entity_id', $agency->agency_id)
                            ->exists();
            if (!$exists) {
                if (!$dry) {
                    Wallet::create([
                        'entity_type' => 'agency',
                        'entity_id'   => $agency->agency_id,
                        'balance'     => 0,
                        'currency'    => 'KES',
                    ]);
                }
                $agencyCreated++;
            }
        }

        // ── Vehicles ──────────────────────────────────────────────────────────
        $vehicles = Vehicle::all(['id']);
        $this->info("Checking {$vehicles->count()} vehicles…");

        $vehicleCreated = 0;
        foreach ($vehicles as $vehicle) {
            $exists = Wallet::where('entity_type', 'vehicle')
                            ->where('entity_id', (string) $vehicle->id)
                            ->exists();
            if (!$exists) {
                if (!$dry) {
                    Wallet::create([
                        'entity_type' => 'vehicle',
                        'entity_id'   => (string) $vehicle->id,
                        'balance'     => 0,
                        'currency'    => 'KES',
                    ]);
                }
                $vehicleCreated++;
            }
        }

        $label = $dry ? 'Would create' : 'Created';
        $this->info("{$label} {$agencyCreated} agency wallet(s) and {$vehicleCreated} vehicle wallet(s).");

        return self::SUCCESS;
    }
}
