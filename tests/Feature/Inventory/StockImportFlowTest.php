<?php

namespace Tests\Feature\Inventory;

use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockImportFlowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Item $item;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create(['is_vat_registered' => true]);
        $this->item = Item::factory()->for($this->company)->create(['type' => 'product']);
        $this->warehouse = Warehouse::factory()->for($this->company)->create();
    }

    private function purchase(bool $import, string $quantity = '10', string $price = '50.00'): PurchaseInvoice
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = PurchaseInvoice::factory()->for($this->company)->create([
            'partner_id' => $partner->id, 'warehouse_id' => $this->warehouse->id, 'invoice_date' => '2026-03-01', 'is_import' => $import,
        ]);
        $invoice->lines()->create(['item_id' => $this->item->id, 'description' => 'Стока', 'quantity' => $quantity, 'unit_price' => $price, 'vat_rate' => '18.00']);

        return app(PurchaseInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);
    }

    private function level(): StockLevel
    {
        return StockLevel::where('item_id', $this->item->id)->where('warehouse_id', $this->warehouse->id)->firstOrFail();
    }

    public function test_an_import_purchase_adds_import_value_and_a_domestic_one_does_not(): void
    {
        $this->purchase(false);
        $this->assertSame('0.000000', (string) $this->level()->import_value);

        $this->purchase(true);
        $this->assertSame('500.000000', (string) $this->level()->import_value);
    }

    public function test_deleting_the_import_purchase_takes_back_exactly_its_import_value(): void
    {
        $this->purchase(false);
        $import = $this->purchase(true);

        app(PurchaseInvoiceService::class)->delete($import->fresh(), User::factory()->create()->id);

        $this->assertSame('0.000000', (string) $this->level()->import_value);
        $this->assertSame('10.000', (string) $this->level()->quantity_on_hand);
    }

    public function test_deleting_a_sale_gives_the_import_part_back(): void
    {
        $this->purchase(false);
        $this->purchase(true);
        $partner = Partner::factory()->for($this->company)->create();
        $sale = SalesInvoice::factory()->for($this->company)->create(['partner_id' => $partner->id, 'warehouse_id' => $this->warehouse->id, 'invoice_date' => '2026-03-05']);
        $sale->lines()->create(['item_id' => $this->item->id, 'description' => 'Стока', 'quantity' => '10', 'unit_price' => '100.00', 'vat_rate' => '18.00']);
        $confirmed = app(SalesInvoiceService::class)->confirm($sale->fresh(), User::factory()->create()->id);

        // 20 парчиња, вредност 1000, увоз 500 → 10 продадени = 500 од кои 250 од увоз
        $this->assertSame('250.000000', (string) $this->level()->import_value);

        app(SalesInvoiceService::class)->delete($confirmed->fresh(), User::factory()->create()->id);

        $this->assertSame('500.000000', (string) $this->level()->import_value);
    }

    public function test_a_manual_receipt_can_be_marked_as_from_import(): void
    {
        app(StockMovementService::class)->receipt($this->item, $this->warehouse, '4', '25.00', '2026-03-01', User::factory()->create()->id, bcmul('4', '25.00', 6));

        $this->assertSame('100.000000', (string) $this->level()->import_value);
    }
}
