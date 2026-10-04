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
            invoiceNumber: 'R-0003/26',
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
        $result = (new ImportScanMapper)->convertInvoice($this->foreignInvoice(), '61.6950');

        $this->assertSame('MKD', $result['invoice']->currency);
        $this->assertCount(2, $result['invoice']->lines);
        // 12.50 * 61.6950 = 771.1875 -> 771.19 ; 33.33 * 61.6950 = 2056.29435 -> 2056.29
        $this->assertSame('771.19', $result['invoice']->lines[0]->unitPrice);
        $this->assertSame('2056.29', $result['invoice']->lines[1]->unitPrice);
        $this->assertSame('0', $result['invoice']->lines[0]->vatRate);
        $this->assertSame('2', $result['invoice']->lines[0]->quantity);
        $this->assertSame('R-0003/26', $result['invoice']->invoiceNumber);
    }

    public function test_a_charge_line_becomes_an_import_cost_row(): void
    {
        $result = (new ImportScanMapper)->convertInvoice($this->foreignInvoice(), '61.6950');

        $this->assertSame([[
            'payee_name' => 'FOREIGN DOO',
            'reference_number' => 'R-0003/26',
            'foreign_amount' => '100.00',
            'base_amount' => '6169.50',
            'vat_amount' => '0.00',
            'source' => 'invoice',
        ]], $result['costs']);
    }

    public function test_an_unreadable_price_is_kept_and_warned_about(): void
    {
        $invoice = new ScannedInvoice(currency: 'EUR', lines: [new ScannedInvoiceLine('Нешто', '1', '1?5', '0', 'goods')]);

        $result = (new ImportScanMapper)->convertInvoice($invoice, '61.6950');

        $this->assertSame('1?5', $result['invoice']->lines[0]->unitPrice);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('1', $result['warnings'][0]);
    }

    public function test_forwarder_cost_sums_net_and_vat(): void
    {
        $forwarder = new ScannedInvoice(
            sellerName: 'ТЕСТ ШПЕДИТЕР ДООЕЛ',
            invoiceNumber: '2600000286',
            currency: 'MKD',
            lines: [
                new ScannedInvoiceLine('Царинско посредување', '1', '3000.00', '18'),
                new ScannedInvoiceLine('Манипулација', '2', '35.00', '18'),
            ],
        );

        $result = (new ImportScanMapper)->forwarderCost($forwarder);

        $this->assertSame('ТЕСТ ШПЕДИТЕР ДООЕЛ', $result['row']['payee_name']);
        $this->assertSame('2600000286', $result['row']['reference_number']);
        $this->assertSame('3070.00', $result['row']['base_amount']);
        $this->assertSame('552.60', $result['row']['vat_amount']);
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
            declarationNumber: '26MKIM99990001C111',
            date: '2026-03-04',
            currency: 'EUR',
            exchangeRate: '61.6950',
        ));

        $this->assertSame([
            'customsDeclarationNumber' => '26MKIM99990001C111',
            'importDate' => '2026-03-04',
            'importCurrencyCode' => 'EUR',
            'importExchangeRate' => '61.6950',
        ], $fields);
    }

    public function test_an_unsupported_currency_gives_null_code(): void
    {
        $fields = (new ImportScanMapper)->declarationFields(new ScannedCustomsDeclaration(currency: 'JPY'));

        $this->assertNull($fields['importCurrencyCode']);
    }
}
