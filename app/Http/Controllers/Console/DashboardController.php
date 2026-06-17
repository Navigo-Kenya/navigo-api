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
                'last_sync'    => Cache::get('otp:last_sync'),
                'last_duration' => Cache::get('otp:last_duration'),
                'status'       => Cache::get('otp:sync_status', 'unknown'),
                'error'        => Cache::get('otp:sync_error'),
            ],
            'queue' => [
                'pending' => DB::table('jobs')->count(),
                'failed'  => DB::table('failed_jobs')->count(),
            ],
        ]);
    }
}
