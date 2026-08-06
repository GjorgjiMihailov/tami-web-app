<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class IncomingEfakturaDocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'euid' => $this->faker->uuid(),
            'status_code' => '01',
            'status_name' => 'Испратена (Нова)',
            'doc_number' => $this->faker->numerify('####-##'),
            'doc_date' => now()->toDateString(),
            'seller_name' => $this->faker->company(),
            'seller_tax_id' => $this->faker->numerify('#############'),
            'total_amount' => 1000,
            'payload_json' => [],
            'discovered_at' => now(),
        ];
    }
}
