<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Item;
use App\Models\StockLevel;
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

    public function test_stock_on_hand_and_totals_exclude_rows_whose_item_and_warehouse_belong_to_different_companies(): void
    {
        // A stock_levels row like this should never be created by
        // StockMovementService (it now guards against cross-company
        // item/warehouse pairs), but insert one directly to simulate "if
        // bad data somehow existed" and prove the query layer also excludes
        // it, for either company, as defense in depth.
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $item = Item::factory()->for($companyA)->create();
        $warehouse = Warehouse::factory()->for($companyB)->create();

        StockLevel::factory()->for($item, 'item')->for($warehouse, 'warehouse')->create([
            'quantity_on_hand' => '10.000',
            'average_cost' => '50.0000',
        ]);

        $this->assertCount(0, StockLevelQuery::stockOnHand($companyA));
        $this->assertCount(0, StockLevelQuery::stockOnHand($companyB));
        $this->assertCount(0, StockLevelQuery::stockOnHandTotals($companyA));
        $this->assertCount(0, StockLevelQuery::stockOnHandTotals($companyB));
    }

    public function test_valuation_summary_with_no_grouping_returns_a_single_total(): void
    {
        $company = Company::factory()->create();
        $itemA = Item::factory()->for($company)->create();
        $itemB = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();
        app(StockMovementService::class)->receipt($itemA, $warehouse, '10', '50.00', '2026-01-01', $user->id);
        app(StockMovementService::class)->receipt($itemB, $warehouse, '4', '25.00', '2026-01-01', $user->id);

        $rows = StockLevelQuery::valuationSummary($company);

        $this->assertCount(1, $rows);
        $this->assertSame('Total', $rows[0]['label']);
        $this->assertSame(600.0, $rows[0]['total_value']);
    }

    public function test_valuation_summary_can_be_grouped_by_warehouse(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouseA = Warehouse::factory()->for($company)->create(['name' => 'Main']);
        $warehouseB = Warehouse::factory()->for($company)->create(['name' => 'Annex']);
        $user = User::factory()->create();
        app(StockMovementService::class)->receipt($item, $warehouseA, '10', '50.00', '2026-01-01', $user->id);
        app(StockMovementService::class)->receipt($item, $warehouseB, '4', '25.00', '2026-01-01', $user->id);

        $rows = StockLevelQuery::valuationSummary($company, 'warehouse')->keyBy('label');

        $this->assertSame(500.0, $rows['Main']['total_value']);
        $this->assertSame(100.0, $rows['Annex']['total_value']);
    }

    public function test_valuation_summary_can_be_grouped_by_category(): void
    {
        $company = Company::factory()->create();
        $itemA = Item::factory()->for($company)->create(['category' => 'Raw materials']);
        $itemB = Item::factory()->for($company)->create(['category' => 'Finished goods']);
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();
        app(StockMovementService::class)->receipt($itemA, $warehouse, '10', '50.00', '2026-01-01', $user->id);
        app(StockMovementService::class)->receipt($itemB, $warehouse, '4', '25.00', '2026-01-01', $user->id);

        $rows = StockLevelQuery::valuationSummary($company, 'category')->keyBy('label');

        $this->assertSame(500.0, $rows['Raw materials']['total_value']);
        $this->assertSame(100.0, $rows['Finished goods']['total_value']);
    }

    public function test_valuation_summary_excludes_rows_whose_item_and_warehouse_belong_to_different_companies(): void
    {
        // Same defense-in-depth rationale as the stockOnHand/stockOnHandTotals
        // cross-company test above: StockMovementService::assertSameCompany()
        // prevents this at write time, but the query layer must also filter
        // on warehouses.company_id (not just items.company_id) so a bad row
        // can never leak into either company's valuation report.
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $item = Item::factory()->for($companyA)->create();
        $warehouse = Warehouse::factory()->for($companyB)->create();

        StockLevel::factory()->for($item, 'item')->for($warehouse, 'warehouse')->create([
            'quantity_on_hand' => '10.000',
            'average_cost' => '50.0000',
        ]);

        $this->assertSame(0.0, StockLevelQuery::valuationSummary($companyA)[0]['total_value']);
        $this->assertSame(0.0, StockLevelQuery::valuationSummary($companyB)[0]['total_value']);
    }
}
