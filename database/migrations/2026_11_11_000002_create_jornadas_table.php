<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jornadas: un propietario puede organizar un mismo día en varias jornadas,
 * normalmente una por conductor, cada una con su vehículo y su código para
 * compartirla. Los viajes creados en una jornada heredan su conductor y su
 * vehículo. Un día sin jornadas se trata como una única "Jornada 1" virtual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jornadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->string('label', 40);
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            // Código para compartir la jornada (mismo formato que los demás códigos).
            $table->string('code', 8)->unique();
            $table->unsignedSmallInteger('position')->default(1);
            $table->timestamps();
            $table->index(['owner_id', 'date']);
        });

        Schema::table('rutas', function (Blueprint $table) {
            $table->foreignId('jornada_id')->nullable()->after('vehicle_id')->constrained('jornadas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rutas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('jornada_id');
        });
        Schema::dropIfExists('jornadas');
    }
};
