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
            // 7 digits: the official chart (auto-seeded whenever a Company is
            // created) goes up to 6 digits, so this can never collide with it.
            'code' => $this->faker->unique()->numerify('#######'),
            'name' => $this->faker->words(3, true),
            'parent_code' => null,
            'is_analytical' => false,
            'is_active' => true,
        ];
    }
}
