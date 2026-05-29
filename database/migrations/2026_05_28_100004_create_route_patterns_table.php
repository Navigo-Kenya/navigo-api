<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_patterns', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('route_id')->index();
            $table->foreign('route_id')->references('route_id')->on('routes')->onDelete('cascade');
            $table->string('name');
            $table->tinyInteger('direction_id')->default(0);
            $table->boolean('is_canonical')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_patterns');
    }
};
