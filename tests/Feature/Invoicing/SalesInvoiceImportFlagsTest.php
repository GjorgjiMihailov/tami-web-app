<?php

namespace Tests\Feature\Invoicing;

use App\Models\Company;
use App\Models\SalesInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoiceImportFlagsTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceWithOneLine(?string $importedTotal): SalesInvoice
    {
        $company = Company::factory()->create();

        $invoice = SalesInvoice::factory()->for($company)->create([
            'is_imported' => true,
            'imported_total' => $importedTotal,
        ]);

        // 1000.00 + 18% ДДВ = 1180.00
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

    public function test_imported_total_mismatch_is_null_without_a_paper_total(): void
    {
        $this->assertNull($this->invoiceWithOneLine(null)->importedTotalMismatch());
    }

    public function test_imported_total_mismatch_is_null_when_totals_match(): void
    {
        $this->assertNull($this->invoiceWithOneLine('1180.00')->importedTotalMismatch());
    }

    public function test_imported_total_mismatch_names_both_amounts_when_they_differ(): void
    {
        $message = $this->invoiceWithOneLine('2360.00')->importedTotalMismatch();

        $this->assertNotNull($message);
        $this->assertStringContainsString('2360.00', $message);
        $this->assertStringContainsString('1180.00', $message);
    }

    public function test_is_imported_reflects_the_flag(): void
    {
        $this->assertTrue($this->invoiceWithOneLine(null)->isImported());

        $plain = SalesInvoice::factory()->create();
        $this->assertFalse($plain->fresh()->isImported());
    }

    public function test_a_new_in_memory_invoice_defaults_to_not_imported(): void
    {
        // Колона со default во базата НЕ полни свежо создаден објект во меморија.
        $this->assertFalse((new SalesInvoice)->is_imported);
    }
}
