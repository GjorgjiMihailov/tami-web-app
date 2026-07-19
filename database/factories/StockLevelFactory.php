<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockLevelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'warehouse_id' => Warehouse::factory(),
            'quantity_on_hand' => '0.000',
            'average_cost' => '0.0000',
        ];
    }
}
