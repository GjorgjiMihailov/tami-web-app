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

    /**
     * Final-review fix #1: comma decimal separator (МК habit) in an import
     * cost amount or a stock-line quantity used to throw
     * `ValueError: bcadd(): Argument #2 is not well-formed` straight out of
     * render(), because this feature's new bcmath calls skipped the
     * VatMath::number() normalization every other money field on this form
     * already goes through. Must not crash, and must actually parse the
     * comma — not silently fall back to 0.
     */
    public function test_comma_decimal_input_in_import_cost_and_quantity_does_not_crash_and_parses_correctly(): void
    {
        $company = Company::factory()->create();
        $this->actingAdmin();
        Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();

        $component = Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('warehouseId', (string) $warehouse->id)
            ->call('selectItem', 0, (string) $item->id)
            ->set('lines.0.quantity', '1,5')
            ->set('lines.0.unit_price', '10')
            ->set('isImport', true)
            ->call('addImportCost')
            ->set('importCosts.0.base_amount', '70,00');

        // No exception means render() survived the comma — now check the
        // comma was actually parsed, not just swallowed into zero.
        $this->assertSame('70.00', $component->viewData('importCostsBase'));
        $landedUnitCosts = $component->viewData('landedUnitCosts');
        $this->assertArrayHasKey('0', $landedUnitCosts);
        // net = 1.5 * 10 = 15.00; landed = 15.00 + 70.00 = 85.00; /1.5 = 56.6667.
        $this->assertSame('56.6667', $landedUnitCosts['0']);
    }

    /**
     * Final-review fix #2: bcadd() truncates at scale 2 while the
     * decimal(14,2) column rounds on save — so a value with more than 2
     * decimals (e.g. a paste from a foreign invoice) must be rounded
     * half-up BEFORE it is summed in the live preview, or the preview would
     * show a different total than what actually gets saved.
     */
    public function test_import_cost_total_rounds_half_up_instead_of_truncating(): void
    {
        $company = Company::factory()->create();
        $this->actingAdmin();
        Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();

        $component = Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('warehouseId', (string) $warehouse->id)
            ->call('selectItem', 0, (string) $item->id)
            ->set('isImport', true)
            ->call('addImportCost')
            ->set('importCosts.0.base_amount', '10.555');

        // bcadd truncation would give '10.55'; round-half-up gives '10.56'.
        $this->assertSame('10.56', $component->viewData('importCostsBase'));
    }

    /**
     * Final-review fix #4: the spec requires the per-line preview to update
     * immediately when a cost row changes, without clicking "Пресметај"
     * again. render() always recomputes from the in-memory arrays, so this
     * should already work — but it had zero test coverage of the actual
     * computed values.
     */
    public function test_landed_preview_updates_live_without_clicking_calculate_again(): void
    {
        $company = Company::factory()->create();
        $this->actingAdmin();
        Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('warehouseId', (string) $warehouse->id)
            ->call('selectItem', 0, (string) $item->id)
            ->set('lines.0.quantity', '10')
            ->set('lines.0.unit_price', '50')
            ->set('isImport', true)
            ->call('addImportCost')
            ->set('importCosts.0.payee_name', 'Шпедитер')
            ->set('importCosts.0.base_amount', '100')
            ->call('revealLandedPreview')
            // net 500.00 + 100.00 cost = 600.00 landed value.
            ->assertSeeHtml('600,00')
            // Changed WITHOUT calling revealLandedPreview again.
            ->set('importCosts.0.base_amount', '200')
            // net 500.00 + 200.00 cost = 700.00 landed value.
            ->assertSeeHtml('700,00')
            ->assertDontSeeHtml('600,00');
    }

    /**
     * Final-review fix #5: the Blade preview reconstructed the row's landed
     * value as `bcmul($landedUnit, $qty, 2)`, which TRUNCATES — so a 4-decimal
     * unit cost that doesn't divide the net value evenly can show a landed
     * value a cent off from net (and a nonsensical negative "Увоз (удел)"
     * even when there are no import costs at all).
     */
    public function test_landed_value_preview_rounds_half_up_instead_of_truncating(): void
    {
        $company = Company::factory()->create();
        $this->actingAdmin();
        Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();

        // qty 7 × unit price 14.2857 = net 100.00 (rounded), but the 4-decimal
        // landed unit cost (14.2857, since there are no import costs to
        // allocate) reconstructs to 99.9999 when multiplied back by 7 — the
        // old truncating bcmul showed 99.99; round-half-up correctly shows
        // 100.00, matching the actual net (no import cost → landed = net).
        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('warehouseId', (string) $warehouse->id)
            ->call('selectItem', 0, (string) $item->id)
            ->set('lines.0.quantity', '7')
            ->set('lines.0.vat_rate', '0')
            ->set('lines.0.unit_price', '14.2857')
            ->set('isImport', true)
            ->call('revealLandedPreview')
            ->assertSeeHtml('100,00')
            ->assertDontSeeHtml('99,99')
            ->assertDontSeeHtml('-0,01');
    }

    /** Final-review fix #6: the "Продажна" column was dropped from the preview table. */
    public function test_landed_preview_shows_selling_price_column(): void
    {
        $company = Company::factory()->create();
        $this->actingAdmin();
        Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create(['selling_price' => '123.45']);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('warehouseId', (string) $warehouse->id)
            ->call('selectItem', 0, (string) $item->id)
            ->set('lines.0.quantity', '10')
            ->set('lines.0.unit_price', '50')
            ->set('isImport', true)
            ->call('revealLandedPreview')
            ->assertSee('Продажна')
            ->assertSeeHtml('123,45');
    }

    /**
     * Final-review fix #7: unchecking "Фактура од увоз" after having filled
     * the ЕЦД/date/currency/rate fields in must null out all four together —
     * previously only the currency was cleared, leaving stale import data on
     * an invoice no longer marked as import.
     */
    public function test_unchecking_import_clears_all_four_import_fields(): void
    {
        $company = Company::factory()->create();
        $this->actingAdmin();
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'INV-4')
            ->set('invoiceDate', '2026-09-15')
            ->set('dueDate', '2026-09-30')
            ->set('warehouseId', (string) $warehouse->id)
            ->call('selectItem', 0, (string) $item->id)
            ->set('lines.0.quantity', '10')
            ->set('lines.0.unit_price', '50.00')
            ->set('isImport', true)
            ->set('customsDeclarationNumber', '26MKIM1013000')
            ->set('importDate', '2026-09-10')
            ->set('importCurrencyCode', 'EUR')
            ->set('importExchangeRate', '61.5')
            ->set('isImport', false)
            ->call('save');

        $invoice = PurchaseInvoice::where('company_id', $company->id)->firstOrFail();

        $this->assertFalse((bool) $invoice->is_import);
        $this->assertNull($invoice->customs_declaration_number);
        $this->assertNull($invoice->import_date);
        $this->assertNull($invoice->import_currency_code);
        $this->assertNull($invoice->import_exchange_rate);
    }
}
