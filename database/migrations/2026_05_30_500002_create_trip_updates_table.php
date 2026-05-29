<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_updates', function (Blueprint $table) {
            $table->id();
            $table->string('trip_id', 255);
            $table->string('stop_id', 255)->nullable();
            $table->smallInteger('stop_sequence')->nullable();
            $table->integer('delay_seconds');
            $table->timestampTz('arrival_estimate')->nullable();
            $table->timestampTz('recorded_at');

            $table->index(['trip_id', 'recorded_at']);
            $table->index(['stop_id', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_updates');
    }
};
