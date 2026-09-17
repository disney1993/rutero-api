<?php

namespace Database\Factories;

use App\Models\Ruta;
use Illuminate\Database\Eloquent\Factories\Factory;

class RutaFactory extends Factory
{
    protected $model = Ruta::class;

    // Ciudades reales de España con coordenadas reales, para que la
    // funcionalidad de direcciones/distancia tenga datos de demo coherentes.
    private const CITIES = [
        'Madrid' => [40.4168, -3.7038],
        'Barcelona' => [41.3874, 2.1686],
        'Valencia' => [39.4699, -0.3763],
        'Sevilla' => [37.3891, -5.9845],
        'Zaragoza' => [41.6488, -0.8891],
        'Málaga' => [36.7213, -4.4213],
        'Bilbao' => [43.2630, -2.9350],
        'Toledo' => [39.8628, -4.0273],
        'Alicante' => [38.3452, -0.4810],
        'Valladolid' => [41.6523, -4.7245],
        'Córdoba' => [37.8882, -4.7794],
        'Segovia' => [40.9429, -4.1088],
        'Granada' => [37.1773, -3.5986],
        'Murcia' => [37.9834, -1.1299],
        'Santander' => [43.4623, -3.8099],
        'San Sebastián' => [43.3183, -1.9812],
    ];

    // Minutos "reales": no todas las rutas son en punto.
    private const MINUTES = [0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55];

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        // +25% para aproximar distancia real por carretera frente a línea recta.
        return round($earthRadius * $c * 1.25, 1);
    }

    public function definition(): array
    {
        $cityNames = array_keys(self::CITIES);
        $originName = $this->faker->randomElement($cityNames);
        $destinationName = $this->faker->randomElement(array_diff($cityNames, [$originName]));
        [$originLat, $originLng] = self::CITIES[$originName];
        [$destLat, $destLng] = self::CITIES[$destinationName];

        $distanceKm = $this->haversineKm($originLat, $originLng, $destLat, $destLng);
        $pricePerKm = $this->faker->randomFloat(2, 0.9, 1.6);
        $estimatedPrice = round($distanceKm * $pricePerKm, 2);

        $status = $this->faker->randomElement([
            'completed', 'completed', 'completed', 'pending', 'pending', 'cancelled',
        ]);

        return [
            'client_name' => $this->faker->name(),
            'client_phone' => $this->faker->numerify('6########'),
            'origin' => $originName . ', España',
            'origin_lat' => $originLat,
            'origin_lng' => $originLng,
            'destination' => $destinationName . ', España',
            'destination_lat' => $destLat,
            'destination_lng' => $destLng,
            'estimated_distance_km' => $distanceKm,
            'price_per_km' => $pricePerKm,
            'estimated_price' => $estimatedPrice,
            'final_price' => $status === 'completed' ? round($estimatedPrice / 5) * 5 : null,
            'payment_method' => $status === 'completed' ? $this->faker->randomElement(['efectivo', 'tarjeta', 'transferencia']) : null,
            'passenger_count' => $this->faker->numberBetween(1, 4),
            'trip_date' => $this->faker->dateTimeBetween('-7 days', '+10 days')->format('Y-m-d'),
            'trip_time' => sprintf('%02d:%02d', $this->faker->numberBetween(6, 22), $this->faker->randomElement(self::MINUTES)),
            'status' => $status,
            'hidden' => false,
            'notes' => $this->faker->boolean(30) ? $this->faker->sentence() : null,
        ];
    }
}
