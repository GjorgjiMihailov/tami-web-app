<?php

namespace Tests\Feature\Bank;

use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use App\Support\Bank\MatchConfidence;
use App\Support\Bank\StatementControls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LineReviewControlTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
    }

    /** @param  array<string, mixed>  $line */
    private function statementWith(array $line): BankStatement
    {
        $account = Account::where('company_id', $this->company->id)->where('code', '7400')->firstOrFail();

        $statement = BankStatement::factory()->for($this->company)->create([
            'statement_date' => '2026-03-05', 'opening_balance' => '0.00', 'closing_balance' => '10.00',
        ]);

        BankStatementLine::factory()->for($statement)->create(array_merge([
            'line_date' => '2026-03-05',
            'direction' => LineDirection::IN,
            'amount' => '10.00',
            'kind' => LineKind::ACCOUNT,
            'account_id' => $account->id,
        ], $line));

        return $statement->fresh();
    }

    public function test_an_unreviewed_unclear_line_blocks_posting_even_with_an_account(): void
    {
        $statement = $this->statementWith(['match_confidence' => MatchConfidence::UNCLEAR]);

        $problems = StatementControls::problems($statement);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('чека проверка', $problems[0]);
    }

    public function test_an_unreviewed_check_line_blocks_posting(): void
    {
        $statement = $this->statementWith(['match_confidence' => MatchConfidence::CHECK]);

        $this->assertStringContainsString('чека проверка', implode(' ', StatementControls::problems($statement)));
    }

    public function test_a_reviewed_line_no_longer_blocks(): void
    {
        $statement = $this->statementWith(['match_confidence' => MatchConfidence::UNCLEAR, 'reviewed_at' => now()]);

        $this->assertSame([], StatementControls::problems($statement));
    }

    public function test_a_sure_line_needs_no_review(): void
    {
        $statement = $this->statementWith(['match_confidence' => MatchConfidence::SURE]);

        $this->assertSame([], StatementControls::problems($statement));
    }

    public function test_a_hand_entered_line_without_a_mark_is_not_reviewed_at_all(): void
    {
        $statement = $this->statementWith([]);

        $this->assertSame([], StatementControls::problems($statement));
    }
}
