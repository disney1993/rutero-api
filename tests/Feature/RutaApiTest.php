<?php

namespace Tests\Feature;

use App\Models\Ruta;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RutaApiTest extends TestCase
{
    use RefreshDatabase;

    private function ownerUser(): User
    {
        $owner = User::factory()->create();
        Vehicle::factory()->create(['owner_id' => $owner->id, 'is_default' => true]);

        return $owner;
    }

    /** Campos obligatorios mínimos para crear una ruta. */
    private function validRutaPayload(array $overrides = []): array
    {
        return array_merge([
            'client_name' => 'Ana García',
            'client_phone' => '+34123456789',
            'origin' => 'Madrid',
            'destination' => 'Toledo',
            'trip_date' => '2026-08-25',
            'trip_time' => '10:00',
            'final_price' => 100,
        ], $overrides);
    }

    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
            ]);
    }

    public function test_unauthenticated_cannot_list_or_create_rutas(): void
    {
        $this->getJson('/api/rutas')->assertUnauthorized();

        $this->postJson('/api/rutas', [
            'client_name' => 'Ana García',
            'origin' => 'Madrid',
            'destination' => 'Toledo',
        ])->assertUnauthorized();
    }

    public function test_it_can_create_a_ruta(): void
    {
        $owner = $this->ownerUser();

        $payload = $this->validRutaPayload([
            'estimated_distance_km' => 75,
            'price_per_km' => 1.5,
            'estimated_price' => 112.5,
            'payment_method' => 'efectivo',
            'passenger_count' => 2,
            'status' => 'pending',
            'notes' => 'Vuelo temprano',
        ]);

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/rutas', $payload);

        $response->assertCreated()
            ->assertJsonPath('client_name', 'Ana García')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('owner_id', $owner->id);
    }

    public function test_it_saves_origin_and_destination_coordinates(): void
    {
        $owner = $this->ownerUser();

        $payload = $this->validRutaPayload([
            'origin_lat' => 40.4168,
            'origin_lng' => -3.7038,
            'destination_lat' => 39.8628,
            'destination_lng' => -4.0273,
        ]);

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/rutas', $payload);

        $response->assertCreated()
            ->assertJsonPath('origin_lat', 40.4168)
            ->assertJsonPath('destination_lng', -4.0273);
    }

    public function test_it_rejects_out_of_range_coordinates(): void
    {
        $owner = $this->ownerUser();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/rutas', $this->validRutaPayload([
            'origin_lat' => 200,
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['origin_lat']);
    }

    public function test_it_can_list_rutas_by_status(): void
    {
        $owner = $this->ownerUser();

        Ruta::factory()->create([
            'owner_id' => $owner->id,
            'client_name' => 'Cliente A',
            'status' => 'pending',
            'trip_date' => '2026-08-25',
        ]);

        Ruta::factory()->create([
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
        $owner = $this->ownerUser();

        $payload = $this->validRutaPayload([
            'client_name' => 'Pedro López',
            'origin' => 'Sevilla',
            'destination' => 'Cádiz',
            'estimated_distance_km' => 120,
            'price_per_km' => 1.75,
            'payment_method' => 'tarjeta',
            'passenger_count' => 3,
            'status' => 'pending',
            'trip_date' => '2026-08-26',
            'final_price' => 210,
        ]);

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/rutas', $payload);

        $response->assertCreated()
            ->assertJsonPath('estimated_price', 210);
    }

    public function test_authenticated_user_cannot_attribute_ruta_to_another_user_without_driver_code(): void
    {
        $owner = $this->ownerUser();
        $victim = User::factory()->create();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/rutas', $this->validRutaPayload([
            'owner_id' => $victim->id,
            'driver_id' => $victim->id,
        ]));

        $response->assertForbidden();
        $this->assertDatabaseMissing('rutas', [
            'client_name' => 'Ana García',
            'owner_id' => $victim->id,
        ]);
    }

    public function test_it_rejects_missing_required_trip_fields(): void
    {
        $owner = $this->ownerUser();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/rutas', [
            'client_name' => 'Ana',
            'origin' => 'Madrid',
            'destination' => 'Toledo',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors([
            'client_phone',
            'trip_date',
            'trip_time',
            'final_price',
        ]);
    }

    public function test_it_rejects_negative_prices_and_short_addresses(): void
    {
        $owner = $this->ownerUser();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/rutas', $this->validRutaPayload([
            'origin' => 'M',
            'price_per_km' => -5,
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['origin', 'price_per_km']);
    }

    public function test_it_can_filter_rutas_by_date_range(): void
    {
        $owner = $this->ownerUser();

        Ruta::factory()->create(['owner_id' => $owner->id, 'trip_date' => '2026-08-01']);
        Ruta::factory()->create(['owner_id' => $owner->id, 'trip_date' => '2026-08-15']);
        Ruta::factory()->create(['owner_id' => $owner->id, 'trip_date' => '2026-09-01']);

        $resp = $this->actingAs($owner, 'sanctum')->getJson('/api/rutas?date_from=2026-08-01&date_to=2026-08-31');

        $resp->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_admin_can_filter_rutas_by_user_id(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);

        Ruta::factory()->create(['owner_id' => $owner->id, 'trip_date' => '2026-08-25']);
        Ruta::factory()->create(['owner_id' => $other->id, 'trip_date' => '2026-08-25']);

        $resp = $this->actingAs($admin, 'sanctum')->getJson("/api/rutas?user_id={$owner->id}");

        $resp->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_non_admin_cannot_use_user_id_filter_to_see_others_rutas(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        Ruta::factory()->create(['owner_id' => $other->id, 'trip_date' => '2026-08-25']);

        $resp = $this->actingAs($owner, 'sanctum')->getJson("/api/rutas?user_id={$other->id}");

        $resp->assertOk()->assertJsonCount(0, 'data');
    }
}
