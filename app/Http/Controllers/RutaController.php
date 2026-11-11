<?php

namespace App\Http\Controllers;

use App\Models\Jornada;
use App\Models\OwnerDriver;
use App\Models\OwnerMonthCode;
use App\Models\Ruta;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\RutaAutoCompleter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RutaController extends Controller
{
    /** Attribution users for list/edit meta (names + avatar tint). */
    private const ATTRIBUTION_WITH = [
        'driver:id,first_name,last_name,avatar_color,avatar',
        'completer:id,first_name,last_name,avatar_color',
        'creator:id,first_name,last_name,avatar_color',
        'canceller:id,first_name,last_name,avatar_color',
        'completions.user:id,first_name,last_name,avatar_color',
    ];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'sometimes|in:pending,completed,cancelled',
            'date' => 'sometimes|date',
            'date_from' => 'sometimes|date',
            'date_to' => 'sometimes|date|after_or_equal:date_from',
            'user_id' => 'sometimes|integer',
            'search' => 'sometimes|string|max:255',
            'needs_review' => 'sometimes|boolean',
            'with_deleted' => 'sometimes|boolean',
        ]);

        $user = $request->user();
        $query = Ruta::query()->with(self::ATTRIBUTION_WITH);

        // Los listados piden también los eliminados (se muestran atenuados y se
        // pueden restaurar); calendarios, informes, etc. no los ven.
        if ($request->boolean('with_deleted')) {
            $query->withTrashed();
        }

        // Authorization: admin sees all, everyone else sees rutas they own or drive.
        if ($user && ! $user->isAdmin()) {
            $query->where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)
                    ->orWhere(fn ($q) => $this->scopeDriverAccess($q, $user));
            });
        }

        if ($request->filled('status')) {
            // Cancelado = borrado blando: sin withTrashed no aparecería ninguno.
            if ($request->status === 'cancelled') {
                $query->withTrashed();
            }
            $query->where('status', $request->status);
        }

        // Only admin may look up another user's rutas by id.
        if ($user && $user->isAdmin() && $request->filled('user_id')) {
            $lookupId = $request->integer('user_id');
            $query->where(function ($q) use ($lookupId) {
                $q->where('owner_id', $lookupId)->orWhere('driver_id', $lookupId);
            });
        }

        // Hide rutas marked hidden from anyone who isn't the owner (or admin).
        if ($user && ! $user->isAdmin()) {
            $query->where(function ($q) use ($user) {
                $q->where('hidden', false)->orWhere('owner_id', $user->id);
            });
        }

        if ($request->filled('date')) {
            $query->whereDate('trip_date', $request->date);
        } elseif ($request->filled('date_from') && $request->filled('date_to')) {
            $query->whereBetween('trip_date', [$request->date_from, $request->date_to]);
        }

        // Búsqueda por nombre de cliente, p.ej. para encontrar una ruta
        // concreta desde "Jornada" sin navegar día a día.
        if ($request->filled('search')) {
            $query->where('client_name', 'like', '%'.$request->input('search').'%');
        }

        // Cerradas automáticamente y aún sin revisar (lista "Por revisar").
        if ($request->boolean('needs_review')) {
            $query->where('auto_completed', true)->whereNull('reviewed_at');
        }

        $rutas = $query->orderBy('trip_date', 'desc')->orderBy('trip_time', 'asc')->get();
        $this->attachAutoCompleteEta($rutas);

        return response()->json([
            'data' => $rutas,
            'count' => $rutas->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_name' => 'required|string|min:2|max:255',
            'client_phone' => ['required', 'string', 'regex:/^[0-9+\-\s()]{6,20}$/'],
            'origin' => 'required|string|min:2|max:255',
            'origin_lat' => 'nullable|numeric|between:-90,90',
            'origin_lng' => 'nullable|numeric|between:-180,180',
            'destination' => 'required|string|min:2|max:255',
            'destination_lat' => 'nullable|numeric|between:-90,90',
            'destination_lng' => 'nullable|numeric|between:-180,180',
            'vehicle_id' => 'nullable|integer|exists:vehicles,id',
            'estimated_distance_km' => 'nullable|numeric|min:0|max:100000',
            'estimated_duration_min' => 'nullable|integer|min:0|max:6000',
            'price_per_km' => 'nullable|numeric|min:0|max:100000',
            'estimated_price' => 'nullable|numeric|min:0|max:1000000',
            'final_price' => 'required|numeric|min:0|max:1000000',
            'payment_method' => 'nullable|string|max:50',
            'passenger_count' => 'nullable|integer|min:1|max:20',
            'trip_date' => 'nullable|date',
            'trip_time' => 'required|date_format:H:i',
            // Cancelar = eliminar (borrado blando, ver destroy): no es un estado editable.
            // En creación el estado siempre es pending (se fuerza abajo).
            'status' => 'nullable|in:pending,completed',
            'hidden' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
            'owner_id' => 'nullable|integer|exists:users,id',
            'driver_id' => 'nullable|integer|exists:users,id',
            'jornada_id' => 'nullable|integer',
        ]);

        if (empty($validated['estimated_price']) && ! empty($validated['estimated_distance_km']) && ! empty($validated['price_per_km'])) {
            $validated['estimated_price'] = round((float) $validated['estimated_distance_km'] * (float) $validated['price_per_km'], 2);
        }

        // Las rutas nuevas siempre nacen pendientes; el cliente no elige estado al crear.
        $validated['status'] = 'pending';
        $validated['passenger_count'] = $validated['passenger_count'] ?? 1;

        $user = $request->user();

        // owner_id/driver_id are never trusted as-is: an anonymous caller must
        // not be able to attribute a ruta to an arbitrary user's account.
        $requestedOwnerId = $validated['owner_id'] ?? null;
        $requestedDriverId = $validated['driver_id'] ?? null;
        $requestedJornadaId = $validated['jornada_id'] ?? null;
        unset($validated['owner_id'], $validated['driver_id'], $validated['jornada_id']);
        $tripDay = isset($validated['trip_date'])
            ? substr((string) $validated['trip_date'], 0, 10)
            : now(config('rutero.auto_complete.timezone'))->toDateString();

        $drivingForAnotherOwner = false;

        if ($user) {
            if ($user->isAdmin()) {
                // Admin creates on behalf of whichever owner (and optionally driver) is given.
                $validated['owner_id'] = $requestedOwnerId ?: $user->id;
                if ($requestedDriverId) {
                    $validated['driver_id'] = $requestedDriverId;
                }
            } elseif ($requestedOwnerId && $requestedOwnerId !== $user->id) {
                // Conducir para otro propietario: código de mes (mes actual) o de jornada (día del viaje).
                $tripDate = isset($validated['trip_date'])
                    ? substr((string) $validated['trip_date'], 0, 10)
                    : now()->toDateString();
                $isDriverForOwner = OwnerDriver::isActive($requestedOwnerId, $user->id)
                    || OwnerMonthCode::where('owner_id', $requestedOwnerId)
                        ->whereHas('driverEntries', fn ($q) => $q->where('driver_id', $user->id))
                        ->get()
                        ->contains(fn ($code) => $code->authorizesDate($tripDate));

                if (! $isDriverForOwner) {
                    return response()->json(['message' => 'No estás unido al código de este propietario para esta fecha'], 403);
                }

                $drivingForAnotherOwner = true;
                $validated['owner_id'] = $requestedOwnerId;
                $validated['driver_id'] = $user->id;
                // Su jornada de ese día (la indicada si es suya, o en la que conduce).
                $jornada = $this->resolveJornada($requestedOwnerId, $tripDay, $requestedJornadaId, $user->id);
                if ($jornada === false) {
                    return response()->json(['message' => 'La jornada no es de ese propietario o de ese día'], 422);
                }
                $validated['jornada_id'] = $jornada?->id;
            } else {
                // Creating a ruta for their own fleet.
                if (! $user->hasOwnerCapability()) {
                    return response()->json(['message' => 'Necesitas al menos un vehículo para crear rutas'], 403);
                }
                $validated['owner_id'] = $user->id;
                // Jornada del viaje: la indicada o la primera del día. Si no se
                // indica conductor o vehículo, se heredan de la jornada; sin
                // jornada, se usa el conductor predeterminado del equipo.
                $jornada = $this->resolveJornada($user->id, $tripDay, $requestedJornadaId);
                if ($jornada === false) {
                    return response()->json(['message' => 'La jornada no es tuya o no es de ese día'], 422);
                }
                $validated['jornada_id'] = $jornada?->id;
                if (! $request->has('driver_id')) {
                    $requestedDriverId = $jornada ? $jornada->driver_id : OwnerDriver::defaultDriverId($user->id);
                }
                if ($jornada && empty($validated['vehicle_id'])) {
                    $validated['vehicle_id'] = $jornada->vehicle_id;
                }
                // Asignar el viaje a un conductor de su equipo (null = lo hace él).
                if ($requestedDriverId && $requestedDriverId !== $user->id) {
                    if (! OwnerDriver::isActive($user->id, $requestedDriverId)) {
                        return response()->json(['message' => 'Ese conductor no forma parte de tu equipo'], 422);
                    }
                    $validated['driver_id'] = $requestedDriverId;
                }
            }
        }

        // El vehículo es obligatorio, salvo cuando quien crea la ruta conduce
        // para un propietario ajeno (código de mes): no tiene visibilidad de
        // esa flota, así que no se le puede exigir elegir uno.
        if (empty($validated['vehicle_id']) && ! $drivingForAnotherOwner) {
            return response()->json(['message' => 'Selecciona un vehículo'], 422);
        }

        // If vehicle_id provided, ensure it belongs to the resolved owner's fleet.
        if (! empty($validated['vehicle_id'])) {
            $vehicle = Vehicle::find($validated['vehicle_id']);
            if (! $vehicle) {
                return response()->json(['message' => 'Vehicle not found'], 422);
            }
            if (isset($validated['owner_id']) && $vehicle->owner_id !== $validated['owner_id']) {
                return response()->json(['message' => 'Vehicle does not belong to this owner'], 403);
            }
        }

        // Enforce the free-plan ruta limit on the resolved owner (admins bypass it).
        if (isset($validated['owner_id']) && (! $user || ! $user->isAdmin())) {
            $ownerUser = User::find($validated['owner_id']);
            if ($ownerUser && ! $ownerUser->isPremium()) {
                $limit = config('rutero.free_owner_ruta_limit');
                $rutaCount = Ruta::where('owner_id', $ownerUser->id)->count();
                if ($rutaCount >= $limit) {
                    return response()->json([
                        'message' => "Has alcanzado el límite de {$limit} rutas del plan gratuito. Actualiza a premium para rutas ilimitadas.",
                    ], 402);
                }
            }
        }

        // default hidden false
        $validated['hidden'] = $validated['hidden'] ?? false;
        $validated['created_by'] = $user?->id;

        $ruta = Ruta::create($validated);
        $ruta->load(self::ATTRIBUTION_WITH);
        $this->attachAutoCompleteEta(collect([$ruta]));

        return response()->json($ruta, 201);
    }

    public function show(Request $request, Ruta $ruta): JsonResponse
    {
        $user = $request->user();
        if ($user && ! $user->isAdmin() && $ruta->owner_id !== $user->id && ! $this->driverCanAccess($ruta, $user)) {
            return response()->json([], 403);
        }

        $ruta->load(self::ATTRIBUTION_WITH);
        $this->attachAutoCompleteEta(collect([$ruta]));

        return response()->json($ruta);
    }

    public function update(Request $request, Ruta $ruta): JsonResponse
    {
        // Owner/full form: if phone/time/final_price are sent they must be present
        // and valid. Drivers keep a limited field set (below) so partial updates
        // (status/payment only) are not forced to send those keys.
        $validated = $request->validate([
            'client_name' => 'sometimes|string|min:2|max:255',
            'client_phone' => ['sometimes', 'required', 'string', 'regex:/^[0-9+\-\s()]{6,20}$/'],
            'origin' => 'sometimes|string|min:2|max:255',
            'origin_lat' => 'sometimes|nullable|numeric|between:-90,90',
            'origin_lng' => 'sometimes|nullable|numeric|between:-180,180',
            'destination' => 'sometimes|string|min:2|max:255',
            'destination_lat' => 'sometimes|nullable|numeric|between:-90,90',
            'destination_lng' => 'sometimes|nullable|numeric|between:-180,180',
            'estimated_distance_km' => 'sometimes|nullable|numeric|min:0|max:100000',
            'estimated_duration_min' => 'sometimes|nullable|integer|min:0|max:6000',
            'price_per_km' => 'sometimes|nullable|numeric|min:0|max:100000',
            'estimated_price' => 'sometimes|nullable|numeric|min:0|max:1000000',
            'final_price' => 'sometimes|required|numeric|min:0|max:1000000',
            'payment_method' => 'sometimes|nullable|string|max:50',
            'passenger_count' => 'sometimes|nullable|integer|min:1|max:20',
            'trip_date' => 'sometimes|nullable|date',
            'trip_time' => 'sometimes|required|date_format:H:i',
            'status' => 'sometimes|in:pending,completed',
            'notes' => 'sometimes|nullable|string|max:2000',
            'driver_id' => 'sometimes|nullable|integer',
            'jornada_id' => 'sometimes|nullable|integer',
        ]);

        if (empty($validated['estimated_price']) && ! empty($ruta->estimated_distance_km) && ! empty($ruta->price_per_km)) {
            $validated['estimated_price'] = round((float) $ruta->estimated_distance_km * (float) $ruta->price_per_km, 2);
        }

        $user = $request->user();

        $isOwner = $user && ($user->isAdmin() || $ruta->owner_id === $user->id);
        if (! $isOwner && (! $user || ! $this->driverCanAccess($ruta, $user))) {
            return response()->json([], 403);
        }

        // Mover de jornada (solo el propietario): a otra del mismo día, o a la
        // primera del nuevo día si cambia la fecha sin indicar jornada.
        if ($isOwner && (array_key_exists('jornada_id', $validated) || array_key_exists('trip_date', $validated))) {
            $day = substr((string) ($validated['trip_date'] ?? $ruta->trip_date?->toDateString() ?? ''), 0, 10);
            $requested = array_key_exists('jornada_id', $validated)
                ? $validated['jornada_id']
                : ($ruta->jornada_id && $ruta->jornada?->date->toDateString() === $day ? $ruta->jornada_id : null);
            $jornada = $day ? $this->resolveJornada($ruta->owner_id, $day, $requested) : null;
            if ($jornada === false) {
                return response()->json(['message' => 'La jornada no es tuya o no es de ese día'], 422);
            }
            $validated['jornada_id'] = $jornada?->id;
            // Un viaje pendiente que cambia de jornada pasa al conductor de la
            // nueva, salvo que la petición indique otro.
            if ($jornada && $jornada->id !== $ruta->jornada_id && ! array_key_exists('driver_id', $validated)
                && ($validated['status'] ?? $ruta->status) === 'pending') {
                $validated['driver_id'] = $jornada->driver_id;
            }
        } else {
            unset($validated['jornada_id']);
        }

        // Reasignar conductor: solo el propietario y solo a alguien de su equipo.
        if ($isOwner && array_key_exists('driver_id', $validated)) {
            $newDriver = $validated['driver_id'] ?: null;
            if ($newDriver === $ruta->owner_id) {
                $newDriver = null;
            }
            if ($newDriver && $newDriver !== $ruta->driver_id && ! $user->isAdmin()
                && ! OwnerDriver::isActive($ruta->owner_id, $newDriver)) {
                return response()->json(['message' => 'Ese conductor no forma parte de tu equipo'], 422);
            }
            $validated['driver_id'] = $newDriver;
        }

        // Whoever isn't the owner (typically the assigned driver) can only update a limited set of fields.
        if (! $isOwner) {
            $allowed = ['final_price', 'payment_method', 'status', 'notes'];
            $validated = array_intersect_key($validated, array_flip($allowed));
            if (isset($validated['status']) && ! in_array($validated['status'], ['completed', 'pending'])) {
                unset($validated['status']);
            }
        }

        // Completar (incl. swipe): cada conductor que marca "completed" se
        // registra en ruta_completions sin borrar las marcas de los demás.
        // completed_by queda como el último que lo marcó (compatibilidad).
        $markingCompleted = $user && ($validated['status'] ?? null) === 'completed';
        // Un conductor del equipo que completa un viaje sin asignar pasa a ser
        // su conductor. Si lo marca el propietario, el conductor no se toca.
        if ($markingCompleted && ! $isOwner && $ruta->driver_id === null) {
            $validated['driver_id'] = $user->id;
        }
        if ($markingCompleted) {
            $validated['completed_by'] = $user->id;
            // Same default as auto-complete / right-swipe: cash when completing with no payment set.
            $nextPayment = array_key_exists('payment_method', $validated)
                ? $validated['payment_method']
                : $ruta->payment_method;
            if (empty($nextPayment)) {
                $validated['payment_method'] = 'efectivo';
            }
        }

        // Cualquier edición de una ruta cerrada automáticamente cuenta como
        // revisión. Volver a "pendiente" deshace el cierre automático; se
        // conserva auto_completed_at para que el cron no la vuelva a cerrar.
        if ($ruta->auto_completed) {
            $validated['reviewed_at'] = now();
            if (($validated['status'] ?? null) === 'pending') {
                $validated['auto_completed'] = false;
            }
        }

        // Si cambia la fecha u hora de una ruta pendiente, el cierre
        // automático se vuelve a programar para el nuevo horario.
        $dateChanged = array_key_exists('trip_date', $validated)
            && $validated['trip_date'] !== $ruta->trip_date?->format('Y-m-d');
        $timeChanged = array_key_exists('trip_time', $validated) && $validated['trip_time'] !== $ruta->trip_time;
        if (($dateChanged || $timeChanged) && ($validated['status'] ?? $ruta->status) === 'pending') {
            $validated['auto_completed'] = false;
            $validated['auto_completed_at'] = null;
        }

        $ruta->update($validated);

        if ($markingCompleted) {
            $ruta->recordCompletion($user->id);
        }

        $ruta->load(self::ATTRIBUTION_WITH);
        $this->attachAutoCompleteEta(collect([$ruta]));

        return response()->json($ruta);
    }

    // "Correcto": marca como revisado un cierre automático sin cambiar nada.
    // Un viaje sin conductor se asigna a quien confirma (solo en confirmación
    // individual). La app no incluye viajes sin asignar en "Todo correcto"
    // de un conductor (AutoCompleteReviewModal), para no apropiarse de los
    // de un compañero de jornada.
    public function review(Request $request, Ruta $ruta): JsonResponse
    {
        $user = $request->user();
        $allowed = $user->isAdmin() || $ruta->owner_id === $user->id || $this->driverCanAccess($ruta, $user);
        if (! $allowed) {
            return response()->json([], 403);
        }

        $changes = [];
        if ($ruta->auto_completed && ! $ruta->reviewed_at) {
            $changes['reviewed_at'] = now();
        }
        // Confirmar ("Correcto") un viaje sin asignar lo deja a nombre del
        // conductor que lo confirma; si confirma el propietario, no se toca.
        $isOwner = $user->isAdmin() || $ruta->owner_id === $user->id;
        if (! $isOwner && $ruta->driver_id === null) {
            $changes['driver_id'] = $user->id;
        }
        if ($changes) {
            $ruta->update($changes);
            if (isset($changes['driver_id']) && $ruta->status === 'completed') {
                $ruta->recordCompletion($user->id);
            }
        }

        return response()->json($ruta);
    }

    /**
     * Añade auto_complete_eta: hora local ("Y-m-d\TH:i") a partir de la cual el
     * cron cerrará la ruta si el siguiente viaje no lo adelanta. La app la usa
     * para avisar al usuario de que el viaje se completará solo.
     */
    /**
     * Viajes que ve un conductor en cuentas ajenas:
     *  a) los que tiene asignados (driver_id) si los creó él (código de
     *     jornada), si tiene vínculo activo con el propietario o si son de
     *     días anteriores (al dejar el equipo conserva su historial);
     *  b) los SIN asignar de los propietarios de su equipo, desde el día en
     *     que se unió: son la jornada compartida. Al completarlos o
     *     confirmarlos pasan a su nombre (ver update y review).
     */
    private function scopeDriverAccess($q, User $user)
    {
        $today = now(config('rutero.auto_complete.timezone'))->toDateString();

        return $q->where(function ($q) use ($user, $today) {
            $q->where(function ($q) use ($user, $today) {
                $q->where('driver_id', $user->id)
                    ->where(function ($q) use ($user, $today) {
                        $q->where('created_by', $user->id)
                            ->orWhereIn('owner_id', $user->activeOwnerIds())
                            ->orWhereDate('trip_date', '<', $today);
                    });
            })->orWhere(function ($q) use ($user) {
                $q->whereNull('driver_id')
                    ->whereExists(function ($sub) use ($user) {
                        $sub->selectRaw('1')
                            ->from('owner_drivers')
                            ->whereColumn('owner_drivers.owner_id', 'rutas.owner_id')
                            ->where('owner_drivers.driver_id', $user->id)
                            ->where('owner_drivers.status', OwnerDriver::STATUS_ACTIVE)
                            ->whereRaw('DATE(owner_drivers.accepted_at) <= rutas.trip_date');
                    });
            });
        });
    }

    private function driverCanAccess(Ruta $ruta, User $user): bool
    {
        if ($ruta->driver_id === null) {
            return $this->isUnassignedForTeamDriver($ruta, $user);
        }
        if ($ruta->driver_id !== $user->id) {
            return false;
        }
        $today = now(config('rutero.auto_complete.timezone'))->toDateString();

        return $ruta->created_by === $user->id
            || OwnerDriver::isActive($ruta->owner_id, $user->id)
            || ($ruta->trip_date && $ruta->trip_date->toDateString() < $today);
    }

    /**
     * Jornada de un viaje: la pedida, si es del propietario y del mismo día
     * (false si no lo es); si no se pide, la del conductor $driverId ese día o
     * la primera del día; null si el día no tiene jornadas.
     */
    private function resolveJornada(int $ownerId, string $day, ?int $requestedId, ?int $driverId = null): Jornada|false|null
    {
        if ($requestedId) {
            $jornada = Jornada::where('id', $requestedId)->where('owner_id', $ownerId)->first();

            return $jornada && $jornada->date->toDateString() === $day ? $jornada : false;
        }

        $query = Jornada::where('owner_id', $ownerId)->whereDate('date', $day)->orderBy('position');
        if ($driverId) {
            return (clone $query)->where('driver_id', $driverId)->first() ?? $query->first();
        }

        return $query->first();
    }

    /** Viaje sin asignar de un propietario de su equipo, desde que se unió. */
    private function isUnassignedForTeamDriver(Ruta $ruta, User $user): bool
    {
        if ($ruta->driver_id !== null || ! $ruta->trip_date) {
            return false;
        }
        $link = OwnerDriver::active()->where('owner_id', $ruta->owner_id)->where('driver_id', $user->id)->first();

        return $link && $link->accepted_at && $link->accepted_at->toDateString() <= $ruta->trip_date->toDateString();
    }

    private function attachAutoCompleteEta($rutas): void
    {
        $completer = app(RutaAutoCompleter::class);
        $owners = User::whereIn('id', $rutas->pluck('owner_id')->filter()->unique())->get()->keyBy('id');

        foreach ($rutas as $ruta) {
            $ruta->setAttribute('auto_complete_eta', $completer->etaFor($ruta, $owners->get($ruta->owner_id)));
        }
    }

    public function destroy(Request $request, Ruta $ruta): JsonResponse
    {
        $user = $request->user();
        $isOwner = $user && ($user->isAdmin() || $ruta->owner_id === $user->id);
        $isCreatorDriver = $user && $ruta->driver_id === $user->id && $ruta->created_by === $user->id;

        if (! $isOwner && ! $isCreatorDriver) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Cancelar y eliminar son lo mismo: queda como "cancelled" y con borrado
        // blando (sigue en los listados, tachado, y se puede deshacer con restore()).
        $ruta->status = 'cancelled';
        $ruta->cancelled_by = $user->id;
        $ruta->save();
        $ruta->delete();

        return response()->json(['message' => 'Ruta eliminada correctamente']);
    }

    // Recupera un viaje eliminado (mismos permisos que para eliminarlo).
    public function restore(Request $request, Ruta $ruta): JsonResponse
    {
        $user = $request->user();
        $isOwner = $user && ($user->isAdmin() || $ruta->owner_id === $user->id);
        $isCreatorDriver = $user && $ruta->driver_id === $user->id && $ruta->created_by === $user->id;

        if (! $isOwner && ! $isCreatorDriver) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($ruta->trashed()) {
            $ruta->restore();
            $ruta->status = 'pending';
            $ruta->cancelled_by = null;
            $ruta->save();
        }

        return response()->json($ruta->fresh());
    }
}
