<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeocodingTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(): array
    {
        $resp = $this->postJson('/api/register', [
            'first_name' => 'Geo', 'last_name' => 'Test',
            'email' => 'geo' . uniqid() . '@example.test', 'password' => 'Secret123!',
        ]);
        return ['Authorization' => 'Bearer ' . $resp->json('access_token')];
    }

    public function test_search_returns_spanish_addresses()
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                ['display_name' => 'Calle Gran Vía, Madrid, España', 'lat' => '40.4200', 'lon' => '-3.7025'],
            ], 200),
        ]);

        $resp = $this->withHeaders($this->authHeader())->getJson('/api/geocode/search?q=Gran Via Madrid');

        $resp->assertOk();
        $this->assertEquals('Calle Gran Vía, Madrid, España', $resp->json('0.display_name'));
        $this->assertEquals(40.42, $resp->json('0.lat'));
    }

    public function test_search_requires_at_least_three_characters()
    {
        $resp = $this->withHeaders($this->authHeader())->getJson('/api/geocode/search?q=ab');
        $resp->assertStatus(422);
    }

    public function test_search_returns_503_when_nominatim_fails()
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([], 500)]);

        $resp = $this->withHeaders($this->authHeader())->getJson('/api/geocode/search?q=Madrid capital');
        $resp->assertStatus(503);
    }

    public function test_distance_calculates_km_between_two_points()
    {
        Http::fake([
            'router.project-osrm.org/*' => Http::response([
                'code' => 'Ok',
                'routes' => [['distance' => 75000, 'duration' => 3600]],
            ], 200),
        ]);

        $resp = $this->withHeaders($this->authHeader())->getJson('/api/geocode/distance?' . http_build_query([
            'origin_lat' => 40.4168, 'origin_lng' => -3.7038,
            'destination_lat' => 39.8628, 'destination_lng' => -4.0273,
        ]));

        $resp->assertOk()
            ->assertJsonPath('distance_km', 75)
            ->assertJsonPath('duration_min', 60);
    }

    public function test_distance_returns_503_when_osrm_fails()
    {
        Http::fake(['router.project-osrm.org/*' => Http::response([], 500)]);

        $resp = $this->withHeaders($this->authHeader())->getJson('/api/geocode/distance?' . http_build_query([
            'origin_lat' => 40.4168, 'origin_lng' => -3.7038,
            'destination_lat' => 39.8628, 'destination_lng' => -4.0273,
        ]));

        $resp->assertStatus(503);
    }

    public function test_distance_validates_coordinate_ranges()
    {
        $resp = $this->withHeaders($this->authHeader())->getJson('/api/geocode/distance?' . http_build_query([
            'origin_lat' => 999, 'origin_lng' => -3.7038,
            'destination_lat' => 39.8628, 'destination_lng' => -4.0273,
        ]));

        $resp->assertStatus(422)->assertJsonValidationErrors(['origin_lat']);
    }
}
