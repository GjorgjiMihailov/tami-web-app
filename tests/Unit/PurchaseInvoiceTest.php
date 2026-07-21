<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_a_company_and_partner(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);

        $this->assertTrue($invoice->company->is($company));
        $this->assertTrue($invoice->partner->is($partner));
    }

    public function test_totals_sum_across_lines_correctly(): void
    {
        $invoice = PurchaseInvoice::factory()->create();
        $invoice->lines()->create(['description' => 'Line A', 'quantity' => '2', 'unit_price' => '100.00', 'vat_rate' => '18.00']);
        $invoice->lines()->create(['description' => 'Line B', 'quantity' => '1', 'unit_price' => '50.00', 'vat_rate' => '18.00']);

        // Line A: 2 * 100 = 200.00 net, VAT 36.00
        // Line B: 1 * 50  = 50.00 net,  VAT 9.00
        $this->assertSame('250.00', $invoice->fresh(['lines'])->subtotal());
        $this->assertSame('45.00', $invoice->fresh(['lines'])->vatTotal());
        $this->assertSame('295.00', $invoice->fresh(['lines'])->grandTotal());
    }

    public function test_draft_invoices_report_payment_status_as_not_applicable(): void
    {
        $invoice = PurchaseInvoice::factory()->create(['status' => 'draft']);

        $this->assertSame('n/a', $invoice->paymentStatus());
    }

    public function test_supplier_invoice_number_is_unique_per_company_and_partner(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'supplier_invoice_number' => 'INV-001']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'supplier_invoice_number' => 'INV-001']);
    }

    public function test_payment_status_reflects_recorded_payments(): void
    {
        $invoice = PurchaseInvoice::factory()->create(['status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Line A', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);

        $invoice = $invoice->fresh(['lines', 'payments']);
        $this->assertSame('unpaid', $invoice->paymentStatus());

        $invoice->payments()->create(['amount' => '40.00', 'payment_date' => now(), 'payment_method' => 'bank', 'created_by' => \App\Models\User::factory()->create()->id]);
        $invoice = $invoice->fresh(['lines', 'payments']);
        $this->assertSame('partially_paid', $invoice->paymentStatus());
        $this->assertSame('60.00', $invoice->balanceDue());

        $invoice->payments()->create(['amount' => '60.00', 'payment_date' => now(), 'payment_method' => 'bank', 'created_by' => \App\Models\User::factory()->create()->id]);
        $invoice = $invoice->fresh(['lines', 'payments']);
        $this->assertSame('paid', $invoice->paymentStatus());
    }

    public function test_is_overdue_when_unpaid_past_due_date(): void
    {
        $invoice = PurchaseInvoice::factory()->create(['status' => 'confirmed', 'due_date' => now()->subDay()]);
        $invoice->lines()->create(['description' => 'Line A', 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '0']);

        $this->assertTrue($invoice->fresh(['lines', 'payments'])->isOverdue());
    }
}
