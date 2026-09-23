<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserCompanyRelationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'internal_client', 'freelancer_client'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_a_freelancer_client_sees_only_their_own_company_and_a_roleless_user_sees_none(): void
    {
        $companyA = Company::factory()->create(['type' => 'individual']);
        $companyB = Company::factory()->create(['type' => 'individual']);

        $freelancer = User::factory()->create(['company_id' => $companyA->id]);
        $freelancer->assignRole('freelancer_client');
        $roleless = User::factory()->create(['company_id' => $companyA->id]);

        $this->assertSame([$companyA->id], $freelancer->visibleCompanies()->pluck('id')->all());
        $this->assertFalse($freelancer->visibleCompanies()->whereKey($companyB->id)->exists());
        $this->assertSame(0, $roleless->visibleCompanies()->count());
    }

    public function test_an_admin_can_see_all_companies_via_visible_companies(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertCount(2, $admin->visibleCompanies()->get());
    }

    public function test_a_client_user_belongs_to_one_company(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);

        $this->assertTrue($client->company->is($company));
    }

    public function test_an_accountant_can_be_assigned_to_multiple_companies(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $accountant = User::factory()->create();

        $accountant->assignedCompanies()->attach([$companyA->id, $companyB->id]);

        $this->assertCount(2, $accountant->assignedCompanies()->get());
        $this->assertTrue($accountant->assignedCompanies->contains($companyA));
        $this->assertTrue($accountant->assignedCompanies->contains($companyB));
    }

    public function test_is_client_is_true_for_both_client_roles(): void
    {
        $internal = User::factory()->create();
        $internal->assignRole('internal_client');
        $freelancer = User::factory()->create();
        $freelancer->assignRole('freelancer_client');

        $this->assertTrue($internal->isClient());
        $this->assertTrue($freelancer->isClient());
    }

    public function test_is_client_is_false_for_office_roles(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');

        $this->assertFalse($admin->isClient());
        $this->assertFalse($accountant->isClient());
    }
}
