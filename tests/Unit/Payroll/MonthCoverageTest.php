<?php

namespace Tests\Unit\Payroll;

use App\Support\Payroll\MonthCoverage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MonthCoverageTest extends TestCase
{
    public function test_a_whole_month_covers_every_day_and_the_whole_fund(): void
    {
        $coverage = MonthCoverage::for('2020-01-01', null, 2026, 8);

        $this->assertTrue($coverage->overlaps());
        $this->assertSame(31, $coverage->calendarDays());
        $this->assertSame(21, $coverage->workingDays());
        $this->assertSame(168, $coverage->hours(168));
    }

    public function test_someone_hired_mid_month_gets_the_covered_share(): void
    {
        // August 2026 has 21 working days. The 16th is a Sunday, so the 17th
        // through the 31st are 11 working days.
        $coverage = MonthCoverage::for('2026-08-16', null, 2026, 8);

        $this->assertSame(16, $coverage->calendarDays());
        $this->assertSame(11, $coverage->workingDays());
        $this->assertSame(88, $coverage->hours(168));
    }

    public function test_someone_who_left_mid_month_is_covered_up_to_that_day(): void
    {
        $coverage = MonthCoverage::for('2020-01-01', '2026-08-10', 2026, 8);

        $this->assertTrue($coverage->overlaps());
        $this->assertSame(10, $coverage->calendarDays());
        $this->assertSame(6, $coverage->workingDays());
    }

    public function test_hired_and_left_inside_the_same_month(): void
    {
        $coverage = MonthCoverage::for('2026-08-10', '2026-08-20', 2026, 8);

        $this->assertSame(11, $coverage->calendarDays());
    }

    public function test_it_does_not_overlap_a_month_that_ended_before_the_hire(): void
    {
        $coverage = MonthCoverage::for('2026-09-01', null, 2026, 8);

        $this->assertFalse($coverage->overlaps());
        $this->assertSame(0, $coverage->calendarDays());
        $this->assertSame(0, $coverage->hours(168));
    }

    public function test_it_does_not_overlap_a_month_after_the_termination(): void
    {
        $coverage = MonthCoverage::for('2020-01-01', '2026-07-31', 2026, 8);

        $this->assertFalse($coverage->overlaps());
    }

    public function test_the_last_day_of_the_month_still_overlaps_on_both_edges(): void
    {
        $this->assertTrue(MonthCoverage::for('2026-08-31', null, 2026, 8)->overlaps());
        $this->assertTrue(MonthCoverage::for('2020-01-01', '2026-08-01', 2026, 8)->overlaps());
    }

    public function test_a_covered_stretch_with_no_working_day_pays_nothing_but_still_counts_service(): void
    {
        // 31 January 2026 is a Saturday and the last day of the month.
        $coverage = MonthCoverage::for('2026-01-31', null, 2026, 1);

        $this->assertTrue($coverage->overlaps());
        $this->assertSame(1, $coverage->calendarDays());
        $this->assertSame(0, $coverage->workingDays());
        $this->assertSame(0, $coverage->hours(176));
    }

    public function test_february_in_a_leap_year(): void
    {
        $this->assertSame(29, MonthCoverage::for('2020-01-01', null, 2024, 2)->calendarDays());
    }

    /**
     * The question the minimum contribution base asks: a whole month keeps the
     * statutory floor untouched, anything shorter has it prorated. It has to be
     * asked as "does this span the month", never as "are there 30 days here" —
     * February would answer no to that and lose its floor.
     */
    public function test_it_knows_a_whole_month_from_a_part_of_one(): void
    {
        $this->assertTrue(MonthCoverage::for('2020-01-01', null, 2026, 8)->isFullMonth());
        $this->assertTrue(MonthCoverage::for('2026-08-01', '2026-08-31', 2026, 8)->isFullMonth());

        // A short February and a 31-day month are both whole months.
        $this->assertTrue(MonthCoverage::for('2020-01-01', null, 2026, 2)->isFullMonth());
        $this->assertTrue(MonthCoverage::for('2020-01-01', null, 2026, 7)->isFullMonth());

        $this->assertFalse(MonthCoverage::for('2026-08-02', null, 2026, 8)->isFullMonth());
        $this->assertFalse(MonthCoverage::for('2020-01-01', '2026-08-30', 2026, 8)->isFullMonth());

        // No overlap at all is not a full month either.
        $this->assertFalse(MonthCoverage::for('2026-09-01', null, 2026, 8)->isFullMonth());
    }

    public function test_hours_round_half_up(): void
    {
        // 11 of 21 working days out of a 160-hour fund is 83.809…
        $this->assertSame(84, MonthCoverage::for('2026-08-16', null, 2026, 8)->hours(160));
    }

    /**
     * The one test that checks our arithmetic against a source outside this
     * repository: the "Максимален број работни денови и часови" table the МПИН
     * client itself publishes. Working days times eight is its hours column,
     * and January has three public holidays yet still reads 22 — which is the
     * proof that holidays are not subtracted.
     *
     * @return list<array{int, int, int}>
     */
    public static function ujpTable2026(): array
    {
        return [
            [1, 22, 176], [2, 20, 160], [3, 22, 176], [4, 22, 176],
            [5, 21, 168], [6, 22, 176], [7, 23, 184], [8, 21, 168],
            [9, 22, 176], [10, 22, 176], [11, 21, 168], [12, 23, 184],
        ];
    }

    #[DataProvider('ujpTable2026')]
    public function test_it_matches_the_ujp_published_table_for_2026(int $month, int $days, int $hours): void
    {
        $this->assertSame($days, MonthCoverage::workingDaysInMonth(2026, $month));

        // Not a tautology despite looking like one: the days and the hours are
        // two independent columns copied from УЈП's table, and this asserts they
        // still agree on the times-eight rule — which is what catches a typo in
        // the transcription above, the only way this test could go quietly wrong.
        $this->assertSame($hours, $days * 8);
    }
}
