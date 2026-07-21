<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\PurchaseInvoiceForm;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoiceFormTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
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
            ->set('sourceDocument', UploadedFile::fake()->create('bill.pdf', 50))
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

        $invoice = \App\Models\PurchaseInvoice::where('supplier_invoice_number', 'SUP-2026-045')->firstOrFail();
        $this->assertNotNull($invoice->source_document_path);
        Storage::disk('google')->assertExists($invoice->source_document_path);
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
        \App\Models\PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'supplier_invoice_number' => 'DUP-1']);
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
        $client->assignRole('client');
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
}
