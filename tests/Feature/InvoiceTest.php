<?php

namespace Tests\Feature;

use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\Ruta;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\SpanishTaxId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceTest extends TestCase
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

    private function as(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $this;
    }

    private function profilePayload(array $attrs = []): array
    {
        return array_merge([
            'issuer_type' => 'autonomo', 'legal_name' => 'Pedro Ruiz Gómez', 'tax_id' => '12345678-z',
            'address_line' => 'Calle Mayor 1', 'postal_code' => '26500', 'city' => 'Calahorra', 'province' => 'La Rioja',
            'activity' => 'passengers', 'vat_rate' => 10, 'series' => '2026-', 'licence' => 'Taxi 12',
            'iban' => 'ES91 2100 0418 4502 0005 1332',
        ], $attrs);
    }

    private function readyProfile(): void
    {
        $this->as($this->owner)->putJson('/api/billing-profile', $this->profilePayload())->assertOk();
    }

    private function completedRuta(array $attrs = []): Ruta
    {
        return Ruta::factory()->create(array_merge([
            'owner_id' => $this->owner->id, 'vehicle_id' => $this->vehicle->id, 'status' => 'completed',
            'final_price' => 55, 'trip_date' => '2026-11-10', 'trip_time' => '10:00',
            'origin' => 'Calahorra', 'destination' => 'Logroño', 'payment_method' => 'efectivo',
        ], $attrs));
    }

    public function test_spanish_tax_ids_iban_and_postal_codes_are_validated_with_their_check_digits(): void
    {
        foreach (['12345678Z', '00000000T', 'X1234567L', 'Y1234567X', 'B12345674', 'Q2826000H', '12.345.678-z'] as $ok) {
            $this->assertTrue(SpanishTaxId::isValid($ok), $ok);
        }
        foreach (['12345678A', 'X1234567A', 'B12345675', 'Q2826000A', '1234567Z', ''] as $bad) {
            $this->assertFalse(SpanishTaxId::isValid($bad), $bad);
        }
        $this->assertTrue(SpanishTaxId::isValidIban('ES91 2100 0418 4502 0005 1332'));
        $this->assertFalse(SpanishTaxId::isValidIban('ES92 2100 0418 4502 0005 1332'));
        $this->assertTrue(SpanishTaxId::isValidPostalCode('26500'));
        $this->assertFalse(SpanishTaxId::isValidPostalCode('60000'));
        $this->assertFalse(SpanishTaxId::isValidPostalCode('2650'));
    }

    public function test_billing_profile_is_validated_and_reports_what_is_missing(): void
    {
        $this->as($this->owner)->getJson('/api/billing-profile')->assertOk()->assertJsonPath('ready', false)
            ->assertJsonPath('profile.series', now()->format('Y').'-');

        $put = fn (array $attrs) => $this->as($this->owner)->putJson('/api/billing-profile', $this->profilePayload($attrs));
        $put(['tax_id' => '12345678A'])->assertJsonValidationErrors('tax_id');
        $put(['tax_id' => 'B12345674'])->assertJsonValidationErrors('tax_id'); // autónomo con CIF
        $put(['postal_code' => '99000'])->assertJsonValidationErrors('postal_code');
        $put(['province' => 'Narnia'])->assertJsonValidationErrors('province');
        $put(['iban' => 'ES00 1234'])->assertJsonValidationErrors('iban');
        $put(['vat_rate' => 0])->assertJsonValidationErrors('exemption_reason');
        $put(['series' => 'F 2026'])->assertJsonValidationErrors('series');

        $put([])->assertOk()->assertJsonPath('ready', true)
            ->assertJsonPath('profile.tax_id', '12345678Z')
            ->assertJsonPath('profile.iban', 'ES9121000418450200051332')
            ->assertJsonPath('simplified_limit', 3000);
    }

    public function test_cannot_invoice_without_a_complete_billing_profile(): void
    {
        $ruta = $this->completedRuta();
        $this->as($this->owner)->postJson("/api/rutas/{$ruta->id}/invoice", ['concept' => 'Viaje', 'total' => 55])
            ->assertStatus(422)->assertJsonFragment(['missing' => BillingProfile::REQUIRED]);
    }

    public function test_owner_issues_a_simplified_invoice_with_vat_breakdown_and_correlative_numbers(): void
    {
        $this->readyProfile();
        $first = $this->completedRuta();
        $second = $this->completedRuta(['final_price' => 33.33]);

        $a = $this->as($this->owner)->postJson("/api/rutas/{$first->id}/invoice", ['concept' => 'Viaje Calahorra – Logroño', 'total' => 55])
            ->assertCreated()
            ->assertJsonPath('type', 'simplified')
            ->assertJsonPath('full_number', '2026-0001')
            ->assertJsonPath('base', 50)
            ->assertJsonPath('vat_amount', 5)
            ->assertJsonPath('total', 55)
            ->assertJsonPath('issuer.tax_id', '12345678Z')
            ->json();
        $b = $this->as($this->owner)->postJson("/api/rutas/{$second->id}/invoice", ['concept' => 'Viaje urbano', 'total' => 33.33])
            ->assertCreated()->assertJsonPath('full_number', '2026-0002')->json();

        // La base y la cuota siempre suman el total cobrado.
        $this->assertEqualsWithDelta(33.33, $b['base'] + $b['vat_amount'], 0.001);
        // Cada factura encadena la huella de la anterior.
        $this->assertSame($a['hash'], Invoice::find($b['id'])->previous_hash);

        // El viaje ya facturado no se vuelve a facturar.
        $this->as($this->owner)->postJson("/api/rutas/{$first->id}/invoice", ['concept' => 'x', 'total' => 55])->assertStatus(409);
        $this->as($this->owner)->getJson("/api/rutas/{$first->id}/invoice")->assertJsonPath('invoice.id', $a['id']);
    }

    public function test_above_the_limit_or_with_tax_id_the_recipient_data_is_required(): void
    {
        $this->readyProfile();
        $ruta = $this->completedRuta(['final_price' => 3500]);
        $post = fn (array $body) => $this->as($this->owner)->postJson("/api/rutas/{$ruta->id}/invoice", $body + ['concept' => 'Traslado', 'total' => 3500]);

        $post(['type' => 'simplified'])->assertJsonValidationErrors('type');
        $post([])->assertJsonValidationErrors(['recipient.name', 'recipient.tax_id', 'recipient.address_line']);
        $post(['recipient' => ['name' => 'Hotel SL', 'tax_id' => 'B12345675', 'address_line' => 'Av. 1', 'postal_code' => '26001', 'city' => 'Logroño']])
            ->assertJsonValidationErrors('recipient.tax_id');

        $post(['save_client' => true, 'recipient' => ['name' => 'Hotel SL', 'tax_id' => 'b12345674', 'address_line' => 'Av. 1', 'postal_code' => '26001', 'city' => 'Logroño']])
            ->assertCreated()->assertJsonPath('type', 'full')->assertJsonPath('recipient.tax_id', 'B12345674');

        // Destinatario guardado para la próxima vez.
        $this->as($this->owner)->getJson('/api/invoice-clients?search=hotel')->assertJsonPath('0.tax_id', 'B12345674');
    }

    public function test_only_completed_trips_of_the_owner_can_be_invoiced(): void
    {
        $this->readyProfile();
        $pending = $this->completedRuta(['status' => 'pending']);
        $this->as($this->owner)->postJson("/api/rutas/{$pending->id}/invoice", ['concept' => 'Viaje', 'total' => 10])->assertStatus(422);

        $other = User::factory()->create();
        $ruta = $this->completedRuta();
        $this->as($other)->postJson("/api/rutas/{$ruta->id}/invoice", ['concept' => 'Viaje', 'total' => 10])->assertForbidden();
    }

    public function test_preview_returns_a_pdf_without_issuing_and_issued_invoices_download_as_pdf(): void
    {
        $this->readyProfile();
        $ruta = $this->completedRuta();

        $preview = $this->as($this->owner)->post("/api/rutas/{$ruta->id}/invoice", ['concept' => 'Viaje', 'total' => 55, 'preview' => true]);
        $preview->assertOk();
        $this->assertStringContainsString('pdf', strtolower((string) $preview->headers->get('content-type')));
        $this->assertSame(0, Invoice::count());

        $id = $this->as($this->owner)->postJson("/api/rutas/{$ruta->id}/invoice", ['concept' => 'Viaje', 'total' => 55])->json('id');
        $this->as($this->owner)->get("/api/invoices/{$id}/pdf")->assertOk();
        $this->as(User::factory()->create())->get("/api/invoices/{$id}/pdf")->assertForbidden();
    }

    public function test_a_corrective_invoice_cancels_the_original_and_allows_reinvoicing(): void
    {
        $this->readyProfile();
        $ruta = $this->completedRuta();
        $id = $this->as($this->owner)->postJson("/api/rutas/{$ruta->id}/invoice", ['concept' => 'Viaje', 'total' => 55])->json('id');

        $this->as($this->owner)->postJson("/api/invoices/{$id}/corrective", ['reason' => 'Importe erróneo'])
            ->assertCreated()
            ->assertJsonPath('type', 'corrective')
            ->assertJsonPath('full_number', 'R2026-0001')
            ->assertJsonPath('total', -55)
            ->assertJsonPath('corrects_invoice_id', $id);
        $this->as($this->owner)->postJson("/api/invoices/{$id}/corrective", ['reason' => 'Otra vez'])->assertStatus(422);

        $this->as($this->owner)->postJson("/api/rutas/{$ruta->id}/invoice", ['concept' => 'Viaje', 'total' => 50])
            ->assertCreated()->assertJsonPath('full_number', '2026-0002');
    }

    public function test_numbering_cannot_be_changed_once_invoices_exist_in_the_series(): void
    {
        $this->readyProfile();
        $ruta = $this->completedRuta();
        $this->as($this->owner)->postJson("/api/rutas/{$ruta->id}/invoice", ['concept' => 'Viaje', 'total' => 55])->assertCreated();

        $this->as($this->owner)->putJson('/api/billing-profile', $this->profilePayload(['next_number' => 1]))->assertStatus(422);
        // Serie nueva (p. ej. al cambiar de año): empieza en 1.
        $this->as($this->owner)->putJson('/api/billing-profile', $this->profilePayload(['series' => '2027-']))
            ->assertOk()->assertJsonPath('profile.next_number', 1);
    }
}
