<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('route_licenses', function (Blueprint $table) {
            $table->id();
            $table->string('agency_id', 50);
            $table->string('route_id', 100)->nullable();
            $table->string('license_number', 100)->nullable();
            $table->string('issuing_authority', 100)->nullable(); // Nairobi City County / NTSA
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('goodwill_value', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('agency_id')->references('agency_id')->on('agencies')->cascadeOnDelete();
            $table->foreign('route_id')->references('route_id')->on('routes')->nullOnDelete();
            $table->index(['agency_id', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_licenses');
    }
};
