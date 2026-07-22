<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceFormTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_creates_a_draft_invoice_with_a_free_text_line(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.description', 'Consulting services')
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_price', '500.00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sales_invoices', ['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'draft']);
        $this->assertDatabaseHas('sales_invoice_lines', ['description' => 'Consulting services', 'quantity' => '2.000', 'unit_price' => '500.00']);
    }

    public function test_selecting_an_item_prefills_description_and_vat_rate(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Widget', 'vat_rate' => '18.00']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->call('selectItem', 0, (string) $item->id)
            ->assertSet('lines.0.description', 'Widget')
            ->assertSet('lines.0.vat_rate', '18.00');
    }

    public function test_an_item_line_without_a_warehouse_is_rejected(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.item_id', (string) $item->id)
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '10.00')
            ->call('save')
            ->assertHasErrors(['warehouseId']);
    }

    public function test_a_confirmed_invoice_cannot_be_edited(): void
    {
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['status' => 'confirmed']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertForbidden();
    }

    public function test_client_can_create_a_draft_invoice_for_their_own_company(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.description', 'Service')
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '10.00')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_a_client_cannot_create_an_invoice_for_a_company_they_do_not_belong_to(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $companyA->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(SalesInvoiceForm::class, ['company' => $companyB])
            ->assertForbidden();
    }

    public function test_the_create_page_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('sales-invoices.create', $company))
            ->assertOk();
    }

    public function test_a_non_standard_treatment_forces_the_vat_rate_to_zero_even_if_submitted_nonzero(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.description', 'Export sale')
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '1000.00')
            ->set('lines.0.vat_rate', '18.00')
            ->call('setVatTreatment', 0, 'export')
            ->assertSet('lines.0.vat_rate', '0.00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sales_invoice_lines', [
            'description' => 'Export sale',
            'vat_treatment' => 'export',
            'vat_rate' => '0.00',
        ]);
    }
}
