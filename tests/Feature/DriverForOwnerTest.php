<?php

namespace Tests\Feature;

use App\Models\OwnerDriver;
use App\Models\Ruta;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Un conductor del equipo trabajando para el propietario (antes, con códigos de mes o día). */
class DriverForOwnerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $driver;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->vehicle = Vehicle::factory()->create(['owner_id' => $this->owner->id]);
        $this->driver = User::factory()->create();
    }

    private function as(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $this;
    }

    private function ruta(array $overrides = []): array
    {
        return array_merge([
            'client_name' => 'Cliente', 'client_phone' => '612345678', 'final_price' => 20, 'trip_time' => '10:00',
            'origin' => 'Origen', 'destination' => 'Destino', 'trip_date' => now()->toDateString(),
        ], $overrides);
    }

    public function test_a_team_driver_creates_a_trip_for_the_owner(): void
    {
        OwnerDriver::activate($this->owner->id, $this->driver);

        $this->as($this->driver)->postJson('/api/rutas', $this->ruta(['owner_id' => $this->owner->id]))
            ->assertCreated()
            ->assertJsonPath('owner_id', $this->owner->id)
            ->assertJsonPath('driver_id', $this->driver->id);
    }

    public function test_someone_outside_the_team_cannot_create_trips_for_the_owner(): void
    {
        $this->as($this->driver)->postJson('/api/rutas', $this->ruta(['owner_id' => $this->owner->id]))
            ->assertForbidden();

        $this->assertSame(0, Ruta::count());
    }

    public function test_driver_can_delete_a_trip_they_created_but_not_one_the_owner_assigned_them(): void
    {
        OwnerDriver::activate($this->owner->id, $this->driver);

        $own = $this->as($this->driver)->postJson('/api/rutas', $this->ruta(['owner_id' => $this->owner->id]))
            ->assertCreated()->json('id');
        $assigned = $this->as($this->owner)->postJson('/api/rutas', $this->ruta([
            'client_name' => 'Cliente 2', 'driver_id' => $this->driver->id, 'vehicle_id' => $this->vehicle->id,
        ]))->assertCreated()->json('id');

        $this->as($this->driver)->deleteJson("/api/rutas/{$own}")->assertOk();
        $this->as($this->driver)->deleteJson("/api/rutas/{$assigned}")->assertForbidden();
    }

    public function test_the_old_code_endpoints_no_longer_exist(): void
    {
        $this->as($this->owner)->getJson('/api/owner/codes')->assertNotFound();
        $this->as($this->driver)->getJson('/api/driver/my-codes')->assertNotFound();
    }
}
