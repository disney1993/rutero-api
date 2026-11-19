<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Datos de prueba: tres vehículos SIN viajes ni predeterminado para el
// propietario de demo (owner@example.test), de forma que se pueda probar el
// gesto de "deslizar para eliminar" (los demás vehículos de demo tienen viajes
// y por eso no se pueden borrar). Si el usuario no existe, no hace nada.
return new class extends Migration
{
    private const PLATES = ['PRUEBA01', 'PRUEBA02', 'PRUEBA03'];

    public function up(): void
    {
        $ownerId = DB::table('users')->where('email', 'owner@example.test')->value('id');
        if (! $ownerId) return;

        $now = now();
        $vehicles = [
            ['plate' => 'PRUEBA01', 'make' => 'SEAT', 'model' => 'Ibiza', 'color' => 'Rojo', 'year' => 2019, 'seats' => 5, 'vehicle_type' => 'compacto'],
            ['plate' => 'PRUEBA02', 'make' => 'Yamaha', 'model' => 'MT-07', 'color' => 'Azul', 'year' => 2021, 'seats' => 2, 'vehicle_type' => 'motocicleta'],
            ['plate' => 'PRUEBA03', 'make' => 'Iveco', 'model' => 'Daily', 'color' => 'Blanco', 'year' => 2018, 'seats' => 3, 'vehicle_type' => 'camion'],
        ];

        foreach ($vehicles as $v) {
            if (DB::table('vehicles')->where('plate', $v['plate'])->exists()) continue;
            DB::table('vehicles')->insert($v + [
                'owner_id' => $ownerId,
                'is_default' => false,
                'notes' => 'Vehículo de prueba sin viajes: se puede eliminar deslizando.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Solo los que sigan sin viajes (si alguien les asignó uno, se respetan).
        DB::table('vehicles')
            ->whereIn('plate', self::PLATES)
            ->whereNotIn('id', DB::table('rutas')->whereNotNull('vehicle_id')->select('vehicle_id'))
            ->delete();
    }
};
