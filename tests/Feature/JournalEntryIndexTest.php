<?php

namespace Tests\Feature;

use App\Livewire\Accounting\JournalEntryIndex;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JournalEntryIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
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
            ->assertSee((string) $entry->entry_number);
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
            ->assertDontSee('New Entry');
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
}
