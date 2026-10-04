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
            new ScannedCustomsItem('61091000', 'a', '234.61', '14474', ['A00' => '2533', 'B00' => '3061']),
            new ScannedCustomsItem('58063210', 'b', '38.67', '2386', ['A00' => '239', 'B00' => '472']),
            new ScannedCustomsItem('61091000', 'c', '193.36', '11929', ['A00' => '2088', 'B00' => '2523']),
        ]);

        $this->assertSame([
            ['tariff_code' => '61091000', 'foreign_amount' => '427.97', 'customs_duty' => '4621.00', 'vat_amount' => '5584.00'],
            ['tariff_code' => '58063210', 'foreign_amount' => '38.67', 'customs_duty' => '239.00', 'vat_amount' => '472.00'],
        ], $result['rows']);
        $this->assertSame('4860.00', $result['duty_total']);
        $this->assertSame('6056.00', $result['vat_total']);
        $this->assertSame('466.64', $result['foreign_total']);
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

    public function test_no_items_gives_empty_result(): void
    {
        $result = (new CustomsTariffAggregator)->aggregate([]);

        $this->assertSame([], $result['rows']);
        $this->assertSame('0.00', $result['duty_total']);
    }
}
