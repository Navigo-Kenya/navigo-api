<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->string('entity_id');
            $table->string('action');
            $table->jsonb('snapshot_json');
            $table->unsignedBigInteger('saved_by')->nullable();
            $table->foreign('saved_by')->references('id')->on('users')->nullOnDelete();
            $table->string('label')->nullable();
            $table->timestamp('created_at');
            $table->index(['entity_type', 'entity_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_snapshots');
    }
};
