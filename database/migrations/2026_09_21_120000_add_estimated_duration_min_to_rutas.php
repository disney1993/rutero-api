<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rutas')) {
            return;
        }
        if (! Schema::hasColumn('rutas', 'estimated_duration_min')) {
            Schema::table('rutas', function (Blueprint $table) {
                $table->unsignedInteger('estimated_duration_min')->nullable()->after('estimated_distance_km');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rutas') && Schema::hasColumn('rutas', 'estimated_duration_min')) {
            Schema::table('rutas', function (Blueprint $table) {
                $table->dropColumn('estimated_duration_min');
            });
        }
    }
};
