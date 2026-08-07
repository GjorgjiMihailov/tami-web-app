<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaIncomingAcceptControllerTest extends TestCase
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

    private function makeUndecidedDocument(Company $company): IncomingEfakturaDocument
    {
        return IncomingEfakturaDocument::factory()->for($company)->create([
            'payload_json' => [
                'document' => [
                    'header' => ['docNumber' => 'SUP-1', 'docDate' => '2026-08-01'],
                    'seller' => ['sellerName' => 'Добавувач', 'sellerTin' => '4030009998887'],
                    'docPayment' => [],
                    'docItems' => [['docItemDesc' => 'Услуга', 'docItemQty' => 1, 'docItemUnitPriceWoVat' => 100, 'docItemTaxIndicator' => 'DDV-A']],
                ],
            ],
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_signing_input_returns_a_token(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = $this->makeUndecidedDocument($company);

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.accept.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertOk()->assertJsonStructure(['token', 'signingInput']);
    }

    public function test_store_creates_a_draft_purchase_invoice_and_marks_the_document_accepted(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        $company = $this->makeOwnModeCompany();
        $document = $this->makeUndecidedDocument($company);
        $admin = $this->admin();

        $signingResponse = $this->actingAs($admin)->postJson(
            route('incoming-efaktura.accept.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();

        $response = $this->actingAs($admin)->postJson(
            route('incoming-efaktura.accept', [$company, $document]),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $response->assertOk()->assertJsonStructure(['status', 'purchaseInvoiceId']);
        $document->refresh();
        $this->assertSame(IncomingEfakturaDocument::DECISION_ACCEPTED, $document->decision);
        $this->assertSame($admin->id, $document->decided_by);
        $this->assertNotNull($document->purchase_invoice_id);
        $this->assertSame('draft', $document->purchaseInvoice->status);
    }

    public function test_store_returns_422_when_already_decided(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        $company = $this->makeOwnModeCompany();
        $document = $this->makeUndecidedDocument($company);
        $admin = $this->admin();

        $signingResponse = $this->actingAs($admin)->postJson(
            route('incoming-efaktura.accept.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();
        $document->update(['decision' => IncomingEfakturaDocument::DECISION_ACCEPTED]);

        $response = $this->actingAs($admin)->postJson(
            route('incoming-efaktura.accept', [$company, $document]),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $response->assertStatus(422);
    }

    public function test_client_role_is_forbidden(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = $this->makeUndecidedDocument($company);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $response = $this->actingAs($client)->postJson(
            route('incoming-efaktura.accept.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
    }
}
