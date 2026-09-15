<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\PortalApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AppDomainRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_sales_invoices_live_on_the_prodazba_host(): void
    {
        $company = Company::factory()->create();

        $this->assertStringStartsWith(
            'http://'.PortalApp::PRODAZBA->domain(),
            route('sales-invoices.index', $company)
        );
    }

    public function test_the_ledger_lives_on_the_finansii_host(): void
    {
        $company = Company::factory()->create();

        $this->assertStringStartsWith(
            'http://'.PortalApp::FINANSII->domain(),
            route('accounting.journal-groups.index', $company)
        );
    }

    public function test_payroll_lives_on_the_plata_host(): void
    {
        $company = Company::factory()->create();

        $this->assertStringStartsWith(
            'http://'.PortalApp::PLATA->domain(),
            route('payroll-runs.index', $company)
        );
    }

    public function test_company_settings_stay_on_the_portal(): void
    {
        $company = Company::factory()->create();

        $this->assertStringStartsWith(
            'http://'.PortalApp::PORTAL->domain(),
            route('companies.profile', $company)
        );
    }

    public function test_an_app_screen_is_not_served_by_the_portal_host(): void
    {
        $company = Company::factory()->create();

        $response = $this->actingAs($this->admin())
            ->get('http://'.PortalApp::PORTAL->domain()."/companies/{$company->id}/sales-invoices");

        $response->assertNotFound();
    }

    public function test_the_login_form_is_served_by_every_host(): void
    {
        foreach (PortalApp::cases() as $app) {
            $this->get('http://'.$app->domain().'/login')->assertOk();
        }
    }
}
