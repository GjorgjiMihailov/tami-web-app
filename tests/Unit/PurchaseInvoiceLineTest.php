<?php

namespace Tests\Unit;

use App\Models\PurchaseInvoiceLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoiceLineTest extends TestCase
{
    use RefreshDatabase;

    public function test_line_total_is_quantity_times_unit_price(): void
    {
        $line = PurchaseInvoiceLine::factory()->create(['quantity' => '3', 'unit_price' => '19.99']);

        $this->assertSame('59.97', $line->lineTotal());
    }

    public function test_vat_amount_is_line_total_times_vat_rate(): void
    {
        $line = PurchaseInvoiceLine::factory()->create(['quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $this->assertSame('18.00', $line->vatAmount());
    }

    public function test_zero_vat_rate_produces_zero_vat_amount(): void
    {
        $line = PurchaseInvoiceLine::factory()->create(['quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0.00']);

        $this->assertSame('0.00', $line->vatAmount());
    }

    public function test_vat_deductible_defaults_to_true(): void
    {
        $line = PurchaseInvoiceLine::factory()->create();

        $this->assertTrue($line->fresh()->vat_deductible);
    }
}
