<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('on_time_performance', function (Blueprint $table) {
            $table->id();
            $table->string('route_id', 255);
            $table->date('date');
            $table->integer('total_trips')->default(0);
            $table->integer('on_time_trips')->default(0);
            $table->integer('avg_delay_s')->default(0);
            $table->integer('p90_delay_s')->default(0);
            $table->timestampTz('computed_at');

            $table->unique(['route_id', 'date']);
            $table->index('date');
            $table->index('route_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('on_time_performance');
    }
};
