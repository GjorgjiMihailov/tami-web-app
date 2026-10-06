<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Accounting\OpenInvoicesQuery;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenInvoicesQueryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->user = User::factory()->create();
    }

    private function sale(string $price, string $invoiceDate, string $dueDate, array $attrs = []): SalesInvoice
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = SalesInvoice::factory()->for($this->company)->create(array_merge([
            'partner_id' => $partner->id, 'invoice_date' => $invoiceDate, 'due_date' => $dueDate,
        ], $attrs));
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => $price, 'vat_rate' => '0']);

        return app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->user->id);
    }

    private function purchase(string $price, string $invoiceDate, string $dueDate): PurchaseInvoice
    {
        $partner = Partner::factory()->for($this->company)->create();
        $expense = Account::where('company_id', $this->company->id)->where('code', '4620')->firstOrFail();
        $invoice = PurchaseInvoice::factory()->for($this->company)->create([
            'partner_id' => $partner->id, 'invoice_date' => $invoiceDate, 'due_date' => $dueDate,
        ]);
        $invoice->lines()->create(['account_id' => $expense->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => $price, 'vat_rate' => '0']);

        return app(PurchaseInvoiceService::class)->confirm($invoice->fresh(), $this->user->id);
    }

    public function test_receivables_split_into_current_and_overdue(): void
    {
        $this->sale('100.00', '2026-01-01', now()->subDays(10)->toDateString());
        $this->sale('250.00', '2026-01-01', now()->addDays(10)->toDateString());

        $this->assertSame(
            ['total' => '350.00', 'current' => '250.00', 'overdue' => '100.00'],
            OpenInvoicesQuery::receivables($this->company)
        );
    }

    public function test_it_counts_every_year_not_just_the_working_one(): void
    {
        $this->sale('100.00', '2024-03-01', '2024-04-01');
        $this->sale('50.00', '2026-03-01', now()->addDays(5)->toDateString());

        $this->assertSame('150.00', OpenInvoicesQuery::receivables($this->company)['total']);
    }

    public function test_paid_drafts_and_other_companies_are_left_out(): void
    {
        $paid = $this->sale('100.00', '2026-01-01', now()->addDays(10)->toDateString());
        app(SalesInvoiceService::class)->recordPayment($paid->fresh(), '100.00', '2026-01-05', 'bank', $this->user->id);
        $partial = $this->sale('200.00', '2026-01-01', now()->addDays(10)->toDateString());
        app(SalesInvoiceService::class)->recordPayment($partial->fresh(), '80.00', '2026-01-05', 'bank', $this->user->id);
        SalesInvoice::factory()->for($this->company)->create(); // нацрт
        $other = Company::factory()->create();
        SalesInvoice::factory()->for($other)->create(['status' => 'confirmed']);

        $this->assertSame('120.00', OpenInvoicesQuery::receivables($this->company)['total']);
    }

    public function test_a_foreign_invoice_is_converted_at_its_own_rate(): void
    {
        $invoice = $this->sale('100.00', '2026-03-01', now()->addDays(10)->toDateString());
        $invoice->update(['currency' => 'EUR', 'exchange_rate' => '61.500000']);

        $this->assertSame('6150.00', OpenInvoicesQuery::receivables($this->company)['total']);
    }

    public function test_an_empty_company_has_zeros(): void
    {
        $zero = ['total' => '0.00', 'current' => '0.00', 'overdue' => '0.00'];

        $this->assertSame($zero, OpenInvoicesQuery::receivables($this->company));
        $this->assertSame($zero, OpenInvoicesQuery::payables($this->company));
    }

    public function test_payables_split_the_same_way(): void
    {
        $this->purchase('300.00', '2026-01-01', now()->subDays(3)->toDateString());
        $this->purchase('120.00', '2026-01-01', now()->addDays(20)->toDateString());

        $this->assertSame(
            ['total' => '420.00', 'current' => '120.00', 'overdue' => '300.00'],
            OpenInvoicesQuery::payables($this->company)
        );
    }
}
