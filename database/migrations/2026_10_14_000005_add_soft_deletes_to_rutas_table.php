<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Los viajes se eliminan de forma "blanda": quedan marcados (deleted_at) y se
// pueden restaurar, p. ej. si el cliente no contestaba y al final el viaje
// sí se hace. Los listados los muestran atenuados; calendarios, informes y el
// cierre automático los ignoran (scope de SoftDeletes).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rutas', function (Blueprint $table) {
            $table->softDeletes()->index();
        });
    }

    public function down(): void
    {
        Schema::table('rutas', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
