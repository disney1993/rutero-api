<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rutas', function (Blueprint $table) {
            // Duración por carretera (OSRM) guardada al crear/editar la ruta:
            // es la base para estimar cuándo termina el viaje.
            $table->unsignedSmallInteger('estimated_duration_min')->nullable()->after('estimated_distance_km');
            $table->boolean('auto_completed')->default(false)->after('completed_by');
            // Se conserva aunque el usuario deshaga el cierre: el cron solo
            // procesa una vez cada ruta, para no volver a cerrarla en bucle.
            $table->timestamp('auto_completed_at')->nullable()->after('auto_completed');
            // Momento en que alguien revisó (o corrigió) un cierre automático;
            // mientras sea null la ruta aparece en "Por revisar".
            $table->timestamp('reviewed_at')->nullable()->after('auto_completed_at');
            $table->index(['status', 'auto_completed_at', 'trip_date']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('auto_complete_enabled')->default(true)->after('language');
            // Espera habitual hasta que el viaje arranca de verdad (recoger al
            // cliente, equipaje): suele ser de 5 a 10 minutos.
            $table->unsignedSmallInteger('auto_complete_start_delay_min')->default(10)->after('auto_complete_enabled');
            // Margen final: bajada del cliente y cobro.
            $table->unsignedSmallInteger('auto_complete_margin_min')->default(5)->after('auto_complete_start_delay_min');
            $table->unsignedTinyInteger('auto_complete_margin_pct')->default(10)->after('auto_complete_margin_min');
            $table->unsignedSmallInteger('auto_complete_default_duration_min')->default(60)->after('auto_complete_margin_pct');
        });
    }

    public function down(): void
    {
        Schema::table('rutas', function (Blueprint $table) {
            $table->dropIndex(['status', 'auto_completed_at', 'trip_date']);
            $table->dropColumn(['estimated_duration_min', 'auto_completed', 'auto_completed_at', 'reviewed_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'auto_complete_enabled',
                'auto_complete_start_delay_min',
                'auto_complete_margin_min',
                'auto_complete_margin_pct',
                'auto_complete_default_duration_min',
            ]);
        });
    }
};
