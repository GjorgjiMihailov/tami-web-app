<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SidebarTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_it_shows_no_module_links_when_no_company_is_selected(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Сметководство')
            ->assertDontSee('Магацин');
    }

    public function test_it_shows_module_links_scoped_to_the_current_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('accounting.accounts.index', $company))
            ->assertOk()
            ->assertSee('Сметководство')
            ->assertSee(route('inventory.warehouses.index', $company), false)
            ->assertSee(route('sales-invoices.index', $company), false)
            ->assertSee(route('documents.index', $company), false)
            ->assertSee(route('reports.ddv04', $company), false);
    }
}
