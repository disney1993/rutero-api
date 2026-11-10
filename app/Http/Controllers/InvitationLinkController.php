<?php

namespace App\Http\Controllers;

use App\Models\OwnerDriver;
use App\Models\OwnerMonthCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Enlaces de invitación: https://<api>/i/{código}.
 *
 * Sirven para los dos tipos de código, que se canjean igual (driver/join-code)
 * y en ambos casos vinculan al conductor con el equipo del propietario:
 * - team: invitación al equipo (un solo uso, caduca a los 7 días).
 * - day / month: código de una jornada (un día) o de un mes.
 *
 * La página web intenta abrir la app (rutero://invite/{código}); si no está
 * instalada, ofrece descargarla y muestra el código para escribirlo a mano. El
 * endpoint JSON permite a la app mostrar quién invita antes de unirse. Quien
 * tiene el código solo ve el nombre del propietario y la fecha.
 */
class InvitationLinkController extends Controller
{
    /** @return array{type: string, owner: User, valid_on: ?string, month: ?string, expires_at: mixed}|null */
    private function resolve(string $code): ?array
    {
        if (! preg_match('/^[A-Za-z0-9]{8}$/', $code)) {
            return null;
        }
        $code = strtoupper($code);

        $invite = OwnerDriver::where('invite_code', $code)
            ->where('status', OwnerDriver::STATUS_PENDING)
            ->with('owner:id,first_name,last_name')
            ->first();
        if ($invite) {
            return $invite->isExpired() ? null : [
                'type' => 'team', 'owner' => $invite->owner, 'valid_on' => null, 'month' => null, 'expires_at' => $invite->expires_at,
            ];
        }

        $jornada = OwnerMonthCode::where('code', $code)->with('owner:id,first_name,last_name')->first();
        if ($jornada && $jornada->isJoinable()) {
            return [
                'type' => $jornada->isDay() ? 'day' : 'month',
                'owner' => $jornada->owner,
                'valid_on' => $jornada->valid_on?->toDateString(),
                'month' => $jornada->month,
                'expires_at' => null,
            ];
        }

        return null;
    }

    public function show(string $code): Response
    {
        $info = $this->resolve($code);
        $code = strtoupper($code);
        $links = config('rutero.app_links');

        $ownerName = $info ? trim($info['owner']->first_name.' '.$info['owner']->last_name) : null;
        $headline = match ($info['type'] ?? null) {
            'day' => "$ownerName te invita a su jornada del ".date('d/m/Y', strtotime($info['valid_on'])),
            'month' => "$ownerName te invita a sus jornadas de ".date('m/Y', strtotime($info['month'].'-01')),
            'team' => "$ownerName te invita a su equipo",
            default => null,
        };

        return response()->view('invitations.link', [
            'invite' => $info,
            'code' => $code,
            'ownerName' => $ownerName,
            'headline' => $headline,
            'appUrl' => $links['scheme'].'://invite/'.$code,
            // En Android, Chrome abre la app con un intent; si no está instalada
            // vuelve a esta misma página (o a la descarga, si está configurada).
            'intentUrl' => 'intent://invite/'.$code.'#Intent;scheme='.$links['scheme']
                .';package='.$links['android_package']
                .($links['download_url'] ? ';S.browser_fallback_url='.rawurlencode($links['download_url']) : '')
                .';end',
            'downloadUrl' => $links['download_url'],
        ], $info ? 200 : 404);
    }

    public function info(string $code): JsonResponse
    {
        $info = $this->resolve($code);
        if (! $info) {
            return response()->json(['message' => 'Invitación no válida o caducada'], 404);
        }

        return response()->json([
            'code' => strtoupper($code),
            'type' => $info['type'],
            'owner' => [
                'id' => $info['owner']->id,
                'first_name' => $info['owner']->first_name,
                'last_name' => $info['owner']->last_name,
            ],
            'valid_on' => $info['valid_on'],
            'month' => $info['month'],
            'expires_at' => $info['expires_at'],
        ]);
    }
}
