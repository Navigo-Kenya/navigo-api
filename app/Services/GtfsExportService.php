<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\FareAttribute;
use App\Models\FareRule;
use App\Models\Level;
use App\Models\Pathway;
use App\Models\Route;
use App\Models\ServiceCalendar;
use App\Models\ServiceException;
use App\Models\Stop;
use App\Models\StopTime;
use App\Models\Trip;
use App\Models\TripFrequency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class GtfsExportService
{
    /**
     * Export all GTFS files as a zip archive.
     * Returns the absolute path to the canonical gtfs.zip in storage.
     */
    public function export(): string
    {
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gtfs_' . uniqid();
        File::makeDirectory($tmpDir, 0755, true);

        try {
            $this->writeAgency($tmpDir);
            $this->writeStops($tmpDir);
            $this->writeRoutes($tmpDir);
            $this->writeCalendar($tmpDir);
            $this->writeCalendarDates($tmpDir);
            $this->writeTrips($tmpDir);
            $this->writeFrequencies($tmpDir);
            $this->writeStopTimes($tmpDir);
            $this->writeShapes($tmpDir);
            $this->writeFareAttributes($tmpDir);
            $this->writeFareRules($tmpDir);
            $this->writeLevels($tmpDir);
            $this->writePathways($tmpDir);

            $storageDir    = storage_path('app/gtfs');
            $versionedPath = $storageDir . DIRECTORY_SEPARATOR . 'gtfs_' . now()->format('Ymd_His') . '.zip';
            $canonicalPath = $storageDir . DIRECTORY_SEPARATOR . 'gtfs.zip';

            File::ensureDirectoryExists($storageDir);

            $zip = new \ZipArchive();
            if ($zip->open($versionedPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException("Cannot create zip archive at {$versionedPath}");
            }

            foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*.txt') as $txtFile) {
                $zip->addFile($txtFile, basename($txtFile));
            }
            $zip->close();

            // Keep only the 5 most recent versioned zips
            $versions = glob($storageDir . DIRECTORY_SEPARATOR . 'gtfs_*.zip');
            if ($versions !== false) {
                rsort($versions);
                foreach (array_slice($versions, 5) as $old) {
                    @unlink($old);
                }
            }

            copy($versionedPath, $canonicalPath);

            Log::info("[GtfsExport] Zip written to {$canonicalPath}");

            return $canonicalPath;
        } finally {
            File::deleteDirectory($tmpDir);
        }
    }

    private function writeAgency(string $path): void
    {
        $rows = [['agency_id', 'agency_name', 'agency_url', 'agency_timezone', 'agency_lang']];

        $agencies = Agency::all();

        if ($agencies->isEmpty()) {
            Log::warning('GtfsExport: no agencies in DB, using hardcoded fallback');
            $rows[] = ['hopln', 'Hopln Nairobi', 'https://hopln.app', 'Africa/Nairobi', 'en'];
        } else {
            foreach ($agencies as $agency) {
                $rows[] = [
                    $agency->agency_id,
                    $agency->agency_name,
                    $agency->agency_url,
                    $agency->agency_timezone,
                    $agency->agency_lang ?? 'en',
                ];
            }
        }

        $this->writeCsv($path . DIRECTORY_SEPARATOR . 'agency.txt', $rows);
    }

    private function writeStops(string $path): void
    {
        $rows = [['stop_id', 'stop_name', 'stop_lat', 'stop_lon', 'location_type', 'parent_station']];

        Stop::selectRaw(
            "id as stop_id, name as stop_name,
             ST_Y(location::geometry) as stop_lat,
             ST_X(location::geometry) as stop_lon,
             COALESCE(location_t, 0) as location_type,
             COALESCE(parent_sta, '') as parent_station"
        )->chunkById(500, function ($stops) use (&$rows) {
            foreach ($stops as $stop) {
                $rows[] = [
                    $stop->stop_id,
                    $stop->stop_name,
                    $stop->stop_lat,
                    $stop->stop_lon,
                    $stop->location_type,
                    $stop->parent_station,
                ];
            }
        }, 'id', 'stop_id');

        $this->writeCsv($path . DIRECTORY_SEPARATOR . 'stops.txt', $rows);
    }

    private function writeRoutes(string $path): void
    {
        $rows = [['route_id', 'agency_id', 'route_short_name', 'route_long_name', 'route_type', 'route_color', 'route_text_color']];

        Route::chunkById(500, function ($routes) use (&$rows) {
            foreach ($routes as $route) {
                $rows[] = [
                    $route->route_id,
                    $route->agency_id ?? 'hopln',
                    $route->route_short_name,
                    $route->route_long_name,
                    $route->route_type ?? 3,
                    $route->route_color ?? '',
                    $route->route_text_color ?? '',
                ];
            }
        });

        $this->writeCsv($path . DIRECTORY_SEPARATOR . 'routes.txt', $rows);
    }

    private function writeCalendar(string $path): void
    {
        $rows = [['service_id', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'start_date', 'end_date']];

        $calendars = ServiceCalendar::all();

        if ($calendars->isEmpty()) {
            Log::warning('GtfsExport: no service_calendars in DB, using synthetic fallback');
            $serviceIds = Trip::distinct()->pluck('service_id');
            foreach ($serviceIds as $id) {
                $rows[] = [$id, 1, 1, 1, 1, 1, 1, 1, '20260101', '20271231'];
            }
        } else {
            foreach ($calendars as $cal) {
                $rows[] = [
                    $cal->service_id,
                    (int) $cal->monday,
                    (int) $cal->tuesday,
                    (int) $cal->wednesday,
                    (int) $cal->thursday,
                    (int) $cal->friday,
                    (int) $cal->saturday,
                    (int) $cal->sunday,
                    $cal->start_date->format('Ymd'),
                    $cal->end_date->format('Ymd'),
                ];
            }
        }

        $this->writeCsv($path . DIRECTORY_SEPARATOR . 'calendar.txt', $rows);
    }

    private function writeCalendarDates(string $path): void
    {
        $rows = [['service_id', 'date', 'exception_type']];

        ServiceException::orderBy('service_id')->orderBy('date')
            ->chunkById(1000, function ($exceptions) use (&$rows) {
                foreach ($exceptions as $ex) {
                    $rows[] = [
                        $ex->service_id,
                        $ex->date->format('Ymd'),
                        $ex->exception_type,
                    ];
                }
            });

        $this->writeCsv($path . DIRECTORY_SEPARATOR . 'calendar_dates.txt', $rows);
    }

    private function writeTrips(string $path): void
    {
        $rows = [['route_id', 'service_id', 'trip_id', 'trip_headsign', 'direction_id', 'shape_id', 'block_id']];

        Trip::chunkById(500, function ($trips) use (&$rows) {
            foreach ($trips as $trip) {
                $rows[] = [
                    $trip->route_id,
                    $trip->service_id,
                    $trip->trip_id,
                    $trip->trip_headsign ?? '',
                    $trip->direction_id ?? 0,
                    $trip->shape_id ?? '',
                    $trip->block_id ?? '',
                ];
            }
        });

        $this->writeCsv($path . DIRECTORY_SEPARATOR . 'trips.txt', $rows);
    }

    private function writeFrequencies(string $path): void
    {
        $rows = [['trip_id', 'start_time', 'end_time', 'headway_secs', 'exact_times']];

        TripFrequency::orderBy('trip_id')->orderBy('start_time')
            ->chunkById(1000, function ($freqs) use (&$rows) {
                foreach ($freqs as $freq) {
                    $rows[] = [
                        $freq->trip_id,
                        $freq->start_time,
                        $freq->end_time,
                        $freq->headway_secs,
                        $freq->exact_times,
                    ];
                }
            });

        $this->writeCsv($path . DIRECTORY_SEPARATOR . 'frequencies.txt', $rows);
    }

    private function writeStopTimes(string $path): void
    {
        $rows = [['trip_id', 'arrival_time', 'departure_time', 'stop_id', 'stop_sequence', 'pickup_type', 'drop_off_type']];

        StopTime::orderBy('trip_id')->orderBy('stop_sequence')->chunkById(1000, function ($times) use (&$rows) {
            foreach ($times as $st) {
                $rows[] = [
                    $st->trip_id,
                    $st->arrival_time,
                    $st->departure_time,
                    $st->stop_id,
                    $st->stop_sequence,
                    $st->pickup_type,
                    $st->drop_off_type,
                ];
            }
        });

        $this->writeCsv($path . DIRECTORY_SEPARATOR . 'stop_times.txt', $rows);
    }

    private function writeShapes(string $path): void
    {
        $rows = [['shape_id', 'shape_pt_lat', 'shape_pt_lon', 'shape_pt_sequence']];

        $points = DB::select("
            SELECT shape_id,
                   ST_Y((dp).geom) as shape_pt_lat,
                   ST_X((dp).geom) as shape_pt_lon,
                   (dp).path[1]    as shape_pt_sequence
            FROM shapes, ST_DumpPoints(path) dp
            ORDER BY shape_id, shape_pt_sequence
        ");

        foreach ($points as $pt) {
            $rows[] = [
                $pt->shape_id,
                $pt->shape_pt_lat,
                $pt->shape_pt_lon,
                $pt->shape_pt_sequence,
            ];
        }

        $this->writeCsv($path . DIRECTORY_SEPARATOR . 'shapes.txt', $rows);
    }

    private function writeFareAttributes(string $path): void
    {
        $attrs = FareAttribute::all();
        if ($attrs->isEmpty()) return;

        $rows = [['fare_id', 'price', 'currency_type', 'payment_method', 'transfers', 'agency_id', 'transfer_duration']];
        foreach ($attrs as $a) {
            $rows[] = [
                $a->fare_id, $a->price, $a->currency_type,
                $a->payment_method, $a->transfers ?? '',
                $a->agency_id, $a->transfer_duration ?? '',
            ];
        }
        $this->writeCsv($path . DIRECTORY_SEPARATOR . 'fare_attributes.txt', $rows);
    }

    private function writeFareRules(string $path): void
    {
        $rules = FareRule::all();
        if ($rules->isEmpty()) return;

        $rows = [['fare_id', 'route_id', 'origin_id', 'destination_id', 'contains_id']];
        foreach ($rules as $r) {
            $rows[] = [
                $r->fare_id, $r->route_id ?? '', $r->origin_id ?? '',
                $r->destination_id ?? '', $r->contains_id ?? '',
            ];
        }
        $this->writeCsv($path . DIRECTORY_SEPARATOR . 'fare_rules.txt', $rows);
    }

    private function writeLevels(string $path): void
    {
        $levels = Level::all();
        if ($levels->isEmpty()) return;

        $rows = [['level_id', 'level_index', 'level_name', 'parent_station']];
        foreach ($levels as $l) {
            $rows[] = [$l->level_id, $l->level_index, $l->level_name, $l->stop_id];
        }
        $this->writeCsv($path . DIRECTORY_SEPARATOR . 'levels.txt', $rows);
    }

    private function writePathways(string $path): void
    {
        $pathways = Pathway::all();
        if ($pathways->isEmpty()) return;

        $rows = [['pathway_id', 'from_stop_id', 'to_stop_id', 'pathway_mode', 'is_bidirectional',
                  'length', 'traversal_time', 'stair_count', 'max_slope', 'min_width',
                  'signposted_as', 'reversed_signposted_as']];
        foreach ($pathways as $p) {
            $rows[] = [
                $p->pathway_id, $p->from_stop_id, $p->to_stop_id,
                $p->pathway_mode, $p->is_bidirectional ? 1 : 0,
                $p->length ?? '', $p->traversal_time ?? '', $p->stair_count ?? '',
                $p->max_slope ?? '', $p->min_width ?? '',
                $p->signposted_as ?? '', $p->reversed_signposted_as ?? '',
            ];
        }
        $this->writeCsv($path . DIRECTORY_SEPARATOR . 'pathways.txt', $rows);
    }

    private function writeCsv(string $filePath, array $rows): void
    {
        $handle = fopen($filePath, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
    }
}
