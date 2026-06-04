<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_sla', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('agency_id', 50);
            $table->foreign('agency_id')->references('agency_id')->on('agencies')->onDelete('cascade');
            $table->string('route_id', 100);
            $table->foreign('route_id')->references('route_id')->on('routes')->onDelete('cascade');
            $table->unsignedSmallInteger('target_headway_minutes');
            $table->unsignedSmallInteger('alert_threshold_minutes')->default(5);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['agency_id', 'route_id']);
            $table->index(['agency_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_sla');
    }
};
