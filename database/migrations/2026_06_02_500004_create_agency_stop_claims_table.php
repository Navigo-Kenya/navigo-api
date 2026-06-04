<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agency_stop_claims', function (Blueprint $table) {
            $table->id();
            $table->string('agency_id', 50);
            $table->string('stop_id', 100);
            $table->unsignedBigInteger('claimed_by')->nullable();
            $table->timestamp('claimed_at')->useCurrent();

            $table->foreign('agency_id')->references('agency_id')->on('agencies')->cascadeOnDelete();
            $table->foreign('stop_id')->references('id')->on('stops')->cascadeOnDelete();
            $table->foreign('claimed_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['stop_id', 'agency_id']);
            $table->index('agency_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_stop_claims');
    }
};
