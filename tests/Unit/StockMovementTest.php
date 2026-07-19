<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_an_item_and_warehouse(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $movement = StockMovement::factory()->create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id]);

        $this->assertTrue($movement->item->is($item));
        $this->assertTrue($movement->warehouse->is($warehouse));
    }

    public function test_transfer_movement_has_a_to_warehouse(): void
    {
        $destination = Warehouse::factory()->create();
        $movement = StockMovement::factory()->create(['type' => 'transfer', 'to_warehouse_id' => $destination->id]);

        $this->assertTrue($movement->toWarehouse->is($destination));
    }

    public function test_quantity_and_unit_cost_are_cast_to_decimals(): void
    {
        $movement = StockMovement::factory()->create(['quantity' => '10.500', 'unit_cost' => '99.9900']);

        $this->assertSame('10.500', (string) $movement->fresh()->quantity);
        $this->assertSame('99.9900', (string) $movement->fresh()->unit_cost);
    }
}
