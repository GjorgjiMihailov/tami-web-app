<?php

namespace Tests\Feature;

use App\Livewire\Accounting\JournalEntryForm;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\Format;
use App\Support\WorkingYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JournalEntryFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
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

    public function test_render_exposes_account_and_partner_options_for_the_autocomplete(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $partner = Partner::factory()->for($company)->create(['name' => 'ABC Trading']);

        $this->actingAs($admin);

        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->assertViewHas('accountsForJs', fn ($accounts) => collect($accounts)->contains(fn ($a) => $a['id'] === $cash->id && str_contains($a['label'], $cash->code)))
            ->assertViewHas('partnersForJs', fn ($partners) => collect($partners)->contains(fn ($p) => $p['id'] === $partner->id && $p['label'] === 'ABC Trading'));
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
        $partner = Partner::factory()->for($company)->create();
        $entry = JournalEntry::factory()->for($company)->create(['created_by' => $admin->id]);
        SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'journal_entry_id' => $entry->id]);

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

    public function test_next_navigates_to_the_next_entry_in_the_same_journal_group(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $entry1 = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'entry_date' => '2026-01-01', 'created_by' => $admin->id]);
        $entry2 = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'entry_date' => '2026-01-02', 'created_by' => $admin->id]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry1])
            ->call('goToNext')
            ->assertRedirect(route('accounting.journal-entries.edit', [$company, $entry2]));
    }

    public function test_previous_navigates_to_the_previous_entry_in_the_same_journal_group(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $entry1 = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'entry_date' => '2026-01-01', 'created_by' => $admin->id]);
        $entry2 = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'entry_date' => '2026-01-02', 'created_by' => $admin->id]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry2])
            ->call('goToPrevious')
            ->assertRedirect(route('accounting.journal-entries.edit', [$company, $entry1]));
    }

    public function test_navigator_does_not_cross_into_a_different_journal_group(): void
    {
        $company = Company::factory()->create();
        $groupA = JournalGroup::factory()->for($company)->create(['code' => '10']);
        $groupB = JournalGroup::factory()->for($company)->create(['code' => '20']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        // entryInA is the ONLY entry in group A, so within-group there is no "next" entry.
        // The group B entry is dated into a LATER fiscal year so its fiscal_year (2027) is
        // strictly greater than entryInA's (2026) regardless of entry_number (which is itself
        // scoped per journal_group_id, so a same-year group B entry would tie at entry_number
        // 1 and not actually exercise the fiscal_year/entry_number ordering). This guarantees
        // nextEntry() would incorrectly surface the group B entry if the journal_group_id
        // scoping were removed, giving this test real discriminating power.
        $entryInA = JournalEntry::factory()->for($company)->create(['journal_group_id' => $groupA->id, 'entry_date' => '2026-01-01', 'created_by' => $admin->id]);
        JournalEntry::factory()->for($company)->create(['journal_group_id' => $groupB->id, 'entry_date' => '2027-01-01', 'created_by' => $admin->id]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entryInA])
            ->call('goToNext')
            ->assertNoRedirect();
    }

    public function test_navigator_rolls_from_the_last_entry_of_one_fiscal_year_into_the_next_years_first_entry(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $lastOf2025 = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'entry_date' => '2025-12-31', 'created_by' => $admin->id]);
        $firstOf2026 = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'entry_date' => '2026-01-01', 'created_by' => $admin->id]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $lastOf2025])
            ->call('goToNext')
            ->assertRedirect(route('accounting.journal-entries.edit', [$company, $firstOf2026]));
    }

    public function test_footer_shows_running_totals(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1500')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '1000')
            ->assertSeeText(Format::money('1500.00'))
            ->assertSeeText(Format::money('1000.00'));
    }

    public function test_footer_flags_red_when_unbalanced(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '500')
            ->assertSeeHtml('sticky bottom-0 bg-white border-t border-gray-200 px-4 py-3 flex flex-wrap justify-end gap-6 text-sm font-semibold text-red-600');
    }

    public function test_footer_does_not_flag_red_when_balanced(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '500')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '500')
            ->assertSeeHtml('sticky bottom-0 bg-white border-t border-gray-200 px-4 py-3 flex flex-wrap justify-end gap-6 text-sm font-semibold text-gray-800')
            ->assertDontSeeHtml('sticky bottom-0 bg-white border-t border-gray-200 px-4 py-3 flex flex-wrap justify-end gap-6 text-sm font-semibold text-red-600');
    }

    public function test_the_desktop_lines_table_has_the_new_header_background(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->assertSee('bg-gray-50', false);
    }

    public function test_the_lines_table_deliberately_has_no_row_hover_but_mobile_cards_get_the_new_radius(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            // deliberately no row-hover: these rows contain live editable inputs, not passive data
            ->assertDontSee('hover:bg-orange-50', false)
            ->assertSee('rounded-xl', false);
    }

    public function test_the_account_list_is_rendered_only_once_regardless_of_line_count(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $component = Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->call('addLine')
            ->call('addLine');

        $account = Account::where('company_id', $company->id)->where('code', '100')->first();
        $html = $component->html();

        // The account's "{code} — {name}" label text survives @js() encoding
        // of the accounts array verbatim (only the surrounding JSON quote
        // characters get escaped, not the label content), so a plain
        // substring match against the rendered HTML is a reliable, distinctive
        // needle. It should appear exactly once — proving the account list is
        // hoisted into the outer wrapper's x-data rather than duplicated once
        // per line (desktop row + mobile card) as it was before this fix.
        $needle = $account->code.' — '.$account->name;
        $this->assertSame(1, substr_count($html, $needle));
    }

    public function test_each_lines_key_is_stable_and_distinct_after_removing_a_line(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $component = Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->call('addLine');

        $keys = collect($component->get('lines'))->pluck('_key');
        $this->assertCount(3, $keys);
        $this->assertCount(3, $keys->unique());
        $this->assertTrue($keys->every(fn ($key) => is_string($key) && $key !== ''));

        $middleKey = $keys[1];
        $lastKey = $keys[2];

        $component->call('removeLine', 1);

        $remainingKeys = collect($component->get('lines'))->pluck('_key');
        $this->assertCount(2, $remainingKeys);
        // The line that used to be at index 2 shifts down to index 1 after
        // removal, but its _key must travel WITH it (not be reset to the
        // key that used to occupy index 1) — that's what keeps Livewire's
        // wire:key stable for Alpine's client-side state across the removal.
        $this->assertSame($lastKey, $remainingKeys[1]);
        $this->assertNotContains($middleKey, $remainingKeys);
    }

    public function test_an_empty_account_id_is_rejected_on_save(): void
    {
        // If the user retypes in the autocomplete box without picking a
        // suggestion, the JS-side onInput() handler clears the bound
        // account_id to '' (see resources/js/journal-entry-picker.js). This
        // proves the server-side 'required' validation catches that state
        // rather than silently posting against whatever account_id was
        // previously selected.
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
            ->set('journalGroupId', $group->id)
            ->set('lines.0.account_id', '')
            ->set('lines.0.debit', '1000')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '1000')
            ->call('save')
            ->assertHasErrors('lines.0.account_id');

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_an_empty_partner_id_after_retyping_without_selecting_does_not_block_save(): void
    {
        // partner_id is nullable — clearing it (the same JS-side behavior as
        // account_id) must NOT block save, since a line with no partner is a
        // valid, normal case in this app.
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
            ->set('lines.0.partner_id', '')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_calling_delete_on_a_brand_new_unsaved_entry_does_not_crash(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        // The delete button only renders when an entry exists, but delete()
        // itself should defensively no-op rather than TypeError on
        // Gate::authorize('delete', null) if it's ever invoked without one.
        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->call('delete')
            ->assertNoRedirect();

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_a_new_entry_opens_dated_inside_the_working_year(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);
        WorkingYear::set($company, 2024);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->assertSet('entryDate', '2024-12-31');
    }

    public function test_a_new_entry_in_the_current_working_year_still_opens_dated_today(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->assertSet('entryDate', now()->toDateString());
    }
}
