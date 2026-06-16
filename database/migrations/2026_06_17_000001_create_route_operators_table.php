<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('route_operators', function (Blueprint $table) {
            $table->string('route_id', 100);
            $table->string('agency_id', 50);
            $table->enum('status', ['active', 'pending'])->default('active');
            $table->timestamp('linked_at')->useCurrent();

            $table->primary(['route_id', 'agency_id']);
            $table->foreign('route_id')->references('route_id')->on('routes')->cascadeOnDelete();
            $table->foreign('agency_id')->references('agency_id')->on('agencies')->cascadeOnDelete();
            $table->index('agency_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_operators');
    }
};
