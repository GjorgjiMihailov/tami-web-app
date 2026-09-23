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

        foreach (['admin', 'accountant', 'internal_client'] as $role) {
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
        $client->assignRole('internal_client');

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

    public function test_an_admin_can_always_create_a_company(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertTrue($admin->can('create', Company::class));
    }

    public function test_an_accountant_without_a_single_company_may_create_one(): void
    {
        // Излезот за нов сметководител: без ова тој нема каде да почне, зашто
        // екранот „Фирми" за него враќа 403. Види App\Livewire\FirstClient.
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');

        $this->assertTrue($accountant->can('create', Company::class));
    }

    public function test_an_accountant_with_existing_companies_may_still_create_another(): void
    {
        // Спротивно од порано: правото повеќе не се затвора по првото
        // создавање. Сопственикот побара сметководителот сам ги внесува
        // сите свои клиенти, не само првиот.
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        Company::factory()->create()->accountants()->attach($accountant);
        Company::factory()->create()->accountants()->attach($accountant);

        $this->assertTrue($accountant->can('create', Company::class));
    }

    public function test_a_client_may_never_create_a_company(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        $this->assertFalse($client->can('create', Company::class));
    }

    public function test_a_client_without_any_company_still_may_not_create_one(): void
    {
        // Правилото е врзано за улогата „сметководител", не за празнината.
        $client = User::factory()->create();
        $client->assignRole('internal_client');

        $this->assertFalse($client->can('create', Company::class));
    }

    public function test_an_accountant_may_update_only_the_companies_they_work_on(): void
    {
        $mine = Company::factory()->create();
        $notMine = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($mine->id);

        $this->assertTrue($accountant->can('update', $mine));
        $this->assertFalse($accountant->can('update', $notMine));
    }

    public function test_an_admin_and_the_assigned_accountant_can_update_a_company_a_client_cannot(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        $this->assertTrue($admin->can('update', $company));
        $this->assertFalse($client->can('update', $company));
    }
}
