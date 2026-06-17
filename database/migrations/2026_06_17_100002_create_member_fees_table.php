<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_fees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id');
            $table->foreign('member_id')->references('id')->on('sacco_members')->onDelete('cascade');
            $table->enum('fee_type', ['registration', 'share_capital', 'entrance_fee', 'monthly_dues', 'other']);
            $table->decimal('amount', 10, 2);
            $table->timestamp('paid_at');
            $table->enum('method', ['mpesa', 'cash', 'bank_transfer']);
            $table->string('mpesa_ref', 30)->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->foreign('recorded_by')->references('id')->on('users')->onDelete('set null');
            $table->string('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_fees');
    }
};
