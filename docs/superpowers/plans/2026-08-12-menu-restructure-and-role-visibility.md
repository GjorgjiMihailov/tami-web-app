# Menu Restructure + Role Visibility + Accounting Access Restriction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the build-order sidebar (Сметководство / Магацин / Фактури / Документи / Извештаи) with the user's workflow-shaped target menu (ФИНАНСИИ / ПРОДАЖБА / ЗАЛИХА / ПЛАТИ И ЧР / ПОСТАВКИ), make it genuinely role-aware, and close the access-control gap that makes "the client does not see ФИНАНСИИ" real rather than cosmetic.

**Architecture:** The menu tree stops living in Blade. A single `App\Support\Menu` class holds the whole tree — groups, items, route patterns, per-item role rules, and which items aren't built yet — and returns the already-filtered tree for a given user. That makes the spec's role table something a unit test can assert directly instead of something you verify by scraping HTML. The sidebar becomes a thin renderer over that structure. Separately, a route middleware — not the menu — is what actually refuses a client's request for an accounting URL.

This is Plan 2 of 2 from `docs/superpowers/specs/2026-08-11-sidebar-ia-and-working-year-design.md`. Plan 1 (working year context) is complete and live — commits `f26d3af`..`59aa9b4`, deployed 2026-08-12. This plan builds directly on it: the year selector it added stays exactly where it is, and gains a company selector above it.

**Tech Stack:** PHP 8.3, Laravel 13.8, Livewire 3.6 (class-based components), Blade, Tailwind CSS 3 (JIT), PHPUnit 12 (`Tests\TestCase` classes, `RefreshDatabase`, `Livewire::test(...)`), spatie/laravel-permission for roles.

## Global Constraints

- **Visible labels change; route names and URLs do not.** `partners.*`, `inventory.*`, `accounting.*`, `sales-invoices.*`, `purchase-invoices.*`, `reports.*`, `documents.*` all keep their existing names and paths even though the labels become Кооперанти / ЗАЛИХА / ФИНАНСИИ. Renaming them would touch every view, PDF controller and test for zero user-visible benefit, and would break existing bookmarks. New routes may be added; existing ones must not be renamed or moved.
- **Exactly two menu levels.** Group → item. Nothing deeper. Anything the user described as a "копче" lives as a button or card *on the page*, not as a third menu level. The current third level (`Движење на залиха` → Прием/Издавање/Трансфер/Корекција) is flattened in this plan.
- **"Корекција" (stock adjustment) is removed from the menu.** Попис replaces it. The `inventory.stock-movements.create` route with `type=adjustment` keeps working — only the menu entry goes.
- **Hiding a menu item is not authorization.** Every restriction in the role table that matters for data must also exist server-side. Verified during the spec's brainstorm: `JournalEntryPolicy::view()` (`app/Policies/JournalEntryPolicy.php:15`) returns true for any user whose `visibleCompanies()` includes the record's company — which includes a `client` viewing their own company. Task 3 is the task that closes this; no other task may assume the menu alone protects anything.
- **All user-visible text is Macedonian.** Group headings are the spec's exact uppercase strings: `ФИНАНСИИ`, `ПРОДАЖБА`, `ЗАЛИХА`, `ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ`, `ПОСТАВКИ`. The unbuilt-item marker is exactly `наскоро` (lowercase).
- **The sidebar must not read `request()` inside `render()`.** The `/livewire/update` POST carries no route information, so any state derived from the request at render time is silently lost the instant a `wire:click` fires. Everything — company, current route name, working year, available years — is captured once in `mount()` into public properties. This is the project's recurring Livewire gotcha; the existing sidebar already gets the *company* right but currently derives active-link highlighting from `request()->routeIs(...)` in the Blade, which is why highlighting disappears after a group toggle today. Task 6 fixes that as part of the rewrite.
- **Do not touch Plan 1's working-year behaviour.** `App\Support\WorkingYear`, the `working-year-changed` event, `App\Livewire\Concerns\InteractsWithWorkingYear`, and the year scoping on the three list screens are finished and live. The sidebar rewrite must preserve the year selector and its `updatedWorkingYear` hook exactly as they behave now.
- **Tests must pass on SQLite (local, `phpunit.xml`, `:memory:`) and MySQL 8 (CI, `phpunit.ci.xml`).**
- **Style:** run `vendor/bin/pint --dirty` before each commit. Tests are PHPUnit classes (not Pest) in `Tests\Feature` / `Tests\Unit`, `use RefreshDatabase;`, roles created with `Role::findOrCreate('admin')` in `setUp()`.
- Work on `main`. CI on push to `main` runs the suite against MySQL and then deploys, including `npm run build`. The suite stands at **776 tests** as this plan begins.

### The target menu, for reference in every task

```
Почетна                       (admin only)
Фирми                         (admin only)
──────────────────────────
Фирма:  [ selector ]
Година: [ selector ]          (built in Plan 1, unchanged)
──────────────────────────
ФИНАНСИИ                      admin, accountant
   Главна книга
   Извештаи и обрасци
   Изводи                     наскоро
ПРОДАЖБА                      all roles
   Излезни фактури
   Влезни фактури
   Профактури                 наскоро
   Кооперанти
ЗАЛИХА                        all roles
   Магацини
   Артикли
   Состојба
   Прием
   Излез
   Пренос
   Попис                      наскоро
ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ       all roles
   Вработени                  наскоро
   Плата (МПИН)               наскоро
   е-ПДД                      наскоро
ПОСТАВКИ
   Компанија                  all roles
   Контен план                admin, accountant
   е-Фактура барања           admin only
──────────────────────────
Документи                     all roles
```

**наскоро items are visible to admin and accountant only — a client never sees them.** A group with no visible items is hidden entirely. Today that means a client has no ПЛАТИ И ЧР group at all, because every item in it is unbuilt. The role table records the *intent* (the client eventually gets the full module), not what is on screen today.

---

## Task 1: `App\Support\Menu` — the whole tree, role-filtered

**Files:**
- Create: `app/Support/Menu.php`
- Test: `tests/Unit/Support/MenuTest.php`

**Interfaces:**
- Consumes: `App\Models\User` (`hasRole`, `hasAnyRole`), `App\Models\Company`.
- Produces, used by Tasks 2, 6 and 7:
  - `Menu::for(User $user, Company $company): array` — the filtered tree.
  - `Menu::SOON_FEATURES` — `array<string, array{label: string, sentence: string}>`, keyed by URL slug. Task 2 renders from this exact constant.

**Return shape** (settle this now; Task 6's Blade and Task 2's page both depend on it):

```php
[
    [
        'key'   => 'finance',                 // stable, used for accordion state
        'label' => 'ФИНАНСИИ',
        'items' => [
            [
                'label'   => 'Главна книга',
                'url'     => 'https://…/companies/1/journal-groups',
                'pattern' => 'accounting.journal-groups.*',   // matched with Str::is() against the current route name
                'soon'    => false,
            ],
            // …
        ],
    ],
    // …
]
```

**Design notes for the implementer:**

*Why the tree is a PHP structure and not Blade markup.* The spec's verification bullet is "each of the three roles sees exactly the menu specified in the role table — no more, no less." Against a returned array that is one `assertSame` on a list of labels. Against rendered HTML it is a pile of brittle `assertSee`/`assertDontSee` calls that pass for the wrong reasons (e.g. `assertDontSee('Артикли')` also fails if the word appears in a page heading). Build the structure; test the structure.

*`url` is a fully-resolved URL, not a route name plus params.* The caller has the company; resolving `route(...)` here keeps every route name in one file and means the Blade never constructs a URL.

*`pattern` is a route-name glob, not a request check.* `Menu` must never call `request()`. Task 6 passes the current route name in and matches with `Str::is()`.

*Group visibility is derived, never declared.* Filter items first, then drop any group left with zero items. Do not hardcode "hide ПЛАТИ И ЧР for clients" — that must fall out of "clients don't see наскоро items", so the group reappears by itself the day that module ships.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Support/MenuTest.php`:

```php
<?php

namespace Tests\Unit\Support;

use App\Models\Company;
use App\Models\User;
use App\Support\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    private function userWithRole(string $role, ?Company $company = null): User
    {
        $user = User::factory()->create($company && $role === 'client' ? ['company_id' => $company->id] : []);
        $user->assignRole($role);

        return $user;
    }

    /** @return list<string> */
    private function groupLabels(array $menu): array
    {
        return array_column($menu, 'label');
    }

    /** @return list<string> */
    private function itemLabels(array $menu, string $groupKey): array
    {
        foreach ($menu as $group) {
            if ($group['key'] === $groupKey) {
                return array_column($group['items'], 'label');
            }
        }

        return [];
    }

    public function test_an_admin_sees_every_group(): void
    {
        $company = Company::factory()->create();

        $this->assertSame(
            ['ФИНАНСИИ', 'ПРОДАЖБА', 'ЗАЛИХА', 'ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ', 'ПОСТАВКИ'],
            $this->groupLabels(Menu::for($this->userWithRole('admin'), $company))
        );
    }

    public function test_an_admin_sees_the_full_finance_and_settings_items(): void
    {
        $company = Company::factory()->create();
        $menu = Menu::for($this->userWithRole('admin'), $company);

        $this->assertSame(['Главна книга', 'Извештаи и обрасци', 'Изводи'], $this->itemLabels($menu, 'finance'));
        $this->assertSame(['Компанија', 'Контен план', 'е-Фактура барања'], $this->itemLabels($menu, 'settings'));
    }

    public function test_an_accountant_sees_finance_but_no_efaktura_requests(): void
    {
        $company = Company::factory()->create();
        $menu = Menu::for($this->userWithRole('accountant'), $company);

        $this->assertContains('ФИНАНСИИ', $this->groupLabels($menu));
        $this->assertSame(['Компанија', 'Контен план'], $this->itemLabels($menu, 'settings'));
    }

    public function test_a_client_sees_no_finance_group_at_all(): void
    {
        $company = Company::factory()->create();
        $menu = Menu::for($this->userWithRole('client', $company), $company);

        $this->assertNotContains('ФИНАНСИИ', $this->groupLabels($menu));
    }

    public function test_a_client_sees_only_the_company_item_under_settings(): void
    {
        $company = Company::factory()->create();
        $menu = Menu::for($this->userWithRole('client', $company), $company);

        $this->assertSame(['Компанија'], $this->itemLabels($menu, 'settings'));
    }

    public function test_a_client_never_sees_a_naskoro_item(): void
    {
        $company = Company::factory()->create();
        $menu = Menu::for($this->userWithRole('client', $company), $company);

        foreach ($menu as $group) {
            foreach ($group['items'] as $item) {
                $this->assertFalse($item['soon'], "Client must not see the наскоро item {$item['label']}.");
            }
        }
    }

    // Follows from the two rules above rather than being hardcoded: every item in
    // ПЛАТИ И ЧР is unbuilt today, clients do not see unbuilt items, and a group
    // with no visible items is dropped. The day that module ships, the group
    // reappears for clients with no change to Menu's role rules.
    public function test_a_group_whose_items_are_all_hidden_disappears(): void
    {
        $company = Company::factory()->create();

        $this->assertNotContains(
            'ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ',
            $this->groupLabels(Menu::for($this->userWithRole('client', $company), $company))
        );
        $this->assertContains(
            'ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ',
            $this->groupLabels(Menu::for($this->userWithRole('admin'), $company))
        );
    }

    public function test_a_client_still_gets_the_full_sales_and_stock_groups(): void
    {
        $company = Company::factory()->create();
        $menu = Menu::for($this->userWithRole('client', $company), $company);

        $this->assertSame(['Излезни фактури', 'Влезни фактури', 'Кооперанти'], $this->itemLabels($menu, 'sales'));
        $this->assertSame(
            ['Магацини', 'Артикли', 'Состојба', 'Прием', 'Излез', 'Пренос'],
            $this->itemLabels($menu, 'stock')
        );
    }

    public function test_every_item_carries_a_resolved_url_and_a_route_pattern(): void
    {
        $company = Company::factory()->create();

        foreach (Menu::for($this->userWithRole('admin'), $company) as $group) {
            foreach ($group['items'] as $item) {
                $this->assertStringStartsWith('http', $item['url'], "{$item['label']} has no resolved URL.");
                $this->assertNotSame('', $item['pattern'], "{$item['label']} has no route pattern.");
            }
        }
    }

    public function test_korekcija_is_not_in_the_menu(): void
    {
        $company = Company::factory()->create();

        foreach (Menu::for($this->userWithRole('admin'), $company) as $group) {
            $this->assertNotContains('Корекција', array_column($group['items'], 'label'));
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=MenuTest`
Expected: FAIL — `Class "App\Support\Menu" not found`, 10 errors.

- [ ] **Step 3: Write the implementation**

Create `app/Support/Menu.php`:

```php
<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;

/**
 * The single source of truth for the sidebar's structure.
 *
 * Kept as data rather than Blade markup so the spec's role table can be
 * asserted directly (see tests/Unit/Support/MenuTest.php) instead of by
 * scraping rendered HTML.
 *
 * Never calls request() — the caller passes in whatever request state it
 * needs matched. See this plan's Global Constraints for why.
 */
class Menu
{
    /**
     * Menu entries whose feature does not exist yet. The key is the URL slug
     * used by the shared placeholder route (see App\Livewire\ComingSoon).
     *
     * @var array<string, array{label: string, sentence: string}>
     */
    public const SOON_FEATURES = [
        'izvodi' => [
            'label' => 'Изводи',
            'sentence' => 'Овде ќе се внесуваат и прегледуваат денарските и девизните изводи од банка.',
        ],
        'profakturi' => [
            'label' => 'Профактури',
            'sentence' => 'Овде ќе се издаваат профактури кои подоцна се претвораат во фактури.',
        ],
        'popis' => [
            'label' => 'Попис',
            'sentence' => 'Овде ќе се прави годишен попис на залихите и ќе се книжат разликите.',
        ],
        'vraboteni' => [
            'label' => 'Вработени',
            'sentence' => 'Овде ќе се води евиденција на вработените и нивните договори.',
        ],
        'plata-mpin' => [
            'label' => 'Плата (МПИН)',
            'sentence' => 'Овде ќе се пресметуваат платите и ќе се генерира МПИН датотеката.',
        ],
        'e-pdd' => [
            'label' => 'е-ПДД',
            'sentence' => 'Овде ќе се подготвуваат и извезуваат е-ПДД пресметките.',
        ],
    ];

    /**
     * The filtered menu tree for this user and company.
     *
     * @return list<array{key: string, label: string, items: list<array{label: string, url: string, pattern: string, soon: bool}>}>
     */
    public static function for(User $user, Company $company): array
    {
        $groups = [];

        foreach (self::tree($company) as $group) {
            $items = array_values(array_filter(
                $group['items'],
                fn (array $item) => self::itemVisible($user, $item)
            ));

            // A group with nothing left in it is dropped entirely — never
            // render a bare heading. This is derived, not declared, so a
            // group returns on its own once its items exist.
            if ($items === []) {
                continue;
            }

            $groups[] = [
                'key' => $group['key'],
                'label' => $group['label'],
                'items' => array_map(
                    fn (array $item) => [
                        'label' => $item['label'],
                        'url' => $item['url'],
                        'pattern' => $item['pattern'],
                        'soon' => $item['soon'] ?? false,
                    ],
                    $items
                ),
            ];
        }

        return $groups;
    }

    private static function itemVisible(User $user, array $item): bool
    {
        // Unbuilt entries double as the admin's remaining-work map. A client
        // only ever sees working features.
        if (($item['soon'] ?? false) && ! $user->hasAnyRole(['admin', 'accountant'])) {
            return false;
        }

        $roles = $item['roles'] ?? null;

        return $roles === null || $user->hasAnyRole($roles);
    }

    private static function soon(Company $company, string $slug): array
    {
        return [
            'label' => self::SOON_FEATURES[$slug]['label'],
            'url' => route('coming-soon', [$company, $slug]),
            'pattern' => 'coming-soon',
            'soon' => true,
        ];
    }

    /**
     * The full, unfiltered tree. 'roles' => null means every role.
     */
    private static function tree(Company $company): array
    {
        return [
            [
                'key' => 'finance',
                'label' => 'ФИНАНСИИ',
                'items' => [
                    ['label' => 'Главна книга', 'url' => route('accounting.journal-groups.index', $company), 'pattern' => 'accounting.journal-groups.*', 'roles' => ['admin', 'accountant']],
                    ['label' => 'Извештаи и обрасци', 'url' => route('reports.index', $company), 'pattern' => 'reports.*', 'roles' => ['admin', 'accountant']],
                    self::soon($company, 'izvodi') + ['roles' => ['admin', 'accountant']],
                ],
            ],
            [
                'key' => 'sales',
                'label' => 'ПРОДАЖБА',
                'items' => [
                    ['label' => 'Излезни фактури', 'url' => route('sales-invoices.index', $company), 'pattern' => 'sales-invoices.*', 'roles' => null],
                    ['label' => 'Влезни фактури', 'url' => route('purchase-invoices.index', $company), 'pattern' => 'purchase-invoices.*', 'roles' => null],
                    self::soon($company, 'profakturi'),
                    ['label' => 'Кооперанти', 'url' => route('partners.index', $company), 'pattern' => 'partners.*', 'roles' => null],
                ],
            ],
            [
                'key' => 'stock',
                'label' => 'ЗАЛИХА',
                'items' => [
                    ['label' => 'Магацини', 'url' => route('inventory.warehouses.index', $company), 'pattern' => 'inventory.warehouses.*', 'roles' => null],
                    ['label' => 'Артикли', 'url' => route('inventory.items.index', $company), 'pattern' => 'inventory.items.*', 'roles' => null],
                    ['label' => 'Состојба', 'url' => route('inventory.reports.stock-on-hand', $company), 'pattern' => 'inventory.reports.*', 'roles' => null],
                    ['label' => 'Прием', 'url' => route('inventory.stock-movements.create', [$company, 'receipt']), 'pattern' => '', 'roles' => null],
                    ['label' => 'Излез', 'url' => route('inventory.stock-movements.create', [$company, 'issue']), 'pattern' => '', 'roles' => null],
                    ['label' => 'Пренос', 'url' => route('inventory.stock-movements.create', [$company, 'transfer']), 'pattern' => '', 'roles' => null],
                    self::soon($company, 'popis'),
                ],
            ],
            [
                'key' => 'payroll',
                'label' => 'ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ',
                'items' => [
                    self::soon($company, 'vraboteni'),
                    self::soon($company, 'plata-mpin'),
                    self::soon($company, 'e-pdd'),
                ],
            ],
            [
                'key' => 'settings',
                'label' => 'ПОСТАВКИ',
                'items' => [
                    ['label' => 'Компанија', 'url' => route('companies.dashboard', $company), 'pattern' => 'companies.dashboard', 'roles' => null],
                    ['label' => 'Контен план', 'url' => route('accounting.accounts.index', $company), 'pattern' => 'accounting.accounts.*', 'roles' => ['admin', 'accountant']],
                    ['label' => 'е-Фактура барања', 'url' => route('efaktura.access-requests'), 'pattern' => 'efaktura.access-requests', 'roles' => ['admin']],
                ],
            ],
        ];
    }
}
```

Note the three Прием/Излез/Пренос entries carry an empty `pattern`. They all share one route (`inventory.stock-movements.create`) distinguished only by a path parameter, so a route-name glob cannot tell them apart; highlighting them would need the `type` param, which the sidebar deliberately does not read (see Global Constraints). Empty pattern means "never highlighted" — accepted, and the reason is recorded here so nobody later "fixes" it by reading `request()->route('type')`.

`route('reports.index', …)` and `route('coming-soon', …)` do not exist yet — they are added in Tasks 5 and 2. **The test will fail with `Route [reports.index] not defined` until those tasks land.** Do Task 2 and Task 5 before re-running the full `MenuTest`; the ordering is deliberate so the tree is written once, in one place, rather than grown item by item.

- [ ] **Step 4: Run test and confirm it fails only on the missing routes**

Run: `php artisan test --filter=MenuTest`
Expected: FAIL, but with `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [coming-soon] not defined.` — **not** "Class not found". That is the correct state to move on from; Tasks 2 and 5 add the two routes and Task 5 ends by re-running this file green.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty && git add app/Support/Menu.php tests/Unit/Support/MenuTest.php && git commit -m "feat(menu): add role-filtered menu tree"
```

---

## Task 2: The shared "наскоро" placeholder page

**Files:**
- Create: `app/Livewire/ComingSoon.php`
- Create: `resources/views/livewire/coming-soon.blade.php`
- Modify: `routes/web.php` (add one route to the existing `companies/{company}` area)
- Test: `tests/Feature/ComingSoonTest.php`

**Interfaces:**
- Consumes: `Menu::SOON_FEATURES` from Task 1.
- Produces: route name **`coming-soon`**, URL `companies/{company}/naskoro/{feature}`. Task 1's `Menu::soon()` already links to it by that exact name.

**Why a clickable page and not a disabled menu entry.** A disabled control reads as broken and communicates nothing. A page can state intent. For the admin this list *is* the remaining-work map, which was the whole point of choosing the "build the full target menu now" approach.

**Access.** admin and accountant only — a client never sees these links, and must not be able to reach them by URL either. An unknown feature slug is a 404, not a blank page.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ComingSoonTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ComingSoonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_an_admin_sees_the_feature_name_and_what_it_will_do(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('coming-soon', [$company, 'popis']))
            ->assertOk()
            ->assertSee('Попис')
            ->assertSee('наскоро')
            ->assertSee('Овде ќе се прави годишен попис на залихите и ќе се книжат разликите.');
    }

    public function test_an_accountant_may_open_it_too(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $this->actingAs($accountant)
            ->get(route('coming-soon', [$company, 'izvodi']))
            ->assertOk()
            ->assertSee('Изводи');
    }

    public function test_a_client_is_refused_even_by_direct_url(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client)
            ->get(route('coming-soon', [$company, 'popis']))
            ->assertForbidden();
    }

    public function test_an_unknown_feature_is_a_404(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('coming-soon', [$company, 'ne-postoi']))
            ->assertNotFound();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $company = Company::factory()->create();

        $this->get(route('coming-soon', [$company, 'popis']))->assertRedirect(route('login'));
    }
}
```

Before running, open `app/Models/Company.php` and confirm the accountants relation is named `accountants()` (it is used by `User::visibleCompanies()` at `app/Models/User.php:58`). If the attach signature differs, match the real one — do not guess.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ComingSoonTest`
Expected: FAIL — `Route [coming-soon] not defined.`

- [ ] **Step 3: Write the component**

Create `app/Livewire/ComingSoon.php`:

```php
<?php

namespace App\Livewire;

use App\Models\Company;
use App\Support\Menu;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One shared page behind every "наскоро" menu entry.
 *
 * Deliberately a real page rather than a disabled menu entry: a disabled
 * control reads as broken and says nothing, while a page can state what the
 * feature will do. For the admin these entries are the remaining-work map.
 */
#[Layout('layouts.app')]
class ComingSoon extends Component
{
    public Company $company;

    public string $feature;

    public string $featureLabel = '';

    public string $featureSentence = '';

    public function mount(Company $company, string $feature): void
    {
        Gate::authorize('view', $company);

        // наскоро entries are visible to admin and accountant only, so the
        // page they lead to must refuse a client too — hiding the link is
        // not access control.
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
        abort_unless(array_key_exists($feature, Menu::SOON_FEATURES), 404);

        $this->company = $company;
        $this->feature = $feature;
        $this->featureLabel = Menu::SOON_FEATURES[$feature]['label'];
        $this->featureSentence = Menu::SOON_FEATURES[$feature]['sentence'];
    }

    public function render()
    {
        return view('livewire.coming-soon');
    }
}
```

- [ ] **Step 4: Write the view**

Create `resources/views/livewire/coming-soon.blade.php`:

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1 flex items-center gap-2">
        <span>{{ $featureLabel }}</span>
        <span class="text-xs font-medium text-gray-500 bg-gray-100 rounded-full px-2 py-0.5">наскоро</span>
    </h1>
    <p class="text-sm text-gray-500 mb-4">{{ $company->name }}</p>

    <x-card>
        <p class="text-gray-700">{{ $featureSentence }}</p>
        <p class="text-sm text-gray-500 mt-3">
            Оваа страница сè уште не е изработена. Местото во менито е подготвено однапред,
            за да се знае каде ќе стои кога ќе биде готова.
        </p>
    </x-card>
</div>
```

- [ ] **Step 5: Register the route**

In `routes/web.php`, add `use App\Livewire\ComingSoon;` to the imports (keeping alphabetical order among the `App\Livewire\*` imports), and add this group next to the other `companies/{company}` groups — put it directly above the `documents.` group:

```php
Route::middleware(['auth'])->prefix('companies/{company}')->group(function () {
    Route::get('/naskoro/{feature}', [ComingSoon::class, '__invoke'])->name('coming-soon');
});
```

Use the array-callable form `[ComingSoon::class, '__invoke']` rather than the bare class-string, matching every other Livewire route in this file — the file's own comment at the `accounting.*` group explains why (a bare class-string resolves `method_exists()` eagerly at route-registration time).

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=ComingSoonTest`
Expected: PASS, 5 tests.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty && git add app/Livewire/ComingSoon.php resources/views/livewire/coming-soon.blade.php routes/web.php tests/Feature/ComingSoonTest.php && git commit -m "feat(menu): add the shared наскоро placeholder page"
```

---

## Task 3: Close the accounting access gap server-side

**Files:**
- Create: `app/Http/Middleware/EnsureAccountingAccess.php`
- Modify: `routes/web.php` (the `accounting.` group and the `reports.` group)
- Modify: `app/Policies/AccountPolicy.php:15-18`
- Modify: `app/Policies/JournalEntryPolicy.php:13-16`
- Modify: `app/Policies/JournalGroupPolicy.php:13-16`
- Test: `tests/Feature/AccountingAccessTest.php`, `tests/Feature/AccountingPoliciesTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: middleware alias-free class `App\Http\Middleware\EnsureAccountingAccess`, referenced in `routes/web.php` by its class name.

**This is the task the spec exists for.** Removing ФИНАНСИИ from the client's menu is cosmetic on its own: `JournalEntryPolicy::view()` currently returns true for any user whose `visibleCompanies()` includes the record's company, which includes a client viewing their own company, and the accounting screens gate on `Gate::authorize('view', $company)` — which a client passes for their own company. Every accounting screen is therefore reachable today by URL or by an old bookmark.

**Two layers, on purpose.** The middleware is the real gate: it covers every current and future route in those groups and cannot be forgotten by a new component's author. The policy tightening is the model-level backstop for anything that reaches a record another way (a PDF controller, a future API). Do both.

**Expect existing tests to change.** `tests/Feature/AccountingPoliciesTest.php:26` asserts `test_client_can_view_but_not_edit_their_own_companys_accounts` — that assertion encodes exactly the behaviour this task deliberately reverses. Updating it is the point of the task, not collateral damage.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AccountingAccessTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountingAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    private function client(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('client');

        return $user;
    }

    public static function accountingRoutes(): array
    {
        return [
            'chart of accounts' => ['accounting.accounts.index'],
            'journal groups' => ['accounting.journal-groups.index'],
            'journal entries' => ['accounting.journal-entries.index'],
            'new journal entry' => ['accounting.journal-entries.create'],
            'ledger card' => ['accounting.reports.ledger-card'],
            'trial balance' => ['accounting.reports.trial-balance'],
            'ddv04' => ['reports.ddv04'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('accountingRoutes')]
    public function test_a_client_is_refused_every_accounting_url(string $routeName): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->client($company))
            ->get(route($routeName, $company))
            ->assertForbidden();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('accountingRoutes')]
    public function test_an_admin_still_reaches_every_accounting_url(string $routeName): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route($routeName, $company))->assertOk();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('accountingRoutes')]
    public function test_an_accountant_still_reaches_every_accounting_url(string $routeName): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $this->actingAs($accountant)->get(route($routeName, $company))->assertOk();
    }

    public function test_a_client_is_refused_a_journal_entry_pdf(): void
    {
        $company = Company::factory()->create();
        $entry = JournalEntry::factory()->for($company)->create();

        $this->actingAs($this->client($company))
            ->get(route('accounting.journal-entries.pdf', [$company, $entry]))
            ->assertForbidden();
    }

    public function test_a_client_can_no_longer_view_an_account_or_a_journal_entry_at_the_policy_level(): void
    {
        $company = Company::factory()->create();
        $client = $this->client($company);
        $entry = JournalEntry::factory()->for($company)->create();
        $account = \App\Models\Account::where('company_id', $company->id)->firstOrFail();

        $this->assertFalse($client->can('view', $account));
        $this->assertFalse($client->can('view', $entry));
    }

    public function test_a_client_keeps_access_to_sales_stock_and_documents(): void
    {
        $company = Company::factory()->create();
        $client = $this->client($company);

        $this->actingAs($client);

        $this->get(route('sales-invoices.index', $company))->assertOk();
        $this->get(route('purchase-invoices.index', $company))->assertOk();
        $this->get(route('inventory.items.index', $company))->assertOk();
        $this->get(route('partners.index', $company))->assertOk();
        $this->get(route('documents.index', $company))->assertOk();
        $this->get(route('companies.dashboard', $company))->assertOk();
    }
}
```

`Account::where(...)->firstOrFail()` works without seeding because `CompanyObserver` auto-seeds the full official chart of accounts on `Company::factory()->create()` — confirmed in `tests/Unit/SalesInvoiceServiceTest.php`'s own note. Check that observer still does so before relying on it; if it doesn't, create the account explicitly with `Account::factory()`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AccountingAccessTest`
Expected: FAIL — every `test_a_client_is_refused_*` case returns 200 instead of 403, and the policy-level test asserts false against true.

- [ ] **Step 3: Write the middleware**

Create `app/Http/Middleware/EnsureAccountingAccess.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bookkeeping screens are for the firm, not for the client whose books
 * they are.
 *
 * This is the real gate. Removing ФИНАНСИИ from the client's menu only stops
 * them clicking through — without this, every accounting screen stays
 * reachable by typing the URL or following an old bookmark.
 *
 * Applied to whole route groups rather than to each component, so a screen
 * added later is covered by default instead of by remembering.
 */
class EnsureAccountingAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            $request->user()?->hasAnyRole(['admin', 'accountant']),
            403,
            'Сметководствените екрани се достапни само за администратор и сметководител.'
        );

        return $next($request);
    }
}
```

- [ ] **Step 4: Apply it to the two route groups**

In `routes/web.php`, add `use App\Http\Middleware\EnsureAccountingAccess;` to the imports, then change the two group declarations. The `accounting.` group:

```php
Route::middleware(['auth', EnsureAccountingAccess::class])->prefix('companies/{company}')->name('accounting.')->group(function () {
```

and the `reports.` group:

```php
Route::middleware(['auth', EnsureAccountingAccess::class])->prefix('companies/{company}')->name('reports.')->group(function () {
```

Leave the long explanatory comment above the `accounting.` group in place — it is about the array-callable route form and is still accurate.

- [ ] **Step 5: Tighten the three policies**

In `app/Policies/AccountPolicy.php`, replace `view()`:

```php
    // The bookkeeping screens are admin/accountant only — see
    // App\Http\Middleware\EnsureAccountingAccess. A client's own company
    // being visible to them is not, by itself, permission to read its books.
    public function view(User $user, Account $account): bool
    {
        return $user->hasAnyRole(['admin', 'accountant'])
            && $user->visibleCompanies()->whereKey($account->company_id)->exists();
    }
```

In `app/Policies/JournalEntryPolicy.php`, replace `view()`:

```php
    // See AccountPolicy::view() — same rule, same reason.
    public function view(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasAnyRole(['admin', 'accountant'])
            && $user->visibleCompanies()->whereKey($journalEntry->company_id)->exists();
    }
```

In `app/Policies/JournalGroupPolicy.php`, replace `view()`:

```php
    // See AccountPolicy::view() — same rule, same reason.
    public function view(User $user, JournalGroup $journalGroup): bool
    {
        return $user->hasAnyRole(['admin', 'accountant'])
            && $user->visibleCompanies()->whereKey($journalGroup->company_id)->exists();
    }
```

Leave `viewAny()`, `create()`, `update()` and `delete()` on all three untouched.

- [ ] **Step 6: Update the two now-wrong assertions in the existing policy test**

In `tests/Feature/AccountingPoliciesTest.php`, the method at line 26 asserts the exact behaviour this task reverses. Rename and rewrite it:

```php
    public function test_client_cannot_view_or_edit_their_own_companys_accounts(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $account = Account::factory()->for($company)->create();

        // Reversed deliberately in the menu-restructure plan: the chart of
        // accounts is a bookkeeping screen, and the client is the subject of
        // the books, not a reader of them.
        $this->assertFalse($client->can('view', $account));
        $this->assertFalse($client->can('update', $account));
    }
```

Keep the surrounding fixture style of the file — open it first and match how it builds `$account` (line 26-34) rather than copying the snippet above verbatim if it differs.

Then run the whole file and fix any other assertion that encodes client-can-read-accounting. Do **not** weaken the new middleware or policies to keep an old assertion green.

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter="AccountingAccessTest|AccountingPoliciesTest|AccountingRoutesTest"`
Expected: PASS. If `AccountingRoutesTest` fails, read it — it may assert a client reaches an accounting route, which is now correctly refused; update the assertion.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty && git add app/Http/Middleware/EnsureAccountingAccess.php routes/web.php app/Policies/AccountPolicy.php app/Policies/JournalEntryPolicy.php app/Policies/JournalGroupPolicy.php tests/Feature/AccountingAccessTest.php tests/Feature/AccountingPoliciesTest.php && git commit -m "feat(access): restrict the accounting screens to admin and accountant"
```

---

## Task 4: Главна книга — journal groups lead to their entries

**Files:**
- Modify: `routes/web.php` (one new route in the `accounting.` group)
- Modify: `app/Livewire/Accounting/JournalEntryIndex.php`
- Modify: `resources/views/livewire/accounting/journal-group-index.blade.php`
- Modify: `resources/views/livewire/accounting/journal-entry-index.blade.php`
- Test: `tests/Feature/JournalEntryIndexTest.php`, `tests/Feature/JournalGroupIndexTest.php`

**Interfaces:**
- Consumes: `App\Livewire\Concerns\InteractsWithWorkingYear` and `App\Support\WorkingYear` (Plan 1, unchanged).
- Produces: route name **`accounting.journal-groups.entries`**, URL `companies/{company}/journal-groups/{journalGroup}/entries`. Task 1's menu already points "Главна книга" at `accounting.journal-groups.index`, which is this task's landing page.

**What "rebuild" means here, concretely.** The spec says Главна книга "merges today's Журнали + Налози: list of groups → click a group → its entries". The smallest faithful build: the existing `JournalGroupIndex` page becomes the landing (relabelled Главна книга, each group row linking to its entries), and `JournalEntryIndex` gains an **optional** journal group. Both existing entry-points keep working — `accounting.journal-entries.index` with no group still lists every entry in the working year, so no existing link, bookmark or test breaks.

**The working-year filter still applies inside a group.** A group's entry list is scoped to the working year exactly like the ungrouped list, and shows the same empty-state wording. Do not special-case it.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/JournalEntryIndexTest.php` (it already imports `Company`, `JournalEntry`, `User`, `WorkingYear`, `Livewire`; add `use App\Models\JournalGroup;`):

```php
    public function test_a_group_shows_only_its_own_entries(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $groupA = JournalGroup::factory()->create(['company_id' => $company->id, 'code' => '10', 'name' => 'Банка']);
        $groupB = JournalGroup::factory()->create(['company_id' => $company->id, 'code' => '20', 'name' => 'Каса']);
        JournalEntry::factory()->for($company)->create(['journal_group_id' => $groupA->id, 'description' => 'Bank entry']);
        JournalEntry::factory()->for($company)->create(['journal_group_id' => $groupB->id, 'description' => 'Cash entry']);

        $this->actingAs($admin);

        Livewire::test(JournalEntryIndex::class, ['company' => $company, 'journalGroup' => $groupA])
            ->assertSee('Bank entry')
            ->assertDontSee('Cash entry')
            ->assertSee('Банка');
    }

    public function test_without_a_group_every_entry_in_the_year_is_listed(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $groupA = JournalGroup::factory()->create(['company_id' => $company->id, 'code' => '10', 'name' => 'Банка']);
        $groupB = JournalGroup::factory()->create(['company_id' => $company->id, 'code' => '20', 'name' => 'Каса']);
        JournalEntry::factory()->for($company)->create(['journal_group_id' => $groupA->id, 'description' => 'Bank entry']);
        JournalEntry::factory()->for($company)->create(['journal_group_id' => $groupB->id, 'description' => 'Cash entry']);

        $this->actingAs($admin);

        Livewire::test(JournalEntryIndex::class, ['company' => $company])
            ->assertSee('Bank entry')
            ->assertSee('Cash entry');
    }

    public function test_a_group_from_another_company_is_a_404(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $foreignGroup = JournalGroup::factory()->create(['company_id' => $otherCompany->id, 'code' => '10', 'name' => 'Туѓа']);

        $this->actingAs($admin);

        $this->get(route('accounting.journal-groups.entries', [$company, $foreignGroup]))->assertNotFound();
    }

    public function test_an_empty_group_uses_the_working_year_empty_state(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $group = JournalGroup::factory()->create(['company_id' => $company->id, 'code' => '10', 'name' => 'Банка']);

        $this->actingAs($admin);

        Livewire::test(JournalEntryIndex::class, ['company' => $company, 'journalGroup' => $group])
            ->assertSee('Нема записи за '.now()->year.' — провери дали работиш во вистинската година');
    }
```

Add to `tests/Feature/JournalGroupIndexTest.php` (open it first and match its existing fixture and import style):

```php
    public function test_each_group_links_to_its_own_entries(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $group = JournalGroup::factory()->create(['company_id' => $company->id, 'code' => '10', 'name' => 'Банка']);

        $this->actingAs($admin);

        Livewire::test(JournalGroupIndex::class, ['company' => $company])
            ->assertSee('Главна книга')
            ->assertSeeHtml(route('accounting.journal-groups.entries', [$company, $group]));
    }
```

Check `database/factories/JournalGroupFactory.php` exists and takes `company_id`/`code`/`name`; if the factory's shape differs, match it rather than guessing.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="JournalEntryIndexTest|JournalGroupIndexTest"`
Expected: FAIL — `Route [accounting.journal-groups.entries] not defined`, and `JournalEntryIndex::mount()` rejects the extra `journalGroup` argument.

- [ ] **Step 3: Add the route**

In `routes/web.php`, inside the existing `accounting.` group, add one line directly after the `journal-groups.index` line:

```php
    Route::get('/journal-groups/{journalGroup}/entries', [JournalEntryIndex::class, '__invoke'])->name('journal-groups.entries');
```

- [ ] **Step 4: Make the group optional on `JournalEntryIndex`**

Replace the whole body of `app/Livewire/Accounting/JournalEntryIndex.php` with:

```php
<?php

namespace App\Livewire\Accounting;

use App\Livewire\Concerns\InteractsWithWorkingYear;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Support\WorkingYear;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class JournalEntryIndex extends Component
{
    use InteractsWithWorkingYear;
    use WithPagination;

    public Company $company;

    // Optional: set when the user drilled in from Главна книга, null when
    // they opened the flat "all entries this year" list. Both entry points
    // stay valid so no existing link or bookmark breaks.
    public ?JournalGroup $journalGroup = null;

    public function mount(Company $company, ?JournalGroup $journalGroup = null): void
    {
        Gate::authorize('view', $company);

        if ($journalGroup && $journalGroup->company_id !== $company->id) {
            abort(404);
        }

        $this->company = $company;
        $this->journalGroup = $journalGroup;
        $this->workingYear = WorkingYear::for($company);
    }

    public function render()
    {
        // fiscal_year is derived from entry_date on create and is never
        // rewritten, so filtering on it is exact — see JournalEntry::booted().
        $entries = JournalEntry::where('company_id', $this->company->id)
            ->where('fiscal_year', $this->workingYear)
            ->when($this->journalGroup, fn ($q) => $q->where('journal_group_id', $this->journalGroup->id))
            ->with(['creator', 'journalGroup'])
            ->orderByDesc('entry_date')
            ->orderByDesc('entry_number')
            ->paginate(25);

        return view('livewire.accounting.journal-entry-index', ['entries' => $entries]);
    }
}
```

- [ ] **Step 5: Show the group on the entry list**

In `resources/views/livewire/accounting/journal-entry-index.blade.php`, find the page's `<h1>` and replace it so the heading names the group and offers a way back. Open the file and read the current heading block first; the replacement keeps whatever "Нов налог" button already sits beside it:

```blade
        <h1 class="text-2xl font-bold text-gray-800">
            {{ $journalGroup ? $journalGroup->code.' — '.$journalGroup->name : 'Налози за книжење' }} — {{ $company->name }}
        </h1>
```

and directly under the heading row add:

```blade
    @if ($journalGroup)
        <div class="mb-4">
            <a href="{{ route('accounting.journal-groups.index', $company) }}" wire:navigate class="text-brand text-sm hover:underline">
                ← Назад на Главна книга
            </a>
        </div>
    @endif
```

- [ ] **Step 6: Turn the group list into the Главна книга landing**

In `resources/views/livewire/accounting/journal-group-index.blade.php`, change the page `<h1>` text to `Главна книга — {{ $company->name }}`, and make each group's name in the table a link to its entries:

```blade
                        <a href="{{ route('accounting.journal-groups.entries', [$company, $group]) }}" wire:navigate class="text-brand hover:underline">
                            {{ $group->name }}
                        </a>
```

Open the file first and place this inside whichever `<td>` currently prints `$group->name` as plain text, leaving the existing edit/delete controls in that row untouched.

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter="JournalEntryIndexTest|JournalGroupIndexTest|AccountingRoutesTest"`
Expected: PASS — the five new tests plus every pre-existing one.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty && git add routes/web.php app/Livewire/Accounting/JournalEntryIndex.php resources/views/livewire/accounting/journal-entry-index.blade.php resources/views/livewire/accounting/journal-group-index.blade.php tests/Feature/JournalEntryIndexTest.php tests/Feature/JournalGroupIndexTest.php && git commit -m "feat(menu): make journal groups the Главна книга landing"
```

---

## Task 5: "Извештаи и обрасци" landing page

**Files:**
- Create: `app/Livewire/Reports/ReportIndex.php`
- Create: `resources/views/livewire/reports/report-index.blade.php`
- Modify: `routes/web.php` (one new route in the `reports.` group)
- Test: `tests/Feature/ReportIndexTest.php`
- Test: `tests/Unit/Support/MenuTest.php` (re-run, no edit)

**Interfaces:**
- Consumes: `Menu::SOON_FEATURES` is **not** used here — the three unbuilt reports are buttons on this page, not menu items, and get their own inline copy.
- Produces: route name **`reports.index`**, URL `companies/{company}/reports`. Task 1's menu already points "Извештаи и обрасци" at it.

**Six buttons, three of which do nothing yet.** Per the spec: ДДВ-04 ✔, Бруто биланс ✔, Аналитичка картица ✔, МДБ ✖, Завршна сметка ✖, Солвентност ✖. The three unbuilt ones are rendered as muted, non-clickable cards with a `наскоро` marker — **not** as links to the Task 2 placeholder page. That page exists for menu entries; these are page-level buttons and the spec's two-level rule means they never become menu items. A muted card that plainly says наскоро is honest here in a way a disabled menu control would not be, because the surrounding page already explains the context.

This route sits inside the `reports.` group, so Task 3's `EnsureAccountingAccess` middleware already covers it — a client cannot reach this page.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ReportIndexTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Livewire\Reports\ReportIndex;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_links_to_the_three_working_reports(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(ReportIndex::class, ['company' => $company])
            ->assertSeeHtml(route('reports.ddv04', $company))
            ->assertSeeHtml(route('accounting.reports.trial-balance', $company))
            ->assertSeeHtml(route('accounting.reports.ledger-card', $company));
    }

    public function test_it_names_the_three_reports_that_are_not_built_yet(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(ReportIndex::class, ['company' => $company])
            ->assertSee('МДБ')
            ->assertSee('Завршна сметка')
            ->assertSee('Солвентност')
            ->assertSee('наскоро');
    }

    public function test_the_page_renders_over_http_for_an_admin(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('reports.index', $company))->assertOk();
    }

    public function test_a_client_is_refused(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client)->get(route('reports.index', $company))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ReportIndexTest`
Expected: FAIL — `Class "App\Livewire\Reports\ReportIndex" not found`.

- [ ] **Step 3: Write the component**

Create `app/Livewire/Reports/ReportIndex.php`:

```php
<?php

namespace App\Livewire\Reports;

use App\Models\Company;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The ФИНАНСИИ → Извештаи и обрасци landing page.
 *
 * The menu is exactly two levels deep, so every report is a button here
 * rather than a third-level menu entry.
 */
#[Layout('layouts.app')]
class ReportIndex extends Component
{
    public Company $company;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        return view('livewire.reports.report-index', [
            'available' => [
                ['label' => 'ДДВ-04', 'description' => 'Пресметка на данок на додадена вредност за период.', 'url' => route('reports.ddv04', $this->company)],
                ['label' => 'Бруто биланс', 'description' => 'Промет и салда по конта за период.', 'url' => route('accounting.reports.trial-balance', $this->company)],
                ['label' => 'Аналитичка картица', 'description' => 'Ставки по конто или по комитент за период.', 'url' => route('accounting.reports.ledger-card', $this->company)],
            ],
            'soon' => [
                ['label' => 'МДБ', 'description' => 'Месечен даночен биланс.'],
                ['label' => 'Завршна сметка', 'description' => 'Годишна завршна сметка.'],
                ['label' => 'Солвентност', 'description' => 'Извештај за солвентност.'],
            ],
        ]);
    }
}
```

- [ ] **Step 4: Write the view**

Create `resources/views/livewire/reports/report-index.blade.php`:

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Извештаи и обрасци — {{ $company->name }}</h1>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($available as $report)
            <a href="{{ $report['url'] }}" wire:navigate
               class="block bg-white rounded-2xl shadow-card p-4 hover:bg-orange-50 transition">
                <span class="font-semibold text-gray-800">{{ $report['label'] }}</span>
                <p class="text-sm text-gray-500 mt-1">{{ $report['description'] }}</p>
            </a>
        @endforeach

        @foreach ($soon as $report)
            <div class="block bg-white rounded-2xl shadow-card p-4 opacity-60">
                <span class="font-semibold text-gray-500 flex items-center gap-2">
                    {{ $report['label'] }}
                    <span class="text-xs font-medium text-gray-500 bg-gray-100 rounded-full px-2 py-0.5">наскоро</span>
                </span>
                <p class="text-sm text-gray-500 mt-1">{{ $report['description'] }}</p>
            </div>
        @endforeach
    </div>
</div>
```

- [ ] **Step 5: Register the route**

In `routes/web.php`, add `use App\Livewire\Reports\ReportIndex;` to the imports, and add one line inside the existing `reports.` group, above the `ddv04` line:

```php
    Route::get('/reports', [ReportIndex::class, '__invoke'])->name('index');
```

- [ ] **Step 6: Run tests to verify they pass — including Task 1's**

Run: `php artisan test --filter="ReportIndexTest|MenuTest|ComingSoonTest"`
Expected: PASS — 4 + 10 + 5 tests. `MenuTest` was left failing on the two missing routes at the end of Task 1; both now exist, so it must be fully green here. If it is not, the route names in `Menu::tree()` and in `routes/web.php` disagree — fix `Menu`, not the route names (the Global Constraints forbid renaming routes, but these two are new, so either side could move; keep `reports.index` and `coming-soon`).

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty && git add app/Livewire/Reports/ReportIndex.php resources/views/livewire/reports/report-index.blade.php routes/web.php tests/Feature/ReportIndexTest.php && git commit -m "feat(menu): add the Извештаи и обрасци landing page"
```

---

## Task 6: Rewrite the sidebar over `Menu`

**Files:**
- Create: `app/Support/CurrentCompany.php`
- Modify: `app/Livewire/Layout/Sidebar.php`
- Modify: `resources/views/livewire/layout/sidebar.blade.php` (full rewrite)
- Modify: `resources/views/livewire/partner-index.blade.php:3`
- Modify: `resources/views/livewire/inventory/stock-on-hand-report.blade.php:2-5`
- Test: `tests/Feature/SidebarTest.php`, `tests/Feature/StockOnHandReportTest.php`

**This task also has to catch two screens the new menu drops.** The old ЗАЛИХА menu carried `Картица на движење` (`inventory.reports.item-movement-card`) and `Вреднување на залихи` (`inventory.reports.stock-valuation`). Neither is in the target menu — the spec instead says the **Состојба page carries them as buttons**. Both routes still exist and both pages still work; without those buttons the sidebar rewrite would silently orphan them, leaving two working reports reachable only by typing a URL. Steps 6 and 7 add them. This belongs in this task rather than its own because it is the same change — the menu stops carrying them, so the page starts.

**Interfaces:**
- Consumes: `Menu::for()` (Task 1); `App\Support\WorkingYear` (Plan 1).
- Produces: `App\Support\CurrentCompany::remember(Company)` and `CurrentCompany::lastFor(User): ?int` — Task 7 uses both. Public `Sidebar` properties `$menu` (array), `$currentRoute` (string), `$expandedGroup` (?string), plus Plan 1's `$workingYear` / `$availableYears` unchanged.

**What changes and what must not.**
- The three hardcoded modules become a `@foreach` over `$menu`. Group state key changes from `$expandedModule` (values `accounting`/`inventory`/`invoicing`) to `$expandedGroup` (values `finance`/`sales`/`stock`/`payroll`/`settings`), matching `Menu`'s `key`.
- One group open at a time — unchanged behaviour, and with five groups it is required rather than stylistic, or the menu exceeds the viewport.
- The third level (`Движење на залиха` → four children) and its `$recordMovementExpanded` / `toggleRecordMovement()` are **deleted**. Прием / Излез / Пренос are now ordinary second-level items; Корекција is gone from the menu.
- **A company selector is added above the year selector.** It lists `auth()->user()->visibleCompanies()`, and picking one navigates to that company's dashboard. For a client with exactly one company it still renders — a one-option select is honest and costs nothing — but it is never a way to reach a company they cannot already see, because the list comes from `visibleCompanies()`.
- Active-link highlighting stops calling `request()->routeIs(...)` in the Blade. The current route name is captured once in `mount()` as `$currentRoute` and matched with `Str::is($item['pattern'], $currentRoute)`. This fixes a real (if cosmetic) bug: today the highlight vanishes the moment a group is toggled, because the `/livewire/update` POST has no route.
- `Почетна` and `Фирми` become admin-only.
- `Документи` moves out of the groups and stands alone at the bottom.
- The label `Партнери` becomes `Кооперанти` in the menu and on the partner index page heading. Route names stay `partners.*`.

- [ ] **Step 1: Write the failing test**

Replace the whole of `tests/Feature/SidebarTest.php` with the version below. It keeps the three tests from Plan 1 that still describe correct behaviour (the year selector, its absence without a company, and storing the year) and the snapshot-replay test that guards the Livewire route-param gotcha, and replaces the module tests with group tests.

```php
<?php

namespace Tests\Feature;

use App\Livewire\Layout\Sidebar;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
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
            ->assertDontSeeHtml(route('companies.index'))
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

        $this->assertSame($company->id, \App\Support\CurrentCompany::lastFor($admin));
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SidebarTest`
Expected: FAIL — no group headings render, `toggleGroup` does not exist, `CurrentCompany` does not exist.

- [ ] **Step 3: Write `CurrentCompany`**

Create `app/Support/CurrentCompany.php`:

```php
<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;

/**
 * Remembers which company a user last had open, so an accountant with
 * several assigned companies lands back where they were instead of on a
 * chooser every time. See App\Livewire\Dashboard.
 *
 * Deliberately session-scoped, not persisted: "where I was last time" is a
 * convenience, not a setting, and it should reset with a new session.
 */
class CurrentCompany
{
    public static function sessionKey(int $userId): string
    {
        return "last_company.{$userId}";
    }

    public static function remember(Company $company): void
    {
        session([self::sessionKey((int) auth()->id()) => $company->id]);
    }

    public static function lastFor(User $user): ?int
    {
        $id = session(self::sessionKey($user->id));

        return is_int($id) ? $id : null;
    }
}
```

- [ ] **Step 4: Rewrite the component**

Replace the whole of `app/Livewire/Layout/Sidebar.php` with:

```php
<?php

namespace App\Livewire\Layout;

use App\Models\Company;
use App\Support\CurrentCompany;
use App\Support\Menu;
use App\Support\WorkingYear;
use Illuminate\Support\Str;
use Livewire\Component;

class Sidebar extends Component
{
    public ?Company $company = null;

    public ?string $expandedGroup = null;

    public int $workingYear = 0;

    /** @var list<int> */
    public array $availableYears = [];

    /** @var list<array{id: int, name: string}> */
    public array $companyOptions = [];

    /** @var list<array{key: string, label: string, items: list<array{label: string, url: string, pattern: string, soon: bool}>}> */
    public array $menu = [];

    // The name of the route the page was loaded on. Captured here, once,
    // because the /livewire/update POST carries no route at all — deriving
    // it in render() silently loses every highlight the moment a group is
    // toggled.
    public string $currentRoute = '';

    public function mount(?Company $company = null): void
    {
        $company ??= request()->route('company');
        $this->company = $company instanceof Company ? $company : null;
        $this->currentRoute = (string) request()->route()?->getName();

        if (! $this->company) {
            return;
        }

        CurrentCompany::remember($this->company);

        $this->menu = Menu::for(auth()->user(), $this->company);
        $this->expandedGroup = $this->groupMatchingCurrentRoute();
        $this->workingYear = WorkingYear::for($this->company);
        $this->availableYears = WorkingYear::availableYears($this->company);
        $this->companyOptions = auth()->user()->visibleCompanies()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name])
            ->all();
    }

    public function toggleGroup(string $group): void
    {
        $this->expandedGroup = $this->expandedGroup === $group ? null : $group;
    }

    public function isActive(string $pattern): bool
    {
        return $pattern !== '' && Str::is($pattern, $this->currentRoute);
    }

    // Livewire hands updated* hooks the raw incoming value, so cast before use
    // rather than type-hinting the parameter.
    public function updatedWorkingYear($value): void
    {
        $year = (int) $value;

        if (! $this->company || ! in_array($year, $this->availableYears, true)) {
            return;
        }

        WorkingYear::set($this->company, $year);

        $this->dispatch('working-year-changed', year: $year);
    }

    private function groupMatchingCurrentRoute(): ?string
    {
        foreach ($this->menu as $group) {
            foreach ($group['items'] as $item) {
                if ($this->isActive($item['pattern'])) {
                    return $group['key'];
                }
            }
        }

        return null;
    }

    public function render()
    {
        return view('livewire.layout.sidebar');
    }
}
```

- [ ] **Step 5: Rewrite the view**

Replace the whole of `resources/views/livewire/layout/sidebar.blade.php` with:

```blade
<div class="w-60 shrink-0 bg-white border-r border-gray-100 text-gray-700 flex flex-col min-h-screen">
    <div class="px-4 py-4 border-b border-gray-100">
        <a href="{{ route('dashboard') }}" wire:navigate class="font-bold text-brand text-sm">
            {{ config('app.name', 'Laravel') }}
        </a>
    </div>

    <nav class="flex-1 py-3 space-y-1">
        @if (auth()->check() && auth()->user()->hasRole('admin'))
            <a href="{{ route('dashboard') }}" wire:navigate
               class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ $currentRoute === 'dashboard' ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}">
                Почетна
            </a>
            <a href="{{ route('companies.index') }}" wire:navigate
               class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ $currentRoute === 'companies.index' ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}">
                Фирми
            </a>
        @endif

        @if ($company)
            <div class="pt-4 mt-3 border-t border-gray-100">
                <div class="px-4 pb-3 space-y-2">
                    <label class="flex items-center gap-2 text-xs text-gray-500">
                        <span>Фирма</span>
                        <select onchange="if (this.value) window.location.href = this.value"
                                class="flex-1 rounded-lg border-gray-200 text-sm py-1 text-gray-700 focus:border-brand focus:ring-brand">
                            @foreach ($companyOptions as $option)
                                <option value="{{ route('companies.dashboard', $option['id']) }}"
                                        @selected($option['id'] === $company->id)>{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex items-center gap-2 text-xs text-gray-500">
                        <span>Година</span>
                        <select wire:model.live="workingYear"
                                class="flex-1 rounded-lg border-gray-200 text-sm py-1 text-gray-700 focus:border-brand focus:ring-brand">
                            @foreach ($availableYears as $year)
                                <option value="{{ $year }}">{{ $year }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                @foreach ($menu as $group)
                    <button type="button" wire:click="toggleGroup('{{ $group['key'] }}')"
                            class="w-full text-left flex items-center justify-between px-4 py-2 text-sm font-medium rounded-lg mx-3 text-gray-600 hover:bg-orange-50"
                            style="width: calc(100% - 1.5rem);">
                        <span>{{ $group['label'] }}</span>
                        <span>{{ $expandedGroup === $group['key'] ? '−' : '+' }}</span>
                    </button>
                    @if ($expandedGroup === $group['key'])
                        <div class="pl-6">
                            @foreach ($group['items'] as $item)
                                <a href="{{ $item['url'] }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-1.5 text-sm {{ $this->isActive($item['pattern']) ? 'text-brand font-medium' : ($item['soon'] ? 'text-gray-400 hover:text-gray-600' : 'text-gray-500 hover:text-gray-800') }}">
                                    <span>{{ $item['label'] }}</span>
                                    @if ($item['soon'])
                                        <span class="text-[10px] uppercase tracking-wide text-gray-400">наскоро</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    @endif
                @endforeach

                <a href="{{ route('documents.index', $company) }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 mt-1 {{ str_starts_with($currentRoute, 'documents.') ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}">
                    Документи
                </a>
            </div>
        @endif
    </nav>
</div>
```

The company `<select>` uses a plain `onchange` navigation rather than a Livewire binding on purpose: switching company is a full page change to a different URL, and routing it through `/livewire/update` would just redirect anyway. Keep the root element's `class="w-60 …"` exactly as written — `SidebarTest::extractSidebarSnapshot()` matches on it.

- [ ] **Step 6: Give the Состојба page the two reports the menu no longer carries**

In `resources/views/livewire/inventory/stock-on-hand-report.blade.php`, the page opens with a heading row that already holds a "Преземи PDF" link. Replace that whole row (lines 2-5) with:

```blade
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Состојба — {{ $company->name }}</h1>
        <div class="flex flex-wrap items-center gap-4 text-sm">
            <a href="{{ route('inventory.reports.item-movement-card', $company) }}" wire:navigate class="text-brand hover:underline">Картица на движење</a>
            <a href="{{ route('inventory.reports.stock-valuation', $company) }}" wire:navigate class="text-brand hover:underline">Вреднување на залихи</a>
            <a href="{{ route('inventory.reports.stock-on-hand.pdf', $company) }}{{ $warehouseId ? '?warehouseId='.$warehouseId : '' }}" class="text-brand hover:underline">Преземи PDF</a>
        </div>
    </div>
```

The heading also changes from `Залиха` to `Состојба`, matching its menu label so the page and the menu entry that leads to it agree.

- [ ] **Step 7: Rename the partner page heading**

In `resources/views/livewire/partner-index.blade.php:3`, change:

```blade
        <h1 class="text-2xl font-bold text-gray-800">Партнери — {{ $company->name }}</h1>
```

to:

```blade
        <h1 class="text-2xl font-bold text-gray-800">Кооперанти — {{ $company->name }}</h1>
```

Then run `grep -rn "Партнери" resources/ app/ --include=*.php` and update any remaining user-visible occurrence to `Кооперанти` — at the time of writing the only other one is a description line in `resources/views/livewire/company-dashboard.blade.php:288`. Do not rename route names, class names, table names, or the `Partner` model.

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test --filter="SidebarTest|MenuTest|PartnerIndexTest|StockOnHandReportTest|AppShellTest"`
Expected: PASS. Two pre-existing assertions are likely to need updating, both correctly: `PartnerIndexTest` may assert the old `Партнери` heading (change to `Кооперанти`), and `StockOnHandReportTest` may assert the old `Залиха —` heading (change to `Состојба —`).

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty && git add app/Support/CurrentCompany.php app/Livewire/Layout/Sidebar.php resources/views/livewire/layout/sidebar.blade.php resources/views/livewire/partner-index.blade.php resources/views/livewire/company-dashboard.blade.php resources/views/livewire/inventory/stock-on-hand-report.blade.php tests/Feature/SidebarTest.php tests/Feature/PartnerIndexTest.php tests/Feature/StockOnHandReportTest.php && git commit -m "feat(menu): rewrite the sidebar over the role-filtered menu tree"
```

---

## Task 7: Where each role lands after login

**Files:**
- Modify: `app/Livewire/Dashboard.php`
- Modify: `app/Livewire/CompanyIndex.php`
- Modify: `resources/views/livewire/dashboard.blade.php`
- Test: `tests/Feature/DashboardTest.php`, `tests/Feature/CompanyIndexTest.php`

**Interfaces:**
- Consumes: `CurrentCompany::lastFor()` (Task 6).
- Produces: nothing consumed later.

**The question the spec raises and answers.** Removing Почетна and Фирми from non-admin menus leaves those users nowhere to land, because login redirects to `route('dashboard')` (`resources/views/livewire/pages/auth/login.blade.php:23`). Rather than change the login redirect — which would spread the rule across four Volt pages that all redirect to `dashboard` — the `dashboard` route itself decides:

- **admin** → the dashboard, unchanged.
- **client** → straight into their own company; they have exactly one.
- **accountant** → the company they last had open if it is still visible to them; else their only company if they have exactly one; else a plain company-choice screen.

The choice screen is the existing dashboard view, which already lists `visibleCompanies()`. It is reachable only in that state — it is not a menu item, so this does not reintroduce "Фирми" for accountants.

**Фирми becomes admin-only** at the component too, not just in the menu — same principle as Task 3.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/DashboardTest.php` (open it first and match its existing imports and setUp; it will need `Role::findOrCreate('accountant')` and `'client'`):

```php
    public function test_an_admin_stays_on_the_dashboard(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
    }

    public function test_a_client_is_sent_straight_into_their_own_company(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client)
            ->get(route('dashboard'))
            ->assertRedirect(route('companies.dashboard', $company));
    }

    public function test_an_accountant_with_one_company_is_sent_into_it(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $this->actingAs($accountant)
            ->get(route('dashboard'))
            ->assertRedirect(route('companies.dashboard', $company));
    }

    public function test_an_accountant_with_several_companies_gets_a_choice_screen(): void
    {
        $first = Company::factory()->create(['name' => 'Прва Фирма']);
        $second = Company::factory()->create(['name' => 'Втора Фирма']);
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $first->accountants()->attach($accountant);
        $second->accountants()->attach($accountant);

        $this->actingAs($accountant)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Прва Фирма')
            ->assertSee('Втора Фирма');
    }

    public function test_an_accountant_returns_to_the_company_they_last_had_open(): void
    {
        $first = Company::factory()->create(['name' => 'Прва Фирма']);
        $second = Company::factory()->create(['name' => 'Втора Фирма']);
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $first->accountants()->attach($accountant);
        $second->accountants()->attach($accountant);

        $this->actingAs($accountant);
        $this->get(route('companies.dashboard', $second))->assertOk();

        $this->get(route('dashboard'))->assertRedirect(route('companies.dashboard', $second));
    }

    public function test_a_remembered_company_that_is_no_longer_visible_is_ignored(): void
    {
        $mine = Company::factory()->create();
        $other = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $mine->accountants()->attach($accountant);

        $this->actingAs($accountant);
        session([\App\Support\CurrentCompany::sessionKey($accountant->id) => $other->id]);

        $this->get(route('dashboard'))->assertRedirect(route('companies.dashboard', $mine));
    }
```

Add to `tests/Feature/CompanyIndexTest.php`:

```php
    public function test_only_an_admin_may_open_the_companies_screen(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $this->actingAs($client)->get(route('companies.index'))->assertForbidden();
        $this->actingAs($accountant)->get(route('companies.index'))->assertForbidden();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="DashboardTest|CompanyIndexTest"`
Expected: FAIL — every role currently gets a 200 on `/dashboard`, and `/companies` is open to everyone.

- [ ] **Step 3: Route each role from the dashboard**

Replace the whole of `app/Livewire/Dashboard.php` with:

```php
<?php

namespace App\Livewire;

use App\Models\Company;
use App\Support\CurrentCompany;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Почетна for the admin; a router for everyone else.
 *
 * Non-admins have no Почетна and no Фирми in their menu, but login still
 * redirects here (see resources/views/livewire/pages/auth/login.blade.php).
 * Deciding the destination here keeps that rule in one place instead of
 * spreading it across the four Volt auth pages that all redirect to
 * 'dashboard'.
 */
#[Layout('layouts.app')]
class Dashboard extends Component
{
    public function mount()
    {
        $user = auth()->user();

        if ($user->hasRole('admin')) {
            return null;
        }

        $target = $this->companyToOpen($user);

        // No target means an accountant with several companies and nothing
        // remembered — fall through and render the choice screen below.
        return $target ? $this->redirect(route('companies.dashboard', $target)) : null;
    }

    private function companyToOpen($user): ?Company
    {
        $visible = $user->visibleCompanies();

        $rememberedId = CurrentCompany::lastFor($user);

        if ($rememberedId !== null) {
            $remembered = (clone $visible)->whereKey($rememberedId)->first();

            if ($remembered) {
                return $remembered;
            }
        }

        $companies = (clone $visible)->orderBy('name')->get();

        return $companies->count() === 1 ? $companies->first() : null;
    }

    public function render()
    {
        $companies = auth()->user()->visibleCompanies()->orderBy('name')->get();

        return view('livewire.dashboard', ['companies' => $companies]);
    }
}
```

`visibleCompanies()` returns an `Illuminate\Database\Eloquent\Builder` (`app/Models/User.php:47`), so it must be cloned before each terminal call — running `whereKey()` and then `get()` on the same builder would apply both constraints. Read that method before changing this if anything looks off.

- [ ] **Step 4: Make Фирми admin-only**

In `app/Livewire/CompanyIndex.php`, add a `mount()` above `addCompany()`:

```php
    public function mount(): void
    {
        // Фирми is an admin screen — see the role table in
        // docs/superpowers/specs/2026-08-11-sidebar-ia-and-working-year-design.md.
        // An accountant with several companies reaches the chooser through
        // App\Livewire\Dashboard instead, which is not a menu entry.
        abort_unless(auth()->user()->hasRole('admin'), 403);
    }
```

- [ ] **Step 5: Give the choice screen an honest heading**

In `resources/views/livewire/dashboard.blade.php`, the page currently serves both purposes. Add a heading that reads correctly for a non-admin landing on it, directly inside the root element, before the existing content:

```blade
    @unless (auth()->user()->hasRole('admin'))
        <h1 class="text-2xl font-bold text-gray-800 mb-4">Избери фирма</h1>
    @endunless
```

Open the file first and place this so it does not duplicate an existing `<h1>` — if the file already opens with an admin-facing heading, wrap that one in `@if (auth()->user()->hasRole('admin'))` instead of adding a second.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter="DashboardTest|CompanyIndexTest|SidebarTest"`
Expected: PASS — the seven new tests plus every pre-existing one. `SidebarTest::test_it_shows_no_groups_when_no_company_is_selected` and `test_there_is_no_year_selector_without_a_company` both `get('/dashboard')` as an admin, which still renders — they must stay green.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty && git add app/Livewire/Dashboard.php app/Livewire/CompanyIndex.php resources/views/livewire/dashboard.blade.php tests/Feature/DashboardTest.php tests/Feature/CompanyIndexTest.php && git commit -m "feat(menu): land each role where its menu actually starts"
```

---

## Task 8: Full suite, asset build, deploy

**Files:**
- No new files. This task fixes whatever the previous seven broke elsewhere.

**Interfaces:**
- Consumes: everything.
- Produces: a green suite and a deployed app.

- [ ] **Step 1: Run the whole suite**

Run: `php artisan test`
Expected: PASS, 0 failures. The suite stood at 776 before this plan. It adds about 58 test cases (10 + 5 + 24 + 5 + 4 + 3 net + 7 across Tasks 1–7 — Task 3's three data-provider methods contribute 21 of them on their own, and Task 6 rewrites `SidebarTest` from 11 methods to 14), so expect roughly 834.

Failures here will cluster in three predictable places. Fix the test, not the feature, in the first two:

1. **Tests asserting a client can reach or view accounting.** Correctly reversed by Task 3. Update the assertion.
2. **Tests asserting the old menu labels** (`Сметководство`, `Магацин`, `Фактури`, `Партнери`, `Движење на залиха`, `Извештаи`) or the old `expandedModule` property. Update to the new labels and `expandedGroup`.
3. **Tests that `get('/dashboard')` as a non-admin and expect 200.** Now a redirect, by design. Update to `assertRedirect`.

Anything that does not fall into those three is a real regression — investigate it rather than adjusting the assertion.

- [ ] **Step 2: Build the assets**

Run: `npm run build`
Expected: build succeeds. Tailwind's JIT only emits classes it can see in Blade, and the sidebar rewrite introduces several that never appeared before (`text-[10px]`, `opacity-60`, the grid classes on the reports page). Skipping this leaves them missing in a local preview. CI rebuilds on deploy regardless.

- [ ] **Step 3: Verify the three role menus by eye**

Start the app locally (`composer dev`) and log in as each role in turn — or, if no non-admin account exists locally, assert it in tinker instead:

```bash
php artisan tinker --execute="\$u = App\Models\User::role('client')->first(); \$c = \$u->visibleCompanies()->first(); print_r(array_column(App\Support\Menu::for(\$u, \$c), 'label'));"
```

Expected for a client: `ПРОДАЖБА`, `ЗАЛИХА`, `ПОСТАВКИ` — and nothing else.

- [ ] **Step 4: Commit and push**

```bash
git add -A && git commit -m "test(menu): update assertions for the new menu and access rules"
```

```bash
git push origin main
```

- [ ] **Step 5: Confirm CI is green**

Run: `gh run watch`
Expected: the `test` job passes against MySQL 8, then `deploy` runs.

- [ ] **Step 6: Verify on the live app**

Open `portal.financebuddy.mk` and check each item against the spec:

1. The five groups appear in order: ФИНАНСИИ, ПРОДАЖБА, ЗАЛИХА, ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ, ПОСТАВКИ.
2. Opening one group closes the previous one, and the whole menu still fits the viewport.
3. Фирма and Година selectors sit at the top; switching company works and each company keeps its own remembered year.
4. A "наскоро" item opens the placeholder page naming the feature.
5. Главна книга lists the journal groups; clicking one shows only its entries for the working year.
6. Извештаи и обрасци shows three working buttons and three muted ones.
7. ЗАЛИХА is flat — Прием, Излез, Пренос are ordinary items, and Корекција is gone from the menu.
8. Партнери now reads Кооперанти everywhere it is visible.
9. Документи stands alone at the bottom and still shows every document regardless of year.
10. **The access check, done properly:** log in as a real client and paste an accounting URL (e.g. `/companies/2/journal-entries`) directly into the address bar. It must be refused, not merely un-linked. Do the same for `/companies` and for a `наскоро` URL.

---

## What this plan does not do

Recorded so the next session does not go looking for them. All were listed as out of scope in the spec:

- **Mobile sidebar behaviour.** The sidebar is still a fixed `w-60` column with no collapse control, which on a phone occupies roughly half the screen. Real, flagged to the user, still unclaimed.
- Приемница / испратница / адресница / преносница as numbered, printable documents. Прием, Излез and Пренос stay raw stock movements, exactly as they work today — the user decided this explicitly.
- OCR of bank statements, Telegram/Viber intake, automatic posting of attached documents.
- Monthly batching of invoices into one journal entry per month (group 20 / group 30). Described by the user as the target workflow, but it is a Главна книга *behaviour* change, not a menu change.
- Any actual implementation of Профактури, Попис, Изводи, МДБ, Завршна сметка, Солвентност, or the ПЛАТИ И ЧР module. When the payroll module starts, the user will supply the MPIN `.txt` template and the е-ПДД `.xml` sample — ask for them then; they are not in the repo.
