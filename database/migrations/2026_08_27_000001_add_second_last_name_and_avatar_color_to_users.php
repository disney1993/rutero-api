<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'second_last_name')) {
                $table->string('second_last_name')->nullable()->after('last_name');
            }
            if (! Schema::hasColumn('users', 'avatar_color')) {
                $table->string('avatar_color', 7)->nullable()->after('avatar');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['second_last_name', 'avatar_color']);
        });
    }
};
