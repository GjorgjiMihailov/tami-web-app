<?php

namespace Tests\Unit;

use App\Support\Posting\VatGroup;
use PHPUnit\Framework\TestCase;

class VatGroupTest extends TestCase
{
    public function test_a_line_falls_in_a_group_by_treatment_and_rate(): void
    {
        $this->assertSame(VatGroup::GENERAL, VatGroup::forLine('standard', '18.00'));
        $this->assertSame(VatGroup::GENERAL, VatGroup::forLine('standard', '0.00'));
        $this->assertSame(VatGroup::REDUCED, VatGroup::forLine('standard', '5.00'));
        $this->assertSame(VatGroup::REDUCED, VatGroup::forLine('standard', '10'));
        $this->assertSame(VatGroup::EXPORT, VatGroup::forLine('export', '0.00'));
        $this->assertSame(VatGroup::EXEMPT_WITH_CREDIT, VatGroup::forLine('exempt_with_credit', '0.00'));
        $this->assertSame(VatGroup::EXEMPT_WITHOUT_CREDIT, VatGroup::forLine('exempt_without_credit', '0.00'));
    }
}
