<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_targets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('agency_id', 50);
            $table->foreign('agency_id')->references('agency_id')->on('agencies')->onDelete('cascade');
            $table->unsignedInteger('vehicle_id')->nullable(); // null = class-level default
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('cascade');
            $table->string('vehicle_class', 50)->nullable(); // e.g. '14-seater', '33-seater'
            $table->decimal('daily_target', 10, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable(); // null = currently active
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['agency_id', 'effective_from']);
            $table->index(['vehicle_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_targets');
    }
};
