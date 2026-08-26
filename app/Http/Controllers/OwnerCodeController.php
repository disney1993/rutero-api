<?php

namespace App\Http\Controllers;

use App\Models\OwnerMonthCode;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OwnerCodeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) return response()->json([], 401);
        if (! $user->isAdmin() && ! $user->hasOwnerCapability()) return response()->json(['message' => 'Debes tener al menos un vehículo para generar un código'], 403);

        $codes = OwnerMonthCode::where('owner_id', $user->id)->get();
        return response()->json($codes);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user) return response()->json([], 401);
        if (! $user->isAdmin() && ! $user->hasOwnerCapability()) return response()->json(['message' => 'Debes tener al menos un vehículo para generar un código'], 403);

        $data = $request->validate([
            'month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], // YYYY-MM
        ]);

        $code = strtoupper(Str::random(8));
        $entry = OwnerMonthCode::create([
            'owner_id' => $user->id,
            'code' => $code,
            'month' => $data['month'],
        ]);

        return response()->json($entry, 201);
    }

    public function destroy(Request $request, OwnerMonthCode $ownerMonthCode)
    {
        $user = $request->user();
        if (! $user) return response()->json([], 401);
        if (! $user->isAdmin() && ! $user->hasOwnerCapability()) return response()->json(['message' => 'Debes tener al menos un vehículo para generar un código'], 403);
        if ($ownerMonthCode->owner_id !== $user->id && ! $user->isAdmin()) return response()->json([], 403);

        $ownerMonthCode->delete();
        return response()->json(['message' => 'Code deleted']);
    }
}
