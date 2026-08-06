<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaStatusControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    private function makeOwnModeCompany(): Company
    {
        return Company::factory()->create([
            'tax_id' => '4030001234567',
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
    }

    private function makeSentInvoice(Company $company, ?string $statusCode = null, ?string $euid = 'euid-1'): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();

        return SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'confirmed',
            'invoice_date' => '2026-08-01',
            'efaktura_status' => 'sent',
            'efaktura_sent_at' => now()->subDays(3),
            'efaktura_doc_id' => $euid,
            'efaktura_ujp_status_code' => $statusCode,
        ]);
    }

    public function test_signing_input_returns_token_when_a_pending_invoice_exists(): void
    {
        $company = $this->makeOwnModeCompany();
        $this->makeSentInvoice($company);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertOk()->assertJsonStructure(['token', 'signingInput']);
    }

    public function test_signing_input_ignores_pending_invoice_with_null_sent_at(): void
    {
        $company = $this->makeOwnModeCompany();
        $partner = Partner::factory()->for($company)->create();
        SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'confirmed',
            'invoice_date' => '2026-08-01',
            'efaktura_status' => 'sent',
            'efaktura_sent_at' => null,
            'efaktura_doc_id' => 'euid-null-sent-at',
            'efaktura_ujp_status_code' => null,
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422)->assertJson(['error' => 'nothing_pending']);
    }

    public function test_signing_input_returns_422_when_nothing_pending(): void
    {
        $company = $this->makeOwnModeCompany();
        $this->makeSentInvoice($company, statusCode: '03');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422)->assertJson(['error' => 'nothing_pending']);
    }

    public function test_refresh_updates_matching_invoice_by_euid(): void
    {
        Http::fake(['*' => Http::response([
            'invoices' => [
                ['euid' => 'euid-1', 'statusCode' => '03', 'statusName' => 'Прифатена'],
            ],
        ], 200)]);
        $company = $this->makeOwnModeCompany();
        $invoice = $this->makeSentInvoice($company);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $signingResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();

        $refreshResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.refresh-statuses', $company),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $refreshResponse->assertOk()->assertJson(['status' => 'refreshed', 'updated' => 1]);
        $this->assertSame('03', $invoice->fresh()->efaktura_ujp_status_code);
        $this->assertSame('Прифатена', $invoice->fresh()->efaktura_ujp_status_name);
    }

    public function test_refresh_leaves_non_matching_invoice_untouched(): void
    {
        Http::fake(['*' => Http::response(['invoices' => [
            ['euid' => 'some-other-euid', 'statusCode' => '03', 'statusName' => 'Прифатена'],
        ]], 200)]);
        $company = $this->makeOwnModeCompany();
        $invoice = $this->makeSentInvoice($company, euid: 'euid-1');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $signingResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();

        $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.refresh-statuses', $company),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        )->assertOk();

        $this->assertNull($invoice->fresh()->efaktura_ujp_status_code);
    }

    public function test_refresh_when_ujp_is_unreachable_returns_503(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Connection timeout');
        });
        $company = $this->makeOwnModeCompany();
        $this->makeSentInvoice($company);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $signingResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.refresh-statuses', $company),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $response->assertStatus(503)->assertJson(['error' => 'ujp_unreachable']);
    }

    public function test_client_role_is_forbidden(): void
    {
        $company = $this->makeOwnModeCompany();
        $this->makeSentInvoice($company);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $response = $this->actingAs($client)->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
    }

    public function test_firm_mode_company_is_rejected(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422);
    }
}
