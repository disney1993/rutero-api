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
            'plate' => strtoupper($this->faker->unique()->bothify('####-???')),
            'make' => $make,
            'model' => $model,
            'color' => $this->faker->safeColorName(),
            'year' => $this->faker->numberBetween(2012, 2024),
            'seats' => $this->faker->randomElement([4, 5, 7]),
            'vehicle_type' => $this->faker->randomElement(['sedan', 'suv', 'compacto', 'monovolumen']),
            'active' => true,
            'notes' => null,
        ];
    }
}
