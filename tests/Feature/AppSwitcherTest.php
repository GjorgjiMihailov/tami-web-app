<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\AppSwitcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AppSwitcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_an_admin_sees_all_three_apps(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertSame(
            ['prodazba', 'finansii', 'plata'],
            array_column(AppSwitcher::for($admin, $company), 'key')
        );
    }

    public function test_an_unticked_app_is_absent_not_greyed(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id, 'app_plata' => false]);
        $user->assignRole('client');

        $this->assertSame(
            ['prodazba'],
            array_column(AppSwitcher::for($user, $company), 'key'),
            'Клиент не гледа Финансии (книжењето е на канцеларијата) ниту исклучена Плата.'
        );
    }

    public function test_an_app_without_screens_for_this_company_is_absent(): void
    {
        $company = Company::factory()->create(['uses_payroll' => false]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertSame(
            ['prodazba', 'finansii'],
            array_column(AppSwitcher::for($admin, $company), 'key')
        );
    }

    public function test_without_a_company_the_list_is_empty(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertSame([], AppSwitcher::for($admin, null));
    }

    /**
     * The four tests above prove AppSwitcher::for()'s logic in isolation.
     * These two render an actual page through HTTP so the markup itself —
     * the button and the panel's contents — is proven without a browser,
     * per the task's verification note.
     */
    public function test_the_button_and_panel_render_on_a_real_page_for_an_admin(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('sales-invoices.index', $company))
            ->assertOk()
            ->assertSee('АПЛИКАЦИИ')
            ->assertSee('Продажба')
            ->assertSee('Финансии')
            ->assertSee('Плата');
    }

    public function test_the_panel_omits_an_app_the_client_may_not_open_on_a_real_page(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id, 'app_plata' => false]);
        $client->assignRole('client');
        $this->actingAs($client);

        $this->get(route('sales-invoices.index', $company))
            ->assertOk()
            ->assertSee('АПЛИКАЦИИ')
            ->assertSee('Продажба')
            ->assertDontSee('Финансии')
            ->assertDontSee('Плата');
    }
}
