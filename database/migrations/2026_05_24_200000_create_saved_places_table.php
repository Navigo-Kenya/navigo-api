<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // Schema for user-saved places, e.g. "Home", "Work", "Gym", or custom labels
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('type');           // 'stop' | 'location'
            $table->string('place_id')->nullable();
            $table->string('list')->nullable(); // 'favorites'|'want_to_go'|'travel_plans'|'labeled'
            $table->string('pin')->nullable();  // 'home' | 'work'
            $table->string('category')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_places');
    }
};
