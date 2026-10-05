<?php

namespace Tests\Feature\Bank;

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankStatementLineTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_statement_is_a_draft_with_no_balances(): void
    {
        $statement = BankStatement::factory()->create();

        $this->assertSame(BankStatement::STATUS_DRAFT, $statement->status);
        $this->assertFalse($statement->isBooked());

        $statement = $statement->fresh();
        $this->assertNull($statement->opening_balance);
        $this->assertNull($statement->closing_balance);
        $this->assertNull($statement->journal_entry_id);
    }

    public function test_the_movement_is_inflows_minus_outflows(): void
    {
        $statement = BankStatement::factory()->create();
        BankStatementLine::factory()->for($statement)->create(['direction' => LineDirection::IN, 'amount' => '1000.50', 'position' => 1]);
        BankStatementLine::factory()->for($statement)->create(['direction' => LineDirection::OUT, 'amount' => '300.25', 'position' => 2]);

        $this->assertSame('700.25', $statement->fresh()->movement());
        $this->assertSame(['1000.50', '-300.25'], $statement->fresh()->lines->map->signedAmount()->all());
    }

    public function test_an_empty_statement_has_a_zero_movement(): void
    {
        $this->assertSame('0.00', BankStatement::factory()->create()->movement());
    }

    public function test_a_line_defaults_to_not_having_created_a_payment(): void
    {
        $line = BankStatementLine::factory()->for(BankStatement::factory()->create())->create(['kind' => LineKind::UNCLEAR])->fresh();

        $this->assertFalse($line->created_payment);
        $this->assertSame('Неразјаснето', LineKind::UNCLEAR->label());
        $this->assertSame('Уплата', LineDirection::IN->label());
    }
}
