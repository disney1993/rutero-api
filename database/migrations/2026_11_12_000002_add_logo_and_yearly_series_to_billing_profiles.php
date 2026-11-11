<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - logo: imagen del emisor en "data URI" (PNG o JPEG, máx. 300 KB). Se guarda
 *   en la base de datos porque el disco de Laravel Cloud no es persistente.
 * - yearly_series: si la serie empieza por un año ("2026-"), al emitir la
 *   primera factura de un año nuevo pasa sola a ese año y empieza en 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_profiles', function (Blueprint $table) {
            $table->mediumText('logo')->nullable()->after('iban');
            $table->boolean('yearly_series')->default(true)->after('series');
        });
    }

    public function down(): void
    {
        Schema::table('billing_profiles', function (Blueprint $table) {
            $table->dropColumn(['logo', 'yearly_series']);
        });
    }
};
