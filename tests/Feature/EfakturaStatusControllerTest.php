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

class EfakturaStatusControllerTest extends TestCase
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

    private function company(): Company
    {
        return Company::factory()->create(['tax_id' => '4030001234567']);
    }

    /** Токенот е личен — на човекот што потпишува, не на фирмата. */
    private function giveToken(User $user): User
    {
        $user->forceFill(['efaktura_eujp_id' => 'EUJP-1', 'efaktura_token_serial_number' => '1A2B3C'])->save();

        return $user->fresh();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $this->giveToken($admin);
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
        $company = $this->company();
        $this->makeSentInvoice($company);

        $response = $this->actingAs($this->admin())->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertOk()->assertJsonStructure(['token', 'signingInput']);
    }

    public function test_signing_input_ignores_pending_invoice_with_null_sent_at(): void
    {
        $company = $this->company();
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

        $response = $this->actingAs($this->admin())->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422)->assertJson(['error' => 'nothing_pending']);
    }

    public function test_signing_input_returns_422_when_nothing_pending(): void
    {
        $company = $this->company();
        $this->makeSentInvoice($company, statusCode: '03');

        $response = $this->actingAs($this->admin())->postJson(
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
        $company = $this->company();
        $invoice = $this->makeSentInvoice($company);
        $admin = $this->admin();

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
        $company = $this->company();
        $invoice = $this->makeSentInvoice($company, euid: 'euid-1');
        $admin = $this->admin();

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
            throw new ConnectionException('cURL error 28: Connection timeout');
        });
        $company = $this->company();
        $this->makeSentInvoice($company);
        $admin = $this->admin();

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

    public function test_an_internal_client_with_an_own_token_can_refresh_statuses(): void
    {
        $company = $this->company();
        $this->makeSentInvoice($company);
        $client = $this->giveToken(User::factory()->create(['company_id' => $company->id]));
        $client->assignRole('internal_client');

        $this->actingAs($client)->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->assertOk()->assertJsonStructure(['token', 'signingInput']);
    }

    public function test_an_internal_client_cannot_refresh_statuses_of_another_company(): void
    {
        $company = $this->company();
        $this->makeSentInvoice($company);
        $otherCompany = Company::factory()->create();
        $client = $this->giveToken(User::factory()->create(['company_id' => $otherCompany->id]));
        $client->assignRole('internal_client');

        $this->actingAs($client)->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->assertStatus(403);
    }

    public function test_an_internal_client_without_a_registered_token_cannot_refresh_statuses(): void
    {
        $company = $this->company();
        $this->makeSentInvoice($company);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        $this->actingAs($client)->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->assertStatus(403);
    }

    public function test_a_freelancer_client_cannot_refresh_statuses(): void
    {
        $company = $this->company();
        $this->makeSentInvoice($company);
        $freelancer = $this->giveToken(User::factory()->create(['company_id' => $company->id]));
        $freelancer->assignRole('freelancer_client');

        $this->actingAs($freelancer)->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->assertStatus(403);
    }

    public function test_an_admin_without_a_personal_token_is_rejected(): void
    {
        $company = $this->company();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
    }
}
