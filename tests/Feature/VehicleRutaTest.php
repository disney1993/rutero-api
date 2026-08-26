<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleRutaTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_vehicle_and_ruta_and_prevent_delete()
    {
        $ownerPayload = [
            'first_name' => 'Owner',
            'last_name' => 'Veh',
            'avatar' => 'http://example.com/a.png',
            'email' => 'ownerveh@example.test',
            'password' => 'Secret123!',
        ];
        $resp = $this->postJson('/api/register', $ownerPayload);
        $token = $resp->json('access_token');

        // create vehicle
        $v = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->postJson('/api/vehicles', [
            'plate' => 'TEST-123', 'make' => 'Make', 'model' => 'Model'
        ]);
        $v->assertStatus(201);
        $vehicleId = $v->json('id');

        // create ruta using vehicle
        $ruta = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->postJson('/api/rutas', [
            'client_name' => 'Client', 'origin' => 'Origen A', 'destination' => 'Destino B', 'trip_date' => date('Y-m-d'), 'vehicle_id' => $vehicleId
        ]);
        $ruta->assertStatus(201)->assertJson(['vehicle_id' => $vehicleId]);

        // attempt to delete vehicle should fail
        $del = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->deleteJson('/api/vehicles/' . $vehicleId);
        $del->assertStatus(422);
    }
}
