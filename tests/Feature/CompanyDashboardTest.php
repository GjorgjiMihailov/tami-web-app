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

    public function test_admin_sees_the_edit_button(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->assertSee('Уреди');
    }

    public function test_non_admin_does_not_see_the_edit_button(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->assertDontSee('Уреди');
    }

    public function test_non_admin_cannot_start_editing(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->assertForbidden();
    }

    public function test_admin_can_edit_the_companys_profile_fields(): void
    {
        $company = Company::factory()->create(['name' => 'Стара фирма ДОО']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('editName', 'Ажурирана фирма ДОО')
            ->set('editShortName', 'АФ')
            ->set('editRegistrationNumber', '1234567')
            ->set('editNkdCode', '62.01')
            ->set('editNkdName', 'Компјутерско програмирање')
            ->set('editWebsite', 'https://example.mk')
            ->set('editDirectorName', 'Марко Марковски')
            ->set('editDirectorPhone', '070123456')
            ->set('editDirectorEmail', 'marko@example.mk')
            ->set('editIsVatRegistered', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => 'Ажурирана фирма ДОО',
            'short_name' => 'АФ',
            'registration_number' => '1234567',
            'nkd_code' => '62.01',
            'nkd_name' => 'Компјутерско програмирање',
            'website' => 'https://example.mk',
            'director_name' => 'Марко Марковски',
            'director_phone' => '070123456',
            'director_email' => 'marko@example.mk',
            'is_vat_registered' => false,
        ]);
    }

    public function test_editing_the_profile_requires_a_name(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('editName', '')
            ->call('save')
            ->assertHasErrors(['editName' => 'required']);
    }

    public function test_non_admin_cannot_call_save(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->call('save')
            ->assertForbidden();
    }
}
