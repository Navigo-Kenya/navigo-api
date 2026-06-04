<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conductors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('agency_id', 50);
            $table->foreign('agency_id')->references('agency_id')->on('agencies')->onDelete('cascade');
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('psv_badge_no', 50)->nullable();
            $table->date('psv_badge_expiry')->nullable();
            $table->string('status', 20)->default('active'); // active / suspended / terminated
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conductors');
    }
};
