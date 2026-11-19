<?php

namespace App\Http\Controllers;

use App\Models\DriverCodeEntry;
use App\Models\Jornada;
use App\Models\OwnerDriver;
use App\Models\OwnerMonthCode;
use Illuminate\Http\Request;

class DriverCodeController extends Controller
{
    public function join(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([], 401);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'size:8', 'regex:/^[A-Z0-9]{8}$/i'],
            // Opcional (compatibilidad): los códigos de día se validan por valid_on.
            'month' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);
        $data['code'] = strtoupper($data['code']);

        // Código de una jornada concreta (varias por día, una por conductor).
        $jornada = Jornada::where('code', $data['code'])->first();
        if ($jornada) {
            return $this->joinJornada($jornada, $user);
        }

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

        if (! OwnerDriver::isActive($ownerCode->owner_id, $user->id)) {
            return $this->notInTeam();
        }

        $entry = DriverCodeEntry::firstOrCreate([
            'owner_month_code_id' => $ownerCode->id,
            'driver_id' => $user->id,
        ]);

        return response()->json(['message' => 'Joined', 'type' => $ownerCode->isDay() ? 'day' : 'month', 'entry' => $entry]);
    }

    /**
     * Los códigos de jornada y de mes se comparten y se pueden reenviar: solo
     * sirven a quien ya está en el equipo. Para entrar en él hace falta una
     * invitación (email o enlace de un solo uso) que el conductor acepta.
     */
    private function notInTeam()
    {
        return response()->json([
            'message' => 'Este código es para conductores del equipo. Pide al propietario que te invite a su equipo.',
        ], 403);
    }

    /**
     * Unirse a una jornada (solo conductores del equipo):
     * si la jornada no tiene conductor, pasa a serlo (junto con sus viajes
     * pendientes sin asignar). Si ya tiene otro, no cambia nada.
     */
    private function joinJornada(Jornada $jornada, $user)
    {
        if ($jornada->owner_id === $user->id) {
            return response()->json(['message' => 'No puedes unirte a tu propia jornada'], 422);
        }
        if (! $jornada->isJoinable()) {
            return response()->json(['message' => 'Este código de jornada ya no es válido: era para el día indicado'], 422);
        }

        if (! OwnerDriver::isActive($jornada->owner_id, $user->id)) {
            return $this->notInTeam();
        }

        $assigned = false;
        if ($jornada->driver_id === null || $jornada->driver_id === $user->id) {
            $before = $jornada->only(['driver_id', 'vehicle_id']);
            $jornada->update(['driver_id' => $user->id]);
            $jornada->propagateToPendingTrips($before);
            $assigned = true;
        }

        return response()->json([
            'message' => $assigned ? 'Joined' : 'Joined team; jornada already has a driver',
            'type' => 'jornada',
            'owner_id' => $jornada->owner_id,
            'jornada_id' => $jornada->id,
            'assigned' => $assigned,
        ]);
    }

    public function listMyCodes(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([], 401);
        }

        $entries = DriverCodeEntry::where('driver_id', $user->id)
            ->with('ownerMonthCode.owner:id,first_name,last_name,second_last_name,avatar_color')
            ->get();

        return response()->json($entries);
    }
}
