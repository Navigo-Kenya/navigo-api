<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interop_entries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // bikeshare|park_and_ride|taxi_rank|airport_terminal|ferry_terminal|brt_station|rail_station
            $table->text('description')->nullable();
            $table->string('gtfs_stop_id')->nullable()->index();
            $table->foreign('gtfs_stop_id')->references('id')->on('stops')->nullOnDelete();
            $table->json('connections')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE interop_entries ADD COLUMN location geometry(Point, 4326)');
        DB::statement('CREATE INDEX interop_entries_loc_idx ON interop_entries USING GIST(location)');
    }

    public function down(): void
    {
        Schema::dropIfExists('interop_entries');
    }
};
