<?php

namespace Tests\Feature\Invoicing;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoicePaperNumberTest extends TestCase
{
    use RefreshDatabase;

    private function draft(Company $company, array $attributes = []): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();

        $invoice = SalesInvoice::factory()->for($company)->create(array_merge([
            'partner_id' => $partner->id,
            'status' => 'draft',
            'invoice_date' => '2026-03-01',
            'due_date' => '2026-03-15',
            'warehouse_id' => null,
        ], $attributes));

        $invoice->lines()->create([
            'item_id' => null,
            'description' => 'Услуга',
            'quantity' => '1',
            'unit_price' => '1000.00',
            'vat_rate' => '18.00',
            'vat_treatment' => 'standard',
        ]);

        return $invoice->fresh();
    }

    public function test_a_paper_number_survives_confirmation(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $invoice = $this->draft($company, ['invoice_number_formatted' => '2026/45']);

        $confirmed = app(SalesInvoiceService::class)->confirm($invoice, $user->id);

        $this->assertSame('2026/45', $confirmed->invoice_number_formatted);
        $this->assertSame('2026/45', $confirmed->formattedNumber());
        $this->assertNull($confirmed->invoice_number);
        $this->assertSame(2026, (int) $confirmed->fiscal_year);
    }

    public function test_a_paper_number_does_not_consume_the_company_counter(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $service = app(SalesInvoiceService::class);

        $first = $service->confirm($this->draft($company), $user->id);
        $this->assertSame(1, (int) $first->invoice_number);

        $service->confirm($this->draft($company, ['invoice_number_formatted' => '2026/99']), $user->id);

        $third = $service->confirm($this->draft($company), $user->id);

        // Скенот меѓу нив не смее да остави дупка — следната своја фактура
        // ја добива бројката што ќе ја добиеше и без него.
        $this->assertSame(2, (int) $third->invoice_number);
    }

    public function test_a_duplicate_paper_number_in_the_same_year_is_refused(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $service = app(SalesInvoiceService::class);

        $service->confirm($this->draft($company, ['invoice_number_formatted' => '2026/45']), $user->id);

        $this->expectException(InvalidInvoiceStateException::class);

        $service->confirm($this->draft($company, ['invoice_number_formatted' => '2026/45']), $user->id);
    }

    public function test_the_same_paper_number_in_a_different_year_is_allowed(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $service = app(SalesInvoiceService::class);

        $service->confirm($this->draft($company, [
            'invoice_number_formatted' => '001',
            'invoice_date' => '2025-03-01',
            'due_date' => '2025-03-15',
        ]), $user->id);

        $second = $service->confirm($this->draft($company, [
            'invoice_number_formatted' => '001',
            'invoice_date' => '2026-03-01',
            'due_date' => '2026-03-15',
        ]), $user->id);

        $this->assertSame('001', $second->invoice_number_formatted);
    }

    public function test_an_invoice_without_a_paper_number_behaves_as_before(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();

        $confirmed = app(SalesInvoiceService::class)->confirm($this->draft($company), $user->id);

        $this->assertSame(1, (int) $confirmed->invoice_number);
        $this->assertNotNull($confirmed->invoice_number_formatted);
    }
}
