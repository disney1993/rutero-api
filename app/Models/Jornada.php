<?php

namespace App\Models;

use App\Support\ShareCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Jornada de trabajo de un día: conductor, vehículo y código para compartirla.
 * Un mismo día puede tener varias (normalmente una por conductor).
 */
class Jornada extends Model
{
    protected $fillable = ['owner_id', 'date', 'label', 'driver_id', 'vehicle_id', 'code', 'position'];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function rutas()
    {
        return $this->hasMany(Ruta::class, 'jornada_id');
    }

    /** Se puede usar el código hasta el día de la jornada incluido (hora de España). */
    public function isJoinable(): bool
    {
        return $this->date->toDateString() >= now(config('rutero.auto_complete.timezone'))->toDateString();
    }

    /**
     * Crea una jornada para el día. La primera del día "adopta" los viajes
     * que ya existían sin jornada (eran la Jornada 1 virtual) y, si no se
     * indica otra cosa, toma el conductor predeterminado y su vehículo.
     */
    public static function createFor(User $owner, string $date, array $attrs = []): self
    {
        return DB::transaction(function () use ($owner, $date, $attrs) {
            $existing = static::where('owner_id', $owner->id)->whereDate('date', $date)->lockForUpdate()->count();
            $position = $existing + 1;
            $isFirst = $existing === 0;

            $driverId = array_key_exists('driver_id', $attrs)
                ? $attrs['driver_id']
                : ($isFirst ? OwnerDriver::defaultDriverId($owner->id) : null);

            $vehicleId = $attrs['vehicle_id'] ?? null;
            if (! $vehicleId) {
                $link = $driverId ? OwnerDriver::active()->where('owner_id', $owner->id)->where('driver_id', $driverId)->first() : null;
                $vehicleId = $link?->default_vehicle_id
                    ?? Vehicle::where('owner_id', $owner->id)->orderByDesc('is_default')->orderBy('id')->value('id');
            }

            $jornada = static::create([
                'owner_id' => $owner->id,
                'date' => $date,
                'label' => $attrs['label'] ?? "Jornada {$position}",
                'driver_id' => $driverId,
                'vehicle_id' => $vehicleId,
                'code' => ShareCode::generate(),
                'position' => $position,
            ]);

            if ($isFirst) {
                Ruta::where('owner_id', $owner->id)->whereDate('trip_date', $date)->whereNull('jornada_id')
                    ->update(['jornada_id' => $jornada->id]);
            }

            return $jornada;
        });
    }

    /**
     * Al cambiar el conductor o el vehículo de la jornada, sus viajes
     * pendientes que tenían el anterior pasan al nuevo. Los completados no
     * se tocan: reflejan quién los hizo.
     */
    public function propagateToPendingTrips(array $before): void
    {
        foreach (['driver_id', 'vehicle_id'] as $field) {
            if (! array_key_exists($field, $before) || $before[$field] === $this->{$field}) {
                continue;
            }
            if ($field === 'vehicle_id' && ! $this->vehicle_id) {
                continue; // un viaje no se queda sin vehículo
            }
            $this->rutas()
                ->where('status', 'pending')
                ->where(fn ($q) => $before[$field] === null ? $q->whereNull($field) : $q->where($field, $before[$field]))
                ->update([$field => $this->{$field}]);
        }
    }
}
