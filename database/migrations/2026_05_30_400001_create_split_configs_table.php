<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('split_configs', function (Blueprint $table) {
            $table->id();
            $table->string('agency_id', 255)->nullable()->comment('NULL = global default');
            $table->decimal('vehicle_pct', 5, 2)->default(85.00);
            $table->decimal('sacco_pct', 5, 2)->default(10.00);
            $table->decimal('platform_pct', 5, 2)->default(5.00);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('agency_id')->references('agency_id')->on('agencies')->nullOnDelete();
            $table->index('agency_id');
            $table->index('is_active');
        });

        // Seed the global default config
        DB::table('split_configs')->insert([
            'agency_id'    => null,
            'vehicle_pct'  => 85.00,
            'sacco_pct'    => 10.00,
            'platform_pct' => 5.00,
            'notes'        => 'Global default — applies to all agencies without a specific config',
            'is_active'    => true,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('split_configs');
    }
};
