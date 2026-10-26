<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PpfCalculation;
use App\Models\PpfPayee;
use App\Models\PpfPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PpfPayment> */
class PpfPaymentFactory extends Factory
{
    protected $model = PpfPayment::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'payment_date' => '2026-10-15',
            // Пресметката, исплатата и примателот се на иста фирма; основно пресметка на денот на исплатата.
            'ppf_calculation_id' => fn (array $attributes) => PpfCalculation::factory()->create([
                'company_id' => $attributes['company_id'], 'period_date' => $attributes['payment_date'],
            ])->id,
            'ppf_payee_id' => fn (array $attributes) => PpfPayee::factory()->create(['company_id' => $attributes['company_id']])->id,
            'type_income_code' => 'T1',
            'subtype_income_code' => '1014',
            'gross_income' => 100000,
            'payment_type_code' => '00001',
        ];
    }
}
