<?php

namespace Tests\Unit\Services\Invoicing;

use App\Services\Invoicing\ImportScanMapper;
use App\Services\Invoicing\ScannedCustomsDeclaration;
use App\Services\Invoicing\ScannedInvoice;
use App\Services\Invoicing\ScannedInvoiceLine;
use PHPUnit\Framework\TestCase;

class ImportScanMapperTest extends TestCase
{
    private function foreignInvoice(): ScannedInvoice
    {
        return new ScannedInvoice(
            sellerName: 'FOREIGN DOO',
            invoiceNumber: 'T-1/26',
            invoiceDate: '2026-02-24',
            currency: 'EUR',
            printedTotal: '1125.00',
            lines: [
                new ScannedInvoiceLine('Рукавици', '2', '12.50', '0', 'goods'),
                new ScannedInvoiceLine('Капа', '3', '33.33', '0', null),
                new ScannedInvoiceLine('ТРОШКОВИ НА ТРАНСПОРТА', '1', '100.00', '0', 'charge'),
            ],
        );
    }

    public function test_it_converts_goods_to_denars_with_half_up_rounding_and_zero_vat(): void
    {
        $result = (new ImportScanMapper)->convertInvoice($this->foreignInvoice(), '61.5000');

        $this->assertSame('MKD', $result['invoice']->currency);
        $this->assertCount(3, $result['invoice']->lines);
        // 12.50 * 61.5 = 768.75 ; 33.33 * 61.5 = 2049.795 -> 2049.80 (половина нагоре)
        $this->assertSame('768.75', $result['invoice']->lines[0]->unitPrice);
        $this->assertSame('2049.80', $result['invoice']->lines[1]->unitPrice);
        $this->assertSame('0', $result['invoice']->lines[0]->vatRate);
        $this->assertSame('2', $result['invoice']->lines[0]->quantity);
        $this->assertSame('T-1/26', $result['invoice']->invoiceNumber);
    }

    public function test_a_charge_line_stays_on_the_invoice_and_also_becomes_an_import_cost_row(): void
    {
        $result = (new ImportScanMapper)->convertInvoice($this->foreignInvoice(), '61.5000');

        // Ставката останува на фактурата (долгот кон добавувачот = хартијата), претворена, ДДВ 0, kind зачуван.
        $charge = $result['invoice']->lines[2];
        $this->assertSame('ТРОШКОВИ НА ТРАНСПОРТА', $charge->description);
        $this->assertSame('6150.00', $charge->unitPrice);
        $this->assertSame('1', $charge->quantity);
        $this->assertSame('0', $charge->vatRate);
        $this->assertSame('charge', $charge->kind);

        $this->assertSame([[
            'payee_name' => 'FOREIGN DOO',
            'reference_number' => 'T-1/26',
            'foreign_amount' => '100.00',
            'base_amount' => '6150.00',
            'vat_amount' => '0.00',
            'source' => 'invoice',
        ]], $result['costs']);
    }

    public function test_an_unreadable_price_is_kept_and_warned_about(): void
    {
        $invoice = new ScannedInvoice(currency: 'EUR', lines: [new ScannedInvoiceLine('Нешто', '1', '1?5', '0', 'goods')]);

        $result = (new ImportScanMapper)->convertInvoice($invoice, '61.5000');

        $this->assertSame('1?5', $result['invoice']->lines[0]->unitPrice);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('1', $result['warnings'][0]);
    }

    public function test_an_unreadable_charge_amount_keeps_the_line_unconverted_and_warns(): void
    {
        $invoice = new ScannedInvoice(currency: 'EUR', lines: [new ScannedInvoiceLine('Транспорт', '1', '10?0', '0', 'charge')]);

        $result = (new ImportScanMapper)->convertInvoice($invoice, '61.5000');

        $this->assertSame('10?0', $result['invoice']->lines[0]->unitPrice);
        $this->assertSame([], $result['costs']);
        $this->assertCount(1, $result['warnings']);
    }

    public function test_forwarder_cost_sums_net_and_vat(): void
    {
        $forwarder = new ScannedInvoice(
            sellerName: 'Шпедитер ДООЕЛ Скопје',
            invoiceNumber: 'F-77/26',
            currency: 'MKD',
            lines: [
                new ScannedInvoiceLine('Царинско посредување', '1', '2000.00', '18'),
                new ScannedInvoiceLine('Манипулација', '2', '25.00', '18'),
            ],
        );

        $result = (new ImportScanMapper)->forwarderCost($forwarder);

        $this->assertSame('Шпедитер ДООЕЛ Скопје', $result['row']['payee_name']);
        $this->assertSame('F-77/26', $result['row']['reference_number']);
        $this->assertSame('2050.00', $result['row']['base_amount']);
        $this->assertSame('369.00', $result['row']['vat_amount']);
        $this->assertSame('', $result['row']['foreign_amount']);
        $this->assertSame('forwarder', $result['row']['source']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_a_foreign_currency_forwarder_invoice_warns(): void
    {
        $forwarder = new ScannedInvoice(sellerName: 'X', currency: 'EUR', lines: [new ScannedInvoiceLine('a', '1', '10', '0')]);

        $result = (new ImportScanMapper)->forwarderCost($forwarder);

        $this->assertCount(1, $result['warnings']);
    }

    public function test_declaration_fields(): void
    {
        $fields = (new ImportScanMapper)->declarationFields(new ScannedCustomsDeclaration(
            declarationNumber: '26MKIM00000001C000',
            date: '2026-03-04',
            currency: 'EUR',
            exchangeRate: '61.5000',
        ));

        $this->assertSame([
            'customsDeclarationNumber' => '26MKIM00000001C000',
            'importDate' => '2026-03-04',
            'importCurrencyCode' => 'EUR',
            'importExchangeRate' => '61.5000',
        ], $fields);
    }

    public function test_an_unsupported_currency_gives_null_code(): void
    {
        $fields = (new ImportScanMapper)->declarationFields(new ScannedCustomsDeclaration(currency: 'JPY'));

        $this->assertNull($fields['importCurrencyCode']);
    }
}
