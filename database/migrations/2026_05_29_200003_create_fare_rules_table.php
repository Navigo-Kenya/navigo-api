<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fare_rules', function (Blueprint $table) {
            $table->id();
            $table->string('fare_id')->index();
            $table->foreign('fare_id')->references('fare_id')->on('fare_attributes')->onDelete('cascade');
            $table->string('route_id')->nullable()->index();
            $table->foreign('route_id')->references('route_id')->on('routes')->nullOnDelete();
            $table->string('origin_id')->nullable();      // zone_id reference
            $table->string('destination_id')->nullable(); // zone_id reference
            $table->string('contains_id')->nullable();    // zone_id reference
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fare_rules');
    }
};
