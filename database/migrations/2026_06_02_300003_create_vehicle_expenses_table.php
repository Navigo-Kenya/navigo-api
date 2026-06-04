<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('agency_id', 50);
            $table->unsignedInteger('vehicle_id');
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('cascade');
            // fuel / service / insurance / inspection / tyres / other
            $table->string('expense_type', 50);
            $table->decimal('amount', 10, 2);
            $table->decimal('litres', 8, 2)->nullable();
            $table->unsignedInteger('odometer_km')->nullable();
            $table->text('description')->nullable();
            $table->string('receipt_ref', 100)->nullable();
            $table->date('expense_date');
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->foreign('recorded_by')->references('id')->on('users')->onDelete('set null');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['vehicle_id', 'expense_date']);
            $table->index(['agency_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_expenses');
    }
};
