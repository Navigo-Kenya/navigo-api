<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('reviewed_at');
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->string('decline_reason')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['reviewed_by', 'decline_reason']);
        });
    }
};
