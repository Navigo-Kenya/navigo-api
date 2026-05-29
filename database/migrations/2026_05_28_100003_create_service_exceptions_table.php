<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('service_id')->index();
            $table->foreign('service_id')->references('service_id')->on('service_calendars')->onDelete('cascade');
            $table->date('date');
            $table->tinyInteger('exception_type');
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['service_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_exceptions');
    }
};
