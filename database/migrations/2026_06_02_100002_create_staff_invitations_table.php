<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('agency_id', 50);
            $table->string('email', 255);
            $table->string('role', 100);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('agency_id')->references('agency_id')->on('agencies')->cascadeOnDelete();
            $table->index(['agency_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_invitations');
    }
};
