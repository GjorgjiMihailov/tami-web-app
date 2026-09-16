<?php

namespace Tests\Feature\Invoicing;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoiceFormattedNumberUniqueIndexTest extends TestCase
{
    use RefreshDatabase;

    private function confirmed(Company $company, array $attributes = []): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();

        return SalesInvoice::factory()->for($company)->create(array_merge([
            'partner_id' => $partner->id,
            'status' => 'confirmed',
            'fiscal_year' => 2026,
            'invoice_date' => '2026-03-01',
            'due_date' => '2026-03-15',
            'invoice_number' => null,
        ], $attributes));
    }

    public function test_the_database_refuses_a_duplicate_formatted_number(): void
    {
        $company = Company::factory()->create();
        $this->confirmed($company, ['invoice_number_formatted' => '2026/45']);

        $this->expectException(QueryException::class);

        $this->confirmed($company, ['invoice_number_formatted' => '2026/45']);
    }

    public function test_another_company_may_use_the_same_formatted_number(): void
    {
        $this->confirmed(Company::factory()->create(), ['invoice_number_formatted' => '2026/45']);
        $second = $this->confirmed(Company::factory()->create(), ['invoice_number_formatted' => '2026/45']);

        $this->assertSame('2026/45', $second->invoice_number_formatted);
    }

    public function test_another_year_may_use_the_same_formatted_number(): void
    {
        $company = Company::factory()->create();
        $this->confirmed($company, ['fiscal_year' => 2025, 'invoice_number_formatted' => '001']);
        $second = $this->confirmed($company, ['fiscal_year' => 2026, 'invoice_number_formatted' => '001']);

        $this->assertSame('001', $second->invoice_number_formatted);
    }

    public function test_drafts_with_the_same_paper_number_do_not_collide(): void
    {
        $company = Company::factory()->create();

        // Нацрт нема fiscal_year, па индексот не се однесува на него. Двата
        // нацрта мораат да поминат — читливата порака ја дава проверката во
        // формата и во confirm(), не базата.
        $first = $this->confirmed($company, ['status' => 'draft', 'fiscal_year' => null, 'invoice_number_formatted' => '2026/45']);
        $second = $this->confirmed($company, ['status' => 'draft', 'fiscal_year' => null, 'invoice_number_formatted' => '2026/45']);

        $this->assertNotSame($first->id, $second->id);
    }
}
