<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('CREATE INDEX IF NOT EXISTS stops_name_trgm ON stops USING gin(name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS stops_updated_at_idx ON stops (updated_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS stops_popularity_idx ON stops (popularity_score DESC)');

        DB::statement('CREATE INDEX IF NOT EXISTS routes_short_name_trgm ON routes USING gin(route_short_name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS routes_long_name_trgm ON routes USING gin(route_long_name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS routes_route_type_idx ON routes (route_type)');

        DB::statement('CREATE INDEX IF NOT EXISTS trips_headsign_trgm ON trips USING gin(trip_headsign gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS trips_updated_at_idx ON trips (updated_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS trips_route_id_idx ON trips (route_id)');

        DB::statement('CREATE INDEX IF NOT EXISTS stop_times_trip_id_seq_idx ON stop_times (trip_id, stop_sequence)');

        DB::statement('CREATE INDEX IF NOT EXISTS network_snapshots_entity_idx ON network_snapshots (entity_type, entity_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS network_snapshots_created_idx ON network_snapshots (created_at DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stops_name_trgm');
        DB::statement('DROP INDEX IF EXISTS stops_updated_at_idx');
        DB::statement('DROP INDEX IF EXISTS stops_popularity_idx');
        DB::statement('DROP INDEX IF EXISTS routes_short_name_trgm');
        DB::statement('DROP INDEX IF EXISTS routes_long_name_trgm');
        DB::statement('DROP INDEX IF EXISTS routes_route_type_idx');
        DB::statement('DROP INDEX IF EXISTS trips_headsign_trgm');
        DB::statement('DROP INDEX IF EXISTS trips_updated_at_idx');
        DB::statement('DROP INDEX IF EXISTS trips_route_id_idx');
        DB::statement('DROP INDEX IF EXISTS stop_times_trip_id_seq_idx');
        DB::statement('DROP INDEX IF EXISTS network_snapshots_entity_idx');
        DB::statement('DROP INDEX IF EXISTS network_snapshots_created_idx');
    }
};
