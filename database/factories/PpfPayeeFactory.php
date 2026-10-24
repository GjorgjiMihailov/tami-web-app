<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PpfPayee;
use App\Support\Embg;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PpfPayee> */
class PpfPayeeFactory extends Factory
{
    protected $model = PpfPayee::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->firstName(),
            'surname' => fake()->lastName(),
            'embg' => $this->validEmbg(),
            'municipality_code' => '179',
            'bank_account' => '100000000000002',
        ];
    }

    /** Измислен, но со точна контролна цифра. */
    private function validEmbg(): string
    {
        $weights = [7, 6, 5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

        do {
            $body = sprintf('%02d%02d%03d45%04d', fake()->numberBetween(1, 28), fake()->numberBetween(1, 12), fake()->numberBetween(950, 999), fake()->numberBetween(0, 9999));
            $body = substr($body, 0, 12);
            $sum = 0;

            foreach ($weights as $i => $weight) {
                $sum += (int) $body[$i] * $weight;
            }

            $remainder = $sum % 11;
            $embg = $body.($remainder === 0 ? 0 : 11 - $remainder);
        } while ($remainder === 1 || ! Embg::isValid($embg));

        return $embg;
    }
}
