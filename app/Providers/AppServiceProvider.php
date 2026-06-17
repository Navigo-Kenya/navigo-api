<?php

namespace App\Providers;

use App\Models\Agency;
use App\Models\Vehicle;
use App\Observers\AgencyObserver;
use App\Observers\VehicleObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Agency::observe(AgencyObserver::class);
        Vehicle::observe(VehicleObserver::class);
    }
}
