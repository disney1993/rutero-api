<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// El estado "activo" de los vehículos no filtraba nada (solo pintaba una
// etiqueta en el listado), así que se elimina. Para retirar un vehículo se
// borra; el borrado ya está protegido si tiene viajes o es el predeterminado.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('active');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('vehicle_type');
        });
    }
};
