<?php

namespace App\Services;

use App\Models\ReportVote;
use App\Models\TransitReport;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Fetch active reports strictly within the user's current map viewport.
     */
    public function getReportsInViewport(float $north, float $south, float $east, float $west): array
    {
        $envelope = "ST_MakeEnvelope(?, ?, ?, ?, 4326)";

        return TransitReport::select('id', 'type', 'user_id', 'upvotes', 'downvotes', 'created_at', 'expires_at')
            ->selectRaw("ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng")
            ->with('user:id,name,points')
            ->whereRaw("location::geometry && $envelope", [$west, $south, $east, $north])
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->limit(50)
            ->get()
            ->map(fn($r) => [
                'id'         => $r->id,
                'type'       => $r->type,
                'lat'        => (float) $r->lat,
                'lng'        => (float) $r->lng,
                'upvotes'    => $r->upvotes,
                'downvotes'  => $r->downvotes,
                'created_at' => $r->created_at->toIso8601String(),
                'expires_at' => $r->expires_at->toIso8601String(),
                'reporter'   => $r->user ? [
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
     * Store a new crowdsourced report, rejecting duplicates near the same location.
     *
     * Authenticated users: dedup within 100 m if they already have an active report
     * of the same type in the area.
     * Guests: dedup within 50 m against any active report of the same type (anti-spam).
     *
     * @throws \RuntimeException('duplicate') when a too-close report already exists.
     */
    public function createReport(array $data): TransitReport
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
            // For authenticated users, restrict to their own reports only.
            // For guests, any nearby report of the same type counts as a duplicate.
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->exists();

        if ($isDuplicate) {
            throw new \RuntimeException('duplicate');
        }

        $ttlMinutes = match ($data['type']) {
            'accident', 'flooded_route' => 120,
            'road_blocked', 'police_check' => 90,
            'traffic_jam', 'stage_queue'   => 45,
            'security'                     => 30, // volatile situation
            default                        => 60,
        };

        return TransitReport::create([
            'user_id'    => $userId,
            'type'       => $data['type'],
            'location'   => DB::raw("ST_SetSRID(ST_MakePoint({$data['lng']}, {$data['lat']}), 4326)"),
            'expires_at' => now()->addMinutes($ttlMinutes),
            'status'     => 'active',
        ]);
    }

    /**
     * Cast a vote on a report.
     *
     * - Voting the same direction twice toggles the vote off.
     * - Voting the opposite direction switches the vote.
     * - A report is auto-dismissed when downvotes ≥ 5 and downvotes > 2× upvotes.
     *
     * Returns the updated { upvotes, downvotes } counts.
     */
    public function voteReport(TransitReport $report, string $vote, ?int $userId, string $ipHash): array
    {
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
                    $report->decrement($old   === 'up' ? 'upvotes' : 'downvotes');
                    $report->increment($vote   === 'up' ? 'upvotes' : 'downvotes');
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

        // Auto-dismiss when community consensus says it's wrong
        if ($report->downvotes >= 5 && $report->downvotes > $report->upvotes * 2) {
            $report->update(['status' => 'dismissed']);
        }

        return ['upvotes' => $report->upvotes, 'downvotes' => $report->downvotes];
    }
}
