<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_votes', function (Blueprint $table) {
            $table->id();
            $table->uuid('report_id');
            $table->foreign('report_id')->references('id')->on('transit_reports')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_hash', 64)->nullable(); // SHA-256 of IP for guest votes
            $table->enum('vote', ['up', 'down']);
            $table->timestamps();

            // Enforce one vote per authenticated user per report
            $table->unique(['report_id', 'user_id']);
            // Guest lookups
            $table->index(['report_id', 'ip_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_votes');
    }
};
