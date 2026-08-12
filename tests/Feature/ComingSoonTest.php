<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ComingSoonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_an_admin_sees_the_feature_name_and_what_it_will_do(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('coming-soon', [$company, 'popis']))
            ->assertOk()
            ->assertSee('Попис')
            ->assertSee('наскоро')
            ->assertSee('Овде ќе се прави годишен попис на залихите и ќе се книжат разликите.');
    }

    public function test_an_accountant_may_open_it_too(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $this->actingAs($accountant)
            ->get(route('coming-soon', [$company, 'izvodi']))
            ->assertOk()
            ->assertSee('Изводи');
    }

    public function test_a_client_is_refused_even_by_direct_url(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client)
            ->get(route('coming-soon', [$company, 'popis']))
            ->assertForbidden();
    }

    public function test_an_unknown_feature_is_a_404(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('coming-soon', [$company, 'ne-postoi']))
            ->assertNotFound();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $company = Company::factory()->create();

        $this->get(route('coming-soon', [$company, 'popis']))->assertRedirect(route('login'));
    }
}
