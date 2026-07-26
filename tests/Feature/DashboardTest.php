<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_shows_a_company_picker_listing_every_visible_company(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Company::factory()->create(['name' => 'Alpha Ltd']);
        Company::factory()->create(['name' => 'Beta Ltd']);
        $this->actingAs($admin);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Alpha Ltd')
            ->assertSee('Beta Ltd');
    }

    public function test_the_picker_still_shows_for_a_user_with_only_one_company(): void
    {
        $company = Company::factory()->create(['name' => 'Solo Ltd']);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Solo Ltd')
            ->assertSee('Изберете фирма');
    }

    public function test_picking_a_company_links_to_its_own_dashboard(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['name' => 'Alpha Ltd']);
        $this->actingAs($admin);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSeeHtml(route('companies.dashboard', $company));
    }

    public function test_it_does_not_show_companies_the_user_cannot_access(): void
    {
        $ownCompany = Company::factory()->create(['name' => 'Alpha Ltd']);
        $otherCompany = Company::factory()->create(['name' => 'Beta Ltd']);
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Alpha Ltd')
            ->assertDontSee('Beta Ltd');
    }

    public function test_the_popup_has_no_dismiss_control(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Company::factory()->create(['name' => 'Alpha Ltd']);
        $this->actingAs($admin);

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('×')
            ->assertDontSeeHtml('close-modal');
    }

    public function test_the_route_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_zero_company_user_can_escape_to_companies_index(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSeeHtml(route('companies.index'));
    }
}
