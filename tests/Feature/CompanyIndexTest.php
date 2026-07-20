<?php

namespace Tests\Feature;

use App\Livewire\CompanyIndex;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_admin_sees_all_companies(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Company::factory()->create(['name' => 'Alpha Ltd']);
        Company::factory()->create(['name' => 'Beta Ltd']);

        $this->actingAs($admin);

        Livewire::test(CompanyIndex::class)
            ->assertSee('Alpha Ltd')
            ->assertSee('Beta Ltd');
    }

    public function test_client_sees_only_their_own_company(): void
    {
        $companyA = Company::factory()->create(['name' => 'Alpha Ltd']);
        $companyB = Company::factory()->create(['name' => 'Beta Ltd']);
        $client = User::factory()->create(['company_id' => $companyA->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        Livewire::test(CompanyIndex::class)
            ->assertSee('Alpha Ltd')
            ->assertDontSee('Beta Ltd');
    }

    public function test_accountant_sees_only_assigned_companies(): void
    {
        $companyA = Company::factory()->create(['name' => 'Alpha Ltd']);
        $companyB = Company::factory()->create(['name' => 'Beta Ltd']);
        $companyC = Company::factory()->create(['name' => 'Gamma Ltd']);
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach([$companyA->id, $companyB->id]);

        $this->actingAs($accountant);

        Livewire::test(CompanyIndex::class)
            ->assertSee('Alpha Ltd')
            ->assertSee('Beta Ltd')
            ->assertDontSee('Gamma Ltd');
    }

    public function test_the_route_requires_authentication(): void
    {
        $this->get('/companies')->assertRedirect('/login');
    }

    public function test_the_companies_page_renders_successfully_over_http(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get('/companies')
            ->assertOk()
            ->assertSee('Companies');
    }

    public function test_admin_can_add_a_company(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(CompanyIndex::class)
            ->set('newName', 'New Client DOO')
            ->set('newTaxId', '4012345678901')
            ->set('newEmail', 'contact@newclient.mk')
            ->call('addCompany')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('companies', [
            'name' => 'New Client DOO',
            'tax_id' => '4012345678901',
            'email' => 'contact@newclient.mk',
        ]);
    }

    public function test_adding_a_company_requires_a_name(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(CompanyIndex::class)
            ->set('newName', '')
            ->call('addCompany')
            ->assertHasErrors(['newName' => 'required']);
    }

    public function test_client_cannot_add_a_company(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        Livewire::test(CompanyIndex::class)
            ->set('newName', 'Sneaky DOO')
            ->call('addCompany')
            ->assertForbidden();

        $this->assertDatabaseMissing('companies', ['name' => 'Sneaky DOO']);
    }

    public function test_add_company_form_is_not_shown_to_non_admins(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        Livewire::test(CompanyIndex::class)
            ->assertDontSee('Add company');
    }

    public function test_the_companies_list_links_to_inventory_screens_for_a_visible_company(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['name' => 'Alpha Ltd']);

        $this->actingAs($admin);

        Livewire::test(CompanyIndex::class)
            ->assertSeeHtml(route('inventory.warehouses.index', $company))
            ->assertSeeHtml(route('inventory.items.index', $company))
            ->assertSeeHtml(route('inventory.reports.stock-on-hand', $company))
            ->assertSeeHtml(route('inventory.reports.item-movement-card', $company))
            ->assertSeeHtml(route('inventory.reports.stock-valuation', $company))
            ->assertSeeHtml(route('inventory.stock-movements.create', [$company, 'receipt']))
            ->assertSeeHtml(route('inventory.stock-movements.create', [$company, 'issue']))
            ->assertSeeHtml(route('inventory.stock-movements.create', [$company, 'transfer']))
            ->assertSeeHtml(route('inventory.stock-movements.create', [$company, 'adjustment']));
    }
}
