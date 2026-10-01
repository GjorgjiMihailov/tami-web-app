<?php

namespace Tests\Unit\Models;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceImportCost;
use App\Models\PurchaseInvoiceTariffLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoiceImportSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_invoice_defaults_to_not_import(): void
    {
        $invoice = PurchaseInvoice::factory()->create();

        $this->assertFalse($invoice->is_import);
        $this->assertNull($invoice->import_date);
    }

    public function test_import_costs_and_tariff_lines_are_ordered_and_cascade_deleted(): void
    {
        $invoice = PurchaseInvoice::factory()->create(['is_import' => true]);

        PurchaseInvoiceImportCost::factory()->for($invoice, 'purchaseInvoice')->create(['sort_order' => 1, 'payee_name' => 'Second']);
        PurchaseInvoiceImportCost::factory()->for($invoice, 'purchaseInvoice')->create(['sort_order' => 0, 'payee_name' => 'First']);
        PurchaseInvoiceTariffLine::factory()->for($invoice, 'purchaseInvoice')->create(['sort_order' => 0, 'tariff_code' => '12345678']);

        $this->assertSame(['First', 'Second'], $invoice->importCosts->pluck('payee_name')->all());
        $this->assertSame('12345678', $invoice->tariffLines->first()->tariff_code);

        $invoice->delete();

        $this->assertSame(0, PurchaseInvoiceImportCost::count());
        $this->assertSame(0, PurchaseInvoiceTariffLine::count());
    }
}
