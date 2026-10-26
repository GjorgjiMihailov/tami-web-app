<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PpfCalculation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PpfCalculation> */
class PpfCalculationFactory extends Factory
{
    protected $model = PpfCalculation::class;

    /** Редоследот е важен: годината следи од датумот, а бројот од фирмата и годината. */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'period_date' => '2026-10-15',
            'year' => fn (array $attributes) => (int) substr((string) $attributes['period_date'], 0, 4),
            'number' => fn (array $attributes) => PpfCalculation::nextNumber($attributes['company_id'], $attributes['year']),
        ];
    }
}
