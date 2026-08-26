<?php

namespace Tests\Feature;

use Tests\TestCase;

class CarCatalogTest extends TestCase
{
    public function test_it_lists_car_makes()
    {
        $resp = $this->getJson('/api/car-catalog/makes');
        $resp->assertOk();
        $this->assertContains('SEAT', $resp->json());
    }

    public function test_it_lists_models_for_a_known_make()
    {
        $resp = $this->getJson('/api/car-catalog/models?make=Toyota');
        $resp->assertOk();
        $this->assertContains('Corolla', $resp->json());
    }

    public function test_it_returns_empty_list_for_unknown_make()
    {
        $resp = $this->getJson('/api/car-catalog/models?make=NoSuchMake');
        $resp->assertOk()->assertJson([]);
    }

    public function test_models_requires_make_param()
    {
        $resp = $this->getJson('/api/car-catalog/models');
        $resp->assertStatus(422);
    }
}
