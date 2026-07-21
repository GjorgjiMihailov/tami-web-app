<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoicingRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_all_invoicing_routes_render_successfully_for_an_admin(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->for($company)->create();
        Warehouse::factory()->for($company)->create();
        Item::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('partners.index', $company))->assertOk();
        $this->get(route('sales-invoices.index', $company))->assertOk();
        $this->get(route('sales-invoices.create', $company))->assertOk();
    }

    public function test_invoicing_routes_require_authentication(): void
    {
        $company = Company::factory()->create();

        $this->get(route('partners.index', $company))->assertRedirect(route('login'));
        $this->get(route('sales-invoices.index', $company))->assertRedirect(route('login'));
    }

    public function test_purchase_invoice_index_and_create_routes_render_successfully_for_an_admin(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = \App\Models\PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('purchase-invoices.index', $company))->assertOk();
        $this->get(route('purchase-invoices.create', $company))->assertOk();
        $this->get(route('purchase-invoices.show', [$company, $invoice]))->assertOk();
    }
}
