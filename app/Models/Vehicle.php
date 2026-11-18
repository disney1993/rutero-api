<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use Auditable, HasFactory, SoftDeletes;

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
        'active',
        'is_default',
        'notes',
    ];

    protected $casts = [
        'active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_id');
    }

    public function rutas(): HasMany
    {
        return $this->hasMany(Ruta::class, 'vehicle_id');
    }

    public function makeDefault(): void
    {
        static::where('owner_id', $this->owner_id)
            ->where('id', '!=', $this->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        if (! $this->is_default) {
            $this->forceFill(['is_default' => true])->save();
        }
    }
}
