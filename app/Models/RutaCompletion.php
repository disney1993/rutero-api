<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RutaCompletion extends Model
{
    protected $fillable = [
        'ruta_id',
        'user_id',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function ruta()
    {
        return $this->belongsTo(Ruta::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
};
