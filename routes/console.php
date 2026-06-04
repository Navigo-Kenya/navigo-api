<?php

use App\Console\Commands\SendComplianceAlertsCommand;
use App\Jobs\CheckHeadwaySlaJob;
use App\Jobs\CheckIncidentEscalationsJob;
use App\Jobs\ComputeOnTimePerformanceJob;
use App\Jobs\PurgeOldPositionsJob;
use App\Jobs\PurgeOldTripUpdatesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(SendComplianceAlertsCommand::class)->dailyAt('08:00')->timezone('Africa/Nairobi');
Schedule::job(new CheckHeadwaySlaJob)->everyFiveMinutes();
Schedule::job(new CheckIncidentEscalationsJob)->everyFifteenMinutes();
Schedule::job(new ComputeOnTimePerformanceJob)->dailyAt('01:00');
Schedule::job(new PurgeOldPositionsJob)->dailyAt('02:00');
Schedule::job(new PurgeOldTripUpdatesJob)->dailyAt('02:30');
