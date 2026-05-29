<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('plate', 20)->unique();
            $table->string('agency_id', 255)->nullable();
            $table->string('route_id', 255)->nullable();
            $table->string('model', 100)->nullable();
            $table->smallInteger('capacity')->nullable();
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('agency_id')->references('agency_id')->on('agencies')->nullOnDelete();
            $table->foreign('route_id')->references('route_id')->on('routes')->nullOnDelete();

            $table->index('agency_id');
            $table->index('route_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
