<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Borrado blando de usuarios y vehículos: al eliminar una cuenta desde admin
// no se pierden filas (deleted_at). SoftDeletes en User las oculta de listados
// y del login; en Vehicle alinea el tratamiento con las rutas.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes()->index();
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->softDeletes()->index();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
