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

    /**
     * Extracts the Sidebar Livewire component's wire:snapshot from a full page's HTML,
     * decoded exactly as the browser would before posting it back to /livewire/update.
     */
    private function extractSidebarSnapshot(string $html): string
    {
        preg_match('/wire:snapshot="(.*?)" wire:effects="\[\]" wire:id="[a-zA-Z0-9]+" class="w-60/', $html, $matches);

        return htmlspecialchars_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE);
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
            ->assertSee('Движење на залиха')
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

    public function test_toggling_a_module_via_livewire_still_shows_the_company_after_the_request(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        // First request: a real full page load, exactly like a user visiting a company page.
        // The Sidebar component mounts here with a real 'company' route parameter bound.
        $html = $this->get(route('accounting.accounts.index', $company))->getContent();
        $snapshot = $this->extractSidebarSnapshot($html);

        // Second request: the real /livewire/update AJAX call the browser sends when a
        // sidebar toggle button is clicked, replaying the Sidebar component's own snapshot.
        // This exercises the actual request boundary a click crosses in production —
        // unlike Livewire::test(), which only ever mounts against a synthetic dummy route.
        $response = $this->withHeaders(['X-Livewire' => 'true'])
            ->postJson(app('livewire')->getUpdateUri(), [
                'components' => [[
                    'snapshot' => $snapshot,
                    'calls' => [['path' => '', 'method' => 'toggleModule', 'params' => ['inventory']]],
                    'updates' => [],
                ]],
            ]);

        $response->assertOk();
        $updatedHtml = $response->json('components.0.effects.html');

        $this->assertStringContainsString(route('inventory.items.index', $company), $updatedHtml);
        $this->assertStringContainsString(route('documents.index', $company), $updatedHtml);
    }
}
