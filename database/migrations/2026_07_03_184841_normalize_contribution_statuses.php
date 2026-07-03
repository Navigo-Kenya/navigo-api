<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The console wrote status='declined' while the app and the original
     * schema use 'rejected'. Standardize on 'rejected' (the console keeps
     * showing a "Declined" label — display only).
     */
    public function up(): void
    {
        DB::table('contributions')->where('status', 'declined')->update(['status' => 'rejected']);
    }

    public function down(): void
    {
        // One-way normalization — nothing sensible to restore.
    }
};
