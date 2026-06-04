<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_to')->nullable()->after('resolved_by');
            $table->integer('escalation_level')->default(0)->after('assigned_to');
            $table->timestamp('last_escalated_at')->nullable()->after('escalation_level');
            $table->timestamp('sla_deadline')->nullable()->after('last_escalated_at');

            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->index(['sla_deadline', 'escalation_level']);
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropForeign(['assigned_to']);
            $table->dropIndex(['sla_deadline', 'escalation_level']);
            $table->dropColumn(['assigned_to', 'escalation_level', 'last_escalated_at', 'sla_deadline']);
        });
    }
};
