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
use RuntimeException;
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

        // The run confirms — there is simply nothing for the company to post.
        // An entry header with no lines would be noise in the ledger, so none
        // is created and the link stays empty.
        $this->assertSame(PayrollRun::CONFIRMED, $run->status);
        $this->assertNull($run->journal_entry_id);
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

    public function test_an_oversized_deduction_still_leaves_a_balanced_entry(): void
    {
        // The line form refuses a deduction bigger than the net, so this should
        // not arise in use. It is asserted anyway because the failure mode is
        // silent: a dropped negative row leaves the books out of balance with
        // nothing on screen to say so.
        $company = $this->company();
        $this->employeeOn($company, 38507);
        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        $run = $service->open($company, 2026, 7);
        PayrollRunLine::create([
            'payroll_run_employee_id' => $run->employees->first()->id,
            'kind' => PayrollRunLine::KIND_DEDUCTION, 'code' => null,
            'description' => 'Прекумерна задршка', 'hours' => null, 'percent' => null,
            'amount' => 40000, 'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
            'is_automatic' => false,
        ]);

        $run = $service->confirm($service->recalculate($run->fresh()), $user->id);

        $lines = $run->journalEntry->lines;

        $this->assertSame(
            round($lines->sum(fn ($l) => (float) $l->debit), 2),
            round($lines->sum(fn ($l) => (float) $l->credit), 2)
        );
    }

    /**
     * The walk-around the review found: PayrollRunShow's own guard only
     * checks employer gross at the moment a deduction is entered. Add the
     * deduction while there is still something on the employer's books, then
     * remove that something afterwards (here, by deleting the ordinary-hours
     * line once a Fund-borne line already covers the rest of the month), and
     * the deduction survives recalculate() untouched — recalculate() does not
     * re-validate deductions, it only rebuilds amounts from the lines that
     * are still there. Left unguarded, confirm()'s `if ($share <= 0) {
     * continue; }` would then skip the employee entirely, deductions
     * included: the payslip would print "Задршка" while account 249 got
     * nothing for it. confirm() must refuse instead of proceeding.
     */
    public function test_confirm_refuses_a_deduction_that_has_nothing_on_the_employers_books(): void
    {
        $company = $this->company();
        $employee = $this->employeeOn($company, 38507);
        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        $run = $service->open($company, 2026, 7);
        $runEmployee = $run->employees->first();
        $ordinaryLine = $runEmployee->lines->firstWhere('code', '001');

        // A Fund-borne code-129 line, alongside the ordinary hours.
        PayrollRunLine::create([
            'payroll_run_employee_id' => $runEmployee->id,
            'kind' => PayrollRunLine::KIND_HOURS, 'code' => '129',
            'description' => 'Боледување на товар на ФЗО', 'hours' => 10,
            'percent' => 100, 'amount' => 0,
            'borne_by' => PayrollRunLine::BORNE_FZO, 'is_automatic' => false,
        ]);

        // A deduction, entered while the ordinary-hours line still leaves the
        // employer with a positive gross — PayrollRunShow's guard allows it.
        PayrollRunLine::create([
            'payroll_run_employee_id' => $runEmployee->id,
            'kind' => PayrollRunLine::KIND_DEDUCTION, 'code' => null,
            'description' => 'Кредит', 'hours' => null, 'percent' => null,
            'amount' => 1000, 'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
            'is_automatic' => false,
        ]);

        // Now the walk-around: remove the only line the employer bore.
        $ordinaryLine->delete();

        $run = $service->recalculate($run->fresh());
        $runEmployee = $run->employees->first();

        // recalculate() kept the deduction and left the employer with nothing.
        $this->assertSame(1000.0, round($runEmployee->deductions_total, 2));
        $this->assertSame(0.0, round($runEmployee->employer_gross, 2));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "Вработениот {$employee->full_name} има задршка, но ништо на товар на фирмата. ".
            'Отстрани ја задршката или додади ставка на товар на работодавачот.'
        );

        try {
            $service->confirm($run, $user->id);
        } finally {
            $run = $run->fresh();
            $this->assertSame(PayrollRun::DRAFT, $run->status);
            $this->assertNull($run->journal_entry_id);
            $this->assertDatabaseCount('journal_entries', 0);
        }
    }

    /**
     * The spec calls for the minimum-base top-up to have its own recap row
     * on 421, distinct from the plain gross — `| 421 (доплата до најниска
     * основица) | доплата | |`. Merging it into one 421 figure was cosmetic
     * until the recap started printing the posted entry; from then on a
     * merged figure made the top-up invisible everywhere.
     */
    public function test_a_top_up_posts_as_its_own_421_line_and_the_entry_still_balances(): void
    {
        $company = $this->company();
        // Below July 2026's min_base of 34 571, so SalaryCalculator produces
        // a non-zero top-up.
        $this->employeeOn($company, 20000);
        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        $run = $service->confirm($service->open($company, 2026, 7), $user->id);
        $runEmployee = $run->employees->first();

        $this->assertGreaterThan(0.0, $runEmployee->top_up);

        $entry421Lines = $run->journalEntry->lines
            ->filter(fn ($l) => Account::find($l->account_id)->code === '421')
            ->values();

        $this->assertCount(2, $entry421Lines);

        $grossLine = $entry421Lines->firstWhere('description', "Плата 7/2026");
        $topUpLine = $entry421Lines->firstWhere('description', 'Доплата до најниска основица');

        $this->assertNotNull($grossLine);
        $this->assertNotNull($topUpLine);
        $this->assertSame(round($runEmployee->employer_gross, 2), round((float) $grossLine->debit, 2));
        $this->assertSame(round($runEmployee->top_up, 2), round((float) $topUpLine->debit, 2));

        $lines = $run->journalEntry->lines;
        $this->assertSame(
            round($lines->sum(fn ($l) => (float) $l->debit), 2),
            round($lines->sum(fn ($l) => (float) $l->credit), 2)
        );
    }

    /**
     * The test the partial-month work was missing. Every other partial-month
     * test stops at the hours and the days of service, so nothing ever carried
     * a short month through to gross, contributions and a posted entry — which
     * is how a whole month of minimum-base contributions on half a month of
     * insurance survived eight task reviews.
     *
     * Ана is hired on Sunday 16 August 2026 at the minimum wage: 11 of August's
     * 21 working days, so 88 of 168 hours and a gross of 20.170,33. Her floor is
     * sixteen days of the minimum base, 34.571 / 30 × 16 = 18.437,87, which her
     * gross clears — so no top-up, no second 421 row, and 421 carries her gross
     * alone.
     */
    public function test_a_mid_month_hire_posts_a_balanced_entry_on_the_prorated_base(): void
    {
        $company = $this->company();
        PayrollMonthHours::create(['year' => 2026, 'month' => 8, 'hours' => 168]);

        $employee = $this->employeeOn($company, 38507);
        $employee->update(['employed_on' => '2026-08-16']);

        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        $run = $service->confirm($service->open($company, 2026, 8), $user->id);
        $runEmployee = $run->employees->first();

        $this->assertSame(88, $runEmployee->lines->firstWhere('code', '001')->hours);
        $this->assertSame(16, $runEmployee->staz_days);
        $this->assertSame(20170.33, round($runEmployee->gross, 2));

        // Sixteen days of insurance owe no top-up at the minimum wage. Measured
        // against the whole-month floor this was 4.032,18 of employer
        // contributions, posted to 421 and 234.
        $this->assertSame(0.0, round($runEmployee->top_up, 2));

        $amounts = [];

        foreach ($run->journalEntry->lines as $line) {
            $code = Account::find($line->account_id)->code;
            $amounts[$code] = round((float) $line->debit + (float) $line->credit, 2);
        }

        // 20.170,33 at 19,9 + 7,5 + 0,5 + 0,1 % is 5.647,69 of contributions;
        // 20.170,33 − 5.647,69 − 10.932 personal allowance is a tax base of
        // 3.590,64, taxed at 10 %.
        $this->assertSame([
            '421' => 20170.33,
            '234' => 5647.69,
            '235' => 359.06,
            '240' => 14163.58,
        ], $amounts);

        // One 421 row, not two: a zero top-up posts nothing of its own.
        $this->assertCount(1, $run->journalEntry->lines->filter(
            fn ($l) => Account::find($l->account_id)->code === '421'
        ));

        $lines = $run->journalEntry->lines;
        $this->assertSame(
            round($lines->sum(fn ($l) => (float) $l->debit), 2),
            round($lines->sum(fn ($l) => (float) $l->credit), 2)
        );
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
