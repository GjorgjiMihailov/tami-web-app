<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\StockLevel;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockLevelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_an_item_and_warehouse(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $level = StockLevel::factory()->create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id]);

        $this->assertTrue($level->item->is($item));
        $this->assertTrue($level->warehouse->is($warehouse));
    }

    public function test_item_and_warehouse_pair_is_unique(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        StockLevel::factory()->create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        StockLevel::factory()->create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id]);
    }
}
