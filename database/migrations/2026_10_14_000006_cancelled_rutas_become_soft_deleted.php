<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// "Cancelar" y "eliminar" pasan a ser lo mismo (borrado blando con estado
// cancelled). Los viajes que ya estaban cancelados se marcan como eliminados.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('rutas')
            ->where('status', 'cancelled')
            ->whereNull('deleted_at')
            ->update(['deleted_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        // Los cancelados vuelven a ser visibles como antes.
        DB::table('rutas')->where('status', 'cancelled')->update(['deleted_at' => null]);
    }
};
