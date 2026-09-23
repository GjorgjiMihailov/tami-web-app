<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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
        Role::findOrCreate('internal_client');
        Role::findOrCreate('accountant');
        Role::findOrCreate('freelancer_client');
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

    public function test_send_when_ujp_is_unreachable_records_failure_instead_of_500(): void
    {
        Http::fake(function () {
            throw new ConnectionException(
                'cURL error 28: Connection timeout after 10001 ms for https://efakturatest.ujp.gov.mk/JSONReceiver/api/v1/sales-invoices/send'
            );
        });
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

        $sendResponse->assertStatus(503)->assertJson(['error' => 'ujp_unreachable']);
        $this->assertSame('failed', $invoice->fresh()->efaktura_status);
        $this->assertStringContainsString('cURL error 28', $invoice->fresh()->efaktura_error);
    }

    public function test_an_internal_client_with_an_own_token_can_get_a_signing_input_and_send(): void
    {
        Http::fake(['*' => Http::response(['euid' => 'euid-9'], 200)]);
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        $signing = $this->actingAs($client)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->assertOk()->json();

        $this->actingAs($client)->postJson(
            route('sales-invoices.efaktura.send', [$company, $invoice]),
            ['token' => $signing['token'], 'signature' => 'fake-signature']
        )->assertOk();

        $this->assertSame('sent', $invoice->fresh()->efaktura_status);
    }

    public function test_an_internal_client_of_a_firm_mode_company_is_forbidden_as_json(): void
    {
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $company->update(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        $response = $this->actingAs($client)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
        // bootstrap/app.php shouldRenderJsonWhen мора да ги покрива е-Фактура рутите.
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertIsArray($response->json());
    }

    public function test_an_internal_client_cannot_send_for_another_company(): void
    {
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $otherCompany->id]);
        $client->assignRole('internal_client');

        $this->actingAs($client)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->assertStatus(403);
    }

    public function test_an_internal_client_cannot_send_before_registering_a_token(): void
    {
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $company->update(['efaktura_token_serial_number' => null]);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        $this->actingAs($client)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->assertStatus(403);
    }

    public function test_a_freelancer_client_cannot_use_efaktura(): void
    {
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $freelancer = User::factory()->create(['company_id' => $company->id]);
        $freelancer->assignRole('freelancer_client');

        $this->actingAs($freelancer)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->assertStatus(403);
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
        Http::fake(['*' => Http::response(['euid' => '019b8d43-7840-7433-b358-08891b53605c', 'message' => 'Фактура успешно зачувана'], 200)]);
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
        $this->assertSame('019b8d43-7840-7433-b358-08891b53605c', $invoice->fresh()->efaktura_doc_id);
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
