<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens; // ✅ Esto es lo que falta

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'second_last_name',
        'avatar',
        'avatar_color',
        'price_per_km',
        'currency',
        'theme',
        'language',
        'account_mode',
        'email',
        'password',
        'role',
        'plan',
        'premium_expires_at',
    ];

    protected $appends = [
        'has_owner_capability',
        'has_driver_capability',
        'is_admin',
        'is_premium',
        'is_driver_only',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'first_name' => 'string',
            'last_name' => 'string',
            'premium_expires_at' => 'datetime',
        ];
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class, 'owner_id');
    }

    public function defaultVehicle()
    {
        return $this->hasOne(Vehicle::class, 'owner_id')->where('is_default', true);
    }

    public function jornadas()
    {
        return $this->hasMany(Jornada::class, 'owner_id');
    }

    public function jornadaDriverEntries()
    {
        return $this->hasMany(JornadaDriverEntry::class, 'driver_id');
    }

    public function ownerMonthCodes()
    {
        return $this->hasMany(OwnerMonthCode::class, 'owner_id');
    }

    public function driverCodeEntries()
    {
        return $this->hasMany(DriverCodeEntry::class, 'driver_id');
    }

    public function hasOwnerCapability(): bool
    {
        return $this->vehicles()->exists()
            || $this->jornadas()->exists()
            || $this->ownerMonthCodes()->exists();
    }

    public function hasDriverCapability(): bool
    {
        return $this->jornadaDriverEntries()->exists()
            || $this->driverCodeEntries()->exists();
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isPremium(): bool
    {
        return $this->plan === 'premium'
            && (! $this->premium_expires_at || $this->premium_expires_at->isFuture());
    }

    public function isDriverOnly(): bool
    {
        return ($this->account_mode ?? 'owner') === 'driver';
    }

    public function getHasOwnerCapabilityAttribute(): bool
    {
        return $this->hasOwnerCapability();
    }

    public function getHasDriverCapabilityAttribute(): bool
    {
        return $this->hasDriverCapability();
    }

    public function getIsAdminAttribute(): bool
    {
        return $this->isAdmin();
    }

    public function getIsPremiumAttribute(): bool
    {
        return $this->isPremium();
    }

    public function getIsDriverOnlyAttribute(): bool
    {
        return $this->isDriverOnly();
    }
}
