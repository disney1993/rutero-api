<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owner_month_codes', function (Blueprint $table) {
            $table->string('type', 16)->default('month')->after('month');
            $table->date('valid_on')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('owner_month_codes', function (Blueprint $table) {
            $table->dropColumn(['type', 'valid_on']);
        });
    }
};
