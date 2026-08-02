<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaSendControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    private function makeConfirmedOwnModeInvoice(): array
    {
        $company = Company::factory()->create([
            'tax_id' => '4030001234567',
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
            'street_address' => 'Мајка Тереза', 'street_number' => '12',
            'postal_code' => '1000', 'city' => 'Скопје',
        ]);
        $partner = Partner::factory()->for($company)->create([
            'street_address' => 'Партизанска', 'street_number' => '5',
            'postal_code' => '1000', 'city' => 'Скопје',
        ]);
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'fiscal_year' => 2026, 'invoice_number' => 1,
            'invoice_date' => '2026-03-01', 'status' => 'confirmed',
        ]);
        $invoice->lines()->create(['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);

        return [$company, $invoice->fresh(['lines'])];
    }

    public function test_signing_input_returns_token_and_signing_input(): void
    {
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertOk()->assertJsonStructure(['token', 'signingInput']);
    }

    public function test_send_completes_and_marks_invoice_sent(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $signingResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();

        $sendResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.send', [$company, $invoice]),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $sendResponse->assertOk()->assertJson(['status' => 'sent']);
        $this->assertSame('sent', $invoice->fresh()->efaktura_status);
        $this->assertNotNull($invoice->fresh()->efaktura_sent_at);
    }

    public function test_send_with_expired_token_returns_410(): void
    {
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.send', [$company, $invoice]),
            ['token' => 'nonexistent-token', 'signature' => 'ZmFrZS1zaWc']
        );

        $response->assertStatus(410);
    }

    public function test_send_when_ujp_rejects_marks_invoice_failed(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid signature'], 400)]);
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $signingResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();

        $sendResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.send', [$company, $invoice]),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $sendResponse->assertStatus(422);
        $this->assertSame('failed', $invoice->fresh()->efaktura_status);
        $this->assertNotNull($invoice->fresh()->efaktura_error);
    }

    public function test_firm_mode_company_is_rejected_with_clear_message(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01',
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422);
    }

    public function test_draft_invoice_is_rejected(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1', 'efaktura_token_serial_number' => '1A2B3C',
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'status' => 'draft', 'invoice_date' => '2026-03-01',
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422);
    }

    public function test_client_role_is_forbidden(): void
    {
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $response = $this->actingAs($client)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
        // Fix 2 coverage: bootstrap/app.php's shouldRenderJsonWhen must cover efaktura routes,
        // otherwise this 403 would render as HTML and $response->json() would throw.
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertIsArray($response->json());
    }

    public function test_second_send_attempt_on_already_sent_invoice_is_rejected(): void
    {
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $invoice->update(['efaktura_status' => 'sent', 'efaktura_sent_at' => now()]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422);
    }

    public function test_efaktura_doc_id_is_populated_from_ujp_response(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok', 'docId' => 'UJP-DOC-12345'], 200)]);
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $signingResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();

        $sendResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.send', [$company, $invoice]),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $sendResponse->assertOk();
        $this->assertSame('UJP-DOC-12345', $invoice->fresh()->efaktura_doc_id);
    }

    public function test_incomplete_company_address_is_rejected_before_signing_input_generated(): void
    {
        $company = Company::factory()->create([
            'tax_id' => '4030001234567',
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
            'street_address' => null, 'street_number' => null,
            'postal_code' => null, 'city' => null,
        ]);
        $partner = Partner::factory()->for($company)->create([
            'street_address' => 'Партизанска', 'street_number' => '5',
            'postal_code' => '1000', 'city' => 'Скопје',
        ]);
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'fiscal_year' => 2026, 'invoice_number' => 1,
            'invoice_date' => '2026-03-01', 'status' => 'confirmed',
        ]);
        $invoice->lines()->create(['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice->fresh(['lines'])]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422)->assertJson(['error' => 'incomplete_address']);
    }

    public function test_incomplete_partner_address_is_rejected_before_signing_input_generated(): void
    {
        $company = Company::factory()->create([
            'tax_id' => '4030001234567',
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
            'street_address' => 'Мајка Тереза', 'street_number' => '12',
            'postal_code' => '1000', 'city' => 'Скопје',
        ]);
        $partner = Partner::factory()->for($company)->create([
            'street_address' => null, 'street_number' => null,
            'postal_code' => null, 'city' => null,
        ]);
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'fiscal_year' => 2026, 'invoice_number' => 1,
            'invoice_date' => '2026-03-01', 'status' => 'confirmed',
        ]);
        $invoice->lines()->create(['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice->fresh(['lines'])]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422)->assertJson(['error' => 'incomplete_address']);
    }
}
