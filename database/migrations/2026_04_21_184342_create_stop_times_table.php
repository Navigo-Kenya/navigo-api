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
        Schema::create('stop_times', function (Blueprint $table) {
            $table->id();
            $table->string('trip_id')->index();
            $table->string('stop_id')->index();
            $table->string('arrival_time'); 
            $table->string('departure_time');
            $table->integer('stop_sequence');
            $table->timestamps();

            $table->foreign('trip_id')->references('trip_id')->on('trips')->onDelete('cascade');
            $table->foreign('stop_id')->references('id')->on('stops')->onDelete('cascade');

            $table->unique(['trip_id', 'stop_sequence']);
            
            // CRITIQUE : Index composite pour booster les jointures de trajets
            $table->index(['stop_id', 'trip_id', 'stop_sequence'], 'idx_stop_times_optimized');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stop_times');
    }
};
