<?php

namespace App\Http\Controllers;

use App\Models\ModelAudit;
use App\Models\Ruta;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function forRuta(int $ruta): JsonResponse
    {
        $model = Ruta::withTrashed()->findOrFail($ruta);

        return $this->listFor($model);
    }

    public function forVehicle(int $vehicle): JsonResponse
    {
        $model = Vehicle::withTrashed()->findOrFail($vehicle);

        return $this->listFor($model);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auditable_type' => ['sometimes', 'string', 'in:ruta,vehicle'],
            'auditable_id' => ['sometimes', 'integer'],
            'user_id' => ['sometimes', 'integer'],
        ]);

        $query = ModelAudit::query()->with('user:id,first_name,last_name,email')->latest('id');

        if (! empty($data['auditable_type'])) {
            $map = [
                'ruta' => Ruta::class,
                'vehicle' => Vehicle::class,
            ];
            $query->where('auditable_type', $map[$data['auditable_type']]);
        }

        if (! empty($data['auditable_id'])) {
            $query->where('auditable_id', $data['auditable_id']);
        }

        if (! empty($data['user_id'])) {
            $query->where('user_id', $data['user_id']);
        }

        $audits = $query->limit(200)->get();

        return response()->json(['data' => $audits, 'count' => $audits->count()]);
    }

    private function listFor($model): JsonResponse
    {
        $audits = ModelAudit::where('auditable_type', $model->getMorphClass())
            ->where('auditable_id', $model->getKey())
            ->with('user:id,first_name,last_name,email')
            ->latest('id')
            ->get();

        return response()->json(['data' => $audits, 'count' => $audits->count()]);
    }
}
