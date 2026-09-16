<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\PortalApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Насловот на прозорецот го кажува името на апликацијата на која си. Низите ги
 * диктира сопственикот и се проверуваат буквално — не се составуваат од делови.
 */
class AppTitleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');
    }

    public function test_every_app_has_the_title_the_owner_asked_for(): void
    {
        $this->assertSame('ТАМИ - FinanceBuddy App', PortalApp::PORTAL->title());
        $this->assertSame('Продажба | ТАМИ', PortalApp::PRODAZBA->title());
        $this->assertSame('Финансии | ТАМИ', PortalApp::FINANSII->title());
        $this->assertSame('Плати | ТАМИ', PortalApp::PLATA->title());
    }

    public function test_a_screen_carries_the_title_of_its_own_app(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('payroll-runs.index', $company))
            ->assertSee('<title>Плати | ТАМИ</title>', false);

        $this->actingAs($admin)
            ->get(route('companies.dashboard', $company))
            ->assertSee('<title>ТАМИ - FinanceBuddy App</title>', false);
    }

    public function test_the_login_form_carries_the_title_of_the_host_it_is_on(): void
    {
        $this->get('http://'.PortalApp::FINANSII->domain().'/login')
            ->assertSee('<title>Финансии | ТАМИ</title>', false);
    }
}
