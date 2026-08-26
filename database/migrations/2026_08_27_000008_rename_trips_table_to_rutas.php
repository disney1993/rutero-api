<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// Rutero -> "ruta". El proyecto dejó de llamar "viajes" a su entidad
// principal para que el nombre encaje con el de la app; esta migración
// renombra la tabla (los datos y las claves foráneas no cambian).
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('trips', 'rutas');
    }

    public function down(): void
    {
        Schema::rename('rutas', 'trips');
    }
};
