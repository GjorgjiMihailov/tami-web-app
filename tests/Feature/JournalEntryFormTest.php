<?php

namespace Tests\Feature;

use App\Livewire\Accounting\JournalEntryForm;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JournalEntryFormTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_a_balanced_entry_saves_and_posts_immediately(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
            ->set('journalGroupId', $group->id)
            ->set('description', 'Cash sale')
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1000')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '1000')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('accounting.journal-entries.index', $company));

        $entry = JournalEntry::where('company_id', $company->id)->where('description', 'Cash sale')->firstOrFail();
        $this->assertTrue($entry->isBalanced());
        $this->assertCount(2, $entry->lines);
    }

    public function test_an_unbalanced_entry_is_rejected(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
            ->set('journalGroupId', $group->id)
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1000')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '900')
            ->call('save')
            ->assertHasErrors('lines');

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_creating_an_entry_requires_a_journal_group(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1000')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '1000')
            ->call('save')
            ->assertHasErrors('journalGroupId');

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_creating_an_entry_assigns_the_selected_group_and_numbers_within_it(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create(['code' => '10']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
            ->set('journalGroupId', $group->id)
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1000')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $entry = JournalEntry::where('company_id', $company->id)->firstOrFail();
        $this->assertSame($group->id, $entry->journal_group_id);
        $this->assertSame(1, $entry->entry_number);
    }

    public function test_numbering_is_independent_across_two_journal_groups(): void
    {
        $company = Company::factory()->create();
        $groupA = JournalGroup::factory()->for($company)->create(['code' => '10']);
        $groupB = JournalGroup::factory()->for($company)->create(['code' => '20']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')->set('journalGroupId', $groupA->id)
            ->set('lines.0.account_id', $cash->id)->set('lines.0.debit', '1000')
            ->set('lines.1.account_id', $revenue->id)->set('lines.1.credit', '1000')
            ->call('save');

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-16')->set('journalGroupId', $groupB->id)
            ->set('lines.0.account_id', $cash->id)->set('lines.0.debit', '500')
            ->set('lines.1.account_id', $revenue->id)->set('lines.1.credit', '500')
            ->call('save');

        $entryInB = JournalEntry::where('journal_group_id', $groupB->id)->firstOrFail();
        $this->assertSame(1, $entryInB->entry_number);
    }

    public function test_the_journal_group_cannot_be_changed_when_editing_an_existing_entry(): void
    {
        $company = Company::factory()->create();
        $originalGroup = JournalGroup::factory()->for($company)->create(['code' => '10']);
        $otherGroup = JournalGroup::factory()->for($company)->create(['code' => '20']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();
        $entry = JournalEntry::factory()->for($company)->create(['journal_group_id' => $originalGroup->id, 'created_by' => $admin->id]);
        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 500, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $revenue->id, 'debit' => 0, 'credit' => 500]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry])
            ->set('journalGroupId', $otherGroup->id)
            ->set('lines.0.debit', '750')
            ->set('lines.1.credit', '750')
            ->call('save')
            ->assertHasNoErrors();

        $entry->refresh();
        $this->assertSame($originalGroup->id, $entry->journal_group_id);
    }

    public function test_editing_an_existing_entry_replaces_its_lines(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();
        $entry = JournalEntry::factory()->for($company)->create(['created_by' => $admin->id]);
        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 500, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $revenue->id, 'debit' => 0, 'credit' => 500]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry])
            ->set('lines.0.debit', '750')
            ->set('lines.1.credit', '750')
            ->call('save')
            ->assertHasNoErrors();

        $entry->refresh();
        $this->assertCount(2, $entry->lines);
        $this->assertSame('750.00', $entry->lines->first()->debit);
    }

    public function test_client_cannot_access_the_form(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->assertForbidden();
    }

    public function test_client_can_view_an_existing_entry_belonging_to_their_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();
        $entry = JournalEntry::factory()->for($company)->create([
            'created_by' => $admin->id,
            'description' => 'Rent payment for July',
        ]);
        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 500, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $revenue->id, 'debit' => 0, 'credit' => 500]);

        $this->actingAs($client);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry])
            ->assertSuccessful()
            ->assertSee('Измени налог '.$entry->displayNumber())
            ->assertSet('description', 'Rent payment for July')
            ->assertSet('lines.0.account_id', $cash->id)
            ->assertSet('lines.0.debit', '500.00');
    }

    public function test_client_cannot_save_changes_to_an_entry_they_can_view(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();
        $entry = JournalEntry::factory()->for($company)->create(['created_by' => $admin->id]);
        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 500, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $revenue->id, 'debit' => 0, 'credit' => 500]);

        $this->actingAs($client);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry])
            ->set('lines.0.debit', '750')
            ->call('save')
            ->assertForbidden();

        $entry->refresh();
        $this->assertSame('500.00', $entry->lines->first()->debit);
    }

    public function test_fetch_rate_pulls_from_nbrm_and_fills_the_line(): void
    {
        Http::fake([
            'nbrm.mk/*' => Http::response([
                ['oznaka' => 'EUR', 'sreden' => 61.6917, 'nomin' => 1, 'datum' => '2026-07-01T00:00:00'],
            ], 200),
        ]);

        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-07-01')
            ->set('lines.0.currency_code', 'EUR')
            ->set('lines.0.foreign_amount', '100')
            ->call('fetchRate', 0)
            ->assertSet('lines.0.exchange_rate', 61.6917)
            ->assertSet('lines.0.debit', '6169.17');
    }

    public function test_an_account_belonging_to_a_different_company_is_rejected(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $foreignRevenue = Account::where('company_id', $otherCompany->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
            ->set('journalGroupId', $group->id)
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1000')
            ->set('lines.1.account_id', $foreignRevenue->id)
            ->set('lines.1.credit', '1000')
            ->call('save')
            ->assertHasErrors(['lines.1.account_id']);

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_a_partner_belonging_to_a_different_company_is_rejected(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();
        $foreignPartner = Partner::factory()->for($otherCompany)->create();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
            ->set('journalGroupId', $group->id)
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1000')
            ->set('lines.0.partner_id', $foreignPartner->id)
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '1000')
            ->call('save')
            ->assertHasErrors(['lines.0.partner_id']);

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_editing_an_entry_from_a_different_company_than_the_route_is_not_found(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $entry = JournalEntry::factory()->for($otherCompany)->create(['created_by' => $admin->id]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry])
            ->assertNotFound();
    }

    public function test_a_foreign_currency_line_without_a_fetched_rate_computes_mkd_value_on_save(): void
    {
        Http::fake([
            'nbrm.mk/*' => Http::response([
                ['oznaka' => 'EUR', 'sreden' => 61.6917, 'nomin' => 1, 'datum' => '2026-07-01T00:00:00'],
            ], 200),
        ]);

        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-07-01')
            ->set('journalGroupId', $group->id)
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.currency_code', 'EUR')
            ->set('lines.0.foreign_amount', '100')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '6169.17')
            ->call('save')
            ->assertHasNoErrors();

        $entry = JournalEntry::where('company_id', $company->id)->firstOrFail();
        $line = $entry->lines()->where('account_id', $cash->id)->firstOrFail();
        $this->assertSame('6169.17', $line->debit);
        $this->assertSame('0.00', $line->credit);
    }

    public function test_a_line_with_both_debit_and_credit_nonzero_is_rejected(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
            ->set('journalGroupId', $group->id)
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1000')
            ->set('lines.0.credit', '1000')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '1000')
            ->call('save')
            ->assertHasErrors('lines');

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_a_new_lines_date_defaults_to_the_entry_date(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-05-10')
            ->set('journalGroupId', $group->id)
            ->assertSet('lines.0.line_date', '2026-05-10');
    }

    public function test_saving_persists_each_lines_own_date(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-05-10')
            ->set('journalGroupId', $group->id)
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1000')
            ->set('lines.0.line_date', '2026-05-08')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $entry = JournalEntry::where('company_id', $company->id)->firstOrFail();
        $this->assertSame('2026-05-08', $entry->lines()->where('account_id', $cash->id)->first()->line_date->toDateString());
    }

    public function test_a_line_dated_after_the_entry_date_is_flagged_red(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        $component = Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-05-10')
            ->set('journalGroupId', $group->id)
            ->set('lines.0.line_date', '2026-05-15');

        $component->assertSeeHtml('bg-red-50');
    }

    public function test_a_line_dated_on_or_before_the_entry_date_is_not_flagged(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        $component = Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-05-10')
            ->set('journalGroupId', $group->id)
            ->set('lines.0.line_date', '2026-05-10');

        $component->assertDontSeeHtml('bg-red-50');
    }

    public function test_changing_the_entry_date_does_not_overwrite_a_manually_set_line_date(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-05-10')
            ->set('journalGroupId', $group->id)
            ->set('lines.0.line_date', '2026-05-01')
            ->set('entryDate', '2026-05-20')
            ->assertSet('lines.0.line_date', '2026-05-01');
    }

    public function test_mounting_an_entry_with_a_null_line_date_does_not_crash(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $entry = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'created_by' => $admin->id]);
        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 100, 'credit' => 0, 'line_date' => null]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry])
            ->assertSuccessful()
            ->assertSet('lines.0.line_date', $entry->entry_date->toDateString());
    }

    public function test_fx_checkbox_is_unchecked_by_default_for_a_new_entry(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->assertSet('isForeignCurrency', false)
            ->assertDontSeeHtml('lines.0.currency_code');
    }

    public function test_checking_the_fx_box_reveals_the_currency_columns(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('isForeignCurrency', true)
            ->assertSeeHtml('lines.0.currency_code');
    }

    public function test_fx_checkbox_auto_checks_when_loading_an_entry_with_a_foreign_currency_line(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $entry = JournalEntry::factory()->for($company)->create(['created_by' => $admin->id]);
        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 100, 'credit' => 0, 'currency_code' => 'EUR', 'exchange_rate' => '61.5', 'foreign_amount' => '1.63']);
        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 0, 'credit' => 100]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry])
            ->assertSet('isForeignCurrency', true);
    }

    public function test_fx_checkbox_stays_unchecked_when_loading_an_all_mkd_entry(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $entry = JournalEntry::factory()->for($company)->create(['created_by' => $admin->id]);
        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 100, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 0, 'credit' => 100]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry])
            ->assertSet('isForeignCurrency', false);
    }

    public function test_admin_can_permanently_delete_a_journal_entry(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $entry = JournalEntry::factory()->for($company)->create(['created_by' => $admin->id]);
        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 100, 'credit' => 0]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry])
            ->call('delete')
            ->assertRedirect(route('accounting.journal-entries.index', $company));

        $this->assertDatabaseMissing('journal_entries', ['id' => $entry->id]);
        $this->assertDatabaseMissing('journal_entry_lines', ['journal_entry_id' => $entry->id]);
    }

    public function test_client_cannot_delete_a_journal_entry(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $entry = JournalEntry::factory()->for($company)->create();

        $this->actingAs($client);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry])
            ->call('delete')
            ->assertForbidden();

        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id]);
    }

    public function test_deleting_an_entry_linked_to_a_sales_invoice_is_blocked(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $partner = \App\Models\Partner::factory()->for($company)->create();
        $entry = JournalEntry::factory()->for($company)->create(['created_by' => $admin->id]);
        \App\Models\SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'journal_entry_id' => $entry->id]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry])
            ->call('delete')
            ->assertHasErrors('delete');

        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id]);
    }

    public function test_deleting_a_non_last_entry_leaves_a_numbering_gap(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $entry1 = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'entry_date' => '2026-01-01', 'created_by' => $admin->id]);
        $entry2 = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'entry_date' => '2026-01-02', 'created_by' => $admin->id]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry1])
            ->call('delete');

        $entry3 = JournalEntry::create([
            'company_id' => $company->id,
            'journal_group_id' => $group->id,
            'entry_date' => '2026-01-03',
            'description' => 'Third',
            'created_by' => $admin->id,
        ]);

        $this->assertGreaterThan($entry2->entry_number, $entry3->entry_number);
    }
}
