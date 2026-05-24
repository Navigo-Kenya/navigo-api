<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\RateLimiter;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Added to fix the avatar display on frontend
        $middleware->trustProxies(at: '*');
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);
    })
    ->booted(function () {
        RateLimiter::for('otp', fn ($r) => Limit::perMinute(1)->by($r->input('phone')));
        RateLimiter::for('auth', fn ($r) => Limit::perMinute(10)->by($r->ip()));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
