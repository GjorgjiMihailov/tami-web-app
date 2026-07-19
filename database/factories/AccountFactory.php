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
            // 4 digits: every official chart-of-accounts code (auto-seeded
            // whenever a Company is created) is exactly 3 digits, so this
            // can never collide with seed data.
            'code' => $this->faker->unique()->numerify('####'),
            'name' => $this->faker->words(3, true),
            'parent_code' => null,
            'is_analytical' => false,
            'is_active' => true,
        ];
    }
}
