<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pathways', function (Blueprint $table) {
            $table->id();
            $table->string('pathway_id')->unique();
            $table->string('from_stop_id')->index();
            $table->foreign('from_stop_id')->references('id')->on('stops')->onDelete('cascade');
            $table->string('to_stop_id')->index();
            $table->foreign('to_stop_id')->references('id')->on('stops')->onDelete('cascade');
            // 1=walkway 2=stairs 3=moving_sidewalk 4=escalator 5=elevator 6=fare_gate 7=exit_gate
            $table->tinyInteger('pathway_mode');
            $table->boolean('is_bidirectional')->default(true);
            $table->decimal('length', 10, 2)->nullable();         // metres
            $table->integer('traversal_time')->nullable();        // seconds
            $table->integer('stair_count')->nullable();
            $table->decimal('max_slope', 6, 3)->nullable();
            $table->decimal('min_width', 6, 2)->nullable();
            $table->string('signposted_as')->nullable();
            $table->string('reversed_signposted_as')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pathways');
    }
};
