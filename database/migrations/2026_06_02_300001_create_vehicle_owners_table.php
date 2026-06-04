<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_owners', function (Blueprint $table) {
            $table->id();
            $table->string('agency_id', 50);
            $table->foreign('agency_id')->references('agency_id')->on('agencies')->onDelete('cascade');
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('national_id', 50)->nullable();
            $table->string('m_pesa_number', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('agency_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_owners');
    }
};
