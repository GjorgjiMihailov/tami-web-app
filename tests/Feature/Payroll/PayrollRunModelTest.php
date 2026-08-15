<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollParameter;
use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use App\Models\PayrollRunLine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollRunModelTest extends TestCase
{
    use RefreshDatabase;

    private function parameter(): PayrollParameter
    {
        // Not 2026-07-01: the payroll_parameters seeding migration already
        // inserts a row for that exact date with these exact rates, so
        // ::create() here would collide with it under RefreshDatabase.
        return PayrollParameter::create([
            'effective_from' => '2026-07-02',
            'rate_pension' => 19.9, 'rate_health' => 7.5, 'rate_injury' => 0.5,
            'rate_unemployment' => 0.1, 'rate_tax' => 10.0,
            'personal_allowance' => 10932, 'average_salary' => 69141,
            'min_base' => 34571, 'max_base' => 1106256, 'minimum_wage' => 38507,
        ]);
    }

    public function test_a_company_has_one_run_per_month(): void
    {
        $company = Company::factory()->create();
        $parameter = $this->parameter();

        PayrollRun::create([
            'company_id' => $company->id, 'year' => 2026, 'month' => 7,
            'status' => PayrollRun::DRAFT, 'month_hours' => 184,
            'payroll_parameter_id' => $parameter->id,
        ]);

        $this->expectException(QueryException::class);

        PayrollRun::create([
            'company_id' => $company->id, 'year' => 2026, 'month' => 7,
            'status' => PayrollRun::DRAFT, 'month_hours' => 184,
            'payroll_parameter_id' => $parameter->id,
        ]);
    }

    public function test_it_walks_from_run_to_lines(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();

        $run = PayrollRun::create([
            'company_id' => $company->id, 'year' => 2026, 'month' => 7,
            'status' => PayrollRun::DRAFT, 'month_hours' => 184,
            'payroll_parameter_id' => $this->parameter()->id,
        ]);

        $runEmployee = PayrollRunEmployee::create([
            'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
            'gross' => 38507, 'pension' => 7663, 'health' => 2888, 'injury' => 193,
            'unemployment' => 39, 'contributions' => 10783, 'tax_base' => 16792,
            'tax' => 1679, 'net' => 26045, 'deductions_total' => 0,
            'effective_net' => 26045, 'top_up_pension' => 0, 'top_up_health' => 0,
            'top_up_injury' => 0, 'top_up_unemployment' => 0, 'top_up' => 0,
            'hourly_rate' => 209.28, 'seniority_years' => 0, 'full_month_gross' => 38507,
        ]);

        PayrollRunLine::create([
            'payroll_run_employee_id' => $runEmployee->id,
            'kind' => PayrollRunLine::KIND_HOURS, 'code' => '001',
            'description' => 'Редовни работни часови', 'hours' => 184,
            'percent' => 100, 'amount' => 38507,
            'borne_by' => PayrollRunLine::BORNE_EMPLOYER, 'is_automatic' => false,
        ]);

        $this->assertSame('001', $run->fresh()->employees->first()->lines->first()->code);
    }

    public function test_end_of_month_is_the_entry_date(): void
    {
        $run = new PayrollRun(['year' => 2026, 'month' => 2]);

        $this->assertSame('2026-02-28', $run->endOfMonth());
    }
}
