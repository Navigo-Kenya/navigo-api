<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_banking', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('agency_id', 50);
            $table->unsignedInteger('vehicle_id')->nullable();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('set null');
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->foreign('shift_id')->references('id')->on('shifts')->onDelete('set null');
            $table->date('banking_date');
            $table->decimal('expected_amount', 10, 2)->nullable();
            $table->decimal('banked_amount', 10, 2);
            $table->string('m_pesa_ref', 100)->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->foreign('recorded_by')->references('id')->on('users')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['vehicle_id', 'banking_date']);
            $table->index(['agency_id', 'banking_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_banking');
    }
};
