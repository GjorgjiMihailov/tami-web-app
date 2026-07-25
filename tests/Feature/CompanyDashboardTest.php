<?php

namespace Tests\Feature;

use App\Livewire\CompanyDashboard;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_shows_the_active_companys_name(): void
    {
        $company = Company::factory()->create(['name' => 'Alpha Ltd']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->assertSee('Alpha Ltd');
    }

    public function test_it_links_to_each_module_for_the_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->assertSeeHtml(route('accounting.accounts.index', $company))
            ->assertSeeHtml(route('inventory.warehouses.index', $company))
            ->assertSeeHtml(route('sales-invoices.index', $company))
            ->assertSeeHtml(route('documents.index', $company))
            ->assertSeeHtml(route('reports.ddv04', $company));
    }

    public function test_a_user_without_access_to_the_company_is_forbidden(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $otherCompany->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->assertForbidden();
    }

    public function test_the_route_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create(['name' => 'Alpha Ltd']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('companies.dashboard', $company))
            ->assertOk()
            ->assertSee('Alpha Ltd');
    }
}
