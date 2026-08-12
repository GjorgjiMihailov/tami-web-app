<?php

namespace Tests\Feature;

use App\Livewire\Accounting\JournalEntryIndex;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\WorkingYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JournalEntryIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_lists_the_companys_journal_entries(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $entry = JournalEntry::factory()->for($company)->create(['description' => 'Opening balances']);

        $this->actingAs($admin);

        Livewire::test(JournalEntryIndex::class, ['company' => $company])
            ->assertSee('Opening balances')
            ->assertSee($entry->displayNumber());
    }

    public function test_client_can_view_the_list_but_sees_no_new_entry_link(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        JournalEntry::factory()->for($company)->create(['description' => 'Opening balances']);

        $this->actingAs($client);

        Livewire::test(JournalEntryIndex::class, ['company' => $company])
            ->assertSee('Opening balances')
            ->assertDontSee('Нов налог');
    }

    public function test_it_only_shows_entries_belonging_to_the_current_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        JournalEntry::factory()->for($companyA)->create(['description' => 'Company A opening balances']);
        JournalEntry::factory()->for($companyB)->create(['description' => 'Company B opening balances']);

        $this->actingAs($admin);

        Livewire::test(JournalEntryIndex::class, ['company' => $companyA])
            ->assertSee('Company A opening balances')
            ->assertDontSee('Company B opening balances');
    }

    public function test_the_journal_entry_table_has_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        JournalEntry::factory()->for($company)->create(['description' => 'Test Entry']);

        $this->actingAs($admin);

        Livewire::test(JournalEntryIndex::class, ['company' => $company])
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
    }

    public function test_it_only_lists_entries_from_the_working_year(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        JournalEntry::factory()->for($company)->create(['entry_date' => now()->toDateString(), 'description' => 'Entry this year']);
        JournalEntry::factory()->for($company)->create(['entry_date' => '2024-04-04', 'description' => 'Entry in 2024']);

        $this->actingAs($admin);

        Livewire::test(JournalEntryIndex::class, ['company' => $company])
            ->assertSee('Entry this year')
            ->assertDontSee('Entry in 2024');
    }

    public function test_changing_the_working_year_reloads_the_list(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        JournalEntry::factory()->for($company)->create(['entry_date' => now()->toDateString(), 'description' => 'Entry this year']);
        JournalEntry::factory()->for($company)->create(['entry_date' => '2024-04-04', 'description' => 'Entry in 2024']);

        $this->actingAs($admin);

        Livewire::test(JournalEntryIndex::class, ['company' => $company])
            ->dispatch('working-year-changed', year: 2024)
            ->assertSee('Entry in 2024')
            ->assertDontSee('Entry this year');
    }

    public function test_an_empty_year_says_so_instead_of_saying_there_is_no_data(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        JournalEntry::factory()->for($company)->create(['entry_date' => '2024-04-04', 'description' => 'Entry in 2024']);

        $this->actingAs($admin);

        Livewire::test(JournalEntryIndex::class, ['company' => $company])
            ->assertSee('Нема записи за '.now()->year.' — провери дали работиш во вистинската година');
    }

    public function test_the_working_year_comes_from_the_session(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        JournalEntry::factory()->for($company)->create(['entry_date' => '2024-04-04', 'description' => 'Entry in 2024']);

        $this->actingAs($admin);
        WorkingYear::set($company, 2024);

        Livewire::test(JournalEntryIndex::class, ['company' => $company])
            ->assertSet('workingYear', 2024)
            ->assertSee('Entry in 2024');
    }
}
