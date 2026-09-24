<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SidebarAppBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
    }

    public function test_the_sidebar_names_the_app_it_is_in(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('payroll-runs.index', $company));

        $response->assertOk();
        $response->assertSee('ПЛАТИ');
        $response->assertSee('ТАМИ - FinanceBuddy App');
    }

    public function test_the_portal_keeps_the_plain_name(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('companies.dashboard', $company));

        $response->assertOk();
        $response->assertSee('ТАМИ');
        $response->assertSee('FinanceBuddy App');
        // Порталот не се претставува како некоја од трите апликации.
        $response->assertDontSee('ПЛАТИ');
        $response->assertDontSee('ПРОДАЖБА');
    }

    public function test_the_accountant_menu_has_no_office_wide_worklist_links(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('accountant');
        $company->accountants()->attach($admin);

        $this->actingAs($admin)->get(route('sales-invoices.index', $company))->assertDontSee('743 обрасци');
        $this->actingAs($admin)->get(route('companies.dashboard', $company))->assertDontSee('743 обрасци')->assertDontSee('е-Фактури на чекање');
    }
}
