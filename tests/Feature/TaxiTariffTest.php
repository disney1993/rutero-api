<?php

namespace Tests\Feature;

use Tests\TestCase;

class TaxiTariffTest extends TestCase
{
    public function test_it_lists_reference_taxi_tariffs_by_region(): void
    {
        $response = $this->getJson('/api/reference/taxi-tariffs');

        $response->assertOk()
            ->assertJsonPath('default_route_km_price', 1.5)
            ->assertJsonPath('median_route_km_price', 1.4)
            ->assertJsonStructure([
                'reviewed_at',
                'default_route_km_price',
                'median_route_km_price',
                'regions' => [['code', 'name', 'year', 'km_price', 'km_price_special', 'km_basis', 'route_km_price', 'source', 'source_url']],
                'unverified',
            ]);
    }

    public function test_route_price_doubles_the_official_price_in_closed_circuit_regions(): void
    {
        foreach (config('taxi_tariffs.regions') as $region) {
            if ($region['km_basis'] === 'closed_circuit') {
                $this->assertEqualsWithDelta($region['km_price'] * 2, $region['route_km_price'], 0.01, $region['name']);
            } elseif ($region['km_basis'] === 'one_way') {
                $this->assertEqualsWithDelta($region['km_price'], $region['route_km_price'], 0.001, $region['name']);
            } else {
                $this->assertNull($region['route_km_price'], $region['name']);
            }
        }
    }
}
