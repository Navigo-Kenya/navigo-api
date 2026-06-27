<?php

namespace App\Services;

use App\Models\FareAttribute;
use App\Models\FareModifier;
use App\Models\FareRule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FareService
{
    /**
     * Attach fare resolution to every transit segment in an itinerary.
     * WALK segments are returned unchanged (no fare field added).
     * Internal _route_id / _agency_id fields are stripped before returning.
     */
    public function resolveItineraryFares(array $segments, ?Carbon $departureTime = null): array
    {
        $dt = $departureTime ?? now()->timezone('Africa/Nairobi');

        return array_map(function (array $segment) use ($dt) {
            // Strip internal routing fields regardless of mode
            $clean = array_diff_key($segment, array_flip(['_route_id', '_agency_id']));

            if ($segment['mode'] === 'WALK') {
                return $clean;
            }

            $routeId  = $segment['_route_id']  ?? null;
            $agencyId = $segment['_agency_id'] ?? '';
            $fromLat  = (float) ($segment['from']['lat'] ?? 0);
            $fromLng  = (float) ($segment['from']['lng'] ?? 0);
            $toLat    = (float) ($segment['to']['lat']   ?? 0);
            $toLng    = (float) ($segment['to']['lng']   ?? 0);

            $clean['fare'] = ($routeId && $fromLat && $fromLng && $toLat && $toLng)
                ? $this->resolveSegmentFare($routeId, $agencyId, $fromLat, $fromLng, $toLat, $toLng, $dt)
                : null;

            return $clean;
        }, $segments);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Resolution
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveSegmentFare(
        string  $routeId,
        string  $agencyId,
        float   $fromLat,
        float   $fromLng,
        float   $toLat,
        float   $toLng,
        Carbon  $time,
    ): ?array {
        $confidence = null;

        // Priority 1 — Route-level flat fare (most precise)
        $rule = FareRule::where('route_id', $routeId)
            ->whereNull('origin_id')
            ->whereNull('destination_id')
            ->first();

        if ($rule) {
            $confidence = 'exact';
        }

        // Priority 2 — Zone-pair fare
        $originZone = null;
        $destZone   = null;
        if (!$rule) {
            $originZone = $this->zoneForPoint($fromLat, $fromLng);
            $destZone   = $this->zoneForPoint($toLat, $toLng);

            if ($originZone && $destZone) {
                $rule = FareRule::where('origin_id', $originZone)
                    ->where('destination_id', $destZone)
                    ->first()
                    ?? FareRule::where('origin_id', $destZone)
                        ->where('destination_id', $originZone)
                        ->first();

                if ($rule) {
                    $confidence = 'zone';
                }
            }
        }

        // Priority 3 — Zone + route combo
        if (!$rule && $originZone) {
            $rule = FareRule::where('route_id', $routeId)
                ->where(fn ($q) => $q
                    ->where('origin_id', $originZone)
                    ->orWhere('destination_id', $destZone ?? $originZone)
                )
                ->first();

            if ($rule) {
                $confidence = 'zone';
            }
        }

        if (!$rule) {
            return null;
        }

        $attr = FareAttribute::where('fare_id', $rule->fare_id)->first();
        if (!$attr) {
            return null;
        }

        $finalPrice = $this->applyModifiers(
            (float) $attr->price,
            $routeId,
            $agencyId,
            $originZone,
            $time,
        );

        return [
            'amount'     => (int) $finalPrice,
            'currency'   => $attr->currency_type ?? 'KES',
            'confidence' => $confidence,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Zone lookup (PostGIS)
    // ─────────────────────────────────────────────────────────────────────────

    private function zoneForPoint(float $lat, float $lng): ?string
    {
        $row = DB::selectOne(
            "SELECT zone_id FROM fare_zones
             WHERE ST_Contains(zone_polygon::geometry, ST_SetSRID(ST_MakePoint(?, ?), 4326))
             LIMIT 1",
            [$lng, $lat],
        );

        return $row?->zone_id ?? null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Modifier application (mirrors ConsoleFareController::buildPreviewResponse)
    // ─────────────────────────────────────────────────────────────────────────

    private function applyModifiers(
        float   $basePrice,
        string  $routeId,
        string  $agencyId,
        ?string $zoneId,
        Carbon  $time,
    ): float {
        $now = now();

        // Active modifiers are cached for 60 s so console changes propagate quickly.
        $modifiers = Cache::remember('fare:modifiers:active', 60, fn () =>
            FareModifier::where('is_active', true)
                ->where(fn ($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', $now))
                ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', $now))
                ->get()
        );

        $applicable = $modifiers->filter(
            fn ($mod) => $this->modifierApplies($mod, $routeId, $agencyId, $zoneId, $time)
        );

        $price = $basePrice;

        // Apply multipliers first, then fixed surcharges (same order as preview controller)
        foreach ($applicable as $mod) {
            if ($mod->multiplier !== null) {
                $price *= (float) $mod->multiplier;
            }
        }
        foreach ($applicable as $mod) {
            if ($mod->fixed_surcharge !== null) {
                $price += (float) $mod->fixed_surcharge;
            }
        }

        // Round to nearest 5 KES
        return round($price / 5) * 5;
    }

    private function modifierApplies(
        FareModifier $mod,
        string       $routeId,
        string       $agencyId,
        ?string      $zoneId,
        Carbon       $time,
    ): bool {
        $scopeMatch = match ($mod->applies_to) {
            'all'    => true,
            'route'  => $mod->applies_to_id === $routeId,
            'agency' => $mod->applies_to_id === $agencyId,
            'zone'   => $zoneId !== null && $mod->applies_to_id === $zoneId,
            default  => false,
        };

        if (!$scopeMatch) {
            return false;
        }

        $cond = $mod->condition_data ?? [];

        return match ($mod->type) {
            'peak_hours'  => $this->matchesPeakHours($cond, $time),
            'day_of_week' => $this->matchesDayOfWeek($cond, $time),
            default       => true,
        };
    }

    /**
     * condition_data shape: {"from":"07:00","to":"09:30","days":["Mon","Tue","Wed","Thu","Fri"]}
     */
    private function matchesPeakHours(array $cond, Carbon $time): bool
    {
        $days = $cond['days'] ?? null;
        if ($days && !in_array($time->format('D'), $days, true)) {
            return false;
        }

        $from = $cond['from'] ?? null;
        $to   = $cond['to']   ?? null;
        if ($from && $to) {
            $hhmm = $time->format('H:i');
            if ($hhmm < $from || $hhmm > $to) {
                return false;
            }
        }

        return true;
    }

    /**
     * condition_data shape: {"days":["Sat","Sun"]}
     */
    private function matchesDayOfWeek(array $cond, Carbon $time): bool
    {
        $days = $cond['days'] ?? [];
        return in_array($time->format('D'), $days, true);
    }
}
