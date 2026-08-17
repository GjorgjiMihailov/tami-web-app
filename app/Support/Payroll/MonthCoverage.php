<?php

namespace App\Support\Payroll;

use Carbon\CarbonImmutable;

/**
 * How much of one month a single employment covers.
 *
 * Everything here is pure calendar arithmetic on two dates, so it is a value
 * object rather than a method on Employee: the payroll service, the run screen
 * and the hour-fund warning all ask the same questions, and none of them should
 * have to know how a month is counted.
 *
 * Two rules from УЈП are encoded here and must not be "tidied up":
 * working days are plain Monday-to-Friday with public holidays NOT subtracted
 * (МПИН field 3.6 counts holiday hours as effective hours), and days of service
 * are calendar days (field 3.5, minimum 1, maximum the days in the month).
 */
final class MonthCoverage
{
    private function __construct(
        private readonly ?CarbonImmutable $from,
        private readonly ?CarbonImmutable $to,
        private readonly int $year,
        private readonly int $month,
    ) {}

    public static function for(string $employedOn, ?string $terminatedOn, int $year, int $month): self
    {
        $monthStart = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->endOfMonth()->startOfDay();

        $employed = CarbonImmutable::parse($employedOn)->startOfDay();
        $terminated = $terminatedOn === null
            ? null
            : CarbonImmutable::parse($terminatedOn)->startOfDay();

        $overlaps = $employed->lte($monthEnd)
            && ($terminated === null || $terminated->gte($monthStart));

        if (! $overlaps) {
            return new self(null, null, $year, $month);
        }

        return new self(
            $employed->gt($monthStart) ? $employed : $monthStart,
            ($terminated !== null && $terminated->lt($monthEnd)) ? $terminated : $monthEnd,
            $year,
            $month,
        );
    }

    /** Whether the employment touches this month at all — the rule for who enters a run. */
    public function overlaps(): bool
    {
        return $this->from !== null;
    }

    /**
     * Whether the employment spans the whole month.
     *
     * Asked by the minimum contribution base, which is prorated for a part of a
     * month and left alone for a whole one. Deliberately a span test rather
     * than a count of days: "28 days" is a whole February and a short March, and
     * a rule written on the count would quietly move the statutory floor with
     * the length of the month.
     */
    public function isFullMonth(): bool
    {
        if ($this->from === null || $this->to === null) {
            return false;
        }

        $monthStart = CarbonImmutable::create($this->year, $this->month, 1)->startOfDay();

        return $this->from->equalTo($monthStart)
            && $this->to->equalTo($monthStart->endOfMonth()->startOfDay());
    }

    /** Days of service: calendar days, counted inclusively on both ends. */
    public function calendarDays(): int
    {
        if ($this->from === null || $this->to === null) {
            return 0;
        }

        return ((int) $this->from->diffInDays($this->to)) + 1;
    }

    public function workingDays(): int
    {
        if ($this->from === null || $this->to === null) {
            return 0;
        }

        return self::countWeekdays($this->from, $this->to);
    }

    public static function workingDaysInMonth(int $year, int $month): int
    {
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();

        return self::countWeekdays($start, $start->endOfMonth()->startOfDay());
    }

    /**
     * The covered share of the month's fund of hours.
     *
     * Deliberately a share of the entered fund rather than covered days times
     * eight: when the entered fund is the usual working days times eight the two
     * agree exactly, and when it is not, this one still gives back the whole
     * fund for a whole month instead of quietly paying a different total.
     */
    public function hours(int $fund): int
    {
        $totalWorkingDays = self::workingDaysInMonth($this->year, $this->month);

        if (! $this->overlaps() || $totalWorkingDays === 0) {
            return 0;
        }

        return (int) round($fund * $this->workingDays() / $totalWorkingDays);
    }

    private static function countWeekdays(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $days = 0;

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            if (! $day->isWeekend()) {
                $days++;
            }
        }

        return $days;
    }
}
