<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

class JournalEntryLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'journal_entry_id' => JournalEntry::factory(),
            'account_id' => Account::factory(),
            'partner_id' => null,
            'description' => $this->faker->words(4, true),
            'debit' => 0,
            'credit' => 0,
            'currency_code' => 'MKD',
            'exchange_rate' => 1,
            'foreign_amount' => null,
        ];
    }
}
