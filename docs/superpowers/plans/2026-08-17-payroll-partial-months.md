# Payroll Partial Months Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pay an employee who joined or left mid-month for the part of the month they actually worked, and record the days of service (`DenoviStaz`) that МПИН requires.

**Architecture:** All calendar arithmetic goes into one pure value object, `App\Support\Payroll\MonthCoverage`, which answers four questions about one employment in one month: does it overlap, how many calendar days, how many working days, and how many hours of a given monthly fund. `PayrollRunService` consumes it in two places — `open()` for the entry rule and the initial hours, `recalculate()` for the days of service. The salary calculator is not touched: fewer hours reach it the same way a hand-typed correction always has.

**Tech Stack:** Laravel 13 + Livewire 3, PHPUnit (not Pest), Carbon 3, SQLite locally and in CI, MySQL in production, Tailwind via Vite.

**Spec:** `docs/superpowers/specs/2026-08-17-payroll-partial-months-design.md` — read it before Task 1. Everything below implements it; where this plan and the spec disagree, the spec wins and the disagreement is a bug in this plan.

## Global Constraints

- **All user-facing text is Macedonian.** Never Bulgarian wording. Code, comments and commit messages are English, matching the rest of the repo.
- **`hours` is a whole number** — `payroll_run_lines.hours` is an integer column and `BrojCasovi` is `xs:int`. Round half up, never store a fraction.
- **Working days mean Monday–Friday, holidays not subtracted.** This is not a simplification; МПИН field 3.6 counts public-holiday hours as effective hours. See the spec's Извори section.
- **Days of service are calendar days**, at least 1 and at most the number of days in the month (МПИН field 3.5).
- **The 5b invariant must survive:** an agreed net salary for a *full* month must still come out to the denar, and the journal entry must still balance. A full month yields an unchanged hours fund, so this holds by construction — Task 4 proves it with a test rather than assuming it.
- **Table cells use `py-1 px-3`** and data rows carry `hover:bg-orange-50`. `tests/Feature/TableDensityTest.php` scans the Blade sources for exactly this and will fail the build otherwise.
- **Migrations must run on both SQLite and MySQL.** No `UPDATE ... JOIN`, no database-specific date functions. This project has been bitten by MySQL-only migration failures three times; a green local suite is necessary but not sufficient.
- **Run tests with** `php artisan test`. A single file: `php artisan test --filter=ClassName`.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Support/Payroll/MonthCoverage.php` *(new)* | All calendar arithmetic. Pure — no Eloquent, no database, no `now()`. |
| `tests/Unit/Payroll/MonthCoverageTest.php` *(new)* | Unit tests for the above, including the УЈП published-table check. |
| `app/Models/Employee.php` | Gains `coverageIn()`; `isActiveOn()` is left alone. |
| `database/migrations/..._add_staz_days_to_payroll_run_employees_table.php` *(new)* | The `staz_days` column plus a portable PHP backfill. |
| `app/Models/PayrollRunEmployee.php` | `staz_days` in `$fillable` and `$casts`. |
| `app/Services/Payroll/PayrollRunService.php` | `open()` — entry rule and initial hours. `recalculate()` — writes `staz_days`. |
| `resources/views/livewire/payroll/payroll-run-show.blade.php` | „Стаж" column, employment dates on partial rows. |
| `app/Livewire/PayrollParameterIndex.php` + its Blade | The soft fund warning. |
| `app/Models/PayrollMonthHours.php` | Docblock correction only. |

---

### Task 1: `MonthCoverage`

**Files:**
- Create: `app/Support/Payroll/MonthCoverage.php`
- Test: `tests/Unit/Payroll/MonthCoverageTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces — every later task depends on exactly these signatures:
  - `MonthCoverage::for(string $employedOn, ?string $terminatedOn, int $year, int $month): self`
  - `overlaps(): bool`
  - `calendarDays(): int`
  - `workingDays(): int`
  - `hours(int $fund): int`
  - `MonthCoverage::workingDaysInMonth(int $year, int $month): int` *(static)*

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Payroll/MonthCoverageTest.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=MonthCoverageTest`
Expected: FAIL — `Class "App\Support\Payroll\MonthCoverage" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Support/Payroll/MonthCoverage.php`:

```php
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=MonthCoverageTest`
Expected: PASS, 22 tests (11 plain plus the 12-row data provider minus none).

- [ ] **Step 5: Commit**

```bash
git add app/Support/Payroll/MonthCoverage.php tests/Unit/Payroll/MonthCoverageTest.php
git commit -m "feat(payroll): add MonthCoverage, the calendar arithmetic for a partial month"
```

---

### Task 2: `Employee::coverageIn()`

**Files:**
- Modify: `app/Models/Employee.php`
- Test: `tests/Feature/EmployeeModelTest.php`

**Interfaces:**
- Consumes: `MonthCoverage::for()` from Task 1.
- Produces: `Employee::coverageIn(int $year, int $month): MonthCoverage`.

- [ ] **Step 1: Write the failing test**

Append inside the existing class in `tests/Feature/EmployeeModelTest.php`:

```php
    public function test_it_reports_its_coverage_of_a_month(): void
    {
        $employee = Employee::factory()->create([
            'employed_on' => '2026-08-16',
            'terminated_on' => null,
        ]);

        $coverage = $employee->coverageIn(2026, 8);

        $this->assertTrue($coverage->overlaps());
        $this->assertSame(16, $coverage->calendarDays());
        $this->assertSame(88, $coverage->hours(168));
    }

    public function test_coverage_carries_the_termination_date_through(): void
    {
        $employee = Employee::factory()->create([
            'employed_on' => '2020-01-01',
            'terminated_on' => '2026-07-31',
        ]);

        $this->assertFalse($employee->coverageIn(2026, 8)->overlaps());
        $this->assertTrue($employee->coverageIn(2026, 7)->overlaps());
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=EmployeeModelTest`
Expected: FAIL — `Call to undefined method App\Models\Employee::coverageIn()`.

- [ ] **Step 3: Write the implementation**

In `app/Models/Employee.php`, add the import `use App\Support\Payroll\MonthCoverage;`
and this method directly below `isActiveOn()`:

```php
    /**
     * How much of the given month this employment covers.
     *
     * Note this is a different question from isActiveOn(), which asks about one
     * instant and is what the employee list uses to decide who is here today. A
     * payroll month wants the overlap instead: someone who left on the 10th is
     * not active on the 31st, but is owed ten days of pay.
     */
    public function coverageIn(int $year, int $month): MonthCoverage
    {
        return MonthCoverage::for(
            $this->employed_on->toDateString(),
            $this->terminated_on?->toDateString(),
            $year,
            $month,
        );
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=EmployeeModelTest`
Expected: PASS, including the pre-existing tests in that file.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Employee.php tests/Feature/EmployeeModelTest.php
git commit -m "feat(payroll): ask an employee how much of a month it covers"
```

---

### Task 3: The `staz_days` column

**Files:**
- Create: `database/migrations/2026_08_17_100000_add_staz_days_to_payroll_run_employees_table.php`
- Modify: `app/Models/PayrollRunEmployee.php`
- Test: `tests/Feature/Payroll/PayrollRunModelTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `payroll_run_employees.staz_days`, an integer attribute on `PayrollRunEmployee`.

- [ ] **Step 1: Write the failing test**

Append inside the existing class in `tests/Feature/Payroll/PayrollRunModelTest.php`:

```php
    public function test_a_run_employee_stores_days_of_service_as_an_integer(): void
    {
        $company = \App\Models\Company::factory()->create();
        $employee = \App\Models\Employee::factory()->for($company)->create();

        $run = \App\Models\PayrollRun::create([
            'company_id' => $company->id,
            'year' => 2026,
            'month' => 8,
            'status' => \App\Models\PayrollRun::DRAFT,
            'month_hours' => 168,
            'payroll_parameter_id' => \App\Models\PayrollParameter::forDate('2026-08-31')->id,
        ]);

        $runEmployee = \App\Models\PayrollRunEmployee::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'staz_days' => 16,
        ]);

        $this->assertSame(16, $runEmployee->fresh()->staz_days);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=PayrollRunModelTest`
Expected: FAIL — the column does not exist, so the insert throws a `QueryException`.

- [ ] **Step 3: Write the migration and the model change**

Create `database/migrations/2026_08_17_100000_add_staz_days_to_payroll_run_employees_table.php`:

```php
<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_run_employees', function (Blueprint $table) {
            $table->unsignedTinyInteger('staz_days')->default(0);
        });

        // Existing runs each gave every employee a full month, because that is
        // all the old code could do — so a full month is also what they paid and
        // posted, and backfilling anything else would contradict a confirmed
        // journal entry. Drafts correct themselves on the next recalculation.
        //
        // Done in PHP rather than one UPDATE ... JOIN: that statement is written
        // differently in SQLite and MySQL, and this project runs both.
        DB::table('payroll_runs')->orderBy('id')->chunk(200, function ($runs) {
            foreach ($runs as $run) {
                DB::table('payroll_run_employees')
                    ->where('payroll_run_id', $run->id)
                    ->update([
                        'staz_days' => CarbonImmutable::create($run->year, $run->month, 1)->daysInMonth,
                    ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_employees', function (Blueprint $table) {
            $table->dropColumn('staz_days');
        });
    }
};
```

In `app/Models/PayrollRunEmployee.php`, add `'staz_days'` to the end of the
`$fillable` array, and `'staz_days' => 'integer',` to the array returned by
`casts()`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=PayrollRunModelTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_17_100000_add_staz_days_to_payroll_run_employees_table.php app/Models/PayrollRunEmployee.php tests/Feature/Payroll/PayrollRunModelTest.php
git commit -m "feat(payroll): store days of service on a run employee"
```

---

### Task 4: Who enters a run, and on how many hours

**Files:**
- Modify: `app/Services/Payroll/PayrollRunService.php` (the `open()` method)
- Test: `tests/Feature/Payroll/PayrollRunServiceTest.php`

**Interfaces:**
- Consumes: `Employee::coverageIn()` from Task 2.
- Produces: no new signatures — `open()` keeps its signature and behaviour for a full month.

- [ ] **Step 1: Write the failing tests**

Append inside the existing class in `tests/Feature/Payroll/PayrollRunServiceTest.php`.
Note `employeeOn()` is the file's existing private helper, which hires on
2026-01-01 and attaches a salary; these tests move the dates afterwards.

```php
    public function test_a_full_month_still_pays_the_whole_fund(): void
    {
        // The 5b invariant: an agreed net for a whole month must be untouched by
        // proration. This is the test that says so out loud.
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 8, 'hours' => 168]);
        $this->employeeOn($company, 30000, 'net');

        $run = app(PayrollRunService::class)->open($company, 2026, 8);
        $runEmployee = $run->employees->first();

        $this->assertSame(168, $runEmployee->lines->first()->hours);
        $this->assertSame(30000.0, round($runEmployee->net, 2));
    }

    public function test_someone_hired_mid_month_gets_only_the_covered_hours(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 8, 'hours' => 168]);
        $this->employeeOn($company, 38507, 'gross')->update(['employed_on' => '2026-08-16']);

        $run = app(PayrollRunService::class)->open($company, 2026, 8);

        $this->assertCount(1, $run->employees);
        $this->assertSame(88, $run->employees->first()->lines->first()->hours);
    }

    public function test_someone_who_left_mid_month_is_still_paid_for_it(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 8, 'hours' => 168]);
        $this->employeeOn($company, 38507, 'gross')->update(['terminated_on' => '2026-08-10']);

        $run = app(PayrollRunService::class)->open($company, 2026, 8);

        $this->assertCount(1, $run->employees);
        // 1-10 August 2026 is 6 working days of 21.
        $this->assertSame(48, $run->employees->first()->lines->first()->hours);
    }

    public function test_someone_hired_after_the_month_ended_does_not_enter(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 8, 'hours' => 168]);
        $this->employeeOn($company, 38507, 'gross')->update(['employed_on' => '2026-09-01']);

        $this->assertCount(0, app(PayrollRunService::class)->open($company, 2026, 8)->employees);
    }

    public function test_hired_and_gone_inside_the_same_month(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 8, 'hours' => 168]);
        $this->employeeOn($company, 38507, 'gross')
            ->update(['employed_on' => '2026-08-10', 'terminated_on' => '2026-08-20']);

        $run = app(PayrollRunService::class)->open($company, 2026, 8);

        // 10-20 August 2026 is 9 working days of 21: 168 * 9 / 21 = 72.
        $this->assertSame(72, $run->employees->first()->lines->first()->hours);
    }

    public function test_a_stretch_with_no_working_day_opens_on_zero_hours(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 1, 'hours' => 176]);
        // 31 January 2026 is a Saturday and the last day of the month.
        $this->employeeOn($company, 38507, 'gross')->update(['employed_on' => '2026-01-31']);

        $run = app(PayrollRunService::class)->open($company, 2026, 1);

        $this->assertCount(1, $run->employees);
        $this->assertSame(0, $run->employees->first()->lines->first()->hours);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=PayrollRunServiceTest`
Expected: FAIL. `test_someone_hired_mid_month_gets_only_the_covered_hours` reports
168 instead of 88, and `test_someone_who_left_mid_month_is_still_paid_for_it`
fails on `assertCount(1, ...)` with 0 — those two failures are exactly the two
production bugs this plan exists to fix. `test_a_full_month_still_pays_the_whole_fund`
should already PASS; if it does not, stop and report, because something other
than this change is wrong.

- [ ] **Step 3: Write the implementation**

No new import is needed in `app/Services/Payroll/PayrollRunService.php`: the
coverage is reached through `Employee`, which is already imported.

Replace the docblock and body of `open()` from the `$asOf` line through the end of
the `foreach` with:

```php
            $employees = Employee::where('company_id', $company->id)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get()
                ->filter(fn (Employee $e) => $e->coverageIn($year, $month)->overlaps());

            foreach ($employees as $employee) {
                $runEmployee = PayrollRunEmployee::create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                ]);

                PayrollRunLine::create([
                    'payroll_run_employee_id' => $runEmployee->id,
                    'kind' => PayrollRunLine::KIND_HOURS,
                    'code' => '001',
                    'description' => LineType::label('001'),
                    'hours' => $employee->coverageIn($year, $month)->hours($fund->hours),
                    'percent' => 100,
                    'amount' => 0,
                    'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
                    'is_automatic' => false,
                ]);
            }
```

The `$asOf` variable in `open()` becomes unused — delete that line. Do **not**
touch the `$asOf` inside `recalculate()`, which serves a different purpose
(the salary and seniority in force at month end).

Replace `open()`'s docblock with:

```php
    /**
     * Opens the month and fills it in: everyone whose employment overlaps the
     * month, each on the hours their employment actually covers.
     *
     * Overlap, not "active on the last day" — someone who left on the 10th is
     * owed ten days of pay, and the old rule silently paid them nothing. The
     * hours are a starting point, not a verdict: the line stays editable, and
     * recalculate() never writes them back, so a hand correction for sick leave
     * or unpaid absence survives.
     */
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=PayrollRunServiceTest`
Expected: PASS, including every pre-existing test in the file. Two of the old
tests describe the old behaviour but happen to still hold —
`test_it_opens_a_run_with_every_active_employee_on_full_hours` hires on
2026-01-01 for a July run (a full month, 184 hours) and
`test_it_leaves_out_someone_who_had_already_left` terminates in March before a
July run. If either fails, the implementation is wrong; do not edit those tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Payroll/PayrollRunService.php tests/Feature/Payroll/PayrollRunServiceTest.php
git commit -m "fix(payroll): pay a partial month for the part that was worked"
```

---

### Task 5: Days of service on every recalculation

**Files:**
- Modify: `app/Services/Payroll/PayrollRunService.php` (the `recalculate()` method)
- Test: `tests/Feature/Payroll/PayrollRunServiceTest.php`

**Interfaces:**
- Consumes: `Employee::coverageIn()` (Task 2), `payroll_run_employees.staz_days` (Task 3).
- Produces: `staz_days` populated on every run employee after `open()` or `recalculate()`.

- [ ] **Step 1: Write the failing tests**

Append inside the existing class in `tests/Feature/Payroll/PayrollRunServiceTest.php`:

```php
    public function test_a_full_month_records_every_calendar_day_as_service(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 8, 'hours' => 168]);
        $this->employeeOn($company, 38507, 'gross');

        $run = app(PayrollRunService::class)->open($company, 2026, 8);

        $this->assertSame(31, $run->employees->first()->staz_days);
    }

    public function test_a_partial_month_records_only_the_covered_days(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 8, 'hours' => 168]);
        $this->employeeOn($company, 38507, 'gross')->update(['employed_on' => '2026-08-16']);

        $run = app(PayrollRunService::class)->open($company, 2026, 8);

        $this->assertSame(16, $run->employees->first()->staz_days);
    }

    public function test_a_single_covered_day_still_counts_as_one(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 1, 'hours' => 176]);
        $this->employeeOn($company, 38507, 'gross')->update(['employed_on' => '2026-01-31']);

        $run = app(PayrollRunService::class)->open($company, 2026, 1);

        // Zero hours, but the insurance ran for a day. УЈП's field 3.5 may never
        // be below 1.
        $this->assertSame(0, $run->employees->first()->lines->first()->hours);
        $this->assertSame(1, $run->employees->first()->staz_days);
    }

    public function test_service_days_follow_a_change_of_dates_on_recalculation(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 8, 'hours' => 168]);
        $employee = $this->employeeOn($company, 38507, 'gross');

        $service = app(PayrollRunService::class);
        $run = $service->open($company, 2026, 8);
        $this->assertSame(31, $run->employees->first()->staz_days);

        $employee->update(['terminated_on' => '2026-08-20']);
        $run = $service->recalculate($run->fresh());

        $this->assertSame(20, $run->employees->first()->staz_days);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=PayrollRunServiceTest`
Expected: FAIL — `staz_days` is 0 everywhere, because nothing writes it yet.

- [ ] **Step 3: Write the implementation**

In `recalculate()`, inside the `foreach` over run employees, immediately after
`$employee = $runEmployee->employee;` add:

```php
                $coverage = $employee->coverageIn($run->year, $run->month);
```

and add one entry to the `$runEmployee->update([...])` array, next to
`'seniority_years' => ...`:

```php
                    'staz_days' => $coverage->calendarDays(),
```

Note what this deliberately does not do: it never rewrites the hours. Days of
service are derived from dates the app owns; hours are a number the bookkeeper
may have corrected by hand, and rebuilding them here would throw that away every
time the screen saved.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=PayrollRunServiceTest`
Expected: PASS, whole file.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Payroll/PayrollRunService.php tests/Feature/Payroll/PayrollRunServiceTest.php
git commit -m "feat(payroll): record days of service on every recalculation"
```

---

### Task 6: The run screen shows service days and why hours are short

**Files:**
- Modify: `resources/views/livewire/payroll/payroll-run-show.blade.php`
- Test: `tests/Feature/Payroll/PayrollRunShowTest.php`

**Interfaces:**
- Consumes: `staz_days` on each row (Tasks 3 and 5).
- Produces: nothing other tasks depend on.

- [ ] **Step 1: Write the failing test**

Append inside the existing class in `tests/Feature/Payroll/PayrollRunShowTest.php`.
The test below is self-contained on purpose — it does not depend on that file's
private helpers, so it can be pasted in as-is. If the file already has a helper
that builds an admin the same way, using it instead is fine.

```php
    public function test_it_shows_days_of_service_and_the_dates_behind_a_short_month(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('admin');

        $company = \App\Models\Company::factory()->create();
        \App\Models\PayrollMonthHours::create(['year' => 2026, 'month' => 8, 'hours' => 168]);

        $employee = \App\Models\Employee::factory()->for($company)->create([
            'first_name' => 'Ана',
            'last_name' => 'Стоева',
            'employed_on' => '2026-08-16',
        ]);
        \App\Models\EmployeeSalary::create([
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'amount' => 38507,
            'basis' => 'gross',
        ]);

        $run = app(\App\Services\Payroll\PayrollRunService::class)->open($company, 2026, 8);

        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('payroll-runs.show', [$company, $run]))
            ->assertOk()
            ->assertSee('Стаж')
            ->assertSee('16')
            ->assertSee('16.08.2026');
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=PayrollRunShowTest`
Expected: FAIL — "Стаж" is not on the page.

- [ ] **Step 3: Write the implementation**

In `resources/views/livewire/payroll/payroll-run-show.blade.php`, in the employee
table's `<thead>`, add a header cell directly after the „Часови" one:

```blade
                    <th class="py-1 px-3 text-right">Стаж</th>
```

In the matching `<tbody>` row, add a cell directly after the hours cell:

```blade
                        <td class="py-1 px-3 text-right">
                            {{ $row->staz_days }}
                            @if ($row->staz_days > 0 && $row->staz_days < \Carbon\Carbon::create($run->year, $run->month, 1)->daysInMonth)
                                <span class="block text-xs text-gray-500">
                                    {{ $row->employee->employed_on->format('d.m.Y') }}
                                    @if ($row->employee->terminated_on)
                                        – {{ $row->employee->terminated_on->format('d.m.Y') }}
                                    @endif
                                </span>
                            @endif
                        </td>
```

In the `<tfoot>` of the same table, change `colspan="2"` on the „Вкупно" cell to
`colspan="3"`, so the totals stay under their own columns.

Keep `py-1 px-3` on both new cells: `tests/Feature/TableDensityTest.php` scans the
Blade source for that exact treatment and fails the build if a data table drifts.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=PayrollRunShowTest`
Then: `php artisan test --filter=TableDensityTest`
Expected: PASS both.

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/payroll/payroll-run-show.blade.php tests/Feature/Payroll/PayrollRunShowTest.php
git commit -m "feat(payroll): show days of service and the dates behind a short month"
```

---

### Task 7: The soft fund warning

**Files:**
- Modify: `app/Livewire/PayrollParameterIndex.php`
- Modify: `resources/views/livewire/payroll-parameter-index.blade.php`
- Modify: `app/Models/PayrollMonthHours.php` (docblock only)
- Test: `tests/Feature/PayrollParameterIndexTest.php`

**Interfaces:**
- Consumes: `MonthCoverage::workingDaysInMonth()` from Task 1.
- Produces: nothing other tasks depend on.

- [ ] **Step 1: Write the failing tests**

Append inside the existing class in `tests/Feature/PayrollParameterIndexTest.php`:

```php
    public function test_it_warns_when_an_entered_fund_does_not_match_the_calendar(): void
    {
        $company = Company::factory()->create();
        $this->admin();
        // August 2026 has 21 working days, so 168 is right and 160 is not.
        PayrollMonthHours::create(['year' => 2026, 'month' => 8, 'hours' => 160]);

        Livewire::test(PayrollParameterIndex::class, ['company' => $company])
            ->set('fundYear', 2026)
            ->assertSee('21 × 8 = 168 часа според календарот');
    }

    public function test_it_stays_quiet_when_the_fund_matches(): void
    {
        $company = Company::factory()->create();
        $this->admin();
        PayrollMonthHours::create(['year' => 2026, 'month' => 8, 'hours' => 168]);

        Livewire::test(PayrollParameterIndex::class, ['company' => $company])
            ->set('fundYear', 2026)
            ->assertDontSee('според календарот');
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=PayrollParameterIndexTest`
Expected: FAIL on the first test — nothing on the page mentions working days.

- [ ] **Step 3: Write the implementation**

In `app/Livewire/PayrollParameterIndex.php`, add the import
`use App\Support\Payroll\MonthCoverage;` and change `render()` so each month row
carries the number the calendar expects:

```php
    public function render()
    {
        $monthHours = PayrollMonthHours::where('year', $this->fundYear)
            ->orderBy('month')
            ->get()
            ->map(function (PayrollMonthHours $fund) {
                // Attached rather than computed in the view: the warning is a
                // fact about the entered number, and a Blade template is a poor
                // place to keep arithmetic a test needs to reach.
                $fund->expected_working_days = MonthCoverage::workingDaysInMonth($fund->year, $fund->month);
                $fund->expected_hours = $fund->expected_working_days * 8;

                return $fund;
            });

        return view('livewire.payroll-parameter-index', [
            'parameters' => PayrollParameter::orderByDesc('effective_from')->get(),
            'monthHours' => $monthHours,
        ]);
    }
```

In `resources/views/livewire/payroll-parameter-index.blade.php`, inside the month
table's `<tbody>` row, replace the hours cell with:

```blade
                        <td class="py-1 px-3 text-right">
                            {{ $fund->hours }}
                            @if ($fund->hours !== $fund->expected_hours)
                                <span class="block text-xs text-amber-700">{{ $fund->expected_working_days }} × 8 = {{ $fund->expected_hours }} часа според календарот</span>
                            @endif
                        </td>
```

In `app/Models/PayrollMonthHours.php`, replace the second paragraph of the class
docblock — the one claiming the fund is entered by hand because the holidays move
— with:

```php
 * Entered once a year by the admin rather than derived from a calendar. It is
 * plain Monday-to-Friday times eight, holidays included: МПИН counts public
 * holiday hours as effective hours (field 3.6), so nothing is subtracted. The
 * parameter screen warns when an entered month disagrees with that.
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=PayrollParameterIndexTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/PayrollParameterIndex.php resources/views/livewire/payroll-parameter-index.blade.php app/Models/PayrollMonthHours.php tests/Feature/PayrollParameterIndexTest.php
git commit -m "feat(payroll): warn when an entered hour fund disagrees with the calendar"
```

---

### Task 8: Whole suite, assets, and hand-off

**Files:** none changed unless something below fails.

- [ ] **Step 1: Run the whole test suite**

Run: `php artisan test`
Expected: PASS, every test. The 5b baseline was 1012 tests; this plan adds roughly
30, so expect about 1040 with zero failures. Any failure outside the files this
plan touched is a real regression — report it, do not adjust the failing
assertion to match new behaviour.

- [ ] **Step 2: Rebuild the front-end assets**

Run: `npm run build`
Expected: success. Tailwind's JIT only emits classes it has seen in the Blade
sources, and this plan added `text-amber-700` in a new place; a stale bundle would
render the warning unstyled.

- [ ] **Step 3: Commit any rebuilt assets**

```bash
git status --short public/build
git add public/build
git commit -m "chore: rebuild assets after the partial-month changes"
```

Skip this step entirely if `git status` shows nothing under `public/build`.

- [ ] **Step 4: Report before deploying**

Do not push or deploy as part of this plan. Report back with the test count, the
list of commits, and this reminder for the user: production already holds
confirmed payroll runs, so the migration in Task 3 will backfill them, and
`php artisan migrate` has to be run by the user over their own SSH session —
Claude has no access to the droplet.

---

## Notes for the reviewer

- **Out of scope on purpose, per the spec:** `DenoviStaz` per line, deriving
  `SifraDvizenje` per month instead of reading it off the employee card, and
  auto-filling the hour fund. If a task appears to be reaching into any of these,
  that is a deviation, not an improvement.
- **A row whose employee no longer overlaps the month** (dates edited after the
  run was opened) gets `staz_days = 0` rather than a forced 1. Zero is not a
  legal МПИН value, and that is the point: it marks an anomaly the bookkeeper
  must resolve instead of hiding it behind a plausible number. 5c will refuse to
  export it.
- **The salary calculator is untouched.** If a task starts editing
  `PayrollRunCalculator` or `SalaryCalculator`, stop — fewer hours are supposed to
  flow through them unchanged, and a change there would put the full-month
  invariant at risk.
