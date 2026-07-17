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
}
