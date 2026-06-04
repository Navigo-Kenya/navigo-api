<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('draft_status', 20)->default('published')->after('trip_headsign');
            $table->unsignedBigInteger('submitted_by')->nullable()->after('draft_status');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('submitted_by');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_notes')->nullable()->after('reviewed_at');

            $table->foreign('submitted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropForeign(['submitted_by']);
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['draft_status', 'submitted_by', 'reviewed_by', 'reviewed_at', 'review_notes']);
        });
    }
};
