<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VehicleController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) return response()->json([], 401);

        $ownerId = $user->id;
        if ($user->isAdmin() && $request->filled('owner_id')) {
            $ownerId = $request->integer('owner_id');
        }

        $vehicles = Vehicle::where('owner_id', $ownerId)->get();
        return response()->json($vehicles);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user) return response()->json([], 401);

        $data = $request->validate([
            'plate' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-\s]+$/', 'unique:vehicles,plate'],
            'make' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'color' => 'nullable|string|max:50',
            'year' => 'nullable|integer|min:1900|max:' . (date('Y') + 1),
            'seats' => 'nullable|integer|min:1|max:9',
            'vehicle_type' => 'nullable|string|max:50',
            'is_default' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
            'owner_id' => 'nullable|integer|exists:users,id',
        ]);

        $data['owner_id'] = ($user->isAdmin() && ! empty($data['owner_id'])) ? $data['owner_id'] : $user->id;

        // El primer vehículo de un propietario siempre se marca predeterminado
        // automáticamente; a partir del segundo, solo si se pide explícitamente.
        $isFirstVehicle = ! Vehicle::where('owner_id', $data['owner_id'])->exists();
        $wantsDefault = $isFirstVehicle || ! empty($data['is_default']);
        unset($data['is_default']);

        $vehicle = DB::transaction(function () use ($data, $wantsDefault) {
            if ($wantsDefault) {
                Vehicle::where('owner_id', $data['owner_id'])->update(['is_default' => false]);
            }
            $data['is_default'] = $wantsDefault;
            return Vehicle::create($data);
        });

        return response()->json($vehicle, 201);
    }

    public function show(Request $request, Vehicle $vehicle)
    {
        $user = $request->user();
        if (! $user) return response()->json([], 401);
        if (! $user->isAdmin() && $vehicle->owner_id !== $user->id) return response()->json([], 403);
        return response()->json($vehicle);
    }

    public function update(Request $request, Vehicle $vehicle)
    {
        $user = $request->user();
        if (! $user) return response()->json([], 401);
        if (! $user->isAdmin() && $vehicle->owner_id !== $user->id) return response()->json([], 403);

        $data = $request->validate([
            'plate' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-\s]+$/', 'unique:vehicles,plate,' . $vehicle->id],
            'make' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'color' => 'nullable|string|max:50',
            'year' => 'nullable|integer|min:1900|max:' . (date('Y') + 1),
            'seats' => 'nullable|integer|min:1|max:9',
            'vehicle_type' => 'nullable|string|max:50',
            'active' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        // "is_default" solo se puede promover, nunca desmarcar directamente:
        // para dejar de ser el predeterminado hay que marcar otro vehículo
        // como tal. Se ignora un false explícito en vez de dar error, porque
        // la UI nunca envía esa combinación a propósito.
        $wantsDefault = ! empty($data['is_default']);
        unset($data['is_default']);

        DB::transaction(function () use ($vehicle, $data, $wantsDefault) {
            if ($wantsDefault && ! $vehicle->is_default) {
                Vehicle::where('owner_id', $vehicle->owner_id)->where('id', '!=', $vehicle->id)->update(['is_default' => false]);
                $data['is_default'] = true;
            }
            $vehicle->update($data);
        });

        return response()->json($vehicle->fresh());
    }

    public function destroy(Request $request, Vehicle $vehicle)
    {
        $user = $request->user();
        if (! $user) return response()->json([], 401);
        if (! $user->isAdmin() && $vehicle->owner_id !== $user->id) return response()->json([], 403);

        // prevent delete if trips exist
        if ($vehicle->rutas()->exists()) {
            return response()->json(['message' => 'Cannot delete vehicle with associated rutas'], 422);
        }

        // El vehículo predeterminado no se puede borrar directamente: hay que
        // asignar el atributo a otro primero, para no dejar la flota sin uno.
        if ($vehicle->is_default) {
            return response()->json(['message' => 'No puedes eliminar el vehículo predeterminado. Marca otro como predeterminado antes de eliminar este.'], 422);
        }

        $vehicle->delete();
        return response()->json(['message' => 'Vehicle deleted']);
    }
}
