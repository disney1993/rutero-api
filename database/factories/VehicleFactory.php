<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    private const CATALOG = [
        ['SEAT', 'León'], ['SEAT', 'Ibiza'], ['Renault', 'Clio'],
        ['Toyota', 'Corolla'], ['Volkswagen', 'Golf'], ['Peugeot', '208'],
        ['Dacia', 'Sandero'], ['Ford', 'Focus'], ['Opel', 'Corsa'],
        ['Hyundai', 'Tucson'], ['Citroën', 'C3'], ['Skoda', 'Octavia'],
    ];

    public function definition(): array
    {
        [$make, $model] = $this->faker->randomElement(self::CATALOG);

        return [
            'owner_id' => User::factory(),
            'plate' => strtoupper($this->faker->unique()->bothify('####???')),
            'make' => $make,
            'model' => $model,
            // Hex de la paleta de la app (carColors.js): blanco, negro, gris,
            // plata, azul, rojo, verde y beige.
            'color' => $this->faker->randomElement(['#FFFFFF', '#1C1C1C', '#9E9E9E', '#C7C7C7', '#1565C0', '#D32F2F', '#2E7D32', '#D7CCC8']),
            'year' => $this->faker->numberBetween(2012, 2024),
            'seats' => $this->faker->randomElement([4, 5, 7]),
            'vehicle_type' => $this->faker->randomElement(['sedan', 'suv', 'compacto', 'monovolumen']),
            'notes' => null,
        ];
    }
}
