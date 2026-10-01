<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\PurchaseInvoiceForm;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoiceImportFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function actingAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);

        return $user;
    }

    public function test_import_checkbox_is_hidden_until_a_line_has_a_stock_item(): void
    {
        $company = Company::factory()->create();
        $this->actingAdmin();
        Partner::factory()->for($company)->create();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->assertDontSee('Фактура од увоз');
    }

    public function test_checking_import_reveals_the_import_section_and_fields_can_be_edited(): void
    {
        $company = Company::factory()->create();
        $this->actingAdmin();
        Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('warehouseId', (string) $warehouse->id)
            ->call('selectItem', 0, (string) $item->id)
            ->assertSee('Фактура од увоз')
            ->set('isImport', true)
            ->assertSee('Увоз')
            ->call('addImportCost')
            ->assertCount('importCosts', 1)
            ->call('addTariffLine')
            ->assertCount('tariffLines', 1)
            ->set('importCosts.0.payee_name', 'Шпедитер ДОО')
            ->set('importCosts.0.base_amount', '70.00')
            ->set('tariffLines.0.tariff_code', '12345678')
            ->set('tariffLines.0.customs_duty', '30.00')
            ->call('removeTariffLine', 0)
            ->assertCount('tariffLines', 0);
    }

    public function test_fetch_import_rate_fills_the_exchange_rate_field_from_nbrm(): void
    {
        Http::fake([
            'nbrm.mk/*' => Http::response([
                ['oznaka' => 'EUR', 'sreden' => 61.6917, 'nomin' => 1, 'datum' => '2026-09-15T00:00:00'],
            ], 200),
        ]);

        $company = Company::factory()->create();
        $this->actingAdmin();
        Partner::factory()->for($company)->create();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('isImport', true)
            ->set('importDate', '2026-09-15')
            ->set('importCurrencyCode', 'EUR')
            ->call('fetchImportRate')
            ->assertSet('importExchangeRate', '61.6917');
    }

    public function test_saving_an_import_invoice_persists_cost_and_tariff_rows(): void
    {
        $company = Company::factory()->create();
        $this->actingAdmin();
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'INV-1')
            ->set('invoiceDate', '2026-09-15')
            ->set('dueDate', '2026-09-30')
            ->set('warehouseId', (string) $warehouse->id)
            ->call('selectItem', 0, (string) $item->id)
            ->set('lines.0.quantity', '10')
            ->set('lines.0.unit_price', '50.00')
            ->set('isImport', true)
            ->set('customsDeclarationNumber', '26MKIM1013000')
            ->call('addImportCost')
            ->set('importCosts.0.payee_name', 'Шпедитер ДОО')
            ->set('importCosts.0.base_amount', '70.00')
            ->call('save');

        $invoice = PurchaseInvoice::where('company_id', $company->id)->firstOrFail();

        $this->assertTrue((bool) $invoice->is_import);
        $this->assertSame('26MKIM1013000', $invoice->customs_declaration_number);
        $this->assertSame(1, $invoice->importCosts()->count());
        $this->assertSame('Шпедитер ДОО', $invoice->importCosts()->first()->payee_name);
    }

    public function test_an_import_cost_row_with_only_reference_number_filled_is_still_persisted(): void
    {
        $company = Company::factory()->create();
        $this->actingAdmin();
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'INV-2')
            ->set('invoiceDate', '2026-09-15')
            ->set('dueDate', '2026-09-30')
            ->set('warehouseId', (string) $warehouse->id)
            ->call('selectItem', 0, (string) $item->id)
            ->set('lines.0.quantity', '10')
            ->set('lines.0.unit_price', '50.00')
            ->set('isImport', true)
            ->call('addImportCost')
            // payee_name остана празно, base_amount остана на стандардното '0' —
            // само reference_number е впишан. Редот не смее тивко да се отфрли.
            ->set('importCosts.0.reference_number', 'REF-123')
            ->call('addTariffLine')
            // tariff_code остана празно, customs_duty остана на стандардното '0' —
            // само foreign_amount е впишан.
            ->set('tariffLines.0.foreign_amount', '15.50')
            ->call('save');

        $invoice = PurchaseInvoice::where('company_id', $company->id)->firstOrFail();

        $this->assertSame(1, $invoice->importCosts()->count());
        $cost = $invoice->importCosts()->first();
        $this->assertSame('REF-123', $cost->reference_number);
        $this->assertSame('—', $cost->payee_name);

        $this->assertSame(1, $invoice->tariffLines()->count());
        $tariff = $invoice->tariffLines()->first();
        $this->assertSame('15.50', $tariff->foreign_amount);
        $this->assertSame('—', $tariff->tariff_code);
    }

    public function test_a_fully_blank_import_cost_and_tariff_row_is_still_skipped(): void
    {
        $company = Company::factory()->create();
        $this->actingAdmin();
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'INV-3')
            ->set('invoiceDate', '2026-09-15')
            ->set('dueDate', '2026-09-30')
            ->set('warehouseId', (string) $warehouse->id)
            ->call('selectItem', 0, (string) $item->id)
            ->set('lines.0.quantity', '10')
            ->set('lines.0.unit_price', '50.00')
            ->set('isImport', true)
            ->call('addImportCost')
            ->call('addTariffLine')
            ->call('save');

        $invoice = PurchaseInvoice::where('company_id', $company->id)->firstOrFail();

        $this->assertSame(0, $invoice->importCosts()->count());
        $this->assertSame(0, $invoice->tariffLines()->count());
    }
}
