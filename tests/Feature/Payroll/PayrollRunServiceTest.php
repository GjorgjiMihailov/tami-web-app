<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollMonthHours;
use App\Models\PayrollParameter;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Services\Payroll\PayrollRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PayrollRunServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedParameters(): PayrollParameter
    {
        return PayrollParameter::forDate('2026-07-31');
    }

    private function employeeOn(Company $company, float $amount, string $basis): Employee
    {
        $employee = Employee::factory()->for($company)->create([
            // Hired inside the run year on purpose: at 2026-07-31 that is zero
            // completed years, so no seniority line is appended and the figures
            // stay УЈП's published ones. Tests that need seniority set their own
            // hire date.
            'employed_on' => '2026-01-01',
            'prior_service_months' => 0,
        ]);

        EmployeeSalary::create([
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'amount' => $amount,
            'basis' => $basis,
        ]);

        return $employee;
    }

    private function partTimeEmployeeOn(Company $company, float $amount, int $weeklyHours): Employee
    {
        $employee = Employee::factory()->for($company)->create([
            'employed_on' => '2026-01-01',
            'prior_service_months' => 0,
            'weekly_hours' => $weeklyHours,
        ]);

        EmployeeSalary::create([
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'amount' => $amount,
            'basis' => 'gross',
        ]);

        return $employee;
    }

    public function test_it_opens_a_run_with_every_active_employee_on_full_hours(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);
        $this->employeeOn($company, 38507, 'gross');

        $run = app(PayrollRunService::class)->open($company, 2026, 7);

        $this->assertSame(PayrollRun::DRAFT, $run->status);
        $this->assertSame(184, $run->month_hours);
        $this->assertCount(1, $run->employees);

        $line = $run->employees->first()->lines->first();
        $this->assertSame('001', $line->code);
        $this->assertSame(184, $line->hours);
        $this->assertSame(38507.0, round($run->employees->first()->gross, 2));
    }

    public function test_it_leaves_out_someone_who_had_already_left(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);

        $gone = $this->employeeOn($company, 38507, 'gross');
        $gone->update(['terminated_on' => '2026-03-31']);

        $run = app(PayrollRunService::class)->open($company, 2026, 7);

        $this->assertCount(0, $run->employees);
    }

    public function test_it_refuses_a_month_with_no_hour_fund(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Нема внесен фонд на часови за 7/2026.');

        app(PayrollRunService::class)->open($company, 2026, 7);
    }

    public function test_recalculating_keeps_typed_lines_and_refreshes_the_automatic_one(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);
        $employee = $this->employeeOn($company, 36800, 'gross');
        $employee->update(['employed_on' => '2006-07-01']); // 20 years of service

        $service = app(PayrollRunService::class);
        $run = $service->open($company, 2026, 7);
        $runEmployee = $run->employees->first();

        PayrollRunLine::create([
            'payroll_run_employee_id' => $runEmployee->id,
            'kind' => PayrollRunLine::KIND_DEDUCTION, 'code' => null,
            'description' => 'Кредит', 'hours' => null, 'percent' => null,
            'amount' => 4000, 'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
            'is_automatic' => false,
        ]);

        $run = $service->recalculate($run->fresh());
        $runEmployee = $run->employees->first();

        $this->assertTrue($runEmployee->lines->contains(fn ($l) => $l->description === 'Кредит'));
        $this->assertTrue($runEmployee->lines->contains(fn ($l) => $l->is_automatic && $l->code === '037'));
        $this->assertSame(4000.0, round($runEmployee->deductions_total, 2));
    }

    public function test_a_parameter_change_does_not_restate_an_open_run(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);
        $this->employeeOn($company, 38507, 'gross');

        $service = app(PayrollRunService::class);
        $run = $service->open($company, 2026, 7);

        $frozenParameterId = $run->payroll_parameter_id;
        $grossBefore = $run->employees->first()->gross;
        $taxBefore = $run->employees->first()->tax;

        // 2026-07-15 is not one of the seeded periods, so it does not collide.
        // Its tax rate is 12% against July's 10% — far enough that a fresh
        // lookup would move the figures visibly, not by a rounding step.
        PayrollParameter::create([
            'effective_from' => '2026-07-15',
            'rate_pension' => 25.0, 'rate_health' => 9.0, 'rate_injury' => 0.5,
            'rate_unemployment' => 0.1, 'rate_tax' => 12.0,
            'personal_allowance' => 10932, 'average_salary' => 69141,
            'min_base' => 34571, 'max_base' => 1106256, 'minimum_wage' => 38507,
        ]);

        // Recalculating is the whole point of this test. Without it the
        // assertions below are true by construction — nothing ever rewrites the
        // frozen id — so it would pass even if recalculate() looked the
        // parameters up fresh on every call.
        $run = $service->recalculate($run->fresh());

        $this->assertSame($frozenParameterId, $run->payroll_parameter_id);
        $this->assertSame(round($grossBefore, 2), round($run->employees->first()->gross, 2));
        $this->assertSame(round($taxBefore, 2), round($run->employees->first()->tax, 2));
    }

    public function test_a_confirmed_run_refuses_to_be_recalculated(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);
        $this->employeeOn($company, 38507, 'gross');

        $service = app(PayrollRunService::class);
        $run = $service->open($company, 2026, 7);

        // Task 7 adds confirm(); the status is set directly because this test is
        // about the guard, not about how a run comes to be confirmed.
        $run->update(['status' => PayrollRun::CONFIRMED]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Потврдена пресметка не се пресметува повторно.');

        $service->recalculate($run->fresh());
    }

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

        // To the denar, not to the cent. SalaryCalculator::fromNet() rounds the
        // gross it solves for to a whole denar, so the net it reproduces lands
        // within a denar of the target — 29999.82 for a 30000 target. That is
        // what the spec's invariant says ("точно до денар") and how the existing
        // PayrollRunCalculatorTest asserts the same property.
        $this->assertSame(30000, (int) round($runEmployee->net));
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

    /**
     * The minimum contribution base is prorated for a part month, by МПИН's own
     * rule for prorating a monthly statutory amount: платата се дели со 30 и се
     * множи со деновите на осигурување. Sixteen days of August 2026 give a floor
     * of 34.571 / 30 × 16 = 18.437,87, which the 88-hour minimum wage clears —
     * so nothing is owed. Measured against the whole-month floor instead, this
     * employee was charged 4.032,18 of employer contributions for half a month
     * of insurance.
     */
    public function test_a_partial_month_at_the_minimum_wage_owes_no_top_up(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 8, 'hours' => 168]);
        $this->employeeOn($company, 38507, 'gross')->update(['employed_on' => '2026-08-16']);

        $runEmployee = app(PayrollRunService::class)->open($company, 2026, 8)->employees->first();

        $this->assertSame(16, $runEmployee->staz_days);
        $this->assertSame(20170.33, round($runEmployee->gross, 2));
        $this->assertSame(0.0, round($runEmployee->top_up, 2));
    }

    public function test_a_partial_month_below_the_prorated_floor_is_topped_up_only_to_it(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 8, 'hours' => 168]);
        $this->employeeOn($company, 20000, 'gross')->update(['employed_on' => '2026-08-16']);

        $runEmployee = app(PayrollRunService::class)->open($company, 2026, 8)->employees->first();

        // 20.000 / 168 × 88 = 10.476,19 against a floor of 18.437,87 leaves
        // 7.961,68 short, at 19,9 + 7,5 + 0,5 + 0,1 %.
        $this->assertSame(10476.19, round($runEmployee->gross, 2));
        $this->assertSame(1584.37, round($runEmployee->top_up_pension, 2));
        $this->assertSame(597.13, round($runEmployee->top_up_health, 2));
        $this->assertSame(39.81, round($runEmployee->top_up_injury, 2));
        $this->assertSame(7.96, round($runEmployee->top_up_unemployment, 2));
        $this->assertSame(2229.27, round($runEmployee->top_up, 2));

        // Far below the 6.396,27 the whole-month floor would have charged for
        // sixteen days of insurance.
        $this->assertGreaterThan(
            $runEmployee->top_up,
            \App\Support\Payroll\SalaryCalculator::fromGross(10476.19, $this->seedParameters())->topUp
        );
    }

    /**
     * The invariant guard. Dividing by 30 unconditionally would give a whole
     * February 28/30 of the statutory floor and a whole 31-day month 31/30 of
     * it — both wrong, and both invisible without these two tests. A whole
     * month keeps `min_base` exactly as it stands, whatever its length.
     */
    public function test_a_whole_february_keeps_the_unprorated_floor(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 2, 'hours' => 160]);
        $this->employeeOn($company, 20000, 'gross');

        $runEmployee = app(PayrollRunService::class)->open($company, 2026, 2)->employees->first();

        $this->assertSame(28, $runEmployee->staz_days);
        $this->assertSame(20000.0, round($runEmployee->gross, 2));

        // 34.571 − 20.000 = 14.571 short, undivided. Prorating 28/30 would
        // leave 12.266,27 and a smaller top-up.
        $this->assertSame(4079.89, round($runEmployee->top_up, 2));
    }

    public function test_a_whole_thirty_one_day_month_keeps_the_unprorated_floor(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);
        $this->employeeOn($company, 20000, 'gross');

        $runEmployee = app(PayrollRunService::class)->open($company, 2026, 7)->employees->first();

        $this->assertSame(31, $runEmployee->staz_days);
        $this->assertSame(20000.0, round($runEmployee->gross, 2));

        // The same 14.571 as February's. Prorating 31/30 would inflate the
        // floor to 35.723,37 and the top-up with it.
        $this->assertSame(4079.89, round($runEmployee->top_up, 2));
    }

    /**
     * Hired on Saturday 31 January 2026: one day of insurance, no working day
     * in it, so no pay. `confirm()` skips an employee whose employer gross is
     * zero and posts nothing — correctly — while the payslip prints any stored
     * top-up above zero. A stored top-up here would advertise money the books
     * never saw, and would go on to be filed in МПИН from the same columns.
     */
    public function test_an_employee_with_nothing_to_pay_stores_no_top_up(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 1, 'hours' => 176]);
        $this->employeeOn($company, 38507, 'gross')->update(['employed_on' => '2026-01-31']);

        $runEmployee = app(PayrollRunService::class)->open($company, 2026, 1)->employees->first();

        $this->assertSame(0.0, round($runEmployee->gross, 2));
        $this->assertSame(0.0, round($runEmployee->top_up, 2));
        $this->assertSame(0.0, round($runEmployee->top_up_pension, 2));
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

    public function test_a_half_time_employee_gets_half_the_fund(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 1, 'hours' => 176]);
        $this->partTimeEmployeeOn($company, 34571, 20);

        $run = app(PayrollRunService::class)->open($company, 2026, 1);

        // Јануари 2026 има 22 работни дена, значи фонд 176 за полно работно
        // време. Празниците не се одземаат — потврдено со вистинска МПИН
        // датотека каде истиот работник има 88 часа.
        $this->assertSame(176, $run->month_hours);

        $line = $run->employees->first()->lines->firstWhere('code', '001');
        $this->assertSame(88, $line->hours);
    }

    public function test_half_the_fund_does_not_move_the_agreed_gross(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 1, 'hours' => 176]);
        $this->partTimeEmployeeOn($company, 34571, 20);

        $run = app(PayrollRunService::class)->open($company, 2026, 1);

        $this->assertSame(34571.0, round((float) $run->employees->first()->gross));
    }

    public function test_a_full_time_employee_is_untouched(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 5, 'hours' => 168]);
        $this->partTimeEmployeeOn($company, 38507, 40);

        $run = app(PayrollRunService::class)->open($company, 2026, 5);

        $line = $run->employees->first()->lines->firstWhere('code', '001');
        $this->assertSame(168, $line->hours);
        $this->assertSame(38507.0, round((float) $run->employees->first()->gross));
    }
}
