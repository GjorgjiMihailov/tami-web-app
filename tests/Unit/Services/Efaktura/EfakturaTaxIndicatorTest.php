<?php

namespace Tests\Unit\Services\Efaktura;

use App\Services\Efaktura\EfakturaTaxIndicator;
use PHPUnit\Framework\TestCase;

class EfakturaTaxIndicatorTest extends TestCase
{
    public function test_standard_18_percent_maps_to_ddv_a(): void
    {
        $this->assertSame('DDV-A', EfakturaTaxIndicator::code('standard', '18.00'));
        $this->assertSame(18.0, EfakturaTaxIndicator::percent('standard', '18.00'));
    }

    public function test_standard_10_percent_maps_to_ddv_v(): void
    {
        $this->assertSame('DDV-V', EfakturaTaxIndicator::code('standard', '10.00'));
        $this->assertSame(10.0, EfakturaTaxIndicator::percent('standard', '10.00'));
    }

    public function test_standard_5_percent_maps_to_ddv_b(): void
    {
        $this->assertSame('DDV-B', EfakturaTaxIndicator::code('standard', '5.00'));
        $this->assertSame(5.0, EfakturaTaxIndicator::percent('standard', '5.00'));
    }

    public function test_standard_0_percent_maps_to_ddv_g_not_a_vat_payer(): void
    {
        // This is the default state for every line of every non-VAT-registered
        // company (see SalesInvoiceForm::emptyLine()), not a hypothetical edge
        // case — DDV-G is УЈП's real code for "Не е ДДВ обврзник" (member 51
        // став 3 ЗДДВ), confirmed in danocni_indikatori_27072026.pdf.
        $this->assertSame('DDV-G', EfakturaTaxIndicator::code('standard', '0.00'));
        $this->assertSame(0.0, EfakturaTaxIndicator::percent('standard', '0.00'));
    }

    public function test_export_maps_to_ddv_7_i(): void
    {
        $this->assertSame('DDV-7-I', EfakturaTaxIndicator::code('export', '0.00'));
        $this->assertSame(0.0, EfakturaTaxIndicator::percent('export', '0.00'));
    }

    public function test_exempt_with_credit_maps_to_ddv_8(): void
    {
        $this->assertSame('DDV-8', EfakturaTaxIndicator::code('exempt_with_credit', '0.00'));
    }

    public function test_exempt_without_credit_maps_to_ddv_9(): void
    {
        $this->assertSame('DDV-9', EfakturaTaxIndicator::code('exempt_without_credit', '0.00'));
    }

    public function test_unknown_combination_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EfakturaTaxIndicator::code('standard', '7.00');
    }

    public function test_from_code_maps_ddv_a_to_18_percent(): void
    {
        $this->assertSame('18.00', EfakturaTaxIndicator::fromCode('DDV-A'));
    }

    public function test_from_code_maps_ddv_v_to_10_percent(): void
    {
        $this->assertSame('10.00', EfakturaTaxIndicator::fromCode('DDV-V'));
    }

    public function test_from_code_maps_ddv_b_to_5_percent(): void
    {
        $this->assertSame('5.00', EfakturaTaxIndicator::fromCode('DDV-B'));
    }

    public function test_from_code_maps_ddv_g_and_exempt_codes_to_0_percent(): void
    {
        $this->assertSame('0.00', EfakturaTaxIndicator::fromCode('DDV-G'));
        $this->assertSame('0.00', EfakturaTaxIndicator::fromCode('DDV-7-I'));
        $this->assertSame('0.00', EfakturaTaxIndicator::fromCode('DDV-8'));
        $this->assertSame('0.00', EfakturaTaxIndicator::fromCode('DDV-9'));
    }

    public function test_from_code_returns_null_for_an_unsupported_member_32_code(): void
    {
        // DDV-11-A (member-32-а reverse charge) is a real code a supplier could legally send
        // on an incoming invoice — this app doesn't model reverse-charge, so it must come back
        // null (caller flags the line needs_review) rather than throw or silently default.
        $this->assertNull(EfakturaTaxIndicator::fromCode('DDV-11-A'));
    }

    public function test_from_code_returns_null_for_an_unknown_code(): void
    {
        $this->assertNull(EfakturaTaxIndicator::fromCode('NOT-A-REAL-CODE'));
    }
}
