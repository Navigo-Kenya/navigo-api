<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stage_queues', function (Blueprint $table) {
            $table->id();
            $table->string('agency_id', 50);
            $table->string('route_id', 100);
            $table->unsignedInteger('vehicle_id');
            $table->integer('queue_position');
            $table->string('status', 20)->default('waiting'); // waiting / departed / skipped
            $table->timestamp('queued_at')->useCurrent();
            $table->timestamp('departed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->foreign('agency_id')->references('agency_id')->on('agencies')->cascadeOnDelete();
            $table->foreign('route_id')->references('route_id')->on('routes')->cascadeOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['agency_id', 'route_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_queues');
    }
};
