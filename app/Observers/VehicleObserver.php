<?php

namespace App\Observers;

use App\Models\Vehicle;
use App\Models\Wallet;

class VehicleObserver
{
    public function created(Vehicle $vehicle): void
    {
        Wallet::firstOrCreate(
            ['entity_type' => 'vehicle', 'entity_id' => (string) $vehicle->id],
            ['balance' => 0, 'currency' => 'KES']
        );
    }
}
