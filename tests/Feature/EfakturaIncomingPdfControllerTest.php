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
        Role::findOrCreate('client');
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
    }

    public function test_client_role_is_forbidden(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create(['decision' => IncomingEfakturaDocument::DECISION_ACCEPTED]);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $response = $this->actingAs($client)->postJson(
            route('incoming-efaktura.pdf.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
    }
}
