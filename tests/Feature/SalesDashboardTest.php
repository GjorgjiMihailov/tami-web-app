<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\PortalApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_the_board_lives_on_the_prodazba_host(): void
    {
        $company = Company::factory()->create();

        $this->assertStringStartsWith(
            'http://'.PortalApp::PRODAZBA->domain(),
            route('prodazba.dashboard', $company)
        );
    }

    public function test_the_board_opens_and_names_the_company(): void
    {
        $company = Company::factory()->create(['name' => 'ТЕСТ ДООЕЛ']);

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertOk()
            ->assertSee('ТЕСТ ДООЕЛ');
    }

    public function test_no_blade_component_is_left_uncompiled(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertDontSee('<x-', false);
    }

    public function test_the_board_survives_material_being_switched_off(): void
    {
        // Кооперанти немаат модул, па таблата мора да се отвори и кога
        // Материјално е исклучено. Затоа рутата НЕ носи EnsureCompanyModule.
        $company = Company::factory()->create(['uses_material' => false]);

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertOk();
    }

    public function test_a_stranger_may_not_open_someone_elses_board(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $mine->id]);
        $client->assignRole('client');

        $this->actingAs($client)
            ->get(route('prodazba.dashboard', $theirs))
            ->assertForbidden();
    }

    public function test_the_route_requires_authentication(): void
    {
        $company = Company::factory()->create();

        $this->get(route('prodazba.dashboard', $company))->assertRedirect();
    }

    public function test_the_sidebar_carries_a_link_to_the_board(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('sales-invoices.index', $company))
            ->assertOk()
            ->assertSee(route('prodazba.dashboard', $company), false)
            ->assertSee('Табла');
    }

    public function test_other_apps_have_no_board_link_in_their_sidebar(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('accounting.journal-groups.index', $company))
            ->assertOk()
            ->assertDontSee(route('prodazba.dashboard', $company), false);
    }
}
