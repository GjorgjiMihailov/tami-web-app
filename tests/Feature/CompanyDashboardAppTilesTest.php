<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyDashboardAppTilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Овој репозиториум бара улогата да постои пред да се додели —
        // Role::findOrCreate('admin') во setUp() е конвенцијата (види
        // CompanyModulesTest, AppSwitcherTest). Тестот од спецификацијата
        // не го содржи ова експлицитно, но без него assignRole('admin')
        // паѓа со RoleDoesNotExist пред воопшто да стигне до плочките.
        Role::findOrCreate('admin');
    }

    public function test_the_dashboard_shows_a_tile_for_every_open_app(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('companies.dashboard', $company));

        $response->assertOk();
        $response->assertSee('Продажба');
        $response->assertSee('Финансии');
        $response->assertSee('Плата');
    }

    public function test_a_switched_off_module_has_no_tile(): void
    {
        $company = Company::factory()->create(['uses_payroll' => false]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('companies.dashboard', $company));

        $response->assertOk();
        // Се бара адресата на плочката, не зборот „Плата" — тој збор се
        // појавува и на друго место на таблата.
        $response->assertDontSee(route('employees.index', $company));
    }

    /**
     * Погоре двата тестови од спецификацијата поминуваат и без плочките: панелот
     * „АПЛИКАЦИИ" (Задача 6) веќе ги испишува истите ознаки и адреси секаде во
     * layout-от, па сами по себе не докажуваат дека плочките на врвот на таблата
     * постојат. Овој тест го бара специфичниот текст на плочката, кој панелот
     * не го содржи, онолку пати колку што има отворени апликации.
     */
    public function test_the_tile_hint_text_appears_once_per_open_app(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('companies.dashboard', $company));

        $response->assertOk();
        $this->assertSame(
            3,
            substr_count($response->getContent(), 'Отвори ја апликацијата'),
            'Очекувани се точно 3 плочки (Продажба, Финансии, Плата) на врвот на таблата.'
        );
    }
}
