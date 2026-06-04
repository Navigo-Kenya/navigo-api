<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->date('psv_badge_expiry')->nullable();
            $table->date('licence_expiry')->nullable();
            $table->date('good_conduct_expiry')->nullable();
            $table->date('medical_cert_expiry')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn([
                'psv_badge_expiry', 'licence_expiry',
                'good_conduct_expiry', 'medical_cert_expiry',
            ]);
        });
    }
};
