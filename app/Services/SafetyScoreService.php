<?php

namespace App\Services;

use App\Models\TransitReport;
use Illuminate\Support\Facades\Log;

/**
 * Night-walking safety scoring for itinerary alternatives.
 *
 * After dark (19:00–05:00 Nairobi), each itinerary gets a safety score
 * derived from how much walking it involves and how many active community
 * reports sit near its walking legs. The best-scoring itinerary is flagged
 * `safer: true` when it's meaningfully better, so the app can show a
 * "Safer route" badge and offer safest-first ranking at night.
 *
 * Ranking-level by design (no OTP graph rebuild): honest, data-driven
 * re-ranking rather than claiming true safest-path routing.
 */
class SafetyScoreService
{
    /** Reports within this distance of a walk leg count against it (m). */
    private const REPORT_RADIUS_M = 150;
    /** Minimum relative score gap before flagging a "safer" option. */
    private const SAFER_MARGIN = 0.20;

    public function isNight(): bool
    {
        $hour = (int) now()->timezone('Africa/Nairobi')->format('G');
        return $hour >= 19 || $hour <= 5;
    }

    /**
     * Annotate itineraries in place with `safety` => ['score' => 0..100, 'safer' => bool].
     * Higher score = safer. No-op outside night hours.
     */
    public function annotate(array &$itineraries): void
    {
        if (!$this->isNight() || count($itineraries) === 0) return;

        try {
            $reports = $this->activeReportPoints();
            $scores  = [];

            foreach ($itineraries as $i => $itinerary) {
                $scores[$i] = $this->scoreItinerary($itinerary, $reports);
            }

            $best = max($scores);
            foreach ($itineraries as $i => &$itinerary) {
                $itinerary['safety'] = [
                    'score' => $scores[$i],
                    'safer' => false,
                ];
            }
            unset($itinerary);

            // Flag the winner only when it clearly beats the runner-up.
            if (count($scores) > 1) {
                $sorted = $scores;
                rsort($sorted);
                $margin = $sorted[0] > 0 ? ($sorted[0] - $sorted[1]) / max($sorted[0], 1) : 0;
                if ($margin >= self::SAFER_MARGIN || $sorted[0] - $sorted[1] >= 15) {
                    $bestIdx = array_search($best, $scores, true);
                    $itineraries[$bestIdx]['safety']['safer'] = true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('SafetyScore: annotation failed: ' . $e->getMessage());
        }
    }

    /** Active report locations as [lat, lng] pairs (cheap, bounded). */
    private function activeReportPoints(): array
    {
        return TransitReport::query()
            ->selectRaw('ST_Y(location::geometry) as rep_lat, ST_X(location::geometry) as rep_lng')
            ->where('created_at', '>=', now()->subHours(6))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->limit(300)
            ->get()
            ->map(fn ($r) => [(float) $r->rep_lat, (float) $r->rep_lng])
            ->all();
    }

    /**
     * Score 0..100. Penalties: walking distance at night (dominant term) and
     * active reports near the walking legs.
     */
    private function scoreItinerary(array $itinerary, array $reports): int
    {
        $walkM = (float) ($itinerary['total_walk_distance'] ?? 0);

        // Walking penalty: 0 m → 0, 1500 m → ~60.
        $walkPenalty = min(60, ($walkM / 1500) * 60);

        // Report penalty: 8 points per report near a walk leg, capped at 40.
        $nearReports = 0;
        foreach (($itinerary['segments'] ?? []) as $segment) {
            if (($segment['mode'] ?? '') !== 'WALK') continue;
            foreach ($segment['coordinates'] ?? [] as $k => $coord) {
                if ($k % 5 !== 0) continue; // sample every 5th vertex
                foreach ($reports as $r) {
                    if ($this->distM($coord[0], $coord[1], $r[0], $r[1]) <= self::REPORT_RADIUS_M) {
                        $nearReports++;
                        break;
                    }
                }
            }
        }
        $reportPenalty = min(40, $nearReports * 8);

        return (int) round(max(0, 100 - $walkPenalty - $reportPenalty));
    }

    private function distM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dy = ($lat2 - $lat1) * 111_320;
        $dx = ($lng2 - $lng1) * 111_320 * cos(deg2rad($lat1));
        return sqrt($dx * $dx + $dy * $dy);
    }
}
