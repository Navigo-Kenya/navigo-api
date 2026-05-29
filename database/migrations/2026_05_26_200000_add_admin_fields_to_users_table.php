<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['user', 'moderator', 'admin', 'superadmin'])
                  ->default('user')
                  ->after('points');
            $table->timestamp('banned_at')->nullable()->after('role');
            $table->string('ban_reason')->nullable()->after('banned_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'banned_at', 'ban_reason']);
        });
    }
};
