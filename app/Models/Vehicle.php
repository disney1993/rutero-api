<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    use HasFactory;

    // Tipos de vehículo admitidos (nomenclatura DGT). Deben coincidir con
    // VEHICLE_TYPES de rutero-react (src/components/ui/VehicleTypeIcon.js).
    // M1 turismos: AB berlina con portón, AA berlina, AF uso múltiple, todoterreno;
    // L motocicletas; N camiones.
    public const TYPES = ['compacto', 'sedan', 'monovolumen', 'suv', 'motocicleta', 'camion'];

    protected $fillable = [
        'owner_id',
        'assigned_driver_id',
        'plate',
        'make',
        'model',
        'color',
        'year',
        'seats',
        'vehicle_type',
        'is_default',
        'notes',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    // Un propietario con vehículos siempre tiene uno predeterminado: si se crea
    // uno y el propietario aún no tiene ninguno, pasa a serlo. Así se cumple
    // venga de la API, del seeder o de una factory (antes solo lo garantizaba
    // VehicleController::store y los datos de demo quedaban sin predeterminado).
    protected static function booted(): void
    {
        static::creating(function (Vehicle $vehicle) {
            if ($vehicle->is_default || ! $vehicle->owner_id) return;
            $hasDefault = static::where('owner_id', $vehicle->owner_id)->where('is_default', true)->exists();
            if (! $hasDefault) $vehicle->is_default = true;
        });
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function assignedDriver()
    {
        return $this->belongsTo(User::class, 'assigned_driver_id');
    }

    public function rutas()
    {
        return $this->hasMany(Ruta::class, 'vehicle_id');
    }
}
