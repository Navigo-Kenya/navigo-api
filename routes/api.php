<?php

use App\Http\Controllers\Api\V1\AiTransitController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\RouteController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    // Geolocation & Stops
    Route::get('/stops/all',    [LocationController::class, 'all']);
    Route::get('/stops/nearby', [LocationController::class, 'nearby']);
    Route::get('/stops/search', [LocationController::class, 'search']);
    Route::get('/stops/{id}',   [LocationController::class, 'show']);

    // The Core Trip Engine Endpoint
    Route::post('/journey/calculate', [RouteController::class, 'calculate']);

    Route::post('/journey/ai-plan', [AiTransitController::class, 'planRouteWithAi']);
});
