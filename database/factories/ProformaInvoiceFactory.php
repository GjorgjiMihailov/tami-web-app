<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProformaInvoiceFactory extends Factory
{
    public function definition(): array
    {
        $company = Company::factory();
        $number = $this->faker->unique()->numberBetween(1, 100000);

        return [
            'company_id' => $company,
            'partner_id' => Partner::factory()->for($company),
            'fiscal_year' => (int) now()->year,
            'proforma_number' => $number,
            'proforma_number_formatted' => 'ПФ-'.now()->year.'/'.$number,
            'reference' => null,
            'proforma_date' => now()->toDateString(),
            'expected_delivery_date' => null,
            'payment_terms_days' => null,
            'currency' => 'MKD',
            'status' => 'draft',
            'notes' => null,
            'terms' => null,
            'sales_invoice_id' => null,
            'created_by' => User::factory(),
        ];
    }
}
