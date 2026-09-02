<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use App\Support\BankStatementKind;
use Illuminate\Database\Eloquent\Factories\Factory;

class BankStatementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'bank' => 'Комерцијална банка АД Скопје',
            'account' => '300000000000000',
            'kind' => BankStatementKind::DENAR,
            'number' => 1,
            'statement_date' => '2026-01-05',
            'uploaded_by' => User::factory(),
        ];
    }

    public function foreign(): static
    {
        return $this->state(fn () => [
            'account' => '300000000000001',
            'kind' => BankStatementKind::FOREIGN,
        ]);
    }
}
