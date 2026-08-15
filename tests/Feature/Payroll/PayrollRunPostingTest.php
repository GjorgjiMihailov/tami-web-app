<?php

namespace Tests\Feature\Payroll;

use App\Models\Account;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollMonthHours;
use App\Models\PayrollParameter;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Models\User;
use App\Services\Payroll\PayrollRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollRunPostingTest extends TestCase
{
    use RefreshDatabase;

    // NOTE: CompanyObserver already auto-seeds the full official chart (all
    // accounts, including every code below) on Company::factory()->create().
    // firstOrCreate() keeps this helper's intent (guarantee these codes
    // exist) without violating the accounts(company_id, code) unique
    // constraint by double-inserting them. Same pattern as
    // tests/Unit/SalesInvoiceServiceTest.php::seedAccounts().
    private function company(): Company
    {
        $company = Company::factory()->create();

        foreach (['421' => 'Плата — бруто', '240' => 'Обврски за плата',
            '249' => 'Останати обврски спрема вработените',
            '234' => 'Обврски за придонеси', '235' => 'Персонален данок'] as $code => $name) {
            Account::firstOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                ['name' => $name]
            );
        }

        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);

        return $company;
    }

    private function employeeOn(Company $company, float $amount): Employee
    {
        $employee = Employee::factory()->for($company)->create([
            // Hired inside the run year on purpose: at 2026-07-31 that is zero
            // completed years, so no seniority line is appended and the figures
            // stay УЈП's published ones. Tests that need seniority set their own
            // hire date.
            'employed_on' => '2026-01-01', 'prior_service_months' => 0,
        ]);

        EmployeeSalary::create([
            'employee_id' => $employee->id, 'effective_from' => '2026-01-01',
            'amount' => $amount, 'basis' => 'gross',
        ]);

        return $employee;
    }

    public function test_confirming_posts_a_balanced_entry(): void
    {
        $company = $this->company();
        $this->employeeOn($company, 38507);
        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        $run = $service->confirm($service->open($company, 2026, 7), $user->id);

        $this->assertSame(PayrollRun::CONFIRMED, $run->status);
        $this->assertNotNull($run->journal_entry_id);

        $lines = $run->journalEntry->lines;
        $this->assertSame(
            round($lines->sum(fn ($l) => (float) $l->debit), 2),
            round($lines->sum(fn ($l) => (float) $l->credit), 2)
        );
        $this->assertSame('2026-07-31', $run->journalEntry->entry_date->toDateString());
    }

    public function test_the_entry_hits_the_expected_accounts(): void
    {
        $company = $this->company();
        $this->employeeOn($company, 38507);
        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        $run = $service->confirm($service->open($company, 2026, 7), $user->id);

        $codes = $run->journalEntry->lines
            ->map(fn ($l) => Account::find($l->account_id)->code)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['234', '235', '240', '421'], $codes);
    }

    public function test_the_funds_share_never_reaches_the_ledger(): void
    {
        $company = $this->company();
        $this->employeeOn($company, 38400);
        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        $run = $service->open($company, 2026, 7);
        $runEmployee = $run->employees->first();

        // Half the month on the Fund: 92 ordinary hours, 92 on code 129.
        $runEmployee->lines()->update(['hours' => 92]);
        PayrollRunLine::create([
            'payroll_run_employee_id' => $runEmployee->id,
            'kind' => PayrollRunLine::KIND_HOURS, 'code' => '129',
            'description' => 'Боледување на товар на ФЗО', 'hours' => 92,
            'percent' => 100, 'amount' => 0,
            'borne_by' => PayrollRunLine::BORNE_FZO, 'is_automatic' => false,
        ]);

        $run = $service->confirm($service->recalculate($run->fresh()), $user->id);

        $debit = $run->journalEntry->lines->sum(fn ($l) => (float) $l->debit);
        $gross = $run->employees->first()->gross;

        $this->assertSame(round($gross / 2, 2), round($debit, 2));
    }

    public function test_an_employee_wholly_on_the_fund_adds_nothing(): void
    {
        $company = $this->company();
        $this->employeeOn($company, 38507);
        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        $run = $service->open($company, 2026, 7);
        $runEmployee = $run->employees->first();
        $runEmployee->lines()->update([
            'code' => '129', 'borne_by' => PayrollRunLine::BORNE_FZO,
        ]);

        $run = $service->confirm($service->recalculate($run->fresh()), $user->id);

        $this->assertCount(0, $run->journalEntry->lines);
    }

    public function test_a_deduction_reaches_the_ledger_whole(): void
    {
        $company = $this->company();
        $this->employeeOn($company, 38507);
        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        $run = $service->open($company, 2026, 7);
        PayrollRunLine::create([
            'payroll_run_employee_id' => $run->employees->first()->id,
            'kind' => PayrollRunLine::KIND_DEDUCTION, 'code' => null,
            'description' => 'Кредит', 'hours' => null, 'percent' => null,
            'amount' => 5000, 'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
            'is_automatic' => false,
        ]);

        $run = $service->confirm($service->recalculate($run->fresh()), $user->id);

        $deductionLine = $run->journalEntry->lines
            ->first(fn ($l) => Account::find($l->account_id)->code === '249');

        $this->assertSame(5000.0, round((float) $deductionLine->credit, 2));
    }

    public function test_returning_to_draft_reverses_the_entry_and_reopens_the_run(): void
    {
        $company = $this->company();
        $this->employeeOn($company, 38507);
        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        $run = $service->confirm($service->open($company, 2026, 7), $user->id);
        $originalEntryId = $run->journal_entry_id;

        $run = $service->returnToDraft($run, $user->id);

        $this->assertSame(PayrollRun::DRAFT, $run->status);
        $this->assertNull($run->journal_entry_id);
        $this->assertNull($run->confirmed_at);

        // The original entry stays; a mirror of it now cancels it out.
        $original = \App\Models\JournalEntry::find($originalEntryId);
        $reversal = \App\Models\JournalEntry::where('company_id', $company->id)
            ->where('id', '!=', $originalEntryId)
            ->latest('id')
            ->first();

        $this->assertNotNull($reversal);
        $this->assertSame(
            round($original->lines->sum(fn ($l) => (float) $l->debit), 2),
            round($reversal->lines->sum(fn ($l) => (float) $l->credit), 2)
        );
    }
}
