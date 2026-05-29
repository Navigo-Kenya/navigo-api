<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_pattern_stops', function (Blueprint $table) {
            $table->id();
            $table->string('route_pattern_id')->index();
            $table->foreign('route_pattern_id')->references('id')->on('route_patterns')->onDelete('cascade');
            $table->string('stop_id')->index();
            $table->foreign('stop_id')->references('id')->on('stops')->onDelete('cascade');
            $table->unsignedInteger('stop_sequence');
            $table->boolean('timepoint')->default(true);
            $table->tinyInteger('pickup_type')->default(0);
            $table->tinyInteger('drop_off_type')->default(0);
            $table->decimal('distance_traveled', 10, 3)->nullable();
            $table->timestamps();
            $table->unique(['route_pattern_id', 'stop_sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_pattern_stops');
    }
};
