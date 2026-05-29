<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fare_zones', function (Blueprint $table) {
            $table->id();
            $table->string('zone_id')->unique();
            $table->string('name');
            $table->string('agency_id')->index();
            $table->foreign('agency_id')->references('agency_id')->on('agencies')->onDelete('cascade');
            $table->char('color', 6)->default('3B82F6');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE fare_zones ADD COLUMN zone_polygon geometry(Polygon, 4326)');
        DB::statement('CREATE INDEX fare_zones_poly_idx ON fare_zones USING GIST(zone_polygon)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fare_zones');
    }
};
