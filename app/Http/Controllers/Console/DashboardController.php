<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function overview(): JsonResponse
    {
        $dau = Cache::remember('console:dau', 60, fn () =>
            User::whereDate('updated_at', today())->count()
        );

        $mau = Cache::remember('console:mau', 300, fn () =>
            User::where('updated_at', '>=', now()->subDays(30))->count()
        );

        $journeysToday = Cache::remember('console:journeys_today', 60, function () {
            try {
                return DB::table('journey_logs')->whereDate('created_at', today())->count();
            } catch (\Throwable) {
                return 0;
            }
        });

        $pendingContributions = Cache::remember('console:pending_contribs', 60, fn () =>
            Contribution::where('status', 'pending')->count()
        );

        $totalUsers = Cache::remember('console:total_users', 300, fn () =>
            User::count()
        );

        $totalContributions = Cache::remember('console:total_contribs', 300, fn () =>
            Contribution::count()
        );

        return response()->json([
            'dau'                   => $dau,
            'mau'                   => $mau,
            'journeys_today'        => $journeysToday,
            'pending_contributions' => $pendingContributions,
            'total_users'           => $totalUsers,
            'total_contributions'   => $totalContributions,
        ]);
    }

    public function activity(): JsonResponse
    {
        $events = collect();

        // Recent users
        $users = User::latest()->limit(20)->get(['id', 'name', 'email', 'created_at']);
        foreach ($users as $u) {
            $events->push([
                'id'         => 'user_'.$u->id,
                'type'       => 'user_joined',
                'label'      => "{$u->name} joined",
                'meta'       => $u->email,
                'created_at' => $u->created_at,
            ]);
        }

        // Recent contributions
        $contribs = Contribution::with('user:id,name')
            ->latest()
            ->limit(30)
            ->get(['id', 'user_id', 'type', 'status', 'created_at', 'updated_at']);

        foreach ($contribs as $c) {
            $events->push([
                'id'         => 'contrib_'.$c->id,
                'type'       => 'contribution_' . $c->status,
                'label'      => ucfirst($c->type) . ' contribution ' . $c->status,
                'meta'       => $c->user?->name ?? 'Unknown',
                'created_at' => $c->updated_at,
            ]);
        }

        $sorted = $events->sortByDesc('created_at')->values()->take(100);

        return response()->json(['data' => $sorted]);
    }

    public function agencyStats(Request $request): JsonResponse
    {
        $scope = $this->agencyScope($request);
        $isOp  = $scope !== null && $request->user()->isOperator();

        if ($isOp) {
            // Operators: count only routes/trips they operate via route_operators
            $routesCount = DB::table('route_operators')
                ->whereIn('agency_id', $scope)
                ->distinct()
                ->count('route_id');

            $tripsCount = DB::table('trips')
                ->whereIn('route_id', fn ($sub) =>
                    $sub->select('route_id')->from('route_operators')->whereIn('agency_id', $scope)
                )
                ->count();
        } else {
            $routesCount = DB::table('routes')
                ->when($scope !== null, fn ($q) => $q->whereIn('agency_id', $scope))
                ->count();

            $tripsCount = DB::table('trips')
                ->join('routes', 'routes.route_id', '=', 'trips.route_id')
                ->when($scope !== null, fn ($q) => $q->whereIn('routes.agency_id', $scope))
                ->count();
        }

        $vehiclesCount = DB::table('vehicles')
            ->when($scope !== null, fn ($q) => $q->whereIn('agency_id', $scope))
            ->count();

        $driversCount = DB::table('drivers')
            ->leftJoin('vehicles', 'vehicles.id', '=', 'drivers.vehicle_id')
            ->when($scope !== null, fn ($q) => $q->whereIn('vehicles.agency_id', $scope))
            ->count();

        $activeVehicles = DB::table('vehicle_positions')
            ->select('vehicle_id')
            ->where('recorded_at', '>=', now()->subMinutes(10))
            ->when($scope !== null, function ($q) use ($scope) {
                $q->whereIn('vehicle_id', function ($sub) use ($scope) {
                    $sub->select('id')->from('vehicles')->whereIn('agency_id', $scope);
                });
            })
            ->distinct()
            ->count('vehicle_id');

        $todayPerf = null;
        try {
            $todayPerf = DB::table('on_time_performance')
                ->whereDate('date', today())
                ->selectRaw('
                    AVG(CASE WHEN total_trips > 0 THEN on_time_trips::float / total_trips ELSE 0 END) as on_time_pct,
                    AVG(avg_delay_s) as avg_delay_s,
                    SUM(total_trips) as trips_tracked
                ')
                ->first();
        } catch (\Throwable) {
            // table may not exist yet
        }

        $pendingContribs = DB::table('contributions')->where('status', 'pending')->count();

        return response()->json([
            'routes_count'          => $routesCount,
            'trips_count'           => $tripsCount,
            'vehicles_count'        => $vehiclesCount,
            'drivers_count'         => $driversCount,
            'active_vehicles'       => $activeVehicles,
            'on_time_pct'           => round(($todayPerf->on_time_pct ?? 0) * 100, 1),
            'avg_delay_s'           => round($todayPerf->avg_delay_s ?? 0),
            'trips_tracked'         => (int) ($todayPerf->trips_tracked ?? 0),
            'pending_contributions' => $pendingContribs,
        ]);
    }

    public function otpTrend(Request $request): JsonResponse
    {
        $scope = $this->agencyScope($request);
        $rows = collect();
        try {
            $q = DB::table('on_time_performance')
                ->where('date', '>=', now()->subDays(7)->toDateString());

            if ($scope !== null) {
                if ($request->user()->isOperator()) {
                    $q->whereIn('route_id', function ($sub) use ($scope) {
                        $sub->select('route_id')->from('route_operators')->whereIn('agency_id', $scope);
                    });
                } else {
                    $q->whereIn('route_id', function ($sub) use ($scope) {
                        $sub->select('route_id')->from('routes')->whereIn('agency_id', $scope);
                    });
                }
            } elseif ($request->filled('agency_id')) {
                $agencyId = $request->input('agency_id');
                $q->whereIn('route_id', function ($sub) use ($agencyId) {
                    $sub->select('route_id')->from('routes')->where('agency_id', $agencyId);
                });
            }

            $rows = $q->groupBy('date')
                ->select([
                    'date',
                    DB::raw('AVG(CASE WHEN total_trips > 0 THEN on_time_trips::float / total_trips ELSE 0 END) * 100 as on_time_pct'),
                    DB::raw('AVG(avg_delay_s) as avg_delay_s'),
                    DB::raw('SUM(total_trips) as trips_tracked'),
                ])
                ->orderBy('date')
                ->get()
                ->map(fn ($r) => [
                    'date'          => $r->date,
                    'on_time_pct'   => round($r->on_time_pct ?? 0, 1),
                    'avg_delay_s'   => round($r->avg_delay_s ?? 0),
                    'trips_tracked' => (int) ($r->trips_tracked ?? 0),
                ]);
        } catch (\Throwable) {
            // table may not exist yet
        }

        return response()->json($rows->values());
    }

    public function systemHealth(): JsonResponse
    {
        return response()->json([
            'otp' => [
                'last_sync'     => Cache::get('otp:last_sync'),
                'last_duration' => Cache::get('otp:last_duration'),
                'status'        => Cache::get('otp:sync_status', 'unknown'),
                'error'         => Cache::get('otp:sync_error'),
            ],
            'queue' => [
                'pending' => DB::table('jobs')->count(),
                'failed'  => DB::table('failed_jobs')->count(),
            ],
        ]);
    }

    public function networkStats(): JsonResponse
    {
        $agenciesCount    = Cache::remember('console:net:agencies',  300, fn () => DB::table('agencies')->count());
        $agenciesAuth     = Cache::remember('console:net:auth',      300, fn () => DB::table('agencies')->where('type', 'authority')->count());
        $agenciesOperator = Cache::remember('console:net:op',        300, fn () => DB::table('agencies')->where('type', 'operator')->count());
        $routesCount      = Cache::remember('console:net:routes',    300, fn () => DB::table('routes')->count());
        $stopsCount       = Cache::remember('console:net:stops',     300, fn () => DB::table('stops')->count());
        $tripsCount       = Cache::remember('console:net:trips',     300, fn () => DB::table('trips')->count());

        $bounds = Cache::remember('console:net:bounds', 600, function () {
            try {
                return DB::table('stops')
                    ->selectRaw('
                        MIN(ST_Y(location::geometry)) as min_lat,
                        MAX(ST_Y(location::geometry)) as max_lat,
                        MIN(ST_X(location::geometry)) as min_lng,
                        MAX(ST_X(location::geometry)) as max_lng
                    ')
                    ->first();
            } catch (\Throwable) {
                return null;
            }
        });

        return response()->json([
            'agencies_count'     => $agenciesCount,
            'agencies_authority' => $agenciesAuth,
            'agencies_operator'  => $agenciesOperator,
            'routes_count'       => $routesCount,
            'stops_count'        => $stopsCount,
            'trips_count'        => $tripsCount,
            'bounds'             => $bounds && $bounds->min_lat !== null ? [
                'min_lat' => (float) $bounds->min_lat,
                'max_lat' => (float) $bounds->max_lat,
                'min_lng' => (float) $bounds->min_lng,
                'max_lng' => (float) $bounds->max_lng,
            ] : null,
        ]);
    }

    public function userGrowth(): JsonResponse
    {
        $rows = Cache::remember('console:user_growth', 300, fn () =>
            DB::table('users')
                ->where('created_at', '>=', now()->subDays(30))
                ->selectRaw("DATE(created_at) as date, COUNT(*) as new_users")
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->keyBy('date')
        );

        $baseline = (int) Cache::remember('console:user_baseline', 300, fn () =>
            DB::table('users')->where('created_at', '<', now()->subDays(30))->count()
        );

        $result  = [];
        $running = $baseline;

        for ($i = 29; $i >= 0; $i--) {
            $date    = now()->subDays($i)->toDateString();
            $newDay  = (int) ($rows->get($date)?->new_users ?? 0);
            $running += $newDay;
            $result[] = ['date' => $date, 'new_users' => $newDay, 'total_users' => $running];
        }

        return response()->json($result);
    }

    public function coverageStops(): JsonResponse
    {
        $features = Cache::remember('console:coverage_stops', 600, function () {
            try {
                return DB::table('stops')
                    ->selectRaw('stop_name, ST_X(location::geometry) as lng, ST_Y(location::geometry) as lat')
                    ->limit(2000)
                    ->get()
                    ->map(fn ($s) => [
                        'type'       => 'Feature',
                        'geometry'   => ['type' => 'Point', 'coordinates' => [(float) $s->lng, (float) $s->lat]],
                        'properties' => ['name' => $s->stop_name],
                    ])
                    ->values()
                    ->toArray();
            } catch (\Throwable) {
                return [];
            }
        });

        return response()->json(['type' => 'FeatureCollection', 'features' => $features]);
    }

    public function journeyHeatmap(): JsonResponse
    {
        $grid = Cache::remember('console:journey_heatmap', 300, function () {
            try {
                return DB::table('journey_logs')
                    ->where('created_at', '>=', now()->subDays(28))
                    ->selectRaw("EXTRACT(DOW FROM created_at)::int AS dow, EXTRACT(HOUR FROM created_at)::int AS hour, COUNT(*) AS count")
                    ->groupByRaw('dow, hour')
                    ->orderByRaw('dow, hour')
                    ->get();
            } catch (\Throwable) {
                return collect();
            }
        });

        return response()->json($grid->values());
    }

    public function topRoutes(): JsonResponse
    {
        $routes = Cache::remember('console:top_routes', 300, function () {
            try {
                return DB::table('journey_logs')
                    ->join('routes', 'routes.route_id', '=', 'journey_logs.route_id')
                    ->where('journey_logs.created_at', '>=', now()->subDays(7))
                    ->selectRaw('journey_logs.route_id, routes.route_short_name, routes.route_long_name, COUNT(*) AS count')
                    ->groupBy('journey_logs.route_id', 'routes.route_short_name', 'routes.route_long_name')
                    ->orderByRaw('count DESC')
                    ->limit(10)
                    ->get();
            } catch (\Throwable) {
                return collect();
            }
        });

        return response()->json($routes->values());
    }

    public function appVersions(): JsonResponse
    {
        $versions = Cache::remember('console:app_versions', 600, function () {
            try {
                return DB::table('users')
                    ->whereNotNull('app_version')
                    ->where('app_version', '!=', '')
                    ->selectRaw('app_version, COUNT(*) AS count')
                    ->groupBy('app_version')
                    ->orderByRaw('count DESC')
                    ->limit(10)
                    ->get();
            } catch (\Throwable) {
                return collect();
            }
        });

        $total = $versions->sum('count');

        return response()->json([
            'versions' => $versions->map(fn ($v) => [
                'version' => $v->app_version,
                'count'   => (int) $v->count,
                'pct'     => $total > 0 ? round(($v->count / $total) * 100, 1) : 0,
            ])->values(),
            'total' => (int) $total,
        ]);
    }

    public function gtfsQuality(): JsonResponse
    {
        $checks = Cache::remember('console:gtfs_quality', 600, function () {
            $stopsTotal    = max(1, DB::table('stops')->count());
            $stopsNamed    = DB::table('stops')->whereNotNull('stop_name')->where('stop_name', '!=', '')->count();
            $stopsGeocoded = DB::table('stops')->whereNotNull('location')->count();

            $routesTotal   = max(1, DB::table('routes')->count());
            $routesNamed   = DB::table('routes')->whereNotNull('route_long_name')->where('route_long_name', '!=', '')->count();
            $routesColored = DB::table('routes')->whereNotNull('route_color')->where('route_color', '!=', '')->count();

            $tripsTotal    = max(1, DB::table('trips')->count());
            $tripsShapes   = DB::table('trips')->whereNotNull('shape_id')->count();
            $tripsHeadsign = DB::table('trips')->whereNotNull('trip_headsign')->where('trip_headsign', '!=', '')->count();

            try {
                $stTotal = max(1, DB::table('stop_times')->count());
                $stArrival = DB::table('stop_times')->whereNotNull('arrival_time')->count();
            } catch (\Throwable) {
                $stTotal = 1;
                $stArrival = 0;
            }

            return [
                ['category' => 'Stops',      'metric' => 'Named',      'score' => (int) round($stopsNamed    / $stopsTotal    * 100)],
                ['category' => 'Stops',      'metric' => 'Geocoded',    'score' => (int) round($stopsGeocoded / $stopsTotal    * 100)],
                ['category' => 'Routes',     'metric' => 'Named',       'score' => (int) round($routesNamed   / $routesTotal   * 100)],
                ['category' => 'Routes',     'metric' => 'Colored',     'score' => (int) round($routesColored / $routesTotal   * 100)],
                ['category' => 'Trips',      'metric' => 'Has shape',   'score' => (int) round($tripsShapes   / $tripsTotal    * 100)],
                ['category' => 'Trips',      'metric' => 'Headsign',    'score' => (int) round($tripsHeadsign / $tripsTotal    * 100)],
                ['category' => 'Stop times', 'metric' => 'Has arrival', 'score' => (int) round($stArrival     / $stTotal       * 100)],
            ];
        });

        $overall = count($checks) > 0 ? (int) round(collect($checks)->avg('score')) : 0;

        return response()->json(['checks' => $checks, 'overall' => $overall]);
    }

    public function contributorLeaderboard(): JsonResponse
    {
        $board = Cache::remember('console:contrib_leaderboard', 300, function () {
            return DB::table('contributions')
                ->where('contributions.status', 'approved')
                ->join('users', 'users.id', '=', 'contributions.user_id')
                ->selectRaw('contributions.user_id, users.name, users.email, COUNT(*) AS approved_count, MAX(contributions.updated_at) AS last_approved_at')
                ->groupBy('contributions.user_id', 'users.name', 'users.email')
                ->orderByRaw('approved_count DESC')
                ->limit(10)
                ->get();
        });

        return response()->json($board->values());
    }

    public function platformRevenue(): JsonResponse
    {
        $data = Cache::remember('console:platform_revenue', 300, function () {
            try {
                $totalBalance  = (float) DB::table('wallets')->sum('balance');
                $walletCount   = (int)   DB::table('wallets')->count();
                $topAgencies   = DB::table('wallets')
                    ->where('wallets.entity_type', 'agency')
                    ->join('agencies', 'agencies.agency_id', '=', 'wallets.entity_id')
                    ->select('agencies.agency_name', 'wallets.balance', 'wallets.currency', 'wallets.last_credited_at')
                    ->orderByDesc('wallets.balance')
                    ->limit(5)
                    ->get();

                return ['total_balance' => $totalBalance, 'wallet_count' => $walletCount, 'top_agencies' => $topAgencies];
            } catch (\Throwable) {
                return ['total_balance' => 0.0, 'wallet_count' => 0, 'top_agencies' => []];
            }
        });

        return response()->json($data);
    }

    public function onboardingFunnel(): JsonResponse
    {
        $counts = Cache::remember('console:onboarding_funnel', 300, function () {
            try {
                return DB::table('agencies')
                    ->selectRaw('onboarding_status, COUNT(*) AS count')
                    ->groupBy('onboarding_status')
                    ->get()
                    ->keyBy('onboarding_status');
            } catch (\Throwable) {
                return collect();
            }
        });

        $steps = [
            ['step' => 'profile_pending',  'label' => 'Not started'],
            ['step' => 'profile_set',      'label' => 'Profile set'],
            ['step' => 'routes_added',     'label' => 'Routes added'],
            ['step' => 'split_configured', 'label' => 'Revenue set'],
            ['step' => 'staff_invited',    'label' => 'Staff invited'],
            ['step' => 'active',           'label' => 'Active'],
        ];

        return response()->json(array_map(fn ($s) => [
            'step'  => $s['step'],
            'label' => $s['label'],
            'count' => (int) ($counts->get($s['step'])?->count ?? 0),
        ], $steps));
    }

    public function retentionCohort(): JsonResponse
    {
        $data = Cache::remember('console:retention_cohort', 600, function () {
            $cohorts = [];
            foreach ([7, 14, 30] as $days) {
                $cohortStart = now()->subDays($days * 2)->toDateString();
                $cohortEnd   = now()->subDays($days)->toDateString();
                $cohortSize  = DB::table('users')
                    ->whereDate('created_at', '>=', $cohortStart)
                    ->whereDate('created_at', '<=', $cohortEnd)
                    ->count();
                $retained = $cohortSize > 0
                    ? DB::table('users')
                        ->whereDate('created_at', '>=', $cohortStart)
                        ->whereDate('created_at', '<=', $cohortEnd)
                        ->whereDate('updated_at', '>=', now()->subDays($days)->toDateString())
                        ->count()
                    : 0;
                $cohorts[] = [
                    'period'      => "D{$days}",
                    'cohort_size' => $cohortSize,
                    'retained'    => $retained,
                    'pct'         => $cohortSize > 0 ? round($retained / $cohortSize * 100, 1) : 0.0,
                ];
            }
            return $cohorts;
        });

        return response()->json($data);
    }

    public function fleetPulse(): JsonResponse
    {
        $byAgency = Cache::remember('console:fleet_pulse', 60, function () {
            try {
                return DB::table('vehicle_positions')
                    ->where('vehicle_positions.recorded_at', '>=', now()->subMinutes(10))
                    ->join('vehicles', 'vehicles.id', '=', 'vehicle_positions.vehicle_id')
                    ->join('agencies', 'agencies.agency_id', '=', 'vehicles.agency_id')
                    ->selectRaw('agencies.agency_id, agencies.agency_name, COUNT(DISTINCT vehicle_positions.vehicle_id) AS active_count')
                    ->groupBy('agencies.agency_id', 'agencies.agency_name')
                    ->orderByRaw('active_count DESC')
                    ->get();
            } catch (\Throwable) {
                return collect();
            }
        });

        $totalActive   = (int) Cache::remember('console:fleet_total_active', 60, function () {
            try {
                return DB::table('vehicle_positions')
                    ->where('recorded_at', '>=', now()->subMinutes(10))
                    ->distinct()
                    ->count('vehicle_id');
            } catch (\Throwable) {
                return 0;
            }
        });

        $totalVehicles = (int) Cache::remember('console:fleet_total_vehicles', 300, fn () => DB::table('vehicles')->count());

        return response()->json([
            'total_active'   => $totalActive,
            'total_vehicles' => $totalVehicles,
            'by_agency'      => $byAgency->values(),
        ]);
    }

    public function complianceOverview(): JsonResponse
    {
        $data = Cache::remember('console:compliance_overview', 300, function () {
            try {
                $today = now()->toDateString();
                $warn  = now()->addDays(30)->toDateString();

                $vExpired = DB::table('vehicles')
                    ->where(fn ($q) => $q
                        ->whereDate('insurance_expiry', '<', $today)
                        ->orWhereDate('inspection_due', '<', $today)
                        ->orWhereDate('road_service_expiry', '<', $today)
                    )->count();

                $vWarn = DB::table('vehicles')
                    ->where(fn ($q) => $q
                        ->whereBetween('insurance_expiry', [$today, $warn])
                        ->orWhereBetween('inspection_due', [$today, $warn])
                        ->orWhereBetween('road_service_expiry', [$today, $warn])
                    )->count();

                $dExpired = DB::table('drivers')
                    ->where(fn ($q) => $q
                        ->whereDate('license_expiry', '<', $today)
                        ->orWhereDate('psvb_expiry', '<', $today)
                    )->count();

                $dWarn = DB::table('drivers')
                    ->where(fn ($q) => $q
                        ->whereBetween('license_expiry', [$today, $warn])
                        ->orWhereBetween('psvb_expiry', [$today, $warn])
                    )->count();

                return [
                    'vehicles_expired' => (int) $vExpired,
                    'vehicles_warning' => (int) $vWarn,
                    'drivers_expired'  => (int) $dExpired,
                    'drivers_warning'  => (int) $dWarn,
                ];
            } catch (\Throwable) {
                return ['vehicles_expired' => 0, 'vehicles_warning' => 0, 'drivers_expired' => 0, 'drivers_warning' => 0];
            }
        });

        return response()->json($data);
    }
}
