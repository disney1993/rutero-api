<?php

namespace Tests\Feature;

use App\Mail\JornadaAssignedMail;
use App\Models\Jornada;
use App\Models\OwnerDriver;
use App\Models\Ruta;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JornadaTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Vehicle $vehicle;

    private Vehicle $van;

    private User $luis;

    private User $ana;

    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->vehicle = Vehicle::factory()->create(['owner_id' => $this->owner->id, 'is_default' => true]);
        $this->van = Vehicle::factory()->create(['owner_id' => $this->owner->id, 'is_default' => false]);
        $this->luis = User::factory()->create();
        $this->ana = User::factory()->create();
        foreach ([$this->luis, $this->ana] as $driver) {
            OwnerDriver::create([
                'owner_id' => $this->owner->id, 'driver_id' => $driver->id,
                'status' => OwnerDriver::STATUS_ACTIVE, 'invited_via' => OwnerDriver::VIA_EMAIL, 'accepted_at' => now()->subDay(),
            ]);
        }
        $this->day = now('Europe/Madrid')->addDay()->toDateString();
    }

    private function as(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $this;
    }

    private function rutaPayload(array $attrs = []): array
    {
        return array_merge([
            'client_name' => 'Cliente', 'client_phone' => '612345678', 'final_price' => 20,
            'trip_date' => $this->day, 'trip_time' => '10:00', 'origin' => 'Calahorra', 'destination' => 'Logroño',
        ], $attrs);
    }

    public function test_owner_creates_several_jornadas_the_same_day(): void
    {
        $first = $this->as($this->owner)->postJson('/api/jornadas', ['date' => $this->day])->assertCreated()->json();
        $second = $this->as($this->owner)->postJson('/api/jornadas', ['date' => $this->day, 'driver_id' => $this->ana->id, 'vehicle_id' => $this->van->id])
            ->assertCreated()->json();

        $this->assertSame('Jornada 1', $first['label']);
        $this->assertSame('Jornada 2', $second['label']);
        $this->assertArrayNotHasKey('code', $first);
        $this->assertSame($this->ana->id, $second['driver_id']);

        $this->as($this->owner)->getJson("/api/jornadas?date={$this->day}")->assertOk()->assertJsonCount(2)
            ->assertJsonPath('1.driver.id', $this->ana->id);
    }

    public function test_the_first_jornada_adopts_existing_trips_and_new_trips_inherit_driver_and_vehicle(): void
    {
        $orphan = $this->as($this->owner)->postJson('/api/rutas', $this->rutaPayload(['vehicle_id' => $this->vehicle->id]))->assertCreated()->json();
        $this->assertNull($orphan['jornada_id']);

        $j1 = $this->as($this->owner)->postJson('/api/jornadas', ['date' => $this->day])->json();
        $this->assertSame($j1['id'], Ruta::find($orphan['id'])->jornada_id);

        $j2 = $this->as($this->owner)->postJson('/api/jornadas', ['date' => $this->day, 'driver_id' => $this->ana->id, 'vehicle_id' => $this->van->id])->json();

        $this->as($this->owner)->postJson('/api/rutas', $this->rutaPayload(['jornada_id' => $j2['id']]))->assertCreated()
            ->assertJsonPath('jornada_id', $j2['id'])
            ->assertJsonPath('driver_id', $this->ana->id)
            ->assertJsonPath('vehicle_id', $this->van->id);

        // Sin jornada indicada: la primera del día.
        $this->as($this->owner)->postJson('/api/rutas', $this->rutaPayload(['vehicle_id' => $this->vehicle->id]))->assertCreated()
            ->assertJsonPath('jornada_id', $j1['id']);
    }

    public function test_a_jornada_from_another_day_or_owner_is_rejected(): void
    {
        $other = Jornada::createFor($this->owner, now('Europe/Madrid')->addDays(5)->toDateString());
        $foreign = Jornada::createFor(User::factory()->create(), $this->day);

        $this->as($this->owner)->postJson('/api/rutas', $this->rutaPayload(['jornada_id' => $other->id, 'vehicle_id' => $this->vehicle->id]))->assertStatus(422);
        $this->as($this->owner)->postJson('/api/rutas', $this->rutaPayload(['jornada_id' => $foreign->id, 'vehicle_id' => $this->vehicle->id]))->assertStatus(422);
    }

    public function test_changing_the_jornada_driver_moves_its_pending_trips_but_not_completed_ones(): void
    {
        $j = Jornada::createFor($this->owner, $this->day, ['driver_id' => $this->luis->id]);
        $pending = $this->as($this->owner)->postJson('/api/rutas', $this->rutaPayload(['jornada_id' => $j->id]))->json();
        $done = Ruta::factory()->create([
            'owner_id' => $this->owner->id, 'vehicle_id' => $this->vehicle->id, 'jornada_id' => $j->id,
            'driver_id' => $this->luis->id, 'status' => 'completed', 'trip_date' => $this->day,
        ]);

        $this->as($this->owner)->patchJson("/api/jornadas/{$j->id}", ['driver_id' => $this->ana->id])->assertOk()
            ->assertJsonPath('driver.id', $this->ana->id);

        $this->assertSame($this->ana->id, Ruta::find($pending['id'])->driver_id);
        $this->assertSame($this->luis->id, $done->fresh()->driver_id);
    }

    public function test_moving_a_pending_trip_to_another_jornada_takes_its_driver(): void
    {
        $j1 = Jornada::createFor($this->owner, $this->day, ['driver_id' => $this->luis->id]);
        $j2 = Jornada::createFor($this->owner, $this->day, ['driver_id' => $this->ana->id]);
        $ruta = $this->as($this->owner)->postJson('/api/rutas', $this->rutaPayload(['jornada_id' => $j1->id]))->json();

        $this->as($this->owner)->patchJson("/api/rutas/{$ruta['id']}", ['jornada_id' => $j2->id])->assertOk()
            ->assertJsonPath('jornada_id', $j2->id)->assertJsonPath('driver_id', $this->ana->id);
    }

    public function test_only_team_drivers_and_own_vehicles_can_be_assigned_to_a_jornada(): void
    {
        $stranger = User::factory()->create();
        $foreignVehicle = Vehicle::factory()->create();

        $this->as($this->owner)->postJson('/api/jornadas', ['date' => $this->day, 'driver_id' => $stranger->id])->assertStatus(422);
        $j = Jornada::createFor($this->owner, $this->day);
        $this->as($this->owner)->patchJson("/api/jornadas/{$j->id}", ['vehicle_id' => $foreignVehicle->id])->assertStatus(422);
        $this->as($this->luis)->patchJson("/api/jornadas/{$j->id}", ['label' => 'Mía'])->assertForbidden();
    }

    public function test_a_jornada_with_trips_cannot_be_deleted_and_an_empty_one_can(): void
    {
        $j1 = Jornada::createFor($this->owner, $this->day);
        $j2 = Jornada::createFor($this->owner, $this->day);
        $this->as($this->owner)->postJson('/api/rutas', $this->rutaPayload(['jornada_id' => $j1->id, 'vehicle_id' => $this->vehicle->id]));

        $this->as($this->owner)->deleteJson("/api/jornadas/{$j1->id}")->assertStatus(422);
        $this->as($this->owner)->deleteJson("/api/jornadas/{$j2->id}")->assertOk();
        $this->assertModelMissing($j2);
    }

    public function test_removing_a_driver_frees_their_future_jornadas_and_pending_trips(): void
    {
        $j = Jornada::createFor($this->owner, $this->day, ['driver_id' => $this->luis->id]);
        $ruta = $this->as($this->owner)->postJson('/api/rutas', $this->rutaPayload(['jornada_id' => $j->id]))->json();
        $link = OwnerDriver::where('owner_id', $this->owner->id)->where('driver_id', $this->luis->id)->first();

        $this->as($this->owner)->deleteJson("/api/team/{$link->id}")->assertOk();

        $this->assertNull($j->fresh()->driver_id);
        $this->assertNull(Ruta::find($ruta['id'])->driver_id);
    }

    // ---------------------------------- conductores asignados y su aviso

    private function visibleIds(User $driver): array
    {
        return collect($this->as($driver)->getJson('/api/rutas')->assertOk()->json('data'))->pluck('id')->all();
    }

    private function unassignedTripIn(Jornada $j): int
    {
        return $this->as($this->owner)->postJson('/api/rutas', $this->rutaPayload(['jornada_id' => $j->id, 'driver_id' => null]))
            ->assertCreated()->json('id');
    }

    private function assignment(Jornada $j, User $driver): ?object
    {
        return DB::table('jornada_drivers')->where('jornada_id', $j->id)->where('driver_id', $driver->id)->first();
    }

    public function test_a_jornada_for_its_drivers_only_shows_its_unassigned_trips_to_them(): void
    {
        $pedro = User::factory()->create();
        OwnerDriver::activate($this->owner->id, $pedro);
        $j = Jornada::createFor($this->owner, $this->day, ['driver_id' => null]);
        $this->as($this->owner)->patchJson("/api/jornadas/{$j->id}", ['driver_ids' => [$this->ana->id, $pedro->id], 'visibility' => 'drivers'])
            ->assertOk()->assertJsonCount(2, 'drivers')->assertJsonPath('visibility', 'drivers');
        $ruta = $this->unassignedTripIn($j);

        $this->assertSame([$ruta], $this->visibleIds($this->ana));
        $this->assertSame([$ruta], $this->visibleIds($pedro));
        $this->assertSame([], $this->visibleIds($this->luis));
        $this->as($this->luis)->getJson("/api/rutas/{$ruta}")->assertForbidden();
        $this->as($this->luis)->patchJson("/api/rutas/{$ruta}", ['status' => 'completed'])->assertForbidden();

        // Quien la coge primero se la queda.
        $this->as($pedro)->patchJson("/api/rutas/{$ruta}", ['status' => 'completed'])
            ->assertOk()->assertJsonPath('driver_id', $pedro->id);
    }

    public function test_a_jornada_for_the_whole_team_is_visible_to_everyone_in_it(): void
    {
        $j = Jornada::createFor($this->owner, $this->day, ['driver_id' => null]);
        $this->as($this->owner)->patchJson("/api/jornadas/{$j->id}", ['driver_ids' => [$this->ana->id]])
            ->assertOk()->assertJsonPath('visibility', 'team');
        $ruta = $this->unassignedTripIn($j);

        $this->assertSame([$ruta], $this->visibleIds($this->luis));
        $this->assertSame([$ruta], $this->visibleIds($this->ana));
    }

    public function test_assigning_a_driver_always_records_one_notice_and_reassigning_does_not_repeat_it(): void
    {
        $j = Jornada::createFor($this->owner, $this->day, ['driver_id' => null]);
        $this->as($this->owner)->patchJson("/api/jornadas/{$j->id}", ['driver_ids' => [$this->ana->id], 'visibility' => 'drivers'])->assertOk();
        $first = $this->assignment($j, $this->ana);
        $this->assertNotNull($first->notified_at);
        $ruta = $this->unassignedTripIn($j);

        // Se la quitan: deja de verla, pero el aviso sigue registrado.
        $this->travel(1)->hours();
        $this->as($this->owner)->patchJson("/api/jornadas/{$j->id}", ['driver_ids' => []])->assertOk()->assertJsonCount(0, 'drivers');
        $this->assertSame([], $this->visibleIds($this->ana));
        $this->assertNotNull($this->assignment($j, $this->ana)->removed_at);

        // Se la vuelven a asignar: la ve otra vez, sin otro aviso.
        $this->as($this->owner)->patchJson("/api/jornadas/{$j->id}", ['driver_ids' => [$this->ana->id]])->assertOk();
        $again = $this->assignment($j, $this->ana);
        $this->assertNull($again->removed_at);
        $this->assertSame($first->notified_at, $again->notified_at);
        $this->assertSame([$ruta], $this->visibleIds($this->ana));
    }

    public function test_the_notice_is_emailed_once_when_mail_is_configured(): void
    {
        Mail::fake();
        config(['rutero.invitations.send_email' => true]);
        $j = Jornada::createFor($this->owner, $this->day, ['driver_id' => null]);

        $this->as($this->owner)->patchJson("/api/jornadas/{$j->id}", ['driver_ids' => [$this->ana->id]])->assertOk();
        $this->as($this->owner)->patchJson("/api/jornadas/{$j->id}", ['driver_ids' => []])->assertOk();
        $this->as($this->owner)->patchJson("/api/jornadas/{$j->id}", ['driver_ids' => [$this->ana->id]])->assertOk();

        Mail::assertSent(JornadaAssignedMail::class, 1);
        Mail::assertSent(JornadaAssignedMail::class, fn ($m) => $m->hasTo($this->ana->email));
        $this->assertNotNull($this->assignment($j, $this->ana)->email_sent_at);
    }

    public function test_the_main_driver_also_gets_the_notice(): void
    {
        $created = $this->as($this->owner)->postJson('/api/jornadas', ['date' => $this->day, 'driver_id' => $this->luis->id])
            ->assertCreated()->json();
        $this->assertNotNull($this->assignment(Jornada::find($created['id']), $this->luis)?->notified_at);

        // Cambiar de principal sin tocar la lista: sale el anterior, entra el nuevo.
        $this->as($this->owner)->patchJson("/api/jornadas/{$created['id']}", ['driver_id' => $this->ana->id])->assertOk();
        $ids = collect($this->as($this->owner)->getJson("/api/jornadas?date={$this->day}")->json('0.drivers'))->pluck('id')->all();
        $this->assertSame([$this->ana->id], $ids);
    }

    public function test_the_driver_sees_their_jornadas_and_marks_the_notice_as_seen(): void
    {
        $j = Jornada::createFor($this->owner, $this->day, ['driver_id' => null]);
        $this->as($this->owner)->patchJson("/api/jornadas/{$j->id}", ['driver_ids' => [$this->ana->id]])->assertOk();

        $this->as($this->ana)->getJson('/api/me/jornadas')->assertOk()
            ->assertJsonCount(1)->assertJsonPath('0.id', $j->id)->assertJsonPath('0.seen_at', null);
        $this->as($this->luis)->getJson('/api/me/jornadas')->assertOk()->assertJsonCount(0);

        $this->as($this->ana)->postJson("/api/me/jornadas/{$j->id}/seen")->assertOk();
        $this->as($this->luis)->postJson("/api/me/jornadas/{$j->id}/seen")->assertNotFound();
        $this->assertNotNull($this->assignment($j, $this->ana)->seen_at);
        $this->assertNotNull($this->as($this->owner)->getJson("/api/jornadas?date={$this->day}")->json('0.drivers.0.pivot.seen_at'));
    }

    public function test_only_team_drivers_can_be_assigned_and_other_changes_keep_the_assignment(): void
    {
        $stranger = User::factory()->create();
        $created = $this->as($this->owner)->postJson('/api/jornadas', ['date' => $this->day, 'driver_ids' => [$this->ana->id]])
            ->assertCreated()->assertJsonPath('drivers.0.id', $this->ana->id)->json();

        $this->as($this->owner)->patchJson("/api/jornadas/{$created['id']}", ['driver_ids' => [$stranger->id]])->assertStatus(422);
        $this->as($this->owner)->patchJson("/api/jornadas/{$created['id']}", ['label' => 'Mañana'])
            ->assertOk()->assertJsonCount(1, 'drivers');
    }

    public function test_a_driver_who_leaves_the_team_loses_access_and_the_jornada_stays_restricted(): void
    {
        $j = Jornada::createFor($this->owner, $this->day, ['driver_id' => null]);
        $this->as($this->owner)->patchJson("/api/jornadas/{$j->id}", ['driver_ids' => [$this->ana->id], 'visibility' => 'drivers'])->assertOk();
        $this->unassignedTripIn($j);

        OwnerDriver::where('owner_id', $this->owner->id)->where('driver_id', $this->ana->id)
            ->update(['status' => OwnerDriver::STATUS_REVOKED]);

        $this->assertSame([], $this->visibleIds($this->ana));
        $this->as($this->ana)->getJson('/api/me/jornadas')->assertOk()->assertJsonCount(0);
        $this->assertSame([], $this->visibleIds($this->luis));
    }
}
