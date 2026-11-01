<?php

namespace Tests\Feature\Bank;

use App\Exceptions\InvalidBankStatementException;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use App\Services\Bank\BankStatementPoster;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use App\Support\BankStatementKind;
use App\Support\JournalGroupCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankStatementSettlementPostingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create(['type' => 'legal']);
        $this->user = User::factory()->create();
        $entry = JournalEntry::create([
            'company_id' => $this->company->id,
            'journal_group_id' => JournalGroupCodes::group($this->company, JournalGroupCodes::FOREIGN_BANK)->id,
            'entry_date' => '2026-10-20', 'description' => 'Конверзија', 'created_by' => $this->user->id,
        ]);
        $entry->lines()->create([
            'account_id' => $this->account('1039')->id, 'debit' => '424368.63', 'credit' => '0', 'line_date' => '2026-10-20',
            'currency_code' => 'EUR', 'foreign_amount' => '6900.00', 'exchange_rate' => '61.5027',
        ]);
        $entry->lines()->create(['account_id' => $this->account('1030')->id, 'debit' => '0', 'credit' => '424368.63', 'line_date' => '2026-10-20']);
    }

    private function account(string $code): Account
    {
        return Account::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function statement(string $amount, bool $settle, array $lineAttrs = []): BankStatement
    {
        $statement = BankStatement::factory()->for($this->company)->create([
            'kind' => BankStatementKind::DENAR, 'statement_date' => '2026-10-25', 'number' => 1,
            'opening_balance' => '0.00', 'closing_balance' => $amount,
        ]);
        BankStatementLine::factory()->for($statement)->create(array_merge([
            'line_date' => '2026-10-25', 'direction' => LineDirection::IN, 'kind' => LineKind::ACCOUNT,
            'account_id' => $this->account('1039')->id, 'amount' => $amount, 'foreign_amount' => '6900.00', 'currency' => 'EUR',
            'settle_difference' => $settle,
        ], $lineAttrs));

        return $statement;
    }

    private function book(BankStatement $statement): JournalEntry
    {
        return app(BankStatementPoster::class)->post($statement->fresh(), $this->user->id);
    }

    private function balance(string $code): string
    {
        return JournalEntryLine::whereHas('account', fn ($q) => $q->where('company_id', $this->company->id)->where('code', $code))
            ->get()->reduce(fn (string $c, $l) => bcsub(bcadd($c, (string) $l->debit, 2), (string) $l->credit, 2), '0.00');
    }

    public function test_the_1039_line_carries_the_foreign_data(): void
    {
        $entry = $this->book($this->statement('424338.96', false));

        $line = $entry->lines()->whereHas('account', fn ($q) => $q->where('code', '1039'))->firstOrFail();
        $this->assertSame('EUR', $line->currency_code);
        $this->assertSame('6900.00', (string) $line->foreign_amount);
        $this->assertSame('61.498400', (string) $line->exchange_rate);
        $this->assertSame('424338.96', (string) $line->credit);
    }

    public function test_accepting_the_proposal_posts_4750_against_1039_and_the_entry_balances(): void
    {
        $entry = $this->book($this->statement('424338.96', true));

        $this->assertSame('0.00', $this->balance('1039'));
        $this->assertSame('29.67', $this->balance('4750'));
        $this->assertSame('424338.96', $this->balance('1000'));
        $this->assertSame((string) $entry->lines()->sum('debit'), (string) $entry->lines()->sum('credit'));
    }

    public function test_not_accepting_leaves_the_difference_on_1039(): void
    {
        $this->book($this->statement('424338.96', false));

        $this->assertSame('29.67', $this->balance('1039'));
        $this->assertSame('0.00', $this->balance('4750'));
    }

    public function test_the_gain_posts_7750(): void
    {
        $this->book($this->statement('424400.00', true));

        $this->assertSame('0.00', $this->balance('1039'));
        $this->assertSame('-31.37', $this->balance('7750'));
    }

    public function test_accepting_with_nothing_to_settle_posts_nothing_extra(): void
    {
        $entry = $this->book($this->statement('424368.63', true));

        $this->assertSame(2, $entry->lines()->count());
        $this->assertSame('0.00', $this->balance('1039'));
    }

    public function test_accepting_without_4750_is_refused_and_leaves_nothing(): void
    {
        Account::where('company_id', $this->company->id)->where('code', '4750')->delete();
        $statement = $this->statement('424338.96', true);
        $entries = JournalEntry::count();

        try {
            $this->book($statement);
            $this->fail('очекуван исклучок');
        } catch (InvalidBankStatementException $e) {
            $this->assertStringContainsString('4750', $e->getMessage());
        }

        $this->assertSame($entries, JournalEntry::count());
        $this->assertFalse($statement->fresh()->isBooked());
    }
}
