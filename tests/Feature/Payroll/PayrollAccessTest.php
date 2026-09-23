<?php

namespace Tests\Feature\Payroll;

use App\Livewire\Payroll\PayrollRunIndex;
use App\Livewire\Payroll\PayrollRunShow;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollMonthHours;
use App\Models\PayrollParameter;
use App\Models\User;
use App\Services\Payroll\PayrollRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayrollAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'internal_client'] as $role) {
            Role::findOrCreate($role);
        }
    }

    private function internalClient(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('internal_client');

        return $user;
    }

    private function draftRun(Company $company)
    {
        PayrollMonthHours::firstOrCreate(['year' => 2026, 'month' => 7], ['hours' => 184]);
        PayrollParameter::forDate('2026-07-31');
        $employee = Employee::factory()->for($company)->create([
            'employed_on' => '2026-01-01', 'prior_service_months' => 0,
        ]);
        EmployeeSalary::create([
            'employee_id' => $employee->id, 'effective_from' => '2026-01-01',
            'amount' => 38507, 'basis' => 'gross',
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        return app(PayrollRunService::class)->open($company, 2026, 7);
    }

    public function test_an_internal_client_can_view_their_own_companys_payroll_runs(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->internalClient($company))
            ->get(route('payroll-runs.index', $company))
            ->assertOk();
    }

    public function test_an_internal_client_cannot_view_another_companys_payroll_runs(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->internalClient(Company::factory()->create()))
            ->get(route('payroll-runs.index', $company))
            ->assertForbidden();
    }

    public function test_an_internal_client_can_view_their_own_run_and_pdfs_but_not_mpin(): void
    {
        $company = Company::factory()->create();
        $run = $this->draftRun($company);
        $client = $this->internalClient($company);

        $this->actingAs($client)->get(route('payroll-runs.show', [$company, $run]))->assertOk();
        $this->actingAs($client)->get(route('payroll.recap-pdf', [$company, $run]))->assertOk();
        $this->actingAs($client)->get(route('payroll.mpin-export', [$company, $run]))->assertForbidden();
    }

    public function test_an_internal_client_cannot_view_another_companys_run_or_pdf(): void
    {
        $company = Company::factory()->create();
        $run = $this->draftRun($company);
        $other = $this->internalClient(Company::factory()->create());

        $this->actingAs($other)->get(route('payroll-runs.show', [$company, $run]))->assertForbidden();
        $this->actingAs($other)->get(route('payroll.recap-pdf', [$company, $run]))->assertForbidden();
    }

    public function test_an_internal_client_gets_404_for_another_companys_run_through_their_own_company_url(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $runOfB = $this->draftRun($companyB);
        $runEmployeeOfB = $runOfB->employees->first();
        $client = $this->internalClient($companyA);

        $this->actingAs($client)
            ->get(route('payroll-runs.show', [$companyA, $runOfB]))
            ->assertNotFound();
        $this->actingAs($client)
            ->get(route('payroll.recap-pdf', [$companyA, $runOfB]))
            ->assertNotFound();
        $this->actingAs($client)
            ->get(route('payroll.payslip-pdf', [$companyA, $runOfB, $runEmployeeOfB]))
            ->assertNotFound();
    }

    public function test_an_internal_client_cannot_create_a_payroll_run(): void
    {
        $company = Company::factory()->create();

        Livewire::actingAs($this->internalClient($company))
            ->test(PayrollRunIndex::class, ['company' => $company])
            ->set('newMonth', 7)
            ->call('createRun')
            ->assertForbidden();
    }

    public function test_an_internal_client_cannot_confirm_a_payroll_run(): void
    {
        $company = Company::factory()->create();
        $run = $this->draftRun($company);

        Livewire::actingAs($this->internalClient($company))
            ->test(PayrollRunShow::class, ['company' => $company, 'run' => $run])
            ->call('confirm')
            ->assertForbidden();

        $this->assertSame('draft', $run->fresh()->status);
    }

    public function test_an_internal_client_cannot_return_a_run_to_draft(): void
    {
        $company = Company::factory()->create();
        $run = $this->draftRun($company);
        app(PayrollRunService::class)->confirm($run, auth()->id());
        $this->assertSame('confirmed', $run->fresh()->status);

        Livewire::actingAs($this->internalClient($company))
            ->test(PayrollRunShow::class, ['company' => $company, 'run' => $run->fresh()])
            ->call('returnToDraft')
            ->assertForbidden();

        $this->assertSame('confirmed', $run->fresh()->status);
    }

    public function test_an_internal_client_cannot_save_or_delete_a_line(): void
    {
        $company = Company::factory()->create();
        $run = $this->draftRun($company);

        Livewire::actingAs($this->internalClient($company))
            ->test(PayrollRunShow::class, ['company' => $company, 'run' => $run])
            ->call('saveLine')
            ->assertForbidden();

        Livewire::actingAs($this->internalClient($company))
            ->test(PayrollRunShow::class, ['company' => $company, 'run' => $run])
            ->call('deleteLine', 1)
            ->assertForbidden();
    }

    public function test_an_accountant_can(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $this->actingAs($accountant)
            ->get(route('payroll-runs.index', $company))
            ->assertOk();
    }

    public function test_an_admin_can(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('payroll-runs.index', $company))
            ->assertOk();
    }

    /**
     * Route-only middleware никогаш не стигнува до Livewire-овиот заеднички
     * `/livewire/update` — а EnsureAccountingAccess веќе ни не е на почетната
     * рута. Затоа секое дејство што пишува си носи сопствена
     * `managePayroll` проверка. Овој тест го проверува тоа на вистинска HTTP
     * рута (Livewire::test() не ги повторува middleware-ите): страницата ја
     * вчитува админ, снимката се праќа како internal_client и се вика
     * `confirm`, најризичното од дејствата.
     */
    public function test_a_user_without_accounting_access_cannot_call_a_payroll_action_on_the_update_endpoint(): void
    {
        Role::findOrCreate('internal_client');

        $company = Company::factory()->create();
        PayrollMonthHours::firstOrCreate(['year' => 2026, 'month' => 7], ['hours' => 184]);
        PayrollParameter::forDate('2026-07-31');

        $employee = Employee::factory()->for($company)->create([
            'employed_on' => '2026-01-01', 'prior_service_months' => 0,
        ]);
        EmployeeSalary::create([
            'employee_id' => $employee->id, 'effective_from' => '2026-01-01',
            'amount' => 38507, 'basis' => 'gross',
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        $run = app(PayrollRunService::class)->open($company, 2026, 7);

        $html = $this->get(route('payroll-runs.show', [$company, $run]))
            ->assertOk()
            ->getContent();

        // Страницата носи повеќе компоненти (на пр. сајдбарот) — го бираме
        // снимкот на PayrollRunShow, не првиот што ќе се сретне.
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
        $snapshot = collect($matches[1])
            ->map(fn ($raw) => html_entity_decode($raw))
            ->first(fn ($raw) => str_contains($raw, 'payroll-run-show') || str_contains($raw, 'PayrollRunShow'));
        $this->assertNotNull($snapshot, 'Could not find the PayrollRunShow wire:snapshot in the rendered page.');

        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');
        $this->actingAs($client);

        $this->postJson('/livewire/update', [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [['path' => '', 'method' => 'confirm', 'params' => []]],
            ]],
        ], ['X-Livewire' => 'true'])->assertForbidden();

        $this->assertSame('draft', $run->fresh()->status);
    }
}
