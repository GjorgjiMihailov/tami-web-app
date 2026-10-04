<?php

namespace Tests\Unit\Services\Invoicing;

use App\Services\Invoicing\ImportScanChecks;
use App\Services\Invoicing\ScannedCustomsDeclaration;
use App\Services\Invoicing\ScannedCustomsItem;
use App\Services\Invoicing\ScannedInvoice;
use App\Services\Invoicing\ScannedInvoiceLine;
use PHPUnit\Framework\TestCase;

class ImportScanChecksTest extends TestCase
{
    private function ecd(array $over = []): ScannedCustomsDeclaration
    {
        $args = array_merge([
            'declarationNumber' => '26MKIM00000001C000',
            'importerTaxId' => 'MK4000000000001',
            'declarantName' => 'ТЕСТ ШПЕДИТЕР',
            'currency' => 'EUR',
            'invoiceTotalForeign' => '100.00',
            'exchangeRate' => '61.5000',
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

    public function test_nbrm_rate_is_flagged_only_when_it_was_used(): void
    {
        $warnings = $this->check(null, new ScannedInvoice(currency: 'EUR'), null, '4000000000001', true);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('НБРМ', $warnings[0]);
        $this->assertStringContainsString('нема ЕЦД со курс од декларацијата', $warnings[0]);

        $this->assertSame([], $this->check(null, new ScannedInvoice(currency: 'EUR'), null, '4000000000001', false));
    }

    public function test_a_longer_reference_in_field_44_still_matches_the_invoice_number(): void
    {
        $ecd = $this->ecd(['referencedInvoiceNumbers' => ['Фактура бр. T-1/26 од 24.02.2026']]);

        $this->assertSame([], $this->check($ecd, new ScannedInvoice(invoiceNumber: 'T-1/26', currency: 'EUR', printedTotal: '100.00')));
    }

    public function test_a_very_short_number_does_not_match_by_containment(): void
    {
        $ecd = $this->ecd(['referencedInvoiceNumbers' => ['123']]);

        $warnings = $this->check($ecd, new ScannedInvoice(invoiceNumber: '12', currency: 'EUR', printedTotal: '100.00'));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('12', $warnings[0]);
    }

    public function test_currency_comparison_ignores_case_and_spaces(): void
    {
        $this->assertSame([], $this->check($this->ecd(), new ScannedInvoice(invoiceNumber: 'T-1/26', currency: ' eur ', printedTotal: '100.00')));
    }

    public function test_different_currencies_do_not_also_produce_a_misleading_total_warning(): void
    {
        $warnings = $this->check($this->ecd(), new ScannedInvoice(invoiceNumber: 'T-1/26', currency: 'USD', printedTotal: '150.00'));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('USD', $warnings[0]);
    }

    public function test_invoice_lines_must_add_up_to_the_printed_total(): void
    {
        $invoice = new ScannedInvoice(currency: 'EUR', printedTotal: '100.00', lines: [
            new ScannedInvoiceLine('a', '2', '50.00', '0'),
            new ScannedInvoiceLine('b', '1', '10.00', '0'),
        ]);

        $warnings = $this->check(null, $invoice);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('110.00', $warnings[0]);
        $this->assertStringContainsString('100.00', $warnings[0]);
        $this->assertStringContainsString('изоставена', $warnings[0]);
    }

    public function test_a_difference_of_a_few_cents_in_the_line_sum_is_tolerated_and_vat_is_included(): void
    {
        $invoice = new ScannedInvoice(currency: 'EUR', printedTotal: '118.04', lines: [new ScannedInvoiceLine('a', '1', '100.00', '18')]);

        $this->assertSame([], $this->check(null, $invoice));
    }

    public function test_the_line_sum_check_is_skipped_when_a_line_is_unreadable(): void
    {
        $invoice = new ScannedInvoice(currency: 'EUR', printedTotal: '100.00', lines: [new ScannedInvoiceLine('a', '1', '9?0', '0')]);

        $this->assertSame([], $this->check(null, $invoice));
    }

    public function test_a_forwarder_line_equal_to_the_declaration_vat_or_duty_warns_about_double_counting(): void
    {
        // Во ecd(): царина 50, ДДВ 100.
        $fwd = new ScannedInvoice(sellerName: 'ТЕСТ ШПЕДИТЕР ДООЕЛ', lines: [
            new ScannedInvoiceLine('Посредување', '1', '300.00', '18'),
            new ScannedInvoiceLine('Пренесен ДДВ', '1', '100.30', '0'),
        ]);

        $warnings = $this->check($this->ecd(), null, $fwd);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('двапати', $warnings[0]);

        $clean = new ScannedInvoice(sellerName: 'ТЕСТ ШПЕДИТЕР ДООЕЛ', lines: [new ScannedInvoiceLine('Посредување', '1', '300.00', '18')]);

        $this->assertSame([], $this->check($this->ecd(), null, $clean));
    }

    public function test_other_charge_codes_are_flagged(): void
    {
        $ecd = $this->ecd(['items' => [new ScannedCustomsItem('61091000', 'a', '100.00', '6000', ['A00' => '50', 'B00' => '100', 'A10' => '7'])]]);

        $warnings = $this->check($ecd);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('A10', $warnings[0]);
    }
}
