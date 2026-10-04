<?php

namespace Tests\Unit\Services\Invoicing;

use App\Services\Invoicing\ImportScanChecks;
use App\Services\Invoicing\ScannedCustomsDeclaration;
use App\Services\Invoicing\ScannedCustomsItem;
use App\Services\Invoicing\ScannedInvoice;
use PHPUnit\Framework\TestCase;

class ImportScanChecksTest extends TestCase
{
    private function ecd(array $over = []): ScannedCustomsDeclaration
    {
        $args = array_merge([
            'declarationNumber' => '26MKIM99990001C111',
            'importerTaxId' => 'MK4000000000001',
            'declarantName' => 'ТЕСТ ШПЕДИТЕР',
            'currency' => 'EUR',
            'invoiceTotalForeign' => '100.00',
            'exchangeRate' => '61.6950',
            'totalDuty' => '50',
            'totalVat' => '100',
            'referencedInvoiceNumbers' => ['T-1/26'],
            'items' => [
                new ScannedCustomsItem('61091000', 'a', '60.00', '3700', ['A00' => '30', 'B00' => '60']),
                new ScannedCustomsItem('61091000', 'b', '40.00', '2470', ['A00' => '20', 'B00' => '40']),
            ],
        ], $over);

        return new ScannedCustomsDeclaration(...$args);
    }

    private function check(?ScannedCustomsDeclaration $ecd, ?ScannedInvoice $invoice = null, ?ScannedInvoice $fwd = null, string $tax = '4000000000001', bool $nbrm = false): array
    {
        return (new ImportScanChecks)->run($ecd, $invoice, $fwd, $tax, $nbrm);
    }

    public function test_everything_matching_gives_no_warnings(): void
    {
        $invoice = new ScannedInvoice(invoiceNumber: 'T-1/26', currency: 'EUR', printedTotal: '100.00');
        $fwd = new ScannedInvoice(sellerName: 'ТЕСТ ШПЕДИТЕР ДООЕЛ');

        $this->assertSame([], $this->check($this->ecd(), $invoice, $fwd));
    }

    public function test_duty_and_vat_totals_must_match_the_declaration_totals(): void
    {
        $warnings = $this->check($this->ecd(['totalDuty' => '51', 'totalVat' => '99']));

        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('царина', $warnings[0]);
        $this->assertStringContainsString('ДДВ', $warnings[1]);
    }

    public function test_item_value_sum_must_match_the_declaration_total(): void
    {
        $warnings = $this->check($this->ecd(['invoiceTotalForeign' => '110.00']));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('110', $warnings[0]);
    }

    public function test_invoice_total_differing_from_the_declaration_warns_with_the_difference(): void
    {
        $warnings = $this->check($this->ecd(), new ScannedInvoice(invoiceNumber: 'T-1/26', currency: 'EUR', printedTotal: '122.50'));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('22.50', $warnings[0]);
    }

    public function test_invoice_number_not_referenced_in_the_declaration_warns(): void
    {
        $warnings = $this->check($this->ecd(), new ScannedInvoice(invoiceNumber: 'OTHER-9', currency: 'EUR', printedTotal: '100.00'));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('OTHER-9', $warnings[0]);
    }

    public function test_importer_must_be_the_current_company(): void
    {
        $warnings = $this->check($this->ecd(), null, null, '4999999999999');

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('фирма', $warnings[0]);
    }

    public function test_currency_mismatch_between_declaration_and_invoice_warns(): void
    {
        $warnings = $this->check($this->ecd(), new ScannedInvoice(invoiceNumber: 'T-1/26', currency: 'USD', printedTotal: '100.00'));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('USD', $warnings[0]);
    }

    public function test_forwarder_name_not_matching_the_declarant_is_informational(): void
    {
        $warnings = $this->check($this->ecd(), null, new ScannedInvoice(sellerName: 'СОВСЕМ ДРУГА ФИРМА'));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('шпедитер', mb_strtolower($warnings[0]));
    }

    public function test_nbrm_rate_is_always_flagged(): void
    {
        $warnings = $this->check(null, new ScannedInvoice(currency: 'EUR'), null, '4000000000001', true);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('НБРМ', $warnings[0]);
    }

    public function test_other_charge_codes_are_flagged(): void
    {
        $ecd = $this->ecd(['items' => [new ScannedCustomsItem('61091000', 'a', '100.00', '6000', ['A00' => '50', 'B00' => '100', 'A10' => '7'])]]);

        $warnings = $this->check($ecd);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('A10', $warnings[0]);
    }
}
