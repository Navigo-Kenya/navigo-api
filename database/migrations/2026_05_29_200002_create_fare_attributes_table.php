<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fare_attributes', function (Blueprint $table) {
            $table->id();
            $table->string('fare_id')->unique();
            $table->decimal('price', 10, 2);
            $table->string('currency_type', 10)->default('KES');
            $table->tinyInteger('payment_method')->default(0); // 0=on_board, 1=before_boarding
            $table->tinyInteger('transfers')->nullable();      // 0=none, 1=once, 2=twice, null=unlimited
            $table->string('agency_id')->index();
            $table->foreign('agency_id')->references('agency_id')->on('agencies')->onDelete('cascade');
            $table->integer('transfer_duration')->nullable();  // seconds
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fare_attributes');
    }
};
