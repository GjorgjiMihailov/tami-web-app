<?php

namespace Tests\Feature;

use App\Models\PayrollParameter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PayrollParameterTest extends TestCase
{
    use RefreshDatabase;

    public function test_february_2026_uses_the_original_rates(): void
    {
        $p = PayrollParameter::forDate('2026-02-28');

        $this->assertSame(18.8, $p->rate_pension);
        $this->assertSame(1.2, $p->rate_unemployment);
        $this->assertSame(36037.0, $p->minimum_wage);
    }

    public function test_april_2026_keeps_the_rates_but_raises_the_minimum_wage(): void
    {
        $p = PayrollParameter::forDate('2026-04-15');

        $this->assertSame(18.8, $p->rate_pension);
        $this->assertSame(38507.0, $p->minimum_wage);
    }

    public function test_august_2026_uses_the_new_rates(): void
    {
        // Confirmed by the user: the new rates apply from the JULY salary.
        $p = PayrollParameter::forDate('2026-08-01');

        $this->assertSame(19.9, $p->rate_pension);
        $this->assertSame(0.1, $p->rate_unemployment);
        $this->assertSame(7.5, $p->rate_health);
        $this->assertSame(0.5, $p->rate_injury);
    }

    public function test_the_shared_values_are_the_published_ones(): void
    {
        $p = PayrollParameter::forDate('2026-08-01');

        $this->assertSame(10932.0, $p->personal_allowance);
        $this->assertSame(69141.0, $p->average_salary);
        // Deliberately NOT 50% of the average — 34.570,5 rounds to УЈП's 34.571.
        $this->assertSame(34571.0, $p->min_base);
        $this->assertSame(1106256.0, $p->max_base);
        $this->assertSame(10.0, $p->rate_tax);
    }

    public function test_it_refuses_a_date_before_any_known_period(): void
    {
        $this->expectException(RuntimeException::class);

        PayrollParameter::forDate('2019-01-01');
    }

    public function test_the_exact_boundary_date_uses_the_new_period(): void
    {
        // The day before 2026-07-01: must use old rates (18.8, 1.2)
        $p_before = PayrollParameter::forDate('2026-06-30');
        $this->assertSame(18.8, $p_before->rate_pension);
        $this->assertSame(1.2, $p_before->rate_unemployment);

        // The exact boundary date 2026-07-01: must use new rates (19.9, 0.1)
        // This catches off-by-one errors in forDate() that would flip <= to <
        $p_on = PayrollParameter::forDate('2026-07-01');
        $this->assertSame(19.9, $p_on->rate_pension);
        $this->assertSame(0.1, $p_on->rate_unemployment);
    }

    public function test_parameter_created_through_model_is_found_on_its_effective_from_date(): void
    {
        // Critical test: verifies that parameters created through the model (which
        // triggers the date cast on write) are still found by forDate() on their own
        // effective_from date. This catches date/datetime serialization mismatches.
        PayrollParameter::create([
            'effective_from' => '2027-01-01',
            'rate_pension' => 20.0,
            'rate_health' => 8.0,
            'rate_injury' => 0.5,
            'rate_unemployment' => 0.5,
            'rate_tax' => 10.0,
            'personal_allowance' => 11000.0,
            'average_salary' => 70000.0,
            'min_base' => 35000.0,
            'max_base' => 1120000.0,
            'minimum_wage' => 40000.0,
        ]);

        $p = PayrollParameter::forDate('2027-01-01');

        $this->assertSame(20.0, $p->rate_pension);
    }
}
