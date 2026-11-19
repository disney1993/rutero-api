<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Los códigos de jornada ya no se usan: las jornadas nuevas no tienen código.
// La columna (y las tablas de códigos de mes y de día) se borran más adelante,
// en otra migración, cuando se haya comprobado que no hacen falta.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jornadas', function (Blueprint $table) {
            $table->string('code', 8)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Sin código no se puede volver a NOT NULL: se deja como está.
    }
};
