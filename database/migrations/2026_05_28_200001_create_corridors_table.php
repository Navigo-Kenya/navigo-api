<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corridors', function (Blueprint $table) {
            $table->string('corridor_id')->primary();
            $table->string('name');
            $table->string('agency_id')->nullable();
            $table->foreign('agency_id')->references('agency_id')->on('agencies')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE corridors ADD COLUMN path geometry(LineString,4326)');
        DB::statement('CREATE INDEX corridors_path_idx ON corridors USING GIST(path)');
    }

    public function down(): void
    {
        Schema::dropIfExists('corridors');
    }
};
