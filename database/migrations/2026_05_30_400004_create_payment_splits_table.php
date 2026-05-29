<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_splits', function (Blueprint $table) {
            $table->id();
            $table->string('external_ref', 255)->nullable()->comment('M-Pesa transaction reference');
            $table->decimal('amount_total', 12, 2);
            $table->foreignId('vehicle_wallet_id')->constrained('wallets');
            $table->foreignId('sacco_wallet_id')->constrained('wallets');
            $table->foreignId('platform_wallet_id')->constrained('wallets');
            $table->decimal('vehicle_amount', 12, 2);
            $table->decimal('sacco_amount', 12, 2);
            $table->decimal('platform_amount', 12, 2);
            $table->foreignId('split_config_id')->constrained('split_configs');
            $table->string('route_id', 255)->nullable();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->enum('status', ['completed', 'reversed'])->default('completed');
            $table->timestamps();

            $table->index('vehicle_id');
            $table->index('route_id');
            $table->index('external_ref');
            $table->index('created_at');
        });

        // Now add FK from wallet_transactions to payment_splits
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->foreign('payment_id')->references('id')->on('payment_splits')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
        });
        Schema::dropIfExists('payment_splits');
    }
};
