<?php

use App\Http\Controllers\Api\V1\AiTransitController;
use App\Http\Controllers\Api\V1\KwameTtsController;
use App\Http\Controllers\Api\V1\AlertsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CoverageController;
use App\Http\Controllers\Api\V1\DriverPositionController;
use App\Http\Controllers\Console\ConsoleAlertsController;
use App\Http\Controllers\Api\V1\CommunityController;
use App\Http\Controllers\Api\V1\ContributionController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OAuthController;
use App\Http\Controllers\Api\V1\PhoneController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\JourneyFeedbackController;
use App\Http\Controllers\Api\V1\KwameSttController;
use App\Http\Controllers\Api\V1\KwameMemoryController;
use App\Http\Controllers\Api\V1\RouteController;
use App\Http\Controllers\Api\V1\SavedJourneysController;
use App\Http\Controllers\Api\V1\SavedPlacesController;
use App\Http\Controllers\Api\V1\SettingsController;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // ── Auth (public) ──────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('register',        [AuthController::class, 'register']);
        Route::post('login',           [AuthController::class, 'login'])->middleware('throttle:auth');
        Route::post('google',          [OAuthController::class, 'google']);
        Route::post('apple',           [OAuthController::class, 'apple']);
        Route::post('phone/set',       [PhoneController::class, 'set'])->middleware('throttle:otp');
        Route::post('phone/send',      [PhoneController::class, 'send'])->middleware('throttle:otp');
        Route::post('phone/verify',    [PhoneController::class, 'verify']);
        Route::post('password/forgot', [AuthController::class, 'forgotPassword']);
        Route::post('password/reset',  [AuthController::class, 'resetPassword']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me',           [AuthController::class, 'me']);
            Route::patch('profile',    [AuthController::class, 'updateProfile']);
            Route::get('settings',     [SettingsController::class, 'show']);
            Route::patch('settings',   [SettingsController::class, 'update']);
            Route::post('avatar',      [AuthController::class, 'updateAvatar']);
            Route::post('logout',      [AuthController::class, 'logout']);

            // Device tokens (push notification registration)
            Route::post('device-tokens',   [DeviceTokenController::class, 'store']);
            Route::delete('device-tokens', [DeviceTokenController::class, 'destroy']);

            // Notification inbox
            Route::get('notifications',                      [NotificationController::class, 'index']);
            Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead']);
            Route::post('notifications/mark-all-read',       [NotificationController::class, 'markAllRead']);
            Route::get('notifications/unread-count',         [NotificationController::class, 'unreadCount']);
        });
    });

    // ── Public coverage data (website) ────────────────────────────
    Route::get('/coverage', [CoverageController::class, 'index'])->middleware('throttle:60,1');

    // ── Geolocation & Stops ────────────────────────────────────────
    Route::get('/stops/all',    [LocationController::class, 'all']);
    Route::get('/stops/nearby', [LocationController::class, 'nearby']);
    Route::get('/stops/search', [LocationController::class, 'search']);
    Route::get('/stops/{id}',         [LocationController::class, 'show']);
    Route::get('/stops/{id}/reviews', [LocationController::class, 'stopReviews']);
    Route::get('/stops/{id}/photos',  [LocationController::class, 'stopPhotos']);

    // ── Journey ────────────────────────────────────────────────────
    Route::post('/journey/calculate', [RouteController::class, 'calculate']);
    Route::post('/journey/ai-plan',   [AiTransitController::class, 'planRouteWithAi']);
    Route::post('/journey/feedback',  [JourneyFeedbackController::class, 'store'])->middleware('throttle:60,1');

    // ── Kwame TTS ──────────────────────────────────────────────────
    Route::post('/kwame/speak', [KwameTtsController::class, 'speak']);

    // ── Kwame STT ──────────────────────────────────────────────────
    Route::post('/kwame/transcribe', [KwameSttController::class, 'transcribe']);

    // ── Community (public) ─────────────────────────────────────────
    Route::get('/contributions/nearby',    [ContributionController::class, 'nearby']);
    Route::get('/community/leaderboard',   [CommunityController::class, 'leaderboard']);


    // ── User data ──────────────────────────────────────────────────
    Route::prefix('user')->middleware('auth:sanctum')->group(function () {
        Route::get('saved-places',           [SavedPlacesController::class, 'index']);
        Route::post('saved-places',          [SavedPlacesController::class, 'store']);
        Route::delete('saved-places/{id}',   [SavedPlacesController::class, 'destroy']);

        Route::get('saved-journeys',         [SavedJourneysController::class, 'index']);
        Route::post('saved-journeys',        [SavedJourneysController::class, 'store']);
        Route::delete('saved-journeys/{id}', [SavedJourneysController::class, 'destroy']);

        Route::get('contributions',          [ContributionController::class, 'index']);
        Route::post('contributions',         [ContributionController::class, 'store']);
        Route::delete('contributions/{id}',  [ContributionController::class, 'destroy']);
        Route::patch('contributions/{id}',   [ContributionController::class, 'update']);

        Route::get('community/stats',        [CommunityController::class, 'stats']);
        Route::get('badges',                 [CommunityController::class, 'badges']);

        // ── Kwame persistent memory (transparency) ─────────────────
        Route::get('kwame-memory',           [KwameMemoryController::class, 'index']);
        Route::delete('kwame-memory',        [KwameMemoryController::class, 'destroyAll']);
        Route::delete('kwame-memory/{id}',   [KwameMemoryController::class, 'destroy']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/contributions/{id}/vote', [ContributionController::class, 'vote']);
        Route::post('driver/position', [DriverPositionController::class, 'store']);
    });

    // ── Community alerts (JSON) ────────────────────────────────────
    Route::get('/alerts', [AlertsController::class, 'index']);

    // ── OTP warm-up ────────────────────────────────────────────────
    Route::get('/otp/warmup', [RouteController::class, 'warmup']);

    // ── Public GTFS-RT feeds ───────────────────────────────────────
    Route::get('gtfs-rt/alerts', [ConsoleAlertsController::class, 'gtfsRtFeed']);


    // ── Map reports (public) ───────────────────────────────────────
    Route::get('/reports/viewport',    [ReportController::class, 'viewport']);
    Route::post('/reports',            [ReportController::class, 'store']);
    Route::post('/reports/{id}/vote',  [ReportController::class, 'vote']);

    // ── [DEBUG] SMS test — REMOVE before going live ────────────────
    Route::get('/debug/sms', function () {
        $sandbox  = (bool) config('services.africastalking.sandbox');
        $apiKey   = config('services.africastalking.api_key', '');
        $username = config('services.africastalking.username', '');
        $senderId = config('services.africastalking.sender_id', 'HOPLN');
        $to       = '+254712345678';

        $config = [
            'sandbox'   => $sandbox,
            'username'  => $username,
            'sender_id' => $senderId,
            'api_key'   => empty($apiKey) ? '(empty)' : substr($apiKey, 0, 8) . '...',
            'base_url'  => $sandbox
                ? 'https://api.sandbox.africastalking.com/'
                : 'https://api.africastalking.com/',
        ];

        if (empty($apiKey)) {
            return response()->json(['error' => 'API key is empty — check AT_API_KEY in .env', 'config' => $config], 500);
        }

        try {
            $client   = new Client(['base_uri' => $config['base_url']]);
            $response = $client->post('version1/messaging', [
                'headers' => [
                    'apiKey'       => $apiKey,
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    'username' => $username,
                    'to'       => $to,
                    'message'  => 'Navigo SMS test. If you received this, AT is working.',
                    // 'from'     => $senderId,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            return response()->json([
                'http_status' => $response->getStatusCode(),
                'at_response' => $body,
                'config'      => $config,
            ]);

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            return response()->json([
                'error'       => 'HTTP client error',
                'http_status' => $e->getResponse()->getStatusCode(),
                'at_response' => json_decode($e->getResponse()->getBody()->getContents(), true),
                'config'      => $config,
            ], 502);
        } catch (\Throwable $e) {
            return response()->json([
                'error'  => $e->getMessage(),
                'config' => $config,
            ], 500);
        }
    });
});
