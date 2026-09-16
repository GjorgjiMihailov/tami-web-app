<?php

namespace Tests\Feature;

use App\Livewire\Layout\Sidebar;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\CompanyType;
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

    /**
     * Isolates the sidebar's own rendered HTML from the rest of the page.
     *
     * The Task 6 app-switcher panel (resources/views/livewire/layout/navigation.blade.php)
     * legitimately links to every app's first screen from the page header, so
     * a page-wide href search is no longer proof of what the sidebar itself
     * expanded or highlighted — it might just be the switcher. Scoping to the
     * sidebar's own markup, between its wrapper div and the mobile-drawer
     * backdrop that immediately follows it in layouts/app.blade.php, restores
     * that proof.
     *
     * Task 9 added the same problem one level down: the sidebar's own brand
     * link at the top now also points at the app's first screen
     * (App\Livewire\Layout\Sidebar::$brandUrl), so it can legitimately carry
     * a route that belongs to a group other than the one the current page
     * auto-expanded. Starting the capture at <nav ...> instead of the
     * wrapper div excludes that header link while keeping the whole menu.
     */
    private function extractSidebarHtml(string $html): string
    {
        $start = strpos($html, '<nav class="flex-1');
        $end = strpos($html, 'x-show="sidebarOpen" x-cloak x-transition.opacity', $start);

        return substr($html, $start, $end - $start);
    }

    public function test_it_shows_no_groups_when_no_company_is_selected(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('ФИНАНСИИ')
            ->assertDontSee('ЗАЛИХА');
    }

    // Every app's menu now shows only its own groups — ФИНАНСИИ, ПРОДАЖБА,
    // ЗАЛИХА and ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ each live on their own subdomain,
    // so no single page shows all of them together any more.
    public function test_an_admin_sees_every_group_heading_for_its_own_app(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        $this->get(route('sales-invoices.index', $company))
            ->assertOk()
            ->assertSee('ПРОДАЖБА')
            ->assertSee('ТРОШОЦИ')
            ->assertSee('ЗАЛИХА')
            ->assertDontSee('ФИНАНСИИ')
            ->assertDontSee('ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ');

        $this->get(route('accounting.journal-entries.index', $company))
            ->assertOk()
            ->assertSee('ФИНАНСИИ')
            ->assertDontSee('ПРОДАЖБА')
            ->assertDontSee('ЗАЛИХА')
            ->assertDontSee('ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ');

        $this->get(route('payroll-runs.index', $company))
            ->assertOk()
            ->assertSee('ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ')
            ->assertDontSee('ФИНАНСИИ')
            ->assertDontSee('ПРОДАЖБА');
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
            // Matched as a complete href: route('companies.index') is "/companies",
            // which is a prefix of every company-scoped URL on the page, so a bare
            // substring check can never pass.
            ->assertDontSeeHtml('href="'.route('companies.index').'"')
            ->assertDontSeeHtml(route('efaktura.access-requests'));

        // ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ moved to the plata app. Вработени is built,
        // so a client sees the group there, just not the two still-unbuilt
        // entries inside it.
        $this->get(route('employees.index', $company))
            ->assertOk()
            ->assertSee('ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ')
            ->assertDontSee('Плата (МПИН)')
            ->assertDontSee('е-ПДД');
    }

    public function test_the_group_matching_the_current_route_auto_expands(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        // Контен план moved out of the single ПОСТАВКИ group into its own
        // finance-settings group, which lives alongside ФИНАНСИИ. Компанија
        // is no longer in this app's menu at all — it now belongs to portal —
        // so the item proving the RIGHT group expanded is the current page's
        // own link, not Компанија.
        $response = $this->get(route('accounting.accounts.index', $company));
        $response->assertOk();

        // Откако лизгањето е во прелистувачот, ставките на СИТЕ групи се во
        // HTML (скриени со x-show), па отворената група се чита од почетната
        // состојба на Alpine, не од отсуството на врските на другите групи.
        $sidebar = $this->extractSidebarHtml($response->getContent());
        $this->assertStringContainsString(route('accounting.accounts.index', $company), $sidebar);
        $this->assertStringContainsString('data-group="finance-settings" x-data="{ open: true }"', $sidebar);
        $this->assertStringContainsString('data-group="finance" x-data="{ open: false }"', $sidebar);
    }

    /**
     * Regression for a route-namespace bug: `Фактурирање` lived under
     * `invoice-settings.*`, and `groupMatchingCurrentRoute()` returns the
     * FIRST matching group key. ПРОДАЖБА's `Излезни фактури` item used the
     * wildcard pattern `sales-invoices.*` and ПРОДАЖБА is listed before
     * ПОСТАВКИ, so if the settings route ever shares that namespace again,
     * ПРОДАЖБА wins the highlight instead of ПОСТАВКИ — the group the user
     * actually just opened silently loses its expanded/highlighted state.
     */
    public function test_visiting_invoice_settings_expands_the_settings_group_not_sales(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        $response = $this->get(route('invoice-settings.index', $company));
        $response->assertOk();

        $sidebar = $this->extractSidebarHtml($response->getContent());
        $this->assertStringContainsString(route('invoice-settings.index', $company), $sidebar);
        $this->assertStringContainsString('data-group="sales-settings" x-data="{ open: true }"', $sidebar);
        $this->assertStringContainsString('data-group="sales" x-data="{ open: false }"', $sidebar);
    }

    /**
     * Отворањето и затворањето на групите е во прелистувачот (Alpine) од
     * 2026-09-16, па серверот повеќе не го менува. Она што серверот сè уште
     * го прави е да ја одреди групата што е отворена при влегување.
     */
    public function test_the_group_of_the_current_screen_starts_open(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        Livewire::test(Sidebar::class, ['company' => $company])
            ->assertSet('expandedGroup', null);

        $this->get(route('inventory.items.index', $company))
            ->assertSee('data-group="stock" x-data="{ open: true }"', false);
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

    /**
     * Документи is absent from the spec's individual menu table — an
     * individual profile has no bookkeeping documents to file. Without this,
     * a future edit could reintroduce the link for individuals and the rest
     * of the suite would stay green, since every other Документи assertion
     * above uses the factory's default legal company.
     */
    public function test_documents_is_hidden_for_an_individual_but_shown_for_a_legal_entity(): void
    {
        $individual = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
        $legal = Company::factory()->create(['type' => CompanyType::LEGAL]);
        $this->actingAs($this->admin());

        $this->get(route('sales-invoices.index', $individual))
            ->assertOk()
            ->assertDontSeeHtml(route('documents.index', $individual));

        $this->get(route('sales-invoices.index', $legal))
            ->assertOk()
            ->assertSeeHtml(route('documents.index', $legal));
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

    public function test_the_company_selector_cannot_overflow_the_rail(): void
    {
        $company = Company::factory()->create(['name' => 'ФАЈНЕНС БАДИ ДООЕЛ СКОПЈЕ']);
        $this->actingAs($this->admin());

        // A <select> is as wide as its longest <option>, so without w-full +
        // min-w-0 a real company name pushes it straight out of the 240px rail.
        Livewire::test(Sidebar::class, ['company' => $company])
            ->assertSee('ФАЈНЕНС БАДИ ДООЕЛ СКОПЈЕ')
            ->assertSee('block w-full min-w-0 truncate', false);
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

        $this->get(route('dashboard'))
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

    public function test_choosing_a_working_year_via_livewire_still_shows_the_company_after_the_request(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        // First request: a real full page load, exactly like a user visiting a company page.
        // The Sidebar component mounts here with a real 'company' route parameter bound.
        // Uses a prodazba-app page so that the /livewire/update POST below lands on the
        // same host — the sidebar's menu depends on which app it is rendered for.
        $html = $this->get(route('sales-invoices.index', $company))->getContent();
        $snapshot = $this->extractSidebarSnapshot($html);

        // Second request: the real /livewire/update AJAX call the browser sends when the
        // working year is chosen, replaying the Sidebar component's own snapshot. This
        // exercises the actual request boundary a click crosses in production — unlike
        // Livewire::test(), which only ever mounts against a synthetic dummy route.
        // The defect this guards: the company was lost across that boundary, so every
        // link in the sidebar came back broken. Group toggling used to cross it too;
        // since that moved into the browser, the working year is what remains.
        $response = $this->withHeaders(['X-Livewire' => 'true'])
            ->postJson(app('livewire')->getUpdateUri(), [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => ['workingYear' => (string) now()->year],
                    'calls' => [],
                ]],
            ]);

        $response->assertOk();
        $updatedHtml = $response->json('components.0.effects.html');

        $this->assertStringContainsString(route('inventory.items.index', $company), $updatedHtml);
        $this->assertStringContainsString(route('documents.index', $company), $updatedHtml);
    }
}
