<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cached_snapped_polylines', function (Blueprint $table) {
            $table->id();
            $table->string('cache_key')->unique();
            $table->jsonb('coordinates');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cached_snapped_polylines');
    }
};
