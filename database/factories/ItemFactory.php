<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => strtoupper($this->faker->unique()->bothify('SKU-####')),
            'name' => $this->faker->words(3, true),
            'unit_of_measure' => 'piece',
            'category' => null,
            'vat_rate' => 18.00,
            'preferred_partner_id' => null,
            'is_active' => true,
            'selling_price' => null,
            'type' => 'product',
            'is_made_in_mk' => false,
            'barcode' => null,
        ];
    }

    public function service(): Factory
    {
        return $this->state(fn () => ['type' => 'service']);
    }
}
