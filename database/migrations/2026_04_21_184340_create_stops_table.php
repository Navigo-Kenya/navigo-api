<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stops', function (Blueprint $table) {
            $table->string('id')->primary(); // stop_id
            $table->string('name');

            // PostGIS Point for the exact stage location
            $table->geometry('location', subtype: 'point', srid: 4326);

            $table->integer('location_t')->default(0); // 0: stop, 1: station
            $table->string('parent_sta')->nullable();

            // Denormalized cache for the frontend interface
            $table->integer('trip_count')->default(0);
            $table->text('trip_ids')->nullable(); // Comma separated
            $table->text('route_ids')->nullable(); // Comma separated
            $table->text('route_nams')->nullable(); // Comma separated

            // A simple text string of comma-separated aliases is actually 
            // faster for pg_trgm to index than a JSONB array!
            $table->text('aliases')->nullable(); 
            
            // Higher number = more important hub
            $table->integer('popularity_score')->default(0)->index();

            $table->timestamps();

            $table->spatialIndex('location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stops');
    }
};
