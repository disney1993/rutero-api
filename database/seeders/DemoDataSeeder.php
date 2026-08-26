<?php

namespace Database\Seeders;

use App\Models\Ruta;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AvatarPalette;
use Illuminate\Database\Seeder;

// Genera coches y rutas de ejemplo. Pensado para correr sobre una base
// limpia (`php artisan migrate:fresh --seed`): las cuentas fijas
// (admin/owner/driver) no duplican datos si ya los tienen, pero los
// usuarios de relleno para probar el dashboard sí se crean de nuevo en
// cada ejecución (igual que el scaffolding por defecto de Laravel).
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::where('email', 'owner@example.test')->first();
        $driver = User::where('email', 'driver@example.test')->first();

        if ($owner && Vehicle::where('owner_id', $owner->id)->count() <= 1) {
            Vehicle::factory()->count(3)->create(['owner_id' => $owner->id]);
        }

        if ($owner && Ruta::where('owner_id', $owner->id)->count() === 0) {
            $this->seedRutasForOwner($owner, $driver);
        }

        // Usuarios de relleno para poder probar el dashboard de admin con
        // datos reales: búsqueda, scroll de listas, paginación visual, etc.
        User::factory()->count(20)->create()->each(function (User $user) {
            $firstName = fake()->firstName();
            $lastName = fake()->lastName();
            $user->forceFill([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => "{$firstName} {$lastName}",
                'avatar_color' => AvatarPalette::random(),
                'price_per_km' => fake()->randomFloat(2, 0.9, 1.5),
            ])->save();

            // La mayoría son "propietarios" (tienen coche); unos pocos se
            // quedan sin flota para poder ver también ese caso en el admin.
            $hasFleet = fake()->boolean(80);
            if (! $hasFleet) {
                return;
            }

            $vehicles = Vehicle::factory()->count(rand(1, 3))->create(['owner_id' => $user->id]);

            for ($i = 0; $i < rand(8, 15); $i++) {
                Ruta::factory()->create([
                    'owner_id' => $user->id,
                    'created_by' => $user->id,
                    'vehicle_id' => $vehicles->random()->id,
                ]);
            }
        });
    }

    private function seedRutasForOwner(User $owner, ?User $driver): void
    {
        $vehicles = Vehicle::where('owner_id', $owner->id)->get();

        // Rutas propias del owner.
        for ($i = 0; $i < 20; $i++) {
            Ruta::factory()->create([
                'owner_id' => $owner->id,
                'created_by' => $owner->id,
                'vehicle_id' => $vehicles->isNotEmpty() ? $vehicles->random()->id : null,
            ]);
        }

        if (! $driver) {
            return;
        }

        // Un par de rutas que el owner asigna directamente al driver.
        for ($i = 0; $i < 3; $i++) {
            Ruta::factory()->create([
                'owner_id' => $owner->id,
                'driver_id' => $driver->id,
                'created_by' => $owner->id,
                'vehicle_id' => $vehicles->isNotEmpty() ? $vehicles->random()->id : null,
            ]);
        }

        // Un par que el propio driver crea mientras conduce para el owner
        // (estas sí puede borrarlas él mismo; las anteriores no).
        for ($i = 0; $i < 3; $i++) {
            Ruta::factory()->create([
                'owner_id' => $owner->id,
                'driver_id' => $driver->id,
                'created_by' => $driver->id,
                'vehicle_id' => $vehicles->isNotEmpty() ? $vehicles->random()->id : null,
            ]);
        }
    }
}
