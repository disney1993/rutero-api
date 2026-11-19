<?php

namespace Tests\Feature;

use App\Models\Ruta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAnalyticsReportTest extends TestCase
{
    use RefreshDatabase;

    private function seedTripsFor(User $user): void
    {
        // Anchor day 2026-03-15
        Ruta::factory()->create([
            'owner_id' => $user->id,
            'driver_id' => null,
            'status' => 'completed',
            'final_price' => 100,
            'trip_date' => '2026-03-15',
            'trip_time' => '10:00',
            'client_name' => 'Cliente Dia',
            'origin' => 'Madrid',
            'destination' => 'Toledo',
        ]);
        Ruta::factory()->create([
            'owner_id' => $user->id,
            'status' => 'pending',
            'final_price' => null,
            'trip_date' => '2026-03-15',
            'trip_time' => '11:00',
            'client_name' => 'Pendiente Dia',
        ]);
        Ruta::factory()->cancelled()->create([
            'owner_id' => $user->id,
            'final_price' => 50,
            'trip_date' => '2026-03-15',
            'trip_time' => '12:00',
            'client_name' => 'Cancelado Dia',
        ]);

        // Same month, other day
        Ruta::factory()->create([
            'owner_id' => $user->id,
            'status' => 'completed',
            'final_price' => 40,
            'trip_date' => '2026-03-01',
            'trip_time' => '09:00',
        ]);

        // Previous month (for month delta)
        Ruta::factory()->create([
            'owner_id' => $user->id,
            'status' => 'completed',
            'final_price' => 20,
            'trip_date' => '2026-02-10',
            'trip_time' => '09:00',
        ]);

        // Same year, other month
        Ruta::factory()->create([
            'owner_id' => $user->id,
            'status' => 'completed',
            'final_price' => 30,
            'trip_date' => '2026-01-20',
            'trip_time' => '09:00',
        ]);

        // Previous year
        Ruta::factory()->create([
            'owner_id' => $user->id,
            'status' => 'completed',
            'final_price' => 15,
            'trip_date' => '2025-06-01',
            'trip_time' => '09:00',
        ]);

        // Another user — must not appear
        $other = User::factory()->create();
        Ruta::factory()->create([
            'owner_id' => $other->id,
            'status' => 'completed',
            'final_price' => 999,
            'trip_date' => '2026-03-15',
        ]);
    }

    public function test_day_period_counters_exclude_cancelled_from_revenue(): void
    {
        $user = User::factory()->create();
        $this->seedTripsFor($user);

        $resp = $this->actingAs($user, 'sanctum')
            ->getJson('/api/reports/user-analytics?period=day&date=2026-03-15');

        $resp->assertOk()
            ->assertJsonPath('period', 'day')
            ->assertJsonPath('date', '2026-03-15')
            ->assertJsonPath('counters.trips_total', 3)
            ->assertJsonPath('counters.trips_completed', 1)
            ->assertJsonPath('counters.trips_cancelled', 1)
            ->assertJsonPath('counters.trips_pending', 1)
            ->assertJsonPath('counters.revenue', 100)
            ->assertJsonPath('counters.avg_ticket', 100)
            ->assertJsonPath('counters.completion_rate', 33.33);
    }

    public function test_month_and_year_period_counters(): void
    {
        $user = User::factory()->create();
        $this->seedTripsFor($user);

        $month = $this->actingAs($user, 'sanctum')
            ->getJson('/api/reports/user-analytics?period=month&date=2026-03-15');

        $month->assertOk()
            ->assertJsonPath('counters.trips_completed', 2)
            ->assertJsonPath('counters.revenue', 140)
            ->assertJsonPath('previous_counters.revenue', 20);

        $year = $this->actingAs($user, 'sanctum')
            ->getJson('/api/reports/user-analytics?period=year&date=2026-03-15');

        $year->assertOk()
            ->assertJsonPath('counters.trips_completed', 4)
            ->assertJsonPath('counters.revenue', 190)
            ->assertJsonPath('previous_counters.revenue', 15);
    }

    public function test_chart_series_shapes_and_values(): void
    {
        $user = User::factory()->create();
        $this->seedTripsFor($user);

        $resp = $this->actingAs($user, 'sanctum')
            ->getJson('/api/reports/user-analytics?period=month&date=2026-03-15');

        $resp->assertOk();

        $daily = $resp->json('charts.daily');
        $this->assertCount(31, $daily);
        $this->assertSame(100.0, (float) collect($daily)->firstWhere('date', '2026-03-15')['revenue']);
        $this->assertSame(40.0, (float) collect($daily)->firstWhere('date', '2026-03-01')['revenue']);

        $monthly = $resp->json('charts.monthly');
        $this->assertCount(12, $monthly);
        $this->assertSame(140.0, (float) collect($monthly)->firstWhere('month', 3)['revenue']);
        $this->assertSame(20.0, (float) collect($monthly)->firstWhere('month', 2)['revenue']);

        $this->assertArrayNotHasKey('yearly', $resp->json('charts'));
    }

    public function test_driver_scoped_trips_are_included(): void
    {
        $driver = User::factory()->create();
        $owner = User::factory()->create();

        Ruta::factory()->create([
            'owner_id' => $owner->id,
            'driver_id' => $driver->id,
            'status' => 'completed',
            'final_price' => 55,
            'trip_date' => '2026-03-15',
        ]);

        $resp = $this->actingAs($driver, 'sanctum')
            ->getJson('/api/reports/user-analytics?period=day&date=2026-03-15');

        $resp->assertOk()
            ->assertJsonPath('counters.trips_completed', 1)
            ->assertJsonPath('counters.revenue', 55);
    }

    public function test_export_xlsx_and_pdf_content_types(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ana',
            'last_name' => 'Garcia',
            'email' => 'ana@example.test',
        ]);
        $this->seedTripsFor($user);

        $xlsx = $this->actingAs($user, 'sanctum')
            ->get('/api/reports/user-export?period=day&date=2026-03-15&format=xlsx');

        $xlsx->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            $xlsx->headers->get('content-type')
        );
        $this->assertNotEmpty($xlsx->streamedContent());

        $pdf = $this->actingAs($user, 'sanctum')
            ->get('/api/reports/user-export?period=day&date=2026-03-15&format=pdf');

        $pdf->assertOk();
        $this->assertStringContainsString('pdf', strtolower((string) $pdf->headers->get('content-type')));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_admin_can_request_another_user_analytics_and_non_admin_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $intruder = User::factory()->create();
        $this->seedTripsFor($owner);

        $adminResp = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/reports/user-analytics?period=day&date=2026-03-15&user_id={$owner->id}");

        $adminResp->assertOk()
            ->assertJsonPath('user.id', $owner->id)
            ->assertJsonPath('counters.revenue', 100);

        $forbidden = $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/reports/user-analytics?period=day&date=2026-03-15&user_id={$owner->id}");

        $forbidden->assertForbidden();

        $exportForbidden = $this->actingAs($intruder, 'sanctum')
            ->get("/api/reports/user-export?period=day&date=2026-03-15&format=pdf&user_id={$owner->id}");

        $exportForbidden->assertForbidden();
    }

    public function test_user_summary_still_works_via_service(): void
    {
        $user = User::factory()->create();
        $this->seedTripsFor($user);

        $resp = $this->actingAs($user, 'sanctum')
            ->getJson('/api/reports/user-summary');

        $resp->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('revenue_completed', 205);
    }
}
