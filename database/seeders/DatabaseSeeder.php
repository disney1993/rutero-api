<?php

namespace Database\Seeders;

use App\Models\DriverCodeEntry;
use App\Models\OwnerMonthCode;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@example.test'],
            [
                'first_name' => 'Admin',
                'last_name' => 'Test',
                'name' => 'Admin Test',
                'avatar' => 'http://example.com/admin.png',
                'password' => 'secret123',
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
                'password' => 'secret123',
                'role' => 'user',
                'price_per_km' => 0.75,
            ]
        );

        Vehicle::updateOrCreate(
            ['plate' => 'TEST-001'],
            [
                'owner_id' => $owner->id,
                'make' => 'Toyota',
                'model' => 'Corolla',
                'color' => 'Blanco',
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
                'password' => 'secret123',
                'role' => 'user',
                'price_per_km' => 0.75,
            ]
        );

        $month = date('Y-m');
        $ownerCode = OwnerMonthCode::updateOrCreate(
            ['owner_id' => $owner->id, 'month' => $month],
            ['code' => 'OWNER001']
        );

        DriverCodeEntry::firstOrCreate([
            'owner_month_code_id' => $ownerCode->id,
            'driver_id' => $driver->id,
        ]);

        $this->call(DemoDataSeeder::class);
    }
}
