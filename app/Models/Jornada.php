<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Jornada de trabajo de un día: conductor principal, vehículo y conductores
 * asignados (drivers). Un mismo día puede tener varias. Los conductores los
 * asigna el propietario entre los de su equipo, y cada uno recibe un aviso.
 */
class Jornada extends Model
{
    /** Los viajes sin asignar los ve todo el equipo. */
    public const VISIBLE_TO_TEAM = 'team';

    /** Los viajes sin asignar solo los ven los conductores asignados. */
    public const VISIBLE_TO_DRIVERS = 'drivers';

    protected $fillable = ['owner_id', 'date', 'label', 'driver_id', 'vehicle_id', 'position', 'visibility'];

    // Columna de los antiguos códigos de jornada; se borra en una migración aparte.
    protected $hidden = ['code'];

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

    /**
     * Conductores asignados ahora a la jornada (sin los retirados), con cuándo
     * se les avisó y si lo han visto. Quien deja el equipo pierde el acceso
     * aunque siga aquí: también se comprueba el vínculo activo.
     */
    public function drivers()
    {
        return $this->belongsToMany(User::class, 'jornada_drivers', 'jornada_id', 'driver_id')
            ->withPivot(['notified_at', 'email_sent_at', 'seen_at'])
            ->withTimestamps()
            ->wherePivotNull('removed_at');
    }

    /** Todos los avisos de la jornada, también de conductores retirados (para mostrar si ya se avisó). */
    public function notices()
    {
        return $this->belongsToMany(User::class, 'jornada_drivers', 'jornada_id', 'driver_id')
            ->withPivot(['notified_at', 'seen_at', 'removed_at']);
    }

    /** ¿Ve este conductor los viajes sin asignar de la jornada? (además de ser del equipo) */
    public function isVisibleTo(int $driverId): bool
    {
        // Recién creada en memoria aún no trae el valor por defecto de la columna.
        return ($this->visibility ?? self::VISIBLE_TO_TEAM) === self::VISIBLE_TO_TEAM
            || $this->drivers()->where('users.id', $driverId)->exists();
    }

    /**
     * Deja asignados exactamente $driverIds. A quien entra por primera vez se
     * le registra el aviso (obligatorio); a quien vuelve tras ser retirado no
     * se le avisa otra vez; a quien sale se le marca como retirado.
     *
     * @return int[] ids de los conductores avisados ahora
     */
    public function assignDrivers(array $driverIds): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($driverIds))));
        $now = now();
        $rows = DB::table('jornada_drivers')->where('jornada_id', $this->id)->get()->keyBy('driver_id');

        $notified = [];
        foreach ($ids as $id) {
            $row = $rows->get($id);
            if (! $row) {
                DB::table('jornada_drivers')->insert([
                    'jornada_id' => $this->id, 'driver_id' => $id, 'notified_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $notified[] = $id;
            } elseif ($row->removed_at) {
                DB::table('jornada_drivers')->where('id', $row->id)->update(['removed_at' => null, 'updated_at' => $now]);
            }
        }

        DB::table('jornada_drivers')->where('jornada_id', $this->id)->whereNull('removed_at')
            ->whereNotIn('driver_id', $ids ?: [0])
            ->update(['removed_at' => $now, 'updated_at' => $now]);

        return $notified;
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
