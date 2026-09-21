<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\WorkingYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
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

    public function test_selecting_an_item_prefills_the_unit_price_from_selling_price(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Widget', 'selling_price' => '249.99']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->call('selectItem', 0, (string) $item->id)
            ->assertSet('lines.0.unit_price', '249.99');
    }

    public function test_selecting_an_item_without_a_selling_price_leaves_unit_price_unchanged(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Widget', 'selling_price' => null]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('lines.0.unit_price', '5.00')
            ->call('selectItem', 0, (string) $item->id)
            ->assertSet('lines.0.unit_price', '5.00');
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
            ->set('lines.0.vat_rate', '18.00') // simulate a bypass: force a nonzero rate back in after the client-side zeroing, so only save()'s own server-side loop can be responsible for the persisted value
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sales_invoice_lines', [
            'description' => 'Export sale',
            'vat_treatment' => 'export',
            'vat_rate' => '0.00',
        ]);
    }

    public function test_a_service_item_line_saves_without_a_warehouse(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $service = Item::factory()->for($company)->service()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.item_id', (string) $service->id)
            ->set('lines.0.description', $service->name)
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '1000.00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sales_invoices', [
            'company_id' => $company->id,
            'warehouse_id' => null,
        ]);
    }

    public function test_a_product_item_line_still_demands_a_warehouse(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $product = Item::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.item_id', (string) $product->id)
            ->set('lines.0.description', $product->name)
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '1000.00')
            ->call('save')
            ->assertHasErrors(['warehouseId']);
    }

    public function test_typing_a_gross_price_fills_in_the_net_price_and_shows_what_it_rounds_to(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('lines.0.vat_rate', '18.00')
            ->set('lines.0.unit_price_gross', '100.00')
            ->assertSet('lines.0.unit_price', '84.75')
            ->assertSet('lines.0.unit_price_gross', '100.01');
    }

    public function test_typing_a_net_price_fills_in_the_gross_price(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('lines.0.vat_rate', '18.00')
            ->set('lines.0.unit_price', '100.00')
            ->assertSet('lines.0.unit_price_gross', '118.00');
    }

    public function test_a_non_standard_vat_treatment_drops_the_gross_price_to_the_net_price(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('lines.0.unit_price', '100.00')
            ->assertSet('lines.0.unit_price_gross', '118.00')
            ->call('setVatTreatment', 0, 'export')
            ->assertSet('lines.0.vat_rate', '0.00')
            ->assertSet('lines.0.unit_price_gross', '100.00');
    }

    public function test_the_line_totals_and_the_footer_add_up(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('lines.0.quantity', '3')
            ->set('lines.0.unit_price', '100.00')
            ->set('lines.0.vat_rate', '18.00')
            ->assertSee('300,00')
            ->assertSee('54,00')
            ->assertSee('354,00');
    }

    /**
     * Blade не ја препознава `@disabled(...)` распослана низ повеќе редови во
     * ознака на компонента — го испишува `<x-text-input>` како обичен текст и
     * полето исчезнува од екранот. Испорачано беше точно така.
     */
    public function test_every_blade_component_in_the_line_table_actually_compiles(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->call('setVatTreatment', 0, 'export')
            ->assertDontSee('<x-', false);
    }

    /**
     * `@foreach (... as $label)` во истиот фајл ја презапишуваше променливата
     * со класите на ознаките, па тие излегуваа со `class="Друго"`: видливи на
     * широк екран, како цел вишок ред над секоја ставка.
     */
    public function test_the_per_cell_labels_stay_hidden_on_a_wide_screen(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $html = Livewire::test(SalesInvoiceForm::class, ['company' => $company])->html();

        preg_match_all('/<span class="([^"]*)">Количина<\/span>/u', $html, $matches);

        $this->assertNotEmpty($matches[1], 'Ознаката „Количина" воопшто не се исцрта.');

        foreach ($matches[1] as $class) {
            $this->assertStringContainsString('md:hidden', $class);
        }
    }

    public function test_a_new_invoice_opens_dated_inside_the_working_year(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);
        WorkingYear::set($company, 2024);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->assertSet('invoiceDate', '2024-12-31')
            ->assertSet('dueDate', '2024-12-31');
    }
}
