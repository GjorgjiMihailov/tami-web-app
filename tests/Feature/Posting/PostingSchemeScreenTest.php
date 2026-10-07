<?php

namespace Tests\Feature\Posting;

use App\Livewire\Accounting\PostingSchemeEdit;
use App\Livewire\Accounting\PostingSchemeIndex;
use App\Models\Company;
use App\Models\PostingScheme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PostingSchemeScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
    }

    private function accountantOf(Company $company): User
    {
        $user = User::factory()->create();
        $user->assignRole('accountant');
        $user->assignedCompanies()->attach($company);

        return $user;
    }

    public function test_the_index_lists_all_four_schemes_and_creates_the_missing_ones(): void
    {
        $company = Company::factory()->create();
        $this->assertSame(0, PostingScheme::where('company_id', $company->id)->count());

        Livewire::actingAs($this->accountantOf($company))
            ->test(PostingSchemeIndex::class, ['company' => $company])
            ->assertSee('Излезна фактура')
            ->assertSee('Уплата од купувач')
            ->assertSee('Влезна фактура')
            ->assertSee('Исплата кон добавувач');

        $this->assertSame(4, PostingScheme::where('company_id', $company->id)->count());
    }

    public function test_the_route_is_open_to_an_accountant_of_the_company_and_closed_to_others(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');
        $stranger = $this->accountantOf(Company::factory()->create());

        $this->actingAs($this->accountantOf($company))->get(route('accounting.posting-schemes.index', $company))->assertOk();
        $this->actingAs($client)->get(route('accounting.posting-schemes.index', $company))->assertForbidden();
        $this->actingAs($stranger)->get(route('accounting.posting-schemes.index', $company))->assertForbidden();
    }

    public function test_the_menu_links_the_screen_for_an_accountant(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->accountantOf($company))->get(route('accounting.accounts.index', $company))
            ->assertSee('Шеми за книжење');
    }

    private function edit(Company $company, string $type = 'sales_invoice')
    {
        return Livewire::actingAs($this->accountantOf($company))->test(PostingSchemeEdit::class, ['company' => $company, 'type' => $type]);
    }

    public function test_the_edit_screen_shows_the_rows_the_matrix_and_the_help(): void
    {
        $company = Company::factory()->create();

        $component = $this->edit($company)
            ->assertSee('Излезна фактура')
            ->assertSee('1200')
            ->assertSee('НАБАВНА_ВРЕДНОСТ')
            ->assertSee('Само ако: има стока од залиха'); // условот се прикажува со назив, не со клуч

        // Контата во матрицата стојат во полиња (вредноста ја става Livewire), па се проверуваат во состојбата.
        $this->assertContains('74000', array_column($component->get('cells'), 'account_code'));
    }

    public function test_an_unknown_scheme_type_is_a_404(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->accountantOf($company))->get(route('accounting.posting-schemes.edit', [$company, 'nesto']))->assertNotFound();
    }

    public function test_a_row_can_be_edited_and_the_scheme_saved(): void
    {
        $company = Company::factory()->create();

        $component = $this->edit($company)
            ->call('editRow', 0)
            ->set('form.description', 'Нов опис {фактура}')
            ->call('saveRow')
            ->assertSet('editing', null)
            ->call('saveScheme')
            ->assertSet('problems', [])
            ->assertSet('saved', true);

        $scheme = PostingScheme::where('company_id', $company->id)->where('doc_type', 'sales_invoice')->firstOrFail();
        $this->assertSame('Нов опис {фактура}', $scheme->rows->first()->description);
    }

    public function test_a_bad_formula_is_reported_and_nothing_is_saved(): void
    {
        $company = Company::factory()->create();

        $this->edit($company)
            ->call('editRow', 0)
            ->set('form.formula', 'ВКУПНО + 1')
            ->call('saveRow')
            ->call('saveScheme')
            ->assertSet('saved', false)
            ->assertSee('не се балансира');

        $scheme = PostingScheme::where('company_id', $company->id)->where('doc_type', 'sales_invoice')->firstOrFail();
        $this->assertSame('ВКУПНО', $scheme->rows->first()->formula);
    }

    public function test_a_row_can_be_added_moved_and_deleted_in_the_draft(): void
    {
        $company = Company::factory()->create();

        $component = $this->edit($company)->assertCount('rows', 6);

        $component->call('addRow')
            ->set('form.account_mode', 'fixed')
            ->set('form.account_code', '1000')
            ->set('form.side', 'debit')
            ->set('form.formula', 'ВКУПНО')
            ->call('saveRow')
            ->assertCount('rows', 7);

        $component->call('moveRow', 6, -1);
        $this->assertSame('1000', $component->get('rows')[5]['account_code']);

        $component->call('deleteRow', 5)->assertCount('rows', 6);
    }

    public function test_the_row_form_refuses_an_empty_formula(): void
    {
        $company = Company::factory()->create();

        $this->edit($company)
            ->call('addRow')
            ->set('form.formula', '')
            ->call('saveRow')
            ->assertHasErrors(['form.formula'])
            ->assertCount('rows', 6);
    }

    public function test_a_matrix_account_can_be_changed(): void
    {
        $company = Company::factory()->create();
        $component = $this->edit($company);
        $index = collect($component->get('cells'))->search(fn ($c) => $c['matrix_key'] === 'revenue' && $c['item_kind'] === 'service' && $c['vat_group'] === 'general');

        $component->set("cells.{$index}.account_code", '74001')->call('saveScheme')->assertSet('problems', []);

        $scheme = PostingScheme::where('company_id', $company->id)->where('doc_type', 'sales_invoice')->firstOrFail();
        $entry = $scheme->matrixAccounts()->where('matrix_key', 'revenue')->where('item_kind', 'service')->where('vat_group', 'general')->with('account')->firstOrFail();
        $this->assertSame('74001', $entry->account->code);
    }

    public function test_restore_default_brings_back_the_suggested_scheme(): void
    {
        $company = Company::factory()->create();

        $this->edit($company)
            ->call('editRow', 0)->set('form.description', 'Променет')->call('saveRow')->call('saveScheme')
            ->call('restoreDefault')
            ->assertSet('saved', false);

        $scheme = PostingScheme::where('company_id', $company->id)->where('doc_type', 'sales_invoice')->firstOrFail();
        $this->assertSame('{фактура}', $scheme->rows->first()->description);
    }

    public function test_a_scheme_of_another_company_is_not_reachable(): void
    {
        $company = Company::factory()->create();
        $stranger = $this->accountantOf(Company::factory()->create());

        Livewire::actingAs($stranger)->test(PostingSchemeEdit::class, ['company' => $company, 'type' => 'sales_invoice'])->assertForbidden();
    }

    public function test_the_trial_on_the_edit_screen_shows_the_lines_of_a_chosen_invoice(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $partner = \App\Models\Partner::factory()->for($company)->create();
        $invoice = \App\Models\SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);
        $confirmed = app(\App\Services\Invoicing\SalesInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);
        $entries = \App\Models\JournalEntry::count();

        $this->edit($company)
            ->set('trialDocument', (string) $confirmed->id)
            ->call('runTrial')
            ->assertSee('74000')
            ->assertSee('1180.00');

        $this->assertSame($entries, \App\Models\JournalEntry::count());
    }

    public function test_the_trial_reports_a_broken_draft_instead_of_failing(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $partner = \App\Models\Partner::factory()->for($company)->create();
        $invoice = \App\Models\SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);
        $confirmed = app(\App\Services\Invoicing\SalesInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);

        $this->edit($company)
            ->call('editRow', 0)->set('form.formula', 'ВКУПНО + 1')->call('saveRow')
            ->set('trialDocument', (string) $confirmed->id)
            ->call('runTrial')
            ->assertSee('не се балансира');
    }

    public function test_save_as_mine_saves_the_scheme_and_remembers_it_for_the_accountant(): void
    {
        $company = Company::factory()->create();
        $accountant = $this->accountantOf($company);

        Livewire::actingAs($accountant)->test(PostingSchemeEdit::class, ['company' => $company, 'type' => 'sales_invoice'])
            ->call('editRow', 0)->set('form.description', 'Мој опис {фактура}')->call('saveRow')
            ->call('saveAsMine')
            ->assertSet('problems', [])
            ->assertSet('mineSaved', true);

        $this->assertTrue(\App\Services\Posting\PostingSchemeSets::has($accountant, \App\Support\Posting\PostingDocType::SALES_INVOICE));
        $scheme = PostingScheme::where('company_id', $company->id)->where('doc_type', 'sales_invoice')->firstOrFail();
        $this->assertSame('Мој опис {фактура}', $scheme->rows->first()->description);
    }

    public function test_a_bad_draft_is_neither_saved_nor_remembered(): void
    {
        $company = Company::factory()->create();
        $accountant = $this->accountantOf($company);

        Livewire::actingAs($accountant)->test(PostingSchemeEdit::class, ['company' => $company, 'type' => 'sales_invoice'])
            ->call('editRow', 0)->set('form.formula', 'ВКУПНО + 1')->call('saveRow')
            ->call('saveAsMine')
            ->assertSet('mineSaved', false)
            ->assertSee('не се балансира');

        $this->assertFalse(\App\Services\Posting\PostingSchemeSets::has($accountant, \App\Support\Posting\PostingDocType::SALES_INVOICE));
    }

    public function test_restore_brings_back_my_set_when_i_have_one(): void
    {
        $company = Company::factory()->create();
        $accountant = $this->accountantOf($company);
        $component = Livewire::actingAs($accountant)->test(PostingSchemeEdit::class, ['company' => $company, 'type' => 'sales_invoice'])
            ->call('editRow', 0)->set('form.description', 'Мој опис {фактура}')->call('saveRow')->call('saveAsMine')
            ->call('editRow', 0)->set('form.description', 'Друго')->call('saveRow')->call('saveScheme');

        $component->call('restoreDefault');

        $scheme = PostingScheme::where('company_id', $company->id)->where('doc_type', 'sales_invoice')->firstOrFail();
        $this->assertSame('Мој опис {фактура}', $scheme->rows->first()->description);
    }

    public function test_restore_reports_a_missing_account_of_my_set_instead_of_failing(): void
    {
        $source = Company::factory()->create();
        $accountant = $this->accountantOf($source);
        Livewire::actingAs($accountant)->test(PostingSchemeEdit::class, ['company' => $source, 'type' => 'sales_invoice'])->call('saveAsMine');
        $company = Company::factory()->create();
        $accountant->assignedCompanies()->attach($company);
        \App\Models\Account::where('company_id', $company->id)->where('code', '74001')->update(['is_analytical' => false]);

        Livewire::actingAs($accountant)->test(PostingSchemeEdit::class, ['company' => $company, 'type' => 'sales_invoice'])
            ->call('restoreDefault')
            ->assertSee('нема во планот на оваа фирма');
    }
}
