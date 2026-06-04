<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->date('insurance_expiry')->nullable();
            $table->date('inspection_due')->nullable();
            $table->date('road_service_license_expiry')->nullable();
            $table->date('speed_limiter_cert_expiry')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'insurance_expiry', 'inspection_due',
                'road_service_license_expiry', 'speed_limiter_cert_expiry',
            ]);
        });
    }
};
