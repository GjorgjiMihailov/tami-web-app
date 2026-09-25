<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Livewire\PartnerIndex;
use App\Models\Company;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseInvoicePayment;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesInvoicePayment;
use App\Models\User;
use App\Services\PartnerInsights;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PartnerDetailTest extends TestCase
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

    /** Потврдена излезна фактура со една ставка: количина 1, нето $net, ДДВ 18%. */
    private function invoice(Company $company, Partner $partner, string $date, string $net = '1000.00', array $extra = []): SalesInvoice
    {
        $invoice = SalesInvoice::factory()->for($company)->create($extra + [
            'partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => $date, 'due_date' => $date,
            'invoice_number_formatted' => 'ФК-'.$date,
        ]);
        SalesInvoiceLine::factory()->create(['sales_invoice_id' => $invoice->id, 'quantity' => '1.000', 'unit_price' => $net, 'vat_rate' => '18.00']);

        return $invoice;
    }

    // ---- Списокот и изборот ----

    public function test_old_show_links_redirect_into_the_list_with_the_partner_selected(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin();

        $this->get(route('partners.show', [$company, $partner]))
            ->assertRedirect(route('partners.index', [$company, 'partner' => $partner->id]));
    }

    public function test_a_client_cannot_open_another_companys_list(): void
    {
        $own = Company::factory()->create();
        $other = Company::factory()->create();
        $partner = Partner::factory()->for($other)->create();
        $client = User::factory()->create(['company_id' => $own->id]);
        $client->assignRole('internal_client');
        $this->actingAs($client);

        $this->get(route('partners.index', [$other, 'partner' => $partner->id]))->assertForbidden();
    }

    public function test_a_partner_of_another_company_cannot_be_selected(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->for($company)->create();
        $foreign = Partner::factory()->for(Company::factory()->create())->create(['name' => 'Туѓ-кооперант']);
        $this->admin();

        Livewire::test(PartnerIndex::class, ['company' => $company, 'selectedId' => $foreign->id])
            ->assertDontSee('Туѓ-кооперант')
            ->assertSee('Избери кооперант од листата');
    }

    public function test_the_list_filters_partners(): void
    {
        $company = Company::factory()->create();
        $debtor = Partner::factory()->for($company)->create(['name' => 'Должник-А']);
        Partner::factory()->for($company)->create(['name' => 'Чист-Б']);
        Partner::factory()->for($company)->individual()->create(['name' => 'Физичко-В']);
        $this->invoice($company, $debtor, '2026-03-01');
        $this->admin();

        Livewire::test(PartnerIndex::class, ['company' => $company])
            ->set('filter', 'owed')
            ->assertSee('Должник-А')->assertDontSee('Чист-Б')
            ->set('filter', 'individual')
            ->assertSee('Физичко-В')->assertDontSee('Чист-Б')
            ->set('filter', 'legal_entity')
            ->assertSee('Чист-Б')->assertDontSee('Физичко-В');
    }

    public function test_the_list_shows_the_outstanding_amount_per_partner(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = $this->invoice($company, $partner, '2026-03-01', '1000.00');
        SalesInvoicePayment::factory()->create(['sales_invoice_id' => $invoice->id, 'amount' => '180.00']);
        $this->admin();

        // 1000 + 18% = 1180, уплатено 180 → 1000 ненаплатено.
        Livewire::test(PartnerIndex::class, ['company' => $company])->assertSee('1.000,00 ден');
    }

    // ---- Преглед ----

    public function test_the_overview_shows_contact_address_details_and_contact_persons(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create([
            'type' => 'legal_entity', 'registration_number' => '7080123', 'director_name' => 'Марко Марковски',
            'is_vat_registered' => true, 'vat_number' => 'MK4030012345678', 'email' => 'kontakt@primer.mk',
            'contact_first_name' => 'Ана', 'contact_last_name' => 'Аничева', 'payment_terms_days' => 30,
            'street_address' => 'Илинденска', 'street_number' => '5', 'city' => 'Скопје', 'address' => null,
            'shipping_street_address' => 'Индустриска', 'shipping_city' => 'Тетово',
        ]);
        $partner->contacts()->create(['position' => 0, 'first_name' => 'Игор', 'last_name' => 'Игоров', 'mobile' => '070111222']);
        $partner->bankAccounts()->create(['bank_name' => 'Комерцијална банка', 'account_number' => 'MK07300701104789126', 'position' => 0]);
        $this->admin();

        Livewire::test(PartnerIndex::class, ['company' => $company])
            ->call('select', $partner->id)
            ->assertSee('kontakt@primer.mk')
            ->assertSee('Ана Аничева')
            ->assertSee('Илинденска 5, Скопје')
            ->assertSee('Индустриска')
            ->assertSee('7080123')
            ->assertSee('Марко Марковски')
            ->assertSee('MK4030012345678')
            ->assertSee('30 дена')
            ->assertSee('Игор Игоров')
            ->assertSee('070111222')
            ->assertSee('MK07300701104789126')
            ->assertSeeHtml(route('partners.edit', [$company, $partner]));
    }

    public function test_an_individual_partner_hides_the_legal_entity_fields(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->individual()->create();
        $this->admin();

        Livewire::test(PartnerIndex::class, ['company' => $company])
            ->call('select', $partner->id)
            ->assertSee('Физичко лице')
            ->assertDontSee('ЕМБС');
    }

    public function test_the_new_invoice_button_carries_the_partner(): void
    {
        $company = Company::factory()->create(['uses_material' => true]);
        $partner = Partner::factory()->for($company)->create();
        $this->admin();

        Livewire::test(PartnerIndex::class, ['company' => $company])
            ->call('select', $partner->id)
            ->assertSeeHtml('partner='.$partner->id);
    }

    public function test_the_sales_invoice_form_opens_with_the_partner_preselected_and_ignores_a_foreign_one(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['payment_terms_days' => 15]);
        $foreign = Partner::factory()->for(Company::factory()->create())->create();
        $this->admin();

        $this->get(route('sales-invoices.create', [$company, 'partner' => $partner->id]));
        Livewire::withQueryParams(['partner' => $partner->id])
            ->test(SalesInvoiceForm::class, ['company' => $company])
            ->assertSet('partnerId', (string) $partner->id);

        Livewire::withQueryParams(['partner' => $foreign->id])
            ->test(SalesInvoiceForm::class, ['company' => $company])
            ->assertSet('partnerId', '');
    }

    public function test_receivables_are_summed_per_currency_and_overdue_is_split_out(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->invoice($company, $partner, '2020-01-01', '1000.00'); // доспеана, 1180
        $future = $this->invoice($company, $partner, now()->addDays(30)->toDateString(), '500.00'); // 590, не е доспеана
        $future->update(['due_date' => now()->addDays(30)->toDateString()]);
        $this->invoice($company, $partner, '2026-01-01', '100.00', ['currency' => 'EUR', 'exchange_rate' => '61.5', 'due_date' => now()->addDays(30)->toDateString()]);
        SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'draft']); // нацрт не се брои

        $rows = PartnerInsights::receivables($partner)->keyBy('currency');

        $this->assertSame('1770.00', $rows['MKD']['outstanding']);
        $this->assertSame('1180.00', $rows['MKD']['overdue']);
        $this->assertSame('118.00', $rows['EUR']['outstanding']);
        $this->assertSame('0.00', $rows['EUR']['overdue']);
    }

    // ---- Трансакции ----

    public function test_the_transactions_tab_lists_invoices_payments_and_bills(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $sale = $this->invoice($company, $partner, '2026-03-01');
        SalesInvoicePayment::factory()->create(['sales_invoice_id' => $sale->id, 'amount' => '300.00', 'payment_date' => '2026-03-10']);
        $bill = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'supplier_invoice_number' => 'ДОБ-77']);
        PurchaseInvoiceLine::factory()->create(['purchase_invoice_id' => $bill->id, 'quantity' => '1.000', 'unit_price' => '200.00']);
        PurchaseInvoicePayment::factory()->create(['purchase_invoice_id' => $bill->id, 'amount' => '50.00']);
        $this->admin();

        $component = Livewire::test(PartnerIndex::class, ['company' => $company])
            ->call('select', $partner->id)
            ->set('tab', 'transactions')
            ->assertSee('ФК-2026-03-01')
            ->assertSee('1.180,00 ден')
            ->assertSee('ДОБ-77')
            ->assertSee('300,00 ден')
            ->assertSee('50,00 ден');

        $component->assertSeeHtml(route('sales-invoices.show', [$company, $sale]));
    }

    public function test_the_invoice_status_filter_narrows_the_invoice_list(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $paid = $this->invoice($company, $partner, '2026-03-01', '100.00', ['invoice_number_formatted' => 'ПЛАТЕНА-1']);
        SalesInvoicePayment::factory()->create(['sales_invoice_id' => $paid->id, 'amount' => '118.00']);
        $this->invoice($company, $partner, '2026-03-02', '100.00', ['invoice_number_formatted' => 'НЕПЛАТЕНА-2']);
        $this->admin();

        Livewire::test(PartnerIndex::class, ['company' => $company])
            ->call('select', $partner->id)
            ->set('tab', 'transactions')
            ->set('invoiceStatus', 'paid')
            ->assertSee('Излезни фактури (1)')
            ->assertSee('ПЛАТЕНА-1')->assertDontSee('НЕПЛАТЕНА-2')
            ->set('invoiceStatus', 'unpaid')
            // Уплатата на платената фактура останува во својата колона, па се мери насловот на списокот.
            ->assertSee('Излезни фактури (1)')
            ->assertSee('НЕПЛАТЕНА-2')
            ->set('invoiceStatus', 'draft')
            ->assertSee('Излезни фактури (0)');
    }

    public function test_transactions_only_show_this_partners_documents(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $other = Partner::factory()->for($company)->create();
        $this->invoice($company, $other, '2026-03-01', '100.00', ['invoice_number_formatted' => 'ТУЃА-ФАКТУРА']);
        $this->admin();

        Livewire::test(PartnerIndex::class, ['company' => $company])
            ->call('select', $partner->id)
            ->set('tab', 'transactions')
            ->assertDontSee('ТУЃА-ФАКТУРА')
            ->assertSee('Нема излезни фактури.');
    }

    // ---- Извод ----

    public function test_the_statement_carries_the_opening_balance_and_a_running_balance(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $today = CarbonImmutable::parse('2026-03-20');

        $old = $this->invoice($company, $partner, '2026-01-10', '1000.00');   // 1180, пред периодот
        SalesInvoicePayment::factory()->create(['sales_invoice_id' => $old->id, 'amount' => '180.00', 'payment_date' => '2026-02-01']); // пред периодот
        $march = $this->invoice($company, $partner, '2026-03-05', '500.00');  // 590
        SalesInvoicePayment::factory()->create(['sales_invoice_id' => $march->id, 'amount' => '90.00', 'payment_date' => '2026-03-12']);
        $this->invoice($company, $partner, '2026-04-02', '999.00');           // по периодот — не се брои

        $statement = PartnerInsights::statement($partner, 'this_month', $today);
        $mkd = $statement['currencies']['MKD'];

        $this->assertSame('1000.00', $mkd['opening']);
        $this->assertSame('590.00', $mkd['invoiced']);
        $this->assertSame('90.00', $mkd['received']);
        $this->assertSame('1500.00', $mkd['closing']);
        $this->assertCount(2, $mkd['rows']);
        $this->assertSame('1590.00', $mkd['rows'][0]['balance']);
        $this->assertSame('1500.00', $mkd['rows'][1]['balance']);
        $this->assertSame('invoice', $mkd['rows'][0]['type']);
        $this->assertSame('payment', $mkd['rows'][1]['type']);
    }

    public function test_the_whole_period_statement_starts_from_zero_and_never_mixes_currencies(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->invoice($company, $partner, '2026-01-10', '100.00');
        $this->invoice($company, $partner, '2026-01-11', '200.00', ['currency' => 'EUR', 'exchange_rate' => '61.5']);

        $statement = PartnerInsights::statement($partner, 'all', CarbonImmutable::parse('2026-03-20'));

        $this->assertSame(['EUR', 'MKD'], array_keys($statement['currencies']));
        $this->assertSame('0.00', $statement['currencies']['MKD']['opening']);
        $this->assertSame('118.00', $statement['currencies']['MKD']['closing']);
        $this->assertSame('236.00', $statement['currencies']['EUR']['closing']);
    }

    public function test_the_statement_ignores_drafts_and_an_empty_one_is_a_zero_denar_statement(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'draft']);

        $statement = PartnerInsights::statement($partner, 'all');

        $this->assertSame(['MKD'], array_keys($statement['currencies']));
        $this->assertSame('0.00', $statement['currencies']['MKD']['closing']);
        $this->assertSame([], $statement['currencies']['MKD']['rows']);
    }

    public function test_the_statement_tab_renders_and_an_unknown_period_falls_back(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Купувач-Извод']);
        $this->invoice($company, $partner, now()->toDateString(), '100.00');
        $this->admin();

        Livewire::test(PartnerIndex::class, ['company' => $company])
            ->call('select', $partner->id)
            ->set('tab', 'statement')
            ->set('period', 'nonsense')
            ->assertSee('Извод на сметка за')
            ->assertSee('*** Почетно салдо ***')
            ->assertSee('118,00 ден')
            ->assertSeeHtml(route('partners.statement.pdf', [$company, $partner, 'period' => 'nonsense']));
    }

    public function test_the_statement_pdf_downloads_and_respects_company_boundaries(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Купувач-PDF']);
        $foreign = Partner::factory()->for(Company::factory()->create())->create();
        $this->invoice($company, $partner, now()->toDateString(), '100.00');
        $this->admin();

        $response = $this->get(route('partners.statement.pdf', [$company, $partner, 'period' => 'all']));
        $response->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        $this->get(route('partners.statement.pdf', [$company, $foreign]))->assertNotFound();
    }

    public function test_a_client_cannot_download_another_companys_statement(): void
    {
        $own = Company::factory()->create();
        $other = Company::factory()->create();
        $partner = Partner::factory()->for($other)->create();
        $client = User::factory()->create(['company_id' => $own->id]);
        $client->assignRole('internal_client');
        $this->actingAs($client);

        $this->get(route('partners.statement.pdf', [$other, $partner]))->assertForbidden();
    }
}
