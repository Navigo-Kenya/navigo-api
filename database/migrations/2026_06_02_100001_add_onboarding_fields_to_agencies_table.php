<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->text('logo_url')->nullable();
            $table->string('reg_number', 50)->nullable();
            $table->string('onboarding_status', 20)->default('pending');
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->string('region', 100)->nullable();
            $table->text('terminus_location')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn([
                'logo_url', 'reg_number', 'onboarding_status',
                'onboarding_completed_at', 'region', 'terminus_location',
            ]);
        });
    }
};
