<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sacco_members', function (Blueprint $table) {
            $table->id();
            $table->string('agency_id', 50);
            $table->foreign('agency_id')->references('agency_id')->on('agencies')->onDelete('cascade');
            $table->string('membership_no', 30)->unique();
            $table->enum('membership_class', ['class_a', 'class_b'])->default('class_a');
            $table->enum('status', ['draft', 'pending_vetting', 'approved', 'active', 'suspended', 'terminated'])->default('pending_vetting');
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('national_id', 50)->nullable();
            $table->string('kra_pin', 30)->nullable();
            $table->string('m_pesa_number', 30)->nullable();
            $table->unsignedBigInteger('vehicle_owner_id')->nullable();
            $table->foreign('vehicle_owner_id')->references('id')->on('vehicle_owners')->onDelete('set null');
            $table->boolean('voting_rights')->default(true);
            $table->decimal('share_capital_paid', 10, 2)->default(0.00);
            $table->text('notes')->nullable();
            $table->date('joined_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->timestamps();

            $table->index('agency_id');
            $table->index('status');
            $table->index('membership_class');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_members');
    }
};
