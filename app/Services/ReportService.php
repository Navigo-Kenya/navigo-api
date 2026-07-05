<?php

namespace App\Services;

use App\Jobs\SendPushNotificationJob;
use App\Models\ReportVote;
use App\Models\TransitReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReportService
{
    // Points awarded to authenticated reporters per incident type.
    // Higher for rare/safety-critical reports that take more courage to submit.
    private const REPORT_POINTS = [
        'accident'      => 5,
        'flooded_route' => 5,
        'security'      => 4,
        'road_blocked'  => 4,
        'police_check'  => 3,
        'breakdown'     => 3,
        'traffic_jam'   => 2,
        'stage_queue'   => 2,
        'fare_hike'     => 2,
    ];

    public function __construct(private ContributionService $contributionService) {}

    /**
     * Fetch active reports strictly within the user's current map viewport.
     * Includes a computed reliability score (0–1) for each report.
     */
    public function getReportsInViewport(float $north, float $south, float $east, float $west): array
    {
        $envelope = "ST_MakeEnvelope(?, ?, ?, ?, 4326)";

        return TransitReport::select('id', 'type', 'user_id', 'is_anonymous', 'upvotes', 'downvotes', 'created_at', 'expires_at')
            ->selectRaw("ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng")
            ->with('user:id,name,points')
            ->whereRaw("location::geometry && $envelope", [$west, $south, $east, $north])
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->limit(50)
            ->get()
            ->map(fn($r) => [
                'id'          => $r->id,
                'type'        => $r->type,
                'lat'         => (float) $r->lat,
                'lng'         => (float) $r->lng,
                'upvotes'     => $r->upvotes,
                'downvotes'   => $r->downvotes,
                'created_at'  => $r->created_at->toIso8601String(),
                'expires_at'  => $r->expires_at->toIso8601String(),
                'reliability' => $this->computeReliability($r),
                'reporter'    => ($r->user && !$r->is_anonymous) ? [
                    'name'  => $this->formatReporterName($r->user->name),
                    'level' => $r->user->levelLabel(),
                ] : null,
            ])
            ->all();
    }

    private function formatReporterName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name), 2);
        if (count($parts) === 2 && mb_strlen($parts[1]) > 0) {
            return $parts[0] . ' ' . mb_strtoupper(mb_substr($parts[1], 0, 1)) . '.';
        }
        return $parts[0];
    }

    /**
     * Store a new crowdsourced report.
     *
     * TTLs are calibrated for Nairobi conditions:
     *   - fare_hike stays high all day → 8 h
     *   - flooded roads drain slowly   → 3 h
     *   - traffic jams clear fast      → 45 min
     *   - stage queues are volatile    → 30 min
     *
     * Returns the report + gamification result so the controller can surface
     * points and new badges to the user immediately.
     *
     * @throws \RuntimeException('duplicate')
     */
    public function createReport(array $data): array
    {
        $userId = $data['user_id'] ?? null;
        $radius = $userId ? 100 : 50;

        $isDuplicate = TransitReport::where('type', $data['type'])
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->whereRaw(
                "ST_DWithin(location::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)",
                [$data['lng'], $data['lat'], $radius]
            )
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->exists();

        if ($isDuplicate) {
            throw new \RuntimeException('duplicate');
        }

        $ttlMinutes = match ($data['type']) {
            'accident'      => 120,  // 2 h — collisions take time to clear
            'flooded_route' => 180,  // 3 h — drainage is slow
            'road_blocked'  => 90,   // 1.5 h
            'breakdown'     => 60,   // 1 h — average tow wait
            'police_check'  => 60,   // 1 h — checkpoints move
            'security'      => 45,   // 45 min — volatile situations
            'traffic_jam'   => 45,   // 45 min — jams dissipate
            'stage_queue'   => 30,   // 30 min — queues clear quickly
            'fare_hike'     => 480,  // 8 h — fares stay high all day
            default         => 60,
        };

        $report = TransitReport::create([
            'user_id'      => $userId,
            'is_anonymous' => $data['is_anonymous'] ?? false,
            'type'         => $data['type'],
            'location'     => DB::raw("ST_SetSRID(ST_MakePoint({$data['lng']}, {$data['lat']}), 4326)"),
            'expires_at'   => now()->addMinutes($ttlMinutes),
            'status'       => 'active',
        ]);

        // Gamification: award points and check badges for authenticated reporters.
        $pointsAwarded = 0;
        $newBadges     = [];

        if ($userId) {
            $user = User::find($userId);
            if ($user) {
                $pts = self::REPORT_POINTS[$data['type']] ?? 2;
                DB::table('users')->where('id', $user->id)->increment('points', $pts);
                $pointsAwarded = $pts;
                $newBadges     = $this->contributionService->checkAndAwardBadges($user->fresh());
            }
        }

        return [
            'report'          => $report,
            'points_awarded'  => $pointsAwarded,
            'new_badges'      => $newBadges,
        ];
    }

    /**
     * Cast a vote on a report.
     *
     * - Voting the same direction again toggles the vote off.
     * - Voting the opposite direction switches the vote.
     * - Community validation bonus: +5 pts to the reporter when their report
     *   crosses the 5-upvote threshold for the first time.
     * - Enhanced auto-dismiss: reliability < 0.2 with ≥ 3 downvotes, OR the
     *   original heavy-downvote ratio check.
     */
    public function voteReport(TransitReport $report, string $vote, ?int $userId, string $ipHash): array
    {
        $prevUpvotes = $report->upvotes;

        DB::transaction(function () use ($report, $vote, $userId, $ipHash) {
            $query = ReportVote::where('report_id', $report->id);

            $existing = $userId
                ? $query->where('user_id', $userId)->first()
                : $query->whereNull('user_id')->where('ip_hash', $ipHash)->first();

            if ($existing) {
                if ($existing->vote === $vote) {
                    // Toggle off
                    $existing->delete();
                    $report->decrement($vote === 'up' ? 'upvotes' : 'downvotes');
                } else {
                    // Switch direction
                    $old = $existing->vote;
                    $existing->update(['vote' => $vote]);
                    $report->decrement($old  === 'up' ? 'upvotes' : 'downvotes');
                    $report->increment($vote === 'up' ? 'upvotes' : 'downvotes');
                }
            } else {
                ReportVote::create([
                    'report_id' => $report->id,
                    'user_id'   => $userId,
                    'ip_hash'   => $userId ? null : $ipHash,
                    'vote'      => $vote,
                ]);
                $report->increment($vote === 'up' ? 'upvotes' : 'downvotes');
            }
        });

        $report->refresh();

        // One-time community validation bonus when crossing 5 upvotes.
        if ($vote === 'up' && $prevUpvotes < 5 && $report->upvotes >= 5 && $report->user_id) {
            DB::table('users')->where('id', $report->user_id)->increment('points', 5);
            // Notify the reporter that their report reached community consensus.
            SendPushNotificationJob::dispatch(
                $report->user_id,
                'points_earned',
                'Your report is trending! 🔥',
                '5 people confirmed your report — +5 Safiri Points',
                ['screen' => '/(tabs)/map'],
            )->onQueue('default');
        }

        // Enhanced auto-dismiss: low reliability OR heavy negative ratio.
        $reliability = $this->computeReliability($report);
        if (($report->downvotes >= 3 && $reliability < 0.20)
            || ($report->downvotes >= 5 && $report->downvotes > $report->upvotes * 2)) {
            $report->update(['status' => 'dismissed']);
        }

        return ['upvotes' => $report->upvotes, 'downvotes' => $report->downvotes];
    }

    /**
     * Compute a reliability score (0.0–1.0) for a report.
     *
     * Components:
     *   voteScore      = Laplace-smoothed upvote ratio            (0.33–1.0)
     *   ageFactor      = sub-linear decay from birth → expiry      (1.0→0.0)
     *   communityFactor= +15% per upvote, capped at 5             (1.0→1.75)
     *   reporterFactor = +10% per user level above 1, max +40%    (1.0→1.40)
     *
     * A brand-new report with zero votes from a level-1 user scores ~0.5.
     * A heavily upvoted report from a trusted user can reach 1.0.
     * A heavily downvoted report near expiry drops towards 0 → auto-dismissed.
     */
    private function computeReliability(TransitReport $r): float
    {
        $voteScore = ($r->upvotes + 1) / ($r->upvotes + $r->downvotes + 2);

        $ttlSeconds = max(1, $r->expires_at->diffInSeconds($r->created_at));
        $ageSeconds = max(0, now()->diffInSeconds($r->created_at));
        $ageFactor  = 1.0 - sqrt(min(1.0, $ageSeconds / $ttlSeconds));

        $communityFactor = 1.0 + 0.15 * min($r->upvotes, 5);

        $reporterFactor = 1.0;
        if ($r->relationLoaded('user') && $r->user) {
            $reporterFactor = 1.0 + 0.1 * min($r->user->level() - 1, 4);
        }

        $score = $voteScore * max(0.0, $ageFactor) * $communityFactor * $reporterFactor;
        return round(min(1.0, max(0.0, $score)), 3);
    }
}
