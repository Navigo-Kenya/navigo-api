<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corridor_routes', function (Blueprint $table) {
            $table->id();
            $table->string('corridor_id')->index();
            $table->foreign('corridor_id')->references('corridor_id')->on('corridors')->onDelete('cascade');
            $table->string('route_id')->index();
            $table->foreign('route_id')->references('route_id')->on('routes')->onDelete('cascade');
            $table->tinyInteger('direction_id')->nullable();
            $table->timestamps();
            $table->unique(['corridor_id', 'route_id', 'direction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corridor_routes');
    }
};
