<?php

namespace Database\Factories;

use App\Models\PurchaseInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseInvoiceLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'purchase_invoice_id' => PurchaseInvoice::factory(),
            'item_id' => null,
            'account_id' => null,
            'stock_movement_id' => null,
            'description' => $this->faker->words(3, true),
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'vat_rate' => '18.00',
            'vat_deductible' => true,
        ];
    }
}
