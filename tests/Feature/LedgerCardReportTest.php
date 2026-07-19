<?php

namespace Tests\Feature;

use App\Livewire\Accounting\LedgerCardReport;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LedgerCardReportTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_it_shows_lines_once_an_account_is_selected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $account = Account::where('company_id', $company->id)->where('code', '120')->first();
        $entry = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-10']);
        $entry->lines()->create(['account_id' => $account->id, 'debit' => 6000, 'credit' => 0, 'description' => 'Фактура 01/26']);

        $this->actingAs($admin);

        Livewire::test(LedgerCardReport::class, ['company' => $company])
            ->set('accountId', $account->id)
            ->set('from', '2026-01-01')
            ->set('to', '2026-01-31')
            ->assertSee('Фактура 01/26')
            ->assertSee('6000');
    }
}
