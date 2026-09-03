<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OtherCostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'cost_date' => '2026-03-10',
            'description' => 'Гориво',
            'amount' => '1200.00',
            'created_by' => User::factory(),
        ];
    }
}
