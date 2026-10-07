<?php

namespace Tests\Feature\Inventory;

use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockImportShareBackfill;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockImportBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reconstructs_the_import_share_from_the_movement_history(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['type' => 'product']);
        $warehouse = Warehouse::factory()->for($company)->create();
        $partner = Partner::factory()->for($company)->create();
        $service = app(StockMovementService::class);
        $userId = User::factory()->create()->id;

        // Домашен прием 10 × 10 и увозен прием 10 × 20 — како пред новата функција (без увозен дел).
        $service->receipt($item, $warehouse, '10', '10.00', '2026-03-01', $userId);
        $import = $service->receipt($item, $warehouse, '10', '20.00', '2026-03-02', $userId);
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'is_import' => true, 'status' => 'confirmed']);
        $invoice->lines()->create(['item_id' => $item->id, 'stock_movement_id' => $import->id, 'description' => 'Стока', 'quantity' => '10', 'unit_price' => '20.00', 'vat_rate' => '0']);
        $service->issue($item, $warehouse, '5', '2026-03-10', $userId);
        StockLevel::where('item_id', $item->id)->update(['import_value' => 0]);
        StockMovement::where('item_id', $item->id)->update(['import_value' => 0]);

        $result = app(StockImportShareBackfill::class)->run();

        $this->assertSame(['items' => 1, 'skipped' => 0], $result);
        $level = StockLevel::where('item_id', $item->id)->firstOrFail();
        $this->assertSame('150.000000', (string) $level->import_value); // 200 − 5×15×(200/300)=50
        $this->assertSame('200.000000', (string) StockMovement::findOrFail($import->id)->import_value);
    }

    public function test_an_item_whose_replay_does_not_match_the_level_is_skipped_untouched(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['type' => 'product']);
        $warehouse = Warehouse::factory()->for($company)->create();
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '10.00', '2026-03-01', User::factory()->create()->id);
        StockLevel::where('item_id', $item->id)->update(['quantity_on_hand' => '7']); // ручно расипано салдо

        $result = app(StockImportShareBackfill::class)->run();

        $this->assertSame(['items' => 0, 'skipped' => 1], $result);
        $this->assertSame('0.000000', (string) StockLevel::where('item_id', $item->id)->first()->import_value);
    }
}
