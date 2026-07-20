<?php

namespace Database\Factories;

use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SalesInvoicePaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sales_invoice_id' => SalesInvoice::factory(),
            'amount' => '100.00',
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank',
            'created_by' => User::factory(),
        ];
    }
}
