<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;

class GtfsExcelSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Prevent memory leaks and timeouts
        ini_set('memory_limit', '-1');
        set_time_limit(0);
        DB::disableQueryLog();

        $this->command->info('🚀 Starting High-Performance XLSX GTFS Import...');

        // Clear GTFS tables (FK-safe order; CASCADE handles any remaining dependents)
        DB::statement('TRUNCATE TABLE trip_frequencies, stop_times, stops, trips, shapes, routes RESTART IDENTITY CASCADE');

        // Seed FK-referenced lookup tables from actual GTFS data BEFORE inserting dependents
        // $this->seedAgencies();           // must run before seedRoutes (routes.agency_id FK)
        $this->seedServiceCalendars();   // must run before seedTrips  (trips.service_id FK)

        $this->seedRoutes();
        $this->seedShapes();
        $this->seedTrips();
        $this->seedStops();
        $this->seedStopTimes();
        $this->seedFrequencies();

        $this->command->info('✅ XLSX Import Complete! Your database is now a spatial powerhouse.');
    }

    /**
     * Bulletproof Excel Streaming Reader using OpenSpout & Generators.
     * Keeps memory at near 0MB by iterating through the file row-by-row.
     */
    private function readExcel($filename)
    {
        $path = storage_path('app/gtfs/' . $filename);
        if (!file_exists($path)) {
            $this->command->warn("⚠️ File not found: {$filename}. Skipping.");
            return;
        }

        $reader = new Reader();
        $reader->open($path);

        foreach ($reader->getSheetIterator() as $sheet) {
            $header = [];

            foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                // OpenSpout extracts the cells as an array
                $cells = $row->toArray();

                // 1st row is always the header
                if ($rowIndex === 1) {
                    $header = array_map('trim', $cells);
                    continue;
                }

                // If a row has missing trailing columns in Excel, pad it to match header length
                if (count($cells) < count($header)) {
                    $cells = array_pad($cells, count($header), null);
                }

                // Yield an associative array ['column_name' => 'value']
                yield array_combine($header, $cells);
            }
            break; // We only read the first sheet of the Excel workbook
        }

        $reader->close();
    }

    // private function seedAgencies(): void
    // {
    //     $this->command->info('🏢 Upserting agencies from routes data...');

    //     $seen = [];
    //     foreach ($this->readExcel('routes.xlsx') as $row) {
    //         $id = isset($row['agency_id']) && $row['agency_id'] !== '' ? (string) $row['agency_id'] : null;
    //         if ($id !== null && !isset($seen[$id])) {
    //             $seen[$id] = true;
    //         }
    //     }

    //     if (empty($seen)) {
    //         return;
    //     }

    //     $now  = now()->toDateTimeString();
    //     $rows = [];
    //     foreach (array_keys($seen) as $id) {
    //         $rows[] = [
    //             'agency_id'       => $id,
    //             'agency_name'     => $id,          // placeholder — update via Agencies console
    //             'agency_url'      => 'https://hopln.app',
    //             'agency_timezone' => 'Africa/Nairobi',
    //             'agency_lang'     => 'en',
    //             'agency_phone'    => null,
    //             'agency_email'    => null,
    //             'created_at'      => $now,
    //             'updated_at'      => $now,
    //         ];
    //     }

    //     // Only insert new agencies — preserve names already set by AgencySeeder / admins
    //     DB::table('agencies')->upsert(
    //         $rows,
    //         ['agency_id'],
    //         ['updated_at']   // do NOT overwrite agency_name/url if the row already exists
    //     );

    //     $this->command->info('   -> ' . count($rows) . ' agencies upserted.');
    // }

    private function seedServiceCalendars(): void
    {
        $this->command->info('📅 Upserting service calendars from calendar.xlsx...');

        $path = storage_path('app/gtfs/calendar.xlsx');
        if (!file_exists($path)) {
            $this->command->warn('   -> calendar.xlsx not found, skipping.');
            return;
        }

        $rows = [];
        $now  = now()->toDateTimeString();

        foreach ($this->readExcel('calendar.xlsx') as $row) {
            $serviceId = isset($row['service_id']) && $row['service_id'] !== '' ? strtolower((string) $row['service_id']) : null;
            if ($serviceId === null) {
                continue;
            }

            $toBool = static fn ($v): bool => (int) $v === 1;

            $rows[] = [
                'service_id' => $serviceId,
                'name'       => $serviceId,
                'monday'     => $toBool($row['monday']    ?? 0),
                'tuesday'    => $toBool($row['tuesday']   ?? 0),
                'wednesday'  => $toBool($row['wednesday'] ?? 0),
                'thursday'   => $toBool($row['thursday']  ?? 0),
                'friday'     => $toBool($row['friday']    ?? 0),
                'saturday'   => $toBool($row['saturday']  ?? 0),
                'sunday'     => $toBool($row['sunday']    ?? 0),
                'start_date' => $row['start_date'] ?? '2026-01-01',
                'end_date'   => $row['end_date']   ?? '2027-12-31',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($rows)) {
            return;
        }

        DB::table('service_calendars')->upsert(
            $rows,
            ['service_id'],
            ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'start_date', 'end_date', 'updated_at']
        );

        $this->command->info('   -> ' . count($rows) . ' service calendars upserted.');
    }

    private function seedRoutes(): void
    {
        $this->command->info('🚌 Seeding Routes...');
        $chunk = [];
        foreach ($this->readExcel('routes.xlsx') as $row) {
            $shortName = $row['route_short_name'] ?? '';
            [$color, $textColor] = $this->routeColor($shortName);

            $chunk[] = [
                'route_id'         => (string) $row['route_id'],
                'agency_id'        => $row['agency_id'] ?? 'hopln',
                'route_short_name' => $shortName,
                'route_long_name'  => $row['route_long_name'] ?? '',
                'route_type'       => (int) ($row['route_type'] ?? 3),
                'route_desc'       => isset($row['route_desc']) && $row['route_desc'] !== '' ? (string) $row['route_desc'] : null,
                'route_color'      => $color,
                'route_text_color' => $textColor,
                'created_at'       => now(),
                'updated_at'       => now(),
            ];

            if (\count($chunk) === 1000) {
                DB::table('routes')->insert($chunk);
                $chunk = [];
            }
        }
        if (!empty($chunk)) DB::table('routes')->insert($chunk);
    }

    private function seedShapes()
    {
        $this->command->info('🗺️ Seeding Shapes (PostGIS Aggregation)...');

        DB::statement("
            CREATE TEMPORARY TABLE temp_shape_points (
                shape_id VARCHAR(255),
                shape_pt_lat FLOAT8,
                shape_pt_lon FLOAT8,
                shape_pt_sequence INT
            )
        ");

        $chunk = [];
        foreach ($this->readExcel('shapes.xlsx') as $row) {
            $chunk[] = [
                'shape_id' => (string) $row['shape_id'],
                'shape_pt_lat' => (float) $row['shape_pt_lat'],
                'shape_pt_lon' => (float) $row['shape_pt_lon'],
                'shape_pt_sequence' => (int) $row['shape_pt_sequence'],
            ];

            if (count($chunk) === 5000) {
                DB::table('temp_shape_points')->insert($chunk);
                $chunk = [];
            }
        }
        if (!empty($chunk)) DB::table('temp_shape_points')->insert($chunk);

        $this->command->info('   -> Stitching points into PostGIS LineStrings...');

        DB::statement("
            INSERT INTO shapes (shape_id, path, created_at, updated_at)
            SELECT
                shape_id,
                ST_SetSRID(ST_MakeLine(ST_MakePoint(shape_pt_lon, shape_pt_lat) ORDER BY shape_pt_sequence), 4326),
                NOW(),
                NOW()
            FROM temp_shape_points
            GROUP BY shape_id
        ");

        DB::statement("DROP TABLE temp_shape_points");
    }

    private function seedTrips(): void
    {
        $this->command->info('🚏 Seeding Trips...');
        $chunk = [];
        foreach ($this->readExcel('trips.xlsx') as $row) {
            $chunk[] = [
                'trip_id'         => (string) $row['trip_id'],
                'route_id'        => (string) $row['route_id'],
                'service_id'      => strtolower($row['service_id'] ?? 'daily'),
                'trip_headsign'   => $row['trip_headsign'] ?? '',
                'direction_id'    => isset($row['direction_id']) && $row['direction_id'] !== '' ? (int) $row['direction_id'] : null,
                'shape_id'        => (string) $row['shape_id'],
                'scheduling_type' => $row['scheduling_type'] ?? 'scheduled',
                'block_id'        => isset($row['block_id']) && $row['block_id'] !== '' ? (string) $row['block_id'] : null,
                'created_at'      => now(),
                'updated_at'      => now(),
            ];

            if (\count($chunk) === 1000) {
                DB::table('trips')->insert($chunk);
                $chunk = [];
            }
        }
        if (!empty($chunk)) DB::table('trips')->insert($chunk);
    }

    private function seedStops()
    {
        $this->command->info('🛑 Seeding Stops (Fixing Space-Separated Arrays)...');

        foreach ($this->readExcel('stops.xlsx') as $row) {

            // ── MAGIC ARRAY FIX ──
            // preg_replace('/\s+/', ',') finds any block of spaces and converts it to a single comma
            $routeIds = isset($row['route_ids']) ? preg_replace('/\s+/', ',', trim((string) $row['route_ids'])) : null;
            $tripIds = isset($row['trip_ids']) ? preg_replace('/\s+/', ',', trim((string) $row['trip_ids'])) : null;
            $routeNams = isset($row['route_nams']) ? preg_replace('/\s+/', ',', trim((string) $row['route_nams'])) : null;

            // Auto-calculate the trip_count based on how many commas we just made
            $tripCount = !empty($tripIds) ? count(explode(',', $tripIds)) : 0;

            DB::statement("
                INSERT INTO stops (id, name, location, location_t, trip_count, trip_ids, route_ids, route_nams, created_at, updated_at)
                VALUES (?, ?, ST_SetSRID(ST_MakePoint(?, ?), 4326), ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                (string) $row['stop_id'],
                (string) $row['stop_name'],
                (float) $row['stop_lon'],
                (float) $row['stop_lat'],
                (int) ($row['location_type'] ?? 0),
                $tripCount,
                $tripIds,
                $routeIds,
                $routeNams
            ]);
        }
    }

    private function seedStopTimes(): void
    {
        $this->command->info('⏱️ Seeding Stop Times (This might take a minute)...');
        $chunk = [];
        foreach ($this->readExcel('stop_times.xlsx') as $row) {
            $chunk[] = [
                'trip_id'             => (string) $row['trip_id'],
                'stop_id'             => (string) $row['stop_id'],
                'arrival_time'        => $row['arrival_time'] ?? '00:00:00',
                'departure_time'      => $row['departure_time'] ?? '00:00:00',
                'stop_sequence'       => (int) $row['stop_sequence'],
                'pickup_type'         => isset($row['pickup_type']) && $row['pickup_type'] !== '' ? (int) $row['pickup_type'] : 0,
                'drop_off_type'       => isset($row['drop_off_type']) && $row['drop_off_type'] !== '' ? (int) $row['drop_off_type'] : 0,
                'shape_dist_traveled' => isset($row['shape_dist_traveled']) && $row['shape_dist_traveled'] !== '' ? (float) $row['shape_dist_traveled'] : null,
            ];

            if (\count($chunk) === 5000) {
                DB::table('stop_times')->insertOrIgnore($chunk);
                $chunk = [];
            }
        }
        if (!empty($chunk)) DB::table('stop_times')->insertOrIgnore($chunk);
    }

    private function seedFrequencies(): void
    {
        $this->command->info('🔁 Seeding Trip Frequencies...');
        $chunk = [];
        foreach ($this->readExcel('frequencies.xlsx') as $row) {
            if (empty($row['trip_id'])) continue;
            $chunk[] = [
                'trip_id'      => (string) $row['trip_id'],
                'start_time'   => $row['start_time'] ?? '00:00:00',
                'end_time'     => $row['end_time'] ?? '00:00:00',
                'headway_secs' => (int) ($row['headway_secs'] ?? 600),
                'exact_times'  => isset($row['exact_times']) && $row['exact_times'] !== '' ? (int) $row['exact_times'] : 0,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];

            if (\count($chunk) === 1000) {
                DB::table('trip_frequencies')->insert($chunk);
                $chunk = [];
            }
        }
        if (!empty($chunk)) DB::table('trip_frequencies')->insert($chunk);
    }

    private function routeColor(string $shortName): array
    {
        $hue = abs(crc32($shortName)) % 360;
        [$r, $g, $b] = $this->hslToRgb($hue / 360.0, 0.75, 0.45);
        $bg        = sprintf('%02X%02X%02X', $r, $g, $b);
        $luminance = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
        $text      = $luminance > 0.40 ? '000000' : 'FFFFFF';
        return [$bg, $text];
    }

    private function hslToRgb(float $h, float $s, float $l): array
    {
        if ($s === 0.0) {
            $v = (int) round($l * 255);
            return [$v, $v, $v];
        }
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        return [
            (int) round($this->hueToRgb($p, $q, $h + 1 / 3) * 255),
            (int) round($this->hueToRgb($p, $q, $h)         * 255),
            (int) round($this->hueToRgb($p, $q, $h - 1 / 3) * 255),
        ];
    }

    private function hueToRgb(float $p, float $q, float $t): float
    {
        if ($t < 0) $t += 1;
        if ($t > 1) $t -= 1;
        if ($t < 1 / 6) return $p + ($q - $p) * 6 * $t;
        if ($t < 1 / 2) return $q;
        if ($t < 2 / 3) return $p + ($q - $p) * (2 / 3 - $t) * 6;
        return $p;
    }
}
