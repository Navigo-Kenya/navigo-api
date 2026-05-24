<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_journeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('from_name');
            $table->decimal('from_lat', 10, 7);
            $table->decimal('from_lng', 10, 7);
            $table->string('from_id')->nullable();
            $table->string('from_type');
            $table->string('to_name');
            $table->decimal('to_lat', 10, 7);
            $table->decimal('to_lng', 10, 7);
            $table->string('to_id')->nullable();
            $table->string('to_type');
            $table->string('summary');
            $table->integer('duration');  // seconds
            $table->json('route');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_journeys');
    }
};
