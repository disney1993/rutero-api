<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VehicleController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([], 401);
        }

        $ownerId = $user->id;
        if ($user->isAdmin() && $request->filled('owner_id')) {
            $ownerId = $request->integer('owner_id');
        }

        $vehicles = Vehicle::where('owner_id', $ownerId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        return response()->json($vehicles);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([], 401);
        }

        $data = $request->validate([
            'plate' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-\s]+$/', Rule::unique('vehicles', 'plate')->whereNull('deleted_at')],
            'make' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'color' => 'nullable|string|max:50',
            'year' => 'nullable|integer|min:1900|max:' . (date('Y') + 1),
            'seats' => 'nullable|integer|min:1|max:20',
            'vehicle_type' => 'nullable|string|in:turismo,furgoneta,camion,autobus,moto',
            'notes' => 'nullable|string|max:2000',
            'owner_id' => 'nullable|integer|exists:users,id',
            'is_default' => 'nullable|boolean',
        ]);

        $data['owner_id'] = ($user->isAdmin() && ! empty($data['owner_id'])) ? $data['owner_id'] : $user->id;
        $data['vehicle_type'] = $data['vehicle_type'] ?? 'turismo';
        $hasVehicles = Vehicle::where('owner_id', $data['owner_id'])->exists();
        $data['is_default'] = ! $hasVehicles || ($data['is_default'] ?? false);

        $vehicle = Vehicle::create($data);

        if ($vehicle->is_default) {
            $vehicle->makeDefault();
        }

        return response()->json($vehicle->fresh(), 201);
    }

    public function show(Request $request, Vehicle $vehicle)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([], 401);
        }
        if (! $user->isAdmin() && $vehicle->owner_id !== $user->id) {
            return response()->json([], 403);
        }

        return response()->json($vehicle);
    }

    public function update(Request $request, Vehicle $vehicle)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([], 401);
        }
        if (! $user->isAdmin() && $vehicle->owner_id !== $user->id) {
            return response()->json([], 403);
        }

        $data = $request->validate([
            'plate' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-\s]+$/', Rule::unique('vehicles', 'plate')->whereNull('deleted_at')->ignore($vehicle->id)],
            'make' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'color' => 'nullable|string|max:50',
            'year' => 'nullable|integer|min:1900|max:' . (date('Y') + 1),
            'seats' => 'nullable|integer|min:1|max:20',
            'vehicle_type' => 'nullable|string|in:turismo,furgoneta,camion,autobus,moto',
            'active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
            'is_default' => 'nullable|boolean',
        ]);

        $makeDefault = array_key_exists('is_default', $data) && $data['is_default'];
        unset($data['is_default']);

        $vehicle->update($data);

        if ($makeDefault) {
            $vehicle->makeDefault();
        }

        return response()->json($vehicle->fresh());
    }

    public function destroy(Request $request, Vehicle $vehicle)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([], 401);
        }
        if (! $user->isAdmin() && $vehicle->owner_id !== $user->id) {
            return response()->json([], 403);
        }

        $wasDefault = $vehicle->is_default;
        $ownerId = $vehicle->owner_id;
        $vehicle->delete();

        if ($wasDefault) {
            $next = Vehicle::where('owner_id', $ownerId)->orderBy('id')->first();
            $next?->makeDefault();
        }

        return response()->json(['message' => 'Vehicle deleted']);
    }

    public function setDefault(Request $request, Vehicle $vehicle)
    {
        $user = $request->user();
        if (! $user->isAdmin() && $vehicle->owner_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $vehicle->makeDefault();

        return response()->json($vehicle->fresh());
    }
}
