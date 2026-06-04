<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->foreign('owner_id')->references('id')->on('vehicle_owners')->onDelete('cascade');
            $table->string('document_type', 50)->default('other'); // national_id, contract, other
            $table->string('label');
            $table->string('file_url');
            $table->date('expiry_date')->nullable();
            $table->timestamps();

            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_documents');
    }
};
