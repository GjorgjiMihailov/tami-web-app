<?php

namespace Database\Factories;

use App\Models\BankStatement;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use Illuminate\Database\Eloquent\Factories\Factory;

class BankStatementLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bank_statement_id' => BankStatement::factory(),
            'position' => 1,
            'line_date' => '2026-01-05',
            'direction' => LineDirection::IN,
            'amount' => '100.00',
            'kind' => LineKind::UNCLEAR,
        ];
    }
}
