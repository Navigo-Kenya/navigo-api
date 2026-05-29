<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Normalise any agency_id values that don't exist in the agencies table
        DB::statement("
            UPDATE routes
            SET agency_id = 'hopln'
            WHERE agency_id NOT IN (SELECT agency_id FROM agencies)
        ");

        Schema::table('routes', function (Blueprint $table) {
            $table->string('route_color', 6)->nullable()->after('route_type');
            $table->string('route_text_color', 6)->nullable()->after('route_color');
            $table->string('route_desc')->nullable()->after('route_long_name');
            $table->foreign('agency_id')->references('agency_id')->on('agencies');
        });
    }

    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropColumn(['route_color', 'route_text_color', 'route_desc']);
        });
    }
};
