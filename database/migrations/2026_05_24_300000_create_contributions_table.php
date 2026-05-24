<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('stop_id')->nullable();
            $table->foreign('stop_id')->references('id')->on('stops')->nullOnDelete();
            $table->string('type');                    // delay_report|stop_review|stop_photo|stop_edit|route_correction|new_stop
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('data')->nullable();          // type-specific payload
            $table->string('status')->default('pending'); // pending|auto_approved|approved|rejected
            $table->unsignedInteger('points_awarded')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['status', 'expires_at']);
            $table->index('stop_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contributions');
    }
};
