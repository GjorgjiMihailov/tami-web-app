<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class JournalGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => $this->faker->unique()->numerify('##'),
            'name' => ucfirst($this->faker->words(2, true)),
            'sort_order' => 0,
        ];
    }
}
