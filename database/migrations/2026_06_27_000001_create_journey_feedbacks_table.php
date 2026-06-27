<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journey_feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_token', 64)->nullable()->index(); // stable anonymous ID
            $table->unsignedTinyInteger('rating')->nullable();      // 1–5 stars
            $table->enum('fare_choice', ['matched', 'more', 'less'])->nullable();
            $table->unsignedInteger('custom_fare')->nullable();     // user-entered amount
            $table->unsignedInteger('estimated_fare')->nullable();  // what the app showed
            $table->string('currency', 10)->default('KES');
            $table->json('tags')->nullable();                       // ['on_time', 'crowded', ...]
            $table->string('to_name', 255)->nullable();
            $table->string('route_summary', 255)->nullable();
            $table->enum('status', ['submitted', 'dismissed'])->default('submitted');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journey_feedbacks');
    }
};
