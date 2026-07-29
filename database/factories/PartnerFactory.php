<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class PartnerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => $this->faker->company(),
            'type' => 'legal_entity',
            'tax_id' => $this->faker->numerify('#############'),
            'registration_number' => $this->faker->numerify('#######'),
            'director_name' => $this->faker->name(),
            'is_vat_registered' => false,
            'vat_number' => null,
            'email' => $this->faker->companyEmail(),
            'phone' => $this->faker->phoneNumber(),
            'address' => $this->faker->address(),
        ];
    }

    public function individual(): static
    {
        return $this->state(fn () => [
            'type' => 'individual',
            'name' => $this->faker->name(),
            'registration_number' => null,
            'director_name' => null,
            'is_vat_registered' => false,
            'vat_number' => null,
        ]);
    }
}
