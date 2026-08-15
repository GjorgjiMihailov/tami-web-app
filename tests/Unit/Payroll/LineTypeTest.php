<?php

namespace Tests\Unit\Payroll;

use App\Models\PayrollRunLine;
use App\Support\Payroll\LineType;
use PHPUnit\Framework\TestCase;

class LineTypeTest extends TestCase
{
    public function test_ordinary_sick_leave_is_borne_by_the_employer(): void
    {
        foreach (['125', '126', '127', '128'] as $code) {
            $this->assertSame(PayrollRunLine::BORNE_EMPLOYER, LineType::borneBy($code));
        }
    }

    public function test_the_fund_bears_its_own_sick_leave(): void
    {
        // 129 is "Надоместок на плата за боледување што го исплатува ФЗО".
        // The company calculates it and declares it; the Fund carries it.
        $this->assertSame(PayrollRunLine::BORNE_FZO, LineType::borneBy('129'));
    }

    public function test_other_state_bodies_bear_their_own_allowances(): void
    {
        foreach (['132', '138', '139'] as $code) {
            $this->assertSame(PayrollRunLine::BORNE_FZO, LineType::borneBy($code));
        }
    }

    public function test_everything_else_falls_on_the_employer(): void
    {
        $this->assertSame(PayrollRunLine::BORNE_EMPLOYER, LineType::borneBy('001'));
        $this->assertSame(PayrollRunLine::BORNE_EMPLOYER, LineType::borneBy('005'));
        $this->assertSame(PayrollRunLine::BORNE_EMPLOYER, LineType::borneBy(null));
    }

    public function test_the_seniority_code_is_never_offered_for_manual_entry(): void
    {
        // It is derived from length of service and appended by the calculator.
        // Offering it would let it be entered on top of the appended one.
        $this->assertArrayNotHasKey(LineType::SENIORITY_CODE, LineType::OFFERED);
        $this->assertSame('Минат труд', LineType::SENIORITY_LABEL);
    }

    public function test_the_statutory_uplifts(): void
    {
        $this->assertSame(135.0, LineType::defaultPercent('005')); // overtime
        $this->assertSame(135.0, LineType::defaultPercent('003')); // night work
        $this->assertSame(150.0, LineType::defaultPercent('007')); // public holiday work
        $this->assertSame(100.0, LineType::defaultPercent('001')); // ordinary hours
    }
}
