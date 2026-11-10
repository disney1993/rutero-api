<?php

namespace App\Support;

use App\Models\Jornada;
use App\Models\OwnerDriver;
use App\Models\OwnerMonthCode;
use Illuminate\Support\Str;

/**
 * Códigos de 8 caracteres que el conductor escribe en el campo "Código" o
 * recibe en un enlace /i/{código}. Son únicos entre todos los tipos (código
 * de mes o día, invitación al equipo y jornada) para que un mismo campo y un
 * mismo enlace sirvan para todos.
 */
class ShareCode
{
    public static function generate(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (
            OwnerMonthCode::where('code', $code)->exists()
            || OwnerDriver::where('invite_code', $code)->exists()
            || Jornada::where('code', $code)->exists()
        );

        return $code;
    }
}
