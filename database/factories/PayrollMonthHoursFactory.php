<?php

namespace Database\Factories;

use App\Models\PayrollMonthHours;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PayrollMonthHours> */
class PayrollMonthHoursFactory extends Factory
{
    protected $model = PayrollMonthHours::class;

    public function definition(): array
    {
        return [
            'year' => 2026,
            'month' => 7,
            'hours' => 184,
        ];
    }
}
