<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PayrollParameter;
use App\Models\PayrollRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PayrollRun> */
class PayrollRunFactory extends Factory
{
    protected $model = PayrollRun::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'year' => 2026,
            'month' => 7,
            'status' => PayrollRun::DRAFT,
            'month_hours' => 184,
            'payroll_parameter_id' => fn () => PayrollParameter::forDate('2026-07-31')->id,
        ];
    }
}
