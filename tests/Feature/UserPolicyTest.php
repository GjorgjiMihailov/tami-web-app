<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_an_accountant_may_invite_and_disable_a_client_of_a_company_they_work_on(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($company->id);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->assertTrue($accountant->can('invite', $client));
        $this->assertTrue($accountant->can('disable', $client));
    }

    public function test_an_accountant_may_not_touch_a_client_of_a_company_they_dont_work_on(): void
    {
        $theirCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        // Закачен на $theirCompany, НЕ на $otherCompany — доказ дека самата
        // граница по фирма се проверува, не само дека listата е празна.
        $accountant->assignedCompanies()->attach($theirCompany->id);
        $client = User::factory()->create(['company_id' => $otherCompany->id]);
        $client->assignRole('client');

        $this->assertFalse($accountant->can('invite', $client));
        $this->assertFalse($accountant->can('disable', $client));
    }

    public function test_an_accountant_may_never_touch_an_office_account_even_if_it_has_no_company(): void
    {
        // Сметка на канцеларија (друг сметководител/админ) секогаш има
        // company_id === null. Тоа мора да остане надвор од дофат на
        // сметководителскиот услов без разлика на кои фирми работи —
        // единствениот услов проверен тука е дека company_id е null.
        $officeAccountant = User::factory()->create(['company_id' => null]);
        $officeAccountant->assignRole('accountant');

        $actor = User::factory()->create();
        $actor->assignRole('accountant');

        $this->assertFalse($actor->can('invite', $officeAccountant));
        $this->assertFalse($actor->can('disable', $officeAccountant));
    }

    public function test_an_admin_may_touch_any_client_and_any_office_account(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $officeAccountant = User::factory()->create(['company_id' => null]);
        $officeAccountant->assignRole('accountant');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertTrue($admin->can('invite', $client));
        $this->assertTrue($admin->can('disable', $client));
        $this->assertTrue($admin->can('disable', $officeAccountant));
    }

    public function test_invite_still_refuses_a_disabled_account_even_for_the_assigned_accountant(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($company->id);
        $client = User::factory()->create(['company_id' => $company->id, 'disabled_at' => now()]);
        $client->assignRole('client');

        $this->assertFalse($accountant->can('invite', $client));
    }
}
