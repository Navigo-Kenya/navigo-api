<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stop_times', function (Blueprint $table) {
            $table->tinyInteger('pickup_type')->default(0)->after('stop_sequence');
            $table->tinyInteger('drop_off_type')->default(0)->after('pickup_type');
            $table->decimal('shape_dist_traveled', 10, 3)->nullable()->after('drop_off_type');
        });
    }

    public function down(): void
    {
        Schema::table('stop_times', function (Blueprint $table) {
            $table->dropColumn(['pickup_type', 'drop_off_type', 'shape_dist_traveled']);
        });
    }
};
