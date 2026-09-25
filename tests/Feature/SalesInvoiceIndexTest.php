<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\SalesInvoiceIndex;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\SalesInvoicePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('internal_client');
        Role::findOrCreate('freelancer_client');
    }

    public function test_it_lists_the_companys_invoices(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Acme']);
        SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'draft']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceIndex::class, ['company' => $company])
            ->assertSee('Acme');
    }

    public function test_status_filter_narrows_the_list(): void
    {
        $company = Company::factory()->create();
        $draftPartner = Partner::factory()->for($company)->create(['name' => 'Draft Customer']);
        $confirmedPartner = Partner::factory()->for($company)->create(['name' => 'Confirmed Customer']);
        SalesInvoice::factory()->for($company)->create(['partner_id' => $draftPartner->id, 'status' => 'draft']);
        SalesInvoice::factory()->for($company)->create(['partner_id' => $confirmedPartner->id, 'status' => 'confirmed', 'fiscal_year' => 2026, 'invoice_number' => 1]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceIndex::class, ['company' => $company])
            ->set('statusFilter', 'confirmed')
            ->assertSee('Confirmed Customer')
            ->assertDontSee('Draft Customer');
    }

    public function test_the_index_page_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('sales-invoices.index', $company))
            ->assertOk();
    }

    public function test_the_invoice_table_has_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(SalesInvoiceIndex::class, ['company' => $company])
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
    }

    public function test_a_euro_invoice_shows_eur_not_denari_in_the_list(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Acme']);
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'currency' => 'EUR',
            'exchange_rate' => '61.500000',
        ]);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '0']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $html = Livewire::test(SalesInvoiceIndex::class, ['company' => $company])->html();

        $this->assertStringContainsString('1.000,00 EUR', $html);
        $this->assertStringNotContainsString('1.000,00 ден', $html);
    }

    public function test_it_only_lists_invoices_from_the_working_year(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $partnerNow = Partner::factory()->for($company)->create(['name' => 'Купувач СЕГА']);
        $partnerOld = Partner::factory()->for($company)->create(['name' => 'Купувач 2024']);
        SalesInvoice::factory()->for($company)->for($partnerNow)->create(['invoice_date' => now()->toDateString()]);
        SalesInvoice::factory()->for($company)->for($partnerOld)->create(['invoice_date' => '2024-04-04']);

        $this->actingAs($admin);

        Livewire::test(SalesInvoiceIndex::class, ['company' => $company])
            ->assertSee('Купувач СЕГА')
            ->assertDontSee('Купувач 2024');
    }

    public function test_a_draft_invoice_stays_visible_even_though_it_has_no_fiscal_year(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $partner = Partner::factory()->for($company)->create(['name' => 'Купувач ДОО']);
        $draft = SalesInvoice::factory()->for($company)->for($partner)->create([
            'invoice_date' => now()->toDateString(),
            'status' => 'draft',
            'fiscal_year' => null,
        ]);

        $this->actingAs($admin);

        Livewire::test(SalesInvoiceIndex::class, ['company' => $company])
            ->assertSee('Купувач ДОО');

        $this->assertNull($draft->fresh()->fiscal_year);
    }

    public function test_an_empty_year_says_so_instead_of_saying_there_is_no_data(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        // Фирмата има фактури, но не во работната година — тогаш се гледа табелата со порака,
        // не празната состојба за нова фирма.
        SalesInvoice::factory()->for($company)->create(['invoice_date' => '2019-04-04']);

        $this->actingAs($admin);

        Livewire::test(SalesInvoiceIndex::class, ['company' => $company])
            ->assertSee('Нема записи за '.now()->year.' — провери дали работиш во вистинската година');
    }

    public function test_a_company_with_no_invoices_at_all_sees_the_empty_state_and_no_table(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceIndex::class, ['company' => $company])
            ->assertSee('Време е да ви платат!')
            ->assertSeeHtml(route('sales-invoices.create', $company))
            ->assertDontSee('<table', false);
    }

    public function test_the_table_shows_the_order_number_due_date_and_balance_due(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $partner = Partner::factory()->for($company)->create(['name' => 'Купувач Т']);
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(), 'order_number' => 'ПФ-2026/7',
            'invoice_number_formatted' => 'ФК-9',
        ]);
        $invoice->lines()->create(['description' => 'А', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);
        SalesInvoicePayment::factory()->create(['sales_invoice_id' => $invoice->id, 'amount' => '180.00']);
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceIndex::class, ['company' => $company])
            ->assertSee('ПФ-2026/7')
            ->assertSee('ФК-9')
            ->assertSee('1.180,00 ден')
            ->assertSee('1.000,00 ден')          // за наплата: 1180 − 180
            ->assertSee('Делумно платена')
            ->assertSeeHtml(route('sales-invoices.show', [$company, $invoice]));
    }

    public function test_the_payment_filters_split_paid_unpaid_and_overdue(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $partner = Partner::factory()->for($company)->create();
        $make = function (string $number, string $due, ?string $paid) use ($company, $partner) {
            $invoice = SalesInvoice::factory()->for($company)->create([
                'partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => now()->toDateString(),
                'due_date' => $due, 'invoice_number_formatted' => $number,
            ]);
            $invoice->lines()->create(['description' => 'А', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0.00', 'vat_treatment' => 'standard']);
            if ($paid) {
                SalesInvoicePayment::factory()->create(['sales_invoice_id' => $invoice->id, 'amount' => $paid]);
            }
        };
        $make('ПЛАТЕНА-1', now()->addDays(5)->toDateString(), '100.00');
        $make('ЧЕКА-2', now()->addDays(5)->toDateString(), null);
        $make('ДОСПЕАНА-3', now()->subDay()->toDateString(), null);
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceIndex::class, ['company' => $company])
            ->set('statusFilter', 'paid')
            ->assertSee('ПЛАТЕНА-1')->assertDontSee('ЧЕКА-2')->assertDontSee('ДОСПЕАНА-3')
            ->set('statusFilter', 'unpaid')
            ->assertSee('ЧЕКА-2')->assertSee('ДОСПЕАНА-3')->assertDontSee('ПЛАТЕНА-1')
            ->set('statusFilter', 'overdue')
            ->assertSee('ДОСПЕАНА-3')->assertDontSee('ЧЕКА-2')->assertDontSee('ПЛАТЕНА-1');
    }

    public function test_changing_the_working_year_reloads_the_list(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $partner = Partner::factory()->for($company)->create(['name' => 'Купувач 2024']);
        SalesInvoice::factory()->for($company)->for($partner)->create(['invoice_date' => '2024-04-04']);

        $this->actingAs($admin);

        Livewire::test(SalesInvoiceIndex::class, ['company' => $company])
            ->assertSee('Нема записи за '.now()->year)
            ->dispatch('working-year-changed', year: 2024)
            ->assertSet('workingYear', 2024)
            ->assertSee('Купувач 2024');
    }

    public function test_the_refresh_statuses_button_follows_the_sign_right_for_an_internal_client(): void
    {
        $own = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
        $ownClient = User::factory()->create(['company_id' => $own->id]);
        $ownClient->assignRole('internal_client');
        $firm = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $firmClient = User::factory()->create(['company_id' => $firm->id]);
        $firmClient->assignRole('internal_client');

        Livewire::actingAs($ownClient)
            ->test(SalesInvoiceIndex::class, ['company' => $own])
            ->assertSee('Освежи статуси');

        Livewire::actingAs($firmClient)
            ->test(SalesInvoiceIndex::class, ['company' => $firm])
            ->assertDontSee('Освежи статуси');
    }

    /** Свој режим + токен, но гледачот нема право да потпишува: контролите мора да се скриени. */
    public function test_the_refresh_button_is_hidden_for_a_freelancer_client_even_with_an_own_token(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('freelancer_client');

        Livewire::actingAs($client)
            ->test(SalesInvoiceIndex::class, ['company' => $company])
            ->assertDontSee('Освежи статуси');
    }

    public function test_the_pdf_fetch_control_is_hidden_for_a_freelancer_client_but_shown_to_an_internal_client(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
        $partner = Partner::factory()->for($company)->create();
        SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01',
            'efaktura_ujp_status_code' => '03', 'efaktura_ujp_status_name' => 'Прифатена',
        ]);
        $freelancer = User::factory()->create(['company_id' => $company->id]);
        $freelancer->assignRole('freelancer_client');
        $internal = User::factory()->create(['company_id' => $company->id]);
        $internal->assignRole('internal_client');

        Livewire::actingAs($internal)
            ->test(SalesInvoiceIndex::class, ['company' => $company])
            ->assertSee('Преземи ПДФ');

        Livewire::actingAs($freelancer)
            ->test(SalesInvoiceIndex::class, ['company' => $company])
            ->assertDontSee('Преземи ПДФ');
    }
}
