<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_calendars', function (Blueprint $table) {
            $table->string('service_id')->primary();
            $table->string('name');
            $table->boolean('monday')->default(true);
            $table->boolean('tuesday')->default(true);
            $table->boolean('wednesday')->default(true);
            $table->boolean('thursday')->default(true);
            $table->boolean('friday')->default(true);
            $table->boolean('saturday')->default(false);
            $table->boolean('sunday')->default(false);
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();
        });

        // Seed canonical calendars so migration 1008 can safely add the trips FK
        DB::table('service_calendars')->upsert([
            ['service_id' => 'daily',      'name' => 'Daily',        'monday' => true,  'tuesday' => true,  'wednesday' => true,  'thursday' => true,  'friday' => true,  'saturday' => true,  'sunday' => true,  'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'created_at' => now(), 'updated_at' => now()],
            ['service_id' => 'weekdays',   'name' => 'Weekdays',     'monday' => true,  'tuesday' => true,  'wednesday' => true,  'thursday' => true,  'friday' => true,  'saturday' => false, 'sunday' => false, 'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'created_at' => now(), 'updated_at' => now()],
            ['service_id' => 'weekends',   'name' => 'Weekends',     'monday' => false, 'tuesday' => false, 'wednesday' => false, 'thursday' => false, 'friday' => false, 'saturday' => true,  'sunday' => true,  'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'created_at' => now(), 'updated_at' => now()],
            ['service_id' => 'school_days','name' => 'School Days',  'monday' => true,  'tuesday' => true,  'wednesday' => true,  'thursday' => true,  'friday' => true,  'saturday' => false, 'sunday' => false, 'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'created_at' => now(), 'updated_at' => now()],
            ['service_id' => 'default',    'name' => 'Default',      'monday' => true,  'tuesday' => true,  'wednesday' => true,  'thursday' => true,  'friday' => true,  'saturday' => true,  'sunday' => true,  'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'created_at' => now(), 'updated_at' => now()],
        ], ['service_id'], ['name', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'start_date', 'end_date', 'updated_at']);
    }

    public function down(): void
    {
        Schema::dropIfExists('service_calendars');
    }
};
