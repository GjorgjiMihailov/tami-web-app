<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => $this->faker->unique()->numerify('###'),
            'name' => $this->faker->words(3, true),
            'parent_code' => null,
            'is_analytical' => false,
            'is_active' => true,
        ];
    }
}
