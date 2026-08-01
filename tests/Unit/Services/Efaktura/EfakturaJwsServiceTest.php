<?php

namespace Tests\Unit\Services\Efaktura;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Services\Efaktura\EfakturaJwsService;
use App\Support\Base64Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EfakturaJwsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(): SalesInvoice
    {
        $company = Company::factory()->create(['tax_id' => '4030001234567']);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'fiscal_year' => 2026, 'invoice_number' => 1,
            'invoice_date' => '2026-03-01', 'status' => 'confirmed',
        ]);
        $invoice->lines()->create(['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);

        return $invoice->fresh(['lines', 'company', 'partner']);
    }

    public function test_signing_input_is_base64url_header_dot_base64url_payload(): void
    {
        $invoice = $this->makeInvoice();
        $certDer = base64_encode('fake-der-bytes');

        $result = (new EfakturaJwsService)->buildSigningInput($invoice, $certDer);

        [$headerPart, $payloadPart] = explode('.', $result['signingInput']);
        $header = json_decode(Base64Url::decode($headerPart), true);
        $payload = json_decode(Base64Url::decode($payloadPart), true);

        $this->assertSame('RS256', $header['alg']);
        $this->assertSame([$certDer], $header['x5c']);
        $this->assertSame('2026-1', $payload['document']['header']['docNumber']);
        $this->assertSame($result['payloadJson'], Base64Url::decode($payloadPart));
    }

    public function test_send_posts_compact_jws_with_expected_headers(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        $company = Company::factory()->create([
            'tax_id' => '4030001234567', 'efaktura_eujp_id' => 'EUJP-1', 'efaktura_token_serial_number' => '1A2B3C',
        ]);

        $service = new EfakturaJwsService;
        $response = $service->send($company, 'header.payload', 'c2ln');

        $this->assertTrue($response->successful());
        Http::assertSent(function ($request) {
            return $request->url() === rtrim(config('services.efaktura.base_url'), '/').'/JSONReceiver/api/v1/sales-invoices/send'
                && $request->hasHeader('X-EUJP-ID', 'EUJP-1')
                && $request->hasHeader('X-EDB', '4030001234567')
                && $request->hasHeader('X-SERIAL-NUMBER', '1A2B3C')
                && $request->body() === 'header.payload.c2ln';
        });
    }
}
