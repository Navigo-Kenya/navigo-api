<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transit_reports', function (Blueprint $table) {
            // Lets authenticated reporters opt-in to anonymity via their
            // privacy settings. user_id is still stored for dedup + accountability;
            // the API simply returns reporter:null for anonymous reports.
            $table->boolean('is_anonymous')->default(false)->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('transit_reports', function (Blueprint $table) {
            $table->dropColumn('is_anonymous');
        });
    }
};
