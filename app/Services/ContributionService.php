<?php

namespace App\Services;

use App\Jobs\SendPushNotificationJob;
use App\Models\Badge;
use App\Models\Contribution;
use App\Models\Stop;
use App\Models\TransitReport;
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

    /** Daily-streak bonus cap (extra points per submission). */
    private const STREAK_BONUS_CAP = 7;

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

        $streakDays = $this->bumpStreak($user);

        $pointsAwarded = 0;
        if ($isAuto) {
            $streakBonus   = min(max($streakDays - 1, 0), self::STREAK_BONUS_CAP);
            $pointsAwarded = $this->awardPoints($user, $contribution, self::POINTS_MAP[$type] + $streakBonus);
        }

        $newBadges = $this->checkAndAwardBadges($user->fresh());

        return [
            'contribution'  => $contribution->load('stop'),
            'points_awarded' => $pointsAwarded,
            'new_badges'    => $newBadges,
            'new_level'     => null,
            'streak_days'   => $streakDays,
        ];
    }

    /**
     * Consecutive-day contribution streak. Same-day submissions keep the
     * current streak; a gap of more than one day resets it.
     */
    public function bumpStreak(User $user): int
    {
        $today = now()->timezone('Africa/Nairobi')->toDateString();
        $last  = $user->last_contribution_at?->toDateString();

        if ($last === $today) {
            return (int) $user->streak_days;
        }

        $yesterday = now()->timezone('Africa/Nairobi')->subDay()->toDateString();
        $newStreak = $last === $yesterday ? ((int) $user->streak_days) + 1 : 1;

        $user->forceFill([
            'streak_days'          => $newStreak,
            'last_contribution_at' => $today,
        ])->save();

        return $newStreak;
    }

    public function approve(Contribution $contribution, ?int $reviewerId = null): void
    {
        $contribution->update([
            'status'      => 'approved',
            'reviewed_at' => now(),
            'reviewed_by' => $reviewerId,
        ]);

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

        $this->applyApprovedEdit($contribution);

        if ($user) {
            $this->awardPoints($user, $contribution, $points);
            $this->checkAndAwardBadges($user->fresh());

            SendPushNotificationJob::dispatch(
                $user->id,
                'points_earned',
                'Contribution approved 🎉',
                "\"{$contribution->title}\" was approved — +{$points} Safiri Points",
                ['screen' => '/(tabs)/contribution'],
            )->onQueue('default');
        }
    }

    /**
     * Write approved stop edits through to the stops table. Currently applies
     * community landmarks ("in front of Hilton") — the field that powers
     * landmark-based boarding instructions.
     */
    private function applyApprovedEdit(Contribution $contribution): void
    {
        if ($contribution->type !== 'stop_edit' || !$contribution->stop_id) return;

        $field    = $contribution->data['field'] ?? null;
        $proposed = trim((string) ($contribution->data['proposed_value'] ?? ''));
        if ($proposed === '') return;

        if ($field === 'landmark') {
            Stop::where('id', $contribution->stop_id)->update(['landmark' => mb_substr($proposed, 0, 160)]);
        }
    }

    /**
     * Reject a contribution and notify the author so they know the outcome
     * and can improve their future submissions.
     */
    public function decline(Contribution $contribution, ?int $reviewerId = null, ?string $reason = null): void
    {
        $contribution->update([
            'status'         => 'rejected',
            'decline_reason' => $reason,
            'reviewed_at'    => now(),
            'reviewed_by'    => $reviewerId,
        ]);

        $user = $contribution->user;
        if ($user) {
            $body = $reason
                ? "Reason: {$reason}"
                : "It didn't meet our accuracy standards — keep contributing!";

            SendPushNotificationJob::dispatch(
                $user->id,
                'points_earned', // reuses the same opt-in toggle (contribution outcomes)
                'Contribution not approved',
                "\"{$contribution->title}\" — {$body}",
                ['screen' => '/(tabs)/contribution'],
            )->onQueue('default');
        }
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
        $approvedByType = $contributions->filter(fn ($c) => \in_array($c->status, ['approved', 'auto_approved']))
                                        ->groupBy('type')->map->count();

        $reports      = TransitReport::where('user_id', $user->id)->get();
        $reportByType = $reports->groupBy('type')->map->count();

        $user->refresh();

        return [
            'total'            => $contributions->count(),
            'by_type'          => $byType->toArray(),
            'approved_by_type' => $approvedByType->toArray(),
            'points'           => $user->points,
            'report_total'     => $reports->count(),
            'report_by_type'   => $reportByType->toArray(),
        ];
    }

    private function meetsBadgeRequirement(Badge $badge, array $stats): bool
    {
        return match ($badge->requirement_type) {
            'total_count'         => $stats['total'] >= $badge->requirement_value,
            'points'              => $stats['points'] >= $badge->requirement_value,
            'type_count'          => ($stats['by_type'][$badge->requirement_meta['type'] ?? ''] ?? 0) >= $badge->requirement_value,
            'approved_type_count' => ($stats['approved_by_type'][$badge->requirement_meta['type'] ?? ''] ?? 0) >= $badge->requirement_value,
            // Incident report badges
            'report_count'        => ($stats['report_total'] ?? 0) >= $badge->requirement_value,
            'report_type_count'   => ($stats['report_by_type'][$badge->requirement_meta['type'] ?? ''] ?? 0) >= $badge->requirement_value,
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
