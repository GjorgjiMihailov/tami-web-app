<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaIncomingPdfControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('internal_client');
        Role::findOrCreate('freelancer_client');
        Storage::fake('local');
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

    public function test_signing_input_returns_a_token_for_an_accepted_document(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create(['decision' => IncomingEfakturaDocument::DECISION_ACCEPTED]);

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.pdf.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertOk()->assertJsonStructure(['token', 'signingInput']);
    }

    public function test_signing_input_rejects_a_document_not_yet_accepted(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create(['decision' => null]);

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.pdf.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422);
    }

    public function test_store_saves_the_pdf_and_download_serves_it(): void
    {
        Http::fake(['*' => Http::response(['pdfBase64' => base64_encode('fake-pdf-bytes')], 200)]);
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create([
            'decision' => IncomingEfakturaDocument::DECISION_ACCEPTED,
            'doc_number' => 'SUP-1',
        ]);
        $admin = $this->admin();

        $signingResponse = $this->actingAs($admin)->postJson(
            route('incoming-efaktura.pdf.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();

        $storeResponse = $this->actingAs($admin)->postJson(
            route('incoming-efaktura.pdf.store', [$company, $document]),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $storeResponse->assertOk()->assertJson(['status' => 'saved']);
        $this->assertNotNull($document->fresh()->efaktura_pdf_path);

        $downloadResponse = $this->actingAs($admin)->get(route('incoming-efaktura.pdf.download', [$company, $document]));
        $downloadResponse->assertOk();

        // Verify the actual PDF content matches the fake bytes
        $storedContent = Storage::disk('local')->get($document->fresh()->efaktura_pdf_path);
        $this->assertEquals('fake-pdf-bytes', $storedContent);

        // Verify the filename in Content-Disposition header
        $this->assertStringContainsString('vlezna-faktura-SUP-1.pdf', $downloadResponse->headers->get('Content-Disposition'));
    }

    public function test_internal_client_with_an_own_token_can_fetch_and_store_the_official_pdf(): void
    {
        Http::fake(['*' => Http::response(['pdfBase64' => base64_encode('fake-pdf-bytes')], 200)]);
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create(['decision' => IncomingEfakturaDocument::DECISION_ACCEPTED]);
        $client = $this->clientOf($company);

        $signingResponse = $this->actingAs($client)->postJson(
            route('incoming-efaktura.pdf.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );
        $signingResponse->assertOk()->assertJsonStructure(['token', 'signingInput']);

        $storeResponse = $this->actingAs($client)->postJson(
            route('incoming-efaktura.pdf.store', [$company, $document]),
            ['token' => $signingResponse->json('token'), 'signature' => 'ZmFrZS1zaWc']
        );

        $storeResponse->assertOk()->assertJson(['status' => 'saved']);
        $this->assertNotNull($document->fresh()->efaktura_pdf_path);
        $this->assertSame('fake-pdf-bytes', Storage::disk('local')->get($document->fresh()->efaktura_pdf_path));
    }

    public function test_internal_client_of_a_firm_mode_company_is_forbidden(): void
    {
        $company = $this->firmModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create(['decision' => IncomingEfakturaDocument::DECISION_ACCEPTED]);
        $user = $this->clientOf($company);

        $response = $this->actingAs($user)->postJson(
            route('incoming-efaktura.pdf.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
    }

    public function test_internal_client_without_a_registered_token_is_forbidden(): void
    {
        $company = $this->noTokenCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create(['decision' => IncomingEfakturaDocument::DECISION_ACCEPTED]);
        $user = $this->clientOf($company);

        $response = $this->actingAs($user)->postJson(
            route('incoming-efaktura.pdf.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
    }

    public function test_freelancer_client_is_forbidden(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create(['decision' => IncomingEfakturaDocument::DECISION_ACCEPTED]);
        $user = $this->clientOf($company, 'freelancer_client');

        $response = $this->actingAs($user)->postJson(
            route('incoming-efaktura.pdf.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
    }

    public function test_internal_client_of_another_company_is_forbidden(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create(['decision' => IncomingEfakturaDocument::DECISION_ACCEPTED]);
        $otherClient = $this->clientOf($this->makeOwnModeCompany());

        $response = $this->actingAs($otherClient)->postJson(
            route('incoming-efaktura.pdf.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
    }
}
