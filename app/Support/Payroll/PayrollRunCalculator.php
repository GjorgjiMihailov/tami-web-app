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
     */
    public static function fullMonthGross(float $amount, string $basis, PayrollParameter $parameters): float
    {
        return $basis === 'net'
            ? SalaryCalculator::fromNet($amount, $parameters)->gross
            : round($amount, 2);
    }

    /**
     * @param  list<array{kind: string, code: ?string, description: string, hours: ?int, percent: ?float, amount: ?float, borne_by: string}>  $inputLines
     */
    public static function calculate(
        float $fullMonthGross,
        int $monthHours,
        int $seniorityYears,
        array $inputLines,
        PayrollParameter $parameters,
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
        // by length of service is not what минат труд is.
        $seniorityAmount = round($baseTotal * LineType::SENIORITY_PERCENT_PER_YEAR * $seniorityYears / 100, 2);

        if ($seniorityAmount > 0) {
            $lines[] = new PayrollRunLineResult(
                kind: PayrollRunLine::KIND_AMOUNT,
                code: LineType::SENIORITY_CODE,
                description: LineType::label(LineType::SENIORITY_CODE),
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

        $breakdown = SalaryCalculator::fromGross($gross, $parameters);

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
