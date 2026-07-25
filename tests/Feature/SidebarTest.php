<?php

namespace Tests\Feature;

use App\Livewire\Layout\Sidebar;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_it_shows_no_module_links_when_no_company_is_selected(): void
    {
        $this->actingAs($this->admin());

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Сметководство')
            ->assertDontSee('Магацин');
    }

    public function test_the_module_matching_the_current_route_auto_expands(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        $this->get(route('accounting.accounts.index', $company))
            ->assertOk()
            ->assertSee('Сметководство')
            ->assertSeeHtml(route('accounting.journal-entries.index', $company))
            ->assertSeeHtml(route('accounting.reports.ledger-card', $company))
            ->assertSeeHtml(route('accounting.reports.trial-balance', $company))
            ->assertDontSeeHtml(route('inventory.warehouses.index', $company));
    }

    public function test_documents_and_reports_stay_single_links_with_no_submenu(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        $this->get(route('accounting.accounts.index', $company))
            ->assertOk()
            ->assertSeeHtml(route('documents.index', $company))
            ->assertSeeHtml(route('reports.ddv04', $company));
    }

    public function test_clicking_a_different_module_collapses_the_previous_one(): void
    {
        Livewire::test(Sidebar::class)
            ->call('toggleModule', 'accounting')
            ->assertSet('expandedModule', 'accounting')
            ->call('toggleModule', 'inventory')
            ->assertSet('expandedModule', 'inventory');
    }

    public function test_clicking_the_open_module_again_collapses_it(): void
    {
        Livewire::test(Sidebar::class)
            ->call('toggleModule', 'accounting')
            ->assertSet('expandedModule', 'accounting')
            ->call('toggleModule', 'accounting')
            ->assertSet('expandedModule', null);
    }

    public function test_record_movement_nests_under_inventory_and_auto_expands_on_its_route(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        $this->get(route('inventory.stock-movements.create', [$company, 'receipt']))
            ->assertOk()
            ->assertSee('Record Movement')
            ->assertSeeHtml(route('inventory.stock-movements.create', [$company, 'issue']))
            ->assertSeeHtml(route('inventory.stock-movements.create', [$company, 'transfer']))
            ->assertSeeHtml(route('inventory.stock-movements.create', [$company, 'adjustment']));
    }

    public function test_invoicing_submenu_expands_for_partners_and_invoice_routes(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        $this->get(route('partners.index', $company))
            ->assertOk()
            ->assertSeeHtml(route('sales-invoices.index', $company))
            ->assertSeeHtml(route('sales-invoices.create', $company))
            ->assertSeeHtml(route('purchase-invoices.index', $company))
            ->assertSeeHtml(route('purchase-invoices.create', $company));
    }
}
