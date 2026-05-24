<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\Contribution;
use App\Models\User;
use App\Models\UserBadge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommunityController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->refresh();

        $submissionsCount = Contribution::where('user_id', $user->id)->count();
        $badgesCount      = UserBadge::where('user_id', $user->id)->count();

        $badgesPreview = $user->badges()
            ->orderByPivot('earned_at', 'desc')
            ->limit(3)
            ->get(['badges.id', 'slug', 'name', 'icon', 'color'])
            ->map(fn ($b) => ['slug' => $b->slug, 'name' => $b->name, 'icon' => $b->icon, 'color' => $b->color]);

        return response()->json([
            'points'               => $user->points,
            'level'                => $user->level(),
            'level_label'          => $user->levelLabel(),
            'points_to_next_level' => $user->pointsToNextLevel(),
            'next_level_label'     => $user->nextLevelLabel(),
            'submissions_count'    => $submissionsCount,
            'badges_count'         => $badgesCount,
            'badges_preview'       => $badgesPreview,
        ]);
    }

    public function badges(Request $request): JsonResponse
    {
        $user      = $request->user();
        $earnedIds = UserBadge::where('user_id', $user->id)->pluck('badge_id', 'badge_id')->toArray();

        $allBadges = Badge::all()->map(function ($badge) use ($earnedIds, $user) {
            $earned   = isset($earnedIds[$badge->id]);
            $earnedAt = $earned
                ? UserBadge::where('user_id', $user->id)->where('badge_id', $badge->id)->value('earned_at')
                : null;

            return array_merge($badge->toArray(), [
                'earned'    => $earned,
                'earned_at' => $earnedAt,
            ]);
        });

        return response()->json([
            'earned' => $allBadges->filter(fn ($b) => $b['earned'])->values(),
            'locked' => $allBadges->filter(fn ($b) => !$b['earned'])->values(),
        ]);
    }

    public function leaderboard(): JsonResponse
    {
        $users = User::where('points', '>', 0)
            ->orderByDesc('points')
            ->limit(20)
            ->get(['id', 'name', 'avatar', 'points']);

        return response()->json(['data' => $users]);
    }
}
