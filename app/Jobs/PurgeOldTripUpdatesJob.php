<?php

namespace App\Jobs;

use App\Models\TripUpdate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PurgeOldTripUpdatesJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        TripUpdate::where('recorded_at', '<', now()->subDays(90))->delete();
    }
}
