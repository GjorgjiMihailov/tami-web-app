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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayrollPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'client'] as $role) {
            Role::findOrCreate($role);
        }
    }

    private function openRun(Company $company): PayrollRun
    {
        // No account seeding here. CompanyObserver seeds the whole official
        // chart on Company::created, and 421, 240, 249, 234 and 235 are all in
        // it — creating them by hand collides with the unique index.

        // firstOrCreate, not create: the hour fund is a national row, not a
        // per-company one, so a test that opens runs for two companies in the
        // same month would otherwise collide on its (year, month) unique index.
        PayrollMonthHours::firstOrCreate(['year' => 2026, 'month' => 7], ['hours' => 184]);

        $employee = Employee::factory()->for($company)->create([
            'first_name' => 'Ана', 'last_name' => 'Николовска',
            // Hired inside the run year on purpose: at 2026-07-31 that is zero
            // completed years, so no seniority line is appended and the figures
            // stay УЈП's published ones. Tests that need seniority set their own
            // hire date.
            'employed_on' => '2026-01-01', 'prior_service_months' => 0,
        ]);

        EmployeeSalary::create([
            'employee_id' => $employee->id, 'effective_from' => '2026-01-01',
            'amount' => 38507, 'basis' => 'gross',
        ]);

        return app(PayrollRunService::class)->open($company, 2026, 7);
    }

    private function admin(Company $company): User
    {
        // No company association: User has no companies() relation, and an
        // admin reaches every company through visibleCompanies() anyway. A
        // client is the one that needs company_id — see the client test below.
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_the_payslip_renders(): void
    {
        $company = Company::factory()->create();
        $run = $this->openRun($company);

        $response = $this->actingAs($this->admin($company))
            ->get(route('payroll.payslip-pdf', [$company, $run, $run->employees->first()]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_recap_renders(): void
    {
        $company = Company::factory()->create();
        $run = $this->openRun($company);

        $response = $this->actingAs($this->admin($company))
            ->get(route('payroll.recap-pdf', [$company, $run]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_client_cannot_download_either_document(): void
    {
        $company = Company::factory()->create();
        $run = $this->openRun($company);

        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        // Both routes, not just one. They share a middleware group today, so
        // asserting only the recap would keep passing if the payslip's
        // protection were ever removed — and the payslip is the document with
        // one person's pay on it.
        $this->actingAs($client)
            ->get(route('payroll.recap-pdf', [$company, $run]))
            ->assertForbidden();

        $this->actingAs($client)
            ->get(route('payroll.payslip-pdf', [$company, $run, $run->employees->first()]))
            ->assertForbidden();
    }

    /**
     * The PDF tests above prove the documents render. They cannot see what is
     * printed on them, so the two statements that matter most are asserted
     * against the rendered Blade instead — cheap, and it fails if the wording
     * is ever softened.
     */
    public function test_the_payslip_says_the_employer_bears_the_top_up(): void
    {
        $company = Company::factory()->create();
        $run = $this->openRun($company);
        $runEmployee = $run->employees->first();
        $runEmployee->update(['top_up' => 1234.56]);

        $html = view('pdf.payslip', [
            'company' => $company,
            'run' => $run,
            'runEmployee' => $runEmployee->fresh(['employee', 'lines']),
        ])->render();

        $this->assertStringContainsString('на товар на работодавачот', $html);
        $this->assertStringContainsString('Не се одзема од платата на работникот', $html);
    }

    public function test_the_recap_shows_what_was_posted_to_the_ledger(): void
    {
        $company = Company::factory()->create();
        $run = $this->openRun($company);
        $user = $this->admin($company);

        $run = app(PayrollRunService::class)->confirm($run, $user->id);
        $run->load(['employees.employee', 'employees.lines', 'journalEntry.lines.account']);

        $html = view('pdf.payroll-recap', ['company' => $company, 'run' => $run])->render();

        $this->assertStringContainsString('Книжено во главна книга', $html);

        // Hardcoded, not derived from the same collection the view iterates:
        // deriving them would make this loop vacuous — and therefore green —
        // if that relation ever came back empty in both places.
        foreach (['421', '234', '235', '240'] as $code) {
            $this->assertStringContainsString($code, $html);
        }
    }

    public function test_the_recap_does_not_call_a_confirmed_run_a_draft(): void
    {
        $company = Company::factory()->create();
        $run = $this->openRun($company);
        $user = $this->admin($company);

        // The whole month on the Fund: the run confirms but posts nothing, so
        // journal_entry_id stays null. Saying „во нацрт" there would be wrong
        // twice over — it is confirmed, and there was nothing to post.
        $run->employees->first()->lines()->update([
            'code' => '129',
            'borne_by' => PayrollRunLine::BORNE_FZO,
        ]);

        $service = app(PayrollRunService::class);
        $run = $service->confirm($service->recalculate($run->fresh()), $user->id);
        $run->load(['employees.employee', 'employees.lines', 'journalEntry.lines.account']);

        $this->assertNull($run->journal_entry_id);

        $html = view('pdf.payroll-recap', ['company' => $company, 'run' => $run])->render();

        $this->assertStringNotContainsString('во нацрт', $html);
        $this->assertStringContainsString('нема што да се книжи', $html);
    }
}
