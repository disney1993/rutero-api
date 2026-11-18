<?php

namespace App\Http\Controllers;

use App\Models\Jornada;
use App\Models\JornadaDriverEntry;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class JornadaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'date' => ['sometimes', 'date'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
        ]);

        $query = Jornada::query()->with([
            'owner:id,first_name,last_name,second_last_name,avatar_color',
            'driverEntries.driver:id,first_name,last_name,second_last_name,avatar_color',
        ]);

        if ($user->isAdmin() && $request->filled('owner_id')) {
            $query->where('owner_id', $request->integer('owner_id'));
        } elseif (! $user->isAdmin()) {
            $query->where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)
                    ->orWhereHas('driverEntries', fn ($d) => $d->where('driver_id', $user->id));
            });
        }

        if (! empty($data['date'])) {
            $query->whereDate('date', $data['date']);
        } elseif (! empty($data['date_from']) && ! empty($data['date_to'])) {
            $query->whereBetween('date', [$data['date_from'], $data['date_to']]);
        }

        $jornadas = $query->orderBy('date', 'desc')->orderBy('label')->get();

        return response()->json(['data' => $jornadas, 'count' => $jornadas->count()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && ! $user->vehicles()->exists()) {
            return response()->json(['message' => 'Necesitas al menos un vehículo para crear jornadas'], 403);
        }

        $data = $request->validate([
            'date' => ['required', 'date'],
            'label' => ['nullable', 'string', Rule::in(Jornada::LABELS)],
            'allow_create' => ['sometimes', 'boolean'],
            'allow_edit' => ['sometimes', 'boolean'],
            'allow_delete' => ['sometimes', 'boolean'],
        ]);

        $ownerId = $user->id;
        $existingLabels = Jornada::where('owner_id', $ownerId)
            ->whereDate('date', $data['date'])
            ->pluck('label')
            ->all();

        if (count($existingLabels) >= Jornada::MAX_PER_DAY) {
            return response()->json(['message' => 'Máximo 3 jornadas por día'], 422);
        }

        $label = $data['label'] ?? collect(Jornada::LABELS)->first(fn ($l) => ! in_array($l, $existingLabels, true));
        if (! $label || in_array($label, $existingLabels, true)) {
            return response()->json(['message' => 'Esa etiqueta de jornada ya está en uso ese día'], 422);
        }

        $jornada = Jornada::create([
            'owner_id' => $ownerId,
            'date' => $data['date'],
            'label' => $label,
            'code' => $this->uniqueCode(),
            'allow_create' => $data['allow_create'] ?? true,
            'allow_edit' => $data['allow_edit'] ?? true,
            'allow_delete' => $data['allow_delete'] ?? false,
        ]);

        return response()->json($jornada->load('owner:id,first_name,last_name,second_last_name,avatar_color'), 201);
    }

    public function show(Request $request, Jornada $jornada): JsonResponse
    {
        $user = $request->user();
        if (! $this->canView($user, $jornada)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $jornada->load([
            'owner:id,first_name,last_name,second_last_name,avatar_color',
            'driverEntries.driver:id,first_name,last_name,second_last_name,avatar_color',
        ]);

        return response()->json($jornada);
    }

    public function update(Request $request, Jornada $jornada): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && $jornada->owner_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'allow_create' => ['sometimes', 'boolean'],
            'allow_edit' => ['sometimes', 'boolean'],
            'allow_delete' => ['sometimes', 'boolean'],
            'label' => ['sometimes', 'string', Rule::in(Jornada::LABELS)],
        ]);

        if (isset($data['label']) && $data['label'] !== $jornada->label) {
            $taken = Jornada::where('owner_id', $jornada->owner_id)
                ->whereDate('date', $jornada->date)
                ->where('label', $data['label'])
                ->where('id', '!=', $jornada->id)
                ->exists();
            if ($taken) {
                return response()->json(['message' => 'Esa etiqueta de jornada ya está en uso ese día'], 422);
            }
        }

        $jornada->update($data);

        return response()->json($jornada);
    }

    public function destroy(Request $request, Jornada $jornada): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && $jornada->owner_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $jornada->delete();

        return response()->json(['message' => 'Jornada eliminada']);
    }

    public function join(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'code' => ['required', 'string', 'size:8', 'regex:/^[A-Z0-9]{8}$/i'],
        ]);
        $code = strtoupper($data['code']);

        $jornada = Jornada::where('code', $code)->first();
        if (! $jornada) {
            return response()->json(['message' => 'Código inválido'], 422);
        }

        if ($jornada->owner_id === $user->id) {
            return response()->json(['message' => 'No puedes unirte a tu propia jornada'], 422);
        }

        $entry = JornadaDriverEntry::firstOrCreate([
            'jornada_id' => $jornada->id,
            'driver_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Joined',
            'entry' => $entry,
            'jornada' => $jornada->load('owner:id,first_name,last_name,second_last_name,avatar_color'),
        ]);
    }

    public function myJoined(Request $request): JsonResponse
    {
        $entries = JornadaDriverEntry::where('driver_id', $request->user()->id)
            ->with(['jornada.owner:id,first_name,last_name,second_last_name,avatar_color'])
            ->latest('id')
            ->get();

        return response()->json($entries);
    }

    public function vehicles(Request $request, Jornada $jornada): JsonResponse
    {
        $user = $request->user();
        if (! $this->canView($user, $jornada)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $vehicles = Vehicle::where('owner_id', $jornada->owner_id)
            ->where('active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $vehicles,
            'default_vehicle_id' => $vehicles->firstWhere('is_default', true)?->id,
        ]);
    }

    private function canView($user, Jornada $jornada): bool
    {
        if ($user->isAdmin() || $jornada->owner_id === $user->id) {
            return true;
        }

        return $jornada->hasDriver($user->id);
    }

    private function uniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Jornada::withTrashed()->where('code', $code)->exists());

        return $code;
    }
}
