<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Conductores asignados a una jornada. La fila no se borra al quitar al
        // conductor (removed_at): así queda constancia de cuándo se le avisó y,
        // si se le vuelve a asignar, la ve de nuevo sin otro aviso.
        Schema::create('jornada_drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jornada_id')->constrained('jornadas')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('notified_at');
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->unique(['jornada_id', 'driver_id']);
        });

        // Quién ve los viajes sin asignar de la jornada: todo el equipo (como
        // hasta ahora) o solo los conductores asignados.
        Schema::table('jornadas', function (Blueprint $table) {
            $table->string('visibility', 10)->default('team');
        });
    }

    public function down(): void
    {
        Schema::table('jornadas', fn (Blueprint $table) => $table->dropColumn('visibility'));
        Schema::dropIfExists('jornada_drivers');
    }
};
