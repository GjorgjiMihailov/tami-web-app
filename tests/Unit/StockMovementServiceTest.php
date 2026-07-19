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

    public function test_many_successive_receipts_at_the_same_unit_cost_do_not_drift_the_average(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $user = User::factory()->create();

        // Receipting a fractional quantity against the same unit cost, over
        // and over, forces the weighted-average recalculation to round on
        // every receipt (the quantity/cost precision doesn't divide evenly
        // at COST_SCALE). If the recalculation truncates instead of
        // rounding half-up when it collapses back to COST_SCALE, the stored
        // average creeps downward over repeated receipts even though the
        // true weighted average never changes. This exact combination
        // (qty 0.014 x 50 @ 33.3333) previously drifted the stored average
        // from 33.3333 down to 33.3332.
        for ($i = 0; $i < 50; $i++) {
            $this->service->receipt($item, $warehouse, '0.014', '33.3333', '2026-01-10', $user->id);
        }

        $level = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first();

        $this->assertSame('0.700', (string) $level->quantity_on_hand);
        $this->assertSame('33.3333', (string) $level->average_cost);
    }

    public function test_issue_decrements_quantity_at_current_average_cost(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouse, '10', '100.00', '2026-01-10', $user->id);
        $this->service->receipt($item, $warehouse, '5', '130.00', '2026-01-12', $user->id);
        $movement = $this->service->issue($item, $warehouse, '6', '2026-01-15', $user->id);

        $this->assertSame('issue', $movement->type);
        $this->assertSame('6.000', (string) $movement->quantity);
        $this->assertSame('110.0000', (string) $movement->unit_cost);

        $level = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame('9.000', (string) $level->quantity_on_hand);
        $this->assertSame('110.0000', (string) $level->average_cost);
    }

    public function test_issue_exceeding_quantity_on_hand_is_rejected(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouse, '10', '100.00', '2026-01-10', $user->id);

        $this->expectException(\App\Exceptions\InsufficientStockException::class);

        $this->service->issue($item, $warehouse, '11', '2026-01-15', $user->id);
    }

    public function test_issue_of_exactly_the_full_quantity_on_hand_succeeds(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouse, '10', '100.00', '2026-01-10', $user->id);

        $movement = $this->service->issue($item, $warehouse, '10', '2026-01-15', $user->id);

        $this->assertSame('issue', $movement->type);
        $this->assertSame('10.000', (string) $movement->quantity);

        $level = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame('0.000', (string) $level->quantity_on_hand);
        $this->assertSame('100.0000', (string) $level->average_cost);
    }
}
