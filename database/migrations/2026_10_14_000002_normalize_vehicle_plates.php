<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Las matrículas pasan a guardarse solo con letras y números en mayúsculas
// (ver VehicleController::normalizePlate). Se limpian las ya guardadas.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('vehicles')->orderBy('id')->each(function ($v) {
            $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $v->plate));
            if ($clean === $v->plate) return;
            // Si al limpiarla coincidiera con otra existente se deja como estaba.
            if (DB::table('vehicles')->where('plate', $clean)->where('id', '!=', $v->id)->exists()) return;
            DB::table('vehicles')->where('id', $v->id)->update(['plate' => $clean]);
        });
    }

    public function down(): void
    {
        // No reversible: el formato original (guiones/espacios) no se conserva.
    }
};
