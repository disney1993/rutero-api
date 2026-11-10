<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Vínculo propietario-conductor (equipo).
 *
 * - pending: invitación enviada (por email o enlace) y aún sin aceptar.
 * - active: el conductor aceptó; el propietario puede asignarle viajes.
 * - rejected / revoked: el conductor la rechazó / cualquiera de los dos rompió
 *   el vínculo. Los viajes pasados conservan su atribución.
 */
class OwnerDriver extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REVOKED = 'revoked';

    public const VIA_EMAIL = 'email';

    public const VIA_LINK = 'link';

    public const VIA_CODE = 'code';

    /** Días de validez de una invitación por enlace. */
    public const LINK_TTL_DAYS = 7;

    protected $fillable = [
        'owner_id',
        'driver_id',
        'invited_email',
        'status',
        'invited_via',
        'invite_code',
        'expires_at',
        'alias',
        'default_vehicle_id',
        'is_default',
        'accepted_at',
        'revoked_at',
        'revoked_by',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function defaultVehicle()
    {
        return $this->belongsTo(Vehicle::class, 'default_vehicle_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Conductor por defecto (activo) del propietario, o null. */
    public static function defaultDriverId(int $ownerId): ?int
    {
        return static::active()->where('owner_id', $ownerId)->where('is_default', true)->value('driver_id');
    }

    /** ¿Tiene $driverId un vínculo activo con $ownerId? */
    public static function isActive(int $ownerId, int $driverId): bool
    {
        return static::active()->where('owner_id', $ownerId)->where('driver_id', $driverId)->exists();
    }

    /**
     * Código de invitación de 8 caracteres, único también frente a los
     * códigos de jornada para que el conductor pueda escribirlo en el mismo
     * campo "Código" de la app.
     */
    public static function newInviteCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (
            static::where('invite_code', $code)->exists()
            || OwnerMonthCode::where('code', $code)->exists()
        );

        return $code;
    }

    /**
     * Activa el vínculo de $driver con el propietario de esta invitación. Si ya
     * existía un vínculo entre ambos (rechazado, revocado o pendiente por
     * email) se reutiliza esa fila y la invitación usada se elimina.
     */
    public function acceptBy(User $driver, ?string $via = null): self
    {
        $existing = static::where('owner_id', $this->owner_id)
            ->where('driver_id', $driver->id)
            ->where('id', '!=', $this->id)
            ->first();

        $link = $existing ?? $this;
        $link->fill([
            'driver_id' => $driver->id,
            'status' => self::STATUS_ACTIVE,
            'accepted_at' => now(),
            'revoked_at' => null,
            'revoked_by' => null,
            'invite_code' => null,
            'expires_at' => null,
        ]);
        if ($via) {
            $link->invited_via = $via;
        }
        $link->save();

        if ($existing) {
            $this->delete();
        }

        return $link;
    }

    /** Vincula directamente (al unirse con un código mensual del propietario). */
    public static function activate(int $ownerId, User $driver, string $via = self::VIA_CODE): self
    {
        $link = static::firstOrNew(['owner_id' => $ownerId, 'driver_id' => $driver->id]);
        if ($link->status !== self::STATUS_ACTIVE) {
            $link->fill([
                'status' => self::STATUS_ACTIVE,
                'invited_via' => $link->exists ? $link->invited_via : $via,
                'accepted_at' => now(),
                'revoked_at' => null,
                'revoked_by' => null,
            ])->save();
        }

        return $link;
    }
}
