<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Services\Accounting\TrialBalanceQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TrialBalanceQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_computes_opening_movement_and_closing_by_account(): void
    {
        $company = Company::factory()->create();
        $account = Account::where('company_id', $company->id)->where('code', '120')->first();

        $before = JournalEntry::factory()->for($company)->create(['entry_date' => '2025-12-15']);
        $before->lines()->create(['account_id' => $account->id, 'debit' => 1000, 'credit' => 0]);

        $inRange = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-10']);
        $inRange->lines()->create(['account_id' => $account->id, 'debit' => 500, 'credit' => 200]);

        $rows = TrialBalanceQuery::run($company, 'account', Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $row = $rows->firstWhere('key', '120');

        $this->assertSame(1000.0, $row['opening_balance']);
        $this->assertSame(500.0, $row['movement_debit']);
        $this->assertSame(200.0, $row['movement_credit']);
        $this->assertSame(1300.0, $row['closing_balance']);
    }

    public function test_synthetic_grouping_collapses_analytical_accounts(): void
    {
        $company = Company::factory()->create();
        $synthetic = Account::where('company_id', $company->id)->where('code', '234')->first();
        $analytical1 = Account::create(['company_id' => $company->id, 'code' => '2341', 'name' => 'Pension', 'parent_code' => '234', 'is_analytical' => true, 'is_active' => true]);
        $analytical2 = Account::create(['company_id' => $company->id, 'code' => '2342', 'name' => 'Health', 'parent_code' => '234', 'is_analytical' => true, 'is_active' => true]);

        $entry = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-10']);
        $entry->lines()->create(['account_id' => $analytical1->id, 'debit' => 0, 'credit' => 300]);
        $entry->lines()->create(['account_id' => $analytical2->id, 'debit' => 0, 'credit' => 150]);

        $rows = TrialBalanceQuery::run($company, 'synthetic', Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $row = $rows->firstWhere('key', '234');
        $this->assertSame(450.0, $row['movement_credit']);
        $this->assertSame($synthetic->name, $row['label']);
    }

    public function test_account_partner_grouping_combines_both_dimensions(): void
    {
        $company = Company::factory()->create();
        $account = Account::where('company_id', $company->id)->where('code', '120')->first();
        $partnerA = Partner::factory()->for($company)->create(['name' => 'АКАУНТ СОЛУШН ДООЕЛ']);
        $partnerB = Partner::factory()->for($company)->create(['name' => 'ХЕТА ЛИЗИНГ ДОО']);

        $entry = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-10']);
        $entry->lines()->create(['account_id' => $account->id, 'partner_id' => $partnerA->id, 'debit' => 6000, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $account->id, 'partner_id' => $partnerB->id, 'debit' => 10000, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $account->id, 'partner_id' => null, 'debit' => 500, 'credit' => 0]);

        $rows = TrialBalanceQuery::run($company, 'account_partner', Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertCount(2, $rows);
        $rowA = $rows->firstWhere('label', 'АКАУНТ СОЛУШН ДООЕЛ');
        $this->assertSame(6000.0, $rowA['movement_debit']);
        $rowB = $rows->firstWhere('label', 'ХЕТА ЛИЗИНГ ДОО');
        $this->assertSame(10000.0, $rowB['movement_debit']);
    }

    public function test_partner_grouping_shows_correct_labels_even_for_opening_only_partners(): void
    {
        $company = Company::factory()->create();
        $account = Account::where('company_id', $company->id)->where('code', '120')->first();
        $openingOnlyPartner = Partner::factory()->for($company)->create(['name' => 'Опенинг Само ДОО']);
        $activePartner = Partner::factory()->for($company)->create(['name' => 'Активен Партнер ДОО']);

        // Opening-only partner: a line dated BEFORE the report range, nothing within it.
        $priorEntry = JournalEntry::factory()->for($company)->create(['entry_date' => '2025-12-15']);
        $priorEntry->lines()->create(['account_id' => $account->id, 'partner_id' => $openingOnlyPartner->id, 'debit' => 1000, 'credit' => 0]);

        // Active partner: a line dated WITHIN the report range, so movementLines overall is non-empty.
        $inRangeEntry = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-10']);
        $inRangeEntry->lines()->create(['account_id' => $account->id, 'partner_id' => $activePartner->id, 'debit' => 500, 'credit' => 0]);

        $rows = TrialBalanceQuery::run($company, 'partner', Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $openingRow = $rows->firstWhere('key', (string) $openingOnlyPartner->id);
        $this->assertSame('Опенинг Само ДОО', $openingRow['label']);
        $this->assertSame(1000.0, $openingRow['opening_balance']);
    }
}
