<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function registerAndToken(array $overrides = []): array
    {
        $payload = array_merge([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test' . uniqid() . '@example.test',
            'password' => 'Secret123!',
        ], $overrides);

        $resp = $this->postJson('/api/register', $payload);

        return [$resp->json('access_token'), $resp->json('user.id')];
    }

    private function makeAdmin(): string
    {
        [$token, $id] = $this->registerAndToken(['email' => 'admin@example.test']);
        \App\Models\User::where('id', $id)->update(['role' => 'admin']);
        $this->app['auth']->forgetGuards();
        return $token;
    }

    public function test_admin_can_search_users_by_name_or_email()
    {
        $adminToken = $this->makeAdmin();
        $this->registerAndToken(['first_name' => 'Carlos', 'last_name' => 'Rivas', 'email' => 'carlos@example.test']);
        $this->registerAndToken(['first_name' => 'Marta', 'last_name' => 'Diaz', 'email' => 'marta@example.test']);

        $this->app['auth']->forgetGuards();
        $resp = $this->withHeader('Authorization', "Bearer $adminToken")->getJson('/api/admin/users?search=carlos');

        $resp->assertStatus(200);
        $this->assertCount(1, $resp->json());
        $this->assertEquals('carlos@example.test', $resp->json('0.email'));
    }

    public function test_admin_can_update_any_user()
    {
        $adminToken = $this->makeAdmin();
        [, $userId] = $this->registerAndToken(['email' => 'target@example.test']);

        $this->app['auth']->forgetGuards();
        $resp = $this->withHeader('Authorization', "Bearer $adminToken")
            ->patchJson("/api/admin/users/{$userId}", ['role' => 'admin', 'plan' => 'premium']);

        $resp->assertStatus(200)
            ->assertJsonPath('role', 'admin')
            ->assertJsonPath('plan', 'premium');
    }

    public function test_update_user_rejects_invalid_role_and_duplicate_email()
    {
        $adminToken = $this->makeAdmin();
        [, $userId] = $this->registerAndToken(['email' => 'target2@example.test']);
        $this->registerAndToken(['email' => 'taken@example.test']);

        $this->app['auth']->forgetGuards();
        $resp = $this->withHeader('Authorization', "Bearer $adminToken")
            ->patchJson("/api/admin/users/{$userId}", ['role' => 'superuser', 'email' => 'taken@example.test']);

        $resp->assertStatus(422)->assertJsonValidationErrors(['role', 'email']);
    }

    public function test_admin_can_delete_a_user_and_their_owned_rutas_go_with_them()
    {
        $adminToken = $this->makeAdmin();
        [$ownerToken, $ownerId] = $this->registerAndToken(['email' => 'ownerdel@example.test']);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer $ownerToken")
            ->postJson('/api/vehicles', ['plate' => 'DEL-001'])->assertStatus(201);
        $this->withHeader('Authorization', "Bearer $ownerToken")
            ->postJson('/api/rutas', ['client_name' => 'Cliente', 'origin' => 'Origen', 'destination' => 'Destino'])
            ->assertStatus(201);

        $this->app['auth']->forgetGuards();
        $resp = $this->withHeader('Authorization', "Bearer $adminToken")->deleteJson("/api/admin/users/{$ownerId}");
        $resp->assertStatus(200);

        $this->assertDatabaseMissing('users', ['id' => $ownerId]);
        $this->assertDatabaseMissing('rutas', ['owner_id' => $ownerId]);
    }

    public function test_admin_cannot_delete_their_own_account()
    {
        $adminToken = $this->makeAdmin();
        $adminId = \App\Models\User::where('email', 'admin@example.test')->value('id');

        $resp = $this->withHeader('Authorization', "Bearer $adminToken")->deleteJson("/api/admin/users/{$adminId}");
        $resp->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $adminId]);
    }

    public function test_non_admin_cannot_manage_users()
    {
        [$token] = $this->registerAndToken(['email' => 'plain@example.test']);
        [, $otherId] = $this->registerAndToken(['email' => 'other@example.test']);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer $token")
            ->patchJson("/api/admin/users/{$otherId}", ['role' => 'admin'])
            ->assertStatus(403);
    }

    public function test_deleting_a_driver_only_unassigns_them_from_others_rutas()
    {
        $adminToken = $this->makeAdmin();
        [$ownerToken, $ownerId] = $this->registerAndToken(['email' => 'owner3@example.test']);
        [$driverToken, $driverId] = $this->registerAndToken(['email' => 'driver3@example.test']);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer $ownerToken")->postJson('/api/vehicles', ['plate' => 'DRV-001'])->assertStatus(201);
        $month = date('Y-m');
        $code = $this->withHeader('Authorization', "Bearer $ownerToken")
            ->postJson('/api/owner/codes', ['month' => $month])->json('code');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer $driverToken")
            ->postJson('/api/driver/join-code', ['code' => $code, 'month' => $month])->assertStatus(200);

        $this->app['auth']->forgetGuards();
        $ruta = $this->withHeader('Authorization', "Bearer $driverToken")->postJson('/api/rutas', [
            'client_name' => 'Cliente', 'origin' => 'Origen', 'destination' => 'Destino', 'owner_id' => $ownerId,
        ]);
        $ruta->assertStatus(201);
        $rutaId = $ruta->json('id');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer $adminToken")->deleteJson("/api/admin/users/{$driverId}")->assertStatus(200);

        $this->assertDatabaseHas('rutas', ['id' => $rutaId, 'owner_id' => $ownerId, 'driver_id' => null]);
    }
}
