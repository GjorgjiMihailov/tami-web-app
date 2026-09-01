<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseInvoicePayment;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesInvoicePayment;
use App\Services\CompanyDashboardQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyDashboardQueryTest extends TestCase
{
    use RefreshDatabase;

    /** Потврдена излезна фактура со една ставка од 59.000 вкупно (1.000 x 50000.00 + 18% ДДВ). */
    private function invoice(
        Company $company,
        string $date,
        string $status = 'confirmed',
        ?string $dueDate = null,
    ): SalesInvoice {
        $invoice = SalesInvoice::factory()->for($company)->create([
            'invoice_date' => $date,
            'due_date' => $dueDate ?? $date,
            'status' => $status,
        ]);

        SalesInvoiceLine::factory()->for($invoice, 'salesInvoice')->create([
            'quantity' => '1.000',
            'unit_price' => '50000.00',
            'vat_rate' => '18.00',
        ]);

        return $invoice->fresh();
    }

    /** Потврдена влезна фактура со една ставка од 59.000 вкупно (1.000 x 50000.00 + 18% ДДВ). */
    private function purchaseInvoice(
        Company $company,
        string $date,
        string $status = 'confirmed',
        ?string $dueDate = null,
    ): PurchaseInvoice {
        $invoice = PurchaseInvoice::factory()->for($company)->create([
            'invoice_date' => $date,
            'due_date' => $dueDate ?? $date,
            'status' => $status,
        ]);

        PurchaseInvoiceLine::factory()->for($invoice, 'purchaseInvoice')->create([
            'quantity' => '1.000',
            'unit_price' => '50000.00',
            'vat_rate' => '18.00',
        ]);

        return $invoice->fresh();
    }

    public function test_revenue_counts_only_confirmed_invoices_of_the_working_year(): void
    {
        $company = Company::factory()->create();

        $this->invoice($company, '2026-03-10');
        $this->invoice($company, '2026-04-10');
        // Нацртот не е приход додека не е издаден.
        $this->invoice($company, '2026-05-10', 'draft');

        $this->assertSame('118000.00', CompanyDashboardQuery::revenue($company, 2026));
    }

    public function test_revenue_excludes_another_year(): void
    {
        $company = Company::factory()->create();

        $this->invoice($company, '2025-11-10');
        $this->invoice($company, '2026-03-10');

        $this->assertSame('59000.00', CompanyDashboardQuery::revenue($company, 2026));
    }

    public function test_receivable_is_what_is_invoiced_minus_what_is_paid(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->invoice($company, '2026-03-10');

        SalesInvoicePayment::factory()->for($invoice, 'salesInvoice')->create([
            'amount' => '20000.00',
            'payment_date' => '2026-03-20',
        ]);

        $this->assertSame('39000.00', CompanyDashboardQuery::receivable($company, 2026));
    }

    public function test_only_a_past_due_date_counts_as_overdue(): void
    {
        Carbon::setTestNow('2026-06-15');

        $company = Company::factory()->create();
        $this->invoice($company, '2026-03-10', 'confirmed', '2026-04-10');
        $this->invoice($company, '2026-03-10', 'confirmed', '2026-12-31');

        $this->assertSame('118000.00', CompanyDashboardQuery::receivable($company, 2026));
        $this->assertSame('59000.00', CompanyDashboardQuery::receivableOverdue($company, 2026));

        Carbon::setTestNow();
    }

    public function test_a_failed_efaktura_send_is_counted(): void
    {
        $company = Company::factory()->create();

        $this->invoice($company, '2026-03-10')->update(['efaktura_status' => 'failed']);
        $this->invoice($company, '2026-04-10');
        $this->invoice($company, '2026-05-10');

        $this->assertSame(1, CompanyDashboardQuery::efakturaFailed($company, 2026));
    }

    public function test_costs_counts_only_confirmed_invoices_of_the_working_year(): void
    {
        $company = Company::factory()->create();

        $this->purchaseInvoice($company, '2026-03-10');
        $this->purchaseInvoice($company, '2026-04-10');
        // Нацртот не е трошок додека не е потврден.
        $this->purchaseInvoice($company, '2026-05-10', 'draft');

        $this->assertSame('118000.00', CompanyDashboardQuery::costs($company, 2026));
    }

    public function test_costs_excludes_another_year(): void
    {
        $company = Company::factory()->create();

        $this->purchaseInvoice($company, '2025-11-10');
        $this->purchaseInvoice($company, '2026-03-10');

        $this->assertSame('59000.00', CompanyDashboardQuery::costs($company, 2026));
    }

    public function test_payable_is_what_is_invoiced_minus_what_is_paid(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->purchaseInvoice($company, '2026-03-10');

        PurchaseInvoicePayment::factory()->for($invoice, 'purchaseInvoice')->create([
            'amount' => '20000.00',
            'payment_date' => '2026-03-20',
        ]);

        $this->assertSame('39000.00', CompanyDashboardQuery::payable($company, 2026));
    }

    public function test_only_a_past_due_date_counts_as_overdue_for_payable(): void
    {
        Carbon::setTestNow('2026-06-15');

        $company = Company::factory()->create();
        $this->purchaseInvoice($company, '2026-03-10', 'confirmed', '2026-04-10');
        $this->purchaseInvoice($company, '2026-03-10', 'confirmed', '2026-12-31');

        $this->assertSame('118000.00', CompanyDashboardQuery::payable($company, 2026));
        $this->assertSame('59000.00', CompanyDashboardQuery::payableOverdue($company, 2026));

        Carbon::setTestNow();
    }
}
