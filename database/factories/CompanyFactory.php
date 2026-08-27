<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'tax_id' => $this->faker->numerify('#############'),
            'email' => $this->faker->companyEmail(),
            'phone' => $this->faker->phoneNumber(),
            'address' => $this->faker->address(),
            'efaktura_credential_mode' => 'firm',
            'efaktura_firm_access_status' => 'none',
            // Фабриката прави правно лице, зашто така изгледа секој постоечки тест.
            // Тест за физичко лице го поставува изречно.
            'type' => \App\Support\CompanyType::LEGAL,
        ];
    }
}
