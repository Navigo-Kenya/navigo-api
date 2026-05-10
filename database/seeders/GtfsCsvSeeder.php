<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GtfsCsvSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Prevent memory leaks and timeouts
        ini_set('memory_limit', '-1');
        set_time_limit(0);
        DB::disableQueryLog();

        $this->command->info('🚀 Starting High-Performance GTFS Import...');

        // Clear existing data
        DB::statement('TRUNCATE TABLE stop_times, stops, trips, shapes, routes CASCADE');

        $this->seedRoutes();
        $this->seedShapes(); // The PostGIS Magic happens here
        $this->seedTrips();
        $this->seedStops();
        $this->seedStopTimes();

        $this->command->info('✅ GTFS Import Complete! Your database is now a spatial powerhouse.');
    }

/**
     * Bulletproof CSV Reader using PHP Generators.
     * Auto-detects delimiters (; , \t |) and fixes broken line endings.
     */
    private function readCsv($filename)
    {
        $path = storage_path('app/gtfs/' . $filename);
        if (!file_exists($path)) {
            $this->command->warn("⚠️ File not found: {$filename}. Skipping.");
            return;
        }

        // 1. Fix broken Mac/Windows line endings so PHP doesn't read the whole file as one line
        ini_set('auto_detect_line_endings', true);

        $handle = fopen($path, 'r');

        // 2. Auto-Detect the Delimiter
        // Read the very first line of the file to see what character separates the words
        $firstLine = fgets($handle);
        $delimiter = ','; // Default to comma

        if (strpos($firstLine, ';') !== false) {
            $delimiter = ';';
        } elseif (strpos($firstLine, "\t") !== false) {
            $delimiter = "\t";
        } elseif (strpos($firstLine, '|') !== false) {
            $delimiter = '|';
        }

        // Rewind the file pointer back to the start after our peek
        rewind($handle);

        // 3. Read the header using our newly discovered delimiter
        $header = fgetcsv($handle, 0, $delimiter);

        if (!$header) {
            fclose($handle);
            return;
        }

        // Clean invisible BOM characters (super common in Excel exports) and trim whitespace
        $header[0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $header[0]);
        $header = array_map('trim', $header);

        // 4. Stream the rows!
        while ($row = fgetcsv($handle, 0, $delimiter)) {
            $row = array_map('trim', $row); // Clean up any trailing spaces

            // Only yield rows that perfectly match the header count (prevents array_combine crashes on blank lines)
            if (count($header) === count($row)) {
                yield array_combine($header, $row);
            }
        }

        fclose($handle);
    }

    private function seedRoutes()
    {
        $this->command->info('🚌 Seeding Routes...');
        $chunk = [];
        foreach ($this->readCsv('routes.csv') as $row) {
            $chunk[] = [
                'route_id' => $row['route_id'],
                'agency_id' => $row['agency_id'] ?? 'AGENCY',
                'route_short_name' => $row['route_short_name'] ?? '',
                'route_long_name' => $row['route_long_name'] ?? '',
                'route_type' => $row['route_type'] ?? 3,
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

        // 1. Create a temporary table for raw points
        DB::statement("
            CREATE TEMPORARY TABLE temp_shape_points (
                shape_id VARCHAR(255),
                shape_pt_lat FLOAT8,
                shape_pt_lon FLOAT8,
                shape_pt_sequence INT
            )
        ");

        // 2. Stream insert points into temp table
        $chunk = [];
        foreach ($this->readCsv('shapes.txt') as $row) {
            $chunk[] = [
                'shape_id' => $row['shape_id'],
                'shape_pt_lat' => (float)$row['shape_pt_lat'],
                'shape_pt_lon' => (float)$row['shape_pt_lon'],
                'shape_pt_sequence' => (int)$row['shape_pt_sequence'],
            ];

            if (count($chunk) === 5000) { // Larger chunk for raw data
                DB::table('temp_shape_points')->insert($chunk);
                $chunk = [];
            }
        }
        if (!empty($chunk)) DB::table('temp_shape_points')->insert($chunk);

        $this->command->info('   -> Stitching points into PostGIS LineStrings...');

        // 3. THE POSTGIS MAGIC: Group the millions of points into single LineStrings
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

        // 4. Cleanup
        DB::statement("DROP TABLE temp_shape_points");
    }

    private function seedTrips()
    {
        $this->command->info('🚏 Seeding Trips...');
        $chunk = [];
        foreach ($this->readCsv('trips.txt') as $row) {
            $chunk[] = [
                'trip_id' => $row['trip_id'],
                'route_id' => $row['route_id'],
                'service_id' => $row['service_id'] ?? 'daily',
                'trip_headsign' => $row['trip_headsign'] ?? '',
                'direction_id' => isset($row['direction_id']) && $row['direction_id'] !== '' ? (int)$row['direction_id'] : null,
                'shape_id' => $row['shape_id'],
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
        $this->command->info('🛑 Seeding Stops...');

        foreach ($this->readCsv('stops.csv') as $row) {
            // We use DB::statement here instead of chunked inserts because we need to execute
            // the PostGIS ST_MakePoint function for every row directly in SQL.
            DB::statement("
                INSERT INTO stops (id, name, location, location_t, trip_count, created_at, updated_at)
                VALUES (?, ?, ST_SetSRID(ST_MakePoint(?, ?), 4326), ?, ?, NOW(), NOW())
            ", [
                $row['stop_id'],
                $row['stop_name'],
                (float)$row['stop_lon'],
                (float)$row['stop_lat'],
                (int)($row['location_type'] ?? 0),
                0 // Trip count can be updated later via a worker command
            ]);
        }
    }

    private function seedStopTimes()
    {
        $this->command->info('⏱️ Seeding Stop Times (This might take a minute)...');
        $chunk = [];
        foreach ($this->readCsv('stop_times.txt') as $row) {
            $chunk[] = [
                'trip_id' => $row['trip_id'],
                'stop_id' => $row['stop_id'],
                'arrival_time' => $row['arrival_time'] ?? '00:00:00',
                'departure_time' => $row['departure_time'] ?? '00:00:00',
                'stop_sequence' => (int)$row['stop_sequence'],
            ];

            if (count($chunk) === 5000) {
                DB::table('stop_times')->insertOrIgnore($chunk);
                $chunk = [];
            }
        }
        if (!empty($chunk)) DB::table('stop_times')->insertOrIgnore($chunk);
    }
}
