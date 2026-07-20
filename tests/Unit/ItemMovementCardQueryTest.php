<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Item;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\ItemMovementCardQuery;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ItemMovementCardQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_movements_with_a_running_quantity(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();
        $service = app(StockMovementService::class);

        $service->receipt($item, $warehouse, '10', '50.00', '2026-01-05', $user->id);
        $service->issue($item, $warehouse, '4', '2026-01-10', $user->id);

        $rows = ItemMovementCardQuery::run($item, $warehouse, [
            'from' => Carbon::parse('2026-01-01'),
            'to' => Carbon::parse('2026-01-31'),
        ]);

        $this->assertCount(2, $rows);
        $this->assertSame(10.0, $rows[0]['quantity']);
        $this->assertSame(10.0, $rows[0]['running_quantity']);
        $this->assertSame(-4.0, $rows[1]['quantity']);
        $this->assertSame(6.0, $rows[1]['running_quantity']);
    }

    public function test_opening_quantity_before_the_range_carries_into_the_running_quantity(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();
        $service = app(StockMovementService::class);

        $service->receipt($item, $warehouse, '10', '50.00', '2025-12-15', $user->id);
        $service->receipt($item, $warehouse, '5', '50.00', '2026-01-10', $user->id);

        $rows = ItemMovementCardQuery::run($item, $warehouse, [
            'from' => Carbon::parse('2026-01-01'),
            'to' => Carbon::parse('2026-01-31'),
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(15.0, $rows[0]['running_quantity']);
    }

    public function test_a_transfer_shows_as_a_decrease_at_the_source_and_increase_at_the_destination(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouseA = Warehouse::factory()->for($company)->create();
        $warehouseB = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();
        $service = app(StockMovementService::class);

        $service->receipt($item, $warehouseA, '10', '50.00', '2026-01-05', $user->id);
        $service->transfer($item, $warehouseA, $warehouseB, '4', '2026-01-10', $user->id);

        $rowsA = ItemMovementCardQuery::run($item, $warehouseA, ['from' => Carbon::parse('2026-01-01'), 'to' => Carbon::parse('2026-01-31')]);
        $rowsB = ItemMovementCardQuery::run($item, $warehouseB, ['from' => Carbon::parse('2026-01-01'), 'to' => Carbon::parse('2026-01-31')]);

        $this->assertSame(6.0, $rowsA[1]['running_quantity']);
        $this->assertSame(4.0, $rowsB[0]['running_quantity']);
        $this->assertSame($warehouseB->name, $rowsA[1]['counterpart_warehouse']);
        $this->assertSame($warehouseA->name, $rowsB[0]['counterpart_warehouse']);
    }

    public function test_the_final_running_quantity_reconciles_with_the_stock_level(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();
        $service = app(StockMovementService::class);

        $service->receipt($item, $warehouse, '10', '50.00', '2026-01-05', $user->id);
        $service->issue($item, $warehouse, '3', '2026-01-10', $user->id);
        $service->adjustment($item, $warehouse, '-1', 'Damage', '2026-01-15', $user->id);

        $rows = ItemMovementCardQuery::run($item, $warehouse, ['from' => Carbon::parse('2026-01-01'), 'to' => Carbon::parse('2026-01-31')]);
        $level = StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first();

        $this->assertSame((float) $level->quantity_on_hand, $rows->last()['running_quantity']);
    }
}
