<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // "Rechazado" desaparece como estado propio: las rutas que lo tuvieran
        // pasan a "cancelado" antes de estrechar el enum, para no perder datos
        // ni dejar filas con un valor que ya no existe en la columna.
        DB::table('rutas')->where('status', 'rejected')->update(['status' => 'cancelled']);

        DB::statement("ALTER TABLE rutas MODIFY status ENUM('pending', 'completed', 'cancelled') DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE rutas MODIFY status ENUM('pending', 'completed', 'rejected', 'cancelled') DEFAULT 'pending'");
    }
};
