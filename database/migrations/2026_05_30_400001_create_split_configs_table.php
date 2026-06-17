<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('split_configs', function (Blueprint $table) {
            $table->id();

            // One config per operator agency — no global default
            $table->string('agency_id', 255)->unique();
            $table->foreign('agency_id')->references('agency_id')->on('agencies')->cascadeOnDelete();

            // Master toggle — when false the full gross (minus 3% platform) goes to vehicle wallet
            $table->boolean('split_enabled')->default(true);

            // 'percentage' → vehicle_pct + sacco_pct + platform_pct split per transaction
            // 'lengo'      → platform takes platform_pct; rest goes to vehicle wallet;
            //                 SACCO levy deducted separately via daily levy job
            $table->enum('split_type', ['percentage', 'lengo'])->default('percentage');

            // ── Percentage-mode fields ────────────────────────────────────────
            $table->decimal('vehicle_pct',  5, 2)->default(87.00);
            $table->decimal('sacco_pct',    5, 2)->default(10.00);
            $table->decimal('platform_pct', 5, 2)->default(3.00);

            // ── Lengo-mode fields ─────────────────────────────────────────────
            // daily_target  = the lengo the driver banks to the owner each day
            // daily_sacco_levy = flat KES amount deducted from vehicle wallet to SACCO daily
            $table->decimal('daily_target',     10, 2)->nullable();
            $table->decimal('daily_sacco_levy',  8, 2)->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('split_configs');
    }
};
