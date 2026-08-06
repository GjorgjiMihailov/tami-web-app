<?php

namespace Tests\Unit\Services\Efaktura;

use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use App\Services\Efaktura\IncomingPurchaseInvoiceBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomingPurchaseInvoiceBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function samplePayload(array $overrides = []): array
    {
        $base = [
            'document' => [
                'header' => ['docNumber' => 'SUP-2026-1', 'docDate' => '2026-08-01'],
                'seller' => [
                    'sellerTin' => '4030009998887',
                    'sellerVatNumber' => "\u{041C}\u{041A}4030009998887",
                    'sellerName' => 'Добавувач ДООЕЛ Скопје',
                    'sellerAddress' => [
                        'streetAddress' => 'Партизанска', 'streetNumber' => '10',
                        'postalCode' => '1000', 'city' => 'СКОПЈЕ',
                    ],
                ],
                'docPayment' => ['docPaymentTypeDueDate' => '2026-08-15'],
                'docItems' => [
                    [
                        'docItemDesc' => 'Канцелариски материјал', 'docItemQty' => 2,
                        'docItemUnitPriceWoVat' => 500, 'docItemTaxIndicator' => 'DDV-A',
                    ],
                ],
            ],
        ];

        return array_replace_recursive($base, $overrides);
    }

    public function test_build_creates_a_draft_purchase_invoice_with_lines(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();

        $invoice = (new IncomingPurchaseInvoiceBuilder)->build($company, $this->samplePayload(), $user);

        $this->assertSame('draft', $invoice->status);
        $this->assertSame('SUP-2026-1', $invoice->supplier_invoice_number);
        $this->assertSame('2026-08-01', $invoice->invoice_date->toDateString());
        $this->assertSame('2026-08-15', $invoice->due_date->toDateString());
        $this->assertCount(1, $invoice->lines);
        $line = $invoice->lines->first();
        $this->assertSame('Канцелариски материјал', $line->description);
        $this->assertSame('2.000', $line->quantity);
        $this->assertSame('500.00', $line->unit_price);
        $this->assertSame('18.00', $line->vat_rate);
        $this->assertNull($line->item_id);
        $this->assertNull($line->account_id);
        $this->assertFalse($line->needs_review);
    }

    public function test_build_creates_a_new_partner_when_tax_id_is_unknown(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();

        $invoice = (new IncomingPurchaseInvoiceBuilder)->build($company, $this->samplePayload(), $user);

        $partner = $invoice->partner;
        $this->assertSame('4030009998887', $partner->tax_id);
        $this->assertSame('Добавувач ДООЕЛ Скопје', $partner->name);
        $this->assertSame('Партизанска', $partner->street_address);
        $this->assertSame('СКОПЈЕ', $partner->city);
        $this->assertTrue($partner->is_vat_registered);
    }

    public function test_build_reuses_an_existing_partner_matched_by_normalized_tax_id(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        // Existing partner's tax_id has a stray Cyrillic "МК" prefix baked in — same real-world
        // dirty-data pattern already fixed once for outgoing invoices in EfakturaDocumentBuilder.
        $existingPartner = Partner::factory()->for($company)->create(['tax_id' => "\u{041C}\u{041A}4030009998887"]);

        $invoice = (new IncomingPurchaseInvoiceBuilder)->build($company, $this->samplePayload(), $user);

        $this->assertTrue($invoice->partner->is($existingPartner));
        $this->assertSame(1, Partner::where('company_id', $company->id)->count());
    }

    public function test_build_flags_a_line_needing_review_for_an_unsupported_tax_indicator(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $payload = $this->samplePayload([
            'document' => ['docItems' => [['docItemTaxIndicator' => 'DDV-11-A']]],
        ]);

        $invoice = (new IncomingPurchaseInvoiceBuilder)->build($company, $payload, $user);

        $line = $invoice->lines->first();
        $this->assertSame('0.00', $line->vat_rate);
        $this->assertTrue($line->needs_review);
    }

    public function test_build_falls_back_to_doc_date_when_due_date_is_missing(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $payload = $this->samplePayload(['document' => ['docPayment' => ['docPaymentTypeDueDate' => null]]]);

        $invoice = (new IncomingPurchaseInvoiceBuilder)->build($company, $payload, $user);

        $this->assertSame('2026-08-01', $invoice->due_date->toDateString());
    }
}
