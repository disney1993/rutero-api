<?php

namespace App\Http\Controllers;

use App\Models\OwnerMonthCode;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OwnerCodeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) return response()->json([], 401);
        if (! $user->isAdmin() && ! $user->hasOwnerCapability()) return response()->json(['message' => 'Debes tener al menos un vehículo para generar un código'], 403);

        $codes = OwnerMonthCode::where('owner_id', $user->id)->orderByDesc('id')->get();
        return response()->json($codes);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user) return response()->json([], 401);
        if (! $user->isAdmin() && ! $user->hasOwnerCapability()) return response()->json(['message' => 'Debes tener al menos un vehículo para generar un código'], 403);

        $data = $request->validate([
            'type' => ['nullable', 'string', Rule::in([OwnerMonthCode::TYPE_MONTH, OwnerMonthCode::TYPE_DAY])],
            'month' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'valid_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $type = $data['type'] ?? OwnerMonthCode::TYPE_MONTH;

        if ($type === OwnerMonthCode::TYPE_DAY) {
            $validOn = $data['valid_on'] ?? now()->toDateString();
            $month = substr($validOn, 0, 7);
            $scopeKey = $validOn;
        } else {
            if (empty($data['month'])) {
                throw ValidationException::withMessages([
                    'month' => ['The month field is required.'],
                ]);
            }
            $month = $data['month'];
            $validOn = null;
            $scopeKey = $month;
        }

        $duplicate = OwnerMonthCode::query()
            ->where('owner_id', $user->id)
            ->where('type', $type)
            ->where('scope_key', $scopeKey)
            ->exists();

        if ($duplicate) {
            $field = $type === OwnerMonthCode::TYPE_DAY ? 'valid_on' : 'month';
            $message = $type === OwnerMonthCode::TYPE_DAY
                ? 'A day code already exists for this date.'
                : 'A month code already exists for this month.';
            throw ValidationException::withMessages([
                $field => [$message],
            ]);
        }

        $code = strtoupper(Str::random(8));
        $entry = OwnerMonthCode::create([
            'owner_id' => $user->id,
            'code' => $code,
            'month' => $month,
            'type' => $type,
            'valid_on' => $validOn,
            'scope_key' => $scopeKey,
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
