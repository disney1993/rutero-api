<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Jornada extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const LABELS = ['A', 'B', 'C'];

    public const MAX_PER_DAY = 3;

    protected $fillable = [
        'owner_id',
        'date',
        'label',
        'code',
        'allow_create',
        'allow_edit',
        'allow_delete',
    ];

    protected $casts = [
        'date' => 'date',
        'allow_create' => 'boolean',
        'allow_edit' => 'boolean',
        'allow_delete' => 'boolean',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function driverEntries(): HasMany
    {
        return $this->hasMany(JornadaDriverEntry::class);
    }

    public function rutas(): HasMany
    {
        return $this->hasMany(Ruta::class);
    }

    public function hasDriver(int $driverId): bool
    {
        return $this->driverEntries()->where('driver_id', $driverId)->exists();
    }
}
