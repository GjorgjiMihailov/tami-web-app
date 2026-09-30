<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\ProformaInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeliveryNoteFactory extends Factory
{
    public function definition(): array
    {
        $company = Company::factory();
        $number = $this->faker->unique()->numberBetween(1, 100000);

        return [
            'company_id' => $company,
            'deliverable_type' => ProformaInvoice::class,
            'deliverable_id' => ProformaInvoice::factory()->for($company),
            'fiscal_year' => (int) now()->year,
            'delivery_note_number' => $number,
            'delivery_note_number_formatted' => 'ИСП-'.now()->year.'/'.$number,
            'delivery_date' => now()->toDateString(),
            'created_by' => User::factory(),
        ];
    }
}
