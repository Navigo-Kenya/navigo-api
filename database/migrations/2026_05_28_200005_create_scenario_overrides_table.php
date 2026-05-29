<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenario_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scenario_id')->index();
            $table->foreign('scenario_id')->references('id')->on('network_scenarios')->onDelete('cascade');
            $table->string('entity_type');
            $table->string('entity_id')->nullable();
            $table->string('action');
            $table->jsonb('data');
            $table->timestamps();
            $table->unique(['scenario_id', 'entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenario_overrides');
    }
};
