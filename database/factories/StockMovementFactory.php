<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockMovementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'warehouse_id' => Warehouse::factory(),
            'to_warehouse_id' => null,
            'type' => 'receipt',
            'quantity' => '10.000',
            'unit_cost' => '100.0000',
            'reason' => null,
            'movement_date' => now()->toDateString(),
            'created_by' => User::factory(),
        ];
    }
}
