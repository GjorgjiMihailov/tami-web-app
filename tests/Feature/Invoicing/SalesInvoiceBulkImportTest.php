<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\SalesInvoiceBulkImport;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceBulkImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ['Број на фактура', 'Датум на издавање', 'Кооперант', 'ЕДБ на кооперант', 'Вкупно од хартија (незадолжително)'];

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function upload(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'inv-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return UploadedFile::fake()->createWithContent('fakturi.xlsx', file_get_contents($path));
    }

    private function previewed(Company $company, array $rows, ?User $user = null)
    {
        return Livewire::actingAs($user ?? $this->admin())
            ->test(SalesInvoiceBulkImport::class, ['company' => $company])
            ->set('importFile', $this->upload(array_merge([self::HEADER], $rows)))
            ->call('preview');
    }

    private function groupKey($component): string
    {
        return array_key_first($component->get('partnerChoices'));
    }

    public function test_the_page_opens_for_an_admin_and_for_an_assigned_accountant(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $this->actingAs($this->admin())->get(route('sales-invoices.bulk-import', $company))->assertOk();
        $this->actingAs($accountant)->get(route('sales-invoices.bulk-import', $company))->assertOk();
    }

    public function test_a_client_cannot_open_the_page_and_sees_no_button(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        $this->actingAs($client)->get(route('sales-invoices.bulk-import', $company))->assertForbidden();

        $this->actingAs($client)->get(route('sales-invoices.index', $company))
            ->assertOk()
            ->assertDontSee('Масовен внес');
    }

    public function test_the_index_shows_the_button_to_an_admin(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->admin())->get(route('sales-invoices.index', $company))
            ->assertOk()
            ->assertSee('Масовен внес')
            ->assertSee(route('sales-invoices.bulk-import', $company), false);
    }

    public function test_confirming_clear_rows_creates_drafts_without_lines(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Прва ДООЕЛ', 'tax_id' => '4043025542148']);

        $this->previewed($company, [
            ['00001-26', '04.01.2026', 'Прва ДООЕЛ', '4043025542148', '2360'],
            ['00002-26', '05.01.2026', 'Прва ДООЕЛ', '', ''],
        ])
            ->assertSet('parsedRows.0.status', 'ready')
            ->call('confirmImport')
            ->assertHasNoErrors();

        $invoices = SalesInvoice::where('company_id', $company->id)->orderBy('invoice_number_formatted')->get();

        $this->assertCount(2, $invoices);
        $first = $invoices[0];
        $this->assertSame('00001-26', $first->invoice_number_formatted);
        $this->assertSame('draft', $first->status);
        $this->assertTrue($first->is_imported);
        $this->assertSame($partner->id, $first->partner_id);
        $this->assertSame('2026-01-04', $first->invoice_date->toDateString());
        $this->assertNull($first->fiscal_year);
        $this->assertNull($first->invoice_number);
        $this->assertSame('MKD', $first->currency);
        $this->assertCount(0, $first->lines);
        $this->assertSame('2360.00', (string) $first->imported_total);
        $this->assertNull($invoices[1]->imported_total);
    }

    public function test_the_due_date_follows_the_partner_terms_and_falls_back_to_the_invoice_date(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->for($company)->create(['name' => 'Со рок', 'payment_terms_days' => 30]);
        Partner::factory()->for($company)->create(['name' => 'Без рок', 'payment_terms_days' => null]);

        $this->previewed($company, [
            ['1', '04.01.2026', 'Со рок', '', ''],
            ['2', '04.01.2026', 'Без рок', '', ''],
        ])->call('confirmImport');

        $this->assertSame('2026-02-03', SalesInvoice::where('invoice_number_formatted', '1')->first()->due_date->toDateString());
        $this->assertSame('2026-01-04', SalesInvoice::where('invoice_number_formatted', '2')->first()->due_date->toDateString());
    }

    public function test_unmatched_names_wait_for_a_choice_and_nothing_is_created_without_it(): void
    {
        $company = Company::factory()->create();

        $component = $this->previewed($company, [
            ['1', '04.01.2026', 'Непозната ДООЕЛ', '', ''],
        ])
            ->assertSet('parsedRows.0.status', 'unmatched');

        $component->call('confirmImport')->assertHasErrors(['confirm']);

        $this->assertSame(0, SalesInvoice::where('company_id', $company->id)->count());
    }

    public function test_choosing_create_new_makes_one_partner_for_all_rows_with_that_name(): void
    {
        $company = Company::factory()->create();

        $component = $this->previewed($company, [
            ['1', '04.01.2026', 'Нова Фирма ДООЕЛ', '', ''],
            ['2', '05.01.2026', '  нова   фирма дооел ', '4044000000000', ''],
            ['3', '06.01.2026', 'НОВА ФИРМА ДООЕЛ', '', ''],
        ]);

        $key = $this->groupKey($component);
        $this->assertCount(1, $component->get('partnerChoices'));

        $component->set("partnerChoices.$key", 'new')->call('confirmImport')->assertHasNoErrors();

        $partners = Partner::where('company_id', $company->id)->get();
        $this->assertCount(1, $partners);
        $this->assertSame('Нова Фирма ДООЕЛ', $partners[0]->name);
        $this->assertSame('4044000000000', $partners[0]->tax_id);
        $this->assertSame(3, SalesInvoice::where('partner_id', $partners[0]->id)->count());
    }

    public function test_choosing_an_existing_partner_attaches_all_rows_to_it(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Постоечка']);

        $component = $this->previewed($company, [
            ['1', '04.01.2026', 'Некое старо име', '', ''],
            ['2', '05.01.2026', 'Некое старо име', '', ''],
        ]);

        $component->set('partnerChoices.'.$this->groupKey($component), (string) $partner->id)
            ->call('confirmImport')
            ->assertHasNoErrors();

        $this->assertSame(2, SalesInvoice::where('partner_id', $partner->id)->count());
    }

    public function test_a_partner_of_another_company_cannot_be_chosen(): void
    {
        $company = Company::factory()->create();
        $foreign = Partner::factory()->create(['name' => 'Туѓа']);

        $component = $this->previewed($company, [['1', '04.01.2026', 'Непозната', '', '']]);

        $component->set('partnerChoices.'.$this->groupKey($component), (string) $foreign->id)
            ->call('confirmImport')
            ->assertHasErrors(['confirm']);

        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_skipping_a_group_leaves_those_rows_out(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->for($company)->create(['name' => 'Позната']);

        $component = $this->previewed($company, [
            ['1', '04.01.2026', 'Позната', '', ''],
            ['2', '05.01.2026', 'Непозната', '', ''],
        ]);

        $component->set('partnerChoices.'.$this->groupKey($component), 'skip')
            ->call('confirmImport')
            ->assertHasNoErrors();

        $this->assertSame(['1'], SalesInvoice::pluck('invoice_number_formatted')->all());
    }

    public function test_rows_with_errors_are_left_out_and_the_others_are_created(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->for($company)->create(['name' => 'Позната']);

        $this->previewed($company, [
            ['1', '04.01.2026', 'Позната', '', ''],
            ['2', 'не е датум', 'Позната', '', ''],
        ])
            ->assertSet('parsedRows.1.status', 'error')
            ->call('confirmImport')
            ->assertHasNoErrors();

        $this->assertSame(['1'], SalesInvoice::pluck('invoice_number_formatted')->all());
    }

    public function test_a_second_confirm_does_not_create_a_second_set(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->for($company)->create(['name' => 'Позната']);

        $this->previewed($company, [['1', '04.01.2026', 'Позната', '', '']])
            ->call('confirmImport')
            ->call('confirmImport');

        $this->assertSame(1, SalesInvoice::count());
    }

    public function test_a_number_taken_between_preview_and_confirm_leaves_only_that_row_out(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->for($company)->create(['name' => 'Позната']);

        $component = $this->previewed($company, [
            ['1', '04.01.2026', 'Позната', '', ''],
            ['2', '05.01.2026', 'Позната', '', ''],
        ]);

        SalesInvoice::factory()->for($company)->create([
            'invoice_number_formatted' => '2',
            'invoice_date' => '2026-02-01',
            'due_date' => '2026-02-01',
        ]);

        $component->call('confirmImport');

        // Преземениот број е грешка на тој ред; другиот ред сепак се увезува.
        $this->assertSame(['2', '1'], SalesInvoice::orderBy('id')->pluck('invoice_number_formatted')->all());
        $this->assertSame(1, SalesInvoice::where('is_imported', true)->count());
    }

    public function test_an_unreadable_file_is_reported_not_crashed(): void
    {
        $company = Company::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(SalesInvoiceBulkImport::class, ['company' => $company])
            ->set('importFile', UploadedFile::fake()->createWithContent('lose.xlsx', 'ова не е табела'))
            ->call('preview')
            ->assertHasErrors(['importFile']);
    }

    public function test_more_than_500_rows_are_refused_with_a_message(): void
    {
        $company = Company::factory()->create();
        $rows = [];

        for ($i = 1; $i <= 501; $i++) {
            $rows[] = [(string) $i, '04.01.2026', 'А', '', ''];
        }

        $this->previewed($company, $rows)->assertHasErrors(['importFile']);
    }
}
