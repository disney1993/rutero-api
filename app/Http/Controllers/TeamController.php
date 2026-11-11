<?php

namespace App\Http\Controllers;

use App\Models\Jornada;
use App\Mail\TeamInvitationMail;
use App\Models\OwnerDriver;
use App\Models\Ruta;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Equipo del propietario y, en el lado del conductor, sus invitaciones y los
 * propietarios para los que conduce.
 *
 * Privacidad: no hay buscador de usuarios. Al invitar por email la respuesta
 * es siempre la misma, exista o no la cuenta, y el nombre del conductor no se
 * muestra al propietario hasta que acepta.
 */
class TeamController extends Controller
{
    private const PUBLIC_USER = 'id,first_name,last_name,second_last_name,avatar_color,avatar';

    // ------------------------------------------------------------- propietario

    public function index(Request $request): JsonResponse
    {
        $owner = $request->user();
        if (! $this->canManageTeam($owner)) {
            return response()->json(['message' => 'Necesitas al menos un vehículo para tener un equipo'], 403);
        }

        $links = OwnerDriver::where('owner_id', $owner->id)
            ->whereIn('status', [OwnerDriver::STATUS_PENDING, OwnerDriver::STATUS_ACTIVE])
            ->with('driver:'.self::PUBLIC_USER.',email')
            ->orderByRaw("CASE status WHEN 'active' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->get();

        return response()->json($links->map(fn (OwnerDriver $l) => $this->presentForOwner($l))->values());
    }

    public function invite(Request $request): JsonResponse
    {
        $owner = $request->user();
        if (! $this->canManageTeam($owner)) {
            return response()->json(['message' => 'Necesitas al menos un vehículo para tener un equipo'], 403);
        }

        $data = $request->validate([
            'type' => ['required', Rule::in([OwnerDriver::VIA_EMAIL, OwnerDriver::VIA_LINK])],
            'email' => ['required_if:type,email', 'nullable', 'string', 'email:rfc', 'max:255'],
        ]);

        if ($data['type'] === OwnerDriver::VIA_LINK) {
            $link = OwnerDriver::create([
                'owner_id' => $owner->id,
                'status' => OwnerDriver::STATUS_PENDING,
                'invited_via' => OwnerDriver::VIA_LINK,
                'invite_code' => OwnerDriver::newInviteCode(),
                'expires_at' => now()->addDays(OwnerDriver::LINK_TTL_DAYS),
            ]);

            return response()->json($this->presentForOwner($link), 201);
        }

        $email = mb_strtolower(trim($data['email']));
        if ($email === mb_strtolower($owner->email)) {
            return response()->json(['message' => 'No puedes invitarte a ti mismo'], 422);
        }

        $driver = User::whereRaw('LOWER(email) = ?', [$email])->first();
        $existing = OwnerDriver::where('owner_id', $owner->id)
            ->where(function ($q) use ($driver, $email) {
                $q->where('invited_email', $email);
                if ($driver) {
                    $q->orWhere('driver_id', $driver->id);
                }
            })
            ->first();

        if (! $existing) {
            OwnerDriver::create([
                'owner_id' => $owner->id,
                'driver_id' => $driver?->id,
                'invited_email' => $email,
                'status' => OwnerDriver::STATUS_PENDING,
                'invited_via' => OwnerDriver::VIA_EMAIL,
            ]);
        } elseif ($existing->status !== OwnerDriver::STATUS_ACTIVE) {
            // Reinvitar a quien rechazó o fue dado de baja.
            $existing->update([
                'status' => OwnerDriver::STATUS_PENDING,
                'invited_email' => $email,
                'invited_via' => OwnerDriver::VIA_EMAIL,
                'revoked_at' => null,
                'revoked_by' => null,
            ]);
        }

        // Correo de invitación (si hay servicio de correo configurado). Se
        // envía exista o no la cuenta, así que tampoco revela nada.
        $emailSent = ! ($existing && $existing->status === OwnerDriver::STATUS_ACTIVE)
            && $this->sendInvitationEmail($owner, $email);

        // Misma respuesta en todos los casos: no revela si el email tiene cuenta.
        return response()->json([
            'message' => 'Invitación registrada. La persona la verá en Rutero al iniciar sesión con ese email.',
            'email_sent' => $emailSent,
        ], 202);
    }

    public function update(Request $request, OwnerDriver $link): JsonResponse
    {
        $owner = $request->user();
        if ($link->owner_id !== $owner->id) {
            return response()->json([], 403);
        }

        $data = $request->validate([
            'alias' => ['sometimes', 'nullable', 'string', 'max:60'],
            'default_vehicle_id' => ['sometimes', 'nullable', 'integer'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['is_default']) && $link->status !== OwnerDriver::STATUS_ACTIVE) {
            return response()->json(['message' => 'Solo un conductor que ya forma parte del equipo puede ser el predeterminado'], 422);
        }

        if (! empty($data['default_vehicle_id'])
            && ! Vehicle::where('id', $data['default_vehicle_id'])->where('owner_id', $owner->id)->exists()) {
            return response()->json(['message' => 'El vehículo no pertenece a tu flota'], 422);
        }

        // Como mucho un conductor por defecto por propietario.
        if (! empty($data['is_default'])) {
            OwnerDriver::where('owner_id', $owner->id)->where('id', '!=', $link->id)->update(['is_default' => false]);
        }

        $link->update($data);

        return response()->json($this->presentForOwner($link->fresh('driver')));
    }

    /** Quitar del equipo (propietario) o dejar de conducir para él (conductor). */
    public function destroy(Request $request, OwnerDriver $link): JsonResponse
    {
        $user = $request->user();
        $isOwner = $link->owner_id === $user->id;
        $isDriver = $link->driver_id !== null && $link->driver_id === $user->id;
        if (! $isOwner && ! $isDriver) {
            return response()->json([], 403);
        }

        // Una invitación que nunca se aceptó no deja rastro.
        if ($link->status === OwnerDriver::STATUS_PENDING && $isOwner) {
            $link->delete();

            return response()->json(['message' => 'Invitación anulada']);
        }

        $link->update([
            'status' => OwnerDriver::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_by' => $user->id,
            'invite_code' => null,
            'is_default' => false,
        ]);

        // Sus jornadas y viajes pendientes desde hoy quedan sin conductor, para
        // que el propietario los reasigne (o los vea el resto del equipo).
        if ($link->driver_id) {
            $today = now(config('rutero.auto_complete.timezone'))->toDateString();
            Jornada::where('owner_id', $link->owner_id)->where('driver_id', $link->driver_id)
                ->whereDate('date', '>=', $today)->update(['driver_id' => null]);
            Ruta::where('owner_id', $link->owner_id)->where('driver_id', $link->driver_id)
                ->where('status', 'pending')->whereDate('trip_date', '>=', $today)->update(['driver_id' => null]);
        }

        return response()->json(['message' => 'Vínculo eliminado']);
    }

    // --------------------------------------------------------------- conductor

    /** Invitaciones pendientes dirigidas al usuario (por su cuenta o su email). */
    public function myInvitations(Request $request): JsonResponse
    {
        $user = $request->user();

        $invitations = OwnerDriver::where('status', OwnerDriver::STATUS_PENDING)
            ->where('invited_via', OwnerDriver::VIA_EMAIL)
            ->where('owner_id', '!=', $user->id)
            ->where(function ($q) use ($user) {
                $q->where('driver_id', $user->id)
                    ->orWhere(fn ($q) => $q->whereNull('driver_id')->whereRaw('LOWER(invited_email) = ?', [mb_strtolower($user->email)]));
            })
            ->with('owner:'.self::PUBLIC_USER)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($invitations->map(fn (OwnerDriver $l) => [
            'id' => $l->id,
            'owner' => $l->owner,
            'created_at' => $l->created_at,
        ])->values());
    }

    public function accept(Request $request, OwnerDriver $link): JsonResponse
    {
        $user = $request->user();
        if (! $this->isInvitationFor($link, $user)) {
            return response()->json([], 404);
        }

        $active = $link->acceptBy($user);

        return response()->json(['message' => 'Ahora formas parte del equipo', 'link' => $this->presentForDriver($active->load('owner:'.self::PUBLIC_USER))]);
    }

    public function reject(Request $request, OwnerDriver $link): JsonResponse
    {
        $user = $request->user();
        if (! $this->isInvitationFor($link, $user)) {
            return response()->json([], 404);
        }

        $link->update(['driver_id' => $user->id, 'status' => OwnerDriver::STATUS_REJECTED]);

        return response()->json(['message' => 'Invitación rechazada']);
    }

    /** Propietarios para los que conduce el usuario (vínculos activos). */
    public function myOwners(Request $request): JsonResponse
    {
        $links = OwnerDriver::active()
            ->where('driver_id', $request->user()->id)
            ->with('owner:'.self::PUBLIC_USER)
            ->get();

        return response()->json($links->map(fn (OwnerDriver $l) => $this->presentForDriver($l))->values());
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Envía el correo de invitación respetando el límite diario por
     * propietario. Un fallo del servicio de correo no impide la invitación:
     * la persona la verá igualmente al entrar en la app.
     */
    private function sendInvitationEmail(User $owner, string $email): bool
    {
        if (! config('rutero.invitations.send_email')) {
            return false;
        }
        $key = 'invite-emails:'.$owner->id.':'.now()->toDateString();
        $sentToday = (int) Cache::get($key, 0);
        if ($sentToday >= config('rutero.invitations.max_emails_per_day')) {
            return false;
        }
        try {
            Mail::to($email)->send(new TeamInvitationMail($owner, $email));
        } catch (\Throwable $e) {
            Log::warning('Invitation email failed: '.$e->getMessage());

            return false;
        }
        Cache::put($key, $sentToday + 1, now()->endOfDay());

        return true;
    }

    private function canManageTeam(?User $user): bool
    {
        return $user && ($user->isAdmin() || $user->hasOwnerCapability());
    }

    private function isInvitationFor(OwnerDriver $link, User $user): bool
    {
        if ($link->status !== OwnerDriver::STATUS_PENDING || $link->invited_via !== OwnerDriver::VIA_EMAIL || $link->owner_id === $user->id) {
            return false;
        }

        return $link->driver_id === $user->id
            || ($link->driver_id === null && mb_strtolower((string) $link->invited_email) === mb_strtolower($user->email));
    }

    private function presentForOwner(OwnerDriver $l): array
    {
        $active = $l->status === OwnerDriver::STATUS_ACTIVE;

        return [
            'id' => $l->id,
            'status' => $l->status,
            'invited_via' => $l->invited_via,
            // Hasta que acepta, el propietario solo ve el email que escribió.
            'driver' => $active ? $l->driver : null,
            'invited_email' => $l->invited_email,
            'invite_code' => $l->invite_code,
            'expires_at' => $l->expires_at,
            'alias' => $l->alias,
            'default_vehicle_id' => $l->default_vehicle_id,
            'is_default' => (bool) $l->is_default,
            'accepted_at' => $l->accepted_at,
            'created_at' => $l->created_at,
        ];
    }

    private function presentForDriver(OwnerDriver $l): array
    {
        return [
            'id' => $l->id,
            'owner_id' => $l->owner_id,
            'owner' => $l->owner,
            'accepted_at' => $l->accepted_at,
        ];
    }
}
