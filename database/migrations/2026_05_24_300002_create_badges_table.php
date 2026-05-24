<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('description');
            $table->string('icon');                     // Ionicons glyph name
            $table->string('color');                    // hex
            $table->string('requirement_type');         // total_count|type_count|points|approved_type_count
            $table->unsignedInteger('requirement_value');
            $table->json('requirement_meta')->nullable(); // { "type": "stop_photo" }
            $table->unsignedInteger('points_bonus')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badges');
    }
};
