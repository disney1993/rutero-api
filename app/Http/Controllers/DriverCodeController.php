<?php

namespace App\Http\Controllers;

use App\Models\OwnerDriver;
use Illuminate\Http\Request;

/**
 * Canjear a mano el código de una invitación al equipo (el mismo del enlace
 * /i/{código}), para quien no puede abrir el enlace. Es la única forma de
 * entrar en un equipo con un código: un solo uso y caduca a los 7 días.
 */
class DriverCodeController extends Controller
{
    public function join(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'code' => ['required', 'string', 'size:8', 'regex:/^[A-Z0-9]{8}$/i'],
        ]);

        $invite = OwnerDriver::where('invite_code', strtoupper($data['code']))
            ->where('status', OwnerDriver::STATUS_PENDING)
            ->first();
        if (! $invite || $invite->isExpired()) {
            return response()->json(['message' => 'Código inválido o caducado'], 422);
        }
        if ($invite->owner_id === $user->id) {
            return response()->json(['message' => 'No puedes unirte a tu propio equipo'], 422);
        }
        $link = $invite->acceptBy($user);

        return response()->json(['message' => 'Joined', 'type' => 'team', 'owner_id' => $link->owner_id]);
    }
}
