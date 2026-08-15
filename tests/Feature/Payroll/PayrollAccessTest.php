<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\User;
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
}
