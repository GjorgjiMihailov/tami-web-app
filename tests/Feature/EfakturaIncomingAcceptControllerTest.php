<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
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
        Role::findOrCreate('internal_client');
        Role::findOrCreate('freelancer_client');
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

    private function clientOf(Company $company, string $role = 'internal_client'): User
    {
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole($role);

        return $client;
    }

    private function firmModeCompany(): Company
    {
        return Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM,
            'efaktura_firm_access_status' => Company::EFAKTURA_STATUS_APPROVED,
        ]);
    }

    private function noTokenCompany(): Company
    {
        return Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => null,
        ]);
    }

    private const DUPLICATE_MESSAGE = 'Влезна фактура со истиот број од овој добавувач веќе е внесена — отворете ја и споредете ја пред да ја прифатите.';

    private function existingPurchaseInvoice(Company $company, string $sellerTaxId, string $number): PurchaseInvoice
    {
        $partner = Partner::factory()->for($company)->create(['tax_id' => $sellerTaxId]);

        return PurchaseInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'supplier_invoice_number' => $number,
        ]);
    }

    public function test_signing_input_and_store_refuse_when_the_client_already_entered_that_invoice(): void
    {
        Http::fake();
        $company = $this->makeOwnModeCompany();
        $document = $this->makeUndecidedDocument($company);
        // Ист ЕДБ, но втипкан со МК-префикс — истата нормализација како кај builder-от.
        $this->existingPurchaseInvoice($company, 'MK4030009998887', 'SUP-1');
        $client = $this->clientOf($company);

        $this->actingAs($client)->postJson(
            route('incoming-efaktura.accept.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->assertStatus(422)->assertJsonFragment(['message' => self::DUPLICATE_MESSAGE]);

        $this->actingAs($client)->postJson(
            route('incoming-efaktura.accept', [$company, $document]),
            ['token' => 'whatever', 'signature' => 'ZmFrZS1zaWc']
        )->assertStatus(422)->assertJsonFragment(['message' => self::DUPLICATE_MESSAGE]);

        Http::assertNothingSent();
        $this->assertNull($document->fresh()->decision);
    }

    public function test_the_same_number_from_a_different_seller_does_not_block(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = $this->makeUndecidedDocument($company);
        $this->existingPurchaseInvoice($company, '4030001112223', 'SUP-1');

        $this->actingAs($this->clientOf($company))->postJson(
            route('incoming-efaktura.accept.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->assertOk();
    }

    public function test_the_same_seller_and_number_in_another_company_does_not_block(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = $this->makeUndecidedDocument($company);
        $this->existingPurchaseInvoice($this->makeOwnModeCompany(), '4030009998887', 'SUP-1');

        $this->actingAs($this->clientOf($company))->postJson(
            route('incoming-efaktura.accept.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->assertOk();
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

    public function test_internal_client_with_an_own_token_can_accept_and_gets_a_draft_purchase_invoice(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        $company = $this->makeOwnModeCompany();
        $document = $this->makeUndecidedDocument($company);
        $client = $this->clientOf($company);

        $signingResponse = $this->actingAs($client)->postJson(
            route('incoming-efaktura.accept.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );
        $signingResponse->assertOk()->assertJsonStructure(['token', 'signingInput']);

        $response = $this->actingAs($client)->postJson(
            route('incoming-efaktura.accept', [$company, $document]),
            ['token' => $signingResponse->json('token'), 'signature' => 'ZmFrZS1zaWc']
        );

        $response->assertOk()->assertJsonStructure(['status', 'purchaseInvoiceId']);
        $document->refresh();
        $this->assertSame(IncomingEfakturaDocument::DECISION_ACCEPTED, $document->decision);
        $this->assertSame($client->id, $document->decided_by);
        $this->assertNotNull($document->purchase_invoice_id);
        $this->assertSame('draft', $document->purchaseInvoice->status);
    }

    public function test_internal_client_of_a_firm_mode_company_is_forbidden(): void
    {
        $company = $this->firmModeCompany();
        $document = $this->makeUndecidedDocument($company);
        $user = $this->clientOf($company);

        $response = $this->actingAs($user)->postJson(
            route('incoming-efaktura.accept.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
    }

    public function test_internal_client_without_a_registered_token_is_forbidden(): void
    {
        $company = $this->noTokenCompany();
        $document = $this->makeUndecidedDocument($company);
        $user = $this->clientOf($company);

        $response = $this->actingAs($user)->postJson(
            route('incoming-efaktura.accept.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
    }

    public function test_freelancer_client_is_forbidden(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = $this->makeUndecidedDocument($company);
        $user = $this->clientOf($company, 'freelancer_client');

        $response = $this->actingAs($user)->postJson(
            route('incoming-efaktura.accept.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
    }

    public function test_internal_client_of_another_company_is_forbidden(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = $this->makeUndecidedDocument($company);
        $otherClient = $this->clientOf($this->makeOwnModeCompany());

        $response = $this->actingAs($otherClient)->postJson(
            route('incoming-efaktura.accept.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
    }
}
