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

    public function test_a_parameter_change_does_not_touch_a_confirmed_run(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);
        $this->employeeOn($company, 38507, 'gross');

        $run = app(PayrollRunService::class)->open($company, 2026, 7);
        $frozenParameterId = $run->payroll_parameter_id;

        PayrollParameter::create([
            'effective_from' => '2026-07-15',
            'rate_pension' => 25.0, 'rate_health' => 9.0, 'rate_injury' => 0.5,
            'rate_unemployment' => 0.1, 'rate_tax' => 12.0,
            'personal_allowance' => 10932, 'average_salary' => 69141,
            'min_base' => 34571, 'max_base' => 1106256, 'minimum_wage' => 38507,
        ]);

        $this->assertSame($frozenParameterId, $run->fresh()->payroll_parameter_id);
    }
}
