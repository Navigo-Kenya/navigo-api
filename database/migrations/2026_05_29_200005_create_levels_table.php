<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('levels', function (Blueprint $table) {
            $table->id();
            $table->string('level_id')->unique(); // e.g. "NRS_L0"
            $table->decimal('level_index', 3, 1); // -1.0=underground, 0.0=ground, 1.0=first floor
            $table->string('level_name');
            $table->string('stop_id')->index(); // parent station stop
            $table->foreign('stop_id')->references('id')->on('stops')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('levels');
    }
};
