<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function overview(): JsonResponse
    {
        $data = Cache::remember('console:analytics:overview', 300, function () {
            $last30 = now()->subDays(30);

            return [
                'total_journeys'         => $this->journeyCount($last30),
                'unique_users'           => User::where('updated_at', '>=', $last30)->distinct()->count(),
                'accepted_contributions' => Contribution::where('status', 'approved')
                    ->where('updated_at', '>=', $last30)->count(),
                'top_routes'             => $this->topRoutes(5),
            ];
        });

        return response()->json($data);
    }

    public function journeys(Request $request): JsonResponse
    {
        $range = $request->input('range', '30d');
        $days  = match ($range) { '7d' => 7, '90d' => 90, default => 30 };
        $since = now()->subDays($days);

        $data = Cache::remember("console:analytics:journeys:{$days}", 300, function () use ($since) {
            try {
                return DB::table('journey_logs')
                    ->select(
                        DB::raw('DATE(created_at) as date'),
                        DB::raw('COUNT(*) as total'),
                        DB::raw("SUM(CASE WHEN type = 'standard' THEN 1 ELSE 0 END) as standard"),
                        DB::raw("SUM(CASE WHEN type = 'ai' THEN 1 ELSE 0 END) as ai_planned")
                    )
                    ->where('created_at', '>=', $since)
                    ->groupBy('date')
                    ->orderBy('date')
                    ->get()
                    ->map(fn ($row) => [
                        'date'       => $row->date,
                        'total'      => (int) $row->total,
                        'standard'   => (int) $row->standard,
                        'ai_planned' => (int) $row->ai_planned,
                    ])
                    ->values()
                    ->toArray();
            } catch (\Throwable) {
                return [];
            }
        });

        return response()->json(['data' => $data, 'days' => $days]);
    }

    public function searches(): JsonResponse
    {
        $data = Cache::remember('console:analytics:searches', 300, function () {
            try {
                return DB::table('journey_logs')
                    ->select('origin_name', 'destination_name', DB::raw('COUNT(*) as count'))
                    ->groupBy('origin_name', 'destination_name')
                    ->orderByDesc('count')
                    ->limit(20)
                    ->get();
            } catch (\Throwable) {
                return [];
            }
        });

        return response()->json(['data' => $data]);
    }

    public function contributions(Request $request): JsonResponse
    {
        $range = $request->input('range', '30d');
        $days  = match ($range) { '7d' => 7, '90d' => 90, default => 60 };
        $since = now()->subDays($days);

        $data = Cache::remember("console:analytics:contributions:{$days}", 300, function () use ($since) {
            $rows = Contribution::select(
                DB::raw('DATE(created_at) as date'),
                'status',
                DB::raw('COUNT(*) as count')
            )
            ->where('created_at', '>=', $since)
            ->groupBy('date', 'status')
            ->orderBy('date')
            ->get();

            return $rows->groupBy('date')->map(fn ($group, $date) => [
                'date'      => $date,
                'submitted' => (int) $group->sum('count'),
                'approved'  => (int) $group->where('status', 'approved')->sum('count'),
                'declined'  => (int) $group->whereIn('status', ['declined', 'rejected'])->sum('count'),
            ])->values()->toArray();
        });

        return response()->json(['data' => $data]);
    }

    public function userGrowth(Request $request): JsonResponse
    {
        $range = $request->input('range', '30d');
        $days  = match ($range) { '7d' => 7, '90d' => 90, default => 90 };
        $since = now()->subDays($days);

        $data = Cache::remember("console:analytics:growth:{$days}", 300, function () use ($since) {
            $base = User::where('created_at', '<', $since)->count();

            $rows = User::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as new_users')
            )
            ->where('created_at', '>=', $since)
            ->groupBy('date')
            ->orderBy('date')
            ->get();

            $cumulative = $base;
            return $rows->map(function ($row) use (&$cumulative) {
                $cumulative += (int) $row->new_users;
                return [
                    'date'        => $row->date,
                    'new_users'   => (int) $row->new_users,
                    'total_users' => $cumulative,
                ];
            })->values()->toArray();
        });

        return response()->json(['data' => $data]);
    }

    public function tripVariance(Request $request): JsonResponse
    {
        $routeId = $request->query('route_id');
        $days    = max(1, min(90, (int) ($request->query('days', 30))));
        $since   = now()->subDays($days)->toDateString();

        $q = DB::table('on_time_performance')
            ->where('date', '>=', $since)
            ->selectRaw(
                'route_id,
                 COUNT(*) as sample_days,
                 SUM(total_trips) as total_trips,
                 SUM(on_time_trips) as on_time_trips,
                 ROUND(AVG(avg_delay_s)::numeric, 1) as avg_delay_s,
                 ROUND(
                   100.0 * SUM(on_time_trips) / NULLIF(SUM(total_trips), 0), 1
                 ) as on_time_pct'
            )
            ->groupBy('route_id');

        if ($routeId) {
            $q->where('route_id', $routeId);
        } elseif ($scope = $this->agencyScope($request)) {
            $q->whereIn('route_id', function ($sub) use ($scope) {
                $sub->select('route_id')->from('routes')->whereIn('agency_id', $scope);
            });
        }

        $rows = $q->orderBy('avg_delay_s', 'desc')->get();

        // Attach route short names
        $routeIds  = $rows->pluck('route_id')->unique()->toArray();
        $routeNames = DB::table('routes')
            ->whereIn('route_id', $routeIds)
            ->pluck('route_short_name', 'route_id');

        $rows = $rows->map(fn ($r) => [
            'route_id'         => $r->route_id,
            'route_short_name' => $routeNames[$r->route_id] ?? $r->route_id,
            'sample_days'      => (int) $r->sample_days,
            'total_trips'      => (int) $r->total_trips,
            'on_time_trips'    => (int) $r->on_time_trips,
            'on_time_pct'      => (float) ($r->on_time_pct ?? 0),
            'avg_delay_s'      => (float) ($r->avg_delay_s ?? 0),
            'avg_delay_min'    => round((float) ($r->avg_delay_s ?? 0) / 60, 1),
        ]);

        return response()->json(['data' => $rows, 'days' => $days]);
    }

    private function journeyCount(\Carbon\Carbon $since): int
    {
        try {
            return DB::table('journey_logs')->where('created_at', '>=', $since)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function topRoutes(int $limit): array
    {
        try {
            return DB::table('journey_logs')
                ->select('primary_route', DB::raw('COUNT(*) as count'))
                ->whereNotNull('primary_route')
                ->groupBy('primary_route')
                ->orderByDesc('count')
                ->limit($limit)
                ->get()
                ->toArray();
        } catch (\Throwable) {
            return [];
        }
    }
}
