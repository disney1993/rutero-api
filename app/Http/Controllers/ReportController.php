<?php

namespace App\Http\Controllers;

use App\Models\Ruta;
use App\Models\User;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    // Resumen de actividad de UN usuario. Un admin puede pedir el de
    // cualquiera pasando user_id; cualquier otro usuario solo puede ver el
    // suyo propio (igual que la atribución de owner_id en RutaController).
    public function userSummary(Request $request)
    {
        $request->validate([
            'user_id' => 'sometimes|integer|exists:users,id',
        ]);

        $caller = $request->user();
        $targetId = ($caller->isAdmin() && $request->filled('user_id'))
            ? $request->integer('user_id')
            : $caller->id;

        $target = User::findOrFail($targetId);

        $rutasQuery = Ruta::where(function ($q) use ($targetId) {
            $q->where('owner_id', $targetId)->orWhere('driver_id', $targetId);
        });

        return response()->json([
            'user' => $target->only(['id', 'first_name', 'last_name', 'email', 'avatar_color']),
            'vehicles_total' => $target->vehicles()->count(),
            'rutas_total' => (clone $rutasQuery)->count(),
            'has_owner_capability' => $target->hasOwnerCapability(),
            'has_driver_capability' => $target->hasDriverCapability(),
            'rutas_by_status' => (clone $rutasQuery)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'revenue_completed' => (float) (clone $rutasQuery)->where('status', 'completed')->sum('final_price'),
        ]);
    }
}
