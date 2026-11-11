<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeocodingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ninguna prueba debe llamar a Photon, Nominatim u OSRM de verdad.
        Http::preventStrayRequests();
    }

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
            // Photon sin resultados: se usa Nominatim.
            'photon.komoot.io/*' => Http::response(['features' => []], 200),
            'nominatim.openstreetmap.org/*' => Http::response([
                ['display_name' => 'Calle Gran Vía, Madrid, España', 'lat' => '40.4200', 'lon' => '-3.7025'],
            ], 200),
        ]);

        $resp = $this->withHeaders($this->authHeader())->getJson('/api/geocode/search?q=Gran Via Madrid');

        $resp->assertOk();
        $this->assertEquals('Calle Gran Vía, Madrid, España', $resp->json('0.display_name'));
        $this->assertEquals(40.42, $resp->json('0.lat'));
    }

    public function test_search_uses_photon_and_keeps_only_spanish_results()
    {
        Http::fake([
            'photon.komoot.io/*' => Http::response(['features' => [
                ['geometry' => ['coordinates' => [-1.9592863, 42.3006093]], 'properties' => [
                    'name' => 'Calle Mayor', 'city' => 'Calahorra', 'state' => 'La Rioja', 'postcode' => '26500', 'countrycode' => 'ES',
                ]],
                ['geometry' => ['coordinates' => [-1.95, 42.31]], 'properties' => [
                    'street' => 'Calle Mayor', 'housenumber' => '12', 'city' => 'Calahorra', 'state' => 'La Rioja', 'countrycode' => 'ES',
                ]],
                ['geometry' => ['coordinates' => [-8.6, 41.1]], 'properties' => ['name' => 'Rua Maior', 'countrycode' => 'PT']],
            ]], 200),
            'nominatim.openstreetmap.org/*' => Http::response([], 500),
        ]);

        $resp = $this->withHeaders($this->authHeader())->getJson('/api/geocode/search?q=Calle Mayor Calahorra');

        $resp->assertOk()->assertJsonCount(2);
        $this->assertSame('Calle Mayor, Calahorra, La Rioja, 26500', $resp->json('0.display_name'));
        $this->assertSame('Calle Mayor 12, Calahorra, La Rioja', $resp->json('1.display_name'));
        $this->assertEqualsWithDelta(42.3006093, $resp->json('0.lat'), 0.0000001);
    }

    public function test_search_falls_back_to_nominatim_when_photon_fails()
    {
        Http::fake([
            'photon.komoot.io/*' => Http::response([], 503),
            'nominatim.openstreetmap.org/*' => Http::response([
                ['display_name' => 'Calle Gran Vía, Madrid, España', 'lat' => '40.42', 'lon' => '-3.70'],
            ], 200),
        ]);

        $this->withHeaders($this->authHeader())->getJson('/api/geocode/search?q=Gran Via Madrid')
            ->assertOk()->assertJsonPath('0.display_name', 'Calle Gran Vía, Madrid, España');
    }

    public function test_search_requires_at_least_three_characters()
    {
        $resp = $this->withHeaders($this->authHeader())->getJson('/api/geocode/search?q=ab');
        $resp->assertStatus(422);
    }

    public function test_search_returns_503_when_nominatim_fails()
    {
        Http::fake([
            'photon.komoot.io/*' => Http::response([], 500),
            'nominatim.openstreetmap.org/*' => Http::response([], 500),
        ]);

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
