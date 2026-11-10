<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\OwnerDriver;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gastos del propietario. Solo los ve y gestiona el propio propietario: los
 * conductores no tienen acceso (las nóminas son datos sensibles).
 */
class ExpenseController extends Controller
{
    private const WITH = [
        'vehicle:id,plate,make,model,color,vehicle_type',
        'driver:id,first_name,last_name,avatar_color,avatar',
    ];

    public function categories(Request $request): JsonResponse
    {
        $lang = in_array($request->query('lang'), ['es', 'en'], true) ? $request->query('lang') : 'es';
        $list = [];
        foreach (Expense::categories() as $key => $c) {
            $list[] = [
                'key' => $key,
                'label' => $c[$lang],
                'icon' => $c['icon'],
                'links' => $c['links'],
                'driver_required' => (bool) ($c['driver_required'] ?? false),
                'description_required' => (bool) ($c['description_required'] ?? false),
            ];
        }

        return response()->json(['categories' => $list, 'payment_methods' => Expense::PAYMENT_METHODS]);
    }

    public function index(Request $request): JsonResponse
    {
        $owner = $request->user();
        if (! $this->canManage($owner)) {
            return response()->json(['message' => 'Solo el propietario puede ver sus gastos'], 403);
        }
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'category' => ['sometimes', Rule::in(array_keys(Expense::categories()))],
            'vehicle_id' => ['sometimes', 'integer'],
        ]);

        $query = Expense::where('owner_id', $owner->id)
            ->whereDate('date', '>=', $data['from'])
            ->whereDate('date', '<=', $data['to'])
            ->when($data['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($data['vehicle_id'] ?? null, fn ($q, $v) => $q->where('vehicle_id', $v));

        $byCategory = (clone $query)->selectRaw('category, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('category')->get()
            ->map(fn ($r) => ['category' => $r->category, 'total' => round((float) $r->total, 2), 'count' => (int) $r->count])
            ->sortByDesc('total')->values();

        return response()->json([
            'data' => $query->with(self::WITH)->orderByDesc('date')->orderByDesc('id')->get(),
            'total' => round((float) $byCategory->sum('total'), 2),
            'by_category' => $byCategory,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $owner = $request->user();
        if (! $this->canManage($owner)) {
            return response()->json(['message' => 'Solo el propietario puede registrar gastos'], 403);
        }
        $data = $this->validated($request, $owner);
        $expense = Expense::create($data + ['owner_id' => $owner->id]);

        return response()->json($expense->load(self::WITH), 201);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $owner = $request->user();
        if ($expense->owner_id !== $owner->id) {
            return response()->json([], 403);
        }
        $expense->update($this->validated($request, $owner, $expense));

        return response()->json($expense->fresh(self::WITH));
    }

    public function destroy(Request $request, Expense $expense): JsonResponse
    {
        if ($expense->owner_id !== $request->user()->id) {
            return response()->json([], 403);
        }
        $expense->delete();

        return response()->json(['message' => 'Gasto eliminado']);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $expense = Expense::withTrashed()->where('id', $id)->where('owner_id', $request->user()->id)->firstOrFail();
        $expense->restore();

        return response()->json($expense->fresh(self::WITH));
    }

    // ----------------------------------------------------------------- helpers

    private function canManage(?User $user): bool
    {
        return $user && $user->hasOwnerCapability();
    }

    /** Validación común de alta y edición (en edición, los campos son opcionales). */
    private function validated(Request $request, User $owner, ?Expense $current = null): array
    {
        $req = $current ? 'sometimes' : 'required';
        $categories = Expense::categories();
        $maxDate = now(config('rutero.auto_complete.timezone'))->addYear()->toDateString();

        $data = $request->validate([
            'date' => [$req, 'date_format:Y-m-d', 'after_or_equal:2000-01-01', "before_or_equal:$maxDate"],
            'category' => [$req, Rule::in(array_keys($categories))],
            'description' => ['sometimes', 'nullable', 'string', 'max:120'],
            'amount' => [$req, 'numeric', 'gt:0', 'max:1000000'],
            'vat_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'vehicle_id' => ['sometimes', 'nullable', 'integer'],
            'driver_id' => ['sometimes', 'nullable', 'integer'],
            'payment_method' => ['sometimes', 'nullable', Rule::in(Expense::PAYMENT_METHODS)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ], [
            'amount.gt' => 'El importe debe ser mayor que 0.',
            'date.before_or_equal' => 'La fecha no puede ser posterior a dentro de un año.',
        ]);

        $category = $data['category'] ?? $current?->category;
        $conf = $categories[$category];
        $description = array_key_exists('description', $data) ? trim((string) $data['description']) : $current?->description;
        $amount = (float) ($data['amount'] ?? $current?->amount);
        $vat = array_key_exists('vat_amount', $data) ? $data['vat_amount'] : $current?->vat_amount;
        $vehicleId = array_key_exists('vehicle_id', $data) ? $data['vehicle_id'] : $current?->vehicle_id;
        $driverId = array_key_exists('driver_id', $data) ? $data['driver_id'] : $current?->driver_id;

        $errors = [];
        if (! empty($conf['description_required']) && $description === '') {
            $errors['description'] = 'Describe el gasto.';
        }
        if ($vat !== null && (float) $vat > $amount) {
            $errors['vat_amount'] = 'El IVA no puede ser mayor que el importe.';
        }
        if ($vehicleId && ! Vehicle::where('id', $vehicleId)->where('owner_id', $owner->id)->exists()) {
            $errors['vehicle_id'] = 'El vehículo no pertenece a tu flota.';
        }
        // Conductores de su equipo, también los que ya lo dejaron (nóminas pasadas).
        if ($driverId && ! OwnerDriver::where('owner_id', $owner->id)->where('driver_id', $driverId)->exists()) {
            $errors['driver_id'] = 'Ese conductor no forma parte de tu equipo.';
        }
        if (! empty($conf['driver_required']) && ! $driverId) {
            $errors['driver_id'] = 'Elige el conductor de la nómina.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        if (array_key_exists('description', $data)) {
            $data['description'] = $description !== '' ? $description : null;
        }
        // Si la categoría no admite vehículo o conductor, no se guardan.
        if (! in_array('vehicle', $conf['links'], true)) {
            $data['vehicle_id'] = null;
        }
        if (! in_array('driver', $conf['links'], true)) {
            $data['driver_id'] = null;
        }

        return $data;
    }
}
