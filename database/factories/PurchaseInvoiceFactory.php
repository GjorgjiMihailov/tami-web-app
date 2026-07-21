<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseInvoiceFactory extends Factory
{
    public function definition(): array
    {
        $company = Company::factory();

        return [
            'company_id' => $company,
            'partner_id' => Partner::factory()->for($company),
            'warehouse_id' => null,
            'journal_entry_id' => null,
            'supplier_invoice_number' => (string) $this->faker->unique()->numberBetween(1000, 999999),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(15)->toDateString(),
            'status' => 'draft',
            'notes' => null,
            'source_document_path' => null,
            'created_by' => User::factory(),
        ];
    }
}
