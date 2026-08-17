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
}
