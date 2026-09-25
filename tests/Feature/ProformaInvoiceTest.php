<?php

namespace Tests\Feature;

use App\Exceptions\InvalidInvoiceStateException;
use App\Livewire\Invoicing\InvoiceSettings;
use App\Livewire\Invoicing\ProformaForm;
use App\Livewire\Invoicing\ProformaIndex;
use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\ProformaInvoice;
use App\Models\ProformaInvoiceLine;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\ProformaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProformaInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('internal_client');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        return $admin;
    }

    private function line(string $price = '100.00', string $qty = '1.000', string $vat = '18.00'): array
    {
        return ['item_id' => null, 'description' => 'Услуга', 'quantity' => $qty, 'unit_price' => $price, 'vat_rate' => $vat];
    }

    private function make(Company $company, Partner $partner, string $status = 'confirmed', array $extra = []): ProformaInvoice
    {
        $proforma = ProformaInvoice::factory()->create($extra + [
            'company_id' => $company->id, 'partner_id' => $partner->id, 'status' => $status,
        ]);
        ProformaInvoiceLine::factory()->create(['proforma_invoice_id' => $proforma->id, 'quantity' => '2.000', 'unit_price' => '500.00', 'vat_rate' => '18.00']);

        return $proforma;
    }

    // ---- Нумерација ----

    public function test_numbers_follow_the_invoice_settings_with_their_own_prefix_and_series(): void
    {
        $company = Company::factory()->create(['invoice_number_padding' => 3, 'invoice_number_separator' => '-', 'invoice_number_year_first' => false, 'proforma_number_prefix' => 'PF/']);
        $partner = Partner::factory()->for($company)->create();
        $service = app(ProformaService::class);
        $attrs = ['partner_id' => $partner->id, 'proforma_date' => '2026-03-01', 'status' => 'draft'];

        $first = $service->create($company, $attrs, [$this->line()]);
        $second = $service->create($company, $attrs, [$this->line()]);

        $this->assertSame('PF/001-2026', $first->proforma_number_formatted);
        $this->assertSame('PF/002-2026', $second->proforma_number_formatted);
    }

    public function test_the_series_restarts_each_year_when_the_year_is_in_the_number(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $service = app(ProformaService::class);

        $a = $service->create($company, ['partner_id' => $partner->id, 'proforma_date' => '2026-12-30', 'status' => 'draft'], [$this->line()]);
        $b = $service->create($company, ['partner_id' => $partner->id, 'proforma_date' => '2027-01-02', 'status' => 'draft'], [$this->line()]);

        $this->assertSame('ПФ-2026/1', $a->proforma_number_formatted);
        $this->assertSame('ПФ-2027/1', $b->proforma_number_formatted);
    }

    public function test_the_series_runs_on_when_the_year_is_not_in_the_number(): void
    {
        $company = Company::factory()->create(['invoice_number_include_year' => false]);
        $partner = Partner::factory()->for($company)->create();
        $service = app(ProformaService::class);

        $service->create($company, ['partner_id' => $partner->id, 'proforma_date' => '2026-12-30', 'status' => 'draft'], [$this->line()]);
        $next = $service->create($company, ['partner_id' => $partner->id, 'proforma_date' => '2027-01-02', 'status' => 'draft'], [$this->line()]);

        $this->assertSame(2, $next->proforma_number);
    }

    public function test_the_proforma_series_is_independent_of_the_invoice_series(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'fiscal_year' => 2026, 'invoice_number' => 41]);

        $proforma = app(ProformaService::class)->create($company, ['partner_id' => $partner->id, 'proforma_date' => '2026-03-01', 'status' => 'draft'], [$this->line()]);

        $this->assertSame(1, $proforma->proforma_number);
    }

    public function test_each_company_has_its_own_series(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $pa = Partner::factory()->for($a)->create();
        $pb = Partner::factory()->for($b)->create();
        $service = app(ProformaService::class);

        $service->create($a, ['partner_id' => $pa->id, 'proforma_date' => '2026-03-01', 'status' => 'draft'], [$this->line()]);
        $first = $service->create($b, ['partner_id' => $pb->id, 'proforma_date' => '2026-03-01', 'status' => 'draft'], [$this->line()]);

        $this->assertSame(1, $first->proforma_number);
    }

    public function test_the_invoice_settings_screen_edits_the_proforma_prefix_and_previews_both(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(InvoiceSettings::class, ['company' => $company])
            ->assertSet('proformaPrefix', 'ПФ-')
            ->set('proformaPrefix', 'PRO-')
            ->assertSee('PRO-'.now()->year.'/1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('PRO-', $company->fresh()->proforma_number_prefix);
    }

    // ---- Форма ----

    public function test_save_creates_a_confirmed_proforma_with_a_number(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $partner = Partner::factory()->for($company)->create();
        $this->admin();

        Livewire::test(ProformaForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('lines.0.description', 'Консултантски час')
            ->set('lines.0.quantity', '3')
            ->set('lines.0.unit_price', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $proforma = ProformaInvoice::first();
        $this->assertSame('confirmed', $proforma->status);
        $this->assertSame('ПФ-'.now()->year.'/1', $proforma->proforma_number_formatted);
        $this->assertSame('3540.00', $proforma->grandTotal());
    }

    public function test_save_as_draft_keeps_it_a_draft(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin();

        Livewire::test(ProformaForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('lines.0.description', 'X')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertSame('draft', ProformaInvoice::first()->status);
    }

    public function test_the_form_offers_a_plain_save_button_and_no_send_button(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(ProformaForm::class, ['company' => $company])
            ->assertSee('Зачувај')
            ->assertSee('Зачувај како нацрт')
            ->assertDontSee('Save and Send');
    }

    public function test_a_partner_and_a_description_are_required_and_the_partner_must_belong_to_the_company(): void
    {
        $company = Company::factory()->create();
        $foreign = Partner::factory()->for(Company::factory()->create())->create();
        $this->admin();

        Livewire::test(ProformaForm::class, ['company' => $company])
            ->call('save')
            ->assertHasErrors(['partnerId', 'lines.0.description']);

        Livewire::test(ProformaForm::class, ['company' => $company])
            ->set('partnerId', (string) $foreign->id)
            ->set('lines.0.description', 'X')
            ->call('save')
            ->assertHasErrors(['partnerId']);
    }

    public function test_choosing_an_item_fills_the_line_and_choosing_a_partner_fills_the_payment_terms(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $partner = Partner::factory()->for($company)->create(['payment_terms_days' => 30]);
        $item = Item::factory()->for($company)->create(['name' => 'Столица', 'selling_price' => '250.00', 'vat_rate' => 5]);
        $this->admin();

        Livewire::test(ProformaForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->assertSet('paymentTermsDays', '30')
            ->call('selectItem', 0, (string) $item->id)
            ->assertSet('lines.0.description', 'Столица')
            ->assertSet('lines.0.unit_price', '250.00')
            ->assertSet('lines.0.vat_rate', '5.00');
    }

    public function test_a_company_that_is_not_vat_registered_never_stores_vat(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => false]);
        $partner = Partner::factory()->for($company)->create();
        $this->admin();

        Livewire::test(ProformaForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('lines.0.description', 'X')
            ->set('lines.0.unit_price', '100')
            ->set('lines.0.vat_rate', '18.00')
            ->call('save');

        $this->assertSame('0.00', ProformaInvoiceLine::first()->vat_rate);
    }

    public function test_only_an_individual_can_use_a_foreign_currency(): void
    {
        $legal = Company::factory()->create();
        $individual = Company::factory()->create(['type' => 'individual']);
        $this->admin();

        foreach ([$legal, $individual] as $company) {
            $partner = Partner::factory()->for($company)->create();
            Livewire::test(ProformaForm::class, ['company' => $company])
                ->set('partnerId', (string) $partner->id)
                ->set('currency', 'EUR')
                ->set('lines.0.description', 'X')
                ->call('save')
                ->assertHasNoErrors();
        }

        $this->assertSame('MKD', ProformaInvoice::where('company_id', $legal->id)->first()->currency);
        $this->assertSame('EUR', ProformaInvoice::where('company_id', $individual->id)->first()->currency);
    }

    public function test_editing_updates_the_lines_and_keeps_the_number_and_status(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $proforma = $this->make($company, $partner, 'confirmed', ['proforma_number_formatted' => 'ПФ-2026/9']);
        $this->admin();

        Livewire::test(ProformaForm::class, ['company' => $company, 'proforma' => $proforma])
            ->assertSet('lines.0.unit_price', '500.00')
            ->set('lines.0.unit_price', '700')
            ->call('save')
            ->assertHasNoErrors();

        $proforma->refresh();
        $this->assertSame('ПФ-2026/9', $proforma->proforma_number_formatted);
        $this->assertSame('confirmed', $proforma->status);
        $this->assertSame('700.00', (string) $proforma->lines()->first()->unit_price);
        $this->assertCount(1, $proforma->lines);
    }

    public function test_a_converted_or_cancelled_proforma_cannot_be_opened_for_editing(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $converted = $this->make($company, $partner, 'converted');
        $cancelled = $this->make($company, $partner, 'cancelled');
        $this->admin();

        $this->get(route('proformas.edit', [$company, $converted]))->assertForbidden();
        $this->get(route('proformas.edit', [$company, $cancelled]))->assertForbidden();
    }

    public function test_a_proforma_of_another_company_is_404_under_this_company(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $foreign = $this->make($other, Partner::factory()->for($other)->create());
        $this->admin();

        $this->get(route('proformas.edit', [$company, $foreign]))->assertNotFound();
        $this->get(route('proformas.pdf', [$company, $foreign]))->assertNotFound();
    }

    // ---- Статуси ----

    public function test_the_service_guards_the_status_transitions(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $service = app(ProformaService::class);

        $empty = ProformaInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'draft']);
        try {
            $service->confirm($empty);
            $this->fail('A proforma without lines must not be confirmed.');
        } catch (InvalidInvoiceStateException) {
            $this->assertSame('draft', $empty->fresh()->status);
        }

        $converted = $this->make($company, $partner, 'converted');
        $this->expectException(InvalidInvoiceStateException::class);
        $service->cancel($converted);
    }

    public function test_the_index_marks_a_draft_confirmed_and_cancels_with_a_guard(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $draft = $this->make($company, $partner, 'draft');
        $converted = $this->make($company, $partner, 'converted');
        $this->admin();

        Livewire::test(ProformaIndex::class, ['company' => $company])
            ->call('markConfirmed', $draft->id)
            ->assertSet('error', '')
            ->call('cancelProforma', $converted->id)
            ->assertSet('error', 'Претворена профактура не може да се откаже — веќе е фактурирана.');

        $this->assertSame('confirmed', $draft->fresh()->status);
        $this->assertSame('converted', $converted->fresh()->status);
    }

    public function test_a_client_cannot_change_another_companys_proforma(): void
    {
        $own = Company::factory()->create();
        $other = Company::factory()->create();
        $foreign = $this->make($other, Partner::factory()->for($other)->create(), 'draft');
        $client = User::factory()->create(['company_id' => $own->id]);
        $client->assignRole('internal_client');
        $this->actingAs($client);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(ProformaIndex::class, ['company' => $own])->call('markConfirmed', $foreign->id);
    }

    // ---- Список и детали ----

    public function test_an_empty_company_sees_the_empty_state_with_the_create_button(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(ProformaIndex::class, ['company' => $company])
            ->assertSee('Профактурите ги отвораат продажбите')
            ->assertSeeHtml(route('proformas.create', $company))
            ->assertDontSee('<table', false);
    }

    public function test_the_list_filters_and_the_detail_shows_the_document(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Купувач Ана']);
        $draft = $this->make($company, $partner, 'draft', ['proforma_number_formatted' => 'ПФ-2026/1']);
        $confirmed = $this->make($company, $partner, 'confirmed', ['proforma_number_formatted' => 'ПФ-2026/2', 'reference' => 'НАРАЧКА-77']);
        $this->admin();

        Livewire::test(ProformaIndex::class, ['company' => $company])
            ->assertSee('ПФ-2026/1')->assertSee('ПФ-2026/2')
            ->set('filter', 'confirmed')
            ->assertSee('ПФ-2026/2')->assertDontSee('ПФ-2026/1')
            ->set('filter', 'all')
            ->set('search', 'НАРАЧКА-77')
            ->assertSee('ПФ-2026/2')->assertDontSee('ПФ-2026/1')
            ->set('search', '')
            ->call('select', $confirmed->id)
            ->assertSee('Купувач Ана')
            ->assertSee('1.180,00 ден') // 2 × 500 + 18% ДДВ
            ->assertSee('Претвори во фактура')
            ->assertSeeHtml(route('sales-invoices.create', [$company, 'proforma' => $confirmed->id]))
            ->call('select', $draft->id)
            ->assertSee('Означи како потврдена')
            ->assertDontSee('Претвори во фактура');
    }

    public function test_a_proforma_of_another_company_cannot_be_selected(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->for($company)->create();
        $this->make($company, Partner::factory()->for($company)->create());
        $other = Company::factory()->create();
        $foreign = $this->make($other, Partner::factory()->for($other)->create(), 'confirmed', ['proforma_number_formatted' => 'ТУЃА-1']);
        $this->admin();

        Livewire::test(ProformaIndex::class, ['company' => $company, 'selectedId' => $foreign->id])
            ->assertDontSee('ТУЃА-1');
    }

    public function test_the_menu_links_to_the_proforma_screen(): void
    {
        $company = Company::factory()->create(['uses_material' => true]);
        $this->admin();

        $this->get(route('proformas.index', $company))->assertOk()->assertSee('Профактури');
    }

    // ---- PDF ----

    public function test_the_pdf_downloads_in_macedonian_and_in_english_for_an_individual(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $proforma = $this->make($company, $partner);
        $this->admin();

        $response = $this->get(route('proformas.pdf', [$company, $proforma]));
        $response->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        $individual = Company::factory()->create(['type' => 'individual']);
        $en = Partner::factory()->for($individual)->create(['invoice_language' => 'en']);
        $enProforma = $this->make($individual, $en, 'confirmed', ['currency' => 'EUR']);

        $this->get(route('proformas.pdf', [$individual, $enProforma]))->assertOk();

        $html = view('pdf.proforma-invoice', [
            'proforma' => $enProforma->load(['lines.item', 'partner', 'company']),
            'company' => $individual,
            'lang' => \App\Support\InvoiceLanguage::EN,
        ])->render();
        $this->assertStringContainsString('PROFORMA INVOICE', $html);
        $this->assertStringContainsString('not a tax invoice', $html);
    }

    // ---- Претворање во фактура ----

    public function test_convert_opens_the_invoice_form_prefilled_and_still_editable(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $partner = Partner::factory()->for($company)->create();
        $proforma = $this->make($company, $partner, 'confirmed', ['payment_terms_days' => 15, 'notes' => 'Благодариме']);
        $this->admin();

        Livewire::withQueryParams(['proforma' => $proforma->id])
            ->test(SalesInvoiceForm::class, ['company' => $company])
            ->assertSet('proformaId', (string) $proforma->id)
            ->assertSet('partnerId', (string) $partner->id)
            ->assertSet('notes', 'Благодариме')
            ->assertSet('lines.0.quantity', '2.000')
            ->assertSet('lines.0.unit_price', '500.00')
            ->assertSet('lines.0.vat_rate', '18.00')
            ->set('lines.0.unit_price', '450');   // сè уште се менува

        // Само отворањето не ја претвора профактурата.
        $this->assertSame('confirmed', $proforma->fresh()->status);
    }

    public function test_saving_the_invoice_marks_the_proforma_converted_and_links_it(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $partner = Partner::factory()->for($company)->create();
        $proforma = $this->make($company, $partner, 'confirmed');
        $this->admin();

        Livewire::withQueryParams(['proforma' => $proforma->id])
            ->test(SalesInvoiceForm::class, ['company' => $company])
            ->call('save')
            ->assertHasNoErrors();

        $invoice = SalesInvoice::first();
        $this->assertNotNull($invoice);
        $this->assertSame('draft', $invoice->status);
        $this->assertSame('1000.00', $invoice->subtotal());

        $proforma->refresh();
        $this->assertSame('converted', $proforma->status);
        $this->assertSame($invoice->id, $proforma->sales_invoice_id);
    }

    public function test_a_converted_cancelled_draft_or_foreign_proforma_is_not_prefilled(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $other = Company::factory()->create();
        $cases = [
            $this->make($company, $partner, 'converted'),
            $this->make($company, $partner, 'cancelled'),
            $this->make($company, $partner, 'draft'),
            $this->make($other, Partner::factory()->for($other)->create(), 'confirmed'),
        ];
        $this->admin();

        foreach ($cases as $proforma) {
            Livewire::withQueryParams(['proforma' => $proforma->id])
                ->test(SalesInvoiceForm::class, ['company' => $company])
                ->assertSet('proformaId', '')
                ->assertSet('partnerId', '');
        }
    }

    public function test_a_tampered_proforma_id_cannot_convert_someone_elses_proforma(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $other = Company::factory()->create();
        $foreign = $this->make($other, Partner::factory()->for($other)->create(), 'confirmed');
        $this->admin();

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('proformaId', (string) $foreign->id)
            ->set('partnerId', (string) $partner->id)
            ->set('lines.0.description', 'X')
            ->call('save')
            ->assertHasErrors(['proformaId']);

        $this->assertSame('confirmed', $foreign->fresh()->status);
        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_the_same_proforma_cannot_be_converted_twice(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $partner = Partner::factory()->for($company)->create();
        $proforma = $this->make($company, $partner, 'confirmed');
        $this->admin();

        $first = Livewire::withQueryParams(['proforma' => $proforma->id])->test(SalesInvoiceForm::class, ['company' => $company]);
        $second = Livewire::withQueryParams(['proforma' => $proforma->id])->test(SalesInvoiceForm::class, ['company' => $company]);

        $first->call('save')->assertHasNoErrors();
        $second->call('save')->assertHasErrors(['proformaId']);

        // Друга фактура не е создадена, а профактурата води до првата.
        $this->assertSame(1, SalesInvoice::count());
        $this->assertSame(1, ProformaInvoice::where('status', 'converted')->count());
        $this->assertSame(SalesInvoice::first()->id, $proforma->fresh()->sales_invoice_id);
    }
}
