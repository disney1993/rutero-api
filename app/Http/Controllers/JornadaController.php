<?php

namespace App\Http\Controllers;

use App\Mail\JornadaAssignedMail;
use App\Models\Jornada;
use App\Models\OwnerDriver;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Jornadas del propietario: varias por día, cada una con su conductor
 * principal, su vehículo y los conductores del equipo asignados (driver_ids).
 *
 * Asignar a un conductor le envía siempre un aviso, una sola vez por jornada,
 * y queda registrado cuándo (y si lo ha visto): así el propietario no puede
 * decir que avisó si no consta. Quitarlo deja de mostrarle la jornada;
 * volver a asignarlo se la muestra otra vez sin otro aviso.
 */
class JornadaController extends Controller
{
    private const WITH = [
        'driver:id,first_name,last_name,avatar_color,avatar',
        'vehicle:id,plate,make,model,color,vehicle_type',
        'drivers:id,first_name,last_name,avatar_color,avatar',
        'notices:id',
    ];

    public function index(Request $request): JsonResponse
    {
        $owner = $request->user();
        if (! $owner->hasOwnerCapability()) {
            return response()->json([]);
        }
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        $jornadas = Jornada::where('owner_id', $owner->id)
            ->whereDate('date', $data['date'])
            ->with(self::WITH)
            ->withCount('rutas')
            ->orderBy('position')
            ->get();

        return response()->json($jornadas);
    }

    public function store(Request $request): JsonResponse
    {
        $owner = $request->user();
        if (! $owner->hasOwnerCapability()) {
            return response()->json(['message' => 'Necesitas al menos un vehículo para crear jornadas'], 403);
        }
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'label' => ['sometimes', 'nullable', 'string', 'max:40'],
            ...$this->assignmentRules(),
        ]);
        if ($error = $this->invalidAssignment($owner, $data)) {
            return $error;
        }

        $attrs = array_filter([
            'label' => $data['label'] ?? null,
            'vehicle_id' => $data['vehicle_id'] ?? null,
        ]);
        if (array_key_exists('driver_id', $data)) {
            $attrs['driver_id'] = $this->normalizeDriver($owner, $data['driver_id']);
        }

        $jornada = Jornada::createFor($owner, $data['date'], $attrs);
        if (isset($data['visibility'])) {
            $jornada->update(['visibility' => $data['visibility']]);
        }
        // El conductor principal puede venir del predeterminado del equipo: también se le avisa.
        $this->applyDrivers($owner, $jornada, $data, null, force: true);

        return response()->json($jornada->load(self::WITH)->loadCount('rutas'), 201);
    }

    public function update(Request $request, Jornada $jornada): JsonResponse
    {
        $owner = $request->user();
        if ($jornada->owner_id !== $owner->id) {
            return response()->json([], 403);
        }
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'min:1', 'max:40'],
            ...$this->assignmentRules(),
        ]);
        if ($error = $this->invalidAssignment($owner, $data)) {
            return $error;
        }
        if (array_key_exists('driver_id', $data)) {
            $data['driver_id'] = $this->normalizeDriver($owner, $data['driver_id']);
        }

        $before = $jornada->only(['driver_id', 'vehicle_id']);
        $jornada->update(collect($data)->except('driver_ids')->all());
        $jornada->propagateToPendingTrips($before);
        $this->applyDrivers($owner, $jornada, $data, $before['driver_id']);

        return response()->json($jornada->fresh(self::WITH)->loadCount('rutas'));
    }

    /** Solo se borra una jornada vacía: sus viajes no deben quedarse huérfanos. */
    public function destroy(Request $request, Jornada $jornada): JsonResponse
    {
        if ($jornada->owner_id !== $request->user()->id) {
            return response()->json([], 403);
        }
        if ($jornada->rutas()->withTrashed()->exists()) {
            return response()->json(['message' => 'La jornada tiene viajes: muévelos a otra jornada antes de eliminarla'], 422);
        }
        $jornada->delete();

        return response()->json(['message' => 'Jornada eliminada']);
    }

    // --------------------------------------------------------------- conductor

    /** Jornadas de hoy en adelante a las que está asignado, de equipos en los que sigue. */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = now(config('rutero.auto_complete.timezone'))->toDateString();

        $jornadas = Jornada::whereHas('drivers', fn ($q) => $q->where('users.id', $user->id))
            ->whereIn('owner_id', $user->activeOwnerIds())
            ->whereDate('date', '>=', $today)
            ->with(['owner:id,first_name,last_name,avatar_color,avatar', 'vehicle:id,plate,make,model,color,vehicle_type'])
            ->with(['drivers' => fn ($q) => $q->where('users.id', $user->id)])
            ->orderBy('date')->orderBy('position')
            ->get();

        return response()->json($jornadas->map(fn (Jornada $j) => [
            'id' => $j->id,
            'date' => $j->date->toDateString(),
            'label' => $j->label,
            'owner' => $j->owner,
            'vehicle' => $j->vehicle,
            'notified_at' => $j->drivers->first()?->pivot->notified_at,
            'seen_at' => $j->drivers->first()?->pivot->seen_at,
        ])->values());
    }

    /** El conductor marca el aviso como visto (queda registrado para el propietario). */
    public function seen(Request $request, Jornada $jornada): JsonResponse
    {
        $updated = DB::table('jornada_drivers')
            ->where('jornada_id', $jornada->id)->where('driver_id', $request->user()->id)
            ->whereNull('removed_at')->whereNull('seen_at')
            ->update(['seen_at' => now(), 'updated_at' => now()]);

        $assigned = $updated || $jornada->drivers()->where('users.id', $request->user()->id)->exists();

        return $assigned ? response()->json(['message' => 'Visto']) : response()->json([], 404);
    }

    // ----------------------------------------------------------------- helpers

    private function assignmentRules(): array
    {
        return [
            'driver_id' => ['sometimes', 'nullable', 'integer'],
            'vehicle_id' => ['sometimes', 'nullable', 'integer'],
            'driver_ids' => ['sometimes', 'array'],
            'driver_ids.*' => ['integer', 'distinct'],
            'visibility' => ['sometimes', Rule::in([Jornada::VISIBLE_TO_TEAM, Jornada::VISIBLE_TO_DRIVERS])],
        ];
    }

    /** El propietario como conductor equivale a "sin conductor del equipo". */
    private function normalizeDriver(User $owner, ?int $driverId): ?int
    {
        return $driverId && $driverId !== $owner->id ? $driverId : null;
    }

    /**
     * Conductores asignados = los pedidos (o, si no se piden, los que había sin
     * el principal anterior) más el principal actual. Avisa a los nuevos.
     */
    private function applyDrivers(User $owner, Jornada $jornada, array $data, ?int $previousPrincipal, bool $force = false): void
    {
        if (! $force && ! array_key_exists('driver_ids', $data) && ! array_key_exists('driver_id', $data)) {
            return;
        }
        $ids = array_key_exists('driver_ids', $data)
            ? $data['driver_ids']
            : $jornada->drivers()->pluck('users.id')->reject(fn ($id) => $id === $previousPrincipal)->all();
        if ($jornada->driver_id) {
            $ids[] = $jornada->driver_id;
        }

        $notified = $jornada->assignDrivers(array_diff($ids, [$owner->id]));
        $this->sendAssignmentEmails($owner, $jornada, $notified);
    }

    /**
     * Correo del aviso, si hay servicio de correo y sin pasar del límite diario
     * del propietario. El aviso en la app queda registrado aunque no haya correo.
     */
    private function sendAssignmentEmails(User $owner, Jornada $jornada, array $driverIds): void
    {
        if (! $driverIds || ! config('rutero.invitations.send_email')) {
            return;
        }
        $key = 'jornada-emails:'.$owner->id.':'.now()->toDateString();
        foreach (User::whereIn('id', $driverIds)->get() as $driver) {
            $sentToday = (int) Cache::get($key, 0);
            if ($sentToday >= config('rutero.invitations.max_emails_per_day')) {
                return;
            }
            try {
                Mail::to($driver->email)->send(new JornadaAssignedMail($jornada->loadMissing('owner')));
            } catch (\Throwable $e) {
                Log::warning('Jornada email failed: '.$e->getMessage());

                continue;
            }
            Cache::put($key, $sentToday + 1, now()->endOfDay());
            DB::table('jornada_drivers')->where('jornada_id', $jornada->id)->where('driver_id', $driver->id)
                ->update(['email_sent_at' => now()]);
        }
    }

    private function invalidAssignment(User $owner, array $data): ?JsonResponse
    {
        $driverId = $this->normalizeDriver($owner, $data['driver_id'] ?? null);
        if ($driverId && ! OwnerDriver::isActive($owner->id, $driverId)) {
            return response()->json(['message' => 'Ese conductor no forma parte de tu equipo'], 422);
        }
        foreach ($data['driver_ids'] ?? [] as $id) {
            if (! OwnerDriver::isActive($owner->id, $id)) {
                return response()->json(['message' => 'Alguno de los conductores no forma parte de tu equipo'], 422);
            }
        }
        if (! empty($data['vehicle_id']) && ! Vehicle::where('id', $data['vehicle_id'])->where('owner_id', $owner->id)->exists()) {
            return response()->json(['message' => 'El vehículo no pertenece a tu flota'], 422);
        }

        return null;
    }
}
