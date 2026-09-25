<?php

namespace Database\Factories;

use App\Models\ProformaInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProformaInvoiceLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'proforma_invoice_id' => ProformaInvoice::factory(),
            'item_id' => null,
            'description' => $this->faker->words(3, true),
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'vat_rate' => '18.00',
        ];
    }
}
