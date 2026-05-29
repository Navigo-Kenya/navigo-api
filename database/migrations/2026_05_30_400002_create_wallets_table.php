<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->enum('entity_type', ['vehicle', 'agency', 'platform']);
            $table->string('entity_id', 255);
            $table->decimal('balance', 12, 2)->default(0.00);
            $table->string('currency', 10)->default('KES');
            $table->timestamp('last_credited_at')->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'entity_id']);
            $table->index('entity_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
