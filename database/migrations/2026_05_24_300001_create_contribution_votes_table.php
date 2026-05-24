<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contribution_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contribution_id')->constrained()->cascadeOnDelete();
            $table->string('vote');  // up | down
            $table->timestamps();

            $table->unique(['user_id', 'contribution_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_votes');
    }
};
