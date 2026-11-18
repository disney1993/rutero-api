<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ruta extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'rutas';

    protected $fillable = [
        'owner_id',
        'driver_id',
        'created_by',
        'vehicle_id',
        'jornada_id',
        'client_name',
        'client_phone',
        'origin',
        'origin_lat',
        'origin_lng',
        'destination',
        'destination_lat',
        'destination_lng',
        'estimated_distance_km',
        'estimated_duration_min',
        'price_per_km',
        'estimated_price',
        'final_price',
        'payment_method',
        'passenger_count',
        'trip_date',
        'trip_time',
        'status',
        'hidden',
        'notes',
    ];

    protected $casts = [
        'trip_date' => 'date',
        'hidden' => 'boolean',
    ];

    public function toArray(): array
    {
        $array = parent::toArray();
        // FE espera Y-m-d / H:i; el cast `date` serializa ISO datetime completo.
        if (! empty($this->attributes['trip_date'])) {
            $array['trip_date'] = substr((string) $this->attributes['trip_date'], 0, 10);
        }
        if (! empty($this->attributes['trip_time'])) {
            $array['trip_time'] = substr((string) $this->attributes['trip_time'], 0, 5);
        }

        return $array;
    }
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function jornada(): BelongsTo
    {
        return $this->belongsTo(Jornada::class);
    }
}
