<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockLevelQuery;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockLevelQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_on_hand_lists_rows_per_item_and_warehouse(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Widget']);
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', '2026-01-01', $user->id);

        $rows = StockLevelQuery::stockOnHand($company);

        $this->assertCount(1, $rows);
        $this->assertSame('Widget', $rows[0]['item_name']);
        $this->assertSame(10.0, $rows[0]['quantity_on_hand']);
        $this->assertSame(500.0, $rows[0]['value']);
    }

    public function test_stock_on_hand_can_be_filtered_by_warehouse(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouseA = Warehouse::factory()->for($company)->create();
        $warehouseB = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();
        app(StockMovementService::class)->receipt($item, $warehouseA, '10', '50.00', '2026-01-01', $user->id);
        app(StockMovementService::class)->receipt($item, $warehouseB, '20', '50.00', '2026-01-01', $user->id);

        $rows = StockLevelQuery::stockOnHand($company, $warehouseA->id);

        $this->assertCount(1, $rows);
        $this->assertSame(10.0, $rows[0]['quantity_on_hand']);
    }

    public function test_stock_on_hand_totals_sums_across_warehouses(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouseA = Warehouse::factory()->for($company)->create();
        $warehouseB = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();
        app(StockMovementService::class)->receipt($item, $warehouseA, '10', '50.00', '2026-01-01', $user->id);
        app(StockMovementService::class)->receipt($item, $warehouseB, '20', '50.00', '2026-01-01', $user->id);

        $rows = StockLevelQuery::stockOnHandTotals($company);

        $this->assertCount(1, $rows);
        $this->assertSame(30.0, $rows[0]['total_quantity']);
        $this->assertSame(1500.0, $rows[0]['total_value']);
    }
}
