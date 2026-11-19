<?php

namespace Tests\Feature;

use App\Models\DriverCodeEntry;
use App\Models\OwnerDriver;
use App\Models\OwnerMonthCode;
use App\Models\Ruta;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\RutaAutoCompleter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Vehicle $vehicle;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['email' => 'owner@example.test']);
        $this->vehicle = Vehicle::factory()->create(['owner_id' => $this->owner->id]);
        $this->driver = User::factory()->create(['email' => 'driver@example.test']);
    }

    private function as(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $this;
    }

    private function link(array $attrs = []): OwnerDriver
    {
        return OwnerDriver::create(array_merge([
            'owner_id' => $this->owner->id,
            'driver_id' => $this->driver->id,
            'status' => OwnerDriver::STATUS_ACTIVE,
            'invited_via' => OwnerDriver::VIA_EMAIL,
            'accepted_at' => now(),
        ], $attrs));
    }

    private function rutaPayload(array $attrs = []): array
    {
        return array_merge([
            'client_name' => 'Cliente', 'client_phone' => '612345678', 'final_price' => 20,
            'trip_date' => now('Europe/Madrid')->addDay()->toDateString(), 'trip_time' => '10:00',
            'origin' => 'Calahorra', 'destination' => 'Logroño', 'vehicle_id' => $this->vehicle->id,
        ], $attrs);
    }

    public function test_email_invitation_gives_the_same_answer_whether_or_not_the_account_exists(): void
    {
        $known = $this->as($this->owner)->postJson('/api/team/invitations', ['type' => 'email', 'email' => 'driver@example.test']);
        $unknown = $this->as($this->owner)->postJson('/api/team/invitations', ['type' => 'email', 'email' => 'nobody@example.test']);

        $known->assertStatus(202);
        $unknown->assertStatus(202);
        $this->assertSame($known->json(), $unknown->json());

        // Hasta que acepta, el propietario no ve el nombre del invitado.
        $team = $this->as($this->owner)->getJson('/api/team')->assertOk()->json();
        $this->assertCount(2, $team);
        $this->assertNull($team[0]['driver']);
        $this->assertNull($team[1]['driver']);
    }

    public function test_driver_accepts_an_email_invitation_and_joins_the_team(): void
    {
        $this->as($this->owner)->postJson('/api/team/invitations', ['type' => 'email', 'email' => 'DRIVER@example.test'])->assertStatus(202);

        $invitations = $this->as($this->driver)->getJson('/api/me/invitations')->assertOk()->json();
        $this->assertCount(1, $invitations);
        $this->assertSame($this->owner->id, $invitations[0]['owner']['id']);

        $this->as($this->driver)->postJson("/api/me/invitations/{$invitations[0]['id']}/accept")->assertOk();

        $this->assertTrue(OwnerDriver::isActive($this->owner->id, $this->driver->id));
        $this->as($this->driver)->getJson('/api/me/owners')->assertOk()->assertJsonPath('0.owner_id', $this->owner->id);
        $this->assertTrue($this->driver->fresh()->hasDriverCapability());
    }

    public function test_invitation_to_someone_without_account_is_found_after_registering(): void
    {
        $this->as($this->owner)->postJson('/api/team/invitations', ['type' => 'email', 'email' => 'nuevo@example.test'])->assertStatus(202);

        $newcomer = User::factory()->create(['email' => 'nuevo@example.test']);
        $invitations = $this->as($newcomer)->getJson('/api/me/invitations')->assertOk()->json();
        $this->assertCount(1, $invitations);

        $this->as($newcomer)->postJson("/api/me/invitations/{$invitations[0]['id']}/reject")->assertOk();
        $this->assertFalse(OwnerDriver::isActive($this->owner->id, $newcomer->id));
        $this->as($newcomer)->getJson('/api/me/invitations')->assertOk()->assertJsonCount(0);
    }

    public function test_nobody_else_can_accept_an_invitation(): void
    {
        $this->as($this->owner)->postJson('/api/team/invitations', ['type' => 'email', 'email' => 'driver@example.test']);
        $invitation = OwnerDriver::first();

        $intruder = User::factory()->create();
        $this->as($intruder)->postJson("/api/me/invitations/{$invitation->id}/accept")->assertNotFound();
        $this->assertSame(OwnerDriver::STATUS_PENDING, $invitation->fresh()->status);
    }

    public function test_link_invitation_code_is_redeemed_in_the_join_code_field_once(): void
    {
        $code = $this->as($this->owner)->postJson('/api/team/invitations', ['type' => 'link'])
            ->assertCreated()->json('invite_code');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $code);

        $this->as($this->driver)->postJson('/api/driver/join-code', ['code' => $code])
            ->assertOk()->assertJsonPath('type', 'team');
        $this->assertTrue(OwnerDriver::isActive($this->owner->id, $this->driver->id));

        // Es de un solo uso.
        $other = User::factory()->create();
        $this->as($other)->postJson('/api/driver/join-code', ['code' => $code])->assertStatus(422);
    }

    public function test_expired_link_invitation_is_rejected(): void
    {
        OwnerDriver::create([
            'owner_id' => $this->owner->id, 'status' => OwnerDriver::STATUS_PENDING,
            'invited_via' => OwnerDriver::VIA_LINK, 'invite_code' => 'ABCD1234', 'expires_at' => now()->subMinute(),
        ]);

        $this->as($this->driver)->postJson('/api/driver/join-code', ['code' => 'ABCD1234'])->assertStatus(422);
    }

    public function test_a_month_code_does_not_add_a_stranger_to_the_team(): void
    {
        $code = OwnerMonthCode::create([
            'owner_id' => $this->owner->id, 'code' => 'MONTH001', 'month' => now()->format('Y-m'),
            'type' => 'month', 'scope_key' => now()->format('Y-m'),
        ]);

        $this->as($this->driver)->postJson('/api/driver/join-code', ['code' => $code->code])->assertForbidden();

        $this->assertFalse(OwnerDriver::isActive($this->owner->id, $this->driver->id));
        $this->assertDatabaseMissing('driver_code_entries', ['driver_id' => $this->driver->id]);
    }

    public function test_owner_assigns_a_trip_and_the_driver_sees_it_without_any_code(): void
    {
        $this->link();

        $ruta = $this->as($this->owner)->postJson('/api/rutas', $this->rutaPayload(['driver_id' => $this->driver->id]))
            ->assertCreated()->assertJsonPath('driver_id', $this->driver->id)->json();

        $this->as($this->driver)->getJson('/api/rutas')->assertOk()
            ->assertJsonPath('count', 1)->assertJsonPath('data.0.id', $ruta['id']);
        $this->as($this->driver)->patchJson("/api/rutas/{$ruta['id']}", ['status' => 'completed'])->assertOk();
    }

    public function test_owner_cannot_assign_trips_to_someone_outside_the_team(): void
    {
        $this->as($this->owner)->postJson('/api/rutas', $this->rutaPayload(['driver_id' => $this->driver->id]))->assertStatus(422);

        $this->link(['status' => OwnerDriver::STATUS_REVOKED]);
        $this->as($this->owner)->postJson('/api/rutas', $this->rutaPayload(['driver_id' => $this->driver->id]))->assertStatus(422);

        $ruta = Ruta::factory()->create(['owner_id' => $this->owner->id, 'vehicle_id' => $this->vehicle->id, 'driver_id' => null]);
        $this->as($this->owner)->patchJson("/api/rutas/{$ruta->id}", ['driver_id' => $this->driver->id])->assertStatus(422);
    }

    public function test_owner_reassigns_a_trip_and_drivers_cannot(): void
    {
        $link = $this->link();
        $ruta = Ruta::factory()->create(['owner_id' => $this->owner->id, 'vehicle_id' => $this->vehicle->id, 'driver_id' => null]);

        $this->as($this->owner)->patchJson("/api/rutas/{$ruta->id}", ['driver_id' => $this->driver->id])
            ->assertOk()->assertJsonPath('driver_id', $this->driver->id);

        // El conductor puede cambiar el estado, pero no reasignarse el viaje.
        $other = User::factory()->create();
        $this->as($this->driver)->patchJson("/api/rutas/{$ruta->id}", ['driver_id' => $other->id])->assertOk();
        $this->assertSame($this->driver->id, $ruta->fresh()->driver_id);

        // "Yo mismo" = sin conductor asignado.
        $this->as($this->owner)->patchJson("/api/rutas/{$ruta->id}", ['driver_id' => null])->assertOk()->assertJsonPath('driver_id', null);
    }

    public function test_after_leaving_the_team_the_driver_keeps_history_but_not_future_trips(): void
    {
        $link = $this->link();
        $past = Ruta::factory()->create([
            'owner_id' => $this->owner->id, 'vehicle_id' => $this->vehicle->id, 'driver_id' => $this->driver->id,
            'created_by' => $this->owner->id, 'trip_date' => now('Europe/Madrid')->subDays(3)->toDateString(),
        ]);
        $future = Ruta::factory()->create([
            'owner_id' => $this->owner->id, 'vehicle_id' => $this->vehicle->id, 'driver_id' => $this->driver->id,
            'created_by' => $this->owner->id, 'trip_date' => now('Europe/Madrid')->addDays(3)->toDateString(),
        ]);

        $this->as($this->driver)->getJson('/api/rutas')->assertJsonPath('count', 2);

        $this->as($this->owner)->deleteJson("/api/team/{$link->id}")->assertOk();
        $this->assertSame(OwnerDriver::STATUS_REVOKED, $link->fresh()->status);

        $ids = collect($this->as($this->driver)->getJson('/api/rutas')->json('data'))->pluck('id')->all();
        $this->assertSame([$past->id], $ids);
        $this->as($this->driver)->getJson("/api/rutas/{$future->id}")->assertForbidden();
    }

    public function test_driver_can_leave_a_team_and_strangers_cannot_touch_it(): void
    {
        $link = $this->link();
        $stranger = User::factory()->create();

        $this->as($stranger)->deleteJson("/api/team/{$link->id}")->assertForbidden();
        $this->as($this->driver)->deleteJson("/api/team/{$link->id}")->assertOk();
        $this->assertFalse(OwnerDriver::isActive($this->owner->id, $this->driver->id));
    }

    public function test_owner_sets_alias_and_only_own_default_vehicle(): void
    {
        $link = $this->link();
        $foreign = Vehicle::factory()->create();

        $this->as($this->owner)->patchJson("/api/team/{$link->id}", ['default_vehicle_id' => $foreign->id])->assertStatus(422);
        $this->as($this->owner)->patchJson("/api/team/{$link->id}", ['alias' => 'Luis', 'default_vehicle_id' => $this->vehicle->id])
            ->assertOk()->assertJsonPath('alias', 'Luis')->assertJsonPath('default_vehicle_id', $this->vehicle->id);
    }

    public function test_auto_completion_is_attributed_to_the_assigned_driver(): void
    {
        $this->link();
        $ruta = Ruta::factory()->create([
            'owner_id' => $this->owner->id, 'vehicle_id' => $this->vehicle->id, 'driver_id' => $this->driver->id,
            'status' => 'pending', 'trip_date' => '2026-10-06', 'trip_time' => '10:00',
            'estimated_duration_min' => 16, 'estimated_price' => 25, 'final_price' => null, 'payment_method' => null,
        ]);

        app(RutaAutoCompleter::class)->run(CarbonImmutable::parse('2026-10-06 10:40', 'Europe/Madrid'));

        $ruta->refresh();
        $this->assertSame('completed', $ruta->status);
        $this->assertSame($this->driver->id, $ruta->completed_by);
        $this->assertDatabaseHas('ruta_completions', ['ruta_id' => $ruta->id, 'user_id' => $this->driver->id, 'source' => 'auto']);
    }

    public function test_existing_month_code_drivers_are_migrated_to_the_team(): void
    {
        // La migración ya se ejecutó sobre una base vacía: se comprueba que la
        // consulta de migración trata igual los datos actuales.
        $code = OwnerMonthCode::create([
            'owner_id' => $this->owner->id, 'code' => 'MIGRA001', 'month' => '2026-10', 'type' => 'month', 'scope_key' => '2026-10',
        ]);
        DriverCodeEntry::create(['owner_month_code_id' => $code->id, 'driver_id' => $this->driver->id]);

        $migration = require database_path('migrations/2026_11_10_000001_create_owner_drivers_table.php');
        $migration->down();
        $migration->up();

        $this->assertTrue(OwnerDriver::isActive($this->owner->id, $this->driver->id));
    }

    public function test_invitation_link_page_opens_the_app_and_shows_who_invites(): void
    {
        $this->owner->update(['first_name' => 'Pedro', 'last_name' => 'Ruiz']);
        $code = $this->as($this->owner)->postJson('/api/team/invitations', ['type' => 'link'])->json('invite_code');

        $this->get("/i/{$code}")
            ->assertOk()
            ->assertSee('Pedro Ruiz te invita a su equipo')
            ->assertSee("rutero://invite/{$code}", false)
            ->assertSee("intent://invite/{$code}#Intent;scheme=rutero;package=com.rutero.app", false);

        $this->getJson("/api/invitations/{$code}")
            ->assertOk()
            ->assertJsonPath('owner.first_name', 'Pedro')
            ->assertJsonMissingPath('owner.email');
    }

    public function test_used_or_unknown_invitation_links_are_not_valid(): void
    {
        $code = $this->as($this->owner)->postJson('/api/team/invitations', ['type' => 'link'])->json('invite_code');
        $this->as($this->driver)->postJson('/api/driver/join-code', ['code' => $code])->assertOk();

        $this->get("/i/{$code}")->assertNotFound()->assertSee('Invitación no válida');
        $this->getJson("/api/invitations/{$code}")->assertNotFound();
        $this->getJson('/api/invitations/ZZZZ9999')->assertNotFound();
    }

    public function test_a_team_driver_can_use_tomorrows_day_code_today(): void
    {
        OwnerDriver::activate($this->owner->id, $this->driver);
        $tomorrow = now('Europe/Madrid')->addDay()->toDateString();
        $code = OwnerMonthCode::create([
            'owner_id' => $this->owner->id, 'code' => 'DAYCODE1', 'month' => substr($tomorrow, 0, 7),
            'type' => 'day', 'valid_on' => $tomorrow, 'scope_key' => $tomorrow,
        ]);

        // Se puede compartir la víspera: el código de mañana ya sirve hoy.
        $this->as($this->driver)->postJson('/api/driver/join-code', ['code' => $code->code])
            ->assertOk()->assertJsonPath('type', 'day');
    }

    public function test_jornada_codes_work_as_invitation_links(): void
    {
        $this->owner->update(['first_name' => 'Pedro', 'last_name' => 'Ruiz']);
        $today = now('Europe/Madrid')->toDateString();
        OwnerMonthCode::create([
            'owner_id' => $this->owner->id, 'code' => 'JORNADA1', 'month' => substr($today, 0, 7),
            'type' => 'day', 'valid_on' => $today, 'scope_key' => $today,
        ]);
        $yesterday = now('Europe/Madrid')->subDay()->toDateString();
        OwnerMonthCode::create([
            'owner_id' => $this->owner->id, 'code' => 'PASADO01', 'month' => substr($yesterday, 0, 7),
            'type' => 'day', 'valid_on' => $yesterday, 'scope_key' => $yesterday,
        ]);

        $this->get('/i/JORNADA1')->assertOk()
            ->assertSee('Pedro Ruiz te invita a su jornada del '.now('Europe/Madrid')->format('d/m/Y'));
        $this->getJson('/api/invitations/JORNADA1')->assertOk()
            ->assertJsonPath('type', 'day')->assertJsonPath('valid_on', $today);

        $this->get('/i/PASADO01')->assertNotFound();
        $this->getJson('/api/invitations/PASADO01')->assertNotFound();
    }

    private function ownerRuta(array $attrs = []): Ruta
    {
        return Ruta::factory()->create(array_merge([
            'owner_id' => $this->owner->id, 'vehicle_id' => $this->vehicle->id, 'driver_id' => null,
            'created_by' => $this->owner->id, 'status' => 'pending', 'payment_method' => null,
            'trip_date' => now('Europe/Madrid')->toDateString(), 'trip_time' => '10:00',
        ], $attrs));
    }

    public function test_team_drivers_see_unassigned_trips_from_the_day_they_joined_but_not_other_drivers_trips(): void
    {
        $this->link(['accepted_at' => now()->subDay()]);
        $colleague = User::factory()->create();
        $this->link(['driver_id' => $colleague->id]);

        $unassigned = $this->ownerRuta();
        $beforeJoining = $this->ownerRuta(['trip_date' => now('Europe/Madrid')->subDays(5)->toDateString()]);
        $colleagues = $this->ownerRuta(['driver_id' => $colleague->id]);

        $ids = collect($this->as($this->driver)->getJson('/api/rutas')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$unassigned->id], $ids);
        $this->as($this->driver)->getJson("/api/rutas/{$beforeJoining->id}")->assertForbidden();
        $this->as($this->driver)->getJson("/api/rutas/{$colleagues->id}")->assertForbidden();
    }

    public function test_a_driver_who_completes_an_unassigned_trip_becomes_its_driver(): void
    {
        $this->link();
        $ruta = $this->ownerRuta();

        $this->as($this->driver)->patchJson("/api/rutas/{$ruta->id}", ['status' => 'completed'])
            ->assertOk()->assertJsonPath('driver_id', $this->driver->id);

        $this->assertDatabaseHas('ruta_completions', ['ruta_id' => $ruta->id, 'user_id' => $this->driver->id, 'source' => 'manual']);
    }

    public function test_a_driver_who_confirms_an_auto_closed_unassigned_trip_becomes_its_driver(): void
    {
        $this->link();
        $ruta = $this->ownerRuta(['status' => 'completed', 'auto_completed' => true, 'auto_completed_at' => now()]);

        $this->as($this->driver)->postJson("/api/rutas/{$ruta->id}/review")->assertOk();

        $ruta->refresh();
        $this->assertSame($this->driver->id, $ruta->driver_id);
        $this->assertNotNull($ruta->reviewed_at);
        $this->assertDatabaseHas('ruta_completions', ['ruta_id' => $ruta->id, 'user_id' => $this->driver->id]);
    }

    public function test_when_the_owner_completes_or_confirms_the_driver_is_not_changed(): void
    {
        $this->link();
        $assigned = $this->ownerRuta(['driver_id' => $this->driver->id]);
        $unassigned = $this->ownerRuta(['status' => 'completed', 'auto_completed' => true, 'auto_completed_at' => now()]);

        $this->as($this->owner)->patchJson("/api/rutas/{$assigned->id}", ['status' => 'completed'])
            ->assertOk()->assertJsonPath('driver_id', $this->driver->id);
        $this->as($this->owner)->postJson("/api/rutas/{$unassigned->id}/review")->assertOk();

        $this->assertNull($unassigned->fresh()->driver_id);
    }

    public function test_drivers_outside_the_team_cannot_take_unassigned_trips(): void
    {
        $ruta = $this->ownerRuta();
        $this->as($this->driver)->patchJson("/api/rutas/{$ruta->id}", ['status' => 'completed'])->assertForbidden();
        $this->as($this->driver)->postJson("/api/rutas/{$ruta->id}/review")->assertForbidden();
        $this->assertNull($ruta->fresh()->driver_id);
    }

    public function test_owner_marks_a_single_default_driver_used_when_no_driver_is_given(): void
    {
        $luis = $this->link();
        $ana = User::factory()->create();
        $anaLink = $this->link(['driver_id' => $ana->id]);

        $this->as($this->owner)->patchJson("/api/team/{$luis->id}", ['is_default' => true])->assertOk()->assertJsonPath('is_default', true);
        $this->as($this->owner)->patchJson("/api/team/{$anaLink->id}", ['is_default' => true])->assertOk();
        $this->assertFalse($luis->fresh()->is_default);
        $this->assertTrue($anaLink->fresh()->is_default);

        // Sin driver_id en la petición: el predeterminado. Con driver_id null: el propietario.
        $this->as($this->owner)->postJson('/api/rutas', $this->rutaPayload())->assertCreated()->assertJsonPath('driver_id', $ana->id);
        $this->as($this->owner)->postJson('/api/rutas', $this->rutaPayload(['driver_id' => null]))->assertCreated()->assertJsonPath('driver_id', null);
    }

    public function test_a_driver_who_leaves_stops_being_the_default_and_pending_links_cannot_be_default(): void
    {
        $link = $this->link(['is_default' => true]);
        $this->as($this->driver)->deleteJson("/api/team/{$link->id}")->assertOk();
        $this->assertFalse($link->fresh()->is_default);
        $this->as($this->owner)->postJson('/api/rutas', $this->rutaPayload())->assertCreated()->assertJsonPath('driver_id', null);

        $pending = OwnerDriver::create([
            'owner_id' => $this->owner->id, 'invited_email' => 'x@example.test',
            'status' => OwnerDriver::STATUS_PENDING, 'invited_via' => OwnerDriver::VIA_EMAIL,
        ]);
        $this->as($this->owner)->patchJson("/api/team/{$pending->id}", ['is_default' => true])->assertStatus(422);
    }
    public function test_invitation_email_is_sent_when_mail_is_configured_without_revealing_accounts(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        config(['rutero.invitations.send_email' => true]);

        $known = $this->as($this->owner)->postJson('/api/team/invitations', ['type' => 'email', 'email' => 'driver@example.test'])->assertStatus(202);
        $unknown = $this->as($this->owner)->postJson('/api/team/invitations', ['type' => 'email', 'email' => 'nadie@example.test'])->assertStatus(202);

        $this->assertSame($known->json(), $unknown->json());
        $this->assertTrue($known->json('email_sent'));
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\TeamInvitationMail::class, 2);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\TeamInvitationMail::class, fn ($m) => $m->hasTo('nadie@example.test'));
    }

    public function test_invitation_emails_respect_the_daily_limit_and_skip_active_members(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        config(['rutero.invitations.send_email' => true, 'rutero.invitations.max_emails_per_day' => 1]);

        $this->as($this->owner)->postJson('/api/team/invitations', ['type' => 'email', 'email' => 'a@example.test'])->assertJsonPath('email_sent', true);
        $this->as($this->owner)->postJson('/api/team/invitations', ['type' => 'email', 'email' => 'b@example.test'])->assertJsonPath('email_sent', false);

        config(['rutero.invitations.max_emails_per_day' => 10]);
        $this->link();
        $this->as($this->owner)->postJson('/api/team/invitations', ['type' => 'email', 'email' => 'driver@example.test'])->assertJsonPath('email_sent', false);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\TeamInvitationMail::class, 1);
    }

    public function test_without_mail_service_no_email_is_sent(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        config(['rutero.invitations.send_email' => false]);

        $this->as($this->owner)->postJson('/api/team/invitations', ['type' => 'email', 'email' => 'x@example.test'])->assertJsonPath('email_sent', false);
        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }
}
