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
            'email' => 'geo'.uniqid().'@example.test', 'password' => 'Secret123!',
        ]);

        return ['Authorization' => 'Bearer '.$resp->json('access_token')];
    }

    public function test_search_returns_spanish_addresses()
    {
        config(['services.google.maps_api_key' => null, 'rutero.geocoding_provider' => 'nominatim']);

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

    public function test_search_accepts_spanish_postal_code()
    {
        config(['services.google.maps_api_key' => null, 'rutero.geocoding_provider' => 'nominatim']);

        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                [
                    'display_name' => '28001, Madrid, España',
                    'lat' => '40.4200',
                    'lon' => '-3.7025',
                    'address' => ['postcode' => '28001'],
                    'type' => 'postcode',
                ],
            ], 200),
        ]);

        $resp = $this->withHeaders($this->authHeader())->getJson('/api/geocode/search?q=28001');

        $resp->assertOk()
            ->assertJsonPath('0.postcode', '28001')
            ->assertJsonPath('0.lat', 40.42);
    }

    public function test_search_uses_google_when_api_key_configured()
    {
        config(['services.google.maps_api_key' => 'test-key', 'rutero.geocoding_provider' => 'google']);

        Http::fake([
            'maps.googleapis.com/maps/api/place/textsearch/*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'Gran Vía, Madrid, Spain',
                    'geometry' => ['location' => ['lat' => 40.42, 'lng' => -3.70]],
                    'place_id' => 'abc123',
                    'types' => ['route'],
                ]],
            ], 200),
        ]);

        $resp = $this->withHeaders($this->authHeader())->getJson('/api/geocode/search?q=Gran Via Madrid');

        $resp->assertOk()
            ->assertJsonPath('0.display_name', 'Gran Vía, Madrid, Spain')
            ->assertJsonPath('0.provider', 'google')
            ->assertJsonPath('0.lat', 40.42);
        $this->assertEquals('google', $resp->headers->get('X-Geocode-Provider'));
    }

    public function test_search_falls_back_to_nominatim_when_google_fails()
    {
        config(['services.google.maps_api_key' => 'test-key', 'rutero.geocoding_provider' => 'auto']);

        Http::fake([
            'maps.googleapis.com/*' => Http::response(['status' => 'REQUEST_DENIED', 'error_message' => 'bad key'], 200),
            'nominatim.openstreetmap.org/*' => Http::response([
                ['display_name' => 'Fallback OSM, Madrid', 'lat' => '40.41', 'lon' => '-3.70'],
            ], 200),
        ]);

        $resp = $this->withHeaders($this->authHeader())->getJson('/api/geocode/search?q=Madrid centro');

        $resp->assertOk()
            ->assertJsonPath('0.display_name', 'Fallback OSM, Madrid')
            ->assertJsonPath('0.provider', 'nominatim');
    }

    public function test_search_returns_503_when_nominatim_fails()
    {
        config(['services.google.maps_api_key' => null, 'rutero.geocoding_provider' => 'nominatim']);

        Http::fake([
            'photon.komoot.io/*' => Http::response([], 500),
            'nominatim.openstreetmap.org/*' => Http::response([], 500),
        ]);

        $resp = $this->withHeaders($this->authHeader())->getJson('/api/geocode/search?q=Madrid capital');
        $resp->assertStatus(503);
    }

    public function test_distance_calculates_km_between_two_points()
    {
        config(['services.google.maps_api_key' => null, 'rutero.geocoding_provider' => 'nominatim']);

        Http::fake([
            'router.project-osrm.org/*' => Http::response([
                'code' => 'Ok',
                'routes' => [['distance' => 75000, 'duration' => 3600]],
            ], 200),
        ]);

        $resp = $this->withHeaders($this->authHeader())->getJson('/api/geocode/distance?'.http_build_query([
            'origin_lat' => 40.4168, 'origin_lng' => -3.7038,
            'destination_lat' => 39.8628, 'destination_lng' => -4.0273,
        ]));

        $resp->assertOk()
            ->assertJsonPath('distance_km', 75)
            ->assertJsonPath('duration_min', 60)
            ->assertJsonPath('provider', 'osrm');
    }

    public function test_distance_uses_google_when_configured()
    {
        config(['services.google.maps_api_key' => 'test-key', 'rutero.geocoding_provider' => 'google']);

        Http::fake([
            'maps.googleapis.com/maps/api/distancematrix/*' => Http::response([
                'status' => 'OK',
                'rows' => [[
                    'elements' => [[
                        'status' => 'OK',
                        'distance' => ['value' => 12300],
                        'duration' => ['value' => 900],
                    ]],
                ]],
            ], 200),
        ]);

        $resp = $this->withHeaders($this->authHeader())->getJson('/api/geocode/distance?'.http_build_query([
            'origin_lat' => 40.4168, 'origin_lng' => -3.7038,
            'destination_lat' => 40.5, 'destination_lng' => -3.7,
        ]));

        $resp->assertOk()
            ->assertJsonPath('distance_km', 12.3)
            ->assertJsonPath('duration_min', 15)
            ->assertJsonPath('provider', 'google');
    }

    public function test_distance_returns_503_when_osrm_fails()
    {
        config(['services.google.maps_api_key' => null, 'rutero.geocoding_provider' => 'nominatim']);

        Http::fake(['router.project-osrm.org/*' => Http::response([], 500)]);

        $resp = $this->withHeaders($this->authHeader())->getJson('/api/geocode/distance?'.http_build_query([
            'origin_lat' => 40.4168, 'origin_lng' => -3.7038,
            'destination_lat' => 39.8628, 'destination_lng' => -4.0273,
        ]));

        $resp->assertStatus(503);
    }

    public function test_distance_validates_coordinate_ranges()
    {
        $resp = $this->withHeaders($this->authHeader())->getJson('/api/geocode/distance?'.http_build_query([
            'origin_lat' => 999, 'origin_lng' => -3.7038,
            'destination_lat' => 39.8628, 'destination_lng' => -4.0273,
        ]));

        $resp->assertStatus(422)->assertJsonValidationErrors(['origin_lat']);
    }

    public function test_status_reports_google_configuration()
    {
        config(['services.google.maps_api_key' => 'test-key', 'rutero.geocoding_provider' => 'auto']);

        $resp = $this->withHeaders($this->authHeader())->getJson('/api/geocode/status');

        $resp->assertOk()
            ->assertJsonPath('google_configured', true)
            ->assertJsonPath('active_search', 'google')
            ->assertJsonPath('active_distance', 'google')
            ->assertJsonStructure(['billing' => ['summary', 'metrics_url', 'pricing_url']]);
    }
}
