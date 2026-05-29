<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Normalise any orphaned service_id values before adding the FK constraint
        DB::statement("
            UPDATE trips
            SET service_id = 'daily'
            WHERE service_id NOT IN (SELECT service_id FROM service_calendars)
        ");

        Schema::table('trips', function (Blueprint $table) {
            $table->string('route_pattern_id')->nullable()->index()->after('shape_id');
            $table->foreign('route_pattern_id')->references('id')->on('route_patterns')->nullOnDelete();
            $table->string('scheduling_type')->default('scheduled')->after('route_pattern_id');
            $table->string('block_id')->nullable()->after('scheduling_type');
            $table->foreign('service_id')->references('service_id')->on('service_calendars');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
            $table->dropForeign(['route_pattern_id']);
            $table->dropColumn(['route_pattern_id', 'scheduling_type', 'block_id']);
        });
    }
};
