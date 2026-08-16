<?php

namespace Tests\Feature\Payroll;

use App\Models\Account;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollMonthHours;
use App\Models\PayrollParameter;
use App\Models\PayrollRun;
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

    public function test_a_client_cannot_download_a_payslip(): void
    {
        $company = Company::factory()->create();
        $run = $this->openRun($company);

        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client)
            ->get(route('payroll.recap-pdf', [$company, $run]))
            ->assertForbidden();
    }
}
