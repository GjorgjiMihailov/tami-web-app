<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaIncomingDiscoveryControllerTest extends TestCase
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

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_ids_signing_input_returns_a_token(): void
    {
        $company = $this->makeOwnModeCompany();

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.discover.ids.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertOk()->assertJsonStructure(['token', 'signingInput']);
    }

    public function test_ids_returns_only_new_euids_and_updates_last_checked_at(): void
    {
        Http::fake(['*' => Http::response(['euids' => ['euid-1', 'euid-2']], 200)]);
        $company = $this->makeOwnModeCompany();
        IncomingEfakturaDocument::factory()->for($company)->create(['euid' => 'euid-1']);

        $signingResponse = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.discover.ids.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.discover.ids', $company),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $response->assertOk()->assertJson(['newEuids' => ['euid-2']]);
        $this->assertNotNull($company->fresh()->efaktura_purchase_last_checked_at);
    }

    public function test_payload_creates_new_incoming_documents_from_returned_documents(): void
    {
        Http::fake(['*' => Http::response(['documents' => [
            ['euid' => 'euid-2', 'document' => [
                'header' => ['docNumber' => 'SUP-1', 'docDate' => '2026-08-01'],
                'seller' => ['sellerName' => 'Добавувач', 'sellerTin' => '4030009998887'],
                'docTotals' => ['docGrossAmount' => 590],
                'docItems' => [],
            ]],
        ]], 200)]);
        $company = $this->makeOwnModeCompany();

        $signingResponse = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.discover.payload.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert'), 'euids' => ['euid-2']]
        )->json();

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.discover.payload', $company),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $response->assertOk()->assertJson(['status' => 'discovered', 'created' => 1]);
        $document = IncomingEfakturaDocument::where('company_id', $company->id)->where('euid', 'euid-2')->first();
        $this->assertNotNull($document);
        $this->assertSame('SUP-1', $document->doc_number);
        $this->assertSame('Добавувач', $document->seller_name);
        $this->assertSame('590.00', $document->total_amount);
    }

    public function test_status_updates_matching_documents_by_euid(): void
    {
        Http::fake(['*' => Http::response(['invoices' => [
            ['euid' => 'euid-1', 'statusCode' => '01', 'statusName' => 'Испратена (Нова)'],
        ]], 200)]);
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create(['euid' => 'euid-1', 'status_code' => null]);

        $signingResponse = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.discover.status.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert'), 'dateFrom' => '2026-01-01', 'dateTo' => '2026-08-06']
        )->json();

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.discover.status', $company),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $response->assertOk()->assertJson(['status' => 'refreshed', 'updated' => 1]);
        $this->assertSame('01', $document->fresh()->status_code);
    }

    public function test_firm_mode_company_is_rejected(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.discover.ids.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422);
    }

    public function test_client_role_is_forbidden(): void
    {
        $company = $this->makeOwnModeCompany();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $response = $this->actingAs($client)->postJson(
            route('incoming-efaktura.discover.ids.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
    }
}
