<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockMovementServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockMovementService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = app(StockMovementService::class);
    }

    public function test_first_receipt_sets_quantity_and_average_cost(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $user = User::factory()->create();

        $movement = $this->service->receipt($item, $warehouse, '10', '100.00', '2026-01-10', $user->id);

        $this->assertSame('receipt', $movement->type);
        $this->assertSame('10.000', (string) $movement->quantity);
        $this->assertSame('100.0000', (string) $movement->unit_cost);

        $level = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame('10.000', (string) $level->quantity_on_hand);
        $this->assertSame('100.0000', (string) $level->average_cost);
    }

    public function test_second_receipt_at_a_different_cost_recalculates_weighted_average(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouse, '10', '100.00', '2026-01-10', $user->id);
        $this->service->receipt($item, $warehouse, '5', '130.00', '2026-01-12', $user->id);

        $level = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first();

        // ((10 * 100) + (5 * 130)) / 15 = 110.00
        $this->assertSame('15.000', (string) $level->quantity_on_hand);
        $this->assertSame('110.0000', (string) $level->average_cost);
    }

    public function test_receipts_for_the_same_item_in_different_warehouses_are_independent(): void
    {
        $item = Item::factory()->create();
        $warehouseA = Warehouse::factory()->create();
        $warehouseB = Warehouse::factory()->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouseA, '10', '100.00', '2026-01-10', $user->id);
        $this->service->receipt($item, $warehouseB, '20', '50.00', '2026-01-10', $user->id);

        $levelA = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouseA->id)->first();
        $levelB = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouseB->id)->first();

        $this->assertSame('100.0000', (string) $levelA->average_cost);
        $this->assertSame('50.0000', (string) $levelB->average_cost);
    }
}
