<?php

namespace Tests\Feature;

use App\Livewire\Layout\Sidebar;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\WorkingYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SidebarTest extends TestCase
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

    public function test_it_shows_no_groups_when_no_company_is_selected(): void
    {
        $this->actingAs($this->admin());

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('ФИНАНСИИ')
            ->assertDontSee('ЗАЛИХА');
    }

    public function test_an_admin_sees_every_group_heading(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        $this->get(route('accounting.journal-entries.index', $company))
            ->assertOk()
            ->assertSee('ФИНАНСИИ')
            ->assertSee('ПРОДАЖБА')
            ->assertSee('ЗАЛИХА')
            ->assertSee('ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ')
            ->assertSee('ПОСТАВКИ');
    }

    public function test_a_client_sees_neither_finance_nor_the_admin_only_links(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        $this->get(route('sales-invoices.index', $company))
            ->assertOk()
            ->assertSee('ПРОДАЖБА')
            ->assertDontSee('ФИНАНСИИ')
            ->assertDontSee('ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ')
            // Matched as a complete href: route('companies.index') is "/companies",
            // which is a prefix of every company-scoped URL on the page, so a bare
            // substring check can never pass.
            ->assertDontSeeHtml('href="'.route('companies.index').'"')
            ->assertDontSeeHtml(route('efaktura.access-requests'));
    }

    public function test_the_group_matching_the_current_route_auto_expands(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        $this->get(route('accounting.accounts.index', $company))
            ->assertOk()
            ->assertSeeHtml(route('companies.dashboard', $company))
            ->assertDontSeeHtml(route('inventory.warehouses.index', $company));
    }

    public function test_clicking_a_different_group_collapses_the_previous_one(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        Livewire::test(Sidebar::class, ['company' => $company])
            ->call('toggleGroup', 'finance')
            ->assertSet('expandedGroup', 'finance')
            ->call('toggleGroup', 'stock')
            ->assertSet('expandedGroup', 'stock')
            ->call('toggleGroup', 'stock')
            ->assertSet('expandedGroup', null);
    }

    public function test_the_stock_group_is_flat_with_no_third_level(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        $this->get(route('inventory.warehouses.index', $company))
            ->assertOk()
            ->assertSee('Прием')
            ->assertSee('Пренос')
            ->assertDontSee('Движење на залиха')
            ->assertDontSee('Корекција');
    }

    public function test_partners_are_labelled_kooperanti(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        $this->get(route('sales-invoices.index', $company))
            ->assertOk()
            ->assertSee('Кооперанти')
            ->assertSeeHtml(route('partners.index', $company));
    }

    public function test_the_two_reports_the_menu_dropped_are_reachable_from_the_stock_page(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        // Not in the target menu any more — the Состојба page carries them.
        $this->get(route('inventory.reports.stock-on-hand', $company))
            ->assertOk()
            ->assertSeeHtml(route('inventory.reports.item-movement-card', $company))
            ->assertSeeHtml(route('inventory.reports.stock-valuation', $company));
    }

    public function test_the_stock_reports_are_no_longer_menu_entries(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        $html = $this->get(route('inventory.warehouses.index', $company))->getContent();
        $sidebar = substr($html, 0, strpos($html, '</nav>'));

        $this->assertStringNotContainsString('Картица на движење', $sidebar);
        $this->assertStringNotContainsString('Вреднување на залихи', $sidebar);
    }

    public function test_documents_stands_alone_outside_the_groups(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        $this->get(route('inventory.warehouses.index', $company))
            ->assertOk()
            ->assertSeeHtml(route('documents.index', $company));
    }

    public function test_the_company_selector_lists_only_visible_companies(): void
    {
        $mine = Company::factory()->create(['name' => 'Моја Фирма']);
        $other = Company::factory()->create(['name' => 'Туѓа Фирма']);
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $mine->accountants()->attach($accountant);

        $this->actingAs($accountant);

        Livewire::test(Sidebar::class, ['company' => $mine])
            ->assertSee('Моја Фирма')
            ->assertDontSee('Туѓа Фирма');
    }

    public function test_opening_a_company_remembers_it_for_next_time(): void
    {
        $company = Company::factory()->create();
        $admin = $this->admin();
        $this->actingAs($admin);

        Livewire::test(Sidebar::class, ['company' => $company]);

        $this->assertSame($company->id, CurrentCompany::lastFor($admin));
    }

    public function test_the_sidebar_shows_a_year_selector_when_a_company_is_open(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        $this->get(route('accounting.journal-entries.index', $company))
            ->assertOk()
            ->assertSee('Година')
            ->assertSee((string) now()->year);
    }

    public function test_there_is_no_year_selector_without_a_company(): void
    {
        $this->actingAs($this->admin());

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Година');
    }

    public function test_changing_the_year_stores_it_and_announces_it(): void
    {
        $company = Company::factory()->create();
        $user = $this->admin();
        $this->actingAs($user);

        // Last year only becomes selectable once the company has data in it.
        JournalEntry::factory()->for($company)->create([
            'entry_date' => now()->subYear()->startOfYear()->toDateString(),
        ]);

        Livewire::test(Sidebar::class, ['company' => $company])
            ->assertSet('workingYear', (int) now()->year)
            ->set('workingYear', (int) now()->year - 1)
            ->assertDispatched('working-year-changed', year: (int) now()->year - 1);

        $this->assertSame(
            (int) now()->year - 1,
            session(WorkingYear::sessionKey($user->id, $company->id))
        );
    }

    public function test_toggling_a_group_via_livewire_still_shows_the_company_after_the_request(): void
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
                    'calls' => [['path' => '', 'method' => 'toggleGroup', 'params' => ['stock']]],
                    'updates' => [],
                ]],
            ]);

        $response->assertOk();
        $updatedHtml = $response->json('components.0.effects.html');

        $this->assertStringContainsString(route('inventory.items.index', $company), $updatedHtml);
        $this->assertStringContainsString(route('documents.index', $company), $updatedHtml);
    }
}
