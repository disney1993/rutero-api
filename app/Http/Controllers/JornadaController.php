<?php

namespace App\Http\Controllers;

use App\Models\Jornada;
use App\Models\OwnerDriver;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\ShareCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Jornadas del propietario: varias por día, cada una con su conductor, su
 * vehículo y su código para compartirla.
 */
class JornadaController extends Controller
{
    private const WITH = [
        'driver:id,first_name,last_name,avatar_color,avatar',
        'vehicle:id,plate,make,model,color,vehicle_type',
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
            'driver_id' => ['sometimes', 'nullable', 'integer'],
            'vehicle_id' => ['sometimes', 'nullable', 'integer'],
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
            'driver_id' => ['sometimes', 'nullable', 'integer'],
            'vehicle_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        if ($error = $this->invalidAssignment($owner, $data)) {
            return $error;
        }
        if (array_key_exists('driver_id', $data)) {
            $data['driver_id'] = $this->normalizeDriver($owner, $data['driver_id']);
        }

        $before = $jornada->only(['driver_id', 'vehicle_id']);
        $jornada->update($data);
        $jornada->propagateToPendingTrips($before);

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

    /** Nuevo código (el anterior deja de servir), p. ej. si se compartió por error. */
    public function regenerateCode(Request $request, Jornada $jornada): JsonResponse
    {
        if ($jornada->owner_id !== $request->user()->id) {
            return response()->json([], 403);
        }
        $jornada->update(['code' => ShareCode::generate()]);

        return response()->json($jornada->fresh(self::WITH)->loadCount('rutas'));
    }

    // ----------------------------------------------------------------- helpers

    /** El propietario como conductor equivale a "sin conductor del equipo". */
    private function normalizeDriver(User $owner, ?int $driverId): ?int
    {
        return $driverId && $driverId !== $owner->id ? $driverId : null;
    }

    private function invalidAssignment(User $owner, array $data): ?JsonResponse
    {
        $driverId = $this->normalizeDriver($owner, $data['driver_id'] ?? null);
        if ($driverId && ! OwnerDriver::isActive($owner->id, $driverId)) {
            return response()->json(['message' => 'Ese conductor no forma parte de tu equipo'], 422);
        }
        if (! empty($data['vehicle_id']) && ! Vehicle::where('id', $data['vehicle_id'])->where('owner_id', $owner->id)->exists()) {
            return response()->json(['message' => 'El vehículo no pertenece a tu flota'], 422);
        }

        return null;
    }
}
