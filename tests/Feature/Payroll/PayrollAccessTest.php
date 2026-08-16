<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollMonthHours;
use App\Models\PayrollParameter;
use App\Models\User;
use App\Services\Payroll\PayrollRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayrollAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'client'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_a_client_cannot_reach_the_payroll_run_url(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client)
            ->get(route('payroll-runs.index', $company))
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
     * `PayrollRunShow::mount()` authorizes once, when the component is
     * instantiated. It does not re-run on a later Livewire action call, and
     * EnsureAccountingAccess is route-group middleware that by default does
     * not survive to Livewire's shared update endpoint. Without registering
     * it via `Livewire::addPersistentMiddleware()` (see
     * AppServiceProvider::boot()), a user who loses their accounting role
     * mid-session — or a client who somehow gets handed a live component
     * instance — would keep a working "confirm" button.
     *
     * `Livewire::test()->call(...)` cannot exercise this: Livewire's own
     * PersistentMiddleware mechanism explicitly skips re-applying middleware
     * for "fake requests such as a test" (see the mechanism's source), so it
     * only ever runs against a real HTTP request hitting the real
     * `livewire/update` route. This test does that for real: it loads the
     * page as an authorized admin, pulls the live `wire:snapshot` out of the
     * rendered HTML, then — acting as a different, unauthorized user —
     * posts that same snapshot straight to `/livewire/update` and calls
     * `confirm`, the highest-stakes of the four write actions.
     */
    public function test_a_user_without_accounting_access_cannot_call_a_payroll_action_on_the_update_endpoint(): void
    {
        Role::findOrCreate('client');

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

        preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
        $this->assertNotEmpty($matches, 'Could not find wire:snapshot in the rendered page.');
        $snapshot = html_entity_decode($matches[1]);

        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
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
