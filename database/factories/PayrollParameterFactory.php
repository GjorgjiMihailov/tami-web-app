<?php

namespace Database\Factories;

use App\Models\PayrollParameter;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PayrollParameter> */
class PayrollParameterFactory extends Factory
{
    protected $model = PayrollParameter::class;

    public function definition(): array
    {
        return [
            // effective_from is globally unique and the payroll_parameters
            // seeding migration already occupies 2026-01-01, 2026-03-01 and
            // 2026-07-01, so a fixed date here would collide with seed data
            // (and across repeated factory calls). The July 2026 rates are
            // used regardless of which date the row lands on.
            'effective_from' => $this->faker->unique()->dateTimeBetween('2030-01-01', '2035-12-31')->format('Y-m-d'),
            'rate_pension' => 19.9,
            'rate_health' => 7.5,
            'rate_injury' => 0.5,
            'rate_unemployment' => 0.1,
            'rate_tax' => 10.0,
            'personal_allowance' => 10932,
            'average_salary' => 69141,
            'min_base' => 34571,
            'max_base' => 1106256,
            'minimum_wage' => 38507,
        ];
    }
}
