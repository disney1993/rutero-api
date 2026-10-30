<?php

namespace Database\Seeders;

use App\Models\DriverCodeEntry;
use App\Models\OwnerMonthCode;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AvatarPalette;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Datos de demo realistas para todo el año en curso: coches, jornadas
// (códigos de día con los que el conductor trabaja para el propietario) y
// viajes de taxi/VTC por Madrid, de 0 a 10 al día.
//
// Pensado para una base limpia: `php artisan migrate:fresh --seed`. Si el
// propietario de prueba ya tiene viajes no se le generan más, pero los
// usuarios de relleno del dashboard de admin se crean en cada ejecución.
//
// Cómo se reparte la actividad:
//  - Días pasados: jornada completa. Viernes y sábados más cargados, lunes
//    más flojos; algunos domingos, festivos, días sueltos y dos semanas de
//    agosto sin trabajar; algo más de faena en diciembre.
//  - Hoy: lo que ya ha pasado está completado y lo que queda, pendiente.
//  - Futuro: solo reservas pendientes, cada vez menos cuanto más lejos.
//  - Los viajes de un día van seguidos dentro de un turno (mañana o tarde),
//    sin solaparse: cada uno empieza después de terminar el anterior.
class DemoDataSeeder extends Seeder
{
    // Puntos habituales de un taxi en Madrid: [nombre, lat, lng, peso].
    // El peso hace que aeropuerto, estaciones y centro salgan más a menudo.
    private const PLACES = [
        ['Aeropuerto Adolfo Suárez Madrid-Barajas T4', 40.4919, -3.5936, 6],
        ['Aeropuerto Adolfo Suárez Madrid-Barajas T1', 40.4677, -3.5706, 3],
        ['Estación de Atocha', 40.4066, -3.6892, 6],
        ['Estación de Chamartín', 40.4721, -3.6825, 4],
        ['Puerta del Sol', 40.4169, -3.7035, 5],
        ['Plaza Mayor', 40.4155, -3.7074, 3],
        ['Gran Vía 32', 40.4200, -3.7055, 5],
        ['Calle de Serrano 50', 40.4290, -3.6863, 4],
        ['Estadio Santiago Bernabéu', 40.4531, -3.6883, 3],
        ['IFEMA Madrid', 40.4636, -3.6163, 3],
        ['Puerta de Alcalá', 40.4200, -3.6887, 2],
        ['Hospital Universitario La Paz', 40.4808, -3.6870, 3],
        ['Hospital 12 de Octubre', 40.3753, -3.6983, 3],
        ['Moncloa', 40.4350, -3.7190, 3],
        ['Plaza de Castilla', 40.4669, -3.6894, 3],
        ['Nuevos Ministerios', 40.4460, -3.6918, 3],
        ['Plaza del Dos de Mayo (Malasaña)', 40.4268, -3.7046, 2],
        ['Lavapiés', 40.4087, -3.7010, 2],
        ['Estación Sur de Autobuses (Méndez Álvaro)', 40.3953, -3.6787, 2],
        ['Príncipe Pío', 40.4212, -3.7206, 2],
        ['Ciudad Universitaria', 40.4446, -3.7270, 2],
        ['Las Tablas', 40.5047, -3.6705, 2],
        ['Pozuelo de Alarcón', 40.4350, -3.8137, 2],
        ['Alcobendas', 40.5475, -3.6420, 2],
        ['Getafe', 40.3057, -3.7329, 1],
        ['Las Rozas Village', 40.5114, -3.8932, 1],
        ['Alcalá de Henares', 40.4818, -3.3643, 1],
    ];

    // Viajes largos ocasionales fuera de Madrid.
    private const LONG_TRIPS = [
        ['Toledo', 39.8628, -4.0273],
        ['Segovia', 40.9429, -4.1088],
        ['Ávila', 40.6565, -4.6818],
        ['Guadalajara', 40.6328, -3.1668],
        ['Aranjuez', 40.0311, -3.6026],
    ];

    // Festivos nacionales (MM-DD) en los que el propietario no trabaja.
    private const HOLIDAYS = ['01-01', '01-06', '05-01', '08-15', '10-12', '11-01', '12-06', '12-08', '12-25'];

    // Viajes base por día de la semana (0 = domingo … 6 = sábado).
    private const BASE_BY_WEEKDAY = [2, 4, 5, 5, 6, 8, 7];

    private const NOTES = [
        'Lleva maleta grande',
        'Necesita silla de bebé',
        'Recoger en la puerta principal',
        'Avisar por WhatsApp al llegar',
        'Cliente con movilidad reducida',
        'Viaje de empresa, pide factura',
        'Va con mascota pequeña',
    ];

    private const PAYMENT_WEIGHTS = ['tarjeta' => 45, 'efectivo' => 30, 'bizum' => 20, 'no_pagado' => 5];

    private const PASSENGER_WEIGHTS = [1 => 55, 2 => 25, 3 => 12, 4 => 8];

    // Colores de flota habituales (hex de la paleta de la app): blanco, negro, gris, plata, azul, rojo.
    private const CAR_COLORS = ['#FFFFFF', '#1C1C1C', '#9E9E9E', '#C7C7C7', '#1565C0', '#D32F2F'];

    private CarbonImmutable $now;

    private int $totalPlaceWeight;

    /** @var array<int, array{name: string, phone: string}> */
    private array $regularClients = [];

    /** @var array<string, true> */
    private array $usedCodes = [];

    /** @var array<int, array<string, mixed>> */
    private array $buffer = [];

    public function run(): void
    {
        $this->now = CarbonImmutable::now();
        $this->totalPlaceWeight = array_sum(array_column(self::PLACES, 3));
        $faker = fake('es_ES');
        for ($i = 0; $i < 40; $i++) {
            $this->regularClients[] = ['name' => $faker->name(), 'phone' => $faker->numerify('6########')];
        }
        $this->usedCodes = OwnerMonthCode::pluck('code')->flip()->map(fn () => true)->all();

        $owner = User::where('email', 'owner@example.test')->first();
        $driver = User::where('email', 'driver@example.test')->first();

        if ($owner) {
            $this->ensureFleet($owner, 3);
            if (! DB::table('rutas')->where('owner_id', $owner->id)->exists()) {
                $this->seedYear($owner, $driver, intensity: 1.0, activeFrom: $this->yearStart());
            }
        }

        $this->seedFillerUsers($faker);

        $this->flush();
        $this->recordCompletions();
    }

    // Usuarios de relleno para probar el dashboard de admin con datos reales.
    // Cada uno trabaja con distinta intensidad y algunos se dieron de alta a
    // mitad de año, para que las estadísticas no salgan todas iguales.
    private function seedFillerUsers($faker): void
    {
        User::factory()->count(20)->create()->each(function (User $user) use ($faker) {
            $firstName = $faker->firstName();
            $lastName = $faker->lastName();
            $user->forceFill([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => "{$firstName} {$lastName}",
                'avatar_color' => AvatarPalette::random(),
                'price_per_km' => $faker->randomFloat(2, 1.05, 1.45),
                // La factory les pone "password"; en producción, una aleatoria
                // que nadie conoce (son cuentas de relleno, no para entrar).
                ...(app()->isProduction() ? ['password' => Str::random(40)] : []),
            ])->save();

            // Unos pocos se quedan sin flota para ver también ese caso.
            if (! $faker->boolean(80)) {
                return;
            }

            $this->ensureFleet($user, rand(1, 3));
            $activeFrom = $faker->boolean(70)
                ? $this->yearStart()
                : $this->yearStart()->addDays(rand(30, max(31, $this->now->dayOfYear - 14)));

            $this->seedYear($user, null, intensity: $faker->randomFloat(2, 0.3, 0.9), activeFrom: $activeFrom);
        });
    }

    private function ensureFleet(User $owner, int $count): void
    {
        $missing = $count - Vehicle::where('owner_id', $owner->id)->count();
        if ($missing > 0) {
            Vehicle::factory()->count($missing)->create([
                'owner_id' => $owner->id,
                'color' => fn () => self::CAR_COLORS[array_rand(self::CAR_COLORS)],
            ]);
        }
    }

    private function seedYear(User $owner, ?User $driver, float $intensity, CarbonImmutable $activeFrom): void
    {
        $vehicles = Vehicle::where('owner_id', $owner->id)->orderByDesc('is_default')->pluck('id')->all();
        if (! $vehicles) {
            return;
        }
        $pricePerKm = (float) ($owner->price_per_km ?: 1.2);

        // Dos semanas de vacaciones en agosto, distintas para cada usuario.
        $vacationStart = CarbonImmutable::create($this->now->year, 8, rand(1, 17));
        $vacationEnd = $vacationStart->addDays(13);

        for ($day = $activeFrom; $day->year === $this->now->year; $day = $day->addDay()) {
            $count = $this->tripsForDay($day, $intensity, $vacationStart, $vacationEnd);
            if ($count === 0) {
                continue;
            }

            // Jornadas con conductor: ~2 días por semana (sobre todo martes y
            // sábado) el conductor trabaja para el propietario con un código
            // del día y se encarga de la mitad de los viajes.
            $driverToday = $driver && $this->isDriverDay($day) ? $driver : null;
            if ($driverToday) {
                $this->createJornada($owner, $driverToday, $day);
            }

            $this->seedDay($owner, $driverToday, $vehicles, $pricePerKm, $day, $count);
        }
    }

    private function tripsForDay(CarbonImmutable $day, float $intensity, CarbonImmutable $vacationStart, CarbonImmutable $vacationEnd): int
    {
        if (in_array($day->format('m-d'), self::HOLIDAYS, true) || $day->betweenIncluded($vacationStart, $vacationEnd)) {
            return 0;
        }
        if ($day->isSunday() && rand(1, 100) <= 60) {
            return 0;
        }
        if (rand(1, 100) <= 7) {
            return 0; // Día libre suelto.
        }

        $base = self::BASE_BY_WEEKDAY[$day->dayOfWeek];
        if ($day->month === 12) {
            $base += 1;
        } elseif (in_array($day->month, [7, 8], true)) {
            $base -= 1;
        }
        $count = (int) round($base * $intensity + $this->noise());

        // Futuro: solo lo ya reservado, menos cuanto más lejos.
        $daysAhead = $this->now->startOfDay()->diffInDays($day->startOfDay(), false);
        if ($daysAhead > 0) {
            $share = match (true) {
                $daysAhead <= 7 => 0.55,
                $daysAhead <= 30 => 0.2,
                default => 0.06,
            };
            $count = (int) round($count * $share + (rand(0, 100) / 100 - 0.5));
        }

        return max(0, min(10, $count));
    }

    // Ruido aproximadamente normal (media 0, desviación ~1.2).
    private function noise(): float
    {
        return (rand(0, 1000) + rand(0, 1000) + rand(0, 1000) - 1500) / 600;
    }

    private function isDriverDay(CarbonImmutable $day): bool
    {
        $chance = match ($day->dayOfWeek) {
            2, 6 => 70,
            4 => 25,
            default => 5,
        };

        return rand(1, 100) <= $chance;
    }

    private function createJornada(User $owner, User $driver, CarbonImmutable $day): void
    {
        $code = OwnerMonthCode::firstOrCreate(
            ['owner_id' => $owner->id, 'type' => OwnerMonthCode::TYPE_DAY, 'scope_key' => $day->toDateString()],
            ['code' => $this->uniqueCode(), 'month' => $day->format('Y-m'), 'valid_on' => $day->toDateString()]
        );

        DriverCodeEntry::firstOrCreate(['owner_month_code_id' => $code->id, 'driver_id' => $driver->id]);
    }

    private function uniqueCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (isset($this->usedCodes[$code]) || ! preg_match('/^[A-Z0-9]{8}$/', $code));
        $this->usedCodes[$code] = true;

        return $code;
    }

    private function seedDay(User $owner, ?User $driver, array $vehicles, float $pricePerKm, CarbonImmutable $day, int $count): void
    {
        // Turno de mañana (más habitual) o de tarde.
        $morning = rand(1, 100) <= 70;
        $cursor = $day->setTime($morning ? rand(6, 8) : rand(13, 15), [0, 15, 30, 45][rand(0, 3)]);
        $shiftEnd = $day->setTime(23, 30);

        // El conductor usa un coche que no es el predeterminado del propietario.
        $driverVehicle = count($vehicles) > 1 ? $vehicles[array_rand(array_slice($vehicles, 1, null, true))] : $vehicles[0];

        for ($i = 0; $i < $count; $i++) {
            $start = $cursor->addMinutes(rand(0, 50));
            $start = $start->setTime($start->hour, intdiv($start->minute, 5) * 5);
            if ($start->greaterThan($shiftEnd)) {
                break;
            }

            $byDriver = $driver && rand(1, 100) <= 50;
            $vehicleId = $byDriver
                ? $driverVehicle
                : (rand(1, 100) <= 80 ? $vehicles[0] : $vehicles[array_rand($vehicles)]);

            $trip = $this->makeTrip($owner, $byDriver ? $driver : null, $vehicleId, $pricePerKm, $start);
            $this->buffer[] = $trip;
            if (count($this->buffer) >= 500) {
                $this->flush();
            }

            $cursor = $start->addMinutes($trip['estimated_duration_min'] + rand(5, 35));
        }
    }

    private function makeTrip(User $owner, ?User $driver, int $vehicleId, float $pricePerKm, CarbonImmutable $start): array
    {
        [$origin, $destination] = $this->pickRoute();
        $distanceKm = $this->roadKm($origin[1], $origin[2], $destination[1], $destination[2]);
        // Ciudad ~24 km/h; interurbano ~70 km/h; más el tiempo de recogida.
        $speed = $distanceKm < 20 ? 24 : 70;
        $durationMin = (int) max(8, round($distanceKm / $speed * 60) + 4);
        $end = $start->addMinutes($durationMin);
        $estimatedPrice = round($distanceKm * $pricePerKm, 2);

        $isFuture = $end->greaterThan($this->now);
        $status = $isFuture ? 'pending' : 'completed';
        $cancelled = rand(1, 100) <= 7;
        $actorId = $driver?->id ?? $owner->id;

        $autoCompleted = $status === 'completed' && ! $cancelled && rand(1, 100) <= 15;
        // Los cierres automáticos de los últimos días siguen "por revisar".
        $reviewed = $autoCompleted && $end->lessThan($this->now->subDays(3));

        $client = rand(1, 100) <= 35
            ? $this->regularClients[array_rand($this->regularClients)]
            : ['name' => fake('es_ES')->name(), 'phone' => fake('es_ES')->numerify('6########')];

        $isAirport = str_contains($origin[0], 'Aeropuerto') || str_contains($destination[0], 'Aeropuerto');
        $note = null;
        if ($isAirport && rand(1, 100) <= 40) {
            $note = 'Vuelo '.['IB', 'UX', 'VY', 'FR'][rand(0, 3)].rand(1000, 8999);
        } elseif (rand(1, 100) <= 10) {
            $note = self::NOTES[array_rand(self::NOTES)];
        }

        // Reservado entre unos minutos y tres días antes (nunca en el futuro).
        $createdAt = $start->subMinutes(rand(5, 72 * 60));
        if ($createdAt->greaterThan($this->now)) {
            $createdAt = $this->now->subMinutes(rand(1, 120));
        }
        $completed = $status === 'completed' && ! $cancelled;

        return [
            'owner_id' => $owner->id,
            'driver_id' => $driver?->id,
            // En jornada, el conductor apunta él mismo la mayoría de viajes.
            'created_by' => $driver && rand(1, 100) <= 60 ? $driver->id : $owner->id,
            'completed_by' => $completed ? $actorId : null,
            'cancelled_by' => $cancelled ? $actorId : null,
            'auto_completed' => $autoCompleted,
            'auto_completed_at' => $autoCompleted ? $end->addMinutes(10) : null,
            'reviewed_at' => $reviewed ? $end->addHours(rand(2, 30)) : null,
            'vehicle_id' => $vehicleId,
            'client_name' => $client['name'],
            'client_phone' => $client['phone'],
            'origin' => $this->placeLabel($origin),
            'origin_lat' => $origin[1],
            'origin_lng' => $origin[2],
            'destination' => $this->placeLabel($destination),
            'destination_lat' => $destination[1],
            'destination_lng' => $destination[2],
            'estimated_distance_km' => $distanceKm,
            'estimated_duration_min' => $durationMin,
            'price_per_km' => $pricePerKm,
            'estimated_price' => $estimatedPrice,
            // Precio final: el estimado con algo de tráfico o espera, redondeado a 0,50 €.
            'final_price' => $completed ? round($estimatedPrice * (rand(95, 115) / 100) * 2) / 2 : null,
            'payment_method' => $completed ? $this->weighted(self::PAYMENT_WEIGHTS) : null,
            'passenger_count' => $this->weighted(self::PASSENGER_WEIGHTS),
            'trip_date' => $start->toDateString(),
            'trip_time' => $start->format('H:i'),
            'status' => $cancelled ? 'cancelled' : $status,
            'hidden' => false,
            'notes' => $note,
            'created_at' => $createdAt,
            // Para los completados, updated_at = hora de llegada: de ahí sale
            // completed_at en ruta_completions (ver recordCompletions()).
            'updated_at' => $cancelled ? $createdAt->addMinutes(rand(5, 120)) : ($completed ? $end : $createdAt),
            'deleted_at' => $cancelled ? $createdAt->addMinutes(rand(5, 120)) : null,
        ];
    }

    /** @return array{0: array, 1: array} */
    private function pickRoute(): array
    {
        $origin = $this->weightedPlace();
        if (rand(1, 100) <= 3) {
            $destination = self::LONG_TRIPS[array_rand(self::LONG_TRIPS)];

            return rand(0, 1) ? [$origin, $destination] : [$destination, $origin];
        }
        do {
            $destination = $this->weightedPlace();
        } while ($destination[0] === $origin[0]);

        return [$origin, $destination];
    }

    // Los puntos de PLACES (con peso) están en Madrid; los LONG_TRIPS no.
    private function placeLabel(array $place): string
    {
        return count($place) > 3 ? $place[0].', Madrid' : $place[0].', España';
    }

    private function weightedPlace(): array
    {
        $roll = rand(1, $this->totalPlaceWeight);
        foreach (self::PLACES as $place) {
            $roll -= $place[3];
            if ($roll <= 0) {
                return $place;
            }
        }

        return self::PLACES[0];
    }

    private function weighted(array $weights): string|int
    {
        $roll = rand(1, array_sum($weights));
        foreach ($weights as $value => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $value;
            }
        }

        return array_key_first($weights);
    }

    // Distancia por carretera aproximada: línea recta +35% (en ciudad se
    // rodea mucho más que en autovía), con un mínimo de 1,5 km.
    private function roadKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return max(1.5, round(6371 * 2 * atan2(sqrt($a), sqrt(1 - $a)) * 1.35, 1));
    }

    private function yearStart(): CarbonImmutable
    {
        return $this->now->startOfYear();
    }

    private function flush(): void
    {
        if ($this->buffer) {
            DB::table('rutas')->insert($this->buffer);
            $this->buffer = [];
        }
    }

    // Una fila en ruta_completions por cada viaje completado, igual que hace
    // Ruta::recordCompletion() desde la API. Se hace con un solo INSERT …
    // SELECT porque los viajes se insertan en bloque y no conocemos sus ids.
    private function recordCompletions(): void
    {
        DB::table('ruta_completions')->insertUsing(
            ['ruta_id', 'user_id', 'completed_at', 'created_at', 'updated_at'],
            DB::table('rutas')
                ->select('id', 'completed_by', 'updated_at', 'updated_at', 'updated_at')
                ->whereNotNull('completed_by')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('ruta_completions')->whereColumn('ruta_completions.ruta_id', 'rutas.id'))
        );
    }
}
