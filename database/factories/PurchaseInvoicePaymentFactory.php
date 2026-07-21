<?php

namespace Database\Factories;

use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseInvoicePaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'purchase_invoice_id' => PurchaseInvoice::factory(),
            'amount' => '100.00',
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank',
            'created_by' => User::factory(),
        ];
    }
}
