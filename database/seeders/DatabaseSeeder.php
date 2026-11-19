<?php

namespace Database\Seeders;

use App\Models\OwnerDriver;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Se resuelven antes de crear nada: si faltan en producción, el seeder
        // falla sin haber escrito ninguna fila.
        $adminPassword = $this->password('admin_password', 'SEED_ADMIN_PASSWORD');
        $demoPassword = $this->password('demo_password', 'SEED_DEMO_PASSWORD');

        User::updateOrCreate(
            ['email' => 'admin@example.test'],
            [
                'first_name' => 'Admin',
                'last_name' => 'Test',
                'name' => 'Admin Test',
                'avatar' => 'http://example.com/admin.png',
                'password' => $adminPassword,
                'role' => 'admin',
                'price_per_km' => 0.75,
            ]
        );

        $owner = User::updateOrCreate(
            ['email' => 'owner@example.test'],
            [
                'first_name' => 'Owner',
                'last_name' => 'Test',
                'name' => 'Owner Test',
                'avatar' => 'http://example.com/owner.png',
                'password' => $demoPassword,
                'role' => 'user',
                'price_per_km' => 1.25,
            ]
        );

        Vehicle::updateOrCreate(
            ['plate' => 'TEST001'],
            [
                'owner_id' => $owner->id,
                'make' => 'Toyota',
                'model' => 'Corolla',
                'color' => '#FFFFFF',
                'year' => 2020,
                'seats' => 4,
                'vehicle_type' => 'sedan',
            ]
        );

        $driver = User::updateOrCreate(
            ['email' => 'driver@example.test'],
            [
                'first_name' => 'Driver',
                'last_name' => 'Test',
                'name' => 'Driver Test',
                'avatar' => 'http://example.com/driver.png',
                'password' => $demoPassword,
                'role' => 'user',
                'price_per_km' => 1.25,
            ]
        );

        // El conductor demo forma parte del equipo del propietario demo desde
        // principios de año, para que vea todo el historial de demo.
        OwnerDriver::activate($owner->id, $driver, OwnerDriver::VIA_EMAIL)
            ->forceFill(['accepted_at' => now()->startOfYear()])->save();

        // Los correos @example.test no existen: sin esto, las cuentas de demo
        // nunca podrían confirmar el email ni recibir invitaciones por email.
        User::whereIn('email', ['admin@example.test', 'owner@example.test', 'driver@example.test'])
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);

        $this->call(DemoDataSeeder::class);
    }

    // En local, la contraseña de siempre (secret123) para entrar rápido.
    // En producción, la de la variable de entorno: nunca una del repositorio.
    private function password(string $key, string $envName): string
    {
        if (! app()->isProduction()) {
            return 'secret123';
        }

        $password = (string) config("rutero.seed.{$key}");
        if (strlen($password) < 12) {
            throw new RuntimeException(
                "Define {$envName} (mínimo 12 caracteres) en las variables de entorno antes de ejecutar los seeders en producción."
            );
        }

        return $password;
    }
}
