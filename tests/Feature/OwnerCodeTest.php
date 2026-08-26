<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_month_code_and_driver_joins_and_creates_ruta()
    {
        $ownerPayload = [
            'first_name' => 'Owner',
            'last_name' => 'Codes',
            'avatar' => 'http://example.com/a.png',
            'email' => 'ownercodes@example.test',
            'password' => 'Secret123!',
        ];
        $resp = $this->postJson('/api/register', $ownerPayload);
        $token = $resp->json('access_token');
        $ownerId = $resp->json('user.id');

        // Owner mode only activates once the user has a vehicle.
        $vehicle = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->postJson('/api/vehicles', ['plate' => 'CODE-001']);
        $vehicle->assertStatus(201);

        $month = date('Y-m');
        $create = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->postJson('/api/owner/codes', ['month' => $month]);
        $create->assertStatus(201)->assertJsonStructure(['id','owner_id','code','month']);
        $code = $create->json('code');

        // register driver
        $driverPayload = [
            'first_name' => 'Driver',
            'last_name' => 'Codes',
            'avatar' => 'http://example.com/b.png',
            'email' => 'drivercodes@example.test',
            'password' => 'Secret123!',
        ];
        $dreg = $this->postJson('/api/register', $driverPayload);
        $dtoken = $dreg->json('access_token');

        // Sanctum's RequestGuard caches the first-resolved user for the
        // lifetime of the test's container, so switching bearer tokens
        // between users within one test requires forgetting guards first.
        $this->app['auth']->forgetGuards();

        $join = $this->withHeaders(['Authorization' => 'Bearer ' . $dtoken])->postJson('/api/driver/join-code', ['code' => $code, 'month' => $month]);
        $join->assertStatus(200)->assertJson(['message' => 'Joined']);

        $this->app['auth']->forgetGuards();

        // Driving for the owner they just joined creates a ruta attributed to that owner.
        $ruta = $this->withHeaders(['Authorization' => 'Bearer ' . $dtoken])->postJson('/api/rutas', [
            'client_name' => 'Cliente',
            'origin' => 'Origen A',
            'destination' => 'Destino B',
            'trip_date' => date('Y-m-d'),
            'owner_id' => $ownerId,
        ]);
        $ruta->assertStatus(201)
            ->assertJsonPath('owner_id', $ownerId)
            ->assertJsonPath('driver_id', $dreg->json('user.id'));
    }

    public function test_driver_can_delete_a_ruta_they_created_but_not_one_the_owner_assigned_them()
    {
        $ownerPayload = [
            'first_name' => 'Owner', 'last_name' => 'Del',
            'email' => 'ownerdel2@example.test', 'password' => 'Secret123!',
        ];
        $oreg = $this->postJson('/api/register', $ownerPayload);
        $otoken = $oreg->json('access_token');
        $ownerId = $oreg->json('user.id');

        $this->withHeaders(['Authorization' => 'Bearer ' . $otoken])->postJson('/api/vehicles', ['plate' => 'DELX-001'])->assertStatus(201);
        $month = date('Y-m');
        $code = $this->withHeaders(['Authorization' => 'Bearer ' . $otoken])->postJson('/api/owner/codes', ['month' => $month])->json('code');

        $dreg = $this->postJson('/api/register', [
            'first_name' => 'Driver', 'last_name' => 'Del',
            'email' => 'driverdel2@example.test', 'password' => 'Secret123!',
        ]);
        $dtoken = $dreg->json('access_token');
        $driverId = $dreg->json('user.id');

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $dtoken])->postJson('/api/driver/join-code', ['code' => $code, 'month' => $month])->assertStatus(200);

        $this->app['auth']->forgetGuards();
        // Ruta the driver creates themselves while driving for the owner.
        $ownRuta = $this->withHeaders(['Authorization' => 'Bearer ' . $dtoken])->postJson('/api/rutas', [
            'client_name' => 'Cliente', 'origin' => 'Origen', 'destination' => 'Destino', 'owner_id' => $ownerId,
        ]);
        $ownRuta->assertStatus(201);

        $this->app['auth']->forgetGuards();
        // Ruta the owner creates and assigns to the driver directly.
        $assignedRuta = $this->withHeaders(['Authorization' => 'Bearer ' . $otoken])->postJson('/api/rutas', [
            'client_name' => 'Cliente 2', 'origin' => 'Origen', 'destination' => 'Destino', 'driver_id' => $driverId,
        ]);
        $assignedRuta->assertStatus(201);

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $dtoken])
            ->deleteJson('/api/rutas/' . $ownRuta->json('id'))
            ->assertStatus(200);

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $dtoken])
            ->deleteJson('/api/rutas/' . $assignedRuta->json('id'))
            ->assertStatus(403);
    }

    public function test_owner_code_rejects_invalid_month_format()
    {
        $resp = $this->postJson('/api/register', [
            'first_name' => 'Owner', 'last_name' => 'Bad',
            'email' => 'ownerbad@example.test', 'password' => 'Secret123!',
        ]);
        $token = $resp->json('access_token');
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])->postJson('/api/vehicles', ['plate' => 'BAD-001'])->assertStatus(201);

        $create = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->postJson('/api/owner/codes', ['month' => 'not-a-month']);
        $create->assertStatus(422)->assertJsonValidationErrors(['month']);
    }

    public function test_driver_join_rejects_malformed_code()
    {
        $resp = $this->postJson('/api/register', [
            'first_name' => 'Driver', 'last_name' => 'Bad',
            'email' => 'driverbad@example.test', 'password' => 'Secret123!',
        ]);
        $token = $resp->json('access_token');

        $join = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/driver/join-code', ['code' => 'short', 'month' => date('Y-m')]);
        $join->assertStatus(422)->assertJsonValidationErrors(['code']);
    }
}
