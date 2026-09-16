<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\LandingUrl;
use App\Support\PortalApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
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

    /**
     * Без правото за апликацијата, менито сепак има содржина (модулот е
     * вклучен, улогата ја гледа ставката) — LandingUrl мора сам да го провери
     * правото, инаку човекот е пратен право во 403 екранот наместо на порталот.
     */
    public function test_an_app_the_user_has_no_right_for_falls_back_to_the_portal(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id, 'app_plata' => false]);
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

    /**
     * Барана адреса мора да победи над стандардната — тоа е целата смисла на
     * default-от во redirectIntended. Го наместуваме доменот на Продажба да
     * се совпаѓа со хостот на кој навистина работи тестот (localhost), за да
     * се симулира најава на тој субдомен: без ова, стандардната адреса и
     * онака би била порталот и тестот не би докажал ништо.
     */
    public function test_redirect_intended_wins_over_the_default_landing(): void
    {
        config(['apps.domains.prodazba' => 'localhost']);

        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $intended = route('companies.profile', $company);
        $default = LandingUrl::for($client, PortalApp::PRODAZBA);

        $this->assertNotSame(
            $intended,
            $default,
            'Тестот бара навистина различни адреси, инаку не докажува ништо.'
        );

        session(['url.intended' => $intended]);

        Volt::test('pages.auth.login')
            ->set('form.email', $client->email)
            ->set('form.password', 'password')
            ->call('login')
            ->assertRedirect($intended);
    }
}
