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

        // Clear existing data
        DB::statement('TRUNCATE TABLE stop_times, stops, trips, shapes, routes CASCADE');

        $this->seedRoutes();
        $this->seedShapes();
        $this->seedTrips();
        $this->seedStops();
        $this->seedStopTimes();

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

    private function seedRoutes()
    {
        $this->command->info('🚌 Seeding Routes...');
        $chunk = [];
        foreach ($this->readExcel('routes.xlsx') as $row) {
            $chunk[] = [
                'route_id' => (string) $row['route_id'],
                'agency_id' => $row['agency_id'] ?? 'AGENCY',
                'route_short_name' => $row['route_short_name'] ?? '',
                'route_long_name' => $row['route_long_name'] ?? '',
                'route_type' => (int) ($row['route_type'] ?? 3),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($chunk) === 1000) {
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

    private function seedTrips()
    {
        $this->command->info('🚏 Seeding Trips...');
        $chunk = [];
        foreach ($this->readExcel('trips.xlsx') as $row) {
            $chunk[] = [
                'trip_id' => (string) $row['trip_id'],
                'route_id' => (string) $row['route_id'],
                'service_id' => $row['service_id'] ?? 'daily',
                'trip_headsign' => $row['trip_headsign'] ?? '',
                'direction_id' => isset($row['direction_id']) && $row['direction_id'] !== '' ? (int)$row['direction_id'] : null,
                'shape_id' => (string) $row['shape_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($chunk) === 1000) {
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

    private function seedStopTimes()
    {
        $this->command->info('⏱️ Seeding Stop Times (This might take a minute)...');
        $chunk = [];
        foreach ($this->readExcel('stop_times.xlsx') as $row) {
            $chunk[] = [
                'trip_id' => (string) $row['trip_id'],
                'stop_id' => (string) $row['stop_id'],
                'arrival_time' => $row['arrival_time'] ?? '00:00:00',
                'departure_time' => $row['departure_time'] ?? '00:00:00',
                'stop_sequence' => (int) $row['stop_sequence'],
            ];

            if (count($chunk) === 5000) {
                DB::table('stop_times')->insertOrIgnore($chunk);
                $chunk = [];
            }
        }
        if (!empty($chunk)) DB::table('stop_times')->insertOrIgnore($chunk);
    }
}
