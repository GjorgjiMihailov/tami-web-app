<?php

namespace Tests\Feature;

use App\Livewire\Accounting\AccountIndex;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_it_lists_the_companys_accounts(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(AccountIndex::class, ['company' => $company])
            ->assertSee('Побарувања од купувачи во земјата')
            ->assertSee('120');
    }

    public function test_accountant_can_deactivate_an_account(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($company);
        $account = Account::where('company_id', $company->id)->where('code', '120')->first();

        $this->actingAs($accountant);

        Livewire::test(AccountIndex::class, ['company' => $company])
            ->call('toggleActive', $account->id);

        $this->assertFalse($account->fresh()->is_active);
    }

    public function test_accountant_can_add_an_analytical_account(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($company);

        $this->actingAs($accountant);

        Livewire::test(AccountIndex::class, ['company' => $company])
            ->set('newParentCode', '234')
            ->set('newCode', '2341')
            ->set('newName', 'Обврски за придонес за задолжително пензиско и инвалидско осигурување')
            ->call('addAnalyticalAccount')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('accounts', [
            'company_id' => $company->id,
            'code' => '2341',
            'parent_code' => '234',
            'is_analytical' => true,
        ]);
    }

    public function test_client_cannot_add_an_analytical_account(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        Livewire::test(AccountIndex::class, ['company' => $company])
            ->set('newParentCode', '234')
            ->set('newCode', '2341')
            ->set('newName', 'Test')
            ->call('addAnalyticalAccount')
            ->assertForbidden();
    }

    public function test_the_accounts_page_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('accounting.accounts.index', $company))
            ->assertOk();
    }
}
