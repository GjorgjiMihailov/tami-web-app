<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\LandingUrl;
use App\Support\PortalApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LoginLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_a_client_lands_in_the_app_it_signed_into(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->assertSame(
            route('sales-invoices.index', $company),
            LandingUrl::for($client, PortalApp::PRODAZBA)
        );
    }

    public function test_an_accountant_with_many_companies_lands_on_the_portal(): void
    {
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        Company::factory()->count(2)->create()
            ->each(fn (Company $company) => $company->accountants()->attach($accountant->id));

        $this->assertSame(route('dashboard'), LandingUrl::for($accountant, PortalApp::PRODAZBA));
    }

    public function test_the_portal_host_always_lands_on_the_portal(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->assertSame(route('dashboard'), LandingUrl::for($client, PortalApp::PORTAL));
    }

    public function test_a_closed_app_falls_back_to_the_portal(): void
    {
        $company = Company::factory()->create(['uses_payroll' => false]);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->assertSame(route('dashboard'), LandingUrl::for($client, PortalApp::PLATA));
    }

    public function test_every_landing_url_carries_a_host(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        foreach (PortalApp::cases() as $app) {
            $this->assertStringStartsWith('http://', LandingUrl::for($client, $app));
        }
    }
}
