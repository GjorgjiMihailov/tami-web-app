<?php

namespace Tests\Feature\Payroll;

use App\Models\PayrollParameter;
use App\Support\Payroll\SalaryCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalaryCalculatorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * These are not invented numbers. Every figure below is УЈП's published
     * calculation of the 2026 minimum wage. If the calculator disagrees with
     * any of them by one denar, the calculator is wrong.
     */
    public function test_it_reproduces_the_published_minimum_wage_for_january_2026(): void
    {
        $breakdown = SalaryCalculator::fromGross(36037, PayrollParameter::forDate('2026-01-31'));

        $this->assertSame([
            'gross' => 36037,
            'pension' => 6775,
            'health' => 2703,
            'injury' => 180,
            'unemployment' => 432,
            'tax' => 1501,
            'net' => 24445,
        ], array_intersect_key($breakdown->whole(), array_flip([
            'gross', 'pension', 'health', 'injury', 'unemployment', 'tax', 'net',
        ])));
    }

    public function test_it_reproduces_the_published_minimum_wage_for_july_2026(): void
    {
        $breakdown = SalaryCalculator::fromGross(38507, PayrollParameter::forDate('2026-07-31'));

        $this->assertSame([
            'gross' => 38507,
            'pension' => 7663,
            'health' => 2888,
            'injury' => 193,
            'unemployment' => 39,
            'tax' => 1679,
            'net' => 26046,
        ], array_intersect_key($breakdown->whole(), array_flip([
            'gross', 'pension', 'health', 'injury', 'unemployment', 'tax', 'net',
        ])));
    }

    public function test_rounding_is_a_write_rule_not_a_calculation_step(): void
    {
        // Chaining rounded values instead of carrying two decimals gives 26.045
        // here. The published figure is 26.046. This test exists so that a
        // future "simplification" that rounds each step is caught immediately.
        $breakdown = SalaryCalculator::fromGross(38507, PayrollParameter::forDate('2026-07-31'));

        $this->assertSame(26046, $breakdown->whole()['net']);
        $this->assertNotSame(26045, $breakdown->whole()['net']);
    }

    public function test_the_top_up_to_the_minimum_base_does_not_reduce_the_employees_net(): void
    {
        $parameter = PayrollParameter::forDate('2026-07-31');
        $gross = 20000.0;

        $breakdown = SalaryCalculator::fromGross($gross, $parameter);

        // Contributions are charged on the employee's own gross...
        $this->assertSame(3980, $breakdown->whole()['pension']); // 20000 × 19,9%
        $this->assertSame(1500, $breakdown->whole()['health']);  // 20000 × 7,5%

        // ...and the top-up to 34.571 is reported separately, as employer cost.
        // 34571 − 20000 = 14571 short; 14571 × 19,9% = 2.899,629 → 2.900.
        $this->assertGreaterThan(0, $breakdown->whole()['topUp']);
        $this->assertSame(2900, $breakdown->whole()['topUpPension']);

        // The employee's net must be exactly what it would be with no minimum
        // base at all. This is the assertion that protects the worker from
        // paying somebody else's obligation.
        $contributions = 3980 + 1500 + 100 + 20;          // 19,9 + 7,5 + 0,5 + 0,1 %
        $taxBase = 20000 - $contributions - 10932;
        $expectedNet = (int) round(20000 - $contributions - $taxBase * 0.10);

        $this->assertSame($expectedNet, $breakdown->whole()['net']);
    }

    public function test_contributions_stop_at_the_maximum_base(): void
    {
        $parameter = PayrollParameter::forDate('2026-07-31');

        $atCap = SalaryCalculator::fromGross(1106256, $parameter);
        $aboveCap = SalaryCalculator::fromGross(1500000, $parameter);

        $this->assertSame($atCap->whole()['pension'], $aboveCap->whole()['pension']);
        $this->assertSame($atCap->whole()['health'], $aboveCap->whole()['health']);
    }

    public function test_the_tax_base_never_goes_below_zero(): void
    {
        // Gross under the personal allowance: no tax, and net is gross minus
        // contributions only.
        $breakdown = SalaryCalculator::fromGross(8000, PayrollParameter::forDate('2026-07-31'));

        $this->assertSame(0, $breakdown->whole()['tax']);
        $this->assertSame(0, $breakdown->whole()['taxBase']);
    }

    /**
     * A part-month gross measured against a whole-month floor charges a whole
     * month of minimum-base contributions for half a month of insurance. The
     * caller supplies the prorated floor; the calculator only has to honour it.
     */
    public function test_an_explicit_floor_replaces_the_statutory_minimum_base(): void
    {
        $parameter = PayrollParameter::forDate('2026-07-31');

        // Half of August's minimum base by МПИН's /30 × days rule:
        // 34.571 / 30 × 16 = 18.438,13.
        $breakdown = SalaryCalculator::fromGross(15000, $parameter, 18438.13);

        // 18.438,13 − 15.000 = 3.438,13 short.
        $this->assertSame(round(3438.13 * 19.9 / 100, 2), $breakdown->topUpPension);
        $this->assertSame(round(3438.13 * 7.5 / 100, 2), $breakdown->topUpHealth);

        // The employee's own side is untouched by the floor, exactly as it is
        // when the floor is the statutory one.
        $this->assertSame(
            SalaryCalculator::fromGross(15000, $parameter)->net,
            $breakdown->net
        );
    }

    public function test_omitting_the_floor_keeps_the_statutory_minimum_base(): void
    {
        $parameter = PayrollParameter::forDate('2026-07-31');

        $this->assertSame(
            SalaryCalculator::fromGross(20000, $parameter, 34571.0)->topUp,
            SalaryCalculator::fromGross(20000, $parameter)->topUp
        );
    }

    /**
     * fromNet() binary-searches through fromGross(), so a net-basis employee
     * solved against the statutory floor while the run prorates it would land
     * on a different gross than the one the run then posts.
     */
    public function test_the_net_to_gross_search_solves_against_the_floor_it_was_given(): void
    {
        $parameter = PayrollParameter::forDate('2026-07-31');

        $breakdown = SalaryCalculator::fromNet(12000, $parameter, 18438.13);

        $this->assertSame(
            SalaryCalculator::fromGross($breakdown->gross, $parameter, 18438.13)->topUp,
            $breakdown->topUp
        );

        // The net side is unaffected by the floor — the search must still land
        // on the same gross as an unprorated run would.
        $this->assertSame(
            SalaryCalculator::fromNet(12000, $parameter)->gross,
            $breakdown->gross
        );
    }
}
