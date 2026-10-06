<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\User;
use App\Services\Accounting\CashFlowQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashFlowQueryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
    }

    /** Еден ред на парична сметка (и спротивен на 7400, за да е налогот избалансиран). */
    private function cash(string $code, string $debit, string $credit, string $date, string $group = '10'): void
    {
        $journalGroup = JournalGroup::firstOrCreate(['company_id' => $this->company->id, 'code' => $group], ['name' => 'G', 'sort_order' => 1]);
        $entry = JournalEntry::create([
            'company_id' => $this->company->id,
            'journal_group_id' => $journalGroup->id,
            'entry_date' => $date,
            'description' => 't',
            'created_by' => User::factory()->create()->id,
        ]);
        $cashId = Account::where('company_id', $this->company->id)->where('code', $code)->value('id');
        $otherId = Account::where('company_id', $this->company->id)->where('code', '7400')->value('id');
        $entry->lines()->create(['account_id' => $cashId, 'line_date' => $date, 'debit' => $debit, 'credit' => $credit]);
        $entry->lines()->create(['account_id' => $otherId, 'line_date' => $date, 'debit' => $credit, 'credit' => $debit]);
    }

    public function test_monthly_inflows_and_outflows_for_the_year(): void
    {
        $this->cash('1000', '500.00', '0', '2026-01-10');
        $this->cash('1000', '0', '200.00', '2026-01-20');
        $this->cash('1020', '50.00', '0', '2026-03-05');

        $flow = CashFlowQuery::forYear($this->company, 2026);

        $this->assertSame(['in' => '500.00', 'out' => '200.00'], $flow['months'][1]);
        $this->assertSame(['in' => '0.00', 'out' => '0.00'], $flow['months'][2]);
        $this->assertSame(['in' => '50.00', 'out' => '0.00'], $flow['months'][3]);
        $this->assertCount(12, $flow['months']);
        $this->assertSame('0.00', $flow['opening']);
        $this->assertSame('350.00', $flow['closing']);
    }

    public function test_the_opening_is_what_was_on_the_accounts_before_the_year(): void
    {
        $this->cash('1000', '1000.00', '0', '2025-06-01');
        $this->cash('1000', '0', '300.00', '2025-12-31');
        $this->cash('1000', '100.00', '0', '2026-02-01');

        $flow = CashFlowQuery::forYear($this->company, 2026);

        $this->assertSame('700.00', $flow['opening']);
        $this->assertSame('800.00', $flow['closing']);
    }

    public function test_the_opening_balance_entry_of_group_00_is_an_opening_not_an_inflow(): void
    {
        $this->cash('1000', '2000.00', '0', '2026-01-01', '00');
        $this->cash('1000', '100.00', '0', '2026-01-15');

        $flow = CashFlowQuery::forYear($this->company, 2026);

        $this->assertSame('2000.00', $flow['opening']);
        $this->assertSame(['in' => '100.00', 'out' => '0.00'], $flow['months'][1]);
        $this->assertSame('2100.00', $flow['closing']);
    }

    public function test_only_cash_accounts_count_and_only_this_company(): void
    {
        $this->cash('1000', '100.00', '0', '2026-04-01');
        $other = Company::factory()->create();
        $group = JournalGroup::create(['company_id' => $other->id, 'code' => '10', 'name' => 'G', 'sort_order' => 1]);
        $entry = JournalEntry::create(['company_id' => $other->id, 'journal_group_id' => $group->id, 'entry_date' => '2026-04-01', 'description' => 't', 'created_by' => User::factory()->create()->id]);
        $entry->lines()->create(['account_id' => Account::where('company_id', $other->id)->where('code', '1000')->value('id'), 'line_date' => '2026-04-01', 'debit' => '999.00', 'credit' => '0']);

        $flow = CashFlowQuery::forYear($this->company, 2026);

        // 7400 на спротивната страна НЕ е паричен конто и не смее да се брои.
        $this->assertSame(['in' => '100.00', 'out' => '0.00'], $flow['months'][4]);
        $this->assertSame('100.00', $flow['closing']);
    }

    public function test_the_last_day_of_the_year_and_the_first_day_of_the_next_are_split_correctly(): void
    {
        $this->cash('1000', '100.00', '0', '2026-12-31');
        $this->cash('1000', '40.00', '0', '2027-01-01');

        $flow = CashFlowQuery::forYear($this->company, 2026);
        $next = CashFlowQuery::forYear($this->company, 2027);

        $this->assertSame(['in' => '100.00', 'out' => '0.00'], $flow['months'][12]);
        $this->assertSame('100.00', $flow['closing']);
        $this->assertSame('100.00', $next['opening']);
        $this->assertSame('140.00', $next['closing']);
    }

    public function test_a_company_without_entries_is_all_zeros(): void
    {
        $flow = CashFlowQuery::forYear($this->company, 2026);

        $this->assertSame('0.00', $flow['opening']);
        $this->assertSame('0.00', $flow['closing']);
        $this->assertSame(['in' => '0.00', 'out' => '0.00'], $flow['months'][12]);
    }
}
