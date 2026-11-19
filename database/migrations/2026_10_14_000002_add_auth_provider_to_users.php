<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Cómo se creó la cuenta: password (registro email) o google (Socialite / id_token).
            $table->string('auth_provider', 32)->default('password')->after('email');
            $table->string('google_id')->nullable()->unique()->after('auth_provider');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['auth_provider', 'google_id']);
        });
    }
};
