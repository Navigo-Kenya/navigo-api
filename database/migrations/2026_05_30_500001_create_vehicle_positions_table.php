<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('trip_id', 255)->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->smallInteger('bearing')->nullable();
            $table->decimal('speed_kmh', 6, 2)->nullable();
            $table->timestampTz('recorded_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['vehicle_id', 'recorded_at']);
            $table->index('recorded_at');      // for purge job
            $table->index('trip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_positions');
    }
};
