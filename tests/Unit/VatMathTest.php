<?php

namespace Tests\Unit;

use App\Support\VatMath;
use PHPUnit\Framework\TestCase;

class VatMathTest extends TestCase
{
    public function test_it_builds_a_gross_price_from_a_net_price(): void
    {
        $this->assertSame('118.00', VatMath::grossFromNet('100.00', '18.00'));
        $this->assertSame('105.00', VatMath::grossFromNet('100.00', '5.00'));
        $this->assertSame('100.00', VatMath::grossFromNet('100.00', '0.00'));
    }

    public function test_it_builds_a_net_price_from_a_gross_price(): void
    {
        $this->assertSame('100.00', VatMath::netFromGross('118.00', '18.00'));
        $this->assertSame('100.00', VatMath::netFromGross('105.00', '5.00'));
        $this->assertSame('100.00', VatMath::netFromGross('100.00', '0.00'));
    }

    public function test_a_gross_price_that_does_not_divide_cleanly_rounds_up_on_the_way_back(): void
    {
        // 100 / 1.18 = 84.745762... which has to round to 84.75, and 84.75 back
        // out is 100.005 — the extra tenth of a denar the user has to see.
        $net = VatMath::netFromGross('100.00', '18.00');

        $this->assertSame('84.75', $net);
        $this->assertSame('100.01', VatMath::grossFromNet($net, '18.00'));
    }

    public function test_it_computes_a_vat_amount_with_half_up_rounding(): void
    {
        $this->assertSame('18.00', VatMath::vatAmount('100.00', '18.00'));
        $this->assertSame('0.00', VatMath::vatAmount('100.00', '0.00'));
        // 12.25 * 0.18 = 2.205 — half up, not truncated to 2.20.
        $this->assertSame('2.21', VatMath::vatAmount('12.25', '18.00'));
    }

    public function test_it_multiplies_a_quantity_by_a_unit_price(): void
    {
        $this->assertSame('250.00', VatMath::lineNet('5', '50.00'));
        $this->assertSame('37.50', VatMath::lineNet('2.5', '15.00'));
        // Three-decimal quantities are real: 0.125 * 10.10 = 1.2625 -> 1.26.
        $this->assertSame('1.26', VatMath::lineNet('0.125', '10.10'));
    }

    public function test_unusable_input_counts_as_zero_instead_of_blowing_up(): void
    {
        $this->assertSame('0.00', VatMath::grossFromNet('', '18.00'));
        $this->assertSame('0.00', VatMath::netFromGross('', '18.00'));
        $this->assertSame('0.00', VatMath::vatAmount('abc', '18.00'));
        $this->assertSame('0.00', VatMath::lineNet('', ''));
        $this->assertSame('100.00', VatMath::grossFromNet('100.00', 'не е број'));
        $this->assertSame('100.00', VatMath::netFromGross('100.00', ''));
    }

    public function test_a_rate_that_would_divide_by_zero_returns_zero(): void
    {
        $this->assertSame('0.00', VatMath::netFromGross('100.00', '-100'));
    }

    public function test_negative_amounts_round_away_from_zero(): void
    {
        $this->assertSame('-2.21', VatMath::vatAmount('-12.25', '18.00'));
        $this->assertSame('-118.00', VatMath::grossFromNet('-100.00', '18.00'));
    }
}
