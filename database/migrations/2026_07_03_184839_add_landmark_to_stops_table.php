<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stops', function (Blueprint $table) {
            // Human wayfinding anchor: "in front of Hilton", "opposite Tuskys".
            // Community-maintained via stop_edit contributions.
            $table->string('landmark', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stops', function (Blueprint $table) {
            $table->dropColumn('landmark');
        });
    }
};
