<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'internal_client', 'freelancer_client'] as $role) {
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

    public function test_an_accountant_at_their_company_limit_may_not_create_another(): void
    {
        $accountant = User::factory()->create(['company_limit' => 1]);
        $accountant->assignRole('accountant');
        Company::factory()->create()->accountants()->attach($accountant);

        $this->assertFalse($accountant->can('create', Company::class));
    }

    public function test_an_accountant_under_their_company_limit_may_still_create(): void
    {
        $accountant = User::factory()->create(['company_limit' => 2]);
        $accountant->assignRole('accountant');
        Company::factory()->create()->accountants()->attach($accountant);

        $this->assertTrue($accountant->can('create', Company::class));
    }

    public function test_a_null_company_limit_means_unlimited(): void
    {
        $accountant = User::factory()->create(['company_limit' => null]);
        $accountant->assignRole('accountant');
        Company::factory()->count(5)->create()->each(
            fn (Company $c) => $c->accountants()->attach($accountant)
        );

        $this->assertTrue($accountant->can('create', Company::class));
    }

    private function ownModeCompany(array $overrides = []): Company
    {
        return Company::factory()->create($overrides + [
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
    }

    private function internalClientOf(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('internal_client');

        return $user;
    }

    public function test_admin_and_the_assigned_accountant_can_sign_efaktura_an_unassigned_accountant_cannot(): void
    {
        $company = $this->ownModeCompany();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $mine = User::factory()->create();
        $mine->assignRole('accountant');
        $company->accountants()->attach($mine);
        $other = User::factory()->create();
        $other->assignRole('accountant');

        $this->assertTrue($admin->can('signEfaktura', $company));
        $this->assertTrue($mine->can('signEfaktura', $company));
        $this->assertFalse($other->can('signEfaktura', $company));
        $this->assertTrue($admin->can('manageEfakturaDevice', $company));
        $this->assertTrue($mine->can('manageEfakturaDevice', $company));
        $this->assertFalse($other->can('manageEfakturaDevice', $company));
    }

    public function test_an_internal_client_with_an_own_token_can_sign_and_manage_the_device(): void
    {
        $company = $this->ownModeCompany();
        $client = $this->internalClientOf($company);

        $this->assertTrue($client->can('signEfaktura', $company));
        $this->assertTrue($client->can('manageEfakturaDevice', $company));
    }

    public function test_an_internal_client_without_a_registered_token_can_register_but_not_sign(): void
    {
        $company = $this->ownModeCompany(['efaktura_token_serial_number' => null]);
        $client = $this->internalClientOf($company);

        $this->assertTrue($client->can('manageEfakturaDevice', $company));
        $this->assertFalse($client->can('signEfaktura', $company));
    }

    public function test_an_internal_client_of_a_firm_mode_company_can_do_neither(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM,
            'efaktura_firm_access_status' => Company::EFAKTURA_STATUS_APPROVED,
        ]);
        $client = $this->internalClientOf($company);

        $this->assertFalse($client->can('signEfaktura', $company));
        $this->assertFalse($client->can('manageEfakturaDevice', $company));
    }

    public function test_an_internal_client_cannot_touch_another_companys_efaktura(): void
    {
        $mine = $this->ownModeCompany();
        $theirs = $this->ownModeCompany();
        $client = $this->internalClientOf($mine);

        $this->assertFalse($client->can('signEfaktura', $theirs));
        $this->assertFalse($client->can('manageEfakturaDevice', $theirs));
    }

    public function test_an_internal_client_of_an_individual_company_has_no_efaktura(): void
    {
        $company = Company::factory()->create([
            'type' => CompanyType::INDIVIDUAL,
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
        $client = $this->internalClientOf($company);

        $this->assertFalse($client->can('signEfaktura', $company));
        $this->assertFalse($client->can('manageEfakturaDevice', $company));
    }

    public function test_a_freelancer_client_of_a_legal_company_has_no_efaktura(): void
    {
        $company = $this->ownModeCompany();
        $freelancer = User::factory()->create(['company_id' => $company->id]);
        $freelancer->assignRole('freelancer_client');

        $this->assertFalse($freelancer->can('signEfaktura', $company));
        $this->assertFalse($freelancer->can('manageEfakturaDevice', $company));
    }

    public function test_a_roleless_user_of_an_own_mode_company_has_no_efaktura(): void
    {
        $company = $this->ownModeCompany();
        $user = User::factory()->create(['company_id' => $company->id]);

        $this->assertFalse($user->can('signEfaktura', $company));
        $this->assertFalse($user->can('manageEfakturaDevice', $company));
    }
}
