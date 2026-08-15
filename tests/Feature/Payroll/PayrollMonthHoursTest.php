<?php

namespace Tests\Feature\Payroll;

use App\Models\PayrollMonthHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PayrollMonthHoursTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_finds_the_fund_for_a_month(): void
    {
        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);

        $this->assertSame(184, PayrollMonthHours::forMonth(2026, 7)->hours);
    }

    public function test_it_refuses_a_month_that_was_never_entered(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Нема внесен фонд на часови за 8/2026.');

        PayrollMonthHours::forMonth(2026, 8);
    }

    public function test_a_month_cannot_be_entered_twice(): void
    {
        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 176]);
    }
}
