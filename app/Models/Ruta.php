<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ruta extends Model
{
    // Borrado blando: los viajes eliminados se pueden restaurar (ver RutaController::restore).
    use HasFactory, SoftDeletes;

    protected $table = 'rutas';

    protected $fillable = [
        'owner_id',
        'driver_id',
        'created_by',
        'completed_by',
        'cancelled_by',
        'auto_completed',
        'auto_completed_at',
        'reviewed_at',
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

    public function owner()
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_id');
    }

    public function driver()
    {
        return $this->belongsTo(\App\Models\User::class, 'driver_id');
    }

    // Nombrada "completer" (no "completedBy") porque una relación camelCase
    // se serializa como "completed_by" en JSON, lo que chocaría con la
    // columna cruda del mismo nombre. Mismo criterio para creator / canceller.
    public function completer()
    {
        return $this->belongsTo(\App\Models\User::class, 'completed_by');
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function canceller()
    {
        return $this->belongsTo(\App\Models\User::class, 'cancelled_by');
    }

    public function completions()
    {
        return $this->hasMany(RutaCompletion::class);
    }

    /** Registra (sin borrar a los demás) que este usuario completó la ruta. */
    public function recordCompletion(int $userId): void
    {
        $this->completions()->firstOrCreate(
            ['user_id' => $userId],
            ['completed_at' => now()]
        );

        if ($this->completed_by !== $userId) {
            $this->forceFill(['completed_by' => $userId])->saveQuietly();
        }
    }

    protected $casts = [
        'trip_date' => 'date',
        'hidden' => 'boolean',
        'estimated_duration_min' => 'integer',
        'auto_completed' => 'boolean',
        'auto_completed_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    protected $appends = ['needs_review'];

    // Cerrada por el cron y todavía sin revisar: aparece en "Por revisar".
    public function getNeedsReviewAttribute(): bool
    {
        return (bool) $this->auto_completed && $this->reviewed_at === null;
    }

    // La columna es TIME en MySQL y siempre guarda/devuelve segundos
    // ("14:30:00"); la app solo necesita horas y minutos.
    public function getTripTimeAttribute(?string $value): ?string
    {
        return $value ? substr($value, 0, 5) : $value;
    }
}
