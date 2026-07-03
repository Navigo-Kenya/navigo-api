<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // preference: durable stated preference ("avoids CBD at night")
            // fact: derived/durable fact about the user ("commutes to Westlands")
            $table->string('kind', 20)->default('preference');
            $table->string('content', 200);
            $table->string('source', 20)->default('stated'); // stated | derived
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_memories');
    }
};
