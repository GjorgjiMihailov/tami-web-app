<?php

namespace Tests\Feature;

use App\Livewire\Accounting\JournalEntryForm;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
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
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
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
            ->set('lines.1.credit', '900')
            ->call('save')
            ->assertHasErrors('lines');

        $this->assertDatabaseCount('journal_entries', 0);
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
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $foreignRevenue = Account::where('company_id', $otherCompany->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
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
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();
        $foreignPartner = Partner::factory()->for($otherCompany)->create();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
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
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        // Note: fetchRate() is deliberately never called here — the user set
        // currency + foreign_amount but never clicked "NBRM".
        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-07-01')
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
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1000')
            ->set('lines.0.credit', '1000')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '1000')
            ->call('save')
            ->assertHasErrors('lines');

        $this->assertDatabaseCount('journal_entries', 0);
    }
}
