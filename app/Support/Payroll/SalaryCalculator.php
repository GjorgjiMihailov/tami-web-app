<?php

namespace App\Support\Payroll;

use App\Models\PayrollParameter;

class SalaryCalculator
{
    public static function fromGross(float $gross, PayrollParameter $p): SalaryBreakdown
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
        $shortfall = max($p->min_base - $gross, 0);

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

    private static function share(float $base, float $ratePercent): float
    {
        return round($base * $ratePercent / 100, 2);
    }
}
