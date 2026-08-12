# Working Year Context Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the app a per-user, per-company "working year" that filters record lists, pre-fills new-record dates and report periods, and is chosen from a selector at the top of the sidebar — without ever writing `fiscal_year` onto a record.

**Architecture:** One stateless helper class (`App\Support\WorkingYear`) owns the session key, the list of selectable years, and the year→date math. The `Sidebar` Livewire component owns the `<select>`; when it changes it writes the session and dispatches a `working-year-changed` Livewire event. The three list screens pick that event up through a small `InteractsWithWorkingYear` trait and re-render. Forms and reports read the year once in `mount()` and use it only to seed a default date — they never listen for changes, because re-dating a half-filled form under the user is worse than a stale default.

This is Plan 1 of 2 from `docs/superpowers/specs/2026-08-11-sidebar-ia-and-working-year-design.md`. Plan 2 (menu restructure, role visibility, accounting access restriction) builds on it. The company selector shown next to the year selector in the spec's menu sketch is **Plan 2's** work — this plan puts the year selector directly under the existing company-name label in the sidebar, which is the slot Plan 2 will later replace with a company selector.

**Tech Stack:** PHP 8.3, Laravel 13.8, Livewire 3.6 (class-based components), Blade, Tailwind CSS 3 (JIT), PHPUnit 12 (`Tests\TestCase` classes, `RefreshDatabase`, `Livewire::test(...)`).

## Global Constraints

- **The working year never writes `fiscal_year` onto a record.** `fiscal_year` continues to be derived from the document's own date — `JournalEntry::booted()` (`app/Models/JournalEntry.php:28`) and `SalesInvoiceService::confirm()` (`app/Services/Invoicing/SalesInvoiceService.php:41`). No task in this plan may touch either. The selector displays and filters; the document date decides.
- **No migrations, no schema changes, no new routes, no renamed routes.** `partners.*`, `inventory.*`, `accounting.*`, `sales-invoices.*`, `purchase-invoices.*`, `reports.*` all stay exactly as they are.
- **Документи (`App\Livewire\DocumentIndex`, `App\Livewire\DocumentManager`) is deliberately NOT year-filtered.** Do not touch those two components or their views in this plan.
- **The tests must pass on SQLite (local, `phpunit.xml`, `:memory:`) and MySQL 8 (CI, `phpunit.ci.xml`).** Never use `strftime(...)`, `YEAR(...)` or any other raw SQL date function. Filter with `whereBetween($column, [$start, $end])` on `'YYYY-01-01'` / `'YYYY-12-31'` strings, which is portable and index-friendly.
- **All user-visible text is Macedonian.** Two strings are fixed by the spec and must be used verbatim, character for character:
  - empty list: `Нема записи за <year> — провери дали работиш во вистинската година`
  - out-of-year record: `Запис од <year>`
- **Livewire route-param gotcha (already hit once on this project).** The `Sidebar` component must never read `request()->route(...)` inside `render()` — the `/livewire/update` POST carries no route params and the state is silently lost. Everything is captured once in `mount()` into public properties. `Sidebar::mount()` already does this correctly; preserve it.
- **Style:** run `vendor/bin/pint --dirty` before each commit. Tests are PHPUnit classes (not Pest) in `Tests\Feature` / `Tests\Unit`, `use RefreshDatabase;`, roles created with `Role::findOrCreate('admin')` in `setUp()`.
- Work on `main` (the project's normal working branch); CI on push to `main` runs the suite against MySQL and then deploys, including `npm run build`.

**One thing the spec asks for that has nowhere to land.** The spec says the year filters "purchase invoices and stock movements by their document date". There is no stock-movement list screen in the app — verified: `Прием`, `Излез`, `Пренос` and `Корекција` are all *create* forms (`App\Livewire\Inventory\StockMovementForm`), `Состојба` and `Вреднување на залихи` are point-in-time balances with no date at all (`StockOnHandReport`, `StockValuationReport`), and the only screen that lists movements over a period is `Картица на движење` (`ItemMovementCardReport`), which already has its own from/to filters. So stock movements are covered by the *period pre-fill* in Task 7, not by a list filter, and the create form is covered by the *date pre-fill* in Task 6. Nothing is missing; there is simply no list to scope. If a stock-movement list is ever built, it scopes on `movement_date` the same way the purchase invoice list does.

---

## Task 1: `App\Support\WorkingYear` helper

**Files:**
- Create: `app/Support/WorkingYear.php`
- Test: `tests/Unit/Support/WorkingYearTest.php`

**Interfaces:**
- Consumes: `App\Models\Company`, `App\Models\JournalEntry`, `App\Models\SalesInvoice`, `App\Models\PurchaseInvoice`, `App\Models\StockMovement`, `App\Models\Warehouse`.
- Produces, all `public static`, used by every later task:
  - `WorkingYear::sessionKey(int $userId, int $companyId): string`
  - `WorkingYear::for(Company $company): int` — the current working year
  - `WorkingYear::set(Company $company, int $year): void`
  - `WorkingYear::availableYears(Company $company): array` — `list<int>`, descending
  - `WorkingYear::startOf(int $year): string` — `'YYYY-01-01'`
  - `WorkingYear::endOf(int $year): string` — `'YYYY-12-31'`
  - `WorkingYear::defaultDate(int $year): string` — today if `$year` is the current calendar year, otherwise `'YYYY-12-31'`

**Design notes for the implementer (read before writing code):**

*Why `availableYears()` uses min/max and not `DISTINCT YEAR(...)`.* A distinct-year query needs a database date function, which differs between SQLite and MySQL and is banned by the Global Constraints. Instead take the earliest and latest date the company has in each of the four record tables, then offer every year in that span plus the current calendar year. A year in the middle of a company's activity span with no documents yet is still a legitimate working year — you are usually about to enter its data — so the span is the right list, not a defect.

*Why `for()` does not call `availableYears()`.* `for()` runs on every component mount. It only needs to reject a nonsense session value, which a cheap range check does. The `<select>`'s own option list is the real gate on what can be picked.

*`StockMovement` has no `company_id`* — it is scoped through its warehouse (`app/Models/StockMovement.php:13`). Reach it via `whereIn('warehouse_id', ...)`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Support/WorkingYearTest.php`:

```php
<?php

namespace Tests\Unit\Support;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\WorkingYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WorkingYearTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_brand_new_company_offers_only_the_current_calendar_year(): void
    {
        $company = Company::factory()->create();

        $this->assertSame([(int) now()->year], WorkingYear::availableYears($company));
    }

    public function test_available_years_span_from_the_oldest_data_to_the_current_year_descending(): void
    {
        $company = Company::factory()->create();
        JournalEntry::factory()->for($company)->create(['entry_date' => '2024-03-04']);

        $expected = array_reverse(range(2024, (int) now()->year));

        $this->assertSame($expected, WorkingYear::availableYears($company));
    }

    public function test_it_defaults_to_the_current_calendar_year(): void
    {
        $company = Company::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->assertSame((int) now()->year, WorkingYear::for($company));
    }

    public function test_it_remembers_a_separate_year_per_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->actingAs(User::factory()->create());

        WorkingYear::set($companyA, 2024);
        WorkingYear::set($companyB, 2023);

        $this->assertSame(2024, WorkingYear::for($companyA));
        $this->assertSame(2023, WorkingYear::for($companyB));
    }

    public function test_a_nonsense_stored_year_falls_back_to_the_current_year(): void
    {
        $company = Company::factory()->create();
        $this->actingAs(User::factory()->create());

        WorkingYear::set($company, 1999);

        $this->assertSame((int) now()->year, WorkingYear::for($company));
    }

    public function test_the_year_boundaries_are_plain_date_strings(): void
    {
        $this->assertSame('2025-01-01', WorkingYear::startOf(2025));
        $this->assertSame('2025-12-31', WorkingYear::endOf(2025));
    }

    public function test_the_default_date_is_today_in_the_current_year_and_year_end_otherwise(): void
    {
        Carbon::setTestNow('2026-08-12');

        $this->assertSame('2026-08-12', WorkingYear::defaultDate(2026));
        $this->assertSame('2025-12-31', WorkingYear::defaultDate(2025));

        Carbon::setTestNow();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WorkingYearTest`
Expected: FAIL — `Class "App\Support\WorkingYear" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Support/WorkingYear.php`:

```php
<?php

namespace App\Support;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Models\Warehouse;

class WorkingYear
{
    // A sanity bound, not the selector's option list. It only exists so a stale
    // or hand-edited session value can never produce a nonsense date filter.
    private const MIN_YEAR = 2000;

    public static function sessionKey(int $userId, int $companyId): string
    {
        return "working_year.{$userId}.{$companyId}";
    }

    public static function for(Company $company): int
    {
        $stored = session(self::sessionKey((int) auth()->id(), $company->id));

        if (is_int($stored) && $stored >= self::MIN_YEAR && $stored <= (int) now()->year + 1) {
            return $stored;
        }

        return (int) now()->year;
    }

    public static function set(Company $company, int $year): void
    {
        session([self::sessionKey((int) auth()->id(), $company->id) => $year]);
    }

    /**
     * Every year the company could reasonably be working in: the span from its
     * earliest to its latest document, widened to include the current calendar
     * year. Newest first.
     *
     * @return list<int>
     */
    public static function availableYears(Company $company): array
    {
        $warehouseIds = Warehouse::where('company_id', $company->id)->select('id');

        // Each entry pairs a company-scoped query with the date column to read.
        $queries = [
            [JournalEntry::where('company_id', $company->id), 'entry_date'],
            [SalesInvoice::where('company_id', $company->id), 'invoice_date'],
            [PurchaseInvoice::where('company_id', $company->id), 'invoice_date'],
            [StockMovement::whereIn('warehouse_id', $warehouseIds), 'movement_date'],
        ];

        $years = [(int) now()->year];

        foreach ($queries as [$query, $column]) {
            $min = (clone $query)->min($column);
            $max = (clone $query)->max($column);

            if ($min === null || $max === null) {
                continue;
            }

            $years[] = (int) substr((string) $min, 0, 4);
            $years[] = (int) substr((string) $max, 0, 4);
        }

        return array_reverse(range(min($years), max($years)));
    }

    public static function startOf(int $year): string
    {
        return sprintf('%04d-01-01', $year);
    }

    public static function endOf(int $year): string
    {
        return sprintf('%04d-12-31', $year);
    }

    /**
     * The date a brand-new record should open with: today when you are working
     * in the current year, otherwise the last day of the year you are in.
     */
    public static function defaultDate(int $year): string
    {
        return $year === (int) now()->year
            ? now()->toDateString()
            : self::endOf($year);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WorkingYearTest`
Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty && git add app/Support/WorkingYear.php tests/Unit/Support/WorkingYearTest.php && git commit -m "feat(working-year): add WorkingYear session and date helper"
```

---

## Task 2: Year selector in the sidebar

**Files:**
- Modify: `app/Livewire/Layout/Sidebar.php`
- Modify: `resources/views/livewire/layout/sidebar.blade.php:25-27`
- Test: `tests/Feature/SidebarTest.php`

**Interfaces:**
- Consumes: `WorkingYear::for()`, `WorkingYear::set()`, `WorkingYear::availableYears()`, `WorkingYear::sessionKey()` from Task 1.
- Produces: the Livewire event **`working-year-changed`**, dispatched with a single named parameter `year` (int). Every later listener uses exactly this name. Also produces public properties `Sidebar::$workingYear` (int) and `Sidebar::$availableYears` (array).

**Design note:** `mount()` gains an optional `?Company $company = null` parameter. The layout renders `<livewire:layout.sidebar />` with no arguments, so in production `$company` arrives as `null` and the existing `request()->route('company')` fallback runs exactly as today. The parameter exists so tests can mount the component with a company without replaying a full page snapshot. This does not reintroduce the route-param gotcha: the value is still captured once, in `mount()`, into a public property.

- [ ] **Step 1: Write the failing test**

Add these three methods to `tests/Feature/SidebarTest.php`, and add `use App\Support\WorkingYear;` to its imports:

```php
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

        Livewire::test(Sidebar::class, ['company' => $company])
            ->assertSet('workingYear', (int) now()->year)
            ->set('workingYear', (int) now()->year - 1)
            ->assertDispatched('working-year-changed', year: (int) now()->year - 1);

        $this->assertSame(
            (int) now()->year - 1,
            session(WorkingYear::sessionKey($user->id, $company->id))
        );
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SidebarTest`
Expected: FAIL — `assertSee('Година')` finds nothing, and `Livewire::test(Sidebar::class, ['company' => $company])` errors because `mount()` takes no arguments.

- [ ] **Step 3: Update the component**

Replace the top of `app/Livewire/Layout/Sidebar.php` (the `use` block, the properties and `mount()`) with:

```php
<?php

namespace App\Livewire\Layout;

use App\Models\Company;
use App\Support\WorkingYear;
use Livewire\Component;

class Sidebar extends Component
{
    public ?Company $company = null;

    public ?string $expandedModule = null;

    public bool $recordMovementExpanded = false;

    public int $workingYear = 0;

    /** @var list<int> */
    public array $availableYears = [];

    // Everything this component needs is captured here, once. Reading
    // request()->route(...) from render() would silently lose the company on
    // the /livewire/update POST, which carries no route parameters.
    public function mount(?Company $company = null): void
    {
        $company ??= request()->route('company');
        $this->company = $company instanceof Company ? $company : null;
        $this->expandedModule = $this->moduleMatchingCurrentRoute();
        $this->recordMovementExpanded = request()->routeIs('inventory.stock-movements.create');

        if ($this->company) {
            $this->workingYear = WorkingYear::for($this->company);
            $this->availableYears = WorkingYear::availableYears($this->company);
        }
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
```

Leave `toggleModule()`, `toggleRecordMovement()`, `moduleMatchingCurrentRoute()` and `render()` exactly as they are.

- [ ] **Step 4: Update the view**

In `resources/views/livewire/layout/sidebar.blade.php`, replace this single line (line 27):

```blade
                <div class="px-4 pb-2 text-xs uppercase tracking-wide text-gray-500">{{ $company->name }}</div>
```

with:

```blade
                <div class="px-4 pb-3 space-y-2">
                    <div class="text-xs uppercase tracking-wide text-gray-500">{{ $company->name }}</div>
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
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=SidebarTest`
Expected: PASS — all pre-existing SidebarTest methods plus the three new ones. The existing `test_toggling_a_module_via_livewire_still_shows_the_company_after_the_request` must still pass; its snapshot regex keys on `class="w-60`, which the root `<div>` still carries.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty && git add app/Livewire/Layout/Sidebar.php resources/views/livewire/layout/sidebar.blade.php tests/Feature/SidebarTest.php && git commit -m "feat(working-year): add year selector to the sidebar"
```

---

## Task 3: `InteractsWithWorkingYear` trait + journal entry list

**Files:**
- Create: `app/Livewire/Concerns/InteractsWithWorkingYear.php`
- Modify: `app/Livewire/Accounting/JournalEntryIndex.php`
- Modify: `resources/views/livewire/accounting/journal-entry-index.blade.php:34`
- Modify: `database/factories/JournalEntryFactory.php`
- Test: `tests/Feature/JournalEntryIndexTest.php`

**Interfaces:**
- Consumes: `working-year-changed` from Task 2; `WorkingYear::for()` from Task 1.
- Produces: trait `App\Livewire\Concerns\InteractsWithWorkingYear`, giving the using component a `public int $workingYear` property and an `#[On('working-year-changed')]` listener. Tasks 4 and 5 use the same trait.

**Why the factory changes too:** `JournalEntryFactory` currently dates entries `dateTimeBetween('-6 months', 'now')`. Once the list is year-scoped, any test that seeds an entry in January–June would randomly land in the previous calendar year and vanish from the list — a latent flaky test the year filter would expose. Pinning the range to the current year removes it. This is Risk 2 from the spec, handled at the source rather than one test at a time.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/JournalEntryIndexTest.php`, and add `use App\Support\WorkingYear;` to its imports:

```php
    public function test_it_only_lists_entries_from_the_working_year(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        JournalEntry::factory()->for($company)->create(['entry_date' => now()->toDateString(), 'description' => 'Entry this year']);
        JournalEntry::factory()->for($company)->create(['entry_date' => '2024-04-04', 'description' => 'Entry in 2024']);

        $this->actingAs($admin);

        Livewire::test(JournalEntryIndex::class, ['company' => $company])
            ->assertSee('Entry this year')
            ->assertDontSee('Entry in 2024');
    }

    public function test_changing_the_working_year_reloads_the_list(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        JournalEntry::factory()->for($company)->create(['entry_date' => now()->toDateString(), 'description' => 'Entry this year']);
        JournalEntry::factory()->for($company)->create(['entry_date' => '2024-04-04', 'description' => 'Entry in 2024']);

        $this->actingAs($admin);

        Livewire::test(JournalEntryIndex::class, ['company' => $company])
            ->dispatch('working-year-changed', year: 2024)
            ->assertSee('Entry in 2024')
            ->assertDontSee('Entry this year');
    }

    public function test_an_empty_year_says_so_instead_of_saying_there_is_no_data(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        JournalEntry::factory()->for($company)->create(['entry_date' => '2024-04-04', 'description' => 'Entry in 2024']);

        $this->actingAs($admin);

        Livewire::test(JournalEntryIndex::class, ['company' => $company])
            ->assertSee('Нема записи за '.now()->year.' — провери дали работиш во вистинската година');
    }

    public function test_the_working_year_comes_from_the_session(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        JournalEntry::factory()->for($company)->create(['entry_date' => '2024-04-04', 'description' => 'Entry in 2024']);

        $this->actingAs($admin);
        WorkingYear::set($company, 2024);

        Livewire::test(JournalEntryIndex::class, ['company' => $company])
            ->assertSet('workingYear', 2024)
            ->assertSee('Entry in 2024');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=JournalEntryIndexTest`
Expected: FAIL — the list shows both entries, and `assertSet('workingYear', ...)` errors because the property does not exist.

- [ ] **Step 3: Write the trait**

Create `app/Livewire/Concerns/InteractsWithWorkingYear.php`:

```php
<?php

namespace App\Livewire\Concerns;

use App\Support\WorkingYear;
use Livewire\Attributes\On;

/**
 * For list screens that must re-scope themselves when the sidebar's year
 * selector changes. Each using component still sets $workingYear itself in
 * mount() — the trait only owns the live update and the date boundaries.
 */
trait InteractsWithWorkingYear
{
    public int $workingYear = 0;

    #[On('working-year-changed')]
    public function onWorkingYearChanged(int $year): void
    {
        $this->workingYear = $year;

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    public function workingYearStart(): string
    {
        return WorkingYear::startOf($this->workingYear);
    }

    public function workingYearEnd(): string
    {
        return WorkingYear::endOf($this->workingYear);
    }
}
```

- [ ] **Step 4: Update `JournalEntryIndex`**

Replace the whole body of `app/Livewire/Accounting/JournalEntryIndex.php` with:

```php
<?php

namespace App\Livewire\Accounting;

use App\Livewire\Concerns\InteractsWithWorkingYear;
use App\Models\Company;
use App\Models\JournalEntry;
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

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);
    }

    public function render()
    {
        // fiscal_year is derived from entry_date on create and is never
        // rewritten, so filtering on it is exact — see JournalEntry::booted().
        $entries = JournalEntry::where('company_id', $this->company->id)
            ->where('fiscal_year', $this->workingYear)
            ->with(['creator', 'journalGroup'])
            ->orderByDesc('entry_date')
            ->orderByDesc('entry_number')
            ->paginate(25);

        return view('livewire.accounting.journal-entry-index', ['entries' => $entries]);
    }
}
```

- [ ] **Step 5: Update the empty state**

In `resources/views/livewire/accounting/journal-entry-index.blade.php`, replace line 34:

```blade
                <tr><td colspan="4" class="py-4 px-4 text-gray-500">Нема внесени налози.</td></tr>
```

with:

```blade
                <tr><td colspan="4" class="py-4 px-4 text-gray-500">Нема записи за {{ $workingYear }} — провери дали работиш во вистинската година</td></tr>
```

- [ ] **Step 6: Pin the factory to the current year**

In `database/factories/JournalEntryFactory.php`, replace:

```php
            'entry_date' => $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
```

with:

```php
            // Kept inside the current calendar year: journal entry lists are
            // year-scoped, so a fixture that drifted into last year would
            // vanish from the list depending on what month the suite runs in.
            'entry_date' => $this->faker->dateTimeBetween(now()->startOfYear(), 'now')->format('Y-m-d'),
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=JournalEntryIndexTest`
Expected: PASS — the four new tests plus the four pre-existing ones.

Then run the wider accounting suite, which also seeds journal entries:
Run: `php artisan test --filter="JournalEntry|TrialBalance|LedgerCard"`
Expected: PASS. If a test fails because a fixture is dated outside the current year, fix that fixture's date — do not weaken the filter.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty && git add app/Livewire/Concerns/InteractsWithWorkingYear.php app/Livewire/Accounting/JournalEntryIndex.php resources/views/livewire/accounting/journal-entry-index.blade.php database/factories/JournalEntryFactory.php tests/Feature/JournalEntryIndexTest.php && git commit -m "feat(working-year): scope the journal entry list to the working year"
```

---

## Task 4: Sales invoice list

**Files:**
- Modify: `app/Livewire/Invoicing/SalesInvoiceIndex.php`
- Modify: `resources/views/livewire/invoicing/sales-invoice-index.blade.php:79`
- Test: `tests/Feature/SalesInvoiceIndexTest.php`

**Interfaces:**
- Consumes: `InteractsWithWorkingYear` (Task 3), `WorkingYear::for()` (Task 1).
- Produces: nothing new.

**One deliberate deviation from the spec, and why.** The spec says to filter sales invoices by `fiscal_year`. Do **not** do that. `sales_invoices.fiscal_year` is nullable and stays `NULL` until the invoice is confirmed (`SalesInvoiceService::confirm()` sets it, `app/Services/Invoicing/SalesInvoiceService.php:129`). Filtering on it would hide every draft invoice from every year — a worse failure than the one the year filter is meant to prevent. Filter on `invoice_date` instead. For confirmed invoices the two are identical by construction (`fiscal_year = invoice_date->year`, and confirmed invoices cannot be edited — `SalesInvoiceForm::mount()` aborts 403 on anything but a draft), so this is strictly a superset that also keeps drafts visible.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/SalesInvoiceIndexTest.php` (add `use App\Support\WorkingYear;` to its imports; check the file's existing helper for creating an admin and reuse it rather than hand-rolling one):

```php
    public function test_it_only_lists_invoices_from_the_working_year(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $partnerNow = Partner::factory()->for($company)->create(['name' => 'Купувач СЕГА']);
        $partnerOld = Partner::factory()->for($company)->create(['name' => 'Купувач 2024']);
        SalesInvoice::factory()->for($company)->for($partnerNow)->create(['invoice_date' => now()->toDateString()]);
        SalesInvoice::factory()->for($company)->for($partnerOld)->create(['invoice_date' => '2024-04-04']);

        $this->actingAs($admin);

        Livewire::test(SalesInvoiceIndex::class, ['company' => $company])
            ->assertSee('Купувач СЕГА')
            ->assertDontSee('Купувач 2024');
    }

    public function test_a_draft_invoice_stays_visible_even_though_it_has_no_fiscal_year(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $partner = Partner::factory()->for($company)->create(['name' => 'Купувач ДОО']);
        $draft = SalesInvoice::factory()->for($company)->for($partner)->create([
            'invoice_date' => now()->toDateString(),
            'status' => 'draft',
            'fiscal_year' => null,
        ]);

        $this->actingAs($admin);

        Livewire::test(SalesInvoiceIndex::class, ['company' => $company])
            ->assertSee('Купувач ДОО');

        $this->assertNull($draft->fresh()->fiscal_year);
    }

    public function test_an_empty_year_says_so_instead_of_saying_there_is_no_data(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(SalesInvoiceIndex::class, ['company' => $company])
            ->assertSee('Нема записи за '.now()->year.' — провери дали работиш во вистинската година');
    }

    public function test_changing_the_working_year_reloads_the_list(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $partner = Partner::factory()->for($company)->create(['name' => 'Купувач 2024']);
        SalesInvoice::factory()->for($company)->for($partner)->create(['invoice_date' => '2024-04-04']);

        $this->actingAs($admin);

        Livewire::test(SalesInvoiceIndex::class, ['company' => $company])
            ->assertSee('Нема записи за '.now()->year)
            ->dispatch('working-year-changed', year: 2024)
            ->assertSet('workingYear', 2024)
            ->assertSee('Купувач 2024');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SalesInvoiceIndexTest`
Expected: FAIL — both invoices appear, and `assertSet('workingYear', 2024)` errors because the property does not exist.

- [ ] **Step 3: Write the implementation**

Replace the whole body of `app/Livewire/Invoicing/SalesInvoiceIndex.php` with:

```php
<?php

namespace App\Livewire\Invoicing;

use App\Livewire\Concerns\InteractsWithWorkingYear;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Support\WorkingYear;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SalesInvoiceIndex extends Component
{
    use InteractsWithWorkingYear;

    public Company $company;

    public string $statusFilter = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);
    }

    public function render()
    {
        // Scoped on invoice_date, not fiscal_year: fiscal_year is NULL until an
        // invoice is confirmed, so filtering on it would hide every draft.
        // For confirmed invoices the two are identical by construction.
        $invoices = SalesInvoice::where('company_id', $this->company->id)
            ->whereBetween('invoice_date', [$this->workingYearStart(), $this->workingYearEnd()])
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->with(['partner', 'lines', 'payments'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->get();

        return view('livewire.invoicing.sales-invoice-index', ['invoices' => $invoices]);
    }
}
```

- [ ] **Step 4: Update the empty state**

In `resources/views/livewire/invoicing/sales-invoice-index.blade.php`, replace line 79:

```blade
                <tr><td colspan="8" class="py-4 px-4 text-gray-500">Нема издадено фактури.</td></tr>
```

with:

```blade
                <tr><td colspan="8" class="py-4 px-4 text-gray-500">Нема записи за {{ $workingYear }} — провери дали работиш во вистинската година</td></tr>
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=SalesInvoiceIndexTest`
Expected: PASS — the four new tests plus every pre-existing one.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty && git add app/Livewire/Invoicing/SalesInvoiceIndex.php resources/views/livewire/invoicing/sales-invoice-index.blade.php tests/Feature/SalesInvoiceIndexTest.php && git commit -m "feat(working-year): scope the sales invoice list to the working year"
```

---

## Task 5: Purchase invoice list

**Files:**
- Modify: `app/Livewire/Invoicing/PurchaseInvoiceIndex.php`
- Modify: `resources/views/livewire/invoicing/purchase-invoice-index.blade.php:140`
- Test: `tests/Feature/PurchaseInvoiceIndexTest.php`

**Interfaces:**
- Consumes: `InteractsWithWorkingYear` (Task 3), `WorkingYear::for()` (Task 1).
- Produces: nothing new.

**The pending-е-Фактура inbox is deliberately NOT filtered.** `PurchaseInvoiceIndex::render()` also loads `$pendingDocuments` — incoming е-Фактура documents awaiting an accept/reject decision. That is a to-do inbox, not a record list. Year-scoping it would silently drop undecided work off the screen, which is the exact failure mode the spec's empty-state wording exists to prevent, and it is the same reasoning that keeps Документи unfiltered. Leave that query untouched.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/PurchaseInvoiceIndexTest.php` (reuse the file's existing fixture helpers and imports; add `use App\Models\IncomingEfakturaDocument;` only if it is not already imported):

```php
    public function test_it_only_lists_invoices_from_the_working_year(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $partner = Partner::factory()->for($company)->create(['name' => 'Добавувач ДОО']);
        PurchaseInvoice::factory()->for($company)->for($partner)->create(['invoice_date' => now()->toDateString(), 'supplier_invoice_number' => 'ФВ-СЕГА']);
        PurchaseInvoice::factory()->for($company)->for($partner)->create(['invoice_date' => '2024-04-04', 'supplier_invoice_number' => 'ФВ-2024']);

        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->assertSee('ФВ-СЕГА')
            ->assertDontSee('ФВ-2024');
    }

    public function test_an_empty_year_says_so_instead_of_saying_there_is_no_data(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->assertSee('Нема записи за '.now()->year.' — провери дали работиш во вистинската година');
    }

    public function test_changing_the_working_year_reloads_the_list(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $partner = Partner::factory()->for($company)->create(['name' => 'Добавувач ДОО']);
        PurchaseInvoice::factory()->for($company)->for($partner)->create(['invoice_date' => '2024-04-04', 'supplier_invoice_number' => 'ФВ-2024']);

        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->assertDontSee('ФВ-2024')
            ->dispatch('working-year-changed', year: 2024)
            ->assertSee('ФВ-2024');
    }

    public function test_the_pending_efaktura_inbox_is_not_year_scoped(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        IncomingEfakturaDocument::factory()->for($company)->create([
            'doc_date' => '2024-04-04',
            'decision' => null,
            'supplier_name' => 'Стар Добавувач',
        ]);

        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->assertSee('Стар Добавувач');
    }
```

Before writing this, open `database/factories/IncomingEfakturaDocumentFactory.php` and confirm the column names `doc_date`, `decision` and `supplier_name`. If the factory names them differently, use the real names — do not guess. If no such factory exists, copy the seeding style used by the existing pending-document test already in `PurchaseInvoiceIndexTest.php`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PurchaseInvoiceIndexTest`
Expected: FAIL — `ФВ-2024` is visible in the first test and the empty-state string is absent in the second. (`test_the_pending_efaktura_inbox_is_not_year_scoped` should already pass — it is a regression guard, and it must still pass after Step 3.)

- [ ] **Step 3: Write the implementation**

Replace the whole body of `app/Livewire/Invoicing/PurchaseInvoiceIndex.php` with:

```php
<?php

namespace App\Livewire\Invoicing;

use App\Livewire\Concerns\InteractsWithWorkingYear;
use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\PurchaseInvoice;
use App\Support\WorkingYear;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseInvoiceIndex extends Component
{
    use InteractsWithWorkingYear;

    public Company $company;

    public string $statusFilter = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);
    }

    public function render()
    {
        $invoices = PurchaseInvoice::where('company_id', $this->company->id)
            ->whereBetween('invoice_date', [$this->workingYearStart(), $this->workingYearEnd()])
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->with(['partner', 'lines', 'payments', 'incomingEfakturaDocument'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->get();

        // Deliberately NOT year-scoped. This is the undecided-work inbox, not a
        // record list; hiding a pending document because of the year selector
        // would silently drop work the user still has to action.
        $pendingDocuments = IncomingEfakturaDocument::where('company_id', $this->company->id)
            ->where(function ($query) {
                $query->whereNull('decision')
                    ->orWhere(function ($query) {
                        $query->where('decision', IncomingEfakturaDocument::DECISION_REJECTED)
                            ->where('decided_at', '>=', now()->subDays(10));
                    });
            })
            ->orderByDesc('doc_date')
            ->orderByDesc('id')
            ->get();

        return view('livewire.invoicing.purchase-invoice-index', [
            'invoices' => $invoices,
            'pendingDocuments' => $pendingDocuments,
        ]);
    }
}
```

- [ ] **Step 4: Update the empty state**

In `resources/views/livewire/invoicing/purchase-invoice-index.blade.php`, replace line 140:

```blade
                <tr><td colspan="6" class="py-4 px-4 text-gray-500">Нема внесено влезни фактури.</td></tr>
```

with:

```blade
                <tr><td colspan="6" class="py-4 px-4 text-gray-500">Нема записи за {{ $workingYear }} — провери дали работиш во вистинската година</td></tr>
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=PurchaseInvoiceIndexTest`
Expected: PASS — the four new tests plus every pre-existing one.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty && git add app/Livewire/Invoicing/PurchaseInvoiceIndex.php resources/views/livewire/invoicing/purchase-invoice-index.blade.php tests/Feature/PurchaseInvoiceIndexTest.php && git commit -m "feat(working-year): scope the purchase invoice list to the working year"
```

---

## Task 6: New records open with a date inside the working year

**Files:**
- Modify: `app/Livewire/Accounting/JournalEntryForm.php:40-74`
- Modify: `app/Livewire/Invoicing/SalesInvoiceForm.php:74-75`
- Modify: `app/Livewire/Invoicing/PurchaseInvoiceForm.php:76-77`
- Modify: `app/Livewire/Inventory/StockMovementForm.php:41-52`
- Test: `tests/Feature/JournalEntryFormTest.php`, `tests/Feature/SalesInvoiceFormTest.php`, `tests/Feature/PurchaseInvoiceFormTest.php`, `tests/Feature/StockMovementFormTest.php`

**Interfaces:**
- Consumes: `WorkingYear::for()` and `WorkingYear::defaultDate()` from Task 1.
- Produces: `public int $workingYear` on all four form components. Task 8 reads `JournalEntryForm::$workingYear`.

**These four components do NOT use `InteractsWithWorkingYear`.** A form must not re-date itself while the user is filling it in. They read the year once in `mount()` and never listen for changes.

**The date only ever seeds a default.** Nothing in this task may pass the working year to a service, a model, or a `fiscal_year` column. The user remains free to type any date they want, and that typed date is what decides the fiscal year.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/JournalEntryFormTest.php` (add `use App\Support\WorkingYear;`):

```php
    public function test_a_new_entry_opens_dated_inside_the_working_year(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);
        WorkingYear::set($company, 2024);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->assertSet('entryDate', '2024-12-31');
    }

    public function test_a_new_entry_in_the_current_working_year_still_opens_dated_today(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->assertSet('entryDate', now()->toDateString());
    }
```

Add to `tests/Feature/SalesInvoiceFormTest.php` (add `use App\Support\WorkingYear;`):

```php
    public function test_a_new_invoice_opens_dated_inside_the_working_year(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);
        WorkingYear::set($company, 2024);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->assertSet('invoiceDate', '2024-12-31')
            ->assertSet('dueDate', '2024-12-31');
    }
```

Add to `tests/Feature/PurchaseInvoiceFormTest.php` (add `use App\Support\WorkingYear;`):

```php
    public function test_a_new_purchase_invoice_opens_dated_inside_the_working_year(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);
        WorkingYear::set($company, 2024);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->assertSet('invoiceDate', '2024-12-31')
            ->assertSet('dueDate', '2024-12-31');
    }
```

Add to `tests/Feature/StockMovementFormTest.php` (add `use App\Support\WorkingYear;`):

```php
    public function test_a_new_movement_opens_dated_inside_the_working_year(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);
        WorkingYear::set($company, 2024);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'receipt'])
            ->assertSet('movementDate', '2024-12-31');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="JournalEntryFormTest|SalesInvoiceFormTest|PurchaseInvoiceFormTest|StockMovementFormTest"`
Expected: FAIL — every new test reports today's date where `2024-12-31` was expected.

- [ ] **Step 3: `JournalEntryForm`**

Add `use App\Support\WorkingYear;` to the imports and `public int $workingYear = 0;` next to the other public properties. In `mount()`, add the assignment right after `$this->company = $company;`:

```php
        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);
```

and in the `else` branch of `mount()` replace:

```php
            $this->entryDate = now()->toDateString();
```

with:

```php
            $this->entryDate = WorkingYear::defaultDate($this->workingYear);
```

- [ ] **Step 4: `SalesInvoiceForm`**

Add `use App\Support\WorkingYear;` to the imports and `public int $workingYear = 0;` next to the other public properties. In `mount()`, add after `$this->company = $company;`:

```php
        $this->workingYear = WorkingYear::for($company);
```

and replace lines 74-75:

```php
            $this->invoiceDate = now()->toDateString();
            $this->dueDate = now()->toDateString();
```

with:

```php
            $this->invoiceDate = WorkingYear::defaultDate($this->workingYear);
            $this->dueDate = WorkingYear::defaultDate($this->workingYear);
```

- [ ] **Step 5: `PurchaseInvoiceForm`**

Same change as Step 4, on lines 76-77 of `app/Livewire/Invoicing/PurchaseInvoiceForm.php`:

```php
            $this->invoiceDate = WorkingYear::defaultDate($this->workingYear);
            $this->dueDate = WorkingYear::defaultDate($this->workingYear);
```

with `use App\Support\WorkingYear;`, `public int $workingYear = 0;`, and `$this->workingYear = WorkingYear::for($company);` added after `$this->company = $company;` in `mount()`.

- [ ] **Step 6: `StockMovementForm`**

Add `use App\Support\WorkingYear;` and `public int $workingYear = 0;`. In `mount()`, replace:

```php
        $this->company = $company;
        $this->type = $type;
        $this->movementDate = now()->toDateString();
```

with:

```php
        $this->company = $company;
        $this->type = $type;
        $this->workingYear = WorkingYear::for($company);
        $this->movementDate = WorkingYear::defaultDate($this->workingYear);
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter="JournalEntryFormTest|SalesInvoiceFormTest|PurchaseInvoiceFormTest|StockMovementFormTest"`
Expected: PASS — the five new tests plus every pre-existing one in those four files.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty && git add app/Livewire/Accounting/JournalEntryForm.php app/Livewire/Invoicing/SalesInvoiceForm.php app/Livewire/Invoicing/PurchaseInvoiceForm.php app/Livewire/Inventory/StockMovementForm.php tests/Feature/JournalEntryFormTest.php tests/Feature/SalesInvoiceFormTest.php tests/Feature/PurchaseInvoiceFormTest.php tests/Feature/StockMovementFormTest.php && git commit -m "feat(working-year): default new record dates into the working year"
```

---

## Task 7: Reports open on a period inside the working year

**Files:**
- Modify: `app/Livewire/Reports/Ddv04Report.php:21-28`
- Modify: `app/Livewire/Accounting/TrialBalanceReport.php:23-29`
- Modify: `app/Livewire/Accounting/LedgerCardReport.php:27-33`
- Modify: `app/Livewire/Inventory/ItemMovementCardReport.php:27-33`
- Test: `tests/Feature/Ddv04ReportTest.php`, `tests/Feature/TrialBalanceReportTest.php`, `tests/Feature/LedgerCardReportTest.php`, `tests/Feature/ItemMovementCardReportTest.php`

**Interfaces:**
- Consumes: `WorkingYear::for()`, `WorkingYear::startOf()`, `WorkingYear::defaultDate()` from Task 1.
- Produces: `public int $workingYear` on all four report components.

**The rule, applied to all four:** `to` becomes `WorkingYear::defaultDate($year)` — today when working in the current year, `31.12.` of the year otherwise. `from` keeps each report's own semantics, clamped into the year:

| Report | `from` in the current year | `from` in a past year |
|---|---|---|
| ДДВ-04 | start of this month (unchanged) | 1 December of that year |
| Бруто биланс | 1 January of this year (unchanged) | 1 January of that year |
| Аналитичка картица | 1 January of this year (unchanged) | 1 January of that year |
| Картица на движење | 1 January of this year (unchanged) | 1 January of that year |

ДДВ-04 is the odd one out because it is a monthly VAT return — a year-long default period would be meaningless for it. December is the last filed month of a closed year, so it is the right landing point. Behaviour in the current calendar year is unchanged for all four.

These four components do **not** use `InteractsWithWorkingYear`: like the forms, they seed a default the user then adjusts, and silently re-dating a report the user has already narrowed would be worse than a stale default.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Ddv04ReportTest.php` (add `use App\Support\WorkingYear;`; reuse the file's existing admin/company setup style):

```php
    public function test_a_past_working_year_opens_on_december_of_that_year(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);
        WorkingYear::set($company, 2024);

        Livewire::test(Ddv04Report::class, ['company' => $company])
            ->assertSet('from', '2024-12-01')
            ->assertSet('to', '2024-12-31');
    }

    public function test_the_current_working_year_still_opens_on_this_month(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(Ddv04Report::class, ['company' => $company])
            ->assertSet('from', now()->startOfMonth()->toDateString())
            ->assertSet('to', now()->toDateString());
    }
```

Add to `tests/Feature/TrialBalanceReportTest.php`, `tests/Feature/LedgerCardReportTest.php` and `tests/Feature/ItemMovementCardReportTest.php` the same test, changing only the component class (`TrialBalanceReport`, `LedgerCardReport`, `ItemMovementCardReport`) and adding `use App\Support\WorkingYear;`:

```php
    public function test_a_past_working_year_opens_on_that_whole_year(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);
        WorkingYear::set($company, 2024);

        Livewire::test(TrialBalanceReport::class, ['company' => $company])
            ->assertSet('from', '2024-01-01')
            ->assertSet('to', '2024-12-31');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="Ddv04ReportTest|TrialBalanceReportTest|LedgerCardReportTest|ItemMovementCardReportTest"`
Expected: FAIL — every `from`/`to` still reports the current year's dates.

- [ ] **Step 3: `Ddv04Report`**

Add `use App\Support\WorkingYear;` to the imports and `public int $workingYear = 0;` next to `$to`. Replace the body of `mount()`:

```php
    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);

        // ДДВ-04 is a monthly return, so it opens on a month, never a whole
        // year: this month when working in the current year, December of the
        // year otherwise.
        $this->from = $this->workingYear === (int) now()->year
            ? now()->startOfMonth()->toDateString()
            : sprintf('%04d-12-01', $this->workingYear);
        $this->to = WorkingYear::defaultDate($this->workingYear);
    }
```

- [ ] **Step 4: `TrialBalanceReport`**

Add `use App\Support\WorkingYear;` and `public int $workingYear = 0;`. Replace the body of `mount()`:

```php
    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);
        $this->from = WorkingYear::startOf($this->workingYear);
        $this->to = WorkingYear::defaultDate($this->workingYear);
    }
```

- [ ] **Step 5: `LedgerCardReport`**

Add `use App\Support\WorkingYear;` and `public int $workingYear = 0;`. Replace the body of `mount()`:

```php
    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);
        $this->from = WorkingYear::startOf($this->workingYear);
        $this->to = WorkingYear::defaultDate($this->workingYear);
    }
```

- [ ] **Step 6: `ItemMovementCardReport`**

Add `use App\Support\WorkingYear;` and `public int $workingYear = 0;`. Replace the body of `mount()`:

```php
    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);
        $this->from = WorkingYear::startOf($this->workingYear);
        $this->to = WorkingYear::defaultDate($this->workingYear);
    }
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter="Ddv04ReportTest|TrialBalanceReportTest|LedgerCardReportTest|ItemMovementCardReportTest"`
Expected: PASS — the five new tests plus every pre-existing one.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty && git add app/Livewire/Reports/Ddv04Report.php app/Livewire/Accounting/TrialBalanceReport.php app/Livewire/Accounting/LedgerCardReport.php app/Livewire/Inventory/ItemMovementCardReport.php tests/Feature/Ddv04ReportTest.php tests/Feature/TrialBalanceReportTest.php tests/Feature/LedgerCardReportTest.php tests/Feature/ItemMovementCardReportTest.php && git commit -m "feat(working-year): open report periods inside the working year"
```

---

## Task 8: "Запис од &lt;year&gt;" notice on out-of-year records

**Files:**
- Modify: `app/Livewire/Invoicing/SalesInvoiceShow.php:27-37`
- Modify: `app/Livewire/Invoicing/PurchaseInvoiceShow.php:27-38`
- Modify: `resources/views/livewire/accounting/journal-entry-form.blade.php:8-14`
- Modify: `resources/views/livewire/invoicing/sales-invoice-show.blade.php:4-12`
- Modify: `resources/views/livewire/invoicing/purchase-invoice-show.blade.php:4-12`
- Test: `tests/Feature/JournalEntryFormTest.php`, `tests/Feature/SalesInvoiceShowTest.php`, `tests/Feature/PurchaseInvoiceShowTest.php`

**Interfaces:**
- Consumes: `WorkingYear::for()` (Task 1); `JournalEntryForm::$workingYear`, already added in Task 6.
- Produces: `public int $workingYear` on `SalesInvoiceShow` and `PurchaseInvoiceShow`.

**This never blocks.** The spec is explicit: opening a record from another year is allowed. The notice is informational only — no `abort()`, no redirect, no disabled controls.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/JournalEntryFormTest.php`:

```php
    public function test_an_entry_from_another_year_is_flagged_but_still_opens(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $entry = JournalEntry::factory()->for($company)->create(['entry_date' => '2024-04-04']);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry])
            ->assertOk()
            ->assertSee('Запис од 2024');
    }

    public function test_an_entry_from_the_working_year_is_not_flagged(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $entry = JournalEntry::factory()->for($company)->create(['entry_date' => now()->toDateString()]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry])
            ->assertDontSee('Запис од');
    }
```

Add to `tests/Feature/SalesInvoiceShowTest.php` (reuse the file's existing invoice fixture helper if it has one):

```php
    public function test_an_invoice_from_another_year_is_flagged_but_still_opens(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->for($partner)->create(['invoice_date' => '2024-04-04']);

        $this->actingAs($admin);

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertOk()
            ->assertSee('Запис од 2024');
    }
```

Add the equivalent to `tests/Feature/PurchaseInvoiceShowTest.php`:

```php
    public function test_an_invoice_from_another_year_is_flagged_but_still_opens(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $partner = Partner::factory()->for($company)->create();
        $invoice = PurchaseInvoice::factory()->for($company)->for($partner)->create(['invoice_date' => '2024-04-04']);

        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceShow::class, ['company' => $company, 'purchaseInvoice' => $invoice])
            ->assertOk()
            ->assertSee('Запис од 2024');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="JournalEntryFormTest|SalesInvoiceShowTest|PurchaseInvoiceShowTest"`
Expected: FAIL — `Запис од 2024` is not rendered anywhere.

- [ ] **Step 3: Add `$workingYear` to the two show components**

In `app/Livewire/Invoicing/SalesInvoiceShow.php`, add `use App\Support\WorkingYear;` to the imports, `public int $workingYear = 0;` next to `$paymentMethod`, and in `mount()` after `$this->company = $company;`:

```php
        $this->workingYear = WorkingYear::for($company);
```

Make the identical change in `app/Livewire/Invoicing/PurchaseInvoiceShow.php`.

(`JournalEntryForm` already got `$workingYear` in Task 6 — do not add it twice.)

- [ ] **Step 4: Render the notice in the journal entry form**

In `resources/views/livewire/accounting/journal-entry-form.blade.php`, replace the `<h1>` block (lines 10-12):

```blade
        <h1 class="text-2xl font-bold text-gray-800">
            {{ $journalEntry ? 'Измени налог '.$journalEntry->displayNumber() : 'Нов налог' }} — {{ $company->name }}
        </h1>
```

with:

```blade
        <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
            <span>{{ $journalEntry ? 'Измени налог '.$journalEntry->displayNumber() : 'Нов налог' }} — {{ $company->name }}</span>
            @if ($journalEntry && (int) $journalEntry->fiscal_year !== $workingYear)
                <span class="text-xs font-medium text-gray-500 bg-gray-100 rounded-full px-2 py-0.5">Запис од {{ $journalEntry->fiscal_year }}</span>
            @endif
        </h1>
```

- [ ] **Step 5: Render the notice on the sales invoice screen**

In `resources/views/livewire/invoicing/sales-invoice-show.blade.php`, inside the `<p class="text-sm text-gray-500 mb-4 flex items-center gap-2">` block, add as the last element before the closing `</p>`:

```blade
        @if ($invoice->invoice_date->year !== $workingYear)
            <span class="text-xs font-medium text-gray-500 bg-gray-100 rounded-full px-2 py-0.5">Запис од {{ $invoice->invoice_date->year }}</span>
        @endif
```

- [ ] **Step 6: Render the notice on the purchase invoice screen**

Add the identical block to the matching `<p class="text-sm text-gray-500 mb-4 flex items-center gap-2">` in `resources/views/livewire/invoicing/purchase-invoice-show.blade.php`, again as the last element before the closing `</p>`.

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter="JournalEntryFormTest|SalesInvoiceShowTest|PurchaseInvoiceShowTest"`
Expected: PASS — the four new tests plus every pre-existing one.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty && git add app/Livewire/Invoicing/SalesInvoiceShow.php app/Livewire/Invoicing/PurchaseInvoiceShow.php resources/views/livewire/accounting/journal-entry-form.blade.php resources/views/livewire/invoicing/sales-invoice-show.blade.php resources/views/livewire/invoicing/purchase-invoice-show.blade.php tests/Feature/JournalEntryFormTest.php tests/Feature/SalesInvoiceShowTest.php tests/Feature/PurchaseInvoiceShowTest.php && git commit -m "feat(working-year): flag records opened outside the working year"
```

---

## Task 9: Numbering-integrity guard, full suite, asset build, deploy

**Files:**
- Create: `tests/Feature/WorkingYearNumberingIntegrityTest.php`

**Interfaces:**
- Consumes: everything built in Tasks 1–8.
- Produces: nothing consumed downstream. This is the plan's safety net for the correctness requirement the whole design hangs on.

This is the test that proves the selector cannot corrupt invoice numbering — the silent data-integrity bug the spec names in its "Explicit non-behaviour" section, which would otherwise surface only at ДДВ filing time.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/WorkingYearNumberingIntegrityTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use App\Support\WorkingYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkingYearNumberingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_the_working_year_never_decides_an_invoices_fiscal_year(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => false]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        $this->seed(\Database\Seeders\OfficialChartOfAccountsSeeder::class);

        // The user is looking at 2025 in the sidebar...
        WorkingYear::set($company, 2025);

        // ...but writes an invoice dated January 2026.
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->for($partner)->create([
            'invoice_date' => '2026-01-15',
            'status' => 'draft',
        ]);
        $invoice->lines()->create([
            'description' => 'Услуга',
            'quantity' => '1',
            'unit_price' => '1000.00',
            'vat_rate' => '0',
        ]);

        app(SalesInvoiceService::class)->confirm($invoice->fresh(['lines', 'company']), $admin->id);

        $confirmed = $invoice->fresh();

        $this->assertSame(2026, (int) $confirmed->fiscal_year, 'The invoice date decides the fiscal year, not the selector.');
        $this->assertSame(1, (int) $confirmed->invoice_number, 'It must take number 1 of the 2026 series.');
    }

    public function test_the_working_year_never_decides_a_journal_entrys_fiscal_year(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        WorkingYear::set($company, 2025);

        $entry = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-15']);

        $this->assertSame(2026, (int) $entry->fresh()->fiscal_year);
    }
}
```

Before running, open `tests/Unit/SalesInvoiceServiceTest.php` and copy its exact way of preparing a confirmable invoice — the chart of accounts it seeds, the line columns it uses (`vat_rate`, `unit_price`, …) and whether it sets a warehouse. `confirm()` calls `$this->account($company, '120')->firstOrFail()`, so the accounts must exist or the test fails for the wrong reason. Adjust the fixture above to match; do not guess at column names or seeder class names.

- [ ] **Step 2: Run test to verify it passes immediately**

Run: `php artisan test --filter=WorkingYearNumberingIntegrityTest`
Expected: PASS on the first run.

This is the one test in the plan that is *expected* to pass without any production change — it is a guard, not a driver. If it **fails**, something in Tasks 1–8 wrote the working year into a record. Find it and remove it; do not adjust the test to accommodate it.

- [ ] **Step 3: Run the whole suite**

Run: `php artisan test`
Expected: PASS, 0 failures. The suite stood at 738 tests before this plan and this plan adds 38 (7 + 3 + 4 + 4 + 4 + 5 + 5 + 4 + 2 across Tasks 1–9), so expect about 776.

If a pre-existing test fails, it will almost always be because its fixture is dated outside the current calendar year and the new year filter now hides it. Fix the fixture's date. Do not weaken a filter, and do not delete a test, to make the suite green.

- [ ] **Step 4: Build the assets**

Run: `npm run build`
Expected: build succeeds. Tailwind's JIT only emits classes it can see in Blade — the new `<select>` in the sidebar introduces `focus:border-brand` / `focus:ring-brand` on a form control, so skipping this leaves the selector unstyled in a local preview. CI rebuilds on deploy regardless.

- [ ] **Step 5: Commit and push**

```bash
vendor/bin/pint --dirty && git add tests/Feature/WorkingYearNumberingIntegrityTest.php && git commit -m "test(working-year): guard invoice and journal numbering against the selector"
```

```bash
git push origin main
```

- [ ] **Step 6: Confirm CI is green**

Run: `gh run watch`
Expected: the `test` job passes against MySQL 8, then `deploy` runs. If `test` passes locally on SQLite but fails on MySQL, the cause is almost certainly a raw date function that slipped past the Global Constraints — search the diff for `strftime`, `YEAR(`, `whereYear` and `DB::raw`.

- [ ] **Step 7: Verify on the live app**

Open `portal.financebuddy.mk`, open any company, and check each item:

1. A **Година** selector appears at the top of the sidebar, under the company name.
2. A new company shows only the current year; a company with older documents shows the full span.
3. Switching to a past year immediately re-renders the journal-entry, sales-invoice and purchase-invoice lists.
4. An empty year reads `Нема записи за <year> — провери дали работиш во вистинската година`, never a bare "нема записи".
5. **Нов налог** opened while working in a past year is dated 31 December of that year.
6. ДДВ-04, Бруто биланс and Аналитичка картица open on a period inside the selected year.
7. Opening a record from another year shows the grey `Запис од <year>` pill and still opens normally.
8. Switching company A → B → A restores A's remembered year.
9. **Документи** still shows every document regardless of the selected year.
10. Create and confirm an invoice dated in a different year from the selector, and check its number belongs to the invoice date's series, not the selector's.

---

## What this plan does not do

Recorded so the next session does not go looking for them. All of these belong to Plan 2 (`docs/superpowers/specs/2026-08-11-sidebar-ia-and-working-year-design.md`, Delivery order row 2) or later:

- The company selector next to the year selector, and the accountant's company-choice landing screen.
- The five-group menu (ФИНАНСИИ / ПРОДАЖБА / ЗАЛИХА / ПЛАТИ И ЧР / ПОСТАВКИ), the "наскоро" placeholder page, and per-role menu visibility.
- The server-side restriction of the accounting screens to admin and accountant — the real access-control gap named in the spec. **Until Plan 2 ships, a client can still reach every accounting URL directly.** This plan does not make that worse, but it does not fix it either.
- Mobile sidebar behaviour (out of scope in the spec).
