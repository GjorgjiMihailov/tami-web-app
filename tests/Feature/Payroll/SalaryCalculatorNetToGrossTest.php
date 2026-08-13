<?php

namespace Tests\Feature\Payroll;

use App\Models\PayrollParameter;
use App\Support\Payroll\SalaryCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SalaryCalculatorNetToGrossTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_recovers_the_published_january_minimum_wage_from_its_net(): void
    {
        $breakdown = SalaryCalculator::fromNet(24445, PayrollParameter::forDate('2026-01-31'));

        $this->assertSame(36037, $breakdown->whole()['gross']);
    }

    public function test_it_recovers_the_published_july_minimum_wage_from_its_net(): void
    {
        $breakdown = SalaryCalculator::fromNet(26046, PayrollParameter::forDate('2026-07-31'));

        $this->assertSame(38507, $breakdown->whole()['gross']);
    }

    public function test_a_net_contract_costs_more_gross_after_the_july_rate_change(): void
    {
        $june = SalaryCalculator::fromNet(30000, PayrollParameter::forDate('2026-06-30'));
        $july = SalaryCalculator::fromNet(30000, PayrollParameter::forDate('2026-07-31'));

        // ПИО went 18,8% → 19,9% while unemployment went 1,2% → 0,1%, so the
        // total stayed at 28% — but the tax base moves, so gross is not
        // identical. Whichever way it moves, the employee still gets 30.000.
        $this->assertSame(30000, $june->whole()['net']);
        $this->assertSame(30000, $july->whole()['net']);
    }

    public function test_the_round_trip_is_stable_across_a_range_of_salaries(): void
    {
        $parameter = PayrollParameter::forDate('2026-07-31');

        // 8000 is below the 10 932 personal allowance, so it exercises the
        // zero floor on the tax base — the other kink in the curve, distinct
        // from the minimum-base kink the values above already cover.
        foreach ([8000, 15000, 25000, 40000, 75000, 250000] as $gross) {
            $net = SalaryCalculator::fromGross($gross, $parameter)->whole()['net'];
            $recovered = SalaryCalculator::fromNet($net, $parameter)->whole()['gross'];

            $this->assertSame(
                $gross,
                $recovered,
                "Net {$net} should have recovered a gross of {$gross}, got {$recovered}."
            );
        }
    }

    public function test_a_negative_net_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SalaryCalculator::fromNet(-5000, PayrollParameter::forDate('2026-07-31'));
    }

    public function test_a_net_of_zero_is_not_caught_by_the_negative_guard(): void
    {
        $breakdown = SalaryCalculator::fromNet(0, PayrollParameter::forDate('2026-07-31'));

        $this->assertSame(0, $breakdown->whole()['net']);
    }
}
