<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Widen the enum first so the backfill below is a valid value in either the old or new set.
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'owner', 'driver', 'user'])->default('driver')->change();
        });

        DB::table('users')->whereIn('role', ['owner', 'driver'])->update(['role' => 'user']);

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
            $table->dropUnique(['owner_code']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('owner_code');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'user'])->default('user')->change();
            $table->enum('plan', ['free', 'premium'])->default('free')->after('role');
            $table->timestamp('premium_expires_at')->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['plan', 'premium_expires_at']);
            $table->enum('role', ['admin', 'owner', 'driver'])->default('driver')->change();
            $table->string('owner_code')->nullable()->unique()->after('role');
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete()->after('owner_code');
        });
    }
};
