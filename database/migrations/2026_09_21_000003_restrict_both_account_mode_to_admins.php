<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Non-admin users cannot use "both"; migrate them to owner.
        DB::table('users')
            ->where('role', '!=', 'admin')
            ->where('account_mode', 'both')
            ->update(['account_mode' => 'owner']);
    }

    public function down(): void
    {
        // Irreversible preference cleanup.
    }
};
