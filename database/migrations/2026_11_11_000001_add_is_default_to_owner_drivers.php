<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conductor por defecto del propietario (como el vehículo por defecto): se
 * propone al crear viajes. Como mucho uno por propietario; si no hay ninguno,
 * los viajes nuevos los hace el propio propietario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owner_drivers', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('default_vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::table('owner_drivers', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
