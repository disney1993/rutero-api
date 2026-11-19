<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de viajes</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        h2 { font-size: 14px; margin: 18px 0 8px; }
        .meta { margin-bottom: 12px; }
        .meta p { margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        th { background: #f3f3f3; }
        .counters td:first-child { width: 40%; }
        .right { text-align: right; }
        .totals { margin-top: 12px; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Reporte de viajes</h1>
    <div class="meta">
        <p><strong>Usuario:</strong> {{ trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email }}</p>
        <p><strong>Email:</strong> {{ $user->email }}</p>
        <p><strong>Periodo:</strong> {{ $period }}</p>
        <p><strong>Desde:</strong> {{ $from }} — <strong>Hasta:</strong> {{ $to }}</p>
    </div>

    <h2>Contadores</h2>
    <table class="counters">
        <tr><td>Viajes totales</td><td class="right">{{ $counters['trips_total'] }}</td></tr>
        <tr><td>Completados</td><td class="right">{{ $counters['trips_completed'] }}</td></tr>
        <tr><td>Cancelados</td><td class="right">{{ $counters['trips_cancelled'] }}</td></tr>
        <tr><td>Pendientes</td><td class="right">{{ $counters['trips_pending'] }}</td></tr>
        <tr><td>Ingresos</td><td class="right">{{ number_format($counters['revenue'], 2, ',', '.') }}</td></tr>
        <tr><td>Ticket medio</td><td class="right">{{ number_format($counters['avg_ticket'], 2, ',', '.') }}</td></tr>
        <tr><td>% completados</td><td class="right">{{ number_format($counters['completion_rate'], 2, ',', '.') }}%</td></tr>
    </table>

    <h2>Viajes del periodo</h2>
    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Hora</th>
                <th>Cliente</th>
                <th>Trayecto</th>
                <th>Estado</th>
                <th>Precio</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($trips as $trip)
                <tr>
                    <td>{{ $trip->trip_date?->format('Y-m-d') }}</td>
                    <td>{{ $trip->trip_time }}</td>
                    <td>{{ $trip->client_name }}</td>
                    <td>{{ $trip->origin }} → {{ $trip->destination }}</td>
                    <td>{{ $trip->status }}</td>
                    <td class="right">
                        @if ($trip->status === 'completed')
                            {{ number_format((float) $trip->final_price, 2, ',', '.') }}
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">Sin viajes en este periodo.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <p class="totals">Total ingresos: {{ number_format($counters['revenue'], 2, ',', '.') }}</p>
</body>
</html>
