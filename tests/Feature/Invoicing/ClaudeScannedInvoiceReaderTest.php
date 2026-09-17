<?php

namespace Tests\Feature\Invoicing;

use App\Services\Invoicing\ClaudeScannedInvoiceReader;
use Tests\TestCase;

class ClaudeScannedInvoiceReaderTest extends TestCase
{
    public function test_a_full_payload_becomes_a_scanned_invoice(): void
    {
        $result = ClaudeScannedInvoiceReader::toScannedInvoice([
            'seller_tax_id' => '4080012345678',
            'buyer_name' => 'Купувач ДООЕЛ',
            'buyer_tax_id' => '4080055555555',
            'buyer_street_address' => 'Партизанска',
            'buyer_street_number' => '10',
            'buyer_postal_code' => '1000',
            'buyer_city' => 'Скопје',
            'invoice_number' => '2026/45',
            'invoice_date' => '2026-03-01',
            'due_date' => '2026-03-15',
            'currency' => 'MKD',
            'printed_total' => '1180.00',
            'lines' => [
                ['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18'],
            ],
        ]);

        $this->assertSame('Купувач ДООЕЛ', $result->buyerName);
        $this->assertSame('2026/45', $result->invoiceNumber);
        $this->assertSame('Скопје', $result->buyerCity);
        $this->assertCount(1, $result->lines);
        $this->assertSame('1000.00', $result->lines[0]->unitPrice);
    }

    public function test_missing_fields_become_null_instead_of_breaking(): void
    {
        $result = ClaudeScannedInvoiceReader::toScannedInvoice(['invoice_number' => '7']);

        $this->assertSame('7', $result->invoiceNumber);
        $this->assertNull($result->buyerName);
        $this->assertNull($result->printedTotal);
        $this->assertSame([], $result->lines);
    }

    public function test_lines_that_are_not_a_list_are_ignored(): void
    {
        $result = ClaudeScannedInvoiceReader::toScannedInvoice(['lines' => 'нешто чудно']);

        $this->assertSame([], $result->lines);
    }

    public function test_numbers_returned_as_numbers_become_strings(): void
    {
        $result = ClaudeScannedInvoiceReader::toScannedInvoice([
            'printed_total' => 1180.5,
            'lines' => [['description' => 'Услуга', 'quantity' => 2, 'unit_price' => 500, 'vat_rate' => 18]],
        ]);

        $this->assertSame('1180.5', $result->printedTotal);
        $this->assertSame('2', $result->lines[0]->quantity);
        $this->assertSame('500', $result->lines[0]->unitPrice);
        $this->assertSame('18', $result->lines[0]->vatRate);
    }
}
