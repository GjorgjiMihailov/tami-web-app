<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaIncomingRejectControllerTest extends TestCase
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

    public function test_signing_input_requires_a_known_reason_code(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create();

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.reject.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert'), 'reasonCode' => 'NOT-A-CODE']
        );

        $response->assertStatus(422);
    }

    public function test_signing_input_requires_a_comment_when_reason_is_other(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create();

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.reject.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert'), 'reasonCode' => IncomingEfakturaDocument::REJECT_REASON_OTHER]
        );

        $response->assertStatus(422);
    }

    public function test_signing_input_returns_a_token_for_a_valid_reason(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create();

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.reject.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert'), 'reasonCode' => 'O-4']
        );

        $response->assertOk()->assertJsonStructure(['token', 'signingInput']);
    }

    public function test_store_marks_the_document_rejected_with_reason(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create();
        $admin = $this->admin();

        $signingResponse = $this->actingAs($admin)->postJson(
            route('incoming-efaktura.reject.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert'), 'reasonCode' => 'O-4']
        )->json();

        $response = $this->actingAs($admin)->postJson(
            route('incoming-efaktura.reject', [$company, $document]),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $response->assertOk()->assertJson(['status' => 'rejected']);
        $document->refresh();
        $this->assertSame(IncomingEfakturaDocument::DECISION_REJECTED, $document->decision);
        $this->assertSame('O-4', $document->reject_reason_code);
        $this->assertNull($document->purchase_invoice_id);
    }

    public function test_client_role_is_forbidden(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $response = $this->actingAs($client)->postJson(
            route('incoming-efaktura.reject.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert'), 'reasonCode' => 'O-4']
        );

        $response->assertStatus(403);
    }
}
