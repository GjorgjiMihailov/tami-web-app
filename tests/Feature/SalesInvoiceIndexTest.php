<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\SalesInvoiceIndex;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
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

        $this->actingAs($admin);

        Livewire::test(SalesInvoiceIndex::class, ['company' => $company])
            ->assertSee('Нема записи за '.now()->year.' — провери дали работиш во вистинската година');
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
}
