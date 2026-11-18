<?php

namespace Tests\Feature;

use App\Models\Jornada;
use App\Models\ModelAudit;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JornadaApiTest extends TestCase
{
    use RefreshDatabase;

    private function registerOwner(string $email = 'owner@jornada.test'): array
    {
        $resp = $this->postJson('/api/register', [
            'first_name' => 'Owner',
            'last_name' => 'Jornada',
            'email' => $email,
            'password' => 'Secret123!',
        ]);
        $token = $resp->json('access_token');
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/vehicles', ['plate' => 'JOR-001'])
            ->assertCreated();

        return [$token, $resp->json('user.id')];
    }

    public function test_owner_can_create_up_to_three_jornadas_per_day(): void
    {
        [$token] = $this->registerOwner();
        $date = date('Y-m-d');

        foreach (['A', 'B', 'C'] as $label) {
            $this->withHeaders(['Authorization' => 'Bearer '.$token])
                ->postJson('/api/jornadas', ['date' => $date])
                ->assertCreated()
                ->assertJsonPath('label', $label)
                ->assertJsonPath('allow_create', true)
                ->assertJsonPath('allow_edit', true)
                ->assertJsonPath('allow_delete', false);
        }

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/jornadas', ['date' => $date])
            ->assertStatus(422);
    }

    public function test_driver_joins_jornada_and_creates_ruta_with_owner_default_vehicle(): void
    {
        [$otoken, $ownerId] = $this->registerOwner('owner2@jornada.test');
        $date = date('Y-m-d');

        $jornada = $this->withHeaders(['Authorization' => 'Bearer '.$otoken])
            ->postJson('/api/jornadas', ['date' => $date])
            ->assertCreated()
            ->json();

        $defaultVehicleId = Vehicle::where('owner_id', $ownerId)->where('is_default', true)->value('id');

        $dreg = $this->postJson('/api/register', [
            'first_name' => 'Driver',
            'last_name' => 'Jornada',
            'email' => 'driver@jornada.test',
            'password' => 'Secret123!',
        ]);
        $dtoken = $dreg->json('access_token');

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$dtoken])
            ->postJson('/api/jornadas/join', ['code' => $jornada['code']])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $vehicles = $this->withHeaders(['Authorization' => 'Bearer '.$dtoken])
            ->getJson('/api/jornadas/'.$jornada['id'].'/vehicles')
            ->assertOk()
            ->json();
        $this->assertSame($defaultVehicleId, $vehicles['default_vehicle_id']);

        $this->app['auth']->forgetGuards();
        $ruta = $this->withHeaders(['Authorization' => 'Bearer '.$dtoken])
            ->postJson('/api/rutas', [
                'client_name' => 'Cliente',
                'client_phone' => '+34600111222',
                'origin' => 'Origen A',
                'destination' => 'Destino B',
                'trip_date' => $date,
                'trip_time' => '10:00',
                'final_price' => 50,
                'jornada_id' => $jornada['id'],
            ])
            ->assertCreated()
            ->json();

        $this->assertSame($ownerId, $ruta['owner_id']);
        $this->assertSame($dreg->json('user.id'), $ruta['driver_id']);
        $this->assertSame($jornada['id'], $ruta['jornada_id']);
        $this->assertSame($defaultVehicleId, $ruta['vehicle_id']);
        $this->assertSame($date, substr($ruta['trip_date'], 0, 10));
    }

    public function test_driver_cannot_delete_when_allow_delete_is_false(): void
    {
        [$otoken, $ownerId] = $this->registerOwner('owner3@jornada.test');
        $date = date('Y-m-d');
        $jornada = $this->withHeaders(['Authorization' => 'Bearer '.$otoken])
            ->postJson('/api/jornadas', ['date' => $date])
            ->json();

        $dreg = $this->postJson('/api/register', [
            'first_name' => 'Driver', 'last_name' => 'NoDel',
            'email' => 'nodel@jornada.test', 'password' => 'Secret123!',
        ]);
        $dtoken = $dreg->json('access_token');

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$dtoken])
            ->postJson('/api/jornadas/join', ['code' => $jornada['code']])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $rutaId = $this->withHeaders(['Authorization' => 'Bearer '.$dtoken])
            ->postJson('/api/rutas', [
                'client_name' => 'Cliente',
                'client_phone' => '+34600111222',
                'origin' => 'Origen',
                'destination' => 'Destino',
                'trip_date' => $date,
                'trip_time' => '10:00',
                'final_price' => 50,
                'jornada_id' => $jornada['id'],
            ])
            ->assertCreated()
            ->json('id');

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$dtoken])
            ->deleteJson('/api/rutas/'.$rutaId)
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$otoken])
            ->patchJson('/api/jornadas/'.$jornada['id'], ['allow_delete' => true])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$dtoken])
            ->deleteJson('/api/rutas/'.$rutaId)
            ->assertOk();

        $this->assertSoftDeleted('rutas', ['id' => $rutaId]);
    }

    public function test_audits_are_recorded_and_visible_to_admin_only(): void
    {
        [$token, $ownerId] = $this->registerOwner('owner4@jornada.test');
        $vehicle = Vehicle::where('owner_id', $ownerId)->first();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->patchJson('/api/vehicles/'.$vehicle->id, ['color' => 'rojo'])
            ->assertOk();

        $this->assertTrue(ModelAudit::where('auditable_type', Vehicle::class)->where('auditable_id', $vehicle->id)->exists());

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/admin/vehicles/'.$vehicle->id.'/audits')
            ->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/vehicles/'.$vehicle->id.'/audits')
            ->assertOk()
            ->assertJsonStructure(['data', 'count']);
    }

    public function test_setting_default_vehicle_clears_previous_default(): void
    {
        [$token, $ownerId] = $this->registerOwner('owner5@jornada.test');
        $first = Vehicle::where('owner_id', $ownerId)->first();
        $this->assertTrue($first->is_default);

        $secondId = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/vehicles', ['plate' => 'JOR-002'])
            ->assertCreated()
            ->json('id');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->patchJson('/api/vehicles/'.$secondId.'/default')
            ->assertOk()
            ->assertJsonPath('is_default', true);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue(Vehicle::find($secondId)->is_default);
    }
}
