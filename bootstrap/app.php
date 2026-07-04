<?php

use App\Http\Middleware\RequireAdminRole;
use App\Http\Middleware\ScopeToUserAgencies;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Added to fix the avatar display on frontend
        $middleware->trustProxies(at: '*');
        // $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        $middleware->statefulApi();

        // $middleware->appendToGroup('api', \Illuminate\Http\Middleware\GzipEncoding::class);
        $middleware->alias([
            'role'         => RequireAdminRole::class,
            'agency.scope' => ScopeToUserAgencies::class,
        ]);
    })
    ->booted(function () {
        RateLimiter::for('otp', fn ($r) => Limit::perMinute(3)->by($r->input('phone') ?? $r->input('phone_number') ?? $r->ip()));
        RateLimiter::for('auth', fn ($r) => Limit::perMinute(10)->by($r->ip()));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sentry crash reporting — inert until SENTRY_LARAVEL_DSN is set in .env.
        Integration::handles($exceptions);
    })->create();
