<?php

namespace App\Observers;

use App\Models\Agency;
use App\Models\Wallet;

class AgencyObserver
{
    public function created(Agency $agency): void
    {
        Wallet::firstOrCreate(
            ['entity_type' => 'agency', 'entity_id' => $agency->agency_id],
            ['balance' => 0, 'currency' => 'KES']
        );
    }
}
