<?php

namespace Tests\Unit\Services\Inventory;

use App\Services\Inventory\CustomsTariffAggregator;
use App\Services\Invoicing\ScannedCustomsItem;
use PHPUnit\Framework\TestCase;

class CustomsTariffAggregatorTest extends TestCase
{
    public function test_it_sums_items_by_tariff_code_in_first_seen_order(): void
    {
        $result = (new CustomsTariffAggregator)->aggregate([
            new ScannedCustomsItem('61091000', 'a', '100.50', '6200', ['A00' => '1250', 'B00' => '800']),
            new ScannedCustomsItem('58063210', 'b', '20.00', '1230', ['A00' => '200', 'B00' => '400']),
            new ScannedCustomsItem('61091000', 'c', '80.25', '4900', ['A00' => '1000', 'B00' => '650']),
        ]);

        $this->assertSame([
            ['tariff_code' => '61091000', 'foreign_amount' => '180.75', 'customs_duty' => '2250.00', 'vat_amount' => '1450.00'],
            ['tariff_code' => '58063210', 'foreign_amount' => '20.00', 'customs_duty' => '200.00', 'vat_amount' => '400.00'],
        ], $result['rows']);
        $this->assertSame('2450.00', $result['duty_total']);
        $this->assertSame('1850.00', $result['vat_total']);
        $this->assertSame('200.75', $result['foreign_total']);
        $this->assertSame([], $result['other_codes']);
    }

    public function test_other_charge_codes_are_reported_and_not_summed(): void
    {
        $result = (new CustomsTariffAggregator)->aggregate([
            new ScannedCustomsItem('61091000', 'a', '10.00', '600', ['A00' => '50', 'B00' => '100', 'A10' => '30', 'C99' => '5']),
            new ScannedCustomsItem('61091000', 'b', '10.00', '600', ['A10' => '30']),
        ]);

        $this->assertSame(['A10', 'C99'], $result['other_codes']);
        $this->assertSame('50.00', $result['rows'][0]['customs_duty']);
    }

    public function test_unreadable_amounts_count_as_zero_and_a_missing_tariff_code_gets_a_dash(): void
    {
        $result = (new CustomsTariffAggregator)->aggregate([
            new ScannedCustomsItem(null, 'a', '1?.00', '', ['A00' => 'x', 'B00' => '']),
        ]);

        $this->assertSame('—', $result['rows'][0]['tariff_code']);
        $this->assertSame('0.00', $result['rows'][0]['foreign_amount']);
        $this->assertSame('0.00', $result['rows'][0]['customs_duty']);
    }

    public function test_amounts_bcmath_would_reject_count_as_zero_instead_of_throwing(): void
    {
        $result = (new CustomsTariffAggregator)->aggregate([
            new ScannedCustomsItem('61091000', 'a', ' 12', '12 ', ['A00' => '1e3', 'B00' => ' 5']),
            new ScannedCustomsItem('58063210', 'b', '1e3', ' 12', ['A00' => '7', 'B00' => '8 ']),
        ]);

        $this->assertSame('0.00', $result['rows'][0]['foreign_amount']);
        $this->assertSame('0.00', $result['rows'][0]['customs_duty']);
        $this->assertSame('0.00', $result['rows'][0]['vat_amount']);
        $this->assertSame('7.00', $result['rows'][1]['customs_duty']);
        $this->assertSame('0.00', $result['foreign_total']);
        $this->assertSame('7.00', $result['duty_total']);
        $this->assertSame('0.00', $result['vat_total']);
    }

    public function test_tariff_codes_differing_only_by_whitespace_merge_into_one_row(): void
    {
        $result = (new CustomsTariffAggregator)->aggregate([
            new ScannedCustomsItem(' 61091000', 'a', '1.00', '1', []),
            new ScannedCustomsItem('61091000 ', 'b', '2.00', '2', []),
            new ScannedCustomsItem('   ', 'c', '3.00', '3', []),
        ]);

        $this->assertCount(2, $result['rows']);
        $this->assertSame('61091000', $result['rows'][0]['tariff_code']);
        $this->assertSame('3.00', $result['rows'][0]['foreign_amount']);
        $this->assertSame('—', $result['rows'][1]['tariff_code']);
    }

    public function test_no_items_gives_empty_result(): void
    {
        $result = (new CustomsTariffAggregator)->aggregate([]);

        $this->assertSame([], $result['rows']);
        $this->assertSame('0.00', $result['duty_total']);
    }
}
