<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// El color de los vehículos pasa de guardarse por nombre ("Blanco") a hex
// "#RRGGBB". Convierte los datos existentes y acorta la columna a 7 chars.
// El mapa va copiado aquí (y no tomado de Vehicle::COLOR_NAMES) para que la
// migración siga dando el mismo resultado aunque el modelo cambie después.
return new class extends Migration
{
    private const NAMES = [
        'blanco' => '#FFFFFF', 'negro' => '#1C1C1C', 'gris' => '#9E9E9E',
        'plata' => '#C7C7C7', 'azul' => '#1565C0', 'rojo' => '#D32F2F',
        'verde' => '#2E7D32', 'amarillo' => '#FBC02D', 'naranja' => '#EF6C00',
        'marron' => '#5D4037', 'beige' => '#D7CCC8',
        'azul marino' => '#1B2A4A', 'celeste' => '#4FC3F7', 'turquesa' => '#00838F',
        'lima' => '#9CCC65', 'morado' => '#6B2C91', 'violeta' => '#6B2C91',
        'magenta' => '#C2185B', 'rosa' => '#EC407A', 'granate' => '#7B1E2B',
        'burdeos' => '#7B1E2B', 'dorado' => '#C9B37E', 'bronce' => '#8D6E63',
        'white' => '#FFFFFF', 'black' => '#1C1C1C', 'gray' => '#9E9E9E',
        'grey' => '#9E9E9E', 'silver' => '#C7C7C7', 'blue' => '#1565C0',
        'navy' => '#1B2A4A', 'red' => '#D32F2F', 'maroon' => '#7B1E2B',
        'green' => '#2E7D32', 'olive' => '#1F4D3A', 'lime' => '#2E7D32',
        'teal' => '#00838F', 'aqua' => '#00838F', 'yellow' => '#FBC02D',
        'orange' => '#EF6C00', 'purple' => '#6B2C91', 'fuchsia' => '#6B2C91',
    ];

    // Lo que no se reconoce (texto libre antiguo) queda en gris neutro.
    private const FALLBACK = '#9E9E9E';

    public function up(): void
    {
        DB::table('vehicles')->select('id', 'color')->orderBy('id')->each(function ($row) {
            $hex = $this->toHex($row->color);
            if ($hex !== $row->color) {
                DB::table('vehicles')->where('id', $row->id)->update(['color' => $hex]);
            }
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('color', 7)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('color')->nullable()->change();
        });

        // Vuelve a nombre solo los tonos de la paleta; los hex propios se quedan.
        $byHex = array_flip(array_slice(self::NAMES, 0, 11, true));
        foreach ($byHex as $hex => $name) {
            DB::table('vehicles')->where('color', $hex)->update(['color' => ucfirst($name)]);
        }
    }

    private function toHex(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^#([0-9A-Fa-f]{6})$/', $value, $m)) {
            return '#'.strtoupper($m[1]);
        }
        if (preg_match('/^#([0-9A-Fa-f])([0-9A-Fa-f])([0-9A-Fa-f])$/', $value, $m)) {
            return strtoupper("#{$m[1]}{$m[1]}{$m[2]}{$m[2]}{$m[3]}{$m[3]}");
        }
        $key = strtr(mb_strtolower($value), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);

        return self::NAMES[$key] ?? self::FALLBACK;
    }
};
