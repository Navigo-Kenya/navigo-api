<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\User;
use Illuminate\Http\JsonResponse;
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
                'type'       => 'contribution_' . $c->status,
                'label'      => ucfirst($c->type) . ' contribution ' . $c->status,
                'meta'       => $c->user?->name ?? 'Unknown',
                'created_at' => $c->updated_at,
            ]);
        }

        $sorted = $events->sortByDesc('created_at')->values()->take(100);

        return response()->json(['data' => $sorted]);
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
