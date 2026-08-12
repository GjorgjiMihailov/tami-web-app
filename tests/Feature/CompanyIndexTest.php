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

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_only_an_admin_may_open_the_companies_screen(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $this->actingAs($client)->get(route('companies.index'))->assertForbidden();
        $this->actingAs($accountant)->get(route('companies.index'))->assertForbidden();
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

    // The two per-role list-filtering tests that stood here are gone: Фирми is
    // an admin-only screen now, so a client or accountant never sees a filtered
    // list — they are refused outright. test_only_an_admin_may_open_the_companies_screen
    // above is the replacement, and the accountant's own multi-company chooser
    // is covered by DashboardTest.

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
            ->assertSee('Фирми');
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

        // Refused at mount now — a client cannot even open the screen, let
        // alone submit the form.
        Livewire::test(CompanyIndex::class)->assertForbidden();

        $this->assertDatabaseMissing('companies', ['name' => 'Sneaky DOO']);
    }

    public function test_add_company_form_is_not_shown_to_non_admins(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        // The whole screen is refused to a non-admin, which subsumes hiding
        // the form on it.
        Livewire::test(CompanyIndex::class)->assertForbidden();
    }

    public function test_the_companies_list_no_longer_shows_per_company_module_links(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['name' => 'Alpha Ltd']);

        $this->actingAs($admin);

        Livewire::test(CompanyIndex::class)
            ->assertDontSeeHtml(route('accounting.accounts.index', $company))
            ->assertDontSeeHtml(route('inventory.warehouses.index', $company))
            ->assertDontSeeHtml(route('sales-invoices.index', $company))
            ->assertDontSeeHtml(route('inventory.stock-movements.create', [$company, 'receipt']));
    }
}
