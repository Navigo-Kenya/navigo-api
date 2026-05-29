<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['accident', 'near_miss', 'crime', 'infrastructure', 'other']);
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('status', ['open', 'investigating', 'resolved'])->default('open');
            $table->string('route_id', 255)->nullable();
            $table->string('stop_id', 255)->nullable();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->text('description');
            $table->text('response_taken')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->integer('resolution_time_mins')->nullable();
            $table->string('reported_by', 255)->nullable();
            $table->string('created_by', 255)->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('severity');
            $table->index('route_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
