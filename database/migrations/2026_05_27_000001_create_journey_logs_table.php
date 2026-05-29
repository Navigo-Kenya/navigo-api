<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journey_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('origin_name')->nullable();
            $table->string('destination_name')->nullable();
            $table->string('primary_route')->nullable();
            $table->string('type')->default('standard'); // 'standard' | 'ai'
            $table->timestamps();

            $table->index('created_at');
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journey_logs');
    }
};
