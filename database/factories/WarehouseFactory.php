<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class WarehouseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => ucfirst($this->faker->unique()->word()).' Warehouse',
            'is_active' => true,
        ];
    }
}
