<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\PortalApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Голата адреса на апликација (без патека) мора да води некаде. Јавната влезна
 * страна живее само на порталот, па без сопствена рута коренот на секој од
 * трите субдомејни враќаше 404 — првото нешто што го гледа човек што ја
 * напишал адресата в рака.
 */
class AppRootUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'internal_client'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_a_guest_is_sent_to_the_login_form_on_the_same_host(): void
    {
        foreach (PortalApp::workApps() as $app) {
            $response = $this->get('http://'.$app->domain().'/');

            $response->assertRedirect();
            $this->assertStringStartsWith(
                'http://'.$app->domain(),
                $response->headers->get('Location'),
                "Ненајавен човек на {$app->value} мора да остане на истиот хост."
            );
        }
    }

    public function test_a_client_lands_on_the_board_of_that_app(): void
    {
        // Порано ова слетуваше право во Излезни фактури. Продажба сега има
        // своја табла и таа е влезот — види Menu::landingUrl().
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        $this->actingAs($client)
            ->get('http://'.PortalApp::PRODAZBA->domain().'/')
            ->assertRedirect(route('prodazba.dashboard', $company));
    }

    public function test_an_app_without_a_board_still_lands_on_its_first_screen(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $this->actingAs($accountant)
            ->get('http://'.PortalApp::FINANSII->domain().'/')
            ->assertRedirect(route('accounting.journal-groups.index', $company));
    }

    public function test_the_portal_root_still_shows_the_public_page(): void
    {
        $this->get('http://'.PortalApp::PORTAL->domain().'/')->assertOk();
    }
}
