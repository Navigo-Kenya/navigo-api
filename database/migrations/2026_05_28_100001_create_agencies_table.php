<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table) {
            $table->string('agency_id')->primary();
            $table->string('agency_name');
            $table->string('agency_url');
            $table->string('agency_timezone');
            $table->string('agency_lang')->default('en');
            $table->string('agency_phone')->nullable();
            $table->string('agency_email')->nullable();
            $table->timestamps();
        });

        // Seed default agency so migration 1007 can safely add the routes FK
        DB::table('agencies')->upsert([
            [
                'agency_id'       => 'hopln',
                'agency_name'     => 'Hopln Nairobi',
                'agency_url'      => 'https://hopln.app',
                'agency_timezone' => 'Africa/Nairobi',
                'agency_lang'     => 'en',
                'agency_phone'    => null,
                'agency_email'    => null,
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
        ], ['agency_id'], ['agency_name', 'agency_url', 'agency_timezone', 'updated_at']);
    }

    public function down(): void
    {
        Schema::dropIfExists('agencies');
    }
};
