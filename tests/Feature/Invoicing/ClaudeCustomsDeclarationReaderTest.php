<?php

namespace Tests\Feature\Invoicing;

use App\Services\Invoicing\ClaudeCustomsDeclarationReader;
use PHPUnit\Framework\TestCase;

class ClaudeCustomsDeclarationReaderTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'ecd_number' => '26MKIM99990001C111',
            'date' => '2026-03-04',
            'importer_name' => 'ТЕСТ УВОЗНИК ДООЕЛ',
            'importer_tax_id' => 'MK4000000000001',
            'declarant_name' => 'ТЕСТ ШПЕДИТЕР',
            'currency' => 'EUR',
            'invoice_total_foreign' => '3.300,00',
            'exchange_rate' => '61.6950',
            'total_duty' => '1000',
            'total_vat' => '2000',
            'referenced_invoice_numbers' => ['T-0001/26'],
            'items' => [
                [
                    'tariff_code' => '6109 10 00',
                    'description' => 'МАИЦИ - ПАМУК',
                    'invoice_value_foreign' => '234.61',
                    'statistical_value' => '14.474',
                    'charges' => [
                        ['code' => 'A00', 'amount' => '2.533'],
                        ['code' => 'B00', 'amount' => '3061'],
                    ],
                ],
            ],
        ], $overrides);
    }

    public function test_it_maps_the_payload_to_a_declaration(): void
    {
        $d = ClaudeCustomsDeclarationReader::toDeclaration($this->payload());

        $this->assertSame('26MKIM99990001C111', $d->declarationNumber);
        $this->assertSame('2026-03-04', $d->date);
        $this->assertSame('EUR', $d->currency);
        $this->assertSame('3300.00', $d->invoiceTotalForeign);
        $this->assertSame('61.6950', $d->exchangeRate);
        $this->assertSame('1000', $d->totalDuty);
        $this->assertSame(['T-0001/26'], $d->referencedInvoiceNumbers);
        $this->assertCount(1, $d->items);
        $this->assertSame('61091000', $d->items[0]->tariffCode);
        $this->assertSame('234.61', $d->items[0]->invoiceValueForeign);
        $this->assertSame('14474', $d->items[0]->statisticalValue);
        $this->assertSame(['A00' => '2533', 'B00' => '3061'], $d->items[0]->charges);
    }

    public function test_missing_and_empty_fields_become_null(): void
    {
        $d = ClaudeCustomsDeclarationReader::toDeclaration(['ecd_number' => '', 'items' => []]);

        $this->assertNull($d->declarationNumber);
        $this->assertNull($d->exchangeRate);
        $this->assertSame([], $d->items);
        $this->assertSame([], $d->referencedInvoiceNumbers);
    }

    public function test_an_unreadable_amount_is_kept_as_the_original_string(): void
    {
        $d = ClaudeCustomsDeclarationReader::toDeclaration($this->payload([
            'items' => [['tariff_code' => '61091000', 'description' => '', 'invoice_value_foreign' => '23?.61', 'statistical_value' => '', 'charges' => []]],
        ]));

        $this->assertSame('23?.61', $d->items[0]->invoiceValueForeign);
    }

    public function test_is_blank_detects_a_reading_with_nothing_in_it(): void
    {
        $this->assertTrue(ClaudeCustomsDeclarationReader::isBlank(ClaudeCustomsDeclarationReader::toDeclaration(['items' => []])));
        $this->assertFalse(ClaudeCustomsDeclarationReader::isBlank(ClaudeCustomsDeclarationReader::toDeclaration($this->payload())));
    }

    public function test_the_prompt_mentions_the_charge_codes(): void
    {
        $this->assertStringContainsString('A00', ClaudeCustomsDeclarationReader::prompt());
        $this->assertStringContainsString('B00', ClaudeCustomsDeclarationReader::prompt());
    }
}
