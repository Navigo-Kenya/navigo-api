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
        Schema::create('trips', function (Blueprint $table) {
            $table->string('trip_id')->primary();
            $table->string('route_id')->index();
            $table->string('service_id'); // e.g., 'daily', 'weekends'
            $table->string('trip_headsign')->nullable();
            $table->integer('direction_id')->nullable(); // 0 or 1
            $table->string('shape_id')->index();
            $table->timestamps();

            $table->foreign('route_id')->references('route_id')->on('routes')->onDelete('cascade');
            $table->foreign('shape_id')->references('shape_id')->on('shapes')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
