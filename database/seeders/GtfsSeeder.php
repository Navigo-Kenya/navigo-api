<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Route;
use App\Models\Shape;
use App\Models\Trip;
use App\Models\Stop;
use App\Models\StopTime;

class GtfsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Clear existing data to prevent duplicates on re-seed
        DB::statement('TRUNCATE TABLE stop_times, stops, trips, shapes, routes CASCADE');

        // 2. Seed Route
        $routeId = '10000107D11';
        Route::create([
            'route_id' => $routeId,
            'agency_id' => 'UON',
            'route_short_name' => '107D',
            'route_long_name' => 'Koja-Ngara-Ruaka',
            'route_type' => 3, // 3 = Bus
        ]);

        // 3. Seed Shape (The Curvy Road Geometry)
        $shapeId = 'SHAPE_107D_OUTBOUND';
        // A GeoJSON LineString representing the curve of the road from CBD up to Ruaka
        $geoJsonShape = json_encode([
            "type" => "LineString",
            "coordinates" => [
                [36.822595, -1.281230], // Koja
                [36.823200, -1.278000], // Curve up the road
                [36.823805, -1.274395], // Ngara
                [36.840000, -1.260000], // Highway curve
                [36.867888, -1.244951]  // Allsops
            ]
        ]);

        // We use raw PostGIS ST_GeomFromGeoJSON to convert the JSON string into binary spatial data
        Shape::create([
            'shape_id' => $shapeId,
            'path' => DB::raw("ST_SetSRID(ST_GeomFromGeoJSON('{$geoJsonShape}'), 4326)")
        ]);

        // 4. Seed Trip
        $tripId = 'TRIP_107D_MORNING';
        Trip::create([
            'trip_id' => $tripId,
            'route_id' => $routeId,
            'service_id' => 'daily',
            'trip_headsign' => 'Ruaka via Ngara',
            'direction_id' => 0, // 0 = Outbound
            'shape_id' => $shapeId,
        ]);

        // 5. Seed Stops (With spatial coordinates and denormalized cache)
        $stops = [
            [
                'id' => '0002KOJ',
                'name' => 'Koja',
                'lng' => 36.822595,
                'lat' => -1.281230,
            ],
            [
                'id' => '0003NGR',
                'name' => 'Ngara',
                'lng' => 36.823805,
                'lat' => -1.274395,
            ],
            [
                'id' => '0013ALS',
                'name' => 'Allsops',
                'lng' => 36.867888,
                'lat' => -1.244951,
            ]
        ];

        foreach ($stops as $stop) {
            Stop::create([
                'id' => $stop['id'],
                'name' => $stop['name'],
                // ST_MakePoint takes (longitude, latitude)
                'location' => DB::raw("ST_SetSRID(ST_MakePoint({$stop['lng']}, {$stop['lat']}), 4326)"),
                'location_t' => 0,
                'trip_count' => 1,
                'trip_ids' => $tripId,
                'route_ids' => $routeId,
                'route_nams' => '107D',
            ]);
        }

        // 6. Seed Stop Times (This dictates the directionality and sequence!)
        $stopTimes = [
            ['stop_id' => '0002KOJ', 'seq' => 1, 'arr' => '08:00:00', 'dep' => '08:05:00'],
            ['stop_id' => '0003NGR', 'seq' => 2, 'arr' => '08:12:00', 'dep' => '08:14:00'],
            ['stop_id' => '0013ALS', 'seq' => 3, 'arr' => '08:30:00', 'dep' => '08:32:00'],
        ];

        foreach ($stopTimes as $time) {
            StopTime::create([
                'trip_id' => $tripId,
                'stop_id' => $time['stop_id'],
                'arrival_time' => $time['arr'],
                'departure_time' => $time['dep'],
                'stop_sequence' => $time['seq'],
            ]);
        }

        $this->command->info('✅ Mock GTFS Data Seeded successfully! Database is spatially aware.');
    }
}
