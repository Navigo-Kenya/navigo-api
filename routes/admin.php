<?php

use App\Http\Controllers\Console\AnalyticsController;
use App\Http\Controllers\Console\BadgeController;
use App\Http\Controllers\Console\ConsoleAlertsController;
use App\Http\Controllers\Console\ConsoleDataQualityController;
use App\Http\Controllers\Console\BroadcastNotificationController;
use App\Http\Controllers\Console\ConsoleAgencyController;
use App\Http\Controllers\Console\ConsoleContributionController;
use App\Http\Controllers\Console\ConsoleCorridorController;
use App\Http\Controllers\Console\ConsoleDriverController;
use App\Http\Controllers\Console\ConsoleGtfsController;
use App\Http\Controllers\Console\ConsoleFareController;
use App\Http\Controllers\Console\ConsoleIncidentController;
use App\Http\Controllers\Console\ConsoleInteropController;
use App\Http\Controllers\Console\ConsoleLedgerController;
use App\Http\Controllers\Console\ConsoleNetworkController;
use App\Http\Controllers\Console\ConsoleRealTimeController;
use App\Http\Controllers\Console\ConsoleRouteController;
use App\Http\Controllers\Console\ConsoleRoutePatternController;
use App\Http\Controllers\Console\ConsoleScenarioController;
use App\Http\Controllers\Console\ConsoleSchedulingController;
use App\Http\Controllers\Console\ConsoleServiceCalendarController;
use App\Http\Controllers\Console\ConsoleStopController;
use App\Http\Controllers\Console\ConsoleTripController;
use App\Http\Controllers\Console\ConsoleTripFrequencyController;
use App\Http\Controllers\Console\ConsoleUserController;
use App\Http\Controllers\Console\ConsoleVehicleController;
use App\Http\Controllers\Console\DashboardController;
use App\Http\Controllers\Console\OtpController;
use Illuminate\Support\Facades\Route;

// ── Hopln Console API ─────────────────────────────────────────────────────────
// All routes require authentication + moderator role minimum.
// Sensitive actions (OTP sync, delete, export) enforce superadmin via middleware
// or inline role checks inside the controller.
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('v1/console')
    ->middleware(['auth:sanctum', 'role'])
    ->group(function () {

        // ── Dashboard ──────────────────────────────────────────────────────────
        Route::get('dashboard',    [DashboardController::class, 'overview']);
        Route::get('activity',     [DashboardController::class, 'activity']);
        Route::get('system-health', [DashboardController::class, 'systemHealth']);

        // ── Users ──────────────────────────────────────────────────────────────
        Route::get('users/export',           [ConsoleUserController::class, 'export']);
        Route::get('users',                  [ConsoleUserController::class, 'index']);
        Route::get('users/{id}',             [ConsoleUserController::class, 'show']);
        Route::patch('users/{id}',           [ConsoleUserController::class, 'update']);
        Route::post('users/{id}/ban',        [ConsoleUserController::class, 'ban']);
        Route::post('users/{id}/unban',      [ConsoleUserController::class, 'unban']);
        Route::patch('users/{id}/points',    [ConsoleUserController::class, 'adjustPoints']);
        Route::post('users/{id}/badges',     [ConsoleUserController::class, 'awardBadge']);
        Route::delete('users/{userId}/badges/{badgeId}', [ConsoleUserController::class, 'revokeBadge']);

        // ── Contributions ──────────────────────────────────────────────────────
        Route::get('contributions',                    [ConsoleContributionController::class, 'index']);
        Route::get('contributions/{id}',               [ConsoleContributionController::class, 'show']);
        Route::patch('contributions/{id}',             [ConsoleContributionController::class, 'update']);
        Route::post('contributions/{id}/approve',      [ConsoleContributionController::class, 'approve']);
        Route::post('contributions/{id}/decline',      [ConsoleContributionController::class, 'decline']);
        Route::post('contributions/bulk-approve',      [ConsoleContributionController::class, 'bulkApprove']);
        Route::post('contributions/bulk-decline',      [ConsoleContributionController::class, 'bulkDecline']);

        // ── Stops ──────────────────────────────────────────────────────────────
        Route::get('stops',          [ConsoleStopController::class, 'index']);
        Route::post('stops',         [ConsoleStopController::class, 'store'])->middleware('role:admin,superadmin');
        Route::get('stops/{id}',     [ConsoleStopController::class, 'show']);
        Route::patch('stops/{id}',   [ConsoleStopController::class, 'update'])->middleware('role:admin,superadmin');
        Route::delete('stops/{id}',  [ConsoleStopController::class, 'destroy'])->middleware('role:admin,superadmin');
        Route::post('stops/{id}/stop-times', [ConsoleStopController::class, 'storeStopTime'])->middleware('role:admin,superadmin');

        // ── Routes ─────────────────────────────────────────────────────────────
        Route::get('routes',                            [ConsoleRouteController::class, 'index']);
        Route::post('routes',                           [ConsoleRouteController::class, 'store'])->middleware('role:admin,superadmin');
        Route::post('routes/stops-near-line',           [ConsoleRouteController::class, 'stopsNearLine']);
        Route::get('routes/{id}',                       [ConsoleRouteController::class, 'show']);
        Route::patch('routes/{id}',                     [ConsoleRouteController::class, 'update'])->middleware('role:admin,superadmin');
        Route::patch('routes/{id}/stop-sequence',       [ConsoleRouteController::class, 'updateStopSequence'])->middleware('role:admin,superadmin');
        Route::put('routes/{id}/shape',                 [ConsoleRouteController::class, 'saveShape'])->middleware('role:admin,superadmin');
        Route::put('routes/{id}/trip-stops',            [ConsoleRouteController::class, 'saveTripStops'])->middleware('role:admin,superadmin');
        Route::delete('routes/{id}',                    [ConsoleRouteController::class, 'destroy'])->middleware('role:admin,superadmin');

        // ── Trips ──────────────────────────────────────────────────────────────
        Route::get('trips',                            [ConsoleTripController::class, 'index']);
        Route::post('trips',                           [ConsoleTripController::class, 'store'])->middleware('role:admin,superadmin');
        Route::post('trips/stops-near-line',           [ConsoleTripController::class, 'stopsNearLine']);
        Route::get('trips/{id}',                       [ConsoleTripController::class, 'show']);
        Route::patch('trips/{id}',                     [ConsoleTripController::class, 'update'])->middleware('role:admin,superadmin');
        Route::put('trips/{id}/shape',                 [ConsoleTripController::class, 'saveShape'])->middleware('role:admin,superadmin');
        Route::put('trips/{id}/stop-times',            [ConsoleTripController::class, 'saveStopTimes'])->middleware('role:admin,superadmin');
        Route::delete('trips/{id}',                    [ConsoleTripController::class, 'destroy'])->middleware('role:admin,superadmin');

        // ── Trip Frequencies ──────────────────────────────────────────────────
        Route::get('trips/{tripId}/frequencies',         [ConsoleTripFrequencyController::class, 'index']);
        Route::post('trips/{tripId}/frequencies',        [ConsoleTripFrequencyController::class, 'store'])->middleware('role:admin,superadmin');
        Route::patch('trips/{tripId}/frequencies/{id}',  [ConsoleTripFrequencyController::class, 'update'])->middleware('role:admin,superadmin');
        Route::delete('trips/{tripId}/frequencies/{id}', [ConsoleTripFrequencyController::class, 'destroy'])->middleware('role:admin,superadmin');

        // ── Agencies ──────────────────────────────────────────────────────────
        Route::get('agencies',          [ConsoleAgencyController::class, 'index']);
        Route::post('agencies',         [ConsoleAgencyController::class, 'store'])->middleware('role:admin,superadmin');
        Route::patch('agencies/{id}',   [ConsoleAgencyController::class, 'update'])->middleware('role:admin,superadmin');
        Route::delete('agencies/{id}',  [ConsoleAgencyController::class, 'destroy'])->middleware('role:admin,superadmin');

        // ── Service Calendars ─────────────────────────────────────────────────
        Route::get('service-calendars',                              [ConsoleServiceCalendarController::class, 'index']);
        Route::post('service-calendars',                             [ConsoleServiceCalendarController::class, 'store'])->middleware('role:admin,superadmin');
        Route::get('service-calendars/{id}',                         [ConsoleServiceCalendarController::class, 'show']);
        Route::patch('service-calendars/{id}',                       [ConsoleServiceCalendarController::class, 'update'])->middleware('role:admin,superadmin');
        Route::delete('service-calendars/{id}',                      [ConsoleServiceCalendarController::class, 'destroy'])->middleware('role:admin,superadmin');
        Route::post('service-calendars/{id}/exceptions',             [ConsoleServiceCalendarController::class, 'addException'])->middleware('role:admin,superadmin');
        Route::delete('service-calendars/{id}/exceptions/{eid}',     [ConsoleServiceCalendarController::class, 'removeException'])->middleware('role:admin,superadmin');
        Route::post('service-calendars/{id}/exceptions/bulk',        [ConsoleServiceCalendarController::class, 'bulkExceptions'])->middleware('role:admin,superadmin');

        // ── Route Patterns ────────────────────────────────────────────────────
        Route::get('route-patterns',             [ConsoleRoutePatternController::class, 'index']);
        Route::post('route-patterns',            [ConsoleRoutePatternController::class, 'store'])->middleware('role:admin,superadmin');
        Route::patch('route-patterns/{id}',      [ConsoleRoutePatternController::class, 'update'])->middleware('role:admin,superadmin');
        Route::put('route-patterns/{id}/stops',  [ConsoleRoutePatternController::class, 'saveStops'])->middleware('role:admin,superadmin');
        Route::delete('route-patterns/{id}',     [ConsoleRoutePatternController::class, 'destroy'])->middleware('role:admin,superadmin');

        // ── GTFS Operations ───────────────────────────────────────────────────
        Route::get('gtfs/status',             [ConsoleGtfsController::class, 'status']);
        Route::post('gtfs/validate',          [ConsoleGtfsController::class, 'validate'])->middleware('role:admin,superadmin');
        Route::post('gtfs/export',            [ConsoleGtfsController::class, 'export'])->middleware('role:superadmin');
        Route::get('gtfs/official-validate',  [ConsoleGtfsController::class, 'officialValidate'])->middleware('role:admin,superadmin');
        Route::post('gtfs/export-as',         [ConsoleGtfsController::class, 'exportAs'])->middleware('role:admin,superadmin');

        // ── Data Quality ──────────────────────────────────────────────────────
        Route::get('quality/score',           [ConsoleDataQualityController::class, 'score']);
        Route::get('quality/drill-down',      [ConsoleDataQualityController::class, 'drillDown']);
        Route::get('quality/shape-inspector', [ConsoleDataQualityController::class, 'shapeInspector']);
        Route::get('quality/duplicate-stops', [ConsoleDataQualityController::class, 'duplicateStops']);
        Route::post('quality/merge-stops',    [ConsoleDataQualityController::class, 'mergeStops'])->middleware('role:admin,superadmin');
        Route::post('stops/{id}/snap',        [ConsoleDataQualityController::class, 'snapStop'])->middleware('role:admin,superadmin');

        // ── Network Analysis ──────────────────────────────────────────────────
        Route::get('network/graph',          [ConsoleNetworkController::class, 'graph']);
        Route::get('network/coverage',       [ConsoleNetworkController::class, 'coverage']);
        Route::post('network/isochrone',     [ConsoleNetworkController::class, 'isochrone']);
        Route::get('network/desire-lines',   [ConsoleNetworkController::class, 'desireLines']);
        Route::get('network/transfer-graph', [ConsoleNetworkController::class, 'transferGraph']);
        Route::get('network/snapshots',      [ConsoleNetworkController::class, 'snapshots']);
        Route::post('network/snapshots',     [ConsoleNetworkController::class, 'createSnapshot'])->middleware('role:admin,superadmin');
        Route::get('network/snapshots/{id}', [ConsoleNetworkController::class, 'showSnapshot']);

        // ── Corridors ─────────────────────────────────────────────────────────
        Route::get('corridors',                      [ConsoleCorridorController::class, 'index']);
        Route::post('corridors',                     [ConsoleCorridorController::class, 'store'])->middleware('role:admin,superadmin');
        Route::get('corridors/{id}',                 [ConsoleCorridorController::class, 'show']);
        Route::patch('corridors/{id}',               [ConsoleCorridorController::class, 'update'])->middleware('role:admin,superadmin');
        Route::put('corridors/{id}/shape',           [ConsoleCorridorController::class, 'saveShape'])->middleware('role:admin,superadmin');
        Route::delete('corridors/{id}',              [ConsoleCorridorController::class, 'destroy'])->middleware('role:admin,superadmin');
        Route::get('corridors/{id}/routes',          [ConsoleCorridorController::class, 'routesNearCorridor']);
        Route::post('corridors/{id}/routes',         [ConsoleCorridorController::class, 'attachRoute'])->middleware('role:admin,superadmin');
        Route::delete('corridors/{id}/routes/{rid}', [ConsoleCorridorController::class, 'detachRoute'])->middleware('role:admin,superadmin');

        // ── Scenarios ─────────────────────────────────────────────────────────
        Route::get('scenarios',                          [ConsoleScenarioController::class, 'index']);
        Route::post('scenarios',                         [ConsoleScenarioController::class, 'store'])->middleware('role:admin,superadmin');
        Route::get('scenarios/{id}',                     [ConsoleScenarioController::class, 'show']);
        Route::patch('scenarios/{id}',                   [ConsoleScenarioController::class, 'update'])->middleware('role:admin,superadmin');
        Route::delete('scenarios/{id}',                  [ConsoleScenarioController::class, 'destroy'])->middleware('role:admin,superadmin');
        Route::post('scenarios/{id}/overrides',          [ConsoleScenarioController::class, 'addOverride'])->middleware('role:admin,superadmin');
        Route::delete('scenarios/{id}/overrides/{oid}',  [ConsoleScenarioController::class, 'removeOverride'])->middleware('role:admin,superadmin');
        Route::get('scenarios/{id}/compare',             [ConsoleScenarioController::class, 'compare']);
        Route::post('scenarios/{id}/publish',            [ConsoleScenarioController::class, 'publish'])->middleware('role:superadmin');

        // ── Timetable ─────────────────────────────────────────────────────────
        Route::get('routes/{id}/timetable', [ConsoleSchedulingController::class, 'timetable']);
        Route::put('routes/{id}/timetable', [ConsoleSchedulingController::class, 'saveTimetable'])->middleware('role:admin,superadmin');

        // ── Scheduling Tools ──────────────────────────────────────────────────
        Route::post('scheduling/optimize-headway', [ConsoleSchedulingController::class, 'optimizeHeadway'])->middleware('role:admin,superadmin');
        Route::get('scheduling/layover-analysis',  [ConsoleSchedulingController::class, 'layoverAnalysis']);
        Route::get('scheduling/blocks',            [ConsoleSchedulingController::class, 'blocks']);

        // ── OTP ────────────────────────────────────────────────────────────────
        Route::get('otp/status',   [OtpController::class, 'status']);
        Route::get('otp/log',      [OtpController::class, 'log']);
        Route::post('otp/sync',    [OtpController::class, 'sync']);
        Route::post('otp/cancel',  [OtpController::class, 'cancel']);

        // ── Analytics ──────────────────────────────────────────────────────────
        Route::get('analytics/overview',      [AnalyticsController::class, 'overview']);
        Route::get('analytics/journeys',      [AnalyticsController::class, 'journeys']);
        Route::get('analytics/searches',      [AnalyticsController::class, 'searches']);
        Route::get('analytics/contributions', [AnalyticsController::class, 'contributions']);
        Route::get('analytics/user-growth',   [AnalyticsController::class, 'userGrowth']);

        // ── Broadcast Notifications ────────────────────────────────────────────
        Route::get('notifications',            [BroadcastNotificationController::class, 'index']);
        Route::post('notifications/broadcast', [BroadcastNotificationController::class, 'broadcast'])
            ->middleware('role:admin,superadmin');

        // ── Badges ─────────────────────────────────────────────────────────────
        Route::get('badges',          [BadgeController::class, 'index']);
        Route::post('badges',         [BadgeController::class, 'store'])->middleware('role:admin,superadmin');
        Route::patch('badges/{id}',   [BadgeController::class, 'update'])->middleware('role:admin,superadmin');
        Route::delete('badges/{id}',  [BadgeController::class, 'destroy'])->middleware('role:admin,superadmin');

        // ── Feature 29 — Multi-Modal Layers ───────────────────────────────────
        Route::get('network/modal-layers',              [ConsoleNetworkController::class, 'modalLayers']);
        Route::post('network/modal-layers/refresh-osm', [ConsoleNetworkController::class, 'refreshOsmLayer'])->middleware('role:admin,superadmin');

        // ── Feature 31 — Multi-Agency ─────────────────────────────────────────
        Route::get('network/agencies',               [ConsoleNetworkController::class, 'agencies']);
        Route::get('network/cross-agency-transfers', [ConsoleNetworkController::class, 'crossAgencyTransfers']);

        // ── Feature 30 — Fare Zones ───────────────────────────────────────────
        Route::get('fares/zones',          [ConsoleFareController::class, 'zones']);
        Route::post('fares/zones',         [ConsoleFareController::class, 'storeZone'])->middleware('role:admin,superadmin');
        Route::patch('fares/zones/{id}',   [ConsoleFareController::class, 'updateZone'])->middleware('role:admin,superadmin');
        Route::delete('fares/zones/{id}',  [ConsoleFareController::class, 'destroyZone'])->middleware('role:admin,superadmin');
        Route::get('fares/attributes',        [ConsoleFareController::class, 'fareAttributes']);
        Route::post('fares/attributes',       [ConsoleFareController::class, 'saveFareAttribute'])->middleware('role:admin,superadmin');
        Route::delete('fares/attributes/{id}',[ConsoleFareController::class, 'deleteFareAttribute'])->middleware('role:admin,superadmin');
        Route::get('fares/rules',        [ConsoleFareController::class, 'fareRules']);
        Route::post('fares/rules',       [ConsoleFareController::class, 'saveFareRule'])->middleware('role:admin,superadmin');
        Route::delete('fares/rules/{id}',[ConsoleFareController::class, 'deleteFareRule'])->middleware('role:admin,superadmin');
        Route::get('fares/preview',      [ConsoleFareController::class, 'previewFare']);
        Route::get('fares/export',       [ConsoleFareController::class, 'exportFareFiles'])->middleware('role:admin,superadmin');
        Route::get('fares/route-fares',          [ConsoleFareController::class, 'routeBasedFares']);
        Route::post('fares/route-fares',         [ConsoleFareController::class, 'saveRouteFare'])->middleware('role:admin,superadmin');
        Route::delete('fares/route-fares/{id}',  [ConsoleFareController::class, 'deleteRouteFare'])->middleware('role:admin,superadmin');
        Route::get('fares/modifiers',                      [ConsoleFareController::class, 'fareModifiers']);
        Route::post('fares/modifiers',                     [ConsoleFareController::class, 'saveFareModifier'])->middleware('role:admin,superadmin');
        Route::patch('fares/modifiers/{id}',               [ConsoleFareController::class, 'updateFareModifier'])->middleware('role:admin,superadmin');
        Route::delete('fares/modifiers/{id}',              [ConsoleFareController::class, 'deleteFareModifier'])->middleware('role:admin,superadmin');
        Route::post('fares/modifiers/{id}/toggle',         [ConsoleFareController::class, 'toggleModifier'])->middleware('role:admin,superadmin');

        // ── Feature 32 — Interop Registry + Levels + Pathways ─────────────────
        Route::get('network/interop',         [ConsoleInteropController::class, 'index']);
        Route::post('network/interop',        [ConsoleInteropController::class, 'store'])->middleware('role:admin,superadmin');
        Route::patch('network/interop/{id}',  [ConsoleInteropController::class, 'update'])->middleware('role:admin,superadmin');
        Route::delete('network/interop/{id}', [ConsoleInteropController::class, 'destroy'])->middleware('role:admin,superadmin');
        Route::get('network/levels',         [ConsoleInteropController::class, 'levels']);
        Route::post('network/levels',        [ConsoleInteropController::class, 'storeLevel'])->middleware('role:admin,superadmin');
        Route::patch('network/levels/{id}',  [ConsoleInteropController::class, 'updateLevel'])->middleware('role:admin,superadmin');
        Route::delete('network/levels/{id}', [ConsoleInteropController::class, 'destroyLevel'])->middleware('role:admin,superadmin');
        Route::get('network/pathways/export',    [ConsoleInteropController::class, 'exportPathwayFiles'])->middleware('role:admin,superadmin');
        Route::get('network/pathways',           [ConsoleInteropController::class, 'pathways']);
        Route::post('network/pathways',          [ConsoleInteropController::class, 'storePathway'])->middleware('role:admin,superadmin');
        Route::patch('network/pathways/{id}',    [ConsoleInteropController::class, 'updatePathway'])->middleware('role:admin,superadmin');
        Route::delete('network/pathways/{id}',   [ConsoleInteropController::class, 'destroyPathway'])->middleware('role:admin,superadmin');

        // ── Fleet — Vehicles & Drivers ─────────────────────────────────────────
        Route::get('vehicles',         [ConsoleVehicleController::class, 'index']);
        Route::post('vehicles',        [ConsoleVehicleController::class, 'store'])->middleware('role:admin,superadmin');
        Route::patch('vehicles/{id}',  [ConsoleVehicleController::class, 'update'])->middleware('role:admin,superadmin');
        Route::delete('vehicles/{id}', [ConsoleVehicleController::class, 'destroy'])->middleware('role:admin,superadmin');

        Route::get('drivers',          [ConsoleDriverController::class, 'index']);
        Route::post('drivers',         [ConsoleDriverController::class, 'store'])->middleware('role:admin,superadmin');
        Route::patch('drivers/{id}',   [ConsoleDriverController::class, 'update'])->middleware('role:admin,superadmin');
        Route::delete('drivers/{id}',  [ConsoleDriverController::class, 'destroy'])->middleware('role:admin,superadmin');

        // ── Ledger & Clearinghouse ─────────────────────────────────────────────
        Route::get('ledger/split-configs',             [ConsoleLedgerController::class, 'splitConfigs']);
        Route::post('ledger/split-configs',            [ConsoleLedgerController::class, 'saveSplitConfig'])->middleware('role:superadmin');
        Route::get('ledger/wallets',                   [ConsoleLedgerController::class, 'wallets']);
        Route::get('ledger/wallets/{id}/transactions', [ConsoleLedgerController::class, 'walletTransactions']);
        Route::get('ledger/fleet-revenue',             [ConsoleLedgerController::class, 'fleetRevenue']);
        Route::get('ledger/vehicles/{id}/revenue',     [ConsoleLedgerController::class, 'vehicleRevenue']);
        Route::get('ledger/route-revenue',             [ConsoleLedgerController::class, 'routeRevenue']);
        Route::post('ledger/test-split',               [ConsoleLedgerController::class, 'testSplit'])->middleware('role:superadmin');

        // ── Real-Time Operations ───────────────────────────────────────────────
        Route::get('ops/live/positions',              [ConsoleRealTimeController::class, 'livePositions']);
        Route::get('ops/live/ghost-trips',            [ConsoleRealTimeController::class, 'ghostTrips']);
        Route::get('ops/live/stats',                  [ConsoleRealTimeController::class, 'liveStats']);
        Route::get('ops/performance',                 [ConsoleRealTimeController::class, 'delayDashboard']);
        Route::get('ops/performance/heatmap',         [ConsoleRealTimeController::class, 'delayHeatmap']);
        Route::get('ops/performance/worst-routes',    [ConsoleRealTimeController::class, 'worstRoutes']);
        Route::get('ops/positions/history',           [ConsoleRealTimeController::class, 'positionHistory']);
        Route::get('ops/positions/dates/{vehicleId}', [ConsoleRealTimeController::class, 'availableDates']);

        // ── Service Alerts ─────────────────────────────────────────────────────
        Route::get('ops/alerts',                [ConsoleAlertsController::class, 'index']);
        Route::post('ops/alerts',               [ConsoleAlertsController::class, 'store'])->middleware('role:admin,superadmin');
        Route::patch('ops/alerts/{id}',         [ConsoleAlertsController::class, 'update'])->middleware('role:admin,superadmin');
        Route::post('ops/alerts/{id}/activate', [ConsoleAlertsController::class, 'activate'])->middleware('role:admin,superadmin');
        Route::post('ops/alerts/{id}/expire',   [ConsoleAlertsController::class, 'expire'])->middleware('role:admin,superadmin');
        Route::delete('ops/alerts/{id}',        [ConsoleAlertsController::class, 'destroy'])->middleware('role:admin,superadmin');

        // ── Incidents ──────────────────────────────────────────────────────────
        Route::get('ops/incidents/stats',         [ConsoleIncidentController::class, 'stats']);
        Route::get('ops/incidents',               [ConsoleIncidentController::class, 'index']);
        Route::post('ops/incidents',              [ConsoleIncidentController::class, 'store'])->middleware('role:moderator,admin,superadmin');
        Route::patch('ops/incidents/{id}',        [ConsoleIncidentController::class, 'update'])->middleware('role:admin,superadmin');
        Route::post('ops/incidents/{id}/resolve', [ConsoleIncidentController::class, 'resolve'])->middleware('role:admin,superadmin');
    });
