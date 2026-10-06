<?php

namespace Tests\Feature\Posting;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use App\Services\Posting\PostedInvoiceAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesPaymentSchemePostingTest extends TestCase
{
    use RefreshDatabase;

    private function confirmed(Company $company, string $price = '100.00', array $invoiceAttrs = [], string $vatRate = '0'): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(array_merge(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01'], $invoiceAttrs));
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => $price, 'vat_rate' => $vatRate]);

        return app(SalesInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);
    }

    private function paymentEntry(SalesInvoice $invoice): JournalEntry
    {
        return JournalEntry::where('company_id', $invoice->company_id)->where('id', '!=', $invoice->journal_entry_id)->with('lines.account')->latest('id')->firstOrFail();
    }

    public function test_a_bank_payment_posts_1000_against_the_receivable_1200(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->confirmed($company);

        app(SalesInvoiceService::class)->recordPayment($invoice, '60.00', '2026-03-10', 'bank', User::factory()->create()->id);

        $entry = $this->paymentEntry($invoice);
        $this->assertSame('60.00', (string) $entry->lines->firstWhere('account.code', '1000')->debit);
        $this->assertSame('60.00', (string) $entry->lines->firstWhere('account.code', '1200')->credit);
        $this->assertSame('Payment for invoice '.$invoice->invoice_number_formatted, $entry->description);
        $this->assertTrue($entry->lines->every(fn ($l) => $l->partner_id === $invoice->partner_id));
    }

    public function test_a_cash_payment_posts_to_1020(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->confirmed($company);

        app(SalesInvoiceService::class)->recordPayment($invoice, '100.00', '2026-03-10', 'cash', User::factory()->create()->id);

        $this->assertNotNull($this->paymentEntry($invoice)->lines->firstWhere('account.code', '1020'));
    }

    public function test_a_payment_on_an_invoice_booked_on_120_before_the_schemes_closes_120(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $legacy = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-01-10', 'status' => 'confirmed', 'fiscal_year' => 2026, 'invoice_number' => 1, 'invoice_number_formatted' => '2026/1']);
        $legacy->lines()->create(['description' => 'Стара', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $group = JournalGroup::firstOrCreate(['company_id' => $company->id, 'code' => '99'], ['name' => 'Автоматски', 'sort_order' => 99]);
        $entry = JournalEntry::create(['company_id' => $company->id, 'journal_group_id' => $group->id, 'entry_date' => '2026-01-10', 'description' => 'Sales Invoice 2026/1', 'created_by' => User::factory()->create()->id]);
        $entry->lines()->create(['account_id' => Account::where('company_id', $company->id)->where('code', '120')->value('id'), 'partner_id' => $partner->id, 'line_date' => '2026-01-10', 'debit' => '100.00', 'credit' => '0', 'description' => 'Invoice 2026/1']);
        $entry->lines()->create(['account_id' => Account::where('company_id', $company->id)->where('code', '740')->value('id'), 'partner_id' => $partner->id, 'line_date' => '2026-01-10', 'debit' => '0', 'credit' => '100.00', 'description' => 'Invoice 2026/1']);
        $legacy->update(['journal_entry_id' => $entry->id]);

        $this->assertSame('120', PostedInvoiceAccounts::receivable($legacy->fresh())->code);

        app(SalesInvoiceService::class)->recordPayment($legacy->fresh(), '100.00', '2026-02-01', 'bank', User::factory()->create()->id);

        $payment = $this->paymentEntry($legacy);
        $this->assertSame('100.00', (string) $payment->lines->firstWhere('account.code', '120')->credit);
        $this->assertNull($payment->lines->firstWhere('account.code', '1200'));
    }

    public function test_a_draft_invoice_falls_back_to_the_scheme_receivable(): void
    {
        $company = Company::factory()->create();
        $draft = SalesInvoice::factory()->for($company)->create();

        $this->assertSame('1200', PostedInvoiceAccounts::receivable($draft)->code);
    }

    public function test_a_foreign_invoice_still_closes_to_exactly_zero_in_instalments(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->confirmed($company, '33.33', ['currency' => 'EUR', 'exchange_rate' => '61.512345'], '18.00')->fresh(['lines', 'payments']);
        $service = app(SalesInvoiceService::class);
        $user = User::factory()->create();
        $total = $invoice->grandTotal();
        $first = bcdiv($total, '3', 2);
        $second = bcdiv($total, '3', 2);
        $service->recordPayment($invoice, $first, '2026-03-10', 'bank', $user->id);
        $service->recordPayment($invoice->fresh(['lines', 'payments']), $second, '2026-03-11', 'bank', $user->id);
        $service->recordPayment($invoice->fresh(['lines', 'payments']), bcsub(bcsub($total, $first, 2), $second, 2), '2026-03-12', 'bank', $user->id);

        $receivable = PostedInvoiceAccounts::receivable($invoice->fresh());
        $lines = \App\Models\JournalEntryLine::where('account_id', $receivable->id)->get();
        $balance = $lines->reduce(fn ($c, $l) => bcsub(bcadd($c, (string) $l->debit, 2), (string) $l->credit, 2), '0.00');

        $this->assertSame('0.00', $balance);
        $this->assertSame('EUR', $lines->last()->currency_code);
    }
}
