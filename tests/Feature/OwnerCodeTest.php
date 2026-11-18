<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_month_code_and_driver_joins()
    {
        $resp = $this->postJson('/api/register', [
            'first_name' => 'Owner',
            'last_name' => 'Codes',
            'avatar' => 'http://example.com/a.png',
            'email' => 'ownercodes@example.test',
            'password' => 'Secret123!',
        ]);
        $token = $resp->json('access_token');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/vehicles', ['plate' => 'CODE-001'])->assertStatus(201);

        $month = date('Y-m');
        $create = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/owner/codes', ['month' => $month]);
        $create->assertStatus(201)->assertJsonStructure(['id', 'owner_id', 'code', 'month']);
        $code = $create->json('code');

        $dreg = $this->postJson('/api/register', [
            'first_name' => 'Driver',
            'last_name' => 'Codes',
            'avatar' => 'http://example.com/b.png',
            'email' => 'drivercodes@example.test',
            'password' => 'Secret123!',
        ]);
        $dtoken = $dreg->json('access_token');

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$dtoken])
            ->postJson('/api/driver/join-code', ['code' => $code, 'month' => $month])
            ->assertStatus(200)
            ->assertJson(['message' => 'Joined']);
    }

    public function test_owner_code_rejects_invalid_month_format()
    {
        $resp = $this->postJson('/api/register', [
            'first_name' => 'Owner', 'last_name' => 'Bad',
            'email' => 'ownerbad@example.test', 'password' => 'Secret123!',
        ]);
        $token = $resp->json('access_token');
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/vehicles', ['plate' => 'BAD-001'])->assertStatus(201);

        $create = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/owner/codes', ['month' => 'not-a-month']);
        $create->assertStatus(422)->assertJsonValidationErrors(['month']);
    }

    public function test_driver_join_rejects_malformed_code()
    {
        $resp = $this->postJson('/api/register', [
            'first_name' => 'Driver', 'last_name' => 'Bad',
            'email' => 'driverbad@example.test', 'password' => 'Secret123!',
        ]);
        $token = $resp->json('access_token');

        $join = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/driver/join-code', ['code' => 'short', 'month' => date('Y-m')]);
        $join->assertStatus(422)->assertJsonValidationErrors(['code']);
    }
}
