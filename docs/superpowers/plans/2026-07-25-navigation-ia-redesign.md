# Navigation/IA Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the app a real navigation structure: a mandatory company-picker on Dashboard, a proper per-company "home base" dashboard, expandable sidebar submenus per module, and a Companies page that's just company management.

**Architecture:** No new session state. A new route `companies/{company}/dashboard` becomes the per-company home base — landing there makes the existing `Sidebar` component (which already reads `request()->route('company')`) show that company's expanded modules for free. The existing company-less `/dashboard` route always shows a mandatory company-picker popup, so clicking "Dashboard" in the sidebar doubles as a company-switcher.

**Tech Stack:** Laravel 11, Livewire 3 (full-page components via `#[Layout('layouts.app')]`), Blade, Tailwind, PHPUnit + `RefreshDatabase`, Spatie `laravel-permission` roles.

## Global Constraints

- Test framework is PHPUnit (not Pest) — one test class per file extending `Tests\TestCase`, `use RefreshDatabase;`, roles created via `Role::findOrCreate('admin')` etc. in `setUp()`.
- Company-scoped Livewire full-page components authorize via `Gate::authorize('view', $company)` inside `mount(Company $company)` (see `app/Livewire/Accounting/AccountIndex.php:19-22` for the exact pattern) — reuse this, do not invent a new authorization mechanism.
- Full-page Livewire components use the `#[Layout('layouts.app')]` attribute (not a `<x-app-layout>` Blade component — that component no longer exists in this codebase).
- Internal navigation links use `wire:navigate`.
- Existing top-level module labels are Macedonian and must not change: Сметководство (Accounting), Магацин (Inventory), Фактури (Invoicing), Документи (Documents), Извештаи (Reports). New sidebar sub-links use the English names from the approved spec (Accounts, Journal, Ledger Card, Trial Balance, Warehouses, Items, Stock On Hand, Item Movement Card, Stock Valuation, Record Movement, Receipt, Issue, Transfer, Adjustment, Partners, Sales Invoices, New Invoice, Purchase Invoices, New Purchase Invoice).
- The company picker popup is mandatory — no close button, no click-outside-to-dismiss, no Escape-key handler. Do not reuse `resources/views/components/modal.blade.php` (it has all three).
- Tailwind classes should match the existing sidebar/card look (`bg-gray-800` sidebar, `bg-brand` active state, `rounded-2xl shadow-sm` cards).

---

## Task 1: Company dashboard ("home base") route + component

**Files:**
- Create: `app/Livewire/CompanyDashboard.php`
- Create: `resources/views/livewire/company-dashboard.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/CompanyDashboardTest.php`

**Interfaces:**
- Consumes: `App\Models\Company` (existing), `CompanyPolicy::view` gate (existing, `app/Policies/CompanyPolicy.php`).
- Produces: named route `companies.dashboard` (accepts a `Company` route-model-bound parameter), used by Task 2's picker links.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Livewire\CompanyDashboard;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_shows_the_active_companys_name(): void
    {
        $company = Company::factory()->create(['name' => 'Alpha Ltd']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->assertSee('Alpha Ltd');
    }

    public function test_it_links_to_each_module_for_the_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->assertSeeHtml(route('accounting.accounts.index', $company))
            ->assertSeeHtml(route('inventory.warehouses.index', $company))
            ->assertSeeHtml(route('sales-invoices.index', $company))
            ->assertSeeHtml(route('documents.index', $company))
            ->assertSeeHtml(route('reports.ddv04', $company));
    }

    public function test_a_user_without_access_to_the_company_is_forbidden(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $otherCompany->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->assertForbidden();
    }

    public function test_the_route_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create(['name' => 'Alpha Ltd']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('companies.dashboard', $company))
            ->assertOk()
            ->assertSee('Alpha Ltd');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=CompanyDashboardTest`
Expected: FAIL — `App\Livewire\CompanyDashboard` class not found / route `companies.dashboard` not defined.

- [ ] **Step 3: Create the Livewire component**

Create `app/Livewire/CompanyDashboard.php`:

```php
<?php

namespace App\Livewire;

use App\Models\Company;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CompanyDashboard extends Component
{
    public Company $company;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        return view('livewire.company-dashboard');
    }
}
```

- [ ] **Step 4: Create the view**

Create `resources/views/livewire/company-dashboard.blade.php`:

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">Working on: {{ $company->name }}</h1>
    <p class="text-sm text-gray-500 mb-6">Pick a module below to get started.</p>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <a href="{{ route('accounting.accounts.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Сметководство</h2>
                <p class="text-sm text-gray-500 mt-1">Accounts, journal, ledger, trial balance</p>
            </x-card>
        </a>
        <a href="{{ route('inventory.warehouses.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Магацин</h2>
                <p class="text-sm text-gray-500 mt-1">Warehouses, items, stock reports</p>
            </x-card>
        </a>
        <a href="{{ route('sales-invoices.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Фактури</h2>
                <p class="text-sm text-gray-500 mt-1">Partners, sales and purchase invoices</p>
            </x-card>
        </a>
        <a href="{{ route('documents.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Документи</h2>
                <p class="text-sm text-gray-500 mt-1">Uploaded and generated documents</p>
            </x-card>
        </a>
        <a href="{{ route('reports.ddv04', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Извештаи</h2>
                <p class="text-sm text-gray-500 mt-1">Statutory reports</p>
            </x-card>
        </a>
    </div>
</div>
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, add the import (keep the alphabetical grouping — insert right before the existing `use App\Livewire\CompanyIndex;` line since "Dashboard" < "Index"):

```php
use App\Livewire\CompanyDashboard;
```

Then add a new route group, placed directly after the existing `companies.index` route group:

```php
Route::middleware(['auth'])->prefix('companies/{company}')->group(function () {
    Route::get('/dashboard', [CompanyDashboard::class, '__invoke'])->name('companies.dashboard');
});
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=CompanyDashboardTest`
Expected: PASS (4 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/CompanyDashboard.php resources/views/livewire/company-dashboard.blade.php routes/web.php tests/Feature/CompanyDashboardTest.php
git commit -m "feat: add per-company dashboard home base"
```

---

## Task 2: Mandatory company picker on the generic Dashboard route

**Files:**
- Create: `app/Livewire/Dashboard.php`
- Create: `resources/views/livewire/dashboard.blade.php`
- Delete: `resources/views/dashboard.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Consumes: `User::visibleCompanies(): Builder` (existing, `app/Models/User.php:47`), route `companies.dashboard` (from Task 1).
- Produces: nothing consumed by later tasks — this is a leaf.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_shows_a_company_picker_listing_every_visible_company(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Company::factory()->create(['name' => 'Alpha Ltd']);
        Company::factory()->create(['name' => 'Beta Ltd']);
        $this->actingAs($admin);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Alpha Ltd')
            ->assertSee('Beta Ltd');
    }

    public function test_the_picker_still_shows_for_a_user_with_only_one_company(): void
    {
        $company = Company::factory()->create(['name' => 'Solo Ltd']);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Solo Ltd')
            ->assertSee('Select a company');
    }

    public function test_picking_a_company_links_to_its_own_dashboard(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['name' => 'Alpha Ltd']);
        $this->actingAs($admin);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSeeHtml(route('companies.dashboard', $company));
    }

    public function test_it_does_not_show_companies_the_user_cannot_access(): void
    {
        $ownCompany = Company::factory()->create(['name' => 'Alpha Ltd']);
        $otherCompany = Company::factory()->create(['name' => 'Beta Ltd']);
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Alpha Ltd')
            ->assertDontSee('Beta Ltd');
    }

    public function test_the_popup_has_no_dismiss_control(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Company::factory()->create(['name' => 'Alpha Ltd']);
        $this->actingAs($admin);

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('×')
            ->assertDontSeeHtml('close-modal');
    }

    public function test_the_route_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=DashboardTest`
Expected: FAIL — `App\Livewire\Dashboard` class not found (route still resolves to the old plain view, so `assertSee('Alpha Ltd')` etc. fail).

- [ ] **Step 3: Create the Livewire component**

Create `app/Livewire/Dashboard.php`:

```php
<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    public function render()
    {
        $companies = auth()->user()->visibleCompanies()->orderBy('name')->get();

        return view('livewire.dashboard', ['companies' => $companies]);
    }
}
```

- [ ] **Step 4: Create the view**

Create `resources/views/livewire/dashboard.blade.php`:

```blade
<div>
    <div class="fixed inset-0 bg-gray-900/50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg p-6">
            <h1 class="text-xl font-bold text-gray-800 mb-1">Select a company</h1>
            <p class="text-sm text-gray-500 mb-4">Choose which company you want to work on.</p>

            @if ($companies->isEmpty())
                <p class="text-gray-500">You don't have access to any companies yet.</p>
            @else
                <ul class="divide-y divide-gray-200">
                    @foreach ($companies as $company)
                        <li>
                            <a href="{{ route('companies.dashboard', $company) }}" wire:navigate
                               class="block py-3 px-2 rounded-lg hover:bg-gray-50 font-medium text-gray-700">
                                {{ $company->name }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
```

- [ ] **Step 5: Replace the dashboard route and delete the orphaned view**

In `routes/web.php`, add the import (insert right after the `use App\Livewire\CompanyIndex;` line, before `use App\Livewire\DocumentIndex;`):

```php
use App\Livewire\Dashboard;
```

Replace:

```php
Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');
```

with:

```php
Route::get('dashboard', [Dashboard::class, '__invoke'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');
```

Delete the now-unused `resources/views/dashboard.blade.php`:

```bash
rm resources/views/dashboard.blade.php
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=DashboardTest`
Expected: PASS (6 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Dashboard.php resources/views/livewire/dashboard.blade.php routes/web.php tests/Feature/DashboardTest.php
git add -u resources/views/dashboard.blade.php
git commit -m "feat: show a mandatory company picker on the Dashboard route"
```

---

## Task 3: Sidebar accordion submenus per module

**Files:**
- Modify: `app/Livewire/Layout/Sidebar.php`
- Modify: `resources/views/livewire/layout/sidebar.blade.php`
- Modify: `tests/Feature/SidebarTest.php`

**Interfaces:**
- Consumes: nothing new (still reads `request()->route('company')` as before).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the failing tests (replace the file's contents entirely)**

Replace `tests/Feature/SidebarTest.php` with:

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=SidebarTest`
Expected: FAIL — `toggleModule` method doesn't exist, `expandedModule` property doesn't exist, submenu links aren't rendered.

- [ ] **Step 3: Update the Sidebar component**

Replace `app/Livewire/Layout/Sidebar.php` with:

```php
<?php

namespace App\Livewire\Layout;

use App\Models\Company;
use Livewire\Component;

class Sidebar extends Component
{
    public ?string $expandedModule = null;

    public bool $recordMovementExpanded = false;

    public function mount(): void
    {
        $this->expandedModule = $this->moduleMatchingCurrentRoute();
        $this->recordMovementExpanded = request()->routeIs('inventory.stock-movements.create');
    }

    public function toggleModule(string $module): void
    {
        $this->expandedModule = $this->expandedModule === $module ? null : $module;

        if ($this->expandedModule !== 'inventory') {
            $this->recordMovementExpanded = false;
        }
    }

    public function toggleRecordMovement(): void
    {
        $this->recordMovementExpanded = ! $this->recordMovementExpanded;
    }

    private function moduleMatchingCurrentRoute(): ?string
    {
        return match (true) {
            request()->routeIs('accounting.*') => 'accounting',
            request()->routeIs('inventory.*') => 'inventory',
            request()->routeIs('partners.*'), request()->routeIs('sales-invoices.*'), request()->routeIs('purchase-invoices.*') => 'invoicing',
            default => null,
        };
    }

    public function render()
    {
        $company = request()->route('company');

        return view('livewire.layout.sidebar', [
            'company' => $company instanceof Company ? $company : null,
        ]);
    }
}
```

- [ ] **Step 4: Update the Sidebar view**

Replace `resources/views/livewire/layout/sidebar.blade.php` with:

```blade
<div class="w-60 shrink-0 bg-gray-800 text-white flex flex-col min-h-screen">
    <div class="px-4 py-4 border-b border-gray-700">
        <a href="{{ route('dashboard') }}" wire:navigate class="font-bold text-brand text-sm">
            {{ config('app.name', 'Laravel') }}
        </a>
    </div>

    <nav class="flex-1 py-3 space-y-1">
        <a href="{{ route('dashboard') }}" wire:navigate
           class="block px-4 py-2 text-sm font-medium {{ request()->routeIs('dashboard') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
            Dashboard
        </a>
        <a href="{{ route('companies.index') }}" wire:navigate
           class="block px-4 py-2 text-sm font-medium {{ request()->routeIs('companies.*') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
            Companies
        </a>

        @if ($company)
            <div class="pt-4 mt-3 border-t border-gray-700">
                <div class="px-4 pb-2 text-xs uppercase tracking-wide text-gray-400">{{ $company->name }}</div>

                {{-- Accounting --}}
                <button type="button" wire:click="toggleModule('accounting')"
                        class="w-full text-left flex items-center justify-between px-4 py-2 text-sm font-medium {{ request()->routeIs('accounting.*') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
                    <span>Сметководство</span>
                    <span>{{ $expandedModule === 'accounting' ? '−' : '+' }}</span>
                </button>
                @if ($expandedModule === 'accounting')
                    <div class="pl-6">
                        <a href="{{ route('accounting.accounts.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('accounting.accounts.*') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Accounts</a>
                        <a href="{{ route('accounting.journal-entries.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('accounting.journal-entries.*') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Journal</a>
                        <a href="{{ route('accounting.reports.ledger-card', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('accounting.reports.ledger-card') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Ledger Card</a>
                        <a href="{{ route('accounting.reports.trial-balance', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('accounting.reports.trial-balance') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Trial Balance</a>
                    </div>
                @endif

                {{-- Inventory --}}
                <button type="button" wire:click="toggleModule('inventory')"
                        class="w-full text-left flex items-center justify-between px-4 py-2 text-sm font-medium {{ request()->routeIs('inventory.*') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
                    <span>Магацин</span>
                    <span>{{ $expandedModule === 'inventory' ? '−' : '+' }}</span>
                </button>
                @if ($expandedModule === 'inventory')
                    <div class="pl-6">
                        <a href="{{ route('inventory.warehouses.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.warehouses.*') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Warehouses</a>
                        <a href="{{ route('inventory.items.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.items.*') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Items</a>
                        <a href="{{ route('inventory.reports.stock-on-hand', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.reports.stock-on-hand') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Stock On Hand</a>
                        <a href="{{ route('inventory.reports.item-movement-card', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.reports.item-movement-card') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Item Movement Card</a>
                        <a href="{{ route('inventory.reports.stock-valuation', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.reports.stock-valuation') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Stock Valuation</a>

                        <button type="button" wire:click="toggleRecordMovement"
                                class="w-full text-left flex items-center justify-between px-4 py-1.5 text-sm {{ request()->routeIs('inventory.stock-movements.create') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">
                            <span>Record Movement</span>
                            <span>{{ $recordMovementExpanded ? '−' : '+' }}</span>
                        </button>
                        @if ($recordMovementExpanded)
                            <div class="pl-4">
                                <a href="{{ route('inventory.stock-movements.create', [$company, 'receipt']) }}" wire:navigate
                                   class="block px-4 py-1 text-sm text-gray-400 hover:text-white">Receipt</a>
                                <a href="{{ route('inventory.stock-movements.create', [$company, 'issue']) }}" wire:navigate
                                   class="block px-4 py-1 text-sm text-gray-400 hover:text-white">Issue</a>
                                <a href="{{ route('inventory.stock-movements.create', [$company, 'transfer']) }}" wire:navigate
                                   class="block px-4 py-1 text-sm text-gray-400 hover:text-white">Transfer</a>
                                <a href="{{ route('inventory.stock-movements.create', [$company, 'adjustment']) }}" wire:navigate
                                   class="block px-4 py-1 text-sm text-gray-400 hover:text-white">Adjustment</a>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Invoicing --}}
                <button type="button" wire:click="toggleModule('invoicing')"
                        class="w-full text-left flex items-center justify-between px-4 py-2 text-sm font-medium {{ (request()->routeIs('partners.*') || request()->routeIs('sales-invoices.*') || request()->routeIs('purchase-invoices.*')) ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
                    <span>Фактури</span>
                    <span>{{ $expandedModule === 'invoicing' ? '−' : '+' }}</span>
                </button>
                @if ($expandedModule === 'invoicing')
                    <div class="pl-6">
                        <a href="{{ route('partners.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('partners.*') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Partners</a>
                        <a href="{{ route('sales-invoices.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('sales-invoices.index') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Sales Invoices</a>
                        <a href="{{ route('sales-invoices.create', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('sales-invoices.create') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">New Invoice</a>
                        <a href="{{ route('purchase-invoices.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('purchase-invoices.index') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Purchase Invoices</a>
                        <a href="{{ route('purchase-invoices.create', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('purchase-invoices.create') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">New Purchase Invoice</a>
                    </div>
                @endif

                {{-- Documents (no submenu) --}}
                <a href="{{ route('documents.index', $company) }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium {{ request()->routeIs('documents.*') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
                    Документи
                </a>

                {{-- Reports (no submenu) --}}
                <a href="{{ route('reports.ddv04', $company) }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium {{ request()->routeIs('reports.*') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
                    Извештаи
                </a>
            </div>
        @endif
    </nav>
</div>
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=SidebarTest`
Expected: PASS (7 tests)

- [ ] **Step 6: Run the full test suite to check for regressions in other tests that render the sidebar**

Run: `php artisan test`
Expected: PASS. If `CompanyIndexTest` or others fail because they incidentally assert old flat single-link sidebar markup, note it — Task 4 below handles `CompanyIndexTest`'s own per-company link-list assertions; anything else failing here is an unexpected regression to investigate before continuing.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Layout/Sidebar.php resources/views/livewire/layout/sidebar.blade.php tests/Feature/SidebarTest.php
git commit -m "feat: accordion-style expandable sidebar submenus per module"
```

---

## Task 4: Shrink the Companies page to company management only

**Files:**
- Modify: `resources/views/livewire/company-index.blade.php`
- Modify: `tests/Feature/CompanyIndexTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: nothing consumed by later tasks — this is a leaf.

- [ ] **Step 1: Update the failing/obsolete tests**

In `tests/Feature/CompanyIndexTest.php`, delete these two test methods entirely (they assert the per-company module link lists that this task removes):

- `test_the_companies_list_links_to_inventory_screens_for_a_visible_company`
- `test_the_companies_list_links_to_invoicing_screens_for_a_visible_company`

Add this replacement test in their place:

```php
    public function test_the_companies_list_no_longer_shows_per_company_module_links(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['name' => 'Alpha Ltd']);

        $this->actingAs($admin);

        Livewire::test(CompanyIndex::class)
            ->assertDontSeeHtml(route('accounting.accounts.index', $company))
            ->assertDontSeeHtml(route('inventory.warehouses.index', $company))
            ->assertDontSeeHtml(route('sales-invoices.index', $company))
            ->assertDontSeeHtml(route('inventory.stock-movements.create', [$company, 'receipt']));
    }
```

- [ ] **Step 2: Run the tests to verify the new test fails**

Run: `php artisan test --filter=CompanyIndexTest`
Expected: FAIL on `test_the_companies_list_no_longer_shows_per_company_module_links` — the old view still renders those links.

- [ ] **Step 3: Update the view**

Replace `resources/views/livewire/company-index.blade.php` with:

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Companies</h1>

    @can('create', \App\Models\Company::class)
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Add company</h2>
            <form wire:submit="addCompany" class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newName" value="Name" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newTaxId" value="Tax ID" />
                    <x-text-input id="newTaxId" wire:model="newTaxId" class="w-40" />
                    @error('newTaxId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newEmail" value="Email" />
                    <x-text-input id="newEmail" wire:model="newEmail" class="w-48" />
                    @error('newEmail') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newPhone" value="Phone" />
                    <x-text-input id="newPhone" wire:model="newPhone" class="w-32" />
                    @error('newPhone') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newAddress" value="Address" />
                    <x-text-input id="newAddress" wire:model="newAddress" class="w-full" />
                    @error('newAddress') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <x-primary-button type="submit">Add company</x-primary-button>
            </form>
        </x-card>
    @endcan

    @if ($companies->isEmpty())
        <p class="text-gray-500">No companies to show.</p>
    @else
        <ul class="divide-y divide-gray-200">
            @foreach ($companies as $company)
                <li class="py-3">
                    <div class="flex items-center justify-between">
                        <span class="font-medium">{{ $company->name }}</span>
                        @can('update', $company)
                            @if ($editingCompanyId !== $company->id)
                                <button type="button" wire:click="startEdit({{ $company->id }})" class="text-brand hover:underline text-sm">Edit settings</button>
                            @endif
                        @endcan
                    </div>

                    @if ($editingCompanyId === $company->id)
                        <div class="mt-2 mb-3 p-3 bg-gray-50 rounded-md">
                            <form wire:submit="saveEdit" class="flex flex-wrap gap-3 items-end">
                                <div>
                                    <x-input-label for="editBankAccount" value="Bank account (IBAN)" />
                                    <x-text-input id="editBankAccount" wire:model="editBankAccount" class="w-64" />
                                </div>
                                <div class="flex items-center gap-2 pb-2">
                                    <input type="checkbox" id="editIsVatRegistered" wire:model="editIsVatRegistered">
                                    <label for="editIsVatRegistered" class="text-sm">VAT registered</label>
                                </div>
                                <x-primary-button type="submit">Save</x-primary-button>
                            </form>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=CompanyIndexTest`
Expected: PASS

- [ ] **Step 5: Run the full test suite**

Run: `php artisan test`
Expected: PASS across the whole suite.

- [ ] **Step 6: Commit**

```bash
git add resources/views/livewire/company-index.blade.php tests/Feature/CompanyIndexTest.php
git commit -m "feat: shrink Companies page to company management only"
```
