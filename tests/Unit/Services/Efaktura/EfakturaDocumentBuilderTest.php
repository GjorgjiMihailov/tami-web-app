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
