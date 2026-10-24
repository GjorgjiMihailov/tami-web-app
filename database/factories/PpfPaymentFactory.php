<?php

namespace Database\Factories;

use App\Models\Company;
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
            // Исплатата и примателот мора да се на иста фирма: примателот се создава за фирмата на исплатата.
            'ppf_payee_id' => fn (array $attributes) => PpfPayee::factory()->create(['company_id' => $attributes['company_id']])->id,
            'type_income_code' => 'T1',
            'subtype_income_code' => '1014',
            'gross_income' => 100000,
            'payment_date' => '2026-10-15',
            'payment_type_code' => '00001',
        ];
    }
}
