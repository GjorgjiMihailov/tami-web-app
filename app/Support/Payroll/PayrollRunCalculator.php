<?php

namespace App\Support\Payroll;

use App\Models\PayrollParameter;
use App\Models\PayrollRunLine;

/**
 * Turns a month's lines into money.
 *
 * Deliberately free of models, database and request state: the two invariants
 * this must satisfy — an agreed net survives a plain month untouched, and the
 * lines sum to the gross — are properties of the arithmetic alone.
 *
 * Two decimals throughout. Rounding to whole denars is МПИН's write rule, not
 * a step here; chaining rounded figures produces 26 045 where the published
 * table says 26 046.
 */
final class PayrollRunCalculator
{
    /**
     * The gross a full month would pay. For a net agreement this is where the
     * agreement is converted, once, before hours enter the picture — so the
     * hourly rate divides a gross that already honours the agreed net.
     *
     * Deliberately solved against the unprorated `min_base`, whatever the
     * employee's days of insurance: this figure defines what a whole month is
     * worth, and the per-hour rate everything else derives from divides it.
     * Prorating here would move the hourly rate itself.
     */
    public static function fullMonthGross(
        float $amount,
        string $basis,
        PayrollParameter $parameters,
        MpinObvrznik $obvrznik = MpinObvrznik::EMPLOYER,
    ): float {
        return $basis === 'net'
            ? SalaryCalculator::fromNet($amount, $parameters, null, $obvrznik)->gross
            : round($amount, 2);
    }

    /**
     * @param  list<array{kind: string, code: ?string, description: string, hours: ?int, percent: ?float, amount: ?float, borne_by: string}>  $inputLines
     * @param  float|null  $minBase  The floor the minimum-base top-up is measured
     *                               against. Null keeps the statutory monthly
     *                               figure; a run prorates it for an employee
     *                               insured only part of the month.
     */
    public static function calculate(
        float $fullMonthGross,
        int $monthHours,
        int $seniorityYears,
        array $inputLines,
        PayrollParameter $parameters,
        ?float $minBase = null,
        MpinObvrznik $obvrznik = MpinObvrznik::EMPLOYER,
    ): PayrollRunResult {
        // Deliberately NOT rounded before it is multiplied out. Rounding the
        // rate first makes a plain full month miss the agreed gross: 38 507 over
        // 184 hours gives a rate of 209,28, which multiplies back to 38 507,52 —
        // so every gross agreement would be quietly overpaid, every month. Only
        // the figure reported for display is rounded, at the return below.
        $hourlyRate = $monthHours > 0 ? $fullMonthGross / $monthHours : 0.0;

        $lines = [];
        $baseTotal = 0.0;

        foreach ($inputLines as $input) {
            // The bonus is derived and appended below. A line arriving with its
            // code can only be a caller bug — dropping it is what stops the same
            // bonus being paid twice.
            if (($input['code'] ?? null) === LineType::SENIORITY_CODE) {
                continue;
            }

            $amount = match ($input['kind']) {
                PayrollRunLine::KIND_HOURS => round(
                    $hourlyRate * (int) $input['hours'] * ((float) $input['percent']) / 100,
                    2
                ),
                default => round((float) ($input['amount'] ?? 0), 2),
            };

            $lines[] = new PayrollRunLineResult(
                kind: $input['kind'],
                code: $input['code'],
                description: $input['description'],
                hours: $input['hours'] ?? null,
                percent: $input['percent'] ?? null,
                amount: $amount,
                borneBy: $input['borne_by'],
                isAutomatic: false,
            );

            if ($input['kind'] === PayrollRunLine::KIND_HOURS && LineType::isBase($input['code'])) {
                $baseTotal += $amount;
            }
        }

        // The seniority bonus is derived, so it is appended rather than
        // entered. It rides on the base lines only: sick leave is already a
        // percentage of the salary, and uplifting overtime or a meal allowance
        // by length of service is not what минат труд is. It is also an
        // employment-relationship benefit under the Law on Labor Relations —
        // gated off for a self-employed obvrznik, who has no employer to owe
        // it. See MpinObvrznik::paysSeniorityBonus() for the actual evidence:
        // the real 111 filing's DatumPocetok carries no hire-date information
        // (it is month coverage, not employed_on), so the gate rests on the
        // filing's gross being a fixed statutory base rather than a negotiated
        // salary, plus the self-employed regime codes on the same line — not
        // on any observed tenure. Pending the accountant's confirmation.
        $seniorityAmount = $obvrznik->paysSeniorityBonus()
            ? round($baseTotal * LineType::SENIORITY_PERCENT_PER_YEAR * $seniorityYears / 100, 2)
            : 0.0;

        if ($seniorityAmount > 0) {
            $lines[] = new PayrollRunLineResult(
                kind: PayrollRunLine::KIND_AMOUNT,
                code: LineType::SENIORITY_CODE,
                description: LineType::SENIORITY_LABEL,
                hours: null,
                percent: null,
                amount: $seniorityAmount,
                borneBy: PayrollRunLine::BORNE_EMPLOYER,
                isAutomatic: true,
            );
        }

        $gross = 0.0;
        $employerGross = 0.0;
        $deductionsTotal = 0.0;

        foreach ($lines as $line) {
            if ($line->kind === PayrollRunLine::KIND_DEDUCTION) {
                $deductionsTotal += $line->amount;

                continue;
            }

            $gross += $line->amount;

            if ($line->borneBy === PayrollRunLine::BORNE_EMPLOYER) {
                $employerGross += $line->amount;
            }
        }

        $gross = round($gross, 2);
        $employerGross = round($employerGross, 2);
        $deductionsTotal = round($deductionsTotal, 2);

        $breakdown = SalaryCalculator::fromGross($gross, $parameters, $minBase, $obvrznik);

        // Contributions and tax are charged on the whole salary — the personal
        // allowance is deducted once, not per line — so the employer's share
        // can only be apportioned, never recomputed. The share is the
        // employer's part of the gross.
        $share = $gross > 0 ? $employerGross / $gross : 0.0;

        $employerContributions = round($breakdown->contributions * $share, 2);
        $employerTax = round($breakdown->tax * $share, 2);

        // The remainder, not an eighth figure of its own. Whatever the rounding
        // of the two lines above leaves behind lands here, which is what keeps
        // the journal entry balanced to the denar.
        $employerNet = round($employerGross - $employerContributions - $employerTax - $deductionsTotal, 2);

        return new PayrollRunResult(
            hourlyRate: round($hourlyRate, 2),
            lines: $lines,
            gross: $gross,
            breakdown: $breakdown,
            deductionsTotal: $deductionsTotal,
            effectiveNet: round($breakdown->net - $deductionsTotal, 2),
            employerGross: $employerGross,
            employerContributions: $employerContributions,
            employerTax: $employerTax,
            employerNet: $employerNet,
        );
    }
}
