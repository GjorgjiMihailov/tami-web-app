<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_an_admin_stays_on_the_dashboard(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
    }

    public function test_a_client_is_sent_straight_into_their_own_company(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client)
            ->get(route('dashboard'))
            ->assertRedirect(route('companies.dashboard', $company));
    }

    public function test_an_accountant_with_one_company_is_sent_into_it(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $this->actingAs($accountant)
            ->get(route('dashboard'))
            ->assertRedirect(route('companies.dashboard', $company));
    }

    public function test_an_accountant_with_several_companies_gets_a_choice_screen(): void
    {
        $first = Company::factory()->create(['name' => 'Прва Фирма']);
        $second = Company::factory()->create(['name' => 'Втора Фирма']);
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $first->accountants()->attach($accountant);
        $second->accountants()->attach($accountant);

        $this->actingAs($accountant)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Прва Фирма')
            ->assertSee('Втора Фирма');
    }

    public function test_an_accountant_returns_to_the_company_they_last_had_open(): void
    {
        $first = Company::factory()->create(['name' => 'Прва Фирма']);
        $second = Company::factory()->create(['name' => 'Втора Фирма']);
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $first->accountants()->attach($accountant);
        $second->accountants()->attach($accountant);

        $this->actingAs($accountant);
        $this->get(route('companies.dashboard', $second))->assertOk();

        $this->get(route('dashboard'))->assertRedirect(route('companies.dashboard', $second));
    }

    public function test_a_remembered_company_that_is_no_longer_visible_is_ignored(): void
    {
        $mine = Company::factory()->create();
        $other = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $mine->accountants()->attach($accountant);

        $this->actingAs($accountant);
        session([CurrentCompany::sessionKey($accountant->id) => $other->id]);

        $this->get(route('dashboard'))->assertRedirect(route('companies.dashboard', $mine));
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

    // A non-admin with exactly one company is now sent straight into it (see
    // test_a_client_is_sent_straight_into_their_own_company), so the picker
    // itself is only ever rendered for an admin or a multi-company accountant.
    public function test_the_picker_still_shows_for_an_admin_with_only_one_company(): void
    {
        Company::factory()->create(['name' => 'Solo Ltd']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

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
        // Uses a two-company accountant: the picker is the only screen they
        // can land on, and a client with one company never reaches it at all.
        $first = Company::factory()->create(['name' => 'Alpha Ltd']);
        $second = Company::factory()->create(['name' => 'Gamma Ltd']);
        Company::factory()->create(['name' => 'Beta Ltd']);
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $first->accountants()->attach($accountant);
        $second->accountants()->attach($accountant);
        $this->actingAs($accountant);

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
