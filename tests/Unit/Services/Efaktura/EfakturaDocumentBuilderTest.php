<?php

namespace Tests\Unit\Services\Efaktura;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Services\Efaktura\EfakturaDocumentBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EfakturaDocumentBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_document_with_seller_buyer_and_totals(): void
    {
        $company = Company::factory()->create([
            'tax_id' => '4030001234567',
            'name' => 'Тест Фирма ДООЕЛ',
            'street_address' => 'Мајка Тереза', 'street_number' => '12',
            'postal_code' => '1000', 'city' => 'Скопје',
        ]);
        $partner = Partner::factory()->for($company)->create([
            'tax_id' => '4030007654321',
            'name' => 'Купувач ДОО',
            'street_address' => 'Партизанска', 'street_number' => '5',
            'postal_code' => '1000', 'city' => 'Скопје',
        ]);
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'fiscal_year' => 2026,
            'invoice_number' => 42,
            'invoice_date' => '2026-03-01',
            'status' => 'confirmed',
            'payment_type_code' => 'P12',
        ]);
        $invoice->lines()->create([
            'description' => 'Услуга А', 'quantity' => '2', 'unit_price' => '100.00',
            'vat_rate' => '18.00', 'vat_treatment' => 'standard',
        ]);

        $document = (new EfakturaDocumentBuilder)->build($invoice->fresh(['lines', 'company', 'partner']));

        $this->assertSame('2026-42', $document['document']['header']['docNumber']);
        $this->assertSame('4030001234567', $document['document']['seller']['sellerTin']);
        $this->assertSame('Мајка Тереза', $document['document']['seller']['sellerAddress']['streetAddress']);
        $this->assertSame('4030007654321', $document['document']['buyer']['buyerTin']);
        $this->assertSame('P12', $document['document']['docPayment']['docPaymentTypeCode']);
        $this->assertSame('Плаќање преку банка', $document['document']['docPayment']['docPaymentTypeDesc']);
        $this->assertCount(1, $document['document']['docItems']);
        $this->assertSame('DDV-A', $document['document']['docItems'][0]['docItemTaxIndicator']);
        $this->assertSame(200.0, $document['document']['docTotals']['docNetAmount']);
        $this->assertSame(36.0, $document['document']['docTotals']['docVatAmount']);
        $this->assertSame(236.0, $document['document']['docTotals']['docGrossAmount']);
        $this->assertCount(1, $document['document']['vatTotals']);
        $this->assertSame('DDV-A', $document['document']['vatTotals'][0]['vatTaxIndicator']);
        $this->assertSame(36.0, $document['document']['vatTotals'][0]['vatAmount']);
    }

    public function test_vat_number_uses_cyrillic_mk_prefix_when_party_is_vat_registered(): void
    {
        // Confirmed against a real UJP-supplied worked example (primer_za_json_3.pdf,
        // "sellerVatNumber": "МК4030995135699") — the VAT number prefix is genuinely
        // Cyrillic "МК" (U+041C U+041A), not the visually-identical Latin "MK". An
        // earlier fix cycle in this project mistook this for a homoglyph bug and
        // "corrected" it to Latin — that was itself the bug; this test locks in the
        // real, UJP-confirmed value so it can't regress back to Latin again.
        $company = Company::factory()->create([
            'tax_id' => '4030001234567',
            'is_vat_registered' => true,
        ]);
        $partner = Partner::factory()->for($company)->create([
            'tax_id' => '4030007654321',
            'is_vat_registered' => true,
        ]);
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'fiscal_year' => 2026, 'invoice_number' => 3,
            'invoice_date' => '2026-03-01', 'status' => 'confirmed',
        ]);
        $invoice->lines()->create(['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);

        $document = (new EfakturaDocumentBuilder)->build($invoice->fresh(['lines', 'company', 'partner']));

        $this->assertSame("\u{041C}\u{041A}4030001234567", $document['document']['seller']['sellerVatNumber']);
        $this->assertSame("\u{041C}\u{041A}4030007654321", $document['document']['buyer']['buyerVatNumber']);
        // Must NOT be the visually-identical Latin "MK" (U+004D U+004B).
        $this->assertNotSame('MK4030001234567', $document['document']['seller']['sellerVatNumber']);
    }

    public function test_vat_number_is_null_when_party_is_not_vat_registered(): void
    {
        $company = Company::factory()->create([
            'tax_id' => '4030001234567',
            'is_vat_registered' => false,
        ]);
        $partner = Partner::factory()->for($company)->create([
            'tax_id' => '4030007654321',
            'is_vat_registered' => false,
        ]);
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'fiscal_year' => 2026, 'invoice_number' => 4,
            'invoice_date' => '2026-03-01', 'status' => 'confirmed',
        ]);
        $invoice->lines()->create(['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);

        $document = (new EfakturaDocumentBuilder)->build($invoice->fresh(['lines', 'company', 'partner']));

        $this->assertNull($document['document']['seller']['sellerVatNumber']);
        $this->assertNull($document['document']['buyer']['buyerVatNumber']);
    }

    public function test_groups_vat_totals_by_distinct_tax_indicator(): void
    {
        $company = Company::factory()->create(['tax_id' => '4030001234567']);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'fiscal_year' => 2026, 'invoice_number' => 1,
            'invoice_date' => '2026-03-01', 'status' => 'confirmed',
        ]);
        $invoice->lines()->create(['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);
        $invoice->lines()->create(['description' => 'B', 'quantity' => '1', 'unit_price' => '50.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);
        $invoice->lines()->create(['description' => 'C', 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '0.00', 'vat_treatment' => 'export']);

        $document = (new EfakturaDocumentBuilder)->build($invoice->fresh(['lines', 'company', 'partner']));

        $this->assertCount(2, $document['document']['vatTotals']);
        $ddvA = collect($document['document']['vatTotals'])->firstWhere('vatTaxIndicator', 'DDV-A');
        $this->assertSame(150.0, $ddvA['vatTaxableAmount']);
        $this->assertSame(27.0, $ddvA['vatAmount']);
        $ddv7i = collect($document['document']['vatTotals'])->firstWhere('vatTaxIndicator', 'DDV-7-I');
        $this->assertSame(10.0, $ddv7i['vatTaxableAmount']);
        $this->assertSame(0.0, $ddv7i['vatAmount']);
    }

    public function test_request_timestamp_is_skopje_local_time_with_no_z_suffix(): void
    {
        // Confirmed against efakturawiki.ujp.gov.mk's changelog (27.05.2026 entry) and
        // primer_za_json_3.pdf's worked examples (e.g. "requestTimestamp": "2026-04-27T11:24:10"):
        // no trailing "Z", and UJP explicitly states the time must be in the Skopje timezone —
        // not UTC, which is this app's default (config/app.php: 'timezone' => 'UTC').
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::create(2026, 6, 15, 10, 0, 0, 'UTC'));

        $company = Company::factory()->create(['tax_id' => '4030001234567']);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'fiscal_year' => 2026, 'invoice_number' => 5,
            'invoice_date' => '2026-03-01', 'status' => 'confirmed',
        ]);
        $invoice->lines()->create(['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);

        $document = (new EfakturaDocumentBuilder)->build($invoice->fresh(['lines', 'company', 'partner']));

        // Skopje is UTC+2 in June (CEST-aligned, summer time) -> 10:00 UTC = 12:00 Skopje.
        $this->assertSame('2026-06-15T12:00:00', $document['requestTimestamp']);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_doc_references_is_present_as_empty_array(): void
    {
        // primer_za_json_3.pdf's multi-item example includes "docReferences": [] at the
        // document level even when there are no referenced documents to report.
        $company = Company::factory()->create(['tax_id' => '4030001234567']);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'fiscal_year' => 2026, 'invoice_number' => 6,
            'invoice_date' => '2026-03-01', 'status' => 'confirmed',
        ]);
        $invoice->lines()->create(['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);

        $document = (new EfakturaDocumentBuilder)->build($invoice->fresh(['lines', 'company', 'partner']));

        $this->assertSame([], $document['document']['docReferences']);
    }

    public function test_doc_item_unit_vat_is_the_per_unit_vat_amount_not_the_rate(): void
    {
        // Confirmed against primer_za_json_3.pdf's worked example (unit price 95.2381 at
        // 5% yields "docItemUnitVat": 4.7619 vs. "docItemVat": 5 — two different fields,
        // per-unit VAT amount vs. the rate itself). This app's unit_price column is
        // decimal:2, so use a non-round 2dp price (33.33 @ 18%) that still breaks the
        // coincidence a round price like 100.00 would hide (18% of 100 is 18, masking
        // the bug that survived earlier tests using only round unit prices).
        $company = Company::factory()->create(['tax_id' => '4030001234567']);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'fiscal_year' => 2026, 'invoice_number' => 7,
            'invoice_date' => '2026-03-01', 'status' => 'confirmed',
        ]);
        $invoice->lines()->create(['description' => 'A', 'quantity' => '1', 'unit_price' => '33.33', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);

        $document = (new EfakturaDocumentBuilder)->build($invoice->fresh(['lines', 'company', 'partner']));

        $item = $document['document']['docItems'][0];
        $this->assertEqualsWithDelta(5.9994, $item['docItemUnitVat'], 0.0001);
        $this->assertSame(18.0, $item['docItemVat']);
    }

    public function test_doc_totals_avoid_float_summation_drift_across_many_lines(): void
    {
        // Classic IEEE-754 drift trigger: array_sum([0.10, 0.20, 0.30]) !== 0.6 in PHP
        // when the individual addends are already float-cast values. docNetAmount must
        // be aggregated via bcmath (like buildVatTotals() already does) rather than by
        // summing the per-item float-cast docItemTotalPriceWoVat values, or this drifts.
        $company = Company::factory()->create(['tax_id' => '4030001234567']);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'fiscal_year' => 2026, 'invoice_number' => 2,
            'invoice_date' => '2026-03-01', 'status' => 'confirmed',
        ]);
        $invoice->lines()->create(['description' => 'A', 'quantity' => '1', 'unit_price' => '0.10', 'vat_rate' => '0.00', 'vat_treatment' => 'export']);
        $invoice->lines()->create(['description' => 'B', 'quantity' => '1', 'unit_price' => '0.20', 'vat_rate' => '0.00', 'vat_treatment' => 'export']);
        $invoice->lines()->create(['description' => 'C', 'quantity' => '1', 'unit_price' => '0.30', 'vat_rate' => '0.00', 'vat_treatment' => 'export']);

        $document = (new EfakturaDocumentBuilder)->build($invoice->fresh(['lines', 'company', 'partner']));

        $this->assertSame(0.6, $document['document']['docTotals']['docNetAmount']);
        $this->assertSame(0.0, $document['document']['docTotals']['docVatAmount']);
        $this->assertSame(0.6, $document['document']['docTotals']['docGrossAmount']);
    }
}
