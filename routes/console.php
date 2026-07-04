<?php

use App\Console\Commands\SendComplianceAlertsCommand;
use App\Jobs\CheckHeadwaySlaJob;
use App\Jobs\CheckIncidentEscalationsJob;
use App\Jobs\ComputeOnTimePerformanceJob;
use App\Jobs\PurgeOldPositionsJob;
use App\Jobs\PurgeOldTripUpdatesJob;
use App\Jobs\SendMorningBriefingJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

// Morning commute briefing (weekdays only — it's a commute product).
Schedule::job(new SendMorningBriefingJob)->weekdays()->dailyAt('06:30')->timezone('Africa/Nairobi');

// Run every 5 minutes to clean up expired map reports
Schedule::call(function () {
    DB::table('transit_reports')
        ->where('status', 'active')
        ->where('expires_at', '<', now())
        ->update(['status' => 'expired']);
})->everyFiveMinutes();
