<?php

namespace App\Services;

use App\Models\Badge;
use App\Models\Contribution;
use App\Models\User;
use App\Models\UserBadge;
use Illuminate\Support\Facades\DB;

class ContributionService
{
    private const POINTS_MAP = [
        'delay_report'     => 3,
        'stop_review'      => 10,
        'stop_photo'       => 5,
        'stop_edit'        => 15,
        'route_correction' => 15,
        'new_stop'         => 50,
    ];

    private const AUTO_APPROVED = ['delay_report', 'stop_review'];

    public function create(User $user, array $data): array
    {
        $type      = $data['type'];
        $isAuto    = in_array($type, self::AUTO_APPROVED);
        $status    = $isAuto ? 'auto_approved' : 'pending';
        $expiresAt = $type === 'delay_report' ? now()->addHours(2) : null;

        $contribution = Contribution::create([
            'user_id'     => $user->id,
            'type'        => $type,
            'stop_id'     => $data['stop_id']     ?? null,
            'title'       => $data['title']       ?? $this->defaultTitle($type, $data),
            'description' => $data['description'] ?? null,
            'data'        => $data['data']        ?? null,
            'status'      => $status,
            'expires_at'  => $expiresAt,
        ]);

        $pointsAwarded = 0;
        if ($isAuto) {
            $pointsAwarded = $this->awardPoints($user, $contribution, self::POINTS_MAP[$type]);
        }

        $newBadges = $this->checkAndAwardBadges($user->fresh());

        return [
            'contribution'  => $contribution->load('stop'),
            'points_awarded' => $pointsAwarded,
            'new_badges'    => $newBadges,
            'new_level'     => null,
        ];
    }

    public function approve(Contribution $contribution): void
    {
        $contribution->update(['status' => 'approved', 'reviewed_at' => now()]);

        $user  = $contribution->user;
        $points = self::POINTS_MAP[$contribution->type] ?? 0;

        // Bonus 20 pts if first photo for this stop
        if ($contribution->type === 'stop_photo' && $contribution->stop_id) {
            $isFirst = Contribution::where('stop_id', $contribution->stop_id)
                ->where('type', 'stop_photo')
                ->where('status', 'approved')
                ->where('id', '!=', $contribution->id)
                ->doesntExist();
            if ($isFirst) $points += 20;
        }

        $this->awardPoints($user, $contribution, $points);
        $this->checkAndAwardBadges($user->fresh());
    }

    public function awardPoints(User $user, Contribution $contribution, int $points): int
    {
        if ($points <= 0) return 0;
        DB::table('users')->where('id', $user->id)->increment('points', $points);
        $contribution->update(['points_awarded' => $points]);
        return $points;
    }

    public function checkAndAwardBadges(User $user): array
    {
        $earnedIds   = UserBadge::where('user_id', $user->id)->pluck('badge_id')->toArray();
        $unearned    = Badge::whereNotIn('id', $earnedIds)->get();
        $stats       = $this->getUserStats($user);
        $newlyEarned = [];

        foreach ($unearned as $badge) {
            if ($this->meetsBadgeRequirement($badge, $stats)) {
                UserBadge::create([
                    'user_id'   => $user->id,
                    'badge_id'  => $badge->id,
                    'earned_at' => now(),
                ]);
                if ($badge->points_bonus > 0) {
                    DB::table('users')->where('id', $user->id)->increment('points', $badge->points_bonus);
                }
                $newlyEarned[] = $badge->slug;
            }
        }

        return $newlyEarned;
    }

    public function getUserStats(User $user): array
    {
        $contributions = Contribution::where('user_id', $user->id)->get();

        $byType         = $contributions->groupBy('type')->map->count();
        $approvedByType = $contributions->filter(fn ($c) => in_array($c->status, ['approved', 'auto_approved']))
                                        ->groupBy('type')->map->count();

        $user->refresh();

        return [
            'total'            => $contributions->count(),
            'by_type'          => $byType->toArray(),
            'approved_by_type' => $approvedByType->toArray(),
            'points'           => $user->points,
        ];
    }

    private function meetsBadgeRequirement(Badge $badge, array $stats): bool
    {
        return match ($badge->requirement_type) {
            'total_count'         => $stats['total'] >= $badge->requirement_value,
            'points'              => $stats['points'] >= $badge->requirement_value,
            'type_count'          => ($stats['by_type'][$badge->requirement_meta['type'] ?? ''] ?? 0) >= $badge->requirement_value,
            'approved_type_count' => ($stats['approved_by_type'][$badge->requirement_meta['type'] ?? ''] ?? 0) >= $badge->requirement_value,
            default               => false,
        };
    }

    private function defaultTitle(string $type, array $data): string
    {
        return match ($type) {
            'delay_report'     => ucfirst($data['data']['severity'] ?? 'Minor') . ' delay report',
            'stop_review'      => 'Stop review',
            'stop_photo'       => 'Stop photo',
            'stop_edit'        => 'Stop edit: ' . ($data['data']['field'] ?? 'info'),
            'route_correction' => 'Route correction',
            'new_stop'         => 'New stop: ' . ($data['data']['name'] ?? 'Unnamed'),
            default            => $type,
        };
    }
}
