<?php

namespace App\Services\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollMonthHours;
use App\Models\PayrollParameter;
use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use App\Models\PayrollRunLine;
use App\Support\Payroll\LineType;
use App\Support\Payroll\PayrollRunCalculator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PayrollRunService
{
    /**
     * Opens the month and fills it in: every employee still working at the end
     * of the month, each on a full month of ordinary hours. An unremarkable
     * month is then one button, not one row of typing per person.
     */
    public function open(Company $company, int $year, int $month): PayrollRun
    {
        return DB::transaction(function () use ($company, $year, $month) {
            $fund = PayrollMonthHours::forMonth($year, $month);

            $run = PayrollRun::create([
                'company_id' => $company->id,
                'year' => $year,
                'month' => $month,
                'status' => PayrollRun::DRAFT,
                'month_hours' => $fund->hours,
                'payroll_parameter_id' => PayrollParameter::forDate(
                    $this->endOfMonth($year, $month)
                )->id,
            ]);

            $asOf = $run->endOfMonth();

            $employees = Employee::where('company_id', $company->id)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get()
                ->filter(fn (Employee $e) => $e->isActiveOn($asOf));

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
                    'hours' => $fund->hours,
                    'percent' => 100,
                    'amount' => 0,
                    'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
                    'is_automatic' => false,
                ]);
            }

            return $this->recalculate($run->fresh());
        });
    }

    /**
     * Recomputes every employee in the run from their lines.
     *
     * Automatic lines are thrown away and rebuilt rather than updated: they are
     * derived, so treating them as stored state is how a stale seniority bonus
     * survives a change to the hours it was derived from.
     */
    public function recalculate(PayrollRun $run): PayrollRun
    {
        if (! $run->isDraft()) {
            throw new RuntimeException('Потврдена пресметка не се пресметува повторно.');
        }

        return DB::transaction(function () use ($run) {
            $parameters = $run->parameter;
            $asOf = $run->endOfMonth();

            foreach ($run->employees()->with(['employee', 'lines'])->get() as $runEmployee) {
                $employee = $runEmployee->employee;
                $salary = $employee->salaryOn($asOf);

                $fullMonthGross = $salary === null
                    ? 0.0
                    : PayrollRunCalculator::fullMonthGross((float) $salary->amount, $salary->basis, $parameters);

                $inputLines = $runEmployee->lines
                    ->reject(fn (PayrollRunLine $line) => $line->is_automatic)
                    ->map(fn (PayrollRunLine $line) => [
                        'kind' => $line->kind,
                        'code' => $line->code,
                        'description' => $line->description,
                        'hours' => $line->hours,
                        'percent' => $line->percent,
                        'amount' => $line->amount,
                        'borne_by' => $line->borne_by,
                    ])
                    ->values()
                    ->all();

                $result = PayrollRunCalculator::calculate(
                    fullMonthGross: $fullMonthGross,
                    monthHours: $run->month_hours,
                    seniorityYears: $employee->seniorityYearsOn($asOf),
                    inputLines: $inputLines,
                    parameters: $parameters,
                );

                $runEmployee->lines()->delete();

                foreach ($result->lines as $line) {
                    PayrollRunLine::create([
                        'payroll_run_employee_id' => $runEmployee->id,
                        'kind' => $line->kind,
                        'code' => $line->code,
                        'description' => $line->description,
                        'hours' => $line->hours,
                        'percent' => $line->percent,
                        'amount' => $line->amount,
                        'borne_by' => $line->borneBy,
                        'is_automatic' => $line->isAutomatic,
                    ]);
                }

                $runEmployee->update([
                    'gross' => $result->gross,
                    'pension' => $result->breakdown->pension,
                    'health' => $result->breakdown->health,
                    'injury' => $result->breakdown->injury,
                    'unemployment' => $result->breakdown->unemployment,
                    'contributions' => $result->breakdown->contributions,
                    'tax_base' => $result->breakdown->taxBase,
                    'tax' => $result->breakdown->tax,
                    'net' => $result->breakdown->net,
                    'deductions_total' => $result->deductionsTotal,
                    'effective_net' => $result->effectiveNet,
                    'top_up_pension' => $result->breakdown->topUpPension,
                    'top_up_health' => $result->breakdown->topUpHealth,
                    'top_up_injury' => $result->breakdown->topUpInjury,
                    'top_up_unemployment' => $result->breakdown->topUpUnemployment,
                    'top_up' => $result->breakdown->topUp,
                    'hourly_rate' => $result->hourlyRate,
                    'seniority_years' => $employee->seniorityYearsOn($asOf),
                    'full_month_gross' => $fullMonthGross,
                ]);
            }

            return $run->fresh(['employees.lines', 'employees.employee']);
        });
    }

    private function endOfMonth(int $year, int $month): string
    {
        return \Carbon\Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
    }
}
