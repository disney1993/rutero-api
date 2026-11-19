<?php

namespace App\Services;

use App\Models\Ruta;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Cierre automático de viajes.
 *
 * Una ruta pendiente se da por completada (pago en efectivo) en cuanto ocurre
 * lo primero de:
 *  1. Encadenamiento: empieza el siguiente viaje de la misma unidad de trabajo
 *     (el conductor asignado o, si no lo hay, el vehículo). Nadie hace dos
 *     viajes a la vez, así que el anterior ya terminó.
 *  2. Fin estimado: inicio + espera inicial + d + margen % de d + margen final,
 *     donde d es la duración por carretera (OSRM). La espera inicial (10 min
 *     por defecto) cubre el tiempo habitual hasta arrancar de verdad (recoger
 *     al cliente, equipaje); el margen % absorbe el tráfico, que OSRM no
 *     conoce; el margen final (5 min) cubre la bajada y el cobro. Cubre el
 *     último viaje de la jornada.
 *
 * La regla se evalúa por unidad de trabajo, no sobre todo el sistema: el viaje
 * de un propietario no dice nada de los de otro.
 */
class RutaAutoCompleter
{
    public function timezone(): string
    {
        return config('rutero.auto_complete.timezone');
    }

    /** Hora de inicio en hora local, o null si la ruta no tiene fecha u hora. */
    public function startAt(Ruta $ruta): ?CarbonImmutable
    {
        if (! $ruta->trip_date || ! $ruta->trip_time) {
            return null;
        }

        return CarbonImmutable::parse($ruta->trip_date->format('Y-m-d') . ' ' . $ruta->trip_time, $this->timezone());
    }

    /** Minutos que se espera que dure el trayecto. */
    public function durationMinutes(Ruta $ruta, ?User $owner): int
    {
        if ($ruta->estimated_duration_min) {
            return (int) $ruta->estimated_duration_min;
        }

        if ((float) $ruta->estimated_distance_km > 0) {
            $speed = max(1, config('rutero.auto_complete.fallback_speed_kmh'));

            return (int) ceil((float) $ruta->estimated_distance_km / $speed * 60);
        }

        return (int) ($owner?->auto_complete_default_duration_min ?? config('rutero.auto_complete.default_duration_min'));
    }

    /**
     * Momento a partir del cual el cron cerrará la ruta si nada la cierra
     * antes (el encadenamiento puede adelantarlo). Las rutas sin hora se
     * cierran al final de su día.
     */
    public function estimatedEnd(Ruta $ruta, ?User $owner): ?CarbonImmutable
    {
        if (! $ruta->trip_date) {
            return null;
        }

        $start = $this->startAt($ruta);
        if (! $start) {
            return CarbonImmutable::parse($ruta->trip_date->format('Y-m-d') . ' 23:59:59', $this->timezone());
        }

        $d = $this->durationMinutes($ruta, $owner);
        $startDelay = (int) ($owner?->auto_complete_start_delay_min ?? config('rutero.auto_complete.start_delay_min'));
        $marginMin = (int) ($owner?->auto_complete_margin_min ?? config('rutero.auto_complete.margin_min'));
        $marginPct = (int) ($owner?->auto_complete_margin_pct ?? config('rutero.auto_complete.margin_pct'));

        return $start->addMinutes($startDelay + $d + (int) ceil($d * $marginPct / 100) + $marginMin);
    }

    /**
     * Hora prevista de cierre para mostrar al usuario ("se completará hacia
     * las ..."), o null si no aplica: ruta no pendiente, ya procesada por el
     * cron o propietario con el cierre automático desactivado.
     */
    public function etaFor(Ruta $ruta, ?User $owner): ?string
    {
        if ($ruta->status !== 'pending' || $ruta->auto_completed_at || ($owner && ! $owner->auto_complete_enabled)) {
            return null;
        }

        return $this->estimatedEnd($ruta, $owner)?->format('Y-m-d\TH:i');
    }

    public function shouldClose(Ruta $ruta, CarbonImmutable $now): bool
    {
        $end = $this->estimatedEnd($ruta, $ruta->owner);
        if ($end && $end->lessThanOrEqualTo($now)) {
            return true;
        }

        $start = $this->startAt($ruta);
        if (! $start || $start->greaterThan($now)) {
            return false;
        }

        return $this->nextTripStarted($ruta, $start, $now);
    }

    /** ¿Ha empezado ya otro viaje posterior de la misma unidad de trabajo? */
    private function nextTripStarted(Ruta $ruta, CarbonImmutable $start, CarbonImmutable $now): bool
    {
        $query = Ruta::query()
            ->where('id', '!=', $ruta->id)
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('trip_time')
            ->whereDate('trip_date', '>=', $start->format('Y-m-d'))
            ->whereDate('trip_date', '<=', $now->format('Y-m-d'));

        if ($ruta->driver_id) {
            $query->where('driver_id', $ruta->driver_id);
        } elseif ($ruta->vehicle_id) {
            $query->where('vehicle_id', $ruta->vehicle_id);
        } else {
            $query->where('owner_id', $ruta->owner_id)->whereNull('driver_id')->whereNull('vehicle_id');
        }

        foreach ($query->get(['id', 'trip_date', 'trip_time']) as $other) {
            $otherStart = $this->startAt($other);
            if ($otherStart && $otherStart->greaterThan($start) && $otherStart->lessThanOrEqualTo($now)) {
                return true;
            }
        }

        return false;
    }

    /** Cierra la ruta con los valores por defecto sin pisar lo que ya hubiera. */
    public function close(Ruta $ruta): void
    {
        $ruta->status = 'completed';
        $ruta->payment_method ??= 'efectivo';
        $ruta->final_price ??= $ruta->estimated_price;
        $ruta->auto_completed = true;
        $ruta->auto_completed_at = now();
        $ruta->reviewed_at = null;
        $ruta->save();
    }

    /** Recorre las rutas pendientes y cierra las que corresponda. Devuelve cuántas cerró. */
    public function run(?CarbonImmutable $now = null): int
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone($this->timezone());
        $closed = 0;

        Ruta::query()
            ->where('status', 'pending')
            ->whereNull('auto_completed_at')
            ->whereNotNull('trip_date')
            ->whereDate('trip_date', '<=', $now->format('Y-m-d'))
            ->whereHas('owner', fn ($q) => $q->where('auto_complete_enabled', true))
            ->with('owner')
            ->chunkById(200, function ($rutas) use ($now, &$closed) {
                foreach ($rutas as $ruta) {
                    if ($this->shouldClose($ruta, $now)) {
                        $this->close($ruta);
                        $closed++;
                    }
                }
            });

        return $closed;
    }
}
