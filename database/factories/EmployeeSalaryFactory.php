<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeSalaryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'effective_from' => '2026-01-01',
            'amount' => 30000,
            'basis' => 'net',
        ];
    }
}
