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

    public function setUp(): void
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
}
