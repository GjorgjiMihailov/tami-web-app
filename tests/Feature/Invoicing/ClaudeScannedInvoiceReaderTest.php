<?php

namespace Tests\Feature\Invoicing;

use App\Services\Invoicing\ClaudeScannedInvoiceReader;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertInstanceOf(Client::class, $options['transporter']);
        $this->assertSame(30.0, $options['transporter']->getConfig('timeout'));
    }

    /**
     * Врз вистинска фактура истиот модел еднаш врати „3540.00“, а другпат
     * „3,540.00“ за истиот износ. Со запирка проверката на збирот воопшто не се
     * извршуваше. Затоа обликот се сведува тука, не се бара од моделот.
     */
    #[DataProvider('amountShapes')]
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
            // Врз вистински СТП скен: истиот знак и за илјади и за децимала.
            'точки насекаде' => ['210.831.00', '210831.00'],
            'запирки насекаде' => ['210,831,00', '210831.00'],
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
     * Со ЕДБ-то во упатството моделот го препишуваше назад таму каде такво
     * нема. Упатството затоа прима само ИМЕ — потписот не дозволува ЕДБ воопшто
     * да стигне до него.
     */
    public function test_the_prompt_can_only_ever_receive_a_name_never_a_tax_id(): void
    {
        $parameters = (new \ReflectionMethod(ClaudeScannedInvoiceReader::class, 'prompt'))->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertSame('companyName', $parameters[0]->getName());
        $this->assertSame('string', (string) $parameters[0]->getType());
    }

    /**
     * Со „таа е продавачот" моделот на влезна фактура го свиткуваше документот
     * за да се согласи. Улогата сега се ПРАШУВА, со три можни одговори.
     */
    public function test_the_prompt_asks_where_the_company_appears_instead_of_asserting_it(): void
    {
        $prompt = ClaudeScannedInvoiceReader::prompt('ФАЈНЕНС БАДИ ДООЕЛ');

        $this->assertStringContainsString('ФАЈНЕНС БАДИ ДООЕЛ', $prompt);
        $this->assertStringContainsString('our_company_role', $prompt);
        $this->assertStringContainsString('"absent"', $prompt);
        $this->assertStringNotContainsString('таа е ПРОДАВАЧОТ', $prompt);
    }

    public function test_the_role_answer_reaches_the_scanned_invoice(): void
    {
        foreach (['seller', 'buyer', 'absent'] as $role) {
            $result = ClaudeScannedInvoiceReader::toScannedInvoice([
                'our_company_role' => $role,
                'buyer_name' => 'Купувач',
            ]);

            $this->assertSame($role, $result->ourCompanyRole);
        }
    }

    public function test_an_unknown_role_answer_becomes_null(): void
    {
        $result = ClaudeScannedInvoiceReader::toScannedInvoice([
            'our_company_role' => 'можеби',
            'buyer_name' => 'Купувач',
        ]);

        $this->assertNull($result->ourCompanyRole);
    }

    public function test_a_role_answer_alone_is_still_a_blank_read(): void
    {
        $this->assertTrue(ClaudeScannedInvoiceReader::isBlank(
            ClaudeScannedInvoiceReader::toScannedInvoice(['our_company_role' => 'absent', 'invoice_count' => 0])
        ));
    }

    public function test_the_prompt_asks_for_both_parties_as_labelled_on_the_page(): void
    {
        $prompt = ClaudeScannedInvoiceReader::prompt('Фирма');

        $this->assertStringContainsString('ПРОДАВАЧ', $prompt);
        $this->assertStringContainsString('КУПУВАЧ', $prompt);
        $this->assertStringContainsString('„Партнер"', $prompt);
        $this->assertStringNotContainsString('таа е ПРОДАВАЧОТ', $prompt);
    }

    /**
     * Врз вистинска фактура моделот го врати матичниот број (7518439) наместо
     * ЕДБ-то (4032021550357) — двата стојат еден до друг во заглавието. Тоа
     * ќе даваше лажно предупредување врз секоја исправна фактура од тој изглед.
     */
    public function test_the_prompt_tells_the_model_which_number_is_the_tax_id(): void
    {
        $prompt = ClaudeScannedInvoiceReader::prompt('Фирма');

        $this->assertStringContainsString('Е.Д.Б.', $prompt);
        $this->assertStringContainsString('М.број', $prompt);
        $this->assertStringContainsString('13 цифри', $prompt);
    }

    /**
     * Вистински PDF од СТП содржеше четири фактури — бр.25, 21, 140 и 85 — а
     * беше прочитана само првата. Бројот на фактури мора да стигне до формата.
     */
    public function test_the_invoice_count_and_seller_name_reach_the_scanned_invoice(): void
    {
        $result = ClaudeScannedInvoiceReader::toScannedInvoice([
            'invoice_count' => 4,
            'seller_name' => 'Т.Д.П.Т.У.У. СТП дооел',
            'buyer_name' => 'СОЛИД ГРАУНД дооел',
        ]);

        $this->assertSame(4, $result->invoiceCount);
        $this->assertSame('Т.Д.П.Т.У.У. СТП дооел', $result->sellerName);
    }

    public function test_an_unusable_invoice_count_becomes_null(): void
    {
        $result = ClaudeScannedInvoiceReader::toScannedInvoice([
            'invoice_count' => 'многу',
            'buyer_name' => 'Купувач',
        ]);

        $this->assertNull($result->invoiceCount);
    }

    public function test_an_invoice_count_alone_is_still_a_blank_read(): void
    {
        $this->assertTrue(ClaudeScannedInvoiceReader::isBlank(
            ClaudeScannedInvoiceReader::toScannedInvoice(['invoice_count' => 1])
        ));
    }

    /**
     * Бројот заминува кон УЈП како `docNumber`. Врз вистинска фактура моделот
     * врати „бр.25“ иако упатството бара само бројот.
     */
    #[DataProvider('invoiceNumbers')]
    public function test_the_word_for_number_is_stripped_from_the_invoice_number(string $given, string $expected): void
    {
        $this->assertSame($expected, ClaudeScannedInvoiceReader::normalizeInvoiceNumber($given));
    }

    public static function invoiceNumbers(): array
    {
        return [
            'бр. залепено' => ['бр.25', '25'],
            // Врз вистински СТП скен „б“ беше прочитано како шестка.
            'бр. прочитано како 6р.' => ['6р.25', '25'],
            'вистински број што почнува на 6 останува' => ['625', '625'],
            'бр. со размак' => ['бр. 25', '25'],
            'голема буква' => ['Бр.140', '140'],
            'цел наслов' => ['Фактура - Испратница бр.85', '85'],
            'број' => ['број 19', '19'],
            'знак за број' => ['№ 12', '12'],
            'латиница' => ['No. 7', '7'],
            'веќе чист со цртичка' => ['00098-26', '00098-26'],
            'веќе чист со коса црта' => ['019/2025', '019/2025'],
            'само зборот останува изворно' => ['бр.', 'бр.'],
        ];
    }

    public function test_a_missing_invoice_number_stays_missing(): void
    {
        $this->assertNull(ClaudeScannedInvoiceReader::normalizeInvoiceNumber(null));
    }

    /**
     * Формата прифаќа само кодови. „денари“ беше тивко игнорирано и остануваше
     * MKD — безопасно случајно; „евра“ истиот пат би дало ПОГРЕШНА валута.
     */
    #[DataProvider('currencies')]
    public function test_currency_names_become_codes(string $given, string $expected): void
    {
        $this->assertSame($expected, ClaudeScannedInvoiceReader::normalizeCurrency($given));
    }

    public static function currencies(): array
    {
        return [
            'денари' => ['денари', 'MKD'],
            'ден.' => ['ден.', 'MKD'],
            'МКД кирилица' => ['МКД', 'MKD'],
            'MKD' => ['MKD', 'MKD'],
            'евра' => ['евра', 'EUR'],
            'евро' => ['Евро', 'EUR'],
            'симбол евро' => ['€', 'EUR'],
            'долари' => ['долари', 'USD'],
            'непознато останува' => ['јени', 'јени'],
        ];
    }
}
