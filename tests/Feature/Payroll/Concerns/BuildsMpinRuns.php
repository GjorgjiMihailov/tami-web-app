<?php

namespace Tests\Feature\Payroll\Concerns;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollMonthHours;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Models\User;
use App\Services\Payroll\PayrollRunService;
use App\Support\Payroll\LineType;
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

    /**
     * Потврдена пресметка чии заокружувања намерно се разидуваат: фонд 160
     * часа, договорено бруто 34 571, 8 часа боледување на 70% и една завршена
     * година стаж. Линиите излегуваат 32 842,45 + 1 209,99 + 164,21 = 34 216,65
     * — заокружениот збир е 34 217, а збирот на заокружените линии 34 216.
     *
     * Споделена меѓу градителот (кој мора да ги порамни) и валидаторот (кој
     * мора да го фати расчекорот ако градителот повторно го изгуби).
     */
    private function roundingClashRun(): PayrollRun
    {
        PayrollMonthHours::firstOrCreate(
            ['year' => 2026, 'month' => 4],
            ['hours' => 160],
        );

        $company = Company::factory()->create([
            'name' => 'DESIGNIA DOOEL',
            'tax_id' => '4080000000000',
            'mpin_obvrznik_code' => MpinObvrznik::EMPLOYER,
        ]);

        $employee = Employee::factory()->for($company)->create([
            'embg' => '0101990450006',
            'municipality_code' => '130',
            'health_area_code' => '4061',
            'bank_account' => '300000000000000',
            'insurance_type_code' => '0050',
            'movement_code' => '1',
            'exemption_code' => null,
            'weekly_hours' => 40,
            'employed_on' => '2026-01-01',
            'terminated_on' => null,
            // 4 месеци во фирмава + 12 донесени = 16 месеци = точно една
            // завршена година, значи минат труд > 0.
            'prior_service_months' => 12,
        ]);

        EmployeeSalary::create([
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'amount' => 34571,
            'basis' => 'gross',
        ]);

        $service = app(PayrollRunService::class);
        $run = $service->open($company, 2026, 4);
        $row = $run->employees->first();

        $row->lines->firstWhere('code', '001')->update(['hours' => 152]);

        PayrollRunLine::create([
            'payroll_run_employee_id' => $row->id,
            'kind' => PayrollRunLine::KIND_HOURS,
            'code' => '125',
            'description' => LineType::label('125'),
            'hours' => 8,
            'percent' => 70,
            'amount' => 0,
            'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
            'is_automatic' => false,
        ]);

        $user = User::factory()->create();

        return $service->confirm($service->recalculate($run->fresh()), $user->id)->fresh();
    }
}
