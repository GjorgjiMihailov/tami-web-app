<?php

namespace Tests\Unit;

use App\Models\Company;
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
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
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
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
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
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouseA = Warehouse::factory()->for($company)->create();
        $warehouseB = Warehouse::factory()->for($company)->create();
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
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
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
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
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
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouse, '10', '100.00', '2026-01-10', $user->id);

        $this->expectException(\App\Exceptions\InsufficientStockException::class);

        $this->service->issue($item, $warehouse, '11', '2026-01-15', $user->id);
    }

    public function test_issue_of_exactly_the_full_quantity_on_hand_succeeds(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouse, '10', '100.00', '2026-01-10', $user->id);

        $movement = $this->service->issue($item, $warehouse, '10', '2026-01-15', $user->id);

        $this->assertSame('issue', $movement->type);
        $this->assertSame('10.000', (string) $movement->quantity);

        $level = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame('0.000', (string) $level->quantity_on_hand);
        $this->assertSame('100.0000', (string) $level->average_cost);
    }

    public function test_transfer_moves_quantity_and_carries_source_cost_into_destination(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouseA = Warehouse::factory()->for($company)->create();
        $warehouseB = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouseA, '10', '100.00', '2026-01-10', $user->id);
        $this->service->receipt($item, $warehouseA, '5', '130.00', '2026-01-12', $user->id);
        // Warehouse A is now 15 units @ 110.00 average.

        $movement = $this->service->transfer($item, $warehouseA, $warehouseB, '5', '2026-01-15', $user->id);

        $this->assertSame('transfer', $movement->type);
        $this->assertSame($warehouseA->id, $movement->warehouse_id);
        $this->assertSame($warehouseB->id, $movement->to_warehouse_id);
        $this->assertSame('110.0000', (string) $movement->unit_cost);

        $levelA = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouseA->id)->first();
        $levelB = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouseB->id)->first();

        $this->assertSame('10.000', (string) $levelA->quantity_on_hand);
        $this->assertSame('110.0000', (string) $levelA->average_cost);
        $this->assertSame('5.000', (string) $levelB->quantity_on_hand);
        $this->assertSame('110.0000', (string) $levelB->average_cost);
    }

    public function test_transfer_into_a_warehouse_with_existing_stock_recalculates_its_average(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouseA = Warehouse::factory()->for($company)->create();
        $warehouseB = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouseA, '10', '120.00', '2026-01-10', $user->id);
        $this->service->receipt($item, $warehouseB, '10', '80.00', '2026-01-10', $user->id);

        $this->service->transfer($item, $warehouseA, $warehouseB, '10', '2026-01-15', $user->id);

        // Warehouse B: ((10 * 80) + (10 * 120)) / 20 = 100.00
        $levelB = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouseB->id)->first();
        $this->assertSame('20.000', (string) $levelB->quantity_on_hand);
        $this->assertSame('100.0000', (string) $levelB->average_cost);
    }

    public function test_transfer_from_higher_id_warehouse_to_lower_id_warehouse_uses_correct_source_and_destination(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        // Create warehouseB before warehouseA so warehouseA ends up with the
        // higher id. Transferring from A (higher id) to B (lower id)
        // exercises the fromWarehouse->id > toWarehouse->id branch of the
        // fixed ascending-id lock ordering in StockMovementService::transfer(),
        // which every other transfer test in this file leaves uncovered.
        $warehouseB = Warehouse::factory()->for($company)->create();
        $warehouseA = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();

        $this->assertGreaterThan($warehouseB->id, $warehouseA->id);

        $this->service->receipt($item, $warehouseA, '10', '120.00', '2026-01-10', $user->id);
        $this->service->receipt($item, $warehouseB, '10', '80.00', '2026-01-10', $user->id);

        $movement = $this->service->transfer($item, $warehouseA, $warehouseB, '10', '2026-01-15', $user->id);

        $this->assertSame('transfer', $movement->type);
        $this->assertSame($warehouseA->id, $movement->warehouse_id);
        $this->assertSame($warehouseB->id, $movement->to_warehouse_id);
        $this->assertSame('120.0000', (string) $movement->unit_cost);

        $levelA = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouseA->id)->first();
        $levelB = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouseB->id)->first();

        $this->assertSame('0.000', (string) $levelA->quantity_on_hand);
        $this->assertSame('120.0000', (string) $levelA->average_cost);

        // Warehouse B: ((10 * 80) + (10 * 120)) / 20 = 100.00
        $this->assertSame('20.000', (string) $levelB->quantity_on_hand);
        $this->assertSame('100.0000', (string) $levelB->average_cost);
    }

    public function test_transfer_exceeding_source_quantity_is_rejected(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouseA = Warehouse::factory()->for($company)->create();
        $warehouseB = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouseA, '5', '100.00', '2026-01-10', $user->id);

        $this->expectException(\App\Exceptions\InsufficientStockException::class);

        $this->service->transfer($item, $warehouseA, $warehouseB, '6', '2026-01-15', $user->id);
    }

    public function test_transfer_to_the_same_warehouse_is_rejected(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouse, '5', '100.00', '2026-01-10', $user->id);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->transfer($item, $warehouse, $warehouse, '1', '2026-01-15', $user->id);
    }

    public function test_positive_adjustment_increases_quantity_without_changing_average_cost(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouse, '10', '100.00', '2026-01-10', $user->id);
        $movement = $this->service->adjustment($item, $warehouse, '5', 'Physical count correction', '2026-01-20', $user->id);

        $this->assertSame('adjustment', $movement->type);
        $this->assertSame('5.000', (string) $movement->quantity);
        $this->assertSame('100.0000', (string) $movement->unit_cost);
        $this->assertSame('Physical count correction', $movement->reason);

        $level = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame('15.000', (string) $level->quantity_on_hand);
        $this->assertSame('100.0000', (string) $level->average_cost);
    }

    public function test_negative_adjustment_decreases_quantity_without_changing_average_cost(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouse, '10', '100.00', '2026-01-10', $user->id);
        $movement = $this->service->adjustment($item, $warehouse, '-3', 'Damaged goods', '2026-01-20', $user->id);

        $this->assertSame('-3.000', (string) $movement->quantity);

        $level = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame('7.000', (string) $level->quantity_on_hand);
        $this->assertSame('100.0000', (string) $level->average_cost);
    }

    public function test_negative_adjustment_exceeding_quantity_on_hand_is_rejected(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouse, '5', '100.00', '2026-01-10', $user->id);

        $this->expectException(\App\Exceptions\InsufficientStockException::class);

        $this->service->adjustment($item, $warehouse, '-6', 'Miscount', '2026-01-20', $user->id);
    }

    public function test_receipt_rejects_item_and_warehouse_from_different_companies(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $item = Item::factory()->for($companyA)->create();
        $warehouse = Warehouse::factory()->for($companyB)->create();
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->receipt($item, $warehouse, '10', '100.00', '2026-01-10', $user->id);
    }

    public function test_issue_rejects_item_and_warehouse_from_different_companies(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $item = Item::factory()->for($companyA)->create();
        $warehouse = Warehouse::factory()->for($companyB)->create();
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->issue($item, $warehouse, '1', '2026-01-15', $user->id);
    }

    public function test_adjustment_rejects_item_and_warehouse_from_different_companies(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $item = Item::factory()->for($companyA)->create();
        $warehouse = Warehouse::factory()->for($companyB)->create();
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->adjustment($item, $warehouse, '1', 'Physical count correction', '2026-01-20', $user->id);
    }

    public function test_transfer_rejects_a_from_warehouse_in_a_different_company_than_the_item(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $item = Item::factory()->for($companyA)->create();
        $fromWarehouse = Warehouse::factory()->for($companyB)->create();
        $toWarehouse = Warehouse::factory()->for($companyA)->create();
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->transfer($item, $fromWarehouse, $toWarehouse, '1', '2026-01-15', $user->id);
    }

    public function test_transfer_rejects_a_to_warehouse_in_a_different_company_than_the_item(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $item = Item::factory()->for($companyA)->create();
        $fromWarehouse = Warehouse::factory()->for($companyA)->create();
        $toWarehouse = Warehouse::factory()->for($companyB)->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $fromWarehouse, '5', '100.00', '2026-01-10', $user->id);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->transfer($item, $fromWarehouse, $toWarehouse, '1', '2026-01-15', $user->id);
    }
}
