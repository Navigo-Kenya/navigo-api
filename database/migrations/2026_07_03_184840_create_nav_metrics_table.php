<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nav_metrics', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // reroute | gps_lost | snap_rate | arrival_precision | wrong_direction | board_manual | board_auto
            $table->string('event', 40);
            $table->float('value')->nullable();
            $table->json('meta')->nullable();
            $table->string('device', 80)->nullable();
            $table->timestamp('created_at');

            $table->index(['event', 'created_at']);
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nav_metrics');
    }
};
