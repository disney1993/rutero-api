<?php

namespace App\Http\Controllers;

use App\Models\Ruta;
use App\Models\User;
use App\Services\RutaAutoCompleter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RutaController extends Controller
{
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
        $query = Ruta::query()->with('completer:id,first_name,last_name');

        // Los listados piden también los eliminados (se muestran atenuados y se
        // pueden restaurar); calendarios, informes, etc. no los ven.
        if ($request->boolean('with_deleted')) {
            $query->withTrashed();
        }

        // Authorization: admin sees all, everyone else sees rutas they own or drive.
        if ($user && ! $user->isAdmin()) {
            $query->where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)->orWhere('driver_id', $user->id);
            });
        }

        if ($request->filled('status')) {
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
            $query->where('client_name', 'like', '%' . $request->input('search') . '%');
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
            'client_phone' => ['nullable', 'string', 'regex:/^[0-9+\-\s()]{6,20}$/'],
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
            'final_price' => 'nullable|numeric|min:0|max:1000000',
            'payment_method' => 'nullable|string|max:50',
            'passenger_count' => 'nullable|integer|min:1|max:20',
            'trip_date' => 'nullable|date',
            'trip_time' => 'nullable|date_format:H:i',
            // Cancelar = eliminar (borrado blando, ver destroy): no es un estado editable.
            'status' => 'nullable|in:pending,completed',
            'hidden' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
            'owner_id' => 'nullable|integer|exists:users,id',
            'driver_id' => 'nullable|integer|exists:users,id',
        ]);

        if (empty($validated['estimated_price']) && !empty($validated['estimated_distance_km']) && !empty($validated['price_per_km'])) {
            $validated['estimated_price'] = round((float) $validated['estimated_distance_km'] * (float) $validated['price_per_km'], 2);
        }

        $validated['status'] ??= 'pending';

        $user = $request->user();

        // owner_id/driver_id are never trusted as-is: an anonymous caller must
        // not be able to attribute a ruta to an arbitrary user's account.
        $requestedOwnerId = $validated['owner_id'] ?? null;
        $requestedDriverId = $validated['driver_id'] ?? null;
        unset($validated['owner_id'], $validated['driver_id']);

        $drivingForAnotherOwner = false;

        if ($user) {
            if ($user->isAdmin()) {
                // Admin creates on behalf of whichever owner (and optionally driver) is given.
                $validated['owner_id'] = $requestedOwnerId ?: $user->id;
                if ($requestedDriverId) {
                    $validated['driver_id'] = $requestedDriverId;
                }
            } elseif ($requestedOwnerId && $requestedOwnerId !== $user->id) {
                // Driving for another owner: must have joined their code this month.
                $month = date('Y-m');
                $isDriverForOwner = \App\Models\OwnerMonthCode::where('owner_id', $requestedOwnerId)
                    ->where('month', $month)
                    ->whereHas('driverEntries', fn ($q) => $q->where('driver_id', $user->id))
                    ->exists();

                if (! $isDriverForOwner) {
                    return response()->json(['message' => 'No estás unido al código de este propietario este mes'], 403);
                }

                $drivingForAnotherOwner = true;
                $validated['owner_id'] = $requestedOwnerId;
                $validated['driver_id'] = $user->id;
            } else {
                // Creating a ruta for their own fleet.
                if (! $user->hasOwnerCapability()) {
                    return response()->json(['message' => 'Necesitas al menos un vehículo para crear rutas'], 403);
                }
                $validated['owner_id'] = $user->id;
            }
        }

        // El vehículo es obligatorio, salvo cuando quien crea la ruta conduce
        // para un propietario ajeno (código de mes): no tiene visibilidad de
        // esa flota, así que no se le puede exigir elegir uno.
        if (empty($validated['vehicle_id']) && ! $drivingForAnotherOwner) {
            return response()->json(['message' => 'Selecciona un vehículo'], 422);
        }

        // If vehicle_id provided, ensure it belongs to the resolved owner's fleet.
        if (!empty($validated['vehicle_id'])) {
            $vehicle = \App\Models\Vehicle::find($validated['vehicle_id']);
            if (! $vehicle) {
                return response()->json(['message' => 'Vehicle not found'], 422);
            }
            if (isset($validated['owner_id']) && $vehicle->owner_id !== $validated['owner_id']) {
                return response()->json(['message' => 'Vehicle does not belong to this owner'], 403);
            }
        }

        // Enforce the free-plan ruta limit on the resolved owner (admins bypass it).
        if (isset($validated['owner_id']) && (! $user || ! $user->isAdmin())) {
            $ownerUser = \App\Models\User::find($validated['owner_id']);
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
        $this->attachAutoCompleteEta(collect([$ruta]));

        return response()->json($ruta, 201);
    }

    public function show(Request $request, Ruta $ruta): JsonResponse
    {
        $user = $request->user();
        if ($user && ! $user->isAdmin() && $ruta->owner_id !== $user->id && $ruta->driver_id !== $user->id) {
            return response()->json([], 403);
        }

        $this->attachAutoCompleteEta(collect([$ruta]));

        return response()->json($ruta);
    }

    public function update(Request $request, Ruta $ruta): JsonResponse
    {
        $validated = $request->validate([
            'client_name' => 'sometimes|string|min:2|max:255',
            'client_phone' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9+\-\s()]{6,20}$/'],
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
            'final_price' => 'sometimes|nullable|numeric|min:0|max:1000000',
            'payment_method' => 'sometimes|nullable|string|max:50',
            'passenger_count' => 'sometimes|nullable|integer|min:1|max:20',
            'trip_date' => 'sometimes|nullable|date',
            'trip_time' => 'sometimes|nullable|date_format:H:i',
            'status' => 'sometimes|in:pending,completed',
            'notes' => 'sometimes|nullable|string|max:2000',
        ]);

        if (empty($validated['estimated_price']) && !empty($ruta->estimated_distance_km) && !empty($ruta->price_per_km)) {
            $validated['estimated_price'] = round((float) $ruta->estimated_distance_km * (float) $ruta->price_per_km, 2);
        }

        $user = $request->user();

        $isOwner = $user && ($user->isAdmin() || $ruta->owner_id === $user->id);
        if (! $isOwner && (! $user || $ruta->driver_id !== $user->id)) {
            return response()->json([], 403);
        }

        // Whoever isn't the owner (typically the assigned driver) can only update a limited set of fields.
        if (! $isOwner) {
            $allowed = ['final_price', 'payment_method', 'status', 'notes'];
            $validated = array_intersect_key($validated, array_flip($allowed));
            if (isset($validated['status']) && !in_array($validated['status'], ['completed', 'pending'])) {
                unset($validated['status']);
            }
        }

        // Se registra quién completó la ruta solo en la transición hacia
        // "completed", no en cada edición posterior mientras siga así. Va
        // después del filtrado de campos por rol para que sobreviva incluso
        // cuando quien actualiza es el conductor (el caso más común).
        if ($user && ($validated['status'] ?? null) === 'completed' && $ruta->status !== 'completed') {
            $validated['completed_by'] = $user->id;
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
        $this->attachAutoCompleteEta(collect([$ruta]));

        return response()->json($ruta);
    }

    // "Correcto": marca como revisado un cierre automático sin cambiar nada.
    public function review(Request $request, Ruta $ruta): JsonResponse
    {
        $user = $request->user();
        $allowed = $user->isAdmin() || $ruta->owner_id === $user->id || $ruta->driver_id === $user->id;
        if (! $allowed) {
            return response()->json([], 403);
        }

        if ($ruta->auto_completed && ! $ruta->reviewed_at) {
            $ruta->update(['reviewed_at' => now()]);
        }

        return response()->json($ruta);
    }

    /**
     * Añade auto_complete_eta: hora local ("Y-m-d\TH:i") a partir de la cual el
     * cron cerrará la ruta si el siguiente viaje no lo adelanta. La app la usa
     * para avisar al usuario de que el viaje se completará solo.
     */
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
            $ruta->save();
        }

        return response()->json($ruta->fresh());
    }
}
