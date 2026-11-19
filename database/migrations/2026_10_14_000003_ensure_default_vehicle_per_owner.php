<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Cada propietario con vehículos debe tener uno predeterminado. Los datos
// creados por el seeder no lo tenían: se marca el más antiguo de cada uno.
return new class extends Migration
{
    public function up(): void
    {
        $owners = DB::table('vehicles')
            ->select('owner_id')
            ->groupBy('owner_id')
            ->havingRaw('SUM(is_default) = 0')
            ->pluck('owner_id');

        foreach ($owners as $ownerId) {
            $firstId = DB::table('vehicles')->where('owner_id', $ownerId)->min('id');
            DB::table('vehicles')->where('id', $firstId)->update(['is_default' => true]);
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás: no se sabe cuáles se marcaron aquí y cuáles a mano.
    }
};
