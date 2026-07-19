<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class JournalEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'entry_date' => $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'description' => $this->faker->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
