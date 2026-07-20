<?php

namespace Database\Factories;

use App\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class SalesInvoiceLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sales_invoice_id' => SalesInvoice::factory(),
            'item_id' => null,
            'stock_movement_id' => null,
            'description' => $this->faker->words(3, true),
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'vat_rate' => '18.00',
        ];
    }
}
