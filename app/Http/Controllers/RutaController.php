<?php

namespace App\Http\Controllers;

use App\Models\Jornada;
use App\Models\Ruta;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'jornada_id' => 'sometimes|integer|exists:jornadas,id',
        ]);

        $user = $request->user();
        $query = Ruta::query();

        // Authorization: admin sees all, everyone else sees rutas they own or drive.
        if (! $user->isAdmin()) {
            $query->where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)->orWhere('driver_id', $user->id);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('jornada_id')) {
            $query->where('jornada_id', $request->integer('jornada_id'));
        }

        // Only admin may look up another user's rutas by id.
        if ($user->isAdmin() && $request->filled('user_id')) {
            $lookupId = $request->integer('user_id');
            $query->where(function ($q) use ($lookupId) {
                $q->where('owner_id', $lookupId)->orWhere('driver_id', $lookupId);
            });
        }

        // Hide rutas marked hidden from anyone who isn't the owner (or admin).
        if (! $user->isAdmin()) {
            $query->where(function ($q) use ($user) {
                $q->where('hidden', false)->orWhere('owner_id', $user->id);
            });
        }

        if ($request->filled('date')) {
            $query->whereDate('trip_date', $request->date);
        } elseif ($request->filled('date_from') && $request->filled('date_to')) {
            $query->whereBetween('trip_date', [$request->date_from, $request->date_to]);
        }

        $rutas = $query->orderBy('trip_date', 'desc')->orderBy('trip_time', 'asc')->get();

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
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
            'jornada_id' => ['nullable', 'integer', Rule::exists('jornadas', 'id')->whereNull('deleted_at')],
            'estimated_distance_km' => 'nullable|numeric|min:0|max:100000',
            'estimated_duration_min' => 'nullable|integer|min:1|max:10080',
            'price_per_km' => 'nullable|numeric|min:0|max:100000',
            'estimated_price' => 'nullable|numeric|min:0|max:1000000',
            'final_price' => 'required|numeric|min:0|max:1000000',
            'payment_method' => 'nullable|string|in:efectivo,tarjeta,bizum',
            'passenger_count' => 'nullable|integer|min:1|max:20',
            'trip_date' => 'required|date',
            'trip_time' => 'required|date_format:H:i',
            'status' => 'nullable|in:pending,completed,cancelled',
            'hidden' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
            'owner_id' => 'nullable|integer|exists:users,id',
            'driver_id' => 'nullable|integer|exists:users,id',
        ]);

        if (empty($validated['estimated_price']) && ! empty($validated['estimated_distance_km']) && ! empty($validated['price_per_km'])) {
            $validated['estimated_price'] = round((float) $validated['estimated_distance_km'] * (float) $validated['price_per_km'], 2);
        }

        $validated['estimated_duration_min'] = $this->resolveDurationMin(
            $validated['estimated_duration_min'] ?? null,
            $validated['estimated_distance_km'] ?? null,
        );

        $validated['status'] ??= 'pending';

        $user = $request->user();
        $requestedOwnerId = $validated['owner_id'] ?? null;
        $requestedDriverId = $validated['driver_id'] ?? null;
        $jornadaId = $validated['jornada_id'] ?? null;
        unset($validated['owner_id'], $validated['driver_id']);

        $jornada = null;

        if ($user->isAdmin()) {
            $validated['owner_id'] = $requestedOwnerId ?: $user->id;
            if ($requestedDriverId) {
                $validated['driver_id'] = $requestedDriverId;
            }
            if ($jornadaId) {
                $jornada = Jornada::find($jornadaId);
                $validated['jornada_id'] = $jornada?->id;
                if ($jornada && empty($validated['trip_date'])) {
                    $validated['trip_date'] = $jornada->date->toDateString();
                }
            }
        } elseif ($jornadaId || ($requestedOwnerId && $requestedOwnerId !== $user->id)) {
            $jornada = $this->resolveDriverJornada($user->id, $jornadaId, $requestedOwnerId, $validated['trip_date'] ?? null);
            if (! $jornada) {
                return response()->json(['message' => 'Debes unirte a una jornada válida para este día'], 403);
            }
            if (! $jornada->allow_create) {
                return response()->json(['message' => 'Esta jornada no permite crear viajes'], 403);
            }

            $validated['owner_id'] = $jornada->owner_id;
            $validated['driver_id'] = $user->id;
            $validated['jornada_id'] = $jornada->id;
            $validated['trip_date'] = $jornada->date->toDateString();
        } else {
            if (! $user->hasOwnerCapability()) {
                return response()->json(['message' => 'Necesitas al menos un vehículo para crear rutas'], 403);
            }
            $validated['owner_id'] = $user->id;

            if ($jornadaId) {
                $jornada = Jornada::where('id', $jornadaId)->where('owner_id', $user->id)->first();
                if (! $jornada) {
                    return response()->json(['message' => 'Jornada no encontrada'], 422);
                }
                $validated['jornada_id'] = $jornada->id;
                $validated['trip_date'] = $validated['trip_date'] ?? $jornada->date->toDateString();
            }
        }

        // Default vehicle: owner's default when none provided.
        if (empty($validated['vehicle_id'])) {
            $defaultVehicle = Vehicle::where('owner_id', $validated['owner_id'])
                ->where('is_default', true)
                ->first()
                ?? Vehicle::where('owner_id', $validated['owner_id'])->orderBy('id')->first();
            if ($defaultVehicle) {
                $validated['vehicle_id'] = $defaultVehicle->id;
            }
        } else {
            $vehicle = Vehicle::find($validated['vehicle_id']);
            if (! $vehicle) {
                return response()->json(['message' => 'Vehicle not found'], 422);
            }
            if ($vehicle->owner_id !== $validated['owner_id']) {
                return response()->json(['message' => 'Vehicle does not belong to this owner'], 403);
            }
        }

        if (! $user->isAdmin()) {
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

        $validated['hidden'] = $validated['hidden'] ?? false;
        $validated['created_by'] = $user->id;

        $ruta = Ruta::create($validated);

        return response()->json($ruta, 201);
    }

    public function show(Request $request, Ruta $ruta): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && $ruta->owner_id !== $user->id && $ruta->driver_id !== $user->id) {
            return response()->json([], 403);
        }

        return response()->json($ruta);
    }

    public function update(Request $request, Ruta $ruta): JsonResponse
    {
        $validated = $request->validate([
            'client_name' => 'sometimes|required|string|min:2|max:255',
            'client_phone' => ['sometimes', 'required', 'string', 'regex:/^[0-9+\-\s()]{6,20}$/'],
            'origin' => 'sometimes|required|string|min:2|max:255',
            'origin_lat' => 'sometimes|nullable|numeric|between:-90,90',
            'origin_lng' => 'sometimes|nullable|numeric|between:-180,180',
            'destination' => 'sometimes|required|string|min:2|max:255',
            'destination_lat' => 'sometimes|nullable|numeric|between:-90,90',
            'destination_lng' => 'sometimes|nullable|numeric|between:-180,180',
            'vehicle_id' => ['sometimes', 'nullable', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
            'estimated_distance_km' => 'sometimes|nullable|numeric|min:0|max:100000',
            'estimated_duration_min' => 'sometimes|nullable|integer|min:1|max:10080',
            'price_per_km' => 'sometimes|nullable|numeric|min:0|max:100000',
            'estimated_price' => 'sometimes|nullable|numeric|min:0|max:1000000',
            'final_price' => 'sometimes|required|numeric|min:0|max:1000000',
            'payment_method' => 'sometimes|nullable|string|in:efectivo,tarjeta,bizum',
            'passenger_count' => 'sometimes|nullable|integer|min:1|max:20',
            'trip_date' => 'sometimes|required|date',
            'trip_time' => 'sometimes|required|date_format:H:i',
            'status' => 'sometimes|in:pending,completed,cancelled',
            'notes' => 'sometimes|nullable|string|max:2000',
        ]);

        if (empty($validated['estimated_price']) && ! empty($ruta->estimated_distance_km) && ! empty($ruta->price_per_km)) {
            $validated['estimated_price'] = round((float) $ruta->estimated_distance_km * (float) $ruta->price_per_km, 2);
        }

        if (array_key_exists('estimated_duration_min', $validated) || array_key_exists('estimated_distance_km', $validated)) {
            $validated['estimated_duration_min'] = $this->resolveDurationMin(
                $validated['estimated_duration_min'] ?? $ruta->estimated_duration_min,
                $validated['estimated_distance_km'] ?? $ruta->estimated_distance_km,
            );
        }

        $user = $request->user();
        $isOwner = $user->isAdmin() || $ruta->owner_id === $user->id;
        $isDriver = $ruta->driver_id === $user->id;

        if (! $isOwner && ! $isDriver) {
            return response()->json([], 403);
        }

        if (! $isOwner) {
            $jornada = $ruta->jornada_id ? Jornada::find($ruta->jornada_id) : null;
            if ($jornada && ! $jornada->allow_edit) {
                return response()->json(['message' => 'Esta jornada no permite editar viajes'], 403);
            }

            $allowed = ['final_price', 'payment_method', 'status', 'notes', 'vehicle_id'];
            $validated = array_intersect_key($validated, array_flip($allowed));
        }

        if (isset($validated['vehicle_id'])) {
            $vehicle = Vehicle::find($validated['vehicle_id']);
            if (! $vehicle || $vehicle->owner_id !== $ruta->owner_id) {
                return response()->json(['message' => 'Vehicle does not belong to this owner'], 403);
            }
        }

        $ruta->update($validated);

        return response()->json($ruta);
    }

    public function destroy(Request $request, Ruta $ruta): JsonResponse
    {
        $user = $request->user();
        $isOwner = $user->isAdmin() || $ruta->owner_id === $user->id;
        $isDriver = $ruta->driver_id === $user->id;

        if ($isOwner) {
            $ruta->delete();

            return response()->json(['message' => 'Ruta eliminada correctamente']);
        }

        if ($isDriver) {
            $jornada = $ruta->jornada_id ? Jornada::find($ruta->jornada_id) : null;
            if (! $jornada || ! $jornada->allow_delete) {
                return response()->json(['message' => 'Esta jornada no permite eliminar viajes'], 403);
            }
            if ($ruta->created_by !== $user->id) {
                return response()->json(['message' => 'Solo puedes eliminar viajes que creaste'], 403);
            }

            $ruta->delete();

            return response()->json(['message' => 'Ruta eliminada correctamente']);
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }

    private function resolveDriverJornada(int $driverId, ?int $jornadaId, ?int $ownerId, ?string $tripDate): ?Jornada
    {
        if ($jornadaId) {
            $jornada = Jornada::find($jornadaId);
            if (! $jornada || ! $jornada->hasDriver($driverId)) {
                return null;
            }
            if ($ownerId && $jornada->owner_id !== $ownerId) {
                return null;
            }

            return $jornada;
        }

        if (! $ownerId) {
            return null;
        }

        $query = Jornada::where('owner_id', $ownerId)
            ->whereHas('driverEntries', fn ($q) => $q->where('driver_id', $driverId));

        if ($tripDate) {
            $query->whereDate('date', $tripDate);
        } else {
            $query->whereDate('date', now()->toDateString());
        }

        $matches = $query->orderBy('label')->get();
        if ($matches->count() === 1) {
            return $matches->first();
        }

        // Ambiguous (0 or 2+) — client must send jornada_id.
        return null;
    }

    /** Always at least 15 minutes; prefers provided duration, else distance @ ~40 km/h. */
    private function resolveDurationMin(mixed $durationMin, mixed $distanceKm): int
    {
        $minFloor = 15;
        if ($durationMin !== null && $durationMin !== '' && (int) $durationMin > 0) {
            return max($minFloor, (int) $durationMin);
        }
        if ($distanceKm !== null && $distanceKm !== '' && (float) $distanceKm > 0) {
            return max($minFloor, (int) round(((float) $distanceKm / 40) * 60));
        }

        return $minFloor;
    }
}
