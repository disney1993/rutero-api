<?php

namespace App\Http\Controllers;

use App\Models\DriverCodeEntry;
use App\Models\OwnerDriver;
use App\Models\OwnerMonthCode;
use Illuminate\Http\Request;

class DriverCodeController extends Controller
{
    public function join(Request $request)
    {
        $user = $request->user();
        if (! $user) return response()->json([], 401);

        $data = $request->validate([
            'code' => ['required', 'string', 'size:8', 'regex:/^[A-Z0-9]{8}$/i'],
            // Opcional (compatibilidad): los códigos de día se validan por valid_on.
            'month' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);
        $data['code'] = strtoupper($data['code']);

        $ownerCode = OwnerMonthCode::where('code', $data['code'])->first();
        if (! $ownerCode) {
            // El mismo campo sirve para las invitaciones al equipo por enlace.
            $invite = OwnerDriver::where('invite_code', $data['code'])
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

        if ($ownerCode->owner_id === $user->id) {
            return response()->json(['message' => 'No puedes unirte a tu propio código'], 422);
        }

        if (! $ownerCode->isJoinable()) {
            return response()->json(['message' => $ownerCode->isDay()
                ? 'Este código de jornada ya no es válido: era para el día indicado'
                : 'Este código ya no es válido'], 422);
        }

        $entry = DriverCodeEntry::firstOrCreate([
            'owner_month_code_id' => $ownerCode->id,
            'driver_id' => $user->id,
        ]);

        // Unirse a una jornada (de mes o de día) vincula también al conductor
        // con el equipo del propietario: así puede asignarle viajes después.
        OwnerDriver::activate($ownerCode->owner_id, $user);

        return response()->json(['message' => 'Joined', 'type' => $ownerCode->isDay() ? 'day' : 'month', 'entry' => $entry]);
    }

    public function listMyCodes(Request $request)
    {
        $user = $request->user();
        if (! $user) return response()->json([], 401);

        $entries = DriverCodeEntry::where('driver_id', $user->id)
            ->with('ownerMonthCode.owner:id,first_name,last_name,second_last_name,avatar_color')
            ->get();
        return response()->json($entries);
    }
}
