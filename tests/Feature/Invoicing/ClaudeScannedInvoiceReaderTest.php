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

    public function test_a_payload_with_no_usable_fields_is_recognised_as_blank(): void
    {
        $blank = ClaudeScannedInvoiceReader::toScannedInvoice([]);

        $this->assertTrue(ClaudeScannedInvoiceReader::isBlank($blank));
    }

    public function test_a_payload_where_every_field_is_an_empty_string_is_recognised_as_blank(): void
    {
        $blank = ClaudeScannedInvoiceReader::toScannedInvoice([
            'seller_tax_id' => '', 'buyer_name' => '', 'buyer_tax_id' => '',
            'buyer_street_address' => '', 'buyer_street_number' => '', 'buyer_postal_code' => '',
            'buyer_city' => '', 'invoice_number' => '', 'invoice_date' => '', 'due_date' => '',
            'currency' => '', 'printed_total' => '', 'lines' => [],
        ]);

        $this->assertTrue(ClaudeScannedInvoiceReader::isBlank($blank));
    }

    public function test_a_payload_with_any_usable_field_is_not_blank(): void
    {
        $result = ClaudeScannedInvoiceReader::toScannedInvoice(['invoice_number' => '7']);

        $this->assertFalse(ClaudeScannedInvoiceReader::isBlank($result));
    }

    public function test_the_transport_is_bounded_by_a_timeout_and_a_retry_cap(): void
    {
        $options = ClaudeScannedInvoiceReader::transportOptions();

        $this->assertSame(1, $options['maxRetries']);
        $this->assertInstanceOf(\GuzzleHttp\Client::class, $options['transporter']);
        $this->assertSame(30.0, $options['transporter']->getConfig('timeout'));
    }

    /**
     * Врз вистинска фактура истиот модел еднаш врати „3540.00“, а другпат
     * „3,540.00“ за истиот износ. Со запирка проверката на збирот воопшто не се
     * извршуваше. Затоа обликот се сведува тука, не се бара од моделот.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('amountShapes')]
    public function test_money_amounts_are_reduced_to_a_computable_shape(string $given, ?string $expected): void
    {
        $this->assertSame($expected, ClaudeScannedInvoiceReader::normalizeAmount($given, thousands: true));
    }

    public static function amountShapes(): array
    {
        return [
            'веќе чист' => ['3540.00', '3540.00'],
            'запирка за илјади' => ['3,540.00', '3540.00'],
            'точка за илјади' => ['3.540,00', '3540.00'],
            'размак за илјади' => ['3 540,00', '3540.00'],
            'тврд размак' => ["3\u{00A0}540,00", '3540.00'],
            'милион со точки' => ['1.234.567,89', '1234567.89'],
            'милион со запирки' => ['1,234,567.89', '1234567.89'],
            'само запирка како децимала' => ['3540,50', '3540.50'],
            'запирка со три цифри е илјада' => ['3,540', '3540'],
            'цел број' => ['3540', '3540'],
            'нула' => ['0', '0'],
            'негативен' => ['-1,200.50', '-1200.50'],
            'со размаци околу' => ['  3,540.00  ', '3540.00'],
            // Нечитливото се враќа непроменето — формата тогаш предупредува,
            // наместо тука да се измисли бројка.
            'текст' => ['нема', 'нема'],
            'со валута' => ['3540.00 ден', '3540.00 ден'],
            'празно' => ['', ''],
        ];
    }

    public function test_quantities_keep_a_dot_as_a_decimal_not_a_thousands_mark(): void
    {
        // „1.500“ како количина значи еден и пол, не илјада и пол.
        $this->assertSame('1.500', ClaudeScannedInvoiceReader::normalizeAmount('1.500', thousands: false));
        $this->assertSame('1.5', ClaudeScannedInvoiceReader::normalizeAmount('1,5', thousands: false));
        $this->assertSame('2', ClaudeScannedInvoiceReader::normalizeAmount('2', thousands: false));
    }

    public function test_a_missing_amount_stays_missing(): void
    {
        $this->assertNull(ClaudeScannedInvoiceReader::normalizeAmount(null, thousands: true));
    }

    public function test_a_payload_with_separators_becomes_computable(): void
    {
        $result = ClaudeScannedInvoiceReader::toScannedInvoice([
            'printed_total' => '3,540.00',
            'lines' => [
                ['description' => 'Консултантски услуги', 'quantity' => '1.00', 'unit_price' => '3,000.00', 'vat_rate' => '18'],
            ],
        ]);

        $this->assertSame('3540.00', $result->printedTotal);
        $this->assertSame('1.00', $result->lines[0]->quantity);
        $this->assertSame('3000.00', $result->lines[0]->unitPrice);
        $this->assertSame('18', $result->lines[0]->vatRate);
    }

    /**
     * Врз вистинска МПИН декларација моделот го враќаше ЕДБ-то на фирмата што му
     * го давашe упатството, како да го прочитал од документот. Проверката „ова
     * изгледа како влезна фактура" тогаш секогаш велеше дека се совпаѓа — токму
     * на погрешно качен фајл, случајот поради кој постои.
     */
    public function test_the_prompt_never_hands_the_model_the_companys_tax_id(): void
    {
        $company = new \App\Models\Company([
            'name' => 'ФАЈНЕНС БАДИ ДООЕЛ Скопје',
            'tax_id' => '4032021550357',
        ]);

        $prompt = ClaudeScannedInvoiceReader::prompt($company);

        $this->assertStringNotContainsString('4032021550357', $prompt);
        $this->assertStringContainsString('ФАЈНЕНС БАДИ ДООЕЛ Скопје', $prompt);
    }

    /**
     * Врз вистинска фактура моделот го врати матичниот број (7518439) наместо
     * ЕДБ-то (4032021550357) — двата стојат еден до друг во заглавието. Тоа
     * ќе даваше лажно предупредување врз секоја исправна фактура од тој изглед.
     */
    public function test_the_prompt_tells_the_model_which_number_is_the_tax_id(): void
    {
        $prompt = ClaudeScannedInvoiceReader::prompt(new \App\Models\Company([
            'name' => 'Фирма',
            'tax_id' => '4080012345678',
        ]));

        $this->assertStringContainsString('Е.Д.Б.', $prompt);
        $this->assertStringContainsString('М.број', $prompt);
        $this->assertStringContainsString('13 цифри', $prompt);
    }
}
