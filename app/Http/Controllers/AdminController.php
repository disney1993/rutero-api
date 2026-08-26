<?php

namespace App\Http\Controllers;

use App\Models\Ruta;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function users(Request $request)
    {
        $query = User::where('role', '!=', 'admin');

        if ($request->filled('search')) {
            $term = trim($request->input('search'));
            $query->where(function ($q) use ($term) {
                $q->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('second_last_name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            });
        }

        $users = $query->get();

        if ($request->input('type') === 'owner') {
            $users = $users->filter(fn (User $user) => $user->hasOwnerCapability())->values();
        } elseif ($request->input('type') === 'driver') {
            $users = $users->filter(fn (User $user) => $user->hasDriverCapability())->values();
        }

        return response()->json($users);
    }

    public function updateUser(Request $request, User $user)
    {
        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'min:2', 'max:50', 'regex:/^[\p{L}\s]+$/u'],
            'last_name' => ['sometimes', 'string', 'min:2', 'max:50', 'regex:/^[\p{L}\s]+$/u'],
            'second_last_name' => ['sometimes', 'nullable', 'string', 'min:2', 'max:50', 'regex:/^[\p{L}\s]+$/u'],
            'email' => ['sometimes', 'string', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'role' => ['sometimes', 'in:admin,user'],
            'plan' => ['sometimes', 'in:free,premium'],
            'premium_expires_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if (array_key_exists('first_name', $data) || array_key_exists('last_name', $data) || array_key_exists('second_last_name', $data)) {
            $firstName = $data['first_name'] ?? $user->first_name;
            $lastName = $data['last_name'] ?? $user->last_name;
            $secondLastName = array_key_exists('second_last_name', $data) ? $data['second_last_name'] : $user->second_last_name;
            $data['name'] = trim(implode(' ', array_filter([$firstName, $lastName, $secondLastName])));
        }

        $user->update($data);

        return response()->json($user);
    }

    public function deleteUser(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'No puedes eliminar tu propia cuenta de administrador'], 422);
        }

        // Elimina primero las rutas que este usuario posee: si no, borrar sus
        // vehículos podría chocar con la restricción de rutas.vehicle_id
        // (restrictOnDelete) antes de que el cascade de owner_id actúe.
        DB::transaction(function () use ($user) {
            Ruta::where('owner_id', $user->id)->delete();
            $user->delete();
        });

        return response()->json(['message' => 'Usuario eliminado']);
    }

    public function reportsSummary()
    {
        $users = User::where('role', '!=', 'admin')->get();

        $topOwners = Ruta::whereNotNull('owner_id')
            ->selectRaw('owner_id, count(*) as rutas_count')
            ->groupBy('owner_id')
            ->orderByDesc('rutas_count')
            ->with('owner:id,first_name,last_name,email')
            ->limit(5)
            ->get();

        return response()->json([
            'users_total' => $users->count(),
            'owners_total' => $users->filter(fn (User $u) => $u->hasOwnerCapability())->count(),
            'drivers_total' => $users->filter(fn (User $u) => $u->hasDriverCapability())->count(),
            'vehicles_total' => Vehicle::count(),
            'rutas_by_status' => Ruta::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'revenue_completed' => (float) Ruta::where('status', 'completed')->sum('final_price'),
            'top_owners' => $topOwners,
        ]);
    }
}
