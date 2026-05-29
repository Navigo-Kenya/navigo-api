<?php

use App\Jobs\ComputeOnTimePerformanceJob;
use App\Jobs\PurgeOldPositionsJob;
use App\Jobs\PurgeOldTripUpdatesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new ComputeOnTimePerformanceJob)->dailyAt('01:00');
Schedule::job(new PurgeOldPositionsJob)->dailyAt('02:00');
Schedule::job(new PurgeOldTripUpdatesJob)->dailyAt('02:30');
