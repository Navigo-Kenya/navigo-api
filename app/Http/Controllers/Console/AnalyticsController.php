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
                    ->get();
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
                'submitted' => $group->sum('count'),
                'approved'  => $group->where('status', 'approved')->sum('count'),
                'declined'  => $group->whereIn('status', ['declined', 'rejected'])->sum('count'),
            ])->values();
        });

        return response()->json(['data' => $data]);
    }

    public function userGrowth(Request $request): JsonResponse
    {
        $range = $request->input('range', '30d');
        $days  = match ($range) { '7d' => 7, '90d' => 90, default => 90 };
        $since = now()->subDays($days);

        $data = Cache::remember("console:analytics:growth:{$days}", 300, function () use ($since) {
            return User::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as new_users')
            )
            ->where('created_at', '>=', $since)
            ->groupBy('date')
            ->orderBy('date')
            ->get();
        });

        return response()->json(['data' => $data]);
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
