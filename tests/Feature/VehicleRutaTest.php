<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Vehicle;
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
        $v = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->postJson('/api/vehicles', ['make' => 'Seat', 'model' => 'Ibiza', 'color' => 'Blanco', 'year' => 2020, 'seats' => 5, 
            'plate' => 'TEST-123', 'vehicle_type' => 'sedan'
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

    public function test_vehicle_type_is_required_and_must_be_known()
    {
        $token = $this->postJson('/api/register', [
            'first_name' => 'Owner', 'last_name' => 'Tipo',
            'email' => 'ownertipo@example.test', 'password' => 'Secret123!',
        ])->json('access_token');
        $auth = ['Authorization' => 'Bearer ' . $token];

        // Todo es obligatorio salvo las notas.
        $this->withHeaders($auth)->postJson('/api/vehicles', ['notes' => 'Solo notas'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plate', 'make', 'model', 'color', 'year', 'seats', 'vehicle_type'])
            ->assertJsonMissingValidationErrors(['notes']);
        $this->withHeaders($auth)->postJson('/api/vehicles', ['plate' => 'TIPO-001', 'vehicle_type' => 'deportivo'])
            ->assertStatus(422)->assertJsonValidationErrors(['vehicle_type']);

        $id = $this->withHeaders($auth)->postJson('/api/vehicles', ['make' => 'Seat', 'model' => 'Ibiza', 'color' => 'Blanco', 'year' => 2020, 'seats' => 5, 'plate' => 'TIPO-001', 'vehicle_type' => 'motocicleta'])
            ->assertStatus(201)->json('id');

        $this->withHeaders($auth)->putJson("/api/vehicles/{$id}", ['plate' => 'TIPO-001', 'vehicle_type' => null])
            ->assertStatus(422)->assertJsonValidationErrors(['vehicle_type']);
        $this->withHeaders($auth)->putJson("/api/vehicles/{$id}", ['make' => 'Seat', 'model' => 'Ibiza', 'color' => 'Blanco', 'year' => 2020, 'seats' => 5, 'plate' => 'TIPO-001', 'vehicle_type' => 'camion'])
            ->assertStatus(200)->assertJsonPath('vehicle_type', 'camion');
    }

    public function test_vehicle_delete_is_blocked_when_default_or_with_rutas()
    {
        $token = $this->postJson('/api/register', [
            'first_name' => 'Owner', 'last_name' => 'Borrar',
            'email' => 'ownerborrar@example.test', 'password' => 'Secret123!',
        ])->json('access_token');
        $auth = ['Authorization' => 'Bearer ' . $token];

        // El primero queda como predeterminado automáticamente.
        $defaultId = $this->withHeaders($auth)->postJson('/api/vehicles', ['make' => 'Seat', 'model' => 'Ibiza', 'color' => 'Blanco', 'year' => 2020, 'seats' => 5, 'plate' => 'DEF-001', 'vehicle_type' => 'sedan'])->json('id');
        $withRutaId = $this->withHeaders($auth)->postJson('/api/vehicles', ['make' => 'Seat', 'model' => 'Ibiza', 'color' => 'Blanco', 'year' => 2020, 'seats' => 5, 'plate' => 'RUT-001', 'vehicle_type' => 'suv'])->json('id');
        $freeId = $this->withHeaders($auth)->postJson('/api/vehicles', ['make' => 'Seat', 'model' => 'Ibiza', 'color' => 'Blanco', 'year' => 2020, 'seats' => 5, 'plate' => 'LIB-001', 'vehicle_type' => 'camion'])->json('id');

        $this->withHeaders($auth)->postJson('/api/rutas', [
            'client_name' => 'Cliente', 'origin' => 'Origen', 'destination' => 'Destino', 'vehicle_id' => $withRutaId,
        ])->assertStatus(201);

        // El listado indica cuántos viajes tiene cada vehículo.
        $list = collect($this->withHeaders($auth)->getJson('/api/vehicles')->assertOk()->json())->keyBy('id');
        $this->assertSame(1, $list[$withRutaId]['rutas_count']);
        $this->assertSame(0, $list[$freeId]['rutas_count']);
        $this->assertArrayNotHasKey('active', $list[$freeId]);

        $this->withHeaders($auth)->deleteJson("/api/vehicles/{$defaultId}")
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'predeterminado'));
        $this->withHeaders($auth)->deleteJson("/api/vehicles/{$withRutaId}")
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'viajes'));
        $this->withHeaders($auth)->deleteJson("/api/vehicles/{$freeId}")->assertOk();
        $this->assertDatabaseMissing('vehicles', ['id' => $freeId]);
    }

    public function test_plate_is_normalized_to_uppercase_alphanumeric()
    {
        $token = $this->postJson('/api/register', [
            'first_name' => 'Owner', 'last_name' => 'Placa',
            'email' => 'ownerplaca@example.test', 'password' => 'Secret123!',
        ])->json('access_token');
        $auth = ['Authorization' => 'Bearer ' . $token];
        $base = ['make' => 'Seat', 'model' => 'Ibiza', 'color' => 'Blanco', 'year' => 2020, 'seats' => 5, 'vehicle_type' => 'sedan'];

        $this->withHeaders($auth)->postJson('/api/vehicles', $base + ['plate' => '1234-abc'])
            ->assertStatus(201)->assertJsonPath('plate', '1234ABC');

        // Con espacios es la misma matrícula: choca con la regla unique.
        $this->withHeaders($auth)->postJson('/api/vehicles', $base + ['plate' => '1234 ABC'])
            ->assertStatus(422)->assertJsonValidationErrors(['plate']);

        // Sin ningún carácter alfanumérico queda vacía.
        $this->withHeaders($auth)->postJson('/api/vehicles', $base + ['plate' => '--- '])
            ->assertStatus(422)->assertJsonValidationErrors(['plate']);
    }

    public function test_ruta_delete_is_soft_and_can_be_restored()
    {
        $token = $this->postJson('/api/register', [
            'first_name' => 'Owner', 'last_name' => 'Soft',
            'email' => 'ownersoft@example.test', 'password' => 'Secret123!',
        ])->json('access_token');
        $auth = ['Authorization' => 'Bearer ' . $token];
        $date = date('Y-m-d');

        $vehicleId = $this->withHeaders($auth)->postJson('/api/vehicles', [
            'plate' => 'SOFT001', 'make' => 'Seat', 'model' => 'Ibiza', 'color' => 'Blanco', 'year' => 2020, 'seats' => 5, 'vehicle_type' => 'sedan',
        ])->json('id');
        $rutaId = $this->withHeaders($auth)->postJson('/api/rutas', [
            'client_name' => 'Cliente', 'origin' => 'Origen', 'destination' => 'Destino', 'trip_date' => $date, 'vehicle_id' => $vehicleId,
        ])->assertStatus(201)->json('id');

        $this->withHeaders($auth)->deleteJson("/api/rutas/{$rutaId}")->assertOk();
        // Cancelar = eliminar: queda cancelado y con borrado blando.
        $this->assertSoftDeleted('rutas', ['id' => $rutaId, 'status' => 'cancelled']);

        // Por defecto (calendarios, informes...) no aparece; el listado lo pide con with_deleted.
        // Mismo rango que pide la app (día anterior a día siguiente).
        $range = 'date_from=' . date('Y-m-d', strtotime('-1 day')) . '&date_to=' . date('Y-m-d', strtotime('+1 day'));
        $this->assertCount(0, $this->withHeaders($auth)->getJson("/api/rutas?{$range}")->json('data'));
        $listed = $this->withHeaders($auth)->getJson("/api/rutas?{$range}&with_deleted=1")->json('data');
        $this->assertCount(1, $listed);
        $this->assertNotNull($listed[0]['deleted_at']);

        // Sigue contando para el vehículo: no se puede borrar (la FK lo impediría).
        $this->withHeaders($auth)->deleteJson("/api/vehicles/{$vehicleId}")->assertStatus(422);

        // Otro usuario no puede restaurarlo.
        $otherToken = $this->postJson('/api/register', [
            'first_name' => 'Otro', 'last_name' => 'User',
            'email' => 'otro@example.test', 'password' => 'Secret123!',
        ])->json('access_token');
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $otherToken])->postJson("/api/rutas/{$rutaId}/restore")->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->withHeaders($auth)->postJson("/api/rutas/{$rutaId}/restore")->assertOk()->assertJsonPath('deleted_at', null)
            ->assertJsonPath('status', 'pending');
        $this->assertNotSoftDeleted('rutas', ['id' => $rutaId]);
        $this->withHeaders($auth)->putJson("/api/rutas/{$rutaId}", ['status' => 'cancelled'])
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->assertCount(1, $this->withHeaders($auth)->getJson("/api/rutas?{$range}")->json('data'));
    }

    public function test_first_vehicle_created_outside_the_api_becomes_default()
    {
        $owner = User::factory()->create();
        $first = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $second = Vehicle::factory()->create(['owner_id' => $owner->id]);

        $this->assertTrue($first->fresh()->is_default);
        $this->assertFalse($second->fresh()->is_default);
    }
}
