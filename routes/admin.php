<?php

use App\Http\Controllers\Console\AccessController;
use App\Http\Controllers\Console\SaccoMemberController;
use App\Http\Controllers\Console\VehicleDocumentController;
use App\Http\Controllers\Console\FleetDeviceController;
use App\Http\Controllers\Console\StageQueueController;
use App\Http\Controllers\Console\RouteLicenseController;
use App\Http\Controllers\Console\AnalyticsController;
use App\Http\Controllers\Console\ConductorController;
use App\Http\Controllers\Console\ConsoleShiftController;
use App\Http\Controllers\Console\DailyBankingController;
use App\Http\Controllers\Console\SlaController;
use App\Http\Controllers\Console\VehicleOwnerController;
use App\Http\Controllers\Console\OwnerDocumentController;
use App\Http\Controllers\Console\VehicleExpenseController;
use App\Http\Controllers\Console\PayrollController;
use App\Http\Controllers\Console\BulkImportController;
use App\Http\Controllers\Console\AuditLogController;
use App\Http\Controllers\Console\BadgeController;
use App\Http\Controllers\Console\ConsoleAlertsController;
use App\Http\Controllers\Console\ConsoleDataQualityController;
use App\Http\Controllers\Console\BroadcastNotificationController;
use App\Http\Controllers\Console\ConsoleAgencyController;
use App\Http\Controllers\Console\StaffInvitationController;
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
// All routes require authentication + at least one console role (checked by
// the 'role' middleware alias with no parameters = hasConsoleAccess() check).
// Specific write/sensitive actions add a second permission check via 'role:perm'.
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('v1/console')
    ->middleware(['auth:sanctum', 'role', 'agency.scope'])
    ->group(function () {

        // ── Dashboard ──────────────────────────────────────────────────────────
        Route::get('dashboard',               [DashboardController::class, 'overview']);
        Route::get('dashboard/agency-stats',  [DashboardController::class, 'agencyStats']);
        Route::get('dashboard/otp-trend',     [DashboardController::class, 'otpTrend']);
        Route::get('activity',                [DashboardController::class, 'activity']);
        Route::get('system-health',           [DashboardController::class, 'systemHealth']);

        // ── Users ──────────────────────────────────────────────────────────────
        Route::get('users/export',           [ConsoleUserController::class, 'export']);
        Route::get('users',                  [ConsoleUserController::class, 'index']);
        Route::get('users/{id}',             [ConsoleUserController::class, 'show']);
        Route::patch('users/{id}',           [ConsoleUserController::class, 'update'])->middleware('role:users.edit');
        Route::post('users/{id}/ban',        [ConsoleUserController::class, 'ban'])->middleware('role:users.ban');
        Route::post('users/{id}/unban',      [ConsoleUserController::class, 'unban'])->middleware('role:users.ban');
        Route::patch('users/{id}/points',    [ConsoleUserController::class, 'adjustPoints'])->middleware('role:users.edit');
        Route::post('users/{id}/badges',     [ConsoleUserController::class, 'awardBadge'])->middleware('role:users.edit');
        Route::delete('users/{userId}/badges/{badgeId}', [ConsoleUserController::class, 'revokeBadge'])->middleware('role:users.edit');

        // ── Contributions ──────────────────────────────────────────────────────
        Route::get('contributions',               [ConsoleContributionController::class, 'index']);
        Route::get('contributions/{id}',          [ConsoleContributionController::class, 'show']);
        Route::patch('contributions/{id}',        [ConsoleContributionController::class, 'update'])->middleware('role:contributions.moderate');
        Route::post('contributions/{id}/approve', [ConsoleContributionController::class, 'approve'])->middleware('role:contributions.moderate');
        Route::post('contributions/{id}/decline', [ConsoleContributionController::class, 'decline'])->middleware('role:contributions.moderate');
        Route::post('contributions/bulk-approve',   [ConsoleContributionController::class, 'bulkApprove'])->middleware('role:contributions.moderate');
        Route::post('contributions/bulk-decline',   [ConsoleContributionController::class, 'bulkDecline'])->middleware('role:contributions.moderate');
        Route::post('contributions/{id}/assign',    [ConsoleContributionController::class, 'assign'])->middleware('role:contributions.moderate');

        // ── Stops ──────────────────────────────────────────────────────────────
        Route::get('stops',          [ConsoleStopController::class, 'index']);
        Route::post('stops',         [ConsoleStopController::class, 'store'])->middleware('role:stops.create');
        Route::get('stops/{id}',     [ConsoleStopController::class, 'show']);
        Route::patch('stops/{id}',   [ConsoleStopController::class, 'update'])->middleware('role:stops.edit');
        Route::delete('stops/{id}',  [ConsoleStopController::class, 'destroy'])->middleware('role:stops.delete');
        Route::post('stops/{id}/stop-times', [ConsoleStopController::class, 'storeStopTime'])->middleware('role:stops.edit');

        // ── Routes ─────────────────────────────────────────────────────────────
        Route::get('routes',                      [ConsoleRouteController::class, 'index']);
        Route::post('routes',                     [ConsoleRouteController::class, 'store'])->middleware('role:routes.create');
        Route::post('routes/stops-near-line',     [ConsoleRouteController::class, 'stopsNearLine']);
        Route::get('routes/{id}',                 [ConsoleRouteController::class, 'show']);
        Route::patch('routes/{id}',               [ConsoleRouteController::class, 'update'])->middleware('role:routes.edit');
        Route::patch('routes/{id}/stop-sequence', [ConsoleRouteController::class, 'updateStopSequence'])->middleware('role:routes.edit');
        Route::put('routes/{id}/shape',           [ConsoleRouteController::class, 'saveShape'])->middleware('role:routes.edit');
        Route::put('routes/{id}/trip-stops',      [ConsoleRouteController::class, 'saveTripStops'])->middleware('role:routes.edit');
        Route::delete('routes/{id}',              [ConsoleRouteController::class, 'destroy'])->middleware('role:routes.delete');

        // ── Trips ──────────────────────────────────────────────────────────────
        Route::get('trips',                   [ConsoleTripController::class, 'index']);
        Route::post('trips',                  [ConsoleTripController::class, 'store'])->middleware('role:trips.create');
        Route::post('trips/stops-near-line',  [ConsoleTripController::class, 'stopsNearLine']);
        Route::get('trips/pending-review',    [ConsoleTripController::class, 'pendingReview']);  // static before {id}
        Route::get('trips/{id}',              [ConsoleTripController::class, 'show']);
        Route::patch('trips/{id}',            [ConsoleTripController::class, 'update'])->middleware('role:trips.edit');
        Route::put('trips/{id}/shape',        [ConsoleTripController::class, 'saveShape'])->middleware('role:trips.edit');
        Route::put('trips/{id}/stop-times',         [ConsoleTripController::class, 'saveStopTimes'])->middleware('role:trips.edit');
        Route::delete('trips/{id}',                 [ConsoleTripController::class, 'destroy'])->middleware('role:trips.delete');
        Route::post('trips/{id}/submit-for-review', [ConsoleTripController::class, 'submitForReview'])->middleware('role:trips.edit');
        Route::post('trips/{id}/approve',           [ConsoleTripController::class, 'approveDraft'])->middleware('role:scheduling.edit');
        Route::post('trips/{id}/reject',            [ConsoleTripController::class, 'rejectDraft'])->middleware('role:scheduling.edit');

        // ── Trip Frequencies ──────────────────────────────────────────────────
        Route::get('trips/{tripId}/frequencies',         [ConsoleTripFrequencyController::class, 'index']);
        Route::post('trips/{tripId}/frequencies',        [ConsoleTripFrequencyController::class, 'store'])->middleware('role:trips.edit');
        Route::patch('trips/{tripId}/frequencies/{id}',  [ConsoleTripFrequencyController::class, 'update'])->middleware('role:trips.edit');
        Route::delete('trips/{tripId}/frequencies/{id}', [ConsoleTripFrequencyController::class, 'destroy'])->middleware('role:trips.edit');

        // ── Agencies ──────────────────────────────────────────────────────────
        Route::get('agencies',                              [ConsoleAgencyController::class, 'index']);
        Route::post('agencies',                             [ConsoleAgencyController::class, 'store'])->middleware('role:agencies.create');
        Route::patch('agencies/{id}',                       [ConsoleAgencyController::class, 'update'])->middleware('role:agencies.edit');
        Route::delete('agencies/{id}',                      [ConsoleAgencyController::class, 'destroy'])->middleware('role:agencies.delete');
        Route::get('agencies/{id}/onboarding-status',         [ConsoleAgencyController::class, 'onboardingStatus']);
        Route::post('agencies/{id}/complete-onboarding',      [ConsoleAgencyController::class, 'completeOnboarding'])->middleware('role:agencies.edit');
        Route::get('agencies/{id}/operated-routes',           [ConsoleAgencyController::class, 'operatedRoutes']);
        Route::get('agencies/{id}/available-routes',          [ConsoleAgencyController::class, 'availableRoutes']);
        Route::post('agencies/{id}/operated-routes',          [ConsoleAgencyController::class, 'claimRoutes'])->middleware('role:agencies.edit');
        Route::delete('agencies/{id}/operated-routes/{route}', [ConsoleAgencyController::class, 'unclaimRoute'])->middleware('role:agencies.edit');

        // ── Staff Invitations ─────────────────────────────────────────────────
        // index/invite/revoke use agencies.edit so operator_owner can manage their own staff.
        // The controller itself enforces agency-scoping and restricts to operator-level roles.
        Route::get('invitations',         [StaffInvitationController::class, 'index'])->middleware('role:agencies.edit|access.view');
        Route::post('invitations',        [StaffInvitationController::class, 'invite'])->middleware('role:agencies.edit|access.manage');
        Route::delete('invitations/{id}', [StaffInvitationController::class, 'revoke'])->middleware('role:agencies.edit|access.manage');

        // ── Audit Logs ────────────────────────────────────────────────────────
        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('role:settings.view');

        // ── Compliance ────────────────────────────────────────────────────────
        Route::get('compliance/expiry-summary', [ConsoleVehicleController::class, 'complianceExpiry']);

        // ── Service Calendars ─────────────────────────────────────────────────
        Route::get('service-calendars',                          [ConsoleServiceCalendarController::class, 'index']);
        Route::post('service-calendars',                         [ConsoleServiceCalendarController::class, 'store'])->middleware('role:calendars.create');
        Route::get('service-calendars/{id}',                     [ConsoleServiceCalendarController::class, 'show']);
        Route::patch('service-calendars/{id}',                   [ConsoleServiceCalendarController::class, 'update'])->middleware('role:calendars.edit');
        Route::delete('service-calendars/{id}',                  [ConsoleServiceCalendarController::class, 'destroy'])->middleware('role:calendars.delete');
        Route::post('service-calendars/{id}/exceptions',         [ConsoleServiceCalendarController::class, 'addException'])->middleware('role:calendars.edit');
        Route::delete('service-calendars/{id}/exceptions/{eid}', [ConsoleServiceCalendarController::class, 'removeException'])->middleware('role:calendars.edit');
        Route::post('service-calendars/{id}/exceptions/bulk',    [ConsoleServiceCalendarController::class, 'bulkExceptions'])->middleware('role:calendars.edit');

        // ── Route Patterns ────────────────────────────────────────────────────
        Route::get('route-patterns',            [ConsoleRoutePatternController::class, 'index']);
        Route::post('route-patterns',           [ConsoleRoutePatternController::class, 'store'])->middleware('role:network.edit');
        Route::patch('route-patterns/{id}',     [ConsoleRoutePatternController::class, 'update'])->middleware('role:network.edit');
        Route::put('route-patterns/{id}/stops', [ConsoleRoutePatternController::class, 'saveStops'])->middleware('role:network.edit');
        Route::delete('route-patterns/{id}',    [ConsoleRoutePatternController::class, 'destroy'])->middleware('role:network.edit');

        // ── GTFS Operations ───────────────────────────────────────────────────
        Route::get('gtfs/status',            [ConsoleGtfsController::class, 'status']);
        Route::post('gtfs/validate',         [ConsoleGtfsController::class, 'validate'])->middleware('role:gtfs.view');
        Route::post('gtfs/export',           [ConsoleGtfsController::class, 'export'])->middleware('role:gtfs.sync');
        Route::get('gtfs/official-validate', [ConsoleGtfsController::class, 'officialValidate'])->middleware('role:gtfs.view');
        Route::post('gtfs/export-as',        [ConsoleGtfsController::class, 'exportAs'])->middleware('role:gtfs.export');

        // ── Data Quality ──────────────────────────────────────────────────────
        Route::get('quality/score',           [ConsoleDataQualityController::class, 'score']);
        Route::get('quality/drill-down',      [ConsoleDataQualityController::class, 'drillDown']);
        Route::get('quality/shape-inspector', [ConsoleDataQualityController::class, 'shapeInspector']);
        Route::get('quality/duplicate-stops', [ConsoleDataQualityController::class, 'duplicateStops']);
        Route::post('quality/merge-stops',    [ConsoleDataQualityController::class, 'mergeStops'])->middleware('role:quality.fix');
        Route::post('stops/{id}/snap',        [ConsoleDataQualityController::class, 'snapStop'])->middleware('role:quality.fix');

        // ── Network Analysis ──────────────────────────────────────────────────
        Route::get('network/graph',          [ConsoleNetworkController::class, 'graph']);
        Route::get('network/coverage',       [ConsoleNetworkController::class, 'coverage']);
        Route::post('network/isochrone',     [ConsoleNetworkController::class, 'isochrone']);
        Route::get('network/desire-lines',   [ConsoleNetworkController::class, 'desireLines']);
        Route::get('network/transfer-graph', [ConsoleNetworkController::class, 'transferGraph']);
        Route::get('network/snapshots',      [ConsoleNetworkController::class, 'snapshots']);
        Route::post('network/snapshots',     [ConsoleNetworkController::class, 'createSnapshot'])->middleware('role:network.edit');
        Route::get('network/snapshots/{id}', [ConsoleNetworkController::class, 'showSnapshot']);

        // ── Corridors ─────────────────────────────────────────────────────────
        Route::get('corridors',                      [ConsoleCorridorController::class, 'index']);
        Route::post('corridors',                     [ConsoleCorridorController::class, 'store'])->middleware('role:network.edit');
        Route::get('corridors/{id}',                 [ConsoleCorridorController::class, 'show']);
        Route::patch('corridors/{id}',               [ConsoleCorridorController::class, 'update'])->middleware('role:network.edit');
        Route::put('corridors/{id}/shape',           [ConsoleCorridorController::class, 'saveShape'])->middleware('role:network.edit');
        Route::delete('corridors/{id}',              [ConsoleCorridorController::class, 'destroy'])->middleware('role:network.edit');
        Route::get('corridors/{id}/routes',          [ConsoleCorridorController::class, 'routesNearCorridor']);
        Route::post('corridors/{id}/routes',         [ConsoleCorridorController::class, 'attachRoute'])->middleware('role:network.edit');
        Route::delete('corridors/{id}/routes/{rid}', [ConsoleCorridorController::class, 'detachRoute'])->middleware('role:network.edit');

        // ── Scenarios ─────────────────────────────────────────────────────────
        Route::get('scenarios',                         [ConsoleScenarioController::class, 'index']);
        Route::post('scenarios',                        [ConsoleScenarioController::class, 'store'])->middleware('role:network.edit');
        Route::get('scenarios/{id}',                    [ConsoleScenarioController::class, 'show']);
        Route::patch('scenarios/{id}',                  [ConsoleScenarioController::class, 'update'])->middleware('role:network.edit');
        Route::delete('scenarios/{id}',                 [ConsoleScenarioController::class, 'destroy'])->middleware('role:network.edit');
        Route::post('scenarios/{id}/overrides',         [ConsoleScenarioController::class, 'addOverride'])->middleware('role:network.edit');
        Route::delete('scenarios/{id}/overrides/{oid}', [ConsoleScenarioController::class, 'removeOverride'])->middleware('role:network.edit');
        Route::get('scenarios/{id}/compare',            [ConsoleScenarioController::class, 'compare']);
        Route::post('scenarios/{id}/publish',           [ConsoleScenarioController::class, 'publish'])->middleware('role:network.publish_scenario');

        // ── Timetable ─────────────────────────────────────────────────────────
        Route::get('routes/{id}/timetable', [ConsoleSchedulingController::class, 'timetable']);
        Route::put('routes/{id}/timetable', [ConsoleSchedulingController::class, 'saveTimetable'])->middleware('role:scheduling.edit');

        // ── Scheduling Tools ──────────────────────────────────────────────────
        Route::post('scheduling/optimize-headway', [ConsoleSchedulingController::class, 'optimizeHeadway'])->middleware('role:scheduling.edit');
        Route::get('scheduling/layover-analysis',  [ConsoleSchedulingController::class, 'layoverAnalysis']);
        Route::get('scheduling/blocks',            [ConsoleSchedulingController::class, 'blocks']);

        // ── OTP ────────────────────────────────────────────────────────────────
        Route::get('otp/status',  [OtpController::class, 'status']);
        Route::get('otp/log',     [OtpController::class, 'log']);
        Route::post('otp/sync',   [OtpController::class, 'sync'])->middleware('role:gtfs.sync');
        Route::post('otp/cancel', [OtpController::class, 'cancel'])->middleware('role:gtfs.sync');

        // ── Analytics (throttled: 30 req/min per user; responses cached 5 min) ─
        Route::middleware('throttle:30,1')->group(function () {
            Route::get('analytics/overview',      [AnalyticsController::class, 'overview']);
            Route::get('analytics/journeys',      [AnalyticsController::class, 'journeys']);
            Route::get('analytics/searches',      [AnalyticsController::class, 'searches']);
            Route::get('analytics/contributions', [AnalyticsController::class, 'contributions']);
            Route::get('analytics/user-growth',   [AnalyticsController::class, 'userGrowth']);
        });

        // ── Broadcast Notifications ────────────────────────────────────────────
        Route::get('notifications',            [BroadcastNotificationController::class, 'index']);
        Route::post('notifications/broadcast', [BroadcastNotificationController::class, 'broadcast'])->middleware('role:notifications.send');

        // ── Badges ─────────────────────────────────────────────────────────────
        Route::get('badges',         [BadgeController::class, 'index']);
        Route::post('badges',        [BadgeController::class, 'store'])->middleware('role:users.edit');
        Route::patch('badges/{id}',  [BadgeController::class, 'update'])->middleware('role:users.edit');
        Route::delete('badges/{id}', [BadgeController::class, 'destroy'])->middleware('role:users.edit');

        // ── Multi-Modal Layers ───────────────────────────────────────────────
        Route::get('network/modal-layers',              [ConsoleNetworkController::class, 'modalLayers']);
        Route::post('network/modal-layers/refresh-osm', [ConsoleNetworkController::class, 'refreshOsmLayer'])->middleware('role:network.edit');

        // ── Multi-Agency ─────────────────────────────────────────────────────
        Route::get('network/agencies',               [ConsoleNetworkController::class, 'agencies']);
        Route::get('network/cross-agency-transfers', [ConsoleNetworkController::class, 'crossAgencyTransfers']);

        // ── Fare Zones & Rules ───────────────────────────────────────────────
        Route::get('fares/zones',              [ConsoleFareController::class, 'zones']);
        Route::post('fares/zones',             [ConsoleFareController::class, 'storeZone'])->middleware('role:fares.edit');
        Route::patch('fares/zones/{id}',       [ConsoleFareController::class, 'updateZone'])->middleware('role:fares.edit');
        Route::delete('fares/zones/{id}',      [ConsoleFareController::class, 'destroyZone'])->middleware('role:fares.edit');
        Route::get('fares/attributes',         [ConsoleFareController::class, 'fareAttributes']);
        Route::post('fares/attributes',        [ConsoleFareController::class, 'saveFareAttribute'])->middleware('role:fares.edit');
        Route::delete('fares/attributes/{id}', [ConsoleFareController::class, 'deleteFareAttribute'])->middleware('role:fares.edit');
        Route::get('fares/rules',              [ConsoleFareController::class, 'fareRules']);
        Route::post('fares/rules',             [ConsoleFareController::class, 'saveFareRule'])->middleware('role:fares.edit');
        Route::delete('fares/rules/{id}',      [ConsoleFareController::class, 'deleteFareRule'])->middleware('role:fares.edit');
        Route::get('fares/preview',            [ConsoleFareController::class, 'previewFare']);
        Route::get('fares/export',             [ConsoleFareController::class, 'exportFareFiles'])->middleware('role:fares.edit');
        Route::get('fares/route-fares',        [ConsoleFareController::class, 'routeBasedFares']);
        Route::post('fares/route-fares',       [ConsoleFareController::class, 'saveRouteFare'])->middleware('role:fares.edit');
        Route::delete('fares/route-fares/{id}',[ConsoleFareController::class, 'deleteRouteFare'])->middleware('role:fares.edit');
        Route::get('fares/modifiers',                [ConsoleFareController::class, 'fareModifiers']);
        Route::post('fares/modifiers',               [ConsoleFareController::class, 'saveFareModifier'])->middleware('role:fares.edit');
        Route::patch('fares/modifiers/{id}',         [ConsoleFareController::class, 'updateFareModifier'])->middleware('role:fares.edit');
        Route::delete('fares/modifiers/{id}',        [ConsoleFareController::class, 'deleteFareModifier'])->middleware('role:fares.edit');
        Route::post('fares/modifiers/{id}/toggle',   [ConsoleFareController::class, 'toggleModifier'])->middleware('role:fares.edit');

        // ── Interop Registry + Levels + Pathways ─────────────────────────────
        Route::get('network/interop',          [ConsoleInteropController::class, 'index']);
        Route::post('network/interop',         [ConsoleInteropController::class, 'store'])->middleware('role:interop.edit');
        Route::patch('network/interop/{id}',   [ConsoleInteropController::class, 'update'])->middleware('role:interop.edit');
        Route::delete('network/interop/{id}',  [ConsoleInteropController::class, 'destroy'])->middleware('role:interop.edit');
        Route::get('network/levels',           [ConsoleInteropController::class, 'levels']);
        Route::post('network/levels',          [ConsoleInteropController::class, 'storeLevel'])->middleware('role:interop.edit');
        Route::patch('network/levels/{id}',    [ConsoleInteropController::class, 'updateLevel'])->middleware('role:interop.edit');
        Route::delete('network/levels/{id}',   [ConsoleInteropController::class, 'destroyLevel'])->middleware('role:interop.edit');
        Route::get('network/pathways/export',  [ConsoleInteropController::class, 'exportPathwayFiles'])->middleware('role:interop.edit');
        Route::get('network/pathways',         [ConsoleInteropController::class, 'pathways']);
        Route::post('network/pathways',        [ConsoleInteropController::class, 'storePathway'])->middleware('role:interop.edit');
        Route::patch('network/pathways/{id}',  [ConsoleInteropController::class, 'updatePathway'])->middleware('role:interop.edit');
        Route::delete('network/pathways/{id}', [ConsoleInteropController::class, 'destroyPathway'])->middleware('role:interop.edit');

        // ── Fleet — Vehicles & Drivers ────────────────────────────────────────
        Route::get('vehicles',         [ConsoleVehicleController::class, 'index']);
        Route::post('vehicles',        [ConsoleVehicleController::class, 'store'])->middleware('role:fleet.edit');
        Route::get('vehicles/{id}',    [ConsoleVehicleController::class, 'show']);
        Route::patch('vehicles/{id}',  [ConsoleVehicleController::class, 'update'])->middleware('role:fleet.edit');
        Route::delete('vehicles/{id}', [ConsoleVehicleController::class, 'destroy'])->middleware('role:fleet.edit');

        // Vehicle Documents (nested under vehicle)
        Route::get('vehicles/{vehicleId}/documents',             [VehicleDocumentController::class, 'index']);
        Route::post('vehicles/{vehicleId}/documents',            [VehicleDocumentController::class, 'store'])->middleware('role:fleet.edit');
        Route::patch('vehicles/{vehicleId}/documents/{id}',      [VehicleDocumentController::class, 'update'])->middleware('role:fleet.edit');
        Route::delete('vehicles/{vehicleId}/documents/{id}',     [VehicleDocumentController::class, 'destroy'])->middleware('role:fleet.edit');

        // Fleet Devices (nested under vehicle)
        Route::get('vehicles/{vehicleId}/devices',               [FleetDeviceController::class, 'index']);
        Route::post('vehicles/{vehicleId}/devices',              [FleetDeviceController::class, 'store'])->middleware('role:fleet.edit');
        Route::patch('vehicles/{vehicleId}/devices/{id}',        [FleetDeviceController::class, 'update'])->middleware('role:fleet.edit');
        Route::delete('vehicles/{vehicleId}/devices/{id}',       [FleetDeviceController::class, 'destroy'])->middleware('role:fleet.edit');
        Route::post('vehicles/{vehicleId}/devices/{id}/rotate-token', [FleetDeviceController::class, 'rotateToken'])->middleware('role:fleet.edit');

        Route::get('drivers',          [ConsoleDriverController::class, 'index']);
        Route::post('drivers',         [ConsoleDriverController::class, 'store'])->middleware('role:fleet.edit');
        Route::patch('drivers/{id}',   [ConsoleDriverController::class, 'update'])->middleware('role:fleet.edit');
        Route::delete('drivers/{id}',  [ConsoleDriverController::class, 'destroy'])->middleware('role:fleet.edit');

        // ── Fleet — Conductors ────────────────────────────────────────────────
        Route::get('conductors',          [ConductorController::class, 'index']);
        Route::post('conductors',         [ConductorController::class, 'store'])->middleware('role:fleet.edit');
        Route::patch('conductors/{id}',   [ConductorController::class, 'update'])->middleware('role:fleet.edit');
        Route::delete('conductors/{id}',  [ConductorController::class, 'destroy'])->middleware('role:fleet.edit');

        // ── Fleet — Shifts ────────────────────────────────────────────────────
        Route::get('shifts/uncovered',    [ConsoleShiftController::class, 'uncovered']);
        Route::get('shifts',              [ConsoleShiftController::class, 'index']);
        Route::post('shifts',             [ConsoleShiftController::class, 'store'])->middleware('role:fleet.edit');
        Route::patch('shifts/{id}',       [ConsoleShiftController::class, 'update'])->middleware('role:fleet.edit');
        Route::delete('shifts/{id}',      [ConsoleShiftController::class, 'destroy'])->middleware('role:fleet.edit');
        Route::post('shifts/{id}/start',  [ConsoleShiftController::class, 'start'])->middleware('role:fleet.edit');
        Route::post('shifts/{id}/end',    [ConsoleShiftController::class, 'end'])->middleware('role:fleet.edit');

        // ── Daily Banking & Vehicle Targets ───────────────────────────────────
        Route::get('vehicle-targets',      [DailyBankingController::class, 'targets']);
        Route::post('vehicle-targets',     [DailyBankingController::class, 'storeTarget'])->middleware('role:fleet.edit');
        Route::get('banking/summary',      [DailyBankingController::class, 'summary']);
        Route::get('banking/trends',       [DailyBankingController::class, 'trends']);
        Route::get('banking',              [DailyBankingController::class, 'index']);
        Route::post('banking',             [DailyBankingController::class, 'record'])->middleware('role:ledger.configure|fleet.edit');
        Route::patch('banking/{id}',       [DailyBankingController::class, 'update'])->middleware('role:ledger.configure|fleet.edit');

        // ── SLA Rules (Headway) ───────────────────────────────────────────────
        Route::get('ops/sla',              [SlaController::class, 'index']);
        Route::post('ops/sla',             [SlaController::class, 'store'])->middleware('role:ops.view');
        Route::patch('ops/sla/{id}',       [SlaController::class, 'update'])->middleware('role:ops.view');
        Route::delete('ops/sla/{id}',      [SlaController::class, 'destroy'])->middleware('role:ops.view');

        // ── Ledger & Clearinghouse ─────────────────────────────────────────────
        Route::get('ledger/split-configs',              [ConsoleLedgerController::class, 'splitConfigs']);
        Route::post('ledger/split-configs',             [ConsoleLedgerController::class, 'saveSplitConfig'])->middleware('role:ledger.configure');
        Route::post('ledger/daily-levy',                [ConsoleLedgerController::class, 'applyDailyLevy'])->middleware('role:ledger.configure');
        Route::get('ledger/wallets',                    [ConsoleLedgerController::class, 'wallets']);
        Route::get('ledger/wallets/{id}/transactions',  [ConsoleLedgerController::class, 'walletTransactions']);
        Route::get('ledger/fleet-revenue',              [ConsoleLedgerController::class, 'fleetRevenue']);
        Route::get('ledger/revenue-trend',              [ConsoleLedgerController::class, 'revenueTrend']);
        Route::get('ledger/vehicles/{id}/revenue',      [ConsoleLedgerController::class, 'vehicleRevenue']);
        Route::get('ledger/route-revenue',              [ConsoleLedgerController::class, 'routeRevenue']);
        Route::post('ledger/test-split',                [ConsoleLedgerController::class, 'testSplit'])->middleware('role:ledger.configure');

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
        Route::post('ops/alerts',               [ConsoleAlertsController::class, 'store'])->middleware('role:ops.manage_alerts');
        Route::patch('ops/alerts/{id}',         [ConsoleAlertsController::class, 'update'])->middleware('role:ops.manage_alerts');
        Route::post('ops/alerts/{id}/activate', [ConsoleAlertsController::class, 'activate'])->middleware('role:ops.manage_alerts');
        Route::post('ops/alerts/{id}/expire',   [ConsoleAlertsController::class, 'expire'])->middleware('role:ops.manage_alerts');
        Route::delete('ops/alerts/{id}',        [ConsoleAlertsController::class, 'destroy'])->middleware('role:ops.manage_alerts');

        // ── Incidents ──────────────────────────────────────────────────────────
        Route::get('ops/incidents/stats',          [ConsoleIncidentController::class, 'stats']);
        Route::get('ops/incidents',                [ConsoleIncidentController::class, 'index']);
        Route::post('ops/incidents',               [ConsoleIncidentController::class, 'store'])->middleware('role:ops.manage_incidents');
        Route::patch('ops/incidents/{id}',         [ConsoleIncidentController::class, 'update'])->middleware('role:ops.manage_incidents');
        Route::post('ops/incidents/{id}/resolve',  [ConsoleIncidentController::class, 'resolve'])->middleware('role:ops.manage_incidents');
        Route::post('ops/incidents/{id}/assign',   [ConsoleIncidentController::class, 'assign'])->middleware('role:ops.manage_incidents');

        // ── Vehicle Owners ─────────────────────────────────────────────────────
        Route::get('fleet/owners',                              [VehicleOwnerController::class, 'index']);
        Route::post('fleet/owners',                             [VehicleOwnerController::class, 'store'])->middleware('role:fleet.edit');
        Route::patch('fleet/owners/{owner}',                    [VehicleOwnerController::class, 'update'])->middleware('role:fleet.edit');
        Route::delete('fleet/owners/{owner}',                   [VehicleOwnerController::class, 'destroy'])->middleware('role:fleet.edit');
        Route::post('fleet/owners/{owner}/photo',               [VehicleOwnerController::class, 'uploadPhoto'])->middleware('role:fleet.edit');
        Route::get('fleet/owners/{owner}/summary',              [VehicleOwnerController::class, 'summary']);
        Route::get('fleet/owners/{owner}/documents',            [OwnerDocumentController::class, 'index']);
        Route::post('fleet/owners/{owner}/documents',           [OwnerDocumentController::class, 'store'])->middleware('role:fleet.edit');
        Route::delete('fleet/owners/{owner}/documents/{document}', [OwnerDocumentController::class, 'destroy'])->middleware('role:fleet.edit');

        // ── Vehicle Expenses & Maintenance ─────────────────────────────────────
        Route::get('fleet/expenses/summary',      [VehicleExpenseController::class, 'summary']);
        Route::get('fleet/expenses',              [VehicleExpenseController::class, 'index']);
        Route::post('fleet/expenses',             [VehicleExpenseController::class, 'store'])->middleware('role:fleet.edit');
        Route::delete('fleet/expenses/{expense}', [VehicleExpenseController::class, 'destroy'])->middleware('role:fleet.edit');

        Route::get('fleet/maintenance',                    [VehicleExpenseController::class, 'maintenanceIndex']);
        Route::post('fleet/maintenance',                   [VehicleExpenseController::class, 'maintenanceStore'])->middleware('role:fleet.edit');
        Route::patch('fleet/maintenance/{window}',         [VehicleExpenseController::class, 'maintenanceUpdate'])->middleware('role:fleet.edit');
        Route::delete('fleet/maintenance/{window}',        [VehicleExpenseController::class, 'maintenanceDestroy'])->middleware('role:fleet.edit');

        // ── Payroll ────────────────────────────────────────────────────────────
        Route::post('payroll/generate', [PayrollController::class, 'generate'])->middleware('role:ledger.view');

        // ── Analytics: Trip Variance ───────────────────────────────────────────
        Route::get('analytics/trip-variance', [AnalyticsController::class, 'tripVariance']);

        // ── Bulk Import ────────────────────────────────────────────────────────
        Route::post('imports/vehicles',   [BulkImportController::class, 'vehicles'])->middleware('role:fleet.edit');
        Route::post('imports/drivers',    [BulkImportController::class, 'drivers'])->middleware('role:fleet.edit');
        Route::post('imports/conductors', [BulkImportController::class, 'conductors'])->middleware('role:fleet.edit');

        // ── Stop Claims ───────────────────────────────────────────────────────
        Route::get('stops/claimed',        [ConsoleStopController::class, 'claimed']);
        Route::post('stops/{id}/claim',    [ConsoleStopController::class, 'claim'])->middleware('role:agencies.edit');
        Route::delete('stops/{id}/claim',  [ConsoleStopController::class, 'unclaim'])->middleware('role:agencies.edit');

        // ── Stage Queues ──────────────────────────────────────────────────────
        Route::get('stage-queues',              [StageQueueController::class, 'index']);
        Route::post('stage-queues',             [StageQueueController::class, 'store'])->middleware('role:ops.view');
        Route::post('stage-queues/reorder',     [StageQueueController::class, 'reorder'])->middleware('role:ops.view');
        Route::post('stage-queues/{id}/depart', [StageQueueController::class, 'depart'])->middleware('role:ops.view');
        Route::post('stage-queues/{id}/skip',   [StageQueueController::class, 'skip'])->middleware('role:ops.view');

        // ── Route Licenses ────────────────────────────────────────────────────
        Route::get('route-licenses',          [RouteLicenseController::class, 'index']);
        Route::post('route-licenses',         [RouteLicenseController::class, 'store'])->middleware('role:agencies.edit');
        Route::patch('route-licenses/{id}',   [RouteLicenseController::class, 'update'])->middleware('role:agencies.edit');
        Route::delete('route-licenses/{id}',  [RouteLicenseController::class, 'destroy'])->middleware('role:agencies.edit');

        // ── Fleet Broadcast ───────────────────────────────────────────────────
        Route::post('broadcasts/fleet', [BroadcastNotificationController::class, 'broadcastToFleet'])->middleware('role:notifications.send');

        // ── SACCO Members ─────────────────────────────────────────────────────
        Route::get('members',                                    [SaccoMemberController::class, 'index'])           ->middleware('role:members.view');
        Route::get('members/export',                             [SaccoMemberController::class, 'export'])          ->middleware('role:members.view');
        Route::post('members',                                   [SaccoMemberController::class, 'store'])           ->middleware('role:members.manage');
        Route::get('members/{member}',                           [SaccoMemberController::class, 'show'])            ->middleware('role:members.view');
        Route::patch('members/{member}',                         [SaccoMemberController::class, 'update'])          ->middleware('role:members.manage');
        Route::post('members/{member}/vet',                      [SaccoMemberController::class, 'vet'])             ->middleware('role:members.manage');
        Route::post('members/{member}/activate',                 [SaccoMemberController::class, 'activate'])        ->middleware('role:members.manage');
        Route::post('members/{member}/suspend',                  [SaccoMemberController::class, 'suspend'])         ->middleware('role:members.manage');
        Route::post('members/{member}/reinstate',                [SaccoMemberController::class, 'reinstate'])       ->middleware('role:members.manage');
        Route::get('members/{member}/fees',                      [SaccoMemberController::class, 'fees'])            ->middleware('role:members.view');
        Route::post('members/{member}/fees',                     [SaccoMemberController::class, 'recordFee'])       ->middleware('role:members.manage');
        Route::post('members/{member}/documents',                [SaccoMemberController::class, 'uploadDocument'])  ->middleware('role:members.manage');
        Route::delete('members/{member}/documents/{doc}',        [SaccoMemberController::class, 'deleteDocument'])  ->middleware('role:members.manage');

        // ── Access Management (RBAC) ───────────────────────────────────────────
        Route::get('access/roles',                        [AccessController::class, 'listRoles'])->middleware('role:access.view');
        Route::get('access/roles/{role}',                 [AccessController::class, 'showRole'])->middleware('role:access.view');
        Route::put('access/roles/{role}/permissions',     [AccessController::class, 'updateRolePermissions'])->middleware('role:access.manage');
        Route::get('access/users',                        [AccessController::class, 'listUsers'])->middleware('role:access.view');
        Route::post('access/users',                       [AccessController::class, 'createUser'])->middleware('role:access.manage');
        Route::get('access/users/{user}',                 [AccessController::class, 'showUser'])->middleware('role:access.view');
        Route::put('access/users/{user}/role',            [AccessController::class, 'updateUserRole'])->middleware('role:access.manage');
        Route::put('access/users/{user}/permissions',     [AccessController::class, 'updateUserPermissions'])->middleware('role:access.manage');
        Route::put('access/users/{user}/agencies',        [AccessController::class, 'updateUserAgencies'])->middleware('role:access.manage');
        Route::delete('access/users/{user}',              [AccessController::class, 'revokeAccess'])->middleware('role:access.manage');
    });

// ── Public invitation acceptance (no auth required) ───────────────────────────
Route::prefix('v1/console')->group(function () {
    Route::get('invitations/accept/{token}',  [StaffInvitationController::class, 'show']);
    Route::post('invitations/accept/{token}', [StaffInvitationController::class, 'complete'])->middleware('throttle:10,1');
});
