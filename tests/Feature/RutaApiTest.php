<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RutaApiTest extends TestCase
{
    use RefreshDatabase;

    // Crear una ruta exige un vehículo de la flota de quien la crea, así que
    // los tests de creación usan un propietario autenticado con un coche.
    private function ownerWithVehicle(string $email = 'owner-ruta@example.test'): array
    {
        $token = $this->postJson('/api/register', [
            'first_name' => 'Owner', 'last_name' => 'Ruta',
            'email' => $email, 'password' => 'Secret123!',
        ])->json('access_token');
        $headers = ['Authorization' => 'Bearer ' . $token];
        $vehicleId = $this->withHeaders($headers)->postJson('/api/vehicles', [
            'plate' => 'RUT001', 'make' => 'Seat', 'model' => 'Ibiza', 'color' => '#FFFFFF',
            'year' => 2020, 'seats' => 5, 'vehicle_type' => 'sedan',
        ])->assertStatus(201)->json('id');

        return [$headers, $vehicleId];
    }

    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
            ]);
    }

    public function test_it_can_create_a_ruta(): void
    {
        [$headers, $vehicleId] = $this->ownerWithVehicle();
        $payload = [
            'vehicle_id' => $vehicleId,
            'client_name' => 'Ana García',
            'client_phone' => '+34123456789',
            'origin' => 'Madrid',
            'destination' => 'Toledo',
            'estimated_distance_km' => 75,
            'price_per_km' => 1.5,
            'estimated_price' => 112.5,
            'final_price' => 115,
            'payment_method' => 'efectivo',
            'passenger_count' => 2,
            'status' => 'pending',
            'trip_date' => '2026-08-25',
            'trip_time' => '09:30',
            'notes' => 'Vuelo temprano',
        ];

        $response = $this->withHeaders($headers)->postJson('/api/rutas', $payload);

        $response->assertCreated()
            ->assertJsonPath('client_name', 'Ana García')
            ->assertJsonPath('status', 'pending');
    }

    public function test_it_saves_origin_and_destination_coordinates(): void
    {
        [$headers, $vehicleId] = $this->ownerWithVehicle();
        $payload = [
            'vehicle_id' => $vehicleId,
            'client_name' => 'Ana García',
            'client_phone' => '612345678',
            'origin' => 'Madrid',
            'origin_lat' => 40.4168,
            'origin_lng' => -3.7038,
            'destination' => 'Toledo',
            'destination_lat' => 39.8628,
            'destination_lng' => -4.0273,
            'trip_time' => '10:00',
            'final_price' => 80,
        ];

        $response = $this->withHeaders($headers)->postJson('/api/rutas', $payload);

        $response->assertCreated()
            ->assertJsonPath('origin_lat', 40.4168)
            ->assertJsonPath('destination_lng', -4.0273);
    }

    public function test_it_rejects_out_of_range_coordinates(): void
    {
        $response = $this->actingAs(\App\Models\User::factory()->create(), 'sanctum')->postJson('/api/rutas', [
            'client_name' => 'Ana García',
            'client_phone' => '612345678',
            'origin' => 'Madrid',
            'origin_lat' => 200,
            'destination' => 'Toledo',
            'trip_time' => '10:00',
            'final_price' => 80,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['origin_lat']);
    }

    public function test_it_can_list_rutas_by_status(): void
    {
        $owner = \App\Models\User::factory()->create();
        \App\Models\Ruta::factory()->create([
            'owner_id' => $owner->id,
            'client_name' => 'Cliente A',
            'status' => 'pending',
            'trip_date' => '2026-08-25',
        ]);

        \App\Models\Ruta::factory()->create([
            'owner_id' => $owner->id,
            'client_name' => 'Cliente B',
            'status' => 'completed',
            'trip_date' => '2026-08-25',
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/rutas?status=pending');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.client_name', 'Cliente A');
    }

    public function test_it_calculates_estimated_price_when_missing(): void
    {
        [$headers, $vehicleId] = $this->ownerWithVehicle();
        $payload = [
            'vehicle_id' => $vehicleId,
            'client_name' => 'Pedro López',
            'client_phone' => '698765432',
            'origin' => 'Sevilla',
            'destination' => 'Cádiz',
            'estimated_distance_km' => 120,
            'price_per_km' => 1.75,
            'final_price' => 210,
            'payment_method' => 'tarjeta',
            'passenger_count' => 3,
            'status' => 'pending',
            'trip_date' => '2026-08-26',
            'trip_time' => '11:15',
        ];

        $response = $this->withHeaders($headers)->postJson('/api/rutas', $payload);

        $response->assertCreated()
            ->assertJsonPath('estimated_price', 210);
    }

    public function test_anonymous_request_cannot_attribute_ruta_to_another_user(): void
    {
        $victim = \App\Models\User::factory()->create();

        // Crear exige sesión: sin ella no queda nada a nombre de otro usuario.
        $this->postJson('/api/rutas', [
            'client_name' => 'Ana García',
            'client_phone' => '612345678',
            'origin' => 'Madrid',
            'destination' => 'Toledo',
            'trip_time' => '09:00',
            'final_price' => 50,
            'owner_id' => $victim->id,
            'driver_id' => $victim->id,
        ])->assertStatus(401);

        $this->assertDatabaseMissing('rutas', ['owner_id' => $victim->id]);
        $this->assertDatabaseMissing('rutas', ['driver_id' => $victim->id]);
    }

    public function test_anonymous_request_cannot_list_rutas(): void
    {
        // Antes el listado era público y devolvía los viajes de todos los
        // usuarios, con nombre y teléfono de sus clientes.
        \App\Models\Ruta::factory()->create(['owner_id' => \App\Models\User::factory()->create()->id]);

        $this->getJson('/api/rutas')->assertStatus(401)->assertJsonMissingPath('data');
    }

    public function test_user_cannot_attribute_ruta_to_owner_without_joining_their_code(): void
    {
        $victim = \App\Models\User::factory()->create();
        [$headers, $vehicleId] = $this->ownerWithVehicle();

        $this->withHeaders($headers)->postJson('/api/rutas', [
            'client_name' => 'Ana García',
            'client_phone' => '612345678',
            'origin' => 'Madrid',
            'destination' => 'Toledo',
            'trip_time' => '09:00',
            'final_price' => 50,
            'vehicle_id' => $vehicleId,
            'owner_id' => $victim->id,
        ])->assertStatus(403);

        $this->assertDatabaseMissing('rutas', ['owner_id' => $victim->id]);
    }

    public function test_it_rejects_negative_prices_and_short_addresses(): void
    {
        $response = $this->actingAs(\App\Models\User::factory()->create(), 'sanctum')->postJson('/api/rutas', [
            'client_name' => 'Ana',
            'client_phone' => '612345678',
            'origin' => 'M',
            'destination' => 'Toledo',
            'trip_time' => '09:00',
            'final_price' => 50,
            'price_per_km' => -5,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['origin', 'price_per_km']);
    }

    public function test_it_can_filter_rutas_by_date_range(): void
    {
        $owner = \App\Models\User::factory()->create();
        foreach (['2026-08-01', '2026-08-15', '2026-09-01'] as $date) {
            \App\Models\Ruta::factory()->create(['owner_id' => $owner->id, 'trip_date' => $date]);
        }

        $resp = $this->actingAs($owner, 'sanctum')->getJson('/api/rutas?date_from=2026-08-01&date_to=2026-08-31');

        $resp->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_admin_can_filter_rutas_by_user_id(): void
    {
        $owner = \App\Models\User::factory()->create();
        $other = \App\Models\User::factory()->create();
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);

        \App\Models\Ruta::factory()->create(['owner_id' => $owner->id, 'trip_date' => '2026-08-25']);
        \App\Models\Ruta::factory()->create(['owner_id' => $other->id, 'trip_date' => '2026-08-25']);

        $resp = $this->actingAs($admin, 'sanctum')->getJson("/api/rutas?user_id={$owner->id}");

        $resp->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_non_admin_cannot_use_user_id_filter_to_see_others_rutas(): void
    {
        $owner = \App\Models\User::factory()->create();
        $other = \App\Models\User::factory()->create();

        \App\Models\Ruta::factory()->create(['owner_id' => $other->id, 'trip_date' => '2026-08-25']);

        $resp = $this->actingAs($owner, 'sanctum')->getJson("/api/rutas?user_id={$other->id}");

        $resp->assertOk()->assertJsonCount(0, 'data');
    }
}
