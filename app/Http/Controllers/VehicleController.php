<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Illuminate\Http\Request;

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
            'notes' => 'nullable|string|max:2000',
            'owner_id' => 'nullable|integer|exists:users,id',
        ]);

        $data['owner_id'] = ($user->isAdmin() && ! empty($data['owner_id'])) ? $data['owner_id'] : $user->id;
        $vehicle = Vehicle::create($data);
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
            'notes' => 'nullable|string|max:2000',
        ]);

        $vehicle->update($data);
        return response()->json($vehicle);
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

        $vehicle->delete();
        return response()->json(['message' => 'Vehicle deleted']);
    }
}
