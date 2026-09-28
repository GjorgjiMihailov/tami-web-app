<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\WorkingYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Горната бела лента (resources/views/livewire/layout/navigation.blade.php)
 * секогаш прикажува модул · фирма · година кога има фирма во контекст —
 * порано ова стоеше само во темната странична лента, невидливо додека таа
 * е затворена на тесен екран, и не наведнато на секоја страница.
 */
class NavigationModuleBreadcrumbTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_it_shows_the_module_company_and_working_year_when_a_company_is_in_context(): void
    {
        $company = Company::factory()->create(['name' => 'ТЈ ПРОСПОРТС ДООЕЛ Скопје']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('sales-invoices.index', $company));

        $response->assertOk();
        $response->assertSee('ТЈ ПРОСПОРТС ДООЕЛ Скопје');
        $response->assertSee((string) WorkingYear::for($company->fresh()));
    }

    public function test_it_renders_without_error_when_there_is_no_company_in_context(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();
    }
}
