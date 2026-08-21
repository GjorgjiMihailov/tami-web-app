<?php

namespace Tests\Feature\Payroll\Concerns;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollMonthHours;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\PayrollRunService;
use App\Support\Payroll\MpinObvrznik;

trait BuildsMpinRuns
{
    /**
     * Потврдена пресметка за мај 2026 што одговара на вистинската поднесена
     * датотека на обврзник 110. Отстапувањата се предаваат како преклопувања,
     * за секој тест да менува точно едно нешто.
     *
     * @param  array<string, mixed>  $companyOverrides
     * @param  array<string, mixed>  $employeeOverrides
     */
    private function mpinRun(array $companyOverrides = [], array $employeeOverrides = []): PayrollRun
    {
        PayrollMonthHours::firstOrCreate(
            ['year' => 2026, 'month' => 5],
            ['hours' => 168],
        );

        $company = Company::factory()->create([
            'name' => 'DESIGNIA DOOEL',
            'tax_id' => '4080000000000',
            'mpin_obvrznik_code' => MpinObvrznik::EMPLOYER,
            ...$companyOverrides,
        ]);

        $employee = Employee::factory()->for($company)->create([
            'embg' => '0101990450006',
            'municipality_code' => '130',
            'health_area_code' => '4061',
            'bank_account' => '300000000000000',
            'insurance_type_code' => '0050',
            'movement_code' => '1',
            'weekly_hours' => 40,
            'employed_on' => '2026-01-01',
            'terminated_on' => null,
            'prior_service_months' => 0,
            ...$employeeOverrides,
        ]);

        EmployeeSalary::create([
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'amount' => 38507,
            'basis' => 'gross',
        ]);

        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        return $service->confirm($service->open($company, 2026, 5), $user->id)->fresh();
    }
}
