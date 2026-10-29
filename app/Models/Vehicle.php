<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use HasFactory, SoftDeletes;

    // Tipos de vehículo admitidos (nomenclatura DGT). Deben coincidir con
    // VEHICLE_TYPES de rutero-react (src/components/ui/VehicleTypeIcon.js).
    // M1 turismos: AB berlina con portón, AA berlina, AF uso múltiple, todoterreno;
    // L motocicletas; N camiones.
    public const TYPES = ['compacto', 'sedan', 'monovolumen', 'suv', 'motocicleta', 'camion'];

    // El color se guarda siempre como hex "#RRGGBB" (mayúsculas). Antes se
    // guardaba el nombre; estos nombres se siguen aceptando y se convierten,
    // con los mismos tonos que CAR_COLOR_PALETTE de rutero-react
    // (src/utils/carColors.js). Incluye los nombres en inglés que generaba la
    // factory antigua (safeColorName).
    public const COLOR_NAMES = [
        'blanco' => '#FFFFFF', 'negro' => '#1C1C1C', 'gris' => '#9E9E9E',
        'plata' => '#C7C7C7', 'azul' => '#1565C0', 'rojo' => '#D32F2F',
        'verde' => '#2E7D32', 'amarillo' => '#FBC02D', 'naranja' => '#EF6C00',
        'marron' => '#5D4037', 'beige' => '#D7CCC8',
        'azul marino' => '#1B2A4A', 'celeste' => '#4FC3F7', 'turquesa' => '#00838F',
        'lima' => '#9CCC65', 'morado' => '#6B2C91', 'violeta' => '#6B2C91',
        'magenta' => '#C2185B', 'rosa' => '#EC407A', 'granate' => '#7B1E2B',
        'burdeos' => '#7B1E2B', 'dorado' => '#C9B37E', 'bronce' => '#8D6E63',
        'white' => '#FFFFFF', 'black' => '#1C1C1C', 'gray' => '#9E9E9E',
        'grey' => '#9E9E9E', 'silver' => '#C7C7C7', 'blue' => '#1565C0',
        'navy' => '#1B2A4A', 'red' => '#D32F2F', 'maroon' => '#7B1E2B',
        'green' => '#2E7D32', 'olive' => '#1F4D3A', 'lime' => '#2E7D32',
        'teal' => '#00838F', 'aqua' => '#00838F', 'yellow' => '#FBC02D',
        'orange' => '#EF6C00', 'purple' => '#6B2C91', 'fuchsia' => '#6B2C91',
    ];

    /** Hex "#RRGGBB" para un hex (#RGB / #RRGGBB) o un nombre conocido; null si no se reconoce. */
    public static function normalizeColor(?string $value): ?string
    {
        $value = trim((string) $value);
        if (preg_match('/^#([0-9A-Fa-f]{6})$/', $value, $m)) {
            return '#'.strtoupper($m[1]);
        }
        if (preg_match('/^#([0-9A-Fa-f])([0-9A-Fa-f])([0-9A-Fa-f])$/', $value, $m)) {
            return strtoupper("#{$m[1]}{$m[1]}{$m[2]}{$m[2]}{$m[3]}{$m[3]}");
        }
        $key = strtr(mb_strtolower($value), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);

        return self::COLOR_NAMES[$key] ?? null;
    }

    // Garantiza el formato hex se cree el vehículo desde donde se cree.
    public function setColorAttribute(?string $value): void
    {
        $this->attributes['color'] = $value === null ? null : (self::normalizeColor($value) ?? $value);
    }

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
