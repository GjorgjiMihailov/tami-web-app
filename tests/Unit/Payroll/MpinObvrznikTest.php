<?php

namespace Tests\Unit\Payroll;

use App\Support\Payroll\MpinObvrznik;
use PHPUnit\Framework\TestCase;

class MpinObvrznikTest extends TestCase
{
    public function test_an_employer_pays_unemployment_and_monthly_tax(): void
    {
        $this->assertTrue(MpinObvrznik::EMPLOYER->chargesUnemployment());
        $this->assertTrue(MpinObvrznik::EMPLOYER->chargesMonthlyTax());
        $this->assertSame('110', MpinObvrznik::EMPLOYER->value);
    }

    public function test_a_self_employed_person_pays_neither(): void
    {
        $this->assertFalse(MpinObvrznik::SELF_EMPLOYED->chargesUnemployment());
        $this->assertFalse(MpinObvrznik::SELF_EMPLOYED->chargesMonthlyTax());
        $this->assertSame('111', MpinObvrznik::SELF_EMPLOYED->value);
    }

    public function test_every_case_carries_a_macedonian_label(): void
    {
        foreach (MpinObvrznik::cases() as $case) {
            $this->assertNotSame('', $case->label());
        }
    }
}
