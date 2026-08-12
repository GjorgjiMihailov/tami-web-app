<?php

namespace Tests\Feature;

use App\Livewire\Accounting\TrialBalanceReport;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\WorkingYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TrialBalanceReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_it_shows_the_trial_balance_grouped_by_account(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $account = Account::where('company_id', $company->id)->where('code', '120')->first();
        $entry = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-10']);
        $entry->lines()->create(['account_id' => $account->id, 'debit' => 1000, 'credit' => 0]);

        $this->actingAs($admin);

        Livewire::test(TrialBalanceReport::class, ['company' => $company])
            ->set('from', '2026-01-01')
            ->set('to', '2026-01-31')
            ->assertSee('120')
            ->assertSee('1.000,00');
    }

    public function test_it_shows_a_totals_row_summing_all_columns(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $ar = Account::where('company_id', $company->id)->where('code', '120')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();
        $entry = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-10']);
        $entry->lines()->create(['account_id' => $ar->id, 'debit' => 1000, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $revenue->id, 'debit' => 0, 'credit' => 1000]);

        $this->actingAs($admin);

        Livewire::test(TrialBalanceReport::class, ['company' => $company])
            ->set('from', '2026-01-01')
            ->set('to', '2026-01-31')
            ->assertSee('Вкупно')
            ->assertSeeHtml('<td class="py-2 px-4 text-right">1.000,00</td>')
            ->assertSeeHtml('<td class="py-2 px-4 text-right">0,00</td>');
    }

    public function test_the_trial_balance_table_has_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $account = Account::where('company_id', $company->id)->where('code', '120')->first();
        $entry = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-10']);
        $entry->lines()->create(['account_id' => $account->id, 'debit' => 1000, 'credit' => 0]);

        $this->actingAs($admin);

        Livewire::test(TrialBalanceReport::class, ['company' => $company])
            ->set('from', '2026-01-01')
            ->set('to', '2026-01-31')
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
    }

    public function test_the_totals_row_deliberately_has_no_hover_treatment(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $account = Account::where('company_id', $company->id)->where('code', '120')->first();
        $entry = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-10']);
        $entry->lines()->create(['account_id' => $account->id, 'debit' => 1000, 'credit' => 0]);

        $this->actingAs($admin);

        // deliberately no row-hover: the tfoot totals row is a summary row, not an interactive data row
        Livewire::test(TrialBalanceReport::class, ['company' => $company])
            ->set('from', '2026-01-01')
            ->set('to', '2026-01-31')
            ->assertSeeHtml('<tr class="text-sm font-bold border-t border-gray-300 bg-gray-50">');
    }

    public function test_a_past_working_year_opens_on_that_whole_year(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);
        WorkingYear::set($company, 2024);

        Livewire::test(TrialBalanceReport::class, ['company' => $company])
            ->assertSet('from', '2024-01-01')
            ->assertSet('to', '2024-12-31');
    }
}
