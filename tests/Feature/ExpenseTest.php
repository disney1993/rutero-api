<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\OwnerDriver;
use App\Models\Ruta;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Vehicle $vehicle;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->vehicle = Vehicle::factory()->create(['owner_id' => $this->owner->id]);
        $this->driver = User::factory()->create();
        OwnerDriver::create([
            'owner_id' => $this->owner->id, 'driver_id' => $this->driver->id,
            'status' => OwnerDriver::STATUS_ACTIVE, 'invited_via' => OwnerDriver::VIA_EMAIL, 'accepted_at' => now(),
        ]);
    }

    private function as(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $this;
    }

    private function payload(array $attrs = []): array
    {
        return array_merge(['date' => '2026-03-15', 'category' => 'fuel', 'amount' => 60.5, 'vehicle_id' => $this->vehicle->id], $attrs);
    }

    public function test_categories_are_served_with_their_rules(): void
    {
        $this->as($this->owner)->getJson('/api/reference/expense-categories?lang=es')->assertOk()
            ->assertJsonPath('categories.0.key', 'payroll')
            ->assertJsonPath('categories.0.driver_required', true)
            ->assertJsonFragment(['key' => 'other', 'description_required' => true]);
    }

    public function test_owner_records_lists_updates_deletes_and_restores_expenses(): void
    {
        $id = $this->as($this->owner)->postJson('/api/expenses', $this->payload())->assertCreated()
            ->assertJsonPath('vehicle.id', $this->vehicle->id)->json('id');
        $this->as($this->owner)->postJson('/api/expenses', $this->payload(['category' => 'workshop', 'amount' => 200]))->assertCreated();
        $this->as($this->owner)->postJson('/api/expenses', $this->payload(['date' => '2026-04-01', 'amount' => 999]))->assertCreated();

        $this->as($this->owner)->getJson('/api/expenses?from=2026-03-01&to=2026-03-31')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 260.5)
            ->assertJsonPath('by_category.0.category', 'workshop');

        $this->as($this->owner)->patchJson("/api/expenses/{$id}", ['amount' => 70])->assertOk()->assertJsonPath('amount', '70.00');
        $this->as($this->owner)->deleteJson("/api/expenses/{$id}")->assertOk();
        $this->assertSoftDeleted('expenses', ['id' => $id]);
        $this->as($this->owner)->postJson("/api/expenses/{$id}/restore")->assertOk();
        $this->assertNotSoftDeleted('expenses', ['id' => $id]);
    }

    public function test_expenses_are_validated(): void
    {
        $post = fn (array $attrs) => $this->as($this->owner)->postJson('/api/expenses', $this->payload($attrs));

        $post(['amount' => 0])->assertJsonValidationErrors('amount');
        $post(['category' => 'caviar'])->assertJsonValidationErrors('category');
        $post(['date' => '2099-01-01'])->assertJsonValidationErrors('date');
        $post(['category' => 'other', 'description' => '  '])->assertJsonValidationErrors('description');
        $post(['category' => 'payroll', 'vehicle_id' => null])->assertJsonValidationErrors('driver_id');
        $post(['vat_amount' => 100])->assertJsonValidationErrors('vat_amount');
        $post(['vehicle_id' => Vehicle::factory()->create()->id])->assertJsonValidationErrors('vehicle_id');
        $post(['category' => 'payroll', 'driver_id' => User::factory()->create()->id])->assertJsonValidationErrors('driver_id');
        $post(['payment_method' => 'oro'])->assertJsonValidationErrors('payment_method');

        // Gasto genérico con descripción propia y nómina de un conductor del equipo.
        $post(['category' => 'other', 'description' => 'Cambio de alfombrillas'])->assertCreated()->assertJsonPath('description', 'Cambio de alfombrillas');
        $post(['category' => 'payroll', 'driver_id' => $this->driver->id, 'amount' => 1200])->assertCreated()
            ->assertJsonPath('driver.id', $this->driver->id)->assertJsonPath('vehicle_id', null);
    }

    public function test_only_the_owner_can_see_and_manage_their_expenses(): void
    {
        $expense = Expense::create($this->payload(['owner_id' => $this->owner->id]));

        $this->as($this->driver)->getJson('/api/expenses?from=2026-03-01&to=2026-03-31')->assertForbidden();
        $this->as($this->driver)->postJson('/api/expenses', $this->payload())->assertForbidden();

        $otherOwner = User::factory()->create();
        Vehicle::factory()->create(['owner_id' => $otherOwner->id]);
        $this->as($otherOwner)->patchJson("/api/expenses/{$expense->id}", ['amount' => 1])->assertForbidden();
        $this->as($otherOwner)->deleteJson("/api/expenses/{$expense->id}")->assertForbidden();
        $this->as($otherOwner)->getJson('/api/expenses?from=2026-03-01&to=2026-03-31')->assertJsonCount(0, 'data');
    }

    public function test_reports_include_expenses_and_net_profit_for_the_owner(): void
    {
        Ruta::factory()->create([
            'owner_id' => $this->owner->id, 'vehicle_id' => $this->vehicle->id, 'status' => 'completed',
            'final_price' => 300, 'trip_date' => '2026-03-15', 'trip_time' => '10:00',
        ]);
        Expense::create($this->payload(['owner_id' => $this->owner->id, 'amount' => 80]));
        Expense::create($this->payload(['owner_id' => $this->owner->id, 'amount' => 20, 'category' => 'tolls_parking', 'date' => '2026-03-20']));

        $resp = $this->as($this->owner)->getJson('/api/reports/user-analytics?period=month&date=2026-03-15&role=owner')->assertOk();
        $resp->assertJsonPath('counters.revenue', 300)
            ->assertJsonPath('counters.expenses', 100)
            ->assertJsonPath('counters.net', 200)
            ->assertJsonPath('expenses_by_category.0.category', 'fuel');
        $day15 = collect($resp->json('charts.daily'))->firstWhere('date', '2026-03-15');
        $this->assertEquals(80, $day15['expenses']);
        $this->assertEquals(220, $day15['net']);
        $march = collect($resp->json('charts.monthly'))->firstWhere('month', 3);
        $this->assertEquals(100, $march['expenses']);

        $xlsx = $this->as($this->owner)->get('/api/reports/user-export?period=month&date=2026-03-15&format=xlsx&role=owner');
        $xlsx->assertOk();
        $pdf = $this->as($this->owner)->get('/api/reports/user-export?period=month&date=2026-03-15&format=pdf&role=owner');
        $pdf->assertOk();
    }

    public function test_deleted_expenses_do_not_count_in_reports(): void
    {
        $expense = Expense::create($this->payload(['owner_id' => $this->owner->id, 'amount' => 50]));
        $expense->delete();

        $this->as($this->owner)->getJson('/api/reports/user-analytics?period=month&date=2026-03-15&role=owner')
            ->assertJsonPath('counters.expenses', 0);
    }
}
