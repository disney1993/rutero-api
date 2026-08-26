<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    use HasFactory;

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
        'notes',
    ];

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
