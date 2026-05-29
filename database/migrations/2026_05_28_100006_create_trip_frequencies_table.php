<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_frequencies', function (Blueprint $table) {
            $table->id();
            $table->string('trip_id')->index();
            $table->foreign('trip_id')->references('trip_id')->on('trips')->onDelete('cascade');
            $table->string('start_time');
            $table->string('end_time');
            $table->unsignedInteger('headway_secs');
            $table->tinyInteger('exact_times')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_frequencies');
    }
};
