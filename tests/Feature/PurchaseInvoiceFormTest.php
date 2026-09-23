<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\PurchaseInvoiceForm;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\WorkingYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoiceFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('internal_client');
    }

    public function test_it_creates_a_draft_purchase_invoice_with_an_expense_line(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $account = Account::where('company_id', $company->id)->where('code', '462')->first();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'SUP-2026-045')
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.account_id', (string) $account->id)
            ->set('lines.0.description', 'Office rent')
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '500.00')
            ->set('lines.0.vat_rate', '18.00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('purchase_invoices', [
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'supplier_invoice_number' => 'SUP-2026-045',
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('purchase_invoice_lines', [
            'account_id' => $account->id,
            'description' => 'Office rent',
        ]);
    }

    public function test_an_item_line_requires_no_account_but_a_non_item_line_does(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('supplierInvoiceNumber', 'SUP-2026-046')
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->call('selectItem', 0, (string) $item->id)
            ->set('lines.0.quantity', '5')
            ->set('lines.0.unit_price', '20.00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('purchase_invoice_lines', ['item_id' => $item->id, 'account_id' => null]);
    }

    public function test_a_non_item_line_without_an_account_is_rejected(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'SUP-2026-047')
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.description', 'Missing account')
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '50.00')
            ->call('save')
            ->assertHasErrors(['lines.0.account_id']);
    }

    public function test_a_line_account_from_another_company_is_rejected(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $otherCompanyAccount = Account::where('company_id', $otherCompany->id)->where('code', '462')->first();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'SUP-2026-049')
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.account_id', (string) $otherCompanyAccount->id)
            ->set('lines.0.description', 'Cross-company line')
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '50.00')
            ->call('save')
            ->assertHasErrors(['lines.0.account_id']);
    }

    public function test_duplicate_supplier_invoice_number_for_the_same_partner_is_rejected(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $account = Account::where('company_id', $company->id)->where('code', '462')->first();
        PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'supplier_invoice_number' => 'DUP-1']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'DUP-1')
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.account_id', (string) $account->id)
            ->set('lines.0.description', 'Line')
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '10.00')
            ->call('save')
            ->assertHasErrors(['supplierInvoiceNumber']);
    }

    public function test_client_can_create_a_purchase_invoice_for_their_own_company(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $account = Account::where('company_id', $company->id)->where('code', '462')->first();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');
        $this->actingAs($client);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'SUP-2026-048')
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.account_id', (string) $account->id)
            ->set('lines.0.description', 'Line')
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '10.00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('purchase_invoices', ['company_id' => $company->id, 'supplier_invoice_number' => 'SUP-2026-048']);
    }

    public function test_both_products_and_services_appear_in_the_item_picker(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['name' => 'Physical Widget']);
        Item::factory()->for($company)->service()->create(['name' => 'Consulting Hour']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->assertSee('Physical Widget')
            ->assertSee('Consulting Hour');
    }

    public function test_a_service_item_line_saves_without_a_warehouse(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $account = Account::where('company_id', $company->id)->where('code', '462')->first();
        $service = Item::factory()->for($company)->service()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'SUP-2026-050')
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.item_id', (string) $service->id)
            ->set('lines.0.account_id', (string) $account->id)
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '10.00')
            ->set('lines.0.vat_rate', '18.00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('purchase_invoices', [
            'supplier_invoice_number' => 'SUP-2026-050',
            'warehouse_id' => null,
        ]);
        $this->assertDatabaseHas('purchase_invoice_lines', [
            'item_id' => $service->id,
            'account_id' => $account->id,
        ]);
    }

    public function test_a_service_item_line_still_needs_an_expense_account(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $service = Item::factory()->for($company)->service()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'SUP-2026-051')
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.item_id', (string) $service->id)
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '10.00')
            ->set('lines.0.vat_rate', '18.00')
            ->call('save')
            ->assertHasErrors(['lines.0.account_id']);
    }

    public function test_a_product_item_line_still_demands_a_warehouse(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $product = Item::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'SUP-2026-052')
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.item_id', (string) $product->id)
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '10.00')
            ->set('lines.0.vat_rate', '18.00')
            ->call('save')
            ->assertHasErrors(['warehouseId']);
    }

    public function test_typing_a_net_price_fills_in_the_gross_price(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('lines.0.vat_rate', '18.00')
            ->set('lines.0.unit_price', '100.00')
            ->assertSet('lines.0.unit_price_gross', '118.00');
    }

    public function test_typing_a_gross_price_never_rewrites_what_was_typed(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('lines.0.vat_rate', '18.00')
            ->set('lines.0.unit_price_gross', '100.00')
            ->assertSet('lines.0.unit_price_gross', '100.00')
            ->assertSet('lines.0.price_basis', 'gross')
            ->assertSet('lines.0.unit_price', '84.75');
    }

    public function test_a_gross_entered_line_totals_exactly_what_was_typed(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('lines.0.quantity', '6')
            ->set('lines.0.vat_rate', '18.00')
            ->set('lines.0.unit_price_gross', '6.00')
            ->assertSee('36,00')   // вкупно со ДДВ, не 35,97
            ->assertSee('30,51')   // основица
            ->assertSee('5,49');   // ДДВ
    }

    public function test_changing_the_rate_on_a_gross_line_keeps_the_gross_price_and_moves_the_net(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('lines.0.unit_price_gross', '105.00')
            ->set('lines.0.vat_rate', '5.00')
            ->assertSet('lines.0.unit_price_gross', '105.00')
            ->assertSet('lines.0.unit_price', '100.00');
    }

    public function test_changing_the_vat_rate_refreshes_the_gross_price(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('lines.0.unit_price', '200.00')
            ->set('lines.0.vat_rate', '5.00')
            ->assertSet('lines.0.unit_price_gross', '210.00');
    }

    public function test_the_line_totals_and_the_footer_add_up(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('lines.0.quantity', '3')
            ->set('lines.0.unit_price', '100.00')
            ->set('lines.0.vat_rate', '18.00')
            ->assertSee('300,00')   // вкупно без ДДВ
            ->assertSee('54,00')    // износ на ДДВ
            ->assertSee('354,00');  // вкупно со ДДВ
    }

    public function test_a_needs_review_line_keeps_its_flag_after_an_unrelated_save(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $account = Account::where('company_id', $company->id)->where('code', '462')->first();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);
        $invoice->lines()->create([
            'account_id' => $account->id,
            'description' => 'Unmapped VAT code line',
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'vat_rate' => '18.00',
            'vat_deductible' => true,
            'needs_review' => true,
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company, 'purchaseInvoice' => $invoice])
            ->set('supplierInvoiceNumber', $invoice->supplier_invoice_number)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('purchase_invoice_lines', [
            'purchase_invoice_id' => $invoice->id,
            'description' => 'Unmapped VAT code line',
            'needs_review' => true,
        ]);
    }

    /** Види го истоимениот тест кај излезните фактури — таму беше испорачано расипано. */
    public function test_every_blade_component_in_the_line_table_actually_compiles(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->assertDontSee('<x-', false);
    }

    public function test_the_per_cell_labels_stay_hidden_on_a_wide_screen(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $html = Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])->html();

        preg_match_all('/<span class="([^"]*)">Количина<\/span>/u', $html, $matches);

        $this->assertNotEmpty($matches[1], 'Ознаката „Количина" воопшто не се исцрта.');

        foreach ($matches[1] as $class) {
            $this->assertStringContainsString('md:hidden', $class);
        }
    }

    public function test_a_new_purchase_invoice_opens_dated_inside_the_working_year(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);
        WorkingYear::set($company, 2024);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->assertSet('invoiceDate', '2024-12-31')
            ->assertSet('dueDate', '2024-12-31');
    }
}
