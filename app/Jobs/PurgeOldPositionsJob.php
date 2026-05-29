<?php

namespace App\Jobs;

use App\Models\VehiclePosition;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PurgeOldPositionsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        VehiclePosition::where('recorded_at', '<', now()->subDays(7))->delete();
    }
}
