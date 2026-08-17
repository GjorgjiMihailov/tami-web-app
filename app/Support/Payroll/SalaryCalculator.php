<?php

namespace App\Support\Payroll;

use App\Models\PayrollParameter;
use InvalidArgumentException;

class SalaryCalculator
{
    /**
     * @param  float|null  $minBase  The floor the top-up is measured against.
     *                               Defaults to the statutory monthly minimum
     *                               base; a run passes a prorated floor for an
     *                               employee insured for part of the month, so
     *                               that half a month of insurance is not
     *                               charged a whole month of minimum-base
     *                               contributions.
     */
    public static function fromGross(float $gross, PayrollParameter $p, ?float $minBase = null): SalaryBreakdown
    {
        // The employee's own contributions are charged on their gross, capped
        // at the maximum base. The minimum base does NOT raise this — see the
        // top-up below.
        $base = min($gross, $p->max_base);

        $pension = self::share($base, $p->rate_pension);
        $health = self::share($base, $p->rate_health);
        $injury = self::share($base, $p->rate_injury);
        $unemployment = self::share($base, $p->rate_unemployment);
        $contributions = round($pension + $health + $injury + $unemployment, 2);

        $taxBase = round(max($gross - $contributions - $p->personal_allowance, 0), 2);
        $tax = self::share($taxBase, $p->rate_tax);
        $net = round($gross - $contributions - $tax, 2);

        // The top-up to the minimum base is the employer's obligation. It is
        // deliberately outside $contributions, outside $taxBase and outside
        // $net: folding it in would make the employee pay it.
        $shortfall = max(($minBase ?? $p->min_base) - $gross, 0);

        $topUpPension = self::share($shortfall, $p->rate_pension);
        $topUpHealth = self::share($shortfall, $p->rate_health);
        $topUpInjury = self::share($shortfall, $p->rate_injury);
        $topUpUnemployment = self::share($shortfall, $p->rate_unemployment);

        return new SalaryBreakdown(
            gross: round($gross, 2),
            pension: $pension,
            health: $health,
            injury: $injury,
            unemployment: $unemployment,
            contributions: $contributions,
            taxBase: $taxBase,
            tax: $tax,
            net: $net,
            topUpPension: $topUpPension,
            topUpHealth: $topUpHealth,
            topUpInjury: $topUpInjury,
            topUpUnemployment: $topUpUnemployment,
            topUp: round($topUpPension + $topUpHealth + $topUpInjury + $topUpUnemployment, 2),
        );
    }

    /**
     * Gross→net is monotone increasing, so a binary search converges. The
     * closed formula does not exist: the minimum base and the zero floor on
     * the tax base each put a kink in the curve.
     */
    public static function fromNet(float $net, PayrollParameter $p, ?float $minBase = null): SalaryBreakdown
    {
        if ($net < 0) {
            throw new InvalidArgumentException('Нето платата не може да биде негативна.');
        }

        $low = 0.0;
        // Three times the net plus a margin is always above the answer, even
        // though contributions plus tax never take more than half — the extra
        // room absorbs the minimum-base top-up and keeps the bound simple.
        $high = $net * 3 + 1000;

        for ($i = 0; $i < 200; $i++) {
            $mid = ($low + $high) / 2;

            if (self::fromGross($mid, $p, $minBase)->net < $net) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        // Salaries are agreed in whole denars; recompute from the rounded gross
        // so every figure in the breakdown belongs to the same gross.
        return self::fromGross(round($high), $p, $minBase);
    }

    private static function share(float $base, float $ratePercent): float
    {
        return round($base * $ratePercent / 100, 2);
    }
}
