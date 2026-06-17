<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id');
            $table->foreign('member_id')->references('id')->on('sacco_members')->onDelete('cascade');
            $table->enum('doc_type', ['national_id', 'kra_pin', 'logbook', 'insurance', 'inspection_cert', 'photo', 'other']);
            $table->string('label')->nullable();
            $table->string('url');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('set null');
            $table->timestamp('created_at')->useCurrent();

            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_documents');
    }
};
