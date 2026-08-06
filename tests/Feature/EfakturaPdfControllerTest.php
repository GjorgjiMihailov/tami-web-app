<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaPdfControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Storage::fake('local');
    }

    private function makeAcceptedInvoice(): array
    {
        $company = Company::factory()->create([
            'tax_id' => '4030001234567',
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'fiscal_year' => 2026, 'invoice_number' => 1,
            'status' => 'confirmed', 'invoice_date' => '2026-08-01',
            'efaktura_status' => 'sent', 'efaktura_doc_id' => 'euid-1',
            'efaktura_ujp_status_code' => '03', 'efaktura_ujp_status_name' => 'Прифатена',
        ]);

        return [$company, $invoice];
    }

    public function test_signing_input_returns_token_for_an_accepted_invoice(): void
    {
        [$company, $invoice] = $this->makeAcceptedInvoice();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.pdf.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertOk()->assertJsonStructure(['token', 'signingInput']);
    }

    public function test_signing_input_rejects_an_invoice_not_yet_accepted(): void
    {
        [$company, $invoice] = $this->makeAcceptedInvoice();
        $invoice->update(['efaktura_ujp_status_code' => null, 'efaktura_ujp_status_name' => null]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.pdf.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422);
    }

    public function test_store_saves_pdf_and_records_path(): void
    {
        Http::fake(['*' => Http::response(['pdfBase64' => base64_encode('%PDF-fake-bytes')], 200)]);
        [$company, $invoice] = $this->makeAcceptedInvoice();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $signingResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.pdf.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();

        $storeResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.pdf.store', [$company, $invoice]),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $storeResponse->assertOk()->assertJson(['status' => 'saved']);
        $invoice->refresh();
        $this->assertNotNull($invoice->efaktura_pdf_path);
        Storage::disk('local')->assertExists($invoice->efaktura_pdf_path);
    }

    public function test_signing_input_rejects_when_pdf_already_cached(): void
    {
        [$company, $invoice] = $this->makeAcceptedInvoice();
        $path = "efaktura-pdfs/{$company->id}/{$invoice->id}.pdf";
        Storage::disk('local')->put($path, '%PDF-fake-bytes');
        $invoice->update(['efaktura_pdf_path' => $path]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.pdf.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422);
    }

    public function test_signing_input_allows_refetch_when_cached_path_set_but_file_missing(): void
    {
        [$company, $invoice] = $this->makeAcceptedInvoice();
        // DB column points at a path but no file was ever written there (lost/deleted file) —
        // this must be self-healing, not a permanent dead-end (see Finding 2 fix).
        $invoice->update(['efaktura_pdf_path' => "efaktura-pdfs/{$company->id}/{$invoice->id}.pdf"]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.pdf.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertOk();
    }

    public function test_download_returns_404_when_path_set_but_file_missing(): void
    {
        [$company, $invoice] = $this->makeAcceptedInvoice();
        $invoice->update(['efaktura_pdf_path' => "efaktura-pdfs/{$company->id}/{$invoice->id}.pdf"]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(
            route('sales-invoices.efaktura.pdf.download', [$company, $invoice])
        );

        $response->assertStatus(404);
    }

    public function test_download_serves_the_cached_file_without_any_ujp_call(): void
    {
        Http::fake(function () {
            $this->fail('No УЈП call should happen on download of an already-cached PDF.');
        });
        [$company, $invoice] = $this->makeAcceptedInvoice();
        Storage::disk('local')->put("efaktura-pdfs/{$company->id}/{$invoice->id}.pdf", '%PDF-fake-bytes');
        $invoice->update(['efaktura_pdf_path' => "efaktura-pdfs/{$company->id}/{$invoice->id}.pdf"]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('sales-invoices.efaktura.pdf.download', [$company, $invoice]));

        $response->assertOk();
    }

    public function test_download_404s_when_no_pdf_cached_yet(): void
    {
        [$company, $invoice] = $this->makeAcceptedInvoice();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('sales-invoices.efaktura.pdf.download', [$company, $invoice]));

        $response->assertStatus(404);
    }
}
