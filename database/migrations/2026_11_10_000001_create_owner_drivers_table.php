<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Equipo: vínculo persistente y con consentimiento entre un propietario y sus
 * conductores. Sustituye al código mensual como forma de "pertenecer" a un
 * propietario; el código pasa a ser solo una vía de invitación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            // Nulo mientras la invitación por enlace no se haya usado o la
            // invitación por email sea para alguien que aún no tiene cuenta.
            $table->foreignId('driver_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('invited_email')->nullable()->index();
            $table->string('status', 16)->default('pending'); // pending|active|rejected|revoked
            $table->string('invited_via', 16)->default('email'); // email|link|code
            // Código de 8 caracteres de las invitaciones por enlace (de un solo uso).
            $table->string('invite_code', 8)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->string('alias', 60)->nullable();
            $table->foreignId('default_vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['owner_id', 'driver_id']);
            $table->index(['driver_id', 'status']);
        });

        Schema::table('ruta_completions', function (Blueprint $table) {
            // manual: lo marcó una persona; auto: lo cerró el cron (RutaAutoCompleter).
            $table->string('source', 8)->default('manual')->after('user_id');
        });

        // Los conductores ya unidos a un código MENSUAL pasan a ser parte del
        // equipo. Los de un código de día eran colaboraciones puntuales: no.
        $pairs = DB::table('driver_code_entries')
            ->join('owner_month_codes', 'owner_month_codes.id', '=', 'driver_code_entries.owner_month_code_id')
            ->where(function ($q) {
                $q->where('owner_month_codes.type', 'month')->orWhereNull('owner_month_codes.type');
            })
            ->whereColumn('owner_month_codes.owner_id', '!=', 'driver_code_entries.driver_id')
            ->select('owner_month_codes.owner_id', 'driver_code_entries.driver_id', DB::raw('MIN(driver_code_entries.created_at) as joined_at'))
            ->groupBy('owner_month_codes.owner_id', 'driver_code_entries.driver_id')
            ->get();

        foreach ($pairs as $pair) {
            DB::table('owner_drivers')->insertOrIgnore([
                'owner_id' => $pair->owner_id,
                'driver_id' => $pair->driver_id,
                'status' => 'active',
                'invited_via' => 'code',
                'accepted_at' => $pair->joined_at ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('ruta_completions', function (Blueprint $table) {
            $table->dropColumn('source');
        });
        Schema::dropIfExists('owner_drivers');
    }
};
