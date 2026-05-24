<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_number')->nullable()->unique()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('phone_number');
            $table->string('google_id')->nullable()->unique()->after('phone_verified_at');
            $table->string('apple_id')->nullable()->unique()->after('google_id');
            $table->string('avatar')->nullable()->after('apple_id');
            $table->string('oauth_provider')->nullable()->after('avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone_number', 'phone_verified_at', 'google_id', 'apple_id', 'avatar', 'oauth_provider']);
        });
    }
};
