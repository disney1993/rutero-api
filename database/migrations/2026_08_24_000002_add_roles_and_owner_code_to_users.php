<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'owner', 'driver'])->default('driver')->after('email');
            $table->string('owner_code')->nullable()->unique()->after('role');
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete()->after('owner_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
            $table->dropColumn(['role', 'owner_code']);
        });
    }
};
