<?php

namespace Database\Factories;

use App\Models\PurchaseInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseInvoiceImportCostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'purchase_invoice_id' => PurchaseInvoice::factory(),
            'payee_name' => $this->faker->company(),
            'reference_number' => (string) $this->faker->numberBetween(1000, 9999),
            'foreign_amount' => null,
            'base_amount' => '1000.00',
            'vat_amount' => '0.00',
            'sort_order' => 0,
        ];
    }
}
