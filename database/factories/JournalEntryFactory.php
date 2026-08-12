<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class JournalEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            // Kept inside the current calendar year: journal entry lists are
            // year-scoped, so a fixture that drifted into last year would
            // vanish from the list depending on what month the suite runs in.
            'entry_date' => $this->faker->dateTimeBetween(now()->startOfYear(), 'now')->format('Y-m-d'),
            'description' => $this->faker->sentence(),
            'created_by' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (JournalEntry $entry) {
            if (! $entry->journal_group_id) {
                $entry->journal_group_id = JournalGroup::factory()->create(['company_id' => $entry->company_id])->id;
            }
        });
    }
}
