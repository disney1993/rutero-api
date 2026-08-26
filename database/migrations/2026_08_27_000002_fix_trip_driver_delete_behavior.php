<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Un viaje pertenece a su owner; el driver es solo quien lo conduce.
    // Borrar la cuenta del driver no debe destruir el historial del owner.
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropForeign(['driver_id']);
        });
        Schema::table('trips', function (Blueprint $table) {
            $table->foreign('driver_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropForeign(['driver_id']);
        });
        Schema::table('trips', function (Blueprint $table) {
            $table->foreign('driver_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
