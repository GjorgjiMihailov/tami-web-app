<?php

namespace Tests\Unit\Payroll;

use App\Models\PayrollRunLine;
use App\Support\Payroll\LineType;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Every entry in OFFERED, not just the four spot-checked above. The
     * expected figures here are hardcoded independently of LineType.php —
     * NOT read back out of LineType::OFFERED — because the point is to
     * catch a transcription slip IN that table, and a test that reads its
     * expectation from the same table it is checking cannot do that.
     *
     * Sources: 125–128's percentages are printed directly in
     * database/data/payroll-codes/vid_nadomestoci.json's labels
     * ("Надоместок за боледување - 70%" etc, converted from
     * ujp_mpin_xml/VID_NADOMESTOCI.xls). 129, and the 003/005/007 uplifts,
     * are the business defaults recorded in the design spec's "Стандардни
     * проценти" section — a transcription slip there is just as silent,
     * since it also prices real hours.
     *
     */
    #[DataProvider('offeredCodeProvider')]
    public function test_every_offered_codes_default_percent_matches_its_table_entry(string $code, float $expectedPercent): void
    {
        $this->assertSame($expectedPercent, LineType::defaultPercent($code));
    }

    /** @return array<string, array{0: string, 1: float}> */
    public static function offeredCodeProvider(): array
    {
        return [
            '001' => ['001', 100.0], // Редовни работни часови
            '009' => ['009', 100.0], // Годишен одмор
            '010' => ['010', 100.0], // Државен празник
            '012' => ['012', 100.0], // Платено отсуство
            '003' => ['003', 135.0], // Ноќна работа
            '005' => ['005', 135.0], // Прекувремена работа
            '007' => ['007', 150.0], // Работа на државен празник
            '023' => ['023', 0.0],   // Неплатено отсуство
            '125' => ['125', 70.0],  // Боледување 70%
            '126' => ['126', 80.0],  // Боледување 80%
            '127' => ['127', 90.0],  // Боледување 90%
            '128' => ['128', 100.0], // Боледување 100%
            '129' => ['129', 70.0],  // Боледување на товар на ФЗО
            '029' => ['029', 100.0], // Храна (amount kind — placeholder)
            '030' => ['030', 100.0], // Превоз (amount kind — placeholder)
            '034' => ['034', 100.0], // Награда (amount kind — placeholder)
            '062' => ['062', 100.0], // Бонус за успешност (amount kind — placeholder)
        ];
    }

    /** Every code offered for manual entry must have a case above — otherwise a newly-added code is silently untested. */
    public function test_the_provider_covers_every_offered_code(): void
    {
        $this->assertSame(
            array_keys(LineType::OFFERED),
            array_keys(self::offeredCodeProvider())
        );
    }
}
