<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_vetting', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id');
            $table->foreign('member_id')->references('id')->on('sacco_members')->onDelete('cascade');
            $table->unsignedBigInteger('vetted_by');
            $table->foreign('vetted_by')->references('id')->on('users')->onDelete('cascade');
            $table->enum('decision', ['approved', 'flagged']);
            $table->text('notes')->nullable();
            $table->timestamp('vetted_at');

            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_vetting');
    }
};
