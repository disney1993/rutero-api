<?php

namespace Tests\Feature;

use App\Models\Ruta;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\RutaAutoCompleter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RutaAutoCompleteTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->vehicle = Vehicle::factory()->create(['owner_id' => $this->owner->id]);
    }

    /** "Ahora" en hora de Madrid, que es como se interpretan trip_date/trip_time. */
    private function at(string $localDateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($localDateTime, 'Europe/Madrid');
    }

    private function ruta(array $attrs = []): Ruta
    {
        return Ruta::factory()->create(array_merge([
            'owner_id' => $this->owner->id,
            'vehicle_id' => $this->vehicle->id,
            'driver_id' => null,
            'status' => 'pending',
            'trip_date' => '2026-10-06',
            'trip_time' => '10:00',
            'estimated_duration_min' => 16,
            'estimated_price' => 25,
            'final_price' => null,
            'payment_method' => null,
        ], $attrs));
    }

    private function run_at(string $localDateTime): int
    {
        return app(RutaAutoCompleter::class)->run($this->at($localDateTime));
    }

    public function test_it_closes_a_trip_when_its_estimated_end_is_reached(): void
    {
        // 10 min de espera + 16 de trayecto + 10 % (2) + 5 de margen final = cierre a las 10:33.
        $ruta = $this->ruta();

        $this->assertSame(0, $this->run_at('2026-10-06 10:32'));
        $this->assertSame('pending', $ruta->fresh()->status);

        $this->assertSame(1, $this->run_at('2026-10-06 10:33'));
        $ruta->refresh();
        $this->assertSame('completed', $ruta->status);
        $this->assertSame('efectivo', $ruta->payment_method);
        $this->assertEquals(25, $ruta->final_price);
        $this->assertTrue($ruta->auto_completed);
        $this->assertNotNull($ruta->auto_completed_at);
        $this->assertTrue($ruta->needs_review);
    }

    public function test_long_trips_stay_open_until_their_own_estimated_end(): void
    {
        // Calahorra → Aeropuerto de Madrid: 10 + 246 + 25 + 5 = 4 h 46 min.
        $ruta = $this->ruta(['trip_time' => '06:00', 'estimated_duration_min' => 246]);

        $this->run_at('2026-10-06 10:45');
        $this->assertSame('pending', $ruta->fresh()->status);

        $this->run_at('2026-10-06 10:46');
        $this->assertSame('completed', $ruta->fresh()->status);
    }

    public function test_the_next_trip_of_the_same_vehicle_closes_the_previous_one_early(): void
    {
        $long = $this->ruta(['trip_time' => '06:00', 'estimated_duration_min' => 246]);
        $this->ruta(['trip_time' => '09:00']);

        $this->run_at('2026-10-06 08:59');
        $this->assertSame('pending', $long->fresh()->status);

        $this->run_at('2026-10-06 09:00');
        $this->assertSame('completed', $long->fresh()->status);
    }

    public function test_chaining_is_evaluated_per_driver_not_across_the_whole_system(): void
    {
        $driverA = User::factory()->create();
        $driverB = User::factory()->create();
        $a = $this->ruta(['driver_id' => $driverA->id, 'trip_time' => '06:00', 'estimated_duration_min' => 246]);
        // Otro conductor empieza un viaje: no dice nada del viaje de A.
        $this->ruta(['driver_id' => $driverB->id, 'trip_time' => '08:00']);

        $this->run_at('2026-10-06 08:30');
        $this->assertSame('pending', $a->fresh()->status);
    }

    public function test_cancelled_next_trips_do_not_close_the_previous_one(): void
    {
        $long = $this->ruta(['trip_time' => '06:00', 'estimated_duration_min' => 246]);
        $this->ruta(['trip_time' => '08:00', 'status' => 'cancelled']);

        $this->run_at('2026-10-06 08:30');
        $this->assertSame('pending', $long->fresh()->status);
    }

    public function test_it_estimates_the_duration_from_distance_when_osrm_duration_is_missing(): void
    {
        // 50 km a 50 km/h = 60 min; 10 + 60 + 6 + 5 = cierre a las 11:21.
        $ruta = $this->ruta(['estimated_duration_min' => null, 'estimated_distance_km' => 50]);

        $this->run_at('2026-10-06 11:20');
        $this->assertSame('pending', $ruta->fresh()->status);
        $this->run_at('2026-10-06 11:21');
        $this->assertSame('completed', $ruta->fresh()->status);
    }

    public function test_it_uses_the_owner_default_duration_when_there_is_no_distance(): void
    {
        $this->owner->update(['auto_complete_default_duration_min' => 30]);
        // 10 + 30 + 3 + 5 = cierre a las 10:48.
        $ruta = $this->ruta(['estimated_duration_min' => null, 'estimated_distance_km' => null]);

        $this->run_at('2026-10-06 10:47');
        $this->assertSame('pending', $ruta->fresh()->status);
        $this->run_at('2026-10-06 10:48');
        $this->assertSame('completed', $ruta->fresh()->status);
    }

    public function test_trips_without_time_are_closed_at_the_end_of_their_day(): void
    {
        $ruta = $this->ruta(['trip_time' => null]);

        $this->run_at('2026-10-06 23:58');
        $this->assertSame('pending', $ruta->fresh()->status);
        $this->run_at('2026-10-07 00:00');
        $this->assertSame('completed', $ruta->fresh()->status);
    }

    public function test_it_keeps_payment_and_price_already_set(): void
    {
        $ruta = $this->ruta(['payment_method' => 'tarjeta', 'final_price' => 30]);

        $this->run_at('2026-10-06 12:00');
        $ruta->refresh();
        $this->assertSame('completed', $ruta->status);
        $this->assertSame('tarjeta', $ruta->payment_method);
        $this->assertEquals(30, $ruta->final_price);
    }

    public function test_it_never_touches_cancelled_or_manually_completed_trips(): void
    {
        $cancelled = $this->ruta(['status' => 'cancelled']);
        $manual = $this->ruta(['status' => 'completed', 'payment_method' => 'bizum']);

        $this->assertSame(0, $this->run_at('2026-10-06 23:00'));
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertFalse($manual->fresh()->auto_completed);
    }

    public function test_owners_can_disable_auto_completion(): void
    {
        $this->owner->update(['auto_complete_enabled' => false]);
        $ruta = $this->ruta();

        $this->run_at('2026-10-06 23:00');
        $this->assertSame('pending', $ruta->fresh()->status);
    }

    public function test_the_artisan_command_runs(): void
    {
        $this->artisan('rutas:auto-complete')->assertSuccessful();
    }

    public function test_api_exposes_the_expected_auto_complete_time(): void
    {
        Sanctum::actingAs($this->owner);
        $ruta = $this->ruta();

        $this->getJson("/api/rutas/{$ruta->id}")
            ->assertOk()
            ->assertJsonPath('auto_complete_eta', '2026-10-06T10:33')
            ->assertJsonPath('needs_review', false);
    }

    public function test_review_endpoint_and_needs_review_filter(): void
    {
        Sanctum::actingAs($this->owner);
        $ruta = $this->ruta();
        $this->run_at('2026-10-06 12:00');

        $this->getJson('/api/rutas?needs_review=1')->assertOk()->assertJsonPath('count', 1);

        $this->postJson("/api/rutas/{$ruta->id}/review")->assertOk()->assertJsonPath('needs_review', false);

        $this->getJson('/api/rutas?needs_review=1')->assertOk()->assertJsonPath('count', 0);
    }

    public function test_undoing_an_auto_completion_is_not_reverted_by_the_cron(): void
    {
        Sanctum::actingAs($this->owner);
        $ruta = $this->ruta();
        $this->run_at('2026-10-06 12:00');

        $this->putJson("/api/rutas/{$ruta->id}", ['status' => 'pending'])
            ->assertOk()
            ->assertJsonPath('auto_completed', false)
            ->assertJsonPath('needs_review', false);

        $this->run_at('2026-10-06 13:00');
        $this->assertSame('pending', $ruta->fresh()->status);
    }

    public function test_editing_payment_of_an_auto_completed_trip_marks_it_as_reviewed(): void
    {
        Sanctum::actingAs($this->owner);
        $ruta = $this->ruta();
        $this->run_at('2026-10-06 12:00');

        $this->putJson("/api/rutas/{$ruta->id}", ['payment_method' => 'tarjeta'])
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('needs_review', false);
    }

    public function test_rescheduling_a_pending_trip_reprograms_its_auto_completion(): void
    {
        Sanctum::actingAs($this->owner);
        $ruta = $this->ruta(['auto_completed_at' => now()]);

        $this->putJson("/api/rutas/{$ruta->id}", ['trip_time' => '18:00'])->assertOk();
        $this->assertNull($ruta->fresh()->auto_completed_at);
    }

    public function test_owner_can_configure_auto_completion_preferences(): void
    {
        Sanctum::actingAs($this->owner);

        $this->patchJson('/api/user/preferences', [
            'auto_complete_enabled' => false,
            'auto_complete_start_delay_min' => 5,
            'auto_complete_margin_min' => 15,
            'auto_complete_margin_pct' => 20,
            'auto_complete_default_duration_min' => 45,
        ])->assertOk()
            ->assertJsonPath('auto_complete_enabled', false)
            ->assertJsonPath('auto_complete_start_delay_min', 5)
            ->assertJsonPath('auto_complete_margin_min', 15);
    }
}
