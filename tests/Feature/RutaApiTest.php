<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RutaApiTest extends TestCase
{
    use RefreshDatabase;

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
        $payload = [
            'client_name' => 'Ana García',
            'client_phone' => '+34123456789',
            'origin' => 'Madrid',
            'destination' => 'Toledo',
            'estimated_distance_km' => 75,
            'price_per_km' => 1.5,
            'estimated_price' => 112.5,
            'payment_method' => 'efectivo',
            'passenger_count' => 2,
            'status' => 'pending',
            'trip_date' => '2026-08-25',
            'notes' => 'Vuelo temprano',
        ];

        $response = $this->postJson('/api/rutas', $payload);

        $response->assertCreated()
            ->assertJsonPath('client_name', 'Ana García')
            ->assertJsonPath('status', 'pending');
    }

    public function test_it_saves_origin_and_destination_coordinates(): void
    {
        $payload = [
            'client_name' => 'Ana García',
            'origin' => 'Madrid',
            'origin_lat' => 40.4168,
            'origin_lng' => -3.7038,
            'destination' => 'Toledo',
            'destination_lat' => 39.8628,
            'destination_lng' => -4.0273,
        ];

        $response = $this->postJson('/api/rutas', $payload);

        $response->assertCreated()
            ->assertJsonPath('origin_lat', 40.4168)
            ->assertJsonPath('destination_lng', -4.0273);
    }

    public function test_it_rejects_out_of_range_coordinates(): void
    {
        $response = $this->postJson('/api/rutas', [
            'client_name' => 'Ana García',
            'origin' => 'Madrid',
            'origin_lat' => 200,
            'destination' => 'Toledo',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['origin_lat']);
    }

    public function test_it_can_list_rutas_by_status(): void
    {
        \App\Models\Ruta::factory()->create([
            'client_name' => 'Cliente A',
            'status' => 'pending',
            'trip_date' => '2026-08-25',
        ]);

        \App\Models\Ruta::factory()->create([
            'client_name' => 'Cliente B',
            'status' => 'completed',
            'trip_date' => '2026-08-25',
        ]);

        $response = $this->getJson('/api/rutas?status=pending');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.client_name', 'Cliente A');
    }

    public function test_it_calculates_estimated_price_when_missing(): void
    {
        $payload = [
            'client_name' => 'Pedro López',
            'origin' => 'Sevilla',
            'destination' => 'Cádiz',
            'estimated_distance_km' => 120,
            'price_per_km' => 1.75,
            'payment_method' => 'tarjeta',
            'passenger_count' => 3,
            'status' => 'pending',
            'trip_date' => '2026-08-26',
        ];

        $response = $this->postJson('/api/rutas', $payload);

        $response->assertCreated()
            ->assertJsonPath('estimated_price', 210);
    }

    public function test_anonymous_request_cannot_attribute_ruta_to_another_user(): void
    {
        $victim = \App\Models\User::factory()->create();

        $response = $this->postJson('/api/rutas', [
            'client_name' => 'Ana García',
            'origin' => 'Madrid',
            'destination' => 'Toledo',
            'owner_id' => $victim->id,
            'driver_id' => $victim->id,
        ]);

        $response->assertCreated();
        $this->assertNull($response->json('owner_id'));
        $this->assertNull($response->json('driver_id'));
    }

    public function test_it_rejects_negative_prices_and_short_addresses(): void
    {
        $response = $this->postJson('/api/rutas', [
            'client_name' => 'Ana',
            'origin' => 'M',
            'destination' => 'Toledo',
            'price_per_km' => -5,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['origin', 'price_per_km']);
    }

    public function test_it_can_filter_rutas_by_date_range(): void
    {
        \App\Models\Ruta::factory()->create(['trip_date' => '2026-08-01']);
        \App\Models\Ruta::factory()->create(['trip_date' => '2026-08-15']);
        \App\Models\Ruta::factory()->create(['trip_date' => '2026-09-01']);

        $resp = $this->getJson('/api/rutas?date_from=2026-08-01&date_to=2026-08-31');

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
