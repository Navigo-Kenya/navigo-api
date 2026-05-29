<?php

namespace App\Services;

use App\Models\ServiceCalendar;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;

class GtfsValidatorService
{
    private GtfsValidationResult $result;

    public function validate(): GtfsValidationResult
    {
        $this->result = new GtfsValidationResult();

        $checks = [
            'validateServiceIds',
            'validateStopTimeCoverage',
            'validateStopSequenceUniqueness',
            'validateTimingLogic',
            'validateMonotonicTimes',
            'validateCalendarDateRanges',
            'validatePatternRefs',
            'validateFrequencies',
            'validateStopShapeProximity',
        ];

        foreach ($checks as $check) {
            try {
                $this->$check();
            } catch (\Throwable $e) {
                $this->result->addWarning(
                    'W_CHECK_FAILED',
                    "Check '{$check}' could not run: " . $e->getMessage()
                );
            }
        }

        return $this->result->finalize();
    }

    private function validateServiceIds(): void
    {
        $orphaned = DB::table('trips')
            ->leftJoin('service_calendars', 'trips.service_id', '=', 'service_calendars.service_id')
            ->whereNull('service_calendars.service_id')
            ->pluck('trips.trip_id');

        foreach ($orphaned as $tripId) {
            $this->result->addError(
                'E001_INVALID_SERVICE_ID',
                "Trip references service_id that does not exist in service_calendars.",
                $tripId
            );
        }
    }

    private function validateStopTimeCoverage(): void
    {
        $missing = Trip::where('scheduling_type', 'scheduled')
            ->doesntHave('stopTimes')
            ->pluck('trip_id');

        foreach ($missing as $tripId) {
            $this->result->addError(
                'E002_NO_STOP_TIMES',
                "Scheduled trip has no stop_times.",
                $tripId
            );
        }
    }

    private function validateStopSequenceUniqueness(): void
    {
        $duplicates = DB::table('stop_times')
            ->select('trip_id', 'stop_sequence', DB::raw('COUNT(*) as cnt'))
            ->groupBy('trip_id', 'stop_sequence')
            ->havingRaw('COUNT(*) > 1')
            ->limit(100)
            ->get();

        foreach ($duplicates as $row) {
            $this->result->addError(
                'E003_DUPLICATE_STOP_SEQUENCE',
                "Duplicate stop_sequence {$row->stop_sequence}.",
                $row->trip_id
            );
        }
    }

    private function validateTimingLogic(): void
    {
        $bad = DB::select("
            SELECT trip_id, stop_sequence
            FROM stop_times
            WHERE arrival_time > departure_time
            LIMIT 100
        ");

        foreach ($bad as $row) {
            $this->result->addError(
                'E004_ARRIVAL_AFTER_DEPARTURE',
                "arrival_time > departure_time at stop_sequence {$row->stop_sequence}.",
                $row->trip_id
            );
        }
    }

    private function validateMonotonicTimes(): void
    {
        $bad = DB::select("
            SELECT a.trip_id, a.stop_sequence
            FROM stop_times a
            JOIN stop_times b
              ON a.trip_id = b.trip_id AND b.stop_sequence = a.stop_sequence + 1
            WHERE a.departure_time IS NOT NULL
              AND b.arrival_time   IS NOT NULL
              AND a.departure_time > b.arrival_time
            LIMIT 100
        ");

        foreach ($bad as $row) {
            $this->result->addError(
                'E005_NON_MONOTONIC_TIMES',
                "departure_time at seq {$row->stop_sequence} exceeds arrival_time of next stop.",
                $row->trip_id
            );
        }
    }

    private function validateCalendarDateRanges(): void
    {
        $bad = ServiceCalendar::where('end_date', '<', DB::raw('start_date'))->get();

        foreach ($bad as $cal) {
            $this->result->addError(
                'E006_INVALID_DATE_RANGE',
                "end_date is before start_date.",
                $cal->service_id
            );
        }
    }

    private function validatePatternRefs(): void
    {
        $orphaned = DB::table('trips')
            ->leftJoin('route_patterns', 'trips.route_pattern_id', '=', 'route_patterns.id')
            ->whereNotNull('trips.route_pattern_id')
            ->whereNull('route_patterns.id')
            ->pluck('trips.trip_id');

        foreach ($orphaned as $tripId) {
            $this->result->addError(
                'E007_INVALID_ROUTE_PATTERN',
                "Trip references route_pattern_id that does not exist.",
                $tripId
            );
        }
    }

    private function validateFrequencies(): void
    {
        $bad = DB::table('trip_frequencies')
            ->where(function ($q) {
                $q->where('start_time', '>=', DB::raw('end_time'))
                  ->orWhere('headway_secs', '<=', 0);
            })
            ->select('id', 'trip_id')
            ->limit(100)
            ->get();

        foreach ($bad as $row) {
            $this->result->addError(
                'E008_INVALID_FREQUENCY',
                "Frequency row has start_time >= end_time or headway_secs <= 0.",
                $row->trip_id
            );
        }
    }

    private function validateStopShapeProximity(): void
    {
        $far = DB::select("
            SELECT st.trip_id, st.stop_id
            FROM stop_times st
            JOIN trips t  ON t.trip_id  = st.trip_id   AND t.shape_id IS NOT NULL
            JOIN shapes s ON s.shape_id = t.shape_id   AND s.path     IS NOT NULL
            JOIN stops  p ON p.id       = st.stop_id   AND p.location IS NOT NULL
            WHERE NOT ST_DWithin(p.location::geography, s.path::geography, 500)
            LIMIT 50
        ");

        foreach ($far as $row) {
            $this->result->addWarning(
                'W001_STOP_FAR_FROM_SHAPE',
                "Stop is more than 500m from the trip's shape.",
                "{$row->trip_id}:{$row->stop_id}"
            );
        }
    }
}
