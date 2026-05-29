<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->enum('type', ['credit', 'debit', 'hold', 'release']);
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('reference', 255)->nullable();
            $table->foreignId('payment_id')->nullable()->comment('FK to payment_splits added after that table is created');
            $table->string('description', 500)->nullable();
            $table->string('created_by', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('wallet_id');
            $table->index('reference');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
