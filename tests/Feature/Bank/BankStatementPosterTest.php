<?php

namespace Tests\Feature\Bank;

use App\Exceptions\InvalidBankStatementException;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Bank\BankStatementPoster;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Invoicing\SalesInvoiceService;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankStatementPosterTest extends TestCase
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

    private function account(string $code): Account
    {
        return Account::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function statement(string $opening, string $closing, array $attrs = []): BankStatement
    {
        return BankStatement::factory()->for($this->company)->create(array_merge([
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'statement_date' => '2026-03-05',
        ], $attrs));
    }

    private function line(BankStatement $statement, LineDirection $direction, string $amount, array $attrs = []): BankStatementLine
    {
        return BankStatementLine::factory()->for($statement)->create(array_merge([
            'line_date' => '2026-03-05',
            'direction' => $direction,
            'amount' => $amount,
            'kind' => LineKind::ACCOUNT,
        ], $attrs));
    }

    private function poster(): BankStatementPoster
    {
        return app(BankStatementPoster::class);
    }

    private function confirmedSalesInvoice(Partner $partner, string $price): SalesInvoice
    {
        $invoice = SalesInvoice::factory()->for($this->company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => $price, 'vat_rate' => '0']);
        app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->user->id);

        return $invoice;
    }

    public function test_one_entry_per_statement_with_inflows_debiting_1000_and_outflows_crediting_it(): void
    {
        $statement = $this->statement('1000.00', '1300.00');
        $this->line($statement, LineDirection::IN, '500.00', ['account_id' => $this->account('7400')->id]);
        $this->line($statement, LineDirection::OUT, '200.00', ['account_id' => $this->account('4200')->id]);

        $entry = $this->poster()->post($statement, $this->user->id)->load('lines.account', 'journalGroup');

        $this->assertSame('10', $entry->journalGroup->code);
        $this->assertSame('2026-03-05', $entry->entry_date->toDateString());
        $this->assertCount(4, $entry->lines);
        $bankLines = $entry->lines->where('account.code', '1000');
        $this->assertSame('500.00', (string) $bankLines->firstWhere('debit', '500.00')->debit);
        $this->assertSame('200.00', (string) $bankLines->firstWhere('credit', '200.00')->credit);
        $this->assertSame('500.00', (string) $entry->lines->firstWhere('account.code', '7400')->credit);
        $this->assertSame('200.00', (string) $entry->lines->firstWhere('account.code', '4200')->debit);
        $this->assertSame('0.00', bcsub((string) $entry->lines->sum('debit'), (string) $entry->lines->sum('credit'), 2));

        $fresh = $statement->fresh();
        $this->assertTrue($fresh->isBooked());
        $this->assertSame($entry->id, $fresh->journal_entry_id);
    }

    public function test_an_unbalanced_statement_is_refused_and_posts_nothing(): void
    {
        $statement = $this->statement('1000.00', '9999.00');
        $this->line($statement, LineDirection::IN, '500.00', ['account_id' => $this->account('7400')->id]);

        try {
            $this->poster()->post($statement, $this->user->id);
            $this->fail('Очекувана е InvalidBankStatementException.');
        } catch (InvalidBankStatementException $e) {
            $this->assertStringContainsString('разлика', $e->getMessage());
        }

        $this->assertFalse($statement->fresh()->isBooked());
        $this->assertSame(0, JournalEntry::where('company_id', $this->company->id)->count());
    }

    public function test_a_booked_statement_cannot_be_booked_twice(): void
    {
        $statement = $this->statement('0.00', '500.00');
        $this->line($statement, LineDirection::IN, '500.00', ['account_id' => $this->account('7400')->id]);
        $this->poster()->post($statement, $this->user->id);

        $this->expectException(InvalidBankStatementException::class);

        $this->poster()->post($statement->fresh(), $this->user->id);
    }

    public function test_an_invoice_line_creates_the_payment_and_posts_against_120(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = $this->confirmedSalesInvoice($partner, '300.00');
        $statement = $this->statement('0.00', '300.00');
        $line = $this->line($statement, LineDirection::IN, '300.00', ['kind' => LineKind::INVOICE_PAYMENT, 'sales_invoice_id' => $invoice->id, 'partner_id' => $partner->id]);

        $entry = $this->poster()->post($statement, $this->user->id)->load('lines.account');

        $this->assertSame('300.00', (string) $entry->lines->firstWhere('account.code', '120')->credit);
        $this->assertSame($partner->id, $entry->lines->firstWhere('account.code', '120')->partner_id);
        $line = $line->fresh();
        $this->assertTrue($line->created_payment);
        $this->assertNotNull($line->sales_invoice_payment_id);
        $this->assertSame('paid', $invoice->fresh(['lines', 'payments'])->paymentStatus());
    }

    public function test_a_purchase_invoice_line_posts_against_220(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = PurchaseInvoice::factory()->for($this->company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => $this->account('462')->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => '200.00', 'vat_rate' => '0']);
        app(PurchaseInvoiceService::class)->confirm($invoice->fresh(), $this->user->id);
        $statement = $this->statement('500.00', '300.00');
        $this->line($statement, LineDirection::OUT, '200.00', ['kind' => LineKind::INVOICE_PAYMENT, 'purchase_invoice_id' => $invoice->id, 'partner_id' => $partner->id]);

        $entry = $this->poster()->post($statement, $this->user->id)->load('lines.account');

        $this->assertSame('200.00', (string) $entry->lines->firstWhere('account.code', '220')->debit);
        $this->assertSame('200.00', (string) $entry->lines->firstWhere('account.code', '1000')->credit);
    }

    public function test_a_line_linked_to_an_existing_payment_posts_nothing_again(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = $this->confirmedSalesInvoice($partner, '300.00');
        $payment = app(SalesInvoiceService::class)->recordPayment($invoice->fresh(), '300.00', '2026-03-04', 'bank', $this->user->id);
        $statement = $this->statement('0.00', '300.00');
        $this->line($statement, LineDirection::IN, '300.00', ['kind' => LineKind::INVOICE_PAYMENT, 'sales_invoice_id' => $invoice->id, 'sales_invoice_payment_id' => $payment->id]);
        $paymentsBefore = $invoice->payments()->count();

        $entry = $this->poster()->post($statement, $this->user->id)->load('lines');

        $this->assertCount(0, $entry->lines);
        $this->assertSame($paymentsBefore, $invoice->payments()->count());
        $this->assertFalse($statement->lines()->first()->created_payment);
    }

    public function test_reopening_deletes_the_entry_and_only_the_payments_the_statement_created(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = $this->confirmedSalesInvoice($partner, '300.00');
        $statement = $this->statement('0.00', '300.00');
        $this->line($statement, LineDirection::IN, '300.00', ['kind' => LineKind::INVOICE_PAYMENT, 'sales_invoice_id' => $invoice->id, 'partner_id' => $partner->id]);
        $entry = $this->poster()->post($statement, $this->user->id);

        $this->poster()->reopen($statement->fresh());

        $fresh = $statement->fresh();
        $this->assertFalse($fresh->isBooked());
        $this->assertNull($fresh->journal_entry_id);
        $this->assertNull(JournalEntry::find($entry->id));
        $this->assertSame(0, $invoice->payments()->count());
        $line = $fresh->lines()->first();
        $this->assertFalse($line->created_payment);
        $this->assertNull($line->sales_invoice_payment_id);
    }

    public function test_reopening_keeps_a_previously_linked_payment(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = $this->confirmedSalesInvoice($partner, '300.00');
        $payment = app(SalesInvoiceService::class)->recordPayment($invoice->fresh(), '300.00', '2026-03-04', 'bank', $this->user->id);
        $statement = $this->statement('0.00', '300.00');
        $this->line($statement, LineDirection::IN, '300.00', ['kind' => LineKind::INVOICE_PAYMENT, 'sales_invoice_id' => $invoice->id, 'sales_invoice_payment_id' => $payment->id]);
        $this->poster()->post($statement, $this->user->id);

        $this->poster()->reopen($statement->fresh());

        $this->assertSame(1, $invoice->payments()->count());
        $this->assertSame($payment->id, $statement->lines()->first()->sales_invoice_payment_id);
    }

    public function test_a_statement_with_a_later_booked_statement_cannot_be_reopened(): void
    {
        $first = $this->statement('0.00', '500.00', ['number' => 1]);
        $this->line($first, LineDirection::IN, '500.00', ['account_id' => $this->account('7400')->id]);
        $this->poster()->post($first, $this->user->id);
        $second = $this->statement('500.00', '800.00', ['number' => 2, 'statement_date' => '2026-03-06']);
        $this->line($second, LineDirection::IN, '300.00', ['account_id' => $this->account('7400')->id, 'line_date' => '2026-03-06']);
        $this->poster()->post($second, $this->user->id);

        $this->expectException(InvalidBankStatementException::class);

        $this->poster()->reopen($first->fresh());
    }

    public function test_two_lines_on_one_invoice_that_together_exceed_it_are_refused_and_nothing_is_posted(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = $this->confirmedSalesInvoice($partner, '300.00');
        $statement = $this->statement('0.00', '400.00');
        $this->line($statement, LineDirection::IN, '200.00', ['kind' => LineKind::INVOICE_PAYMENT, 'sales_invoice_id' => $invoice->id, 'position' => 1]);
        $this->line($statement, LineDirection::IN, '200.00', ['kind' => LineKind::INVOICE_PAYMENT, 'sales_invoice_id' => $invoice->id, 'position' => 2]);
        $entriesBefore = JournalEntry::count();

        try {
            $this->poster()->post($statement, $this->user->id);
            $this->fail('Очекувано е исклучок за надминато салдо.');
        } catch (InvalidBankStatementException $e) {
            $this->assertStringContainsString('заедно ја надминуваат фактурата', $e->getMessage());
        }

        $this->assertFalse($statement->fresh()->isBooked());
        $this->assertSame($entriesBefore, JournalEntry::count());
        $this->assertSame(0, $invoice->payments()->count());
    }

    public function test_each_bank_account_posts_into_its_own_group(): void
    {
        $a = $this->statement('0.00', '100.00', ['account' => '300000000000001']);
        $this->line($a, LineDirection::IN, '100.00', ['account_id' => $this->account('7400')->id]);
        $b = $this->statement('0.00', '50.00', ['account' => '200000000000002', 'bank' => 'Стопанска']);
        $this->line($b, LineDirection::IN, '50.00', ['account_id' => $this->account('7400')->id]);

        $entryA = $this->poster()->post($a, $this->user->id)->load('journalGroup');
        $entryB = $this->poster()->post($b, $this->user->id)->load('journalGroup');

        $this->assertSame('10', $entryA->journalGroup->code);
        $this->assertSame('11', $entryB->journalGroup->code);
        $this->assertSame('10-0001', $entryA->displayNumber());
        $this->assertSame('11-0001', $entryB->displayNumber());
    }
}
