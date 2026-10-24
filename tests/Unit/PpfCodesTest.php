<?php

namespace Tests\Unit;

use App\Support\Ppf\PpfCodes;
use Carbon\Carbon;
use Tests\TestCase;

class PpfCodesTest extends TestCase
{
    public function test_every_code_of_the_ujp_lists_is_present(): void
    {
        $this->assertCount(28, PpfCodes::types());
        $this->assertCount(81, PpfCodes::subtypes());
        $this->assertSame(['00001', '00002'], array_column(PpfCodes::paymentTypes(), 'code'));
    }

    public function test_the_codes_used_in_real_filings_exist_under_the_right_type(): void
    {
        foreach ([['T1', '1014'], ['T1', '1012'], ['T1', '1007'], ['T1', '1016'], ['T3', '3004'], ['T7', '7001'], ['T6', '6002']] as [$type, $sub]) {
            $this->assertTrue(PpfCodes::subtypeBelongsTo($type, $sub), "$type/$sub");
        }
    }

    public function test_every_subtype_belongs_to_an_existing_type(): void
    {
        foreach (PpfCodes::subtypes() as $subtype) {
            $this->assertTrue(PpfCodes::typeExists($subtype['type']), $subtype['code']);
        }
    }

    public function test_a_subtype_does_not_belong_to_another_type(): void
    {
        $this->assertFalse(PpfCodes::subtypeBelongsTo('T3', '1014'));
        $this->assertFalse(PpfCodes::subtypeBelongsTo('T1', '9999'));
        $this->assertFalse(PpfCodes::subtypeBelongsTo('7', '1014'));
    }

    public function test_types_without_a_subtype_in_the_list_are_known(): void
    {
        foreach (['T2', 'T8', '15', '18', '19'] as $type) {
            $this->assertTrue(PpfCodes::typeExists($type));
            $this->assertFalse(PpfCodes::typeHasSubtypes($type), $type);
        }
        $this->assertTrue(PpfCodes::typeHasSubtypes('T1'));
    }

    public function test_the_old_codes_are_valid_only_in_2018_and_the_new_ones_from_2019(): void
    {
        $this->assertTrue(PpfCodes::typeValidOn('7', Carbon::parse('2018-06-30')));
        $this->assertFalse(PpfCodes::typeValidOn('7', Carbon::parse('2019-01-01')));
        $this->assertFalse(PpfCodes::typeValidOn('T1', Carbon::parse('2018-12-31')));
        $this->assertTrue(PpfCodes::typeValidOn('T1', Carbon::parse('2026-10-24')));
        $this->assertFalse(PpfCodes::typeValidOn('NEMA', Carbon::parse('2026-10-24')));
    }

    public function test_the_offer_for_a_date_has_only_the_valid_codes(): void
    {
        $now = array_column(PpfCodes::typesFor(Carbon::parse('2026-10-24')), 'code');
        $old = array_column(PpfCodes::typesFor(Carbon::parse('2018-03-01')), 'code');

        $this->assertContains('T1', $now);
        $this->assertNotContains('7', $now);
        $this->assertContains('7', $old);
        $this->assertNotContains('T1', $old);
        $this->assertSame($now, array_column(PpfCodes::typesFor(null), 'code'));
    }

    public function test_names_are_available(): void
    {
        $this->assertStringContainsString('консултантски', PpfCodes::subtypeName('1014'));
        $this->assertNull(PpfCodes::typeName('NEMA'));
    }
}
