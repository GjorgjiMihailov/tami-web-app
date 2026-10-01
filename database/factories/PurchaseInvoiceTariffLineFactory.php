<?php

namespace Database\Factories;

use App\Models\PurchaseInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseInvoiceTariffLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'purchase_invoice_id' => PurchaseInvoice::factory(),
            'tariff_code' => (string) $this->faker->numberBetween(10000000, 99999999),
            'foreign_amount' => null,
            'customs_duty' => '100.00',
            'vat_amount' => '0.00',
            'sort_order' => 0,
        ];
    }
}
