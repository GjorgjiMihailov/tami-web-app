<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Services\Reports\Ddv04Query;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Ddv04QueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_aggregates_standard_rate_sales_by_rate(): void
    {
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['status' => 'confirmed', 'invoice_date' => '2026-01-10']);
        $invoice->lines()->create(['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);
        $invoice->lines()->create(['description' => 'B', 'quantity' => '1', 'unit_price' => '200.00', 'vat_rate' => '10.00', 'vat_treatment' => 'standard']);
        $invoice->lines()->create(['description' => 'C', 'quantity' => '1', 'unit_price' => '50.00', 'vat_rate' => '5.00', 'vat_treatment' => 'standard']);

        $result = Ddv04Query::run($company, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame('100.00', $result['01']);
        $this->assertSame('18.00', $result['02']);
        $this->assertSame('200.00', $result['03']);
        $this->assertSame('20.00', $result['04']);
        $this->assertSame('50.00', $result['05']);
        $this->assertSame('2.50', $result['06']);
        $this->assertSame('40.50', $result['20']);
    }

    public function test_it_aggregates_export_lines_to_field_07_with_no_vat(): void
    {
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['status' => 'confirmed', 'invoice_date' => '2026-01-10']);
        $invoice->lines()->create(['description' => 'Export', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '0.00', 'vat_treatment' => 'export']);

        $result = Ddv04Query::run($company, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame('1000.00', $result['07']);
        $this->assertSame('0.00', $result['20']);
    }

    public function test_it_aggregates_exempt_lines_to_fields_08_and_09_separately(): void
    {
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['status' => 'confirmed', 'invoice_date' => '2026-01-10']);
        $invoice->lines()->create(['description' => 'With credit', 'quantity' => '1', 'unit_price' => '300.00', 'vat_rate' => '0.00', 'vat_treatment' => 'exempt_with_credit']);
        $invoice->lines()->create(['description' => 'Without credit', 'quantity' => '1', 'unit_price' => '150.00', 'vat_rate' => '0.00', 'vat_treatment' => 'exempt_without_credit']);

        $result = Ddv04Query::run($company, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame('300.00', $result['08']);
        $this->assertSame('150.00', $result['09']);
    }

    public function test_it_aggregates_deductible_input_vat_and_excludes_non_deductible_lines(): void
    {
        $company = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['status' => 'confirmed', 'invoice_date' => '2026-01-10']);
        $invoice->lines()->create(['description' => 'Deductible', 'quantity' => '1', 'unit_price' => '400.00', 'vat_rate' => '18.00', 'vat_deductible' => true]);
        $invoice->lines()->create(['description' => 'Non-deductible', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00', 'vat_deductible' => false]);

        $result = Ddv04Query::run($company, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame('400.00', $result['21']);
        $this->assertSame('72.00', $result['22']);
        $this->assertSame('72.00', $result['29']);
    }

    public function test_field_31_is_output_vat_minus_deductible_input_vat(): void
    {
        $company = Company::factory()->create();
        $salesInvoice = SalesInvoice::factory()->for($company)->create(['status' => 'confirmed', 'invoice_date' => '2026-01-10']);
        $salesInvoice->lines()->create(['description' => 'Sale', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);
        $purchaseInvoice = PurchaseInvoice::factory()->for($company)->create(['status' => 'confirmed', 'invoice_date' => '2026-01-15']);
        $purchaseInvoice->lines()->create(['description' => 'Purchase', 'quantity' => '1', 'unit_price' => '500.00', 'vat_rate' => '18.00', 'vat_deductible' => true]);

        $result = Ddv04Query::run($company, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame('90.00', $result['31']);
    }

    public function test_draft_and_cancelled_invoices_are_excluded(): void
    {
        $company = Company::factory()->create();
        $draft = SalesInvoice::factory()->for($company)->create(['status' => 'draft', 'invoice_date' => '2026-01-10']);
        $draft->lines()->create(['description' => 'Draft', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);
        $cancelled = SalesInvoice::factory()->for($company)->create(['status' => 'cancelled', 'invoice_date' => '2026-01-10']);
        $cancelled->lines()->create(['description' => 'Cancelled', 'quantity' => '1', 'unit_price' => '2000.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);

        $result = Ddv04Query::run($company, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame('0.00', $result['01']);
        $this->assertSame('0.00', $result['20']);
    }

    public function test_invoices_outside_the_date_range_are_excluded(): void
    {
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['status' => 'confirmed', 'invoice_date' => '2026-02-01']);
        $invoice->lines()->create(['description' => 'Out of range', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);

        $result = Ddv04Query::run($company, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame('0.00', $result['01']);
    }
}
