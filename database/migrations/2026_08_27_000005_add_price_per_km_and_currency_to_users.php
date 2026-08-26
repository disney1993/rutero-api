<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('price_per_km', 10, 2)->nullable()->after('avatar_color');
            $table->string('currency', 3)->default('EUR')->after('price_per_km');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['price_per_km', 'currency']);
        });
    }
};
