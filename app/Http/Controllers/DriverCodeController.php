<?php

namespace App\Http\Controllers;

use App\Models\DriverCodeEntry;
use App\Models\OwnerMonthCode;
use Illuminate\Http\Request;

class DriverCodeController extends Controller
{
    public function join(Request $request)
    {
        $user = $request->user();
        if (! $user) return response()->json([], 401);

        $data = $request->validate([
            'code' => ['required', 'string', 'size:8', 'regex:/^[A-Z0-9]{8}$/i'],
            'month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);
        $data['code'] = strtoupper($data['code']);

        $ownerCode = OwnerMonthCode::where('code', $data['code'])->where('month', $data['month'])->first();
        if (! $ownerCode) {
            return response()->json(['message' => 'Código inválido'], 422);
        }

        if ($ownerCode->owner_id === $user->id) {
            return response()->json(['message' => 'No puedes unirte a tu propio código'], 422);
        }

        $entry = DriverCodeEntry::firstOrCreate([
            'owner_month_code_id' => $ownerCode->id,
            'driver_id' => $user->id,
        ]);

        return response()->json(['message' => 'Joined', 'entry' => $entry]);
    }

    public function listMyCodes(Request $request)
    {
        $user = $request->user();
        if (! $user) return response()->json([], 401);

        $entries = DriverCodeEntry::where('driver_id', $user->id)
            ->with('ownerMonthCode.owner:id,first_name,last_name,second_last_name,avatar_color')
            ->get();
        return response()->json($entries);
    }
}
