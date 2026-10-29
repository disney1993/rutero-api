<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens; // ✅ Esto es lo que falta

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    // SoftDeletes: listados, login y resolución Sanctum ignoran cuentas borradas.
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

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
        'work_mode',
        'auto_complete_enabled',
        'auto_complete_start_delay_min',
        'auto_complete_margin_min',
        'auto_complete_margin_pct',
        'auto_complete_default_duration_min',
        'email',
        'auth_provider',
        'google_id',
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
        'profile_incomplete',
        'effective_work_mode',
        'is_google_account',
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
            'auto_complete_enabled' => 'boolean',
            'auto_complete_start_delay_min' => 'integer',
            'auto_complete_margin_min' => 'integer',
            'auto_complete_margin_pct' => 'integer',
            'auto_complete_default_duration_min' => 'integer',
        ];
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class, 'owner_id');
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
        return $this->vehicles()->exists() || $this->ownerMonthCodes()->exists();
    }

    public function hasDriverCapability(): bool
    {
        return $this->driverCodeEntries()->exists();
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

    // Los logins con Google pueden crear el usuario sin nombre/apellido si
    // Google no los devuelve; esto permite detectarlo y forzar completar el
    // perfil antes de dejar usar el resto de la app.
    public function hasIncompleteProfile(): bool
    {
        return blank($this->first_name) || blank($this->last_name);
    }

    /** Cuenta creada vía Google (email no editable; contraseña no gestionable aquí). */
    public function isGoogleAccount(): bool
    {
        return $this->auth_provider === 'google';
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

    public function getProfileIncompleteAttribute(): bool
    {
        return $this->hasIncompleteProfile();
    }

    /**
     * Modo de trabajo efectivo para la app: con capacidad de propietario
     * respeta work_mode (puede probar driver sin haber unido códigos aún);
     * conductor puro → driver; resto → owner (usuarios nuevos sin flota).
     */
    public function effectiveWorkMode(): string
    {
        $owner = $this->hasOwnerCapability();
        $driver = $this->hasDriverCapability();

        if ($owner) {
            return in_array($this->work_mode, ['owner', 'driver'], true)
                ? $this->work_mode
                : 'owner';
        }

        if ($driver) {
            return 'driver';
        }

        return 'owner';
    }

    public function getEffectiveWorkModeAttribute(): string
    {
        return $this->effectiveWorkMode();
    }

    public function getIsGoogleAccountAttribute(): bool
    {
        return $this->isGoogleAccount();
    }
}
