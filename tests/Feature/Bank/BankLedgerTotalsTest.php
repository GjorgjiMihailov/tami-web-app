<?php

namespace Tests\Feature\Bank;

use App\Models\Account;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\User;
use App\Support\Bank\BankLedgerTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankLedgerTotalsTest extends TestCase
{
    use RefreshDatabase;

    private function post1000(Company $company, string $debit, string $credit, string $date = '2026-03-05'): void
    {
        $group = JournalGroup::firstOrCreate(['company_id' => $company->id, 'code' => '00'], ['name' => 'Почетна', 'sort_order' => 0]);
        $entry = JournalEntry::create([
            'company_id' => $company->id,
            'journal_group_id' => $group->id,
            'entry_date' => $date,
            'description' => 't',
            'created_by' => User::factory()->create()->id,
        ]);
        $entry->lines()->create([
            'account_id' => Account::where('company_id', $company->id)->where('code', '1000')->value('id'),
            'line_date' => $date, 'debit' => $debit, 'credit' => $credit,
        ]);
    }

    public function test_ledger_matches_the_sum_of_the_latest_closings_per_account(): void
    {
        $company = Company::factory()->create();
        $this->post1000($company, '1000.00', '0');
        $this->post1000($company, '500.00', '0');
        BankStatement::factory()->for($company)->create(['number' => 1, 'closing_balance' => '1200.00', 'opening_balance' => '1000.00', 'status' => 'booked']);
        BankStatement::factory()->for($company)->create(['number' => 2, 'closing_balance' => '1500.00', 'opening_balance' => '1200.00', 'status' => 'booked', 'statement_date' => '2026-03-06']);

        $totals = BankLedgerTotals::forCompany($company, 2026);

        $this->assertSame('1500.00', $totals['ledger']);
        $this->assertSame('1500.00', $totals['closings']);
        $this->assertSame('0.00', $totals['difference']);
    }

    public function test_two_accounts_are_summed(): void
    {
        $company = Company::factory()->create();
        $this->post1000($company, '1000.00', '0');
        BankStatement::factory()->for($company)->create(['account' => 'A', 'closing_balance' => '600.00', 'status' => 'booked']);
        BankStatement::factory()->for($company)->create(['account' => 'B', 'closing_balance' => '400.00', 'status' => 'booked']);

        $this->assertSame('0.00', BankLedgerTotals::forCompany($company, 2026)['difference']);
    }

    public function test_a_gap_shows_as_a_difference(): void
    {
        $company = Company::factory()->create();
        $this->post1000($company, '1000.00', '0');
        BankStatement::factory()->for($company)->create(['closing_balance' => '1700.00', 'opening_balance' => '1000.00', 'status' => 'booked']);

        $this->assertSame('-700.00', BankLedgerTotals::forCompany($company, 2026)['difference']);
    }

    public function test_drafts_foreign_statements_and_other_years_are_ignored(): void
    {
        $company = Company::factory()->create();
        $this->post1000($company, '300.00', '0', '2025-12-30');
        BankStatement::factory()->for($company)->create(['closing_balance' => '999.00', 'status' => 'draft']);
        BankStatement::factory()->foreign()->for($company)->create(['closing_balance' => '888.00', 'status' => 'booked']);

        $totals = BankLedgerTotals::forCompany($company, 2026);

        $this->assertSame('0.00', $totals['ledger']);
        $this->assertSame('0.00', $totals['closings']);
    }
}
