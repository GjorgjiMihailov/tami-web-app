<?php

namespace Tests\Feature\Payroll;

use App\Models\PayrollParameter;
use App\Models\PayrollRunLine;
use App\Support\Payroll\LineType;
use App\Support\Payroll\PayrollRunCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollRunCalculatorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * July–December 2026, read from the seeded periods rather than built by
     * hand — the same source 5a's calculator is verified against, so the two
     * can never drift apart in a way a test would hide.
     */
    private function july2026(): PayrollParameter
    {
        return PayrollParameter::forDate('2026-07-31');
    }

    /** @return array{kind: string, code: ?string, description: string, hours: ?int, percent: ?float, amount: ?float, borne_by: string} */
    private function hoursLine(string $code, int $hours, float $percent): array
    {
        return [
            'kind' => PayrollRunLine::KIND_HOURS, 'code' => $code,
            'description' => 'ставка', 'hours' => $hours, 'percent' => $percent,
            'amount' => null, 'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
        ];
    }

    public function test_a_full_month_of_ordinary_hours_reproduces_the_agreed_gross(): void
    {
        $result = PayrollRunCalculator::calculate(
            fullMonthGross: 38507.0,
            monthHours: 184,
            seniorityYears: 0,
            inputLines: [$this->hoursLine('001', 184, 100.0)],
            parameters: $this->july2026(),
        );

        $this->assertSame(38507.0, round($result->gross, 2));
        $this->assertSame(26046, (int) round($result->breakdown->net));
    }

    /**
     * The invariant that ties 5b back to 5a. An employee agreed at a net
     * figure, working a plain full month, must be paid exactly that net —
     * otherwise the hourly split silently rewrites what was agreed.
     */
    public function test_an_agreed_net_survives_a_full_month_untouched(): void
    {
        $parameters = $this->july2026();

        $fullMonthGross = PayrollRunCalculator::fullMonthGross(30000.0, 'net', $parameters);

        $result = PayrollRunCalculator::calculate(
            fullMonthGross: $fullMonthGross,
            monthHours: 176,
            seniorityYears: 0,
            inputLines: [$this->hoursLine('001', 176, 100.0)],
            parameters: $parameters,
        );

        $this->assertSame(30000, (int) round($result->effectiveNet));
    }

    public function test_the_lines_always_sum_to_the_gross(): void
    {
        $result = PayrollRunCalculator::calculate(
            fullMonthGross: 40000.0,
            monthHours: 184,
            seniorityYears: 12,
            inputLines: [
                $this->hoursLine('001', 160, 100.0),
                $this->hoursLine('125', 16, 70.0),
                $this->hoursLine('005', 8, 135.0),
                [
                    'kind' => PayrollRunLine::KIND_AMOUNT, 'code' => '029',
                    'description' => 'Храна', 'hours' => null, 'percent' => null,
                    'amount' => 3000.0, 'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
                ],
            ],
            parameters: $this->july2026(),
        );

        $sum = 0.0;

        foreach ($result->lines as $line) {
            if ($line->kind !== PayrollRunLine::KIND_DEDUCTION) {
                $sum += $line->amount;
            }
        }

        $this->assertSame(round($result->gross, 2), round($sum, 2));
    }

    public function test_the_seniority_bonus_is_half_a_percent_of_the_base_lines_per_year(): void
    {
        $result = PayrollRunCalculator::calculate(
            fullMonthGross: 36800.0,
            monthHours: 184,
            seniorityYears: 20,
            inputLines: [
                $this->hoursLine('001', 184, 100.0),
                // Overtime and food must not be uplifted by seniority.
                $this->hoursLine('005', 10, 135.0),
                [
                    'kind' => PayrollRunLine::KIND_AMOUNT, 'code' => '029',
                    'description' => 'Храна', 'hours' => null, 'percent' => null,
                    'amount' => 3000.0, 'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
                ],
            ],
            parameters: $this->july2026(),
        );

        $seniority = null;

        foreach ($result->lines as $line) {
            if ($line->isAutomatic) {
                $seniority = $line;
            }
        }

        $this->assertNotNull($seniority);
        // 20 years × 0,5% = 10% of the 36 800 of base lines.
        $this->assertSame(3680.0, round($seniority->amount, 2));
    }

    public function test_a_seniority_line_arriving_as_input_is_not_paid_twice(): void
    {
        $manual = [
            'kind' => PayrollRunLine::KIND_AMOUNT, 'code' => '037',
            'description' => 'Минат труд', 'hours' => null, 'percent' => null,
            'amount' => 5000.0, 'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
        ];

        $result = PayrollRunCalculator::calculate(
            fullMonthGross: 36800.0,
            monthHours: 184,
            seniorityYears: 20,
            inputLines: [$this->hoursLine('001', 184, 100.0), $manual],
            parameters: $this->july2026(),
        );

        $seniorityLines = array_values(array_filter(
            $result->lines,
            fn ($line) => $line->code === LineType::SENIORITY_CODE
        ));

        $this->assertCount(1, $seniorityLines);
        $this->assertSame(3680.0, round($seniorityLines[0]->amount, 2));
        $this->assertTrue($seniorityLines[0]->isAutomatic);
        $this->assertSame(40480.0, round($result->gross, 2));
    }

    public function test_deductions_lower_the_effective_net_but_not_the_net(): void
    {
        $result = PayrollRunCalculator::calculate(
            fullMonthGross: 38507.0,
            monthHours: 184,
            seniorityYears: 0,
            inputLines: [
                $this->hoursLine('001', 184, 100.0),
                [
                    'kind' => PayrollRunLine::KIND_DEDUCTION, 'code' => null,
                    'description' => 'Кредит', 'hours' => null, 'percent' => null,
                    'amount' => 5000.0, 'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
                ],
            ],
            parameters: $this->july2026(),
        );

        $this->assertSame(26046, (int) round($result->breakdown->net));
        $this->assertSame(21046, (int) round($result->effectiveNet));
        $this->assertSame(5000.0, round($result->deductionsTotal, 2));
    }

    public function test_the_fund_borne_share_is_kept_out_of_the_employers_figures(): void
    {
        $fzoLine = $this->hoursLine('129', 92, 100.0);
        $fzoLine['borne_by'] = PayrollRunLine::BORNE_FZO;

        $result = PayrollRunCalculator::calculate(
            fullMonthGross: 38400.0,
            monthHours: 184,
            seniorityYears: 0,
            inputLines: [$this->hoursLine('001', 92, 100.0), $fzoLine],
            parameters: $this->july2026(),
        );

        // Half the hours are the Fund's, so half the gross is.
        $this->assertSame(19200.0, round($result->employerGross, 2));
        $this->assertSame(
            round($result->breakdown->contributions / 2, 2),
            round($result->employerContributions, 2)
        );
        $this->assertSame(round($result->breakdown->tax / 2, 2), round($result->employerTax, 2));
    }

    public function test_the_employers_figures_balance(): void
    {
        $fzoLine = $this->hoursLine('129', 40, 100.0);
        $fzoLine['borne_by'] = PayrollRunLine::BORNE_FZO;

        $result = PayrollRunCalculator::calculate(
            fullMonthGross: 45000.0,
            monthHours: 184,
            seniorityYears: 7,
            inputLines: [
                $this->hoursLine('001', 144, 100.0),
                $fzoLine,
                [
                    'kind' => PayrollRunLine::KIND_DEDUCTION, 'code' => null,
                    'description' => 'Административна забрана', 'hours' => null,
                    'percent' => null, 'amount' => 2500.0,
                    'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
                ],
            ],
            parameters: $this->july2026(),
        );

        // What the ledger will debit against what it will credit.
        $debit = $result->employerGross + $result->breakdown->topUp;
        $credit = ($result->employerContributions + $result->breakdown->topUp)
            + $result->employerTax
            + $result->deductionsTotal
            + $result->employerNet;

        $this->assertSame(round($debit, 2), round($credit, 2));
    }

    public function test_a_month_entirely_on_the_fund_leaves_the_employer_with_nothing(): void
    {
        $fzoLine = $this->hoursLine('129', 184, 100.0);
        $fzoLine['borne_by'] = PayrollRunLine::BORNE_FZO;

        $result = PayrollRunCalculator::calculate(
            fullMonthGross: 38507.0,
            monthHours: 184,
            seniorityYears: 0,
            inputLines: [$fzoLine],
            parameters: $this->july2026(),
        );

        $this->assertSame(0.0, round($result->employerGross, 2));
        $this->assertSame(0.0, round($result->employerContributions, 2));
        $this->assertSame(0.0, round($result->employerTax, 2));
    }

    /**
     * The 2026 rate change was exactly revenue-neutral: pension went up 1,1
     * points and unemployment came down 1,1. Both halves of the year therefore
     * charge 28% in total, and a net agreement costs the employer the same
     * gross in both — even though the individual rates differ.
     *
     * This is worth a test precisely because it is counter-intuitive. Someone
     * mistyping a future rate change breaks the equality; someone loading the
     * same period twice by accident breaks the inequality below it.
     */
    public function test_the_2026_rate_change_leaves_a_net_agreement_costing_the_same_gross(): void
    {
        $january = PayrollParameter::forDate('2026-01-31');
        $july = $this->july2026();

        $this->assertNotSame($january->rate_pension, $july->rate_pension);
        $this->assertNotSame($january->rate_unemployment, $july->rate_unemployment);

        $this->assertSame(
            $january->rate_pension + $january->rate_unemployment,
            $july->rate_pension + $july->rate_unemployment,
        );

        $januaryGross = PayrollRunCalculator::fullMonthGross(30000.0, 'net', $january);
        $julyGross = PayrollRunCalculator::fullMonthGross(30000.0, 'net', $july);

        $this->assertSame((int) round($januaryGross), (int) round($julyGross));
    }
}
