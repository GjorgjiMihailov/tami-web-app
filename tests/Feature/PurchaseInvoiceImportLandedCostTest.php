<?php

namespace Tests\Feature;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Invoicing\PurchaseInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoiceImportLandedCostTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseInvoiceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PurchaseInvoiceService::class);
    }

    // Same helper as PurchaseInvoiceServiceTest — CompanyObserver already
    // seeds the full chart, firstOrCreate() keeps this idempotent.
    private function seedAccounts(Company $company): void
    {
        foreach ([
            ['code' => '130', 'name' => 'Input VAT'],
            ['code' => '220', 'name' => 'AP'],
            ['code' => '660', 'name' => 'Inventory Asset'],
        ] as $account) {
            Account::firstOrCreate(['company_id' => $company->id, 'code' => $account['code']], $account);
        }
    }

    public function test_import_invoice_receives_stock_at_landed_cost_but_books_the_raw_invoice_amount(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $itemA = Item::factory()->for($company)->create(['vat_rate' => '18.00']);
        $itemB = Item::factory()->for($company)->create(['vat_rate' => '18.00']);
        $user = User::factory()->create();

        $invoice = PurchaseInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'warehouse_id' => $warehouse->id,
            'invoice_date' => '2026-03-01',
            'is_import' => true,
        ]);
        $lineA = $invoice->lines()->create(['item_id' => $itemA->id, 'description' => 'A', 'quantity' => '10', 'unit_price' => '50.00', 'vat_rate' => '18.00']);
        $lineB = $invoice->lines()->create(['item_id' => $itemB->id, 'description' => 'B', 'quantity' => '5', 'unit_price' => '40.00', 'vat_rate' => '18.00']);

        $invoice->importCosts()->create(['payee_name' => 'Шпедитер', 'base_amount' => '70.00', 'vat_amount' => '12.60', 'sort_order' => 0]);
        $invoice->tariffLines()->create(['tariff_code' => '12345678', 'customs_duty' => '30.00', 'vat_amount' => '0', 'sort_order' => 0]);

        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        // Total net 700.00 (500 + 200), total import costs 100.00 (70 + 30).
        // A's share = round(100*500/700, 2) = 71.43; landed value 571.43 / 10.
        $levelA = StockLevel::where('item_id', $itemA->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame('57.1430', (string) $levelA->average_cost);
        $this->assertSame('10.000', (string) $levelA->quantity_on_hand);

        // B (last) absorbs the remainder: 100.00 - 71.43 = 28.57; landed value 228.57 / 5.
        $levelB = StockLevel::where('item_id', $itemB->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame('45.7140', (string) $levelB->average_cost);

        $movementA = StockMovement::where('item_id', $itemA->id)->where('type', 'receipt')->first();
        $this->assertSame('57.1430', (string) $movementA->unit_cost);

        // The journal entry still books only the raw invoice net amount —
        // import costs are deliberately not posted to the ledger yet.
        $entry = $confirmed->journalEntry()->with('lines.account')->first();
        $inventoryAsset = $entry->lines->firstWhere('account.code', '660');
        $this->assertSame('700.00', (string) $inventoryAsset->debit);
    }

    /**
     * Final-review fix #3: if every stock line on an import invoice has a
     * zero net value (free samples, warranty replacement) but there ARE
     * import costs/duty to allocate, LandedCostAllocator's zero-totalNet
     * branch would silently fall back to net/quantity = 0 for every line —
     * the import cost is dropped on the floor with no trace. Confirming must
     * refuse instead of silently losing the cost.
     */
    public function test_confirm_throws_when_only_stock_line_has_zero_value_but_import_costs_exist(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create(['vat_rate' => '18.00']);
        $user = User::factory()->create();

        $invoice = PurchaseInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'warehouse_id' => $warehouse->id,
            'invoice_date' => '2026-03-01',
            'is_import' => true,
        ]);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => 'Free sample', 'quantity' => '10', 'unit_price' => '0', 'vat_rate' => '18.00']);
        $invoice->importCosts()->create(['payee_name' => 'Шпедитер', 'base_amount' => '70.00', 'vat_amount' => '0', 'sort_order' => 0]);

        try {
            $this->service->confirm($invoice->fresh(), $user->id);
            $this->fail('Expected InvalidInvoiceStateException to be thrown.');
        } catch (InvalidInvoiceStateException $e) {
            $this->assertStringContainsString('не можат да се распределат', mb_strtolower($e->getMessage()));
        }

        // The transaction must have rolled back — no stock movement, draft still a draft.
        $this->assertSame(0, StockMovement::count());
        $this->assertSame('draft', $invoice->fresh()->status);
    }

    public function test_non_import_invoice_is_completely_unaffected(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create(['vat_rate' => '18.00']);
        $user = User::factory()->create();

        $invoice = PurchaseInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'warehouse_id' => $warehouse->id,
            'invoice_date' => '2026-03-01',
        ]);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => 'A', 'quantity' => '10', 'unit_price' => '50.00', 'vat_rate' => '18.00']);

        $this->service->confirm($invoice->fresh(), $user->id);

        $level = StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame('50.0000', (string) $level->average_cost);
    }
}
