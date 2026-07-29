<?php

namespace Tests\Unit;

use App\Support\Format;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    public function test_date_formats_a_carbon_instance(): void
    {
        $this->assertSame('26.07.2026', Format::date(Carbon::create(2026, 7, 26)));
    }

    public function test_date_formats_a_date_string(): void
    {
        $this->assertSame('01.01.2026', Format::date('2026-01-01'));
    }

    public function test_money_formats_with_default_currency_and_decimals(): void
    {
        $this->assertSame('1.234,56 ден', Format::money('1234.56'));
    }

    public function test_money_formats_with_empty_currency(): void
    {
        $this->assertSame('1.234,56', Format::money('1234.56', currency: ''));
    }

    public function test_money_formats_with_custom_decimals(): void
    {
        $this->assertSame('12,3400', Format::money('12.34', currency: '', decimals: 4));
    }

    public function test_money_formats_negative_amounts(): void
    {
        $this->assertSame('-500,00 ден', Format::money('-500'));
    }

    public function test_money_formats_zero(): void
    {
        $this->assertSame('0,00 ден', Format::money(0));
    }

    public function test_invoice_status_maps_known_values(): void
    {
        $this->assertSame('Нацрт', Format::invoiceStatus('draft'));
        $this->assertSame('Потврдена', Format::invoiceStatus('confirmed'));
        $this->assertSame('Откажана', Format::invoiceStatus('cancelled'));
    }

    public function test_payment_status_maps_known_values(): void
    {
        $this->assertSame('Платена', Format::paymentStatus('paid'));
        $this->assertSame('Неплатена', Format::paymentStatus('unpaid'));
        $this->assertSame('Делумно платена', Format::paymentStatus('partially_paid'));
    }

    public function test_movement_type_maps_known_values(): void
    {
        $this->assertSame('Прием', Format::movementType('receipt'));
        $this->assertSame('Издавање', Format::movementType('issue'));
        $this->assertSame('Трансфер', Format::movementType('transfer'));
        $this->assertSame('Корекција', Format::movementType('adjustment'));
    }

    public function test_vat_treatment_maps_known_values(): void
    {
        $this->assertSame('Стандардна', Format::vatTreatment('standard'));
        $this->assertSame('Извоз', Format::vatTreatment('export'));
        $this->assertSame('ослободено со право на одбивка', Format::vatTreatment('exempt_with_credit'));
        $this->assertSame('ослободено без право на одбивка', Format::vatTreatment('exempt_without_credit'));
    }

    public function test_payment_method_maps_known_values(): void
    {
        $this->assertSame('Банка', Format::paymentMethod('bank'));
        $this->assertSame('Готовина', Format::paymentMethod('cash'));
    }

    public function test_document_category_maps_known_values(): void
    {
        $this->assertSame('Фактура', Format::documentCategory('Invoice'));
        $this->assertSame('Договор', Format::documentCategory('Contract'));
        $this->assertSame('Извод од банка', Format::documentCategory('Bank Statement'));
        $this->assertSame('Сметка', Format::documentCategory('Receipt'));
        $this->assertSame('Документ за регистрација', Format::documentCategory('ID/Registration'));
        $this->assertSame('Друго', Format::documentCategory('Other'));
    }

    public function test_unmapped_enum_values_fall_back_to_ucfirst_or_original(): void
    {
        $this->assertSame('Somethingnew', Format::invoiceStatus('somethingnew'));
        $this->assertSame('Unknown', Format::documentCategory('Unknown'));
    }

    public function test_partner_type_maps_to_macedonian_labels(): void
    {
        $this->assertSame('Физичко лице', Format::partnerType('individual'));
        $this->assertSame('Правно лице', Format::partnerType('legal_entity'));
    }

    public function test_item_type_maps_to_macedonian_labels(): void
    {
        $this->assertSame('Производ', Format::itemType('product'));
        $this->assertSame('Услуга', Format::itemType('service'));
    }
}
