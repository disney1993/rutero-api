<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ruta extends Model
{
    use HasFactory;

    protected $table = 'rutas';

    protected $fillable = [
        'owner_id',
        'driver_id',
        'created_by',
        'vehicle_id',
        'client_name',
        'client_phone',
        'origin',
        'origin_lat',
        'origin_lng',
        'destination',
        'destination_lat',
        'destination_lng',
        'estimated_distance_km',
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

    public function owner()
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_id');
    }

    public function driver()
    {
        return $this->belongsTo(\App\Models\User::class, 'driver_id');
    }

    protected $casts = [
        'trip_date' => 'date',
        'hidden' => 'boolean',
    ];
}
