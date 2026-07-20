<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

class SalesInvoiceFactory extends Factory
{
    public function definition(): array
    {
        $company = Company::factory();

        return [
            'company_id' => $company,
            'partner_id' => Partner::factory()->for($company),
            'warehouse_id' => null,
            'journal_entry_id' => null,
            'fiscal_year' => null,
            'invoice_number' => null,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(15)->toDateString(),
            'status' => 'draft',
            'sent_at' => null,
            'notes' => null,
            'created_by' => \App\Models\User::factory(),
        ];
    }
}
