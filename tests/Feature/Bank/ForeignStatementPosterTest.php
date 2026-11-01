<?php

namespace Tests\Feature\Bank;

use App\Exceptions\InvalidBankStatementException;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Bank\BankStatementPoster;
use App\Services\Invoicing\SalesInvoiceService;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use App\Support\BankStatementKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForeignStatementPosterTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create(['type' => 'legal', 'is_vat_registered' => false]);
        $this->partner = Partner::factory()->for($this->company)->create();
        $this->user = User::factory()->create();
    }

    private function invoice(string $amount = '1000.00'): SalesInvoice
    {
        $invoice = SalesInvoice::factory()->for($this->company)->create([
            'partner_id' => $this->partner->id, 'invoice_date' => '2026-10-01', 'currency' => 'EUR', 'exchange_rate' => '61.500000',
        ]);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => $amount, 'vat_rate' => '0.00', 'vat_treatment' => 'export']);

        return app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->user->id);
    }

    private function statement(string $opening = '0.00', string $closing = '0.00'): BankStatement
    {
        return BankStatement::factory()->for($this->company)->create([
            'kind' => BankStatementKind::FOREIGN, 'currency' => 'EUR', 'opening_balance' => $opening,
            'closing_balance' => $closing, 'statement_date' => '2026-10-20', 'number' => 1,
        ]);
    }

    private function receipt(BankStatement $statement, SalesInvoice $invoice, string $amount, string $rate): BankStatementLine
    {
        return BankStatementLine::factory()->for($statement)->create([
            'line_date' => '2026-10-20', 'direction' => LineDirection::IN, 'amount' => $amount, 'exchange_rate' => $rate,
            'kind' => LineKind::INVOICE_PAYMENT, 'sales_invoice_id' => $invoice->id, 'partner_id' => $invoice->partner_id,
        ]);
    }

    private function sale(BankStatement $statement, string $amount, string $rate): BankStatementLine
    {
        return BankStatementLine::factory()->for($statement)->create([
            'line_date' => '2026-10-20', 'direction' => LineDirection::OUT, 'amount' => $amount, 'exchange_rate' => $rate,
            'kind' => LineKind::CURRENCY_SALE,
        ]);
    }

    private function book(BankStatement $statement): JournalEntry
    {
        return app(BankStatementPoster::class)->post($statement->fresh(), $this->user->id);
    }

    /** @return array<string, string> код → "debit/credit" */
    private function sums(JournalEntry $entry): array
    {
        $out = [];
        foreach ($entry->lines()->with('account')->get() as $line) {
            $key = $line->account->code;
            $debit = bcadd(explode('/', $out[$key] ?? '0/0')[0], (string) $line->debit, 2);
            $credit = bcadd(explode('/', $out[$key] ?? '0/0')[1], (string) $line->credit, 2);
            $out[$key] = "{$debit}/{$credit}";
        }

        return $out;
    }

    public function test_a_receipt_at_a_lower_rate_posts_1030_and_a_loss_on_4750(): void
    {
        $statement = $this->statement('0.00', '1000.00');
        $this->receipt($statement, $this->invoice(), '1000.00', '61.200000');

        $entry = $this->book($statement);

        $this->assertEquals(['1030' => '61200.00/0.00', '1211' => '0.00/61500.00', '4750' => '300.00/0.00'], $this->sums($entry));
        $this->assertSame('11', $entry->journalGroup->code);
        $this->assertSame(BankStatement::STATUS_BOOKED, $statement->fresh()->status);
    }

    public function test_a_receipt_at_a_higher_rate_posts_a_gain_on_7750(): void
    {
        $statement = $this->statement('0.00', '1000.00');
        $this->receipt($statement, $this->invoice(), '1000.00', '61.800000');

        $this->assertEquals(['1030' => '61800.00/0.00', '1211' => '0.00/61500.00', '7750' => '0.00/300.00'], $this->sums($this->book($statement)));
    }

    public function test_a_conversion_posts_1039_debit_and_1030_credit_at_the_same_rate(): void
    {
        $statement = $this->statement('1000.00', '0.00');
        $this->sale($statement, '1000.00', '61.500000');

        $entry = $this->book($statement);

        $this->assertEquals(['1030' => '0.00/61500.00', '1039' => '61500.00/0.00'], $this->sums($entry));
        $line = $entry->lines()->whereHas('account', fn ($q) => $q->where('code', '1039'))->firstOrFail();
        $this->assertSame('EUR', $line->currency_code);
        $this->assertSame('1000.00', (string) $line->foreign_amount);
        $this->assertSame('61.500000', (string) $line->exchange_rate);
    }

    public function test_a_receipt_creates_the_payment_and_reduces_the_invoice_balance(): void
    {
        $invoice = $this->invoice();
        $statement = $this->statement('0.00', '400.00');
        $line = $this->receipt($statement, $invoice, '400.00', '61.200000');

        $this->book($statement);

        $this->assertSame('600.00', $invoice->fresh()->balanceDue());
        $this->assertTrue($line->fresh()->created_payment);
        $this->assertNotNull($line->fresh()->sales_invoice_payment_id);
    }

    public function test_one_statement_with_receipt_and_conversion_is_one_balanced_entry(): void
    {
        $statement = $this->statement('0.00', '0.00');
        $this->receipt($statement, $this->invoice(), '1000.00', '61.200000');
        $this->sale($statement, '1000.00', '61.300000');

        $entry = $this->book($statement);

        $this->assertSame(1, JournalEntry::where('journal_group_id', $entry->journal_group_id)->count());
        $this->assertSame(
            (string) $entry->lines()->sum('debit'),
            (string) $entry->lines()->sum('credit'),
        );
        $this->assertSame('61200.00/61300.00', $this->sums($entry)['1030']);
    }

    public function test_reopen_removes_the_entry_and_the_payment(): void
    {
        $invoice = $this->invoice();
        $statement = $this->statement('0.00', '1000.00');
        $this->receipt($statement, $invoice, '1000.00', '61.200000');
        $entry = $this->book($statement);

        app(BankStatementPoster::class)->reopen($statement->fresh());

        $this->assertNull(JournalEntry::find($entry->id));
        $this->assertSame('1000.00', $invoice->fresh()->balanceDue());
        $this->assertSame(BankStatement::STATUS_DRAFT, $statement->fresh()->status);
    }

    public function test_posting_without_1039_is_refused_and_leaves_nothing(): void
    {
        Account::where('company_id', $this->company->id)->where('code', '1039')->delete();
        $statement = $this->statement('1000.00', '0.00');
        $this->sale($statement, '1000.00', '61.500000');
        $entries = JournalEntry::count();

        try {
            $this->book($statement);
            $this->fail('очекуван исклучок');
        } catch (InvalidBankStatementException $e) {
            $this->assertStringContainsString('1039', $e->getMessage());
        }

        $this->assertSame($entries, JournalEntry::count());
        $this->assertFalse($statement->fresh()->isBooked());
    }
}
