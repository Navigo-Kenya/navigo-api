<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('severity', ['info', 'warning', 'critical'])->default('info');
            $table->enum('effect', ['detour', 'reduced_service', 'cancellation', 'other'])->default('other');
            $table->enum('status', ['draft', 'active', 'expired'])->default('draft');
            $table->enum('affected_type', ['route', 'stop', 'all'])->default('all');
            $table->string('affected_id', 255)->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->string('created_by', 255)->nullable();
            $table->boolean('auto_generated')->default(false);
            $table->timestamps();

            $table->index('status');
            $table->index('starts_at');
            $table->index(['affected_type', 'affected_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_alerts');
    }
};
