<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'client'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_admin_can_view_any_company(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create();

        $this->assertTrue($admin->can('view', $company));
    }

    public function test_client_can_view_only_their_own_company(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');

        $this->assertTrue($client->can('view', $ownCompany));
        $this->assertFalse($client->can('view', $otherCompany));
    }

    public function test_accountant_can_view_only_assigned_companies(): void
    {
        $assigned = Company::factory()->create();
        $notAssigned = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($assigned->id);

        $this->assertTrue($accountant->can('view', $assigned));
        $this->assertFalse($accountant->can('view', $notAssigned));
    }
}
