<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

        // rutas_count permite a la app avisar de antemano de que un vehículo
        // con viajes no se puede eliminar (ver destroy).
        // Cuenta también los viajes eliminados (borrado blando): siguen apuntando al
        // vehículo y la clave foránea impediría borrarlo.
        $vehicles = Vehicle::where('owner_id', $ownerId)->withCount(['rutas' => fn ($q) => $q->withTrashed()])->get();
        return response()->json($vehicles);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user) return response()->json([], 401);

        $this->normalizePlate($request);
        $this->normalizeColor($request);

        // Todo obligatorio salvo las notas (igual en update).
        $data = $request->validate([
            'plate' => ['required', 'string', 'max:12', 'regex:/^[A-Z0-9]+$/', 'unique:vehicles,plate'],
            'make' => 'required|string|max:100',
            'model' => 'required|string|max:100',
            'color' => ['required', 'string', 'regex:/^#[0-9A-F]{6}$/'],
            'year' => 'required|integer|min:1950|max:' . (date('Y') + 1),
            'seats' => 'required|integer|min:1|max:90',
            'vehicle_type' => ['required', 'string', Rule::in(Vehicle::TYPES)],
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

        $this->normalizePlate($request);
        $this->normalizeColor($request);

        $data = $request->validate([
            'plate' => ['required', 'string', 'max:12', 'regex:/^[A-Z0-9]+$/', 'unique:vehicles,plate,' . $vehicle->id],
            'make' => 'required|string|max:100',
            'model' => 'required|string|max:100',
            'color' => ['required', 'string', 'regex:/^#[0-9A-F]{6}$/'],
            'year' => 'required|integer|min:1950|max:' . (date('Y') + 1),
            'seats' => 'required|integer|min:1|max:90',
            'vehicle_type' => ['required', 'string', Rule::in(Vehicle::TYPES)],
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

        // El vehículo predeterminado no se puede borrar directamente: hay que
        // asignar el atributo a otro primero, para no dejar la flota sin uno.
        if ($vehicle->is_default) {
            return response()->json(['message' => 'No puedes eliminar el vehículo predeterminado. Marca otro como predeterminado antes de eliminar este.'], 422);
        }

        // Con viajes asociados tampoco: se perdería a qué vehículo correspondían.
        if ($vehicle->rutas()->withTrashed()->exists()) {
            return response()->json(['message' => 'No puedes eliminar un vehículo que tiene viajes asociados.'], 422);
        }

        $vehicle->delete();
        return response()->json(['message' => 'Vehicle deleted']);
    }

    // La matrícula se guarda solo con letras y números en mayúsculas: se
    // quitan espacios, guiones y demás antes de validar, así "1234-abc" y
    // "1234 ABC" son la misma matrícula (y la regla unique lo detecta).
    // El color se guarda como hex "#RRGGBB". Un nombre conocido ("Blanco")
    // o un hex corto se convierten antes de validar; cualquier otra cosa se
    // deja tal cual para que la validación la rechace.
    private function normalizeColor(Request $request): void
    {
        if ($request->has('color') && is_string($request->input('color'))) {
            $hex = Vehicle::normalizeColor($request->input('color'));
            if ($hex) $request->merge(['color' => $hex]);
        }
    }

    private function normalizePlate(Request $request): void
    {
        if ($request->has('plate') && is_string($request->input('plate'))) {
            $request->merge(['plate' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $request->input('plate')))]);
        }
    }
}
