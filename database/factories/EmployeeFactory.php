<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            // A real, check-digit-valid ЕМБГ, so factory-made records survive
            // the validation rule if a test ever routes them through it.
            'embg' => '3101980455019',
            'first_name' => 'Марко',
            'last_name' => 'Петровски',
            'municipality_code' => '175',
            'bank_account' => '300000000000000',
            'insurance_type_code' => '0050',
            'registration_number' => null,
            'employed_on' => '2026-01-01',
            'terminated_on' => null,
            'movement_code' => null,
            'exemption_code' => null,
            'weekly_hours' => 40,
            'prior_service_months' => 0,
            'address' => null,
            'phone' => null,
            'email' => null,
        ];
    }
}
