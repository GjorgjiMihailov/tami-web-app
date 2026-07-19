<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Services\Accounting\LedgerCardQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LedgerCardQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_lines_for_an_account_with_a_running_balance(): void
    {
        $company = Company::factory()->create();
        $account = Account::where('company_id', $company->id)->where('code', '120')->first();
        $partner = Partner::factory()->for($company)->create(['name' => 'АКАУНТ СОЛУШН ДООЕЛ']);

        $entry1 = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-10']);
        $entry1->lines()->create(['account_id' => $account->id, 'partner_id' => $partner->id, 'debit' => 6000, 'credit' => 0, 'description' => 'Фактура 01/26']);

        $entry2 = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-20']);
        $entry2->lines()->create(['account_id' => $account->id, 'partner_id' => $partner->id, 'debit' => 0, 'credit' => 2000, 'description' => 'Извод 5']);

        $rows = LedgerCardQuery::run($company, [
            'account_id' => $account->id,
            'partner_id' => null,
            'from' => Carbon::parse('2026-01-01'),
            'to' => Carbon::parse('2026-01-31'),
        ]);

        $this->assertCount(2, $rows);
        $this->assertSame(6000.0, (float) $rows[0]['debit']);
        $this->assertSame(6000.0, (float) $rows[0]['balance']);
        $this->assertSame(2000.0, (float) $rows[1]['credit']);
        $this->assertSame(4000.0, (float) $rows[1]['balance']);
    }

    public function test_opening_balance_before_the_date_range_carries_into_the_running_balance(): void
    {
        $company = Company::factory()->create();
        $account = Account::where('company_id', $company->id)->where('code', '120')->first();

        $before = JournalEntry::factory()->for($company)->create(['entry_date' => '2025-12-15']);
        $before->lines()->create(['account_id' => $account->id, 'debit' => 1000, 'credit' => 0]);

        $inRange = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-10']);
        $inRange->lines()->create(['account_id' => $account->id, 'debit' => 500, 'credit' => 0]);

        $rows = LedgerCardQuery::run($company, [
            'account_id' => $account->id,
            'partner_id' => null,
            'from' => Carbon::parse('2026-01-01'),
            'to' => Carbon::parse('2026-01-31'),
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(1500.0, (float) $rows[0]['balance']);
    }

    public function test_it_filters_by_account_and_partner_together(): void
    {
        $company = Company::factory()->create();
        $account = Account::where('company_id', $company->id)->where('code', '120')->first();
        $partnerA = Partner::factory()->for($company)->create();
        $partnerB = Partner::factory()->for($company)->create();

        $entry = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-10']);
        $entry->lines()->create(['account_id' => $account->id, 'partner_id' => $partnerA->id, 'debit' => 1000, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $account->id, 'partner_id' => $partnerB->id, 'debit' => 2000, 'credit' => 0]);

        $rows = LedgerCardQuery::run($company, [
            'account_id' => $account->id,
            'partner_id' => $partnerA->id,
            'from' => Carbon::parse('2026-01-01'),
            'to' => Carbon::parse('2026-01-31'),
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(1000.0, (float) $rows[0]['debit']);
    }
}
