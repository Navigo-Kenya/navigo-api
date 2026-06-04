<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedBigInteger('owner_id')->nullable()->after('status');
            $table->foreign('owner_id')->references('id')->on('vehicle_owners')->onDelete('set null');
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropIndex(['owner_id']);
            $table->dropColumn('owner_id');
        });
    }
};
