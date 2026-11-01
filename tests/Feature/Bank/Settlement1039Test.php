<?php

namespace Tests\Feature\Bank;

use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Bank\Settlement1039;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use App\Support\Bank\StatementControls;
use App\Support\BankStatementKind;
use App\Support\JournalGroupCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Settlement1039Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create(['type' => 'legal']);
    }

    private function account(string $code, ?Company $company = null): Account
    {
        return Account::where('company_id', ($company ?? $this->company)->id)->where('code', $code)->firstOrFail();
    }

    /** Прокнижена конверзија: 1039 Должи (денари + девизен износ). */
    private function conversion(string $mkd, string $foreign, string $currency = 'EUR', ?Company $company = null): void
    {
        $company ??= $this->company;
        $entry = JournalEntry::create([
            'company_id' => $company->id,
            'journal_group_id' => JournalGroupCodes::group($company, JournalGroupCodes::FOREIGN_BANK)->id,
            'entry_date' => '2026-10-20', 'description' => 'Конверзија', 'created_by' => User::factory()->create()->id,
        ]);
        $entry->lines()->create([
            'account_id' => $this->account('1039', $company)->id, 'debit' => $mkd, 'credit' => '0', 'line_date' => '2026-10-20',
            'currency_code' => $currency, 'foreign_amount' => $foreign, 'exchange_rate' => '61.5',
        ]);
        $entry->lines()->create(['account_id' => $this->account('1030', $company)->id, 'debit' => '0', 'credit' => $mkd, 'line_date' => '2026-10-20']);
    }

    private function statement(array $lineAttrs, array $statementAttrs = []): array
    {
        $statement = BankStatement::factory()->for($this->company)->create(array_merge([
            'kind' => BankStatementKind::DENAR, 'statement_date' => '2026-10-25', 'number' => 1,
        ], $statementAttrs));
        $line = BankStatementLine::factory()->for($statement)->create(array_merge([
            'line_date' => '2026-10-25', 'direction' => LineDirection::IN, 'kind' => LineKind::ACCOUNT,
            'account_id' => $this->account('1039')->id, 'amount' => '424338.96', 'foreign_amount' => '6900.00', 'currency' => 'EUR',
        ], $lineAttrs));

        return [$statement->fresh(), $line];
    }

    public function test_a_loss_is_proposed_when_the_bank_pays_fewer_denars_than_1039_holds(): void
    {
        $this->conversion('424368.63', '6900.00');
        [$statement, $line] = $this->statement([]);

        $this->assertSame([$line->id => ['side' => 'loss', 'amount' => '29.67']], Settlement1039::proposals($statement));
    }

    public function test_a_gain_is_proposed_when_the_bank_pays_more(): void
    {
        $this->conversion('424368.63', '6900.00');
        [$statement, $line] = $this->statement(['amount' => '424400.00']);

        $this->assertSame([$line->id => ['side' => 'gain', 'amount' => '31.37']], Settlement1039::proposals($statement));
    }

    public function test_no_proposal_when_the_amounts_match(): void
    {
        $this->conversion('424368.63', '6900.00');
        [$statement] = $this->statement(['amount' => '424368.63']);

        $this->assertSame([], Settlement1039::proposals($statement));
    }

    public function test_no_proposal_while_foreign_balance_remains(): void
    {
        $this->conversion('424368.63', '6900.00');
        [$statement] = $this->statement(['amount' => '184500.00', 'foreign_amount' => '3000.00']);

        $this->assertSame([], Settlement1039::proposals($statement));
    }

    public function test_a_second_conversion_is_not_mistaken_for_a_difference(): void
    {
        $this->conversion('184500.00', '3000.00');
        $this->conversion('239868.63', '3900.00');
        [$statement] = $this->statement(['amount' => '184500.00', 'foreign_amount' => '3000.00']);

        $this->assertSame([], Settlement1039::proposals($statement));
    }

    public function test_other_company_balances_do_not_count(): void
    {
        $other = Company::factory()->create(['type' => 'legal']);
        $this->conversion('424368.63', '6900.00', 'EUR', $other);
        [$statement] = $this->statement([]);

        $this->assertSame([], Settlement1039::proposals($statement));
    }

    public function test_other_currency_foreign_balance_does_not_close_the_line(): void
    {
        $this->conversion('424368.63', '6900.00', 'USD');
        [$statement] = $this->statement([]);

        $this->assertSame([], Settlement1039::proposals($statement));
    }

    public function test_only_inflows_on_1039_with_a_foreign_amount_count(): void
    {
        $this->conversion('424368.63', '6900.00');
        [$out] = $this->statement(['direction' => LineDirection::OUT]);
        [$plain] = $this->statement(['foreign_amount' => null, 'currency' => null], ['number' => 2]);
        [$elsewhere] = $this->statement(['account_id' => $this->account('7400')->id], ['number' => 3]);

        $this->assertSame([], Settlement1039::proposals($out));
        $this->assertSame([], Settlement1039::proposals($plain));
        $this->assertSame([], Settlement1039::proposals($elsewhere));
    }

    public function test_a_booked_statement_has_no_proposals(): void
    {
        $this->conversion('424368.63', '6900.00');
        [$statement] = $this->statement([], ['status' => BankStatement::STATUS_BOOKED]);

        $this->assertSame([], Settlement1039::proposals($statement));
    }

    public function test_a_foreign_amount_on_1039_needs_a_valid_currency(): void
    {
        [$statement] = $this->statement(['currency' => null], ['opening_balance' => '0', 'closing_balance' => '424338.96']);

        $this->assertStringContainsString('девизен износ', implode(' ', StatementControls::problems($statement)));
    }
}
