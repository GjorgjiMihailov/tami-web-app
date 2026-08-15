<?php

namespace App\Services\Payroll;

use App\Models\Account;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
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

    /**
     * Posts the month and locks it.
     *
     * Only lines the employer bears reach the ledger. The Fund's share is
     * calculated, declared and shown on the payslip, but it is not the
     * company's cost and not its liability — the same parallel track the
     * minimum-base top-up already runs on.
     */
    public function confirm(PayrollRun $run, int $userId): PayrollRun
    {
        if (! $run->isDraft()) {
            throw new RuntimeException('Пресметката е веќе потврдена.');
        }

        return DB::transaction(function () use ($run, $userId) {
            $run = $this->recalculate($run);

            $gross = 0.0;
            $contributions = 0.0;
            $tax = 0.0;
            $deductions = 0.0;
            $net = 0.0;
            $topUp = 0.0;

            foreach ($run->employees as $runEmployee) {
                $share = $runEmployee->gross > 0
                    ? $this->employerGross($runEmployee) / $runEmployee->gross
                    : 0.0;

                if ($share <= 0) {
                    continue;
                }

                $employerGross = $this->employerGross($runEmployee);
                $employerContributions = round($runEmployee->contributions * $share, 2);
                $employerTax = round($runEmployee->tax * $share, 2);

                $gross += $employerGross;
                $contributions += $employerContributions;
                $tax += $employerTax;
                $deductions += $runEmployee->deductions_total;
                $topUp += $runEmployee->top_up;
                $net += round(
                    $employerGross - $employerContributions - $employerTax - $runEmployee->deductions_total,
                    2
                );
            }

            // No entry at all when the company owes nothing — a month where
            // every employee is wholly on the Fund confirms, but posts nothing.
            // An entry header with no lines is noise in the ledger, and a null
            // journal_entry_id says "nothing was posted" more honestly than an
            // empty entry does.
            $entry = null;

            if (round($gross + $topUp, 2) > 0) {
                $label = "Плата {$run->month}/{$run->year}";

                $entry = JournalEntry::create([
                    'company_id' => $run->company_id,
                    'journal_group_id' => $this->systemJournalGroup($run->company)->id,
                    'entry_date' => $run->endOfMonth(),
                    'description' => $label,
                    'created_by' => $userId,
                ]);

                $this->line($entry, $run, '421', $label, round($gross + $topUp, 2), 0.0);
                $this->line($entry, $run, '234', $label, 0.0, round($contributions + $topUp, 2));
                $this->line($entry, $run, '235', $label, 0.0, round($tax, 2));
                $this->line($entry, $run, '249', $label, 0.0, round($deductions, 2));
                $this->line($entry, $run, '240', $label, 0.0, round($net, 2));
            }

            $run->update([
                'status' => PayrollRun::CONFIRMED,
                'journal_entry_id' => $entry?->id,
                'confirmed_by' => $userId,
                'confirmed_at' => now(),
            ]);

            return $run->fresh(['employees.lines', 'journalEntry.lines']);
        });
    }

    public function returnToDraft(PayrollRun $run, int $userId): PayrollRun
    {
        if ($run->isDraft()) {
            throw new RuntimeException('Пресметката е веќе нацрт.');
        }

        return DB::transaction(function () use ($run, $userId) {
            $original = $run->journalEntry;

            if ($original !== null) {
                $reversal = JournalEntry::create([
                    'company_id' => $run->company_id,
                    'journal_group_id' => $original->journal_group_id,
                    'entry_date' => $run->endOfMonth(),
                    'description' => "Сторно: {$original->description}",
                    'created_by' => $userId,
                ]);

                foreach ($original->lines as $line) {
                    $reversal->lines()->create([
                        'account_id' => $line->account_id,
                        'description' => $line->description,
                        'line_date' => $line->line_date,
                        'debit' => $line->credit,
                        'credit' => $line->debit,
                    ]);
                }
            }

            $run->update([
                'status' => PayrollRun::DRAFT,
                'journal_entry_id' => null,
                'confirmed_by' => null,
                'confirmed_at' => null,
            ]);

            return $run->fresh(['employees.lines']);
        });
    }

    private function employerGross(PayrollRunEmployee $runEmployee): float
    {
        return round(
            $runEmployee->lines
                ->where('kind', '!=', PayrollRunLine::KIND_DEDUCTION)
                ->where('borne_by', PayrollRunLine::BORNE_EMPLOYER)
                ->sum('amount'),
            2
        );
    }

    /** Zero-value lines are skipped: an empty row in the ledger is noise. */
    private function line(JournalEntry $entry, PayrollRun $run, string $code, string $label, float $debit, float $credit): void
    {
        // A negative amount on one side is the same amount on the other. This
        // is not cosmetics: without it the zero-skip below would swallow a
        // negative remainder on 240, and an entry that quietly loses a row is
        // an unbalanced set of books. The line form refuses the deduction that
        // would cause it, so this is the second lock, not the first.
        if (round($credit, 2) < 0) {
            $debit += -$credit;
            $credit = 0.0;
        }

        if (round($debit, 2) < 0) {
            $credit += -$debit;
            $debit = 0.0;
        }

        // Only an exactly-zero line is dropped.
        if (round($debit, 2) == 0.0 && round($credit, 2) == 0.0) {
            return;
        }

        $entry->lines()->create([
            'account_id' => $this->account($run->company, $code)->id,
            'description' => $label,
            'line_date' => $run->endOfMonth(),
            'debit' => number_format($debit, 2, '.', ''),
            'credit' => number_format($credit, 2, '.', ''),
        ]);
    }

    private function account(Company $company, string $code): Account
    {
        return Account::where('company_id', $company->id)->where('code', $code)->firstOrFail();
    }

    private function systemJournalGroup(Company $company): JournalGroup
    {
        return JournalGroup::firstOrCreate(
            ['company_id' => $company->id, 'code' => '99'],
            ['name' => 'Автоматски (фактури)', 'sort_order' => 99]
        );
    }
}
