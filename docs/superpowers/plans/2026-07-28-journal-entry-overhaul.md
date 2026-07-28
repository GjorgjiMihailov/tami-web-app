# Journal Entry (Налози) Screen Overhaul Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the Journal Entry (Налози) create/edit screen — per-company journal groups with group-scoped numbering, per-line dates with late-date highlighting, a prev/next navigator, single-entry PDF printing, an FX display toggle, Alpine-driven account/partner autocomplete, a sticky running-totals footer, and a responsive no-horizontal-scroll layout — plus a new Journal Groups settings screen.

**Architecture:** A new `journal_groups` table (per-company, admin/accountant-managed) feeds a `journal_group_id` column on `journal_entries` that the existing auto-numbering hook now scopes by, alongside the existing `company_id`/`fiscal_year` scope. `JournalEntryForm` (the existing Livewire component) gains the group picker, per-line `line_date`, the FX toggle, navigator, delete, and autocomplete; a new `JournalEntryPdfController` renders a table-based PDF (dompdf has zero flex/grid support, confirmed from the sales-invoice sub-project). Two existing GL-posting services (`SalesInvoiceService`, `PurchaseInvoiceService`) that create `JournalEntry` rows directly — bypassing the form entirely — must be updated so their auto-posted entries still get a valid group, or the new numbering hook and `displayNumber()` accessor would break invoice confirmation across the whole app.

**Tech Stack:** Laravel 13, Livewire 3, Alpine.js (already a dependency via the barcode-scanner feature — no new JS package), `barryvdh/laravel-dompdf` 3.1.6 (table-only layout), PHPUnit + SQLite in tests / MySQL in CI and production.

## Global Constraints

- Money formatting always goes through `\App\Support\Format::money()`; dates through `\App\Support\Format::date()`. Never format manually in a view.
- The PDF template (`resources/views/pdf/journal-entry.blade.php`) must use `<table>`/`<td>` for every multi-column region — **no `display: flex` or `display: grid` anywhere** — dompdf 3.1.6 silently downgrades flex to block with no reflower, confirmed from the vendor source during the sales-invoice-redesign sub-project.
- New migration columns (`journal_group_id` on `journal_entries`, `line_date` on `journal_entry_lines`) are added **nullable at the database level** and backfilled within the same migration. This project has no `doctrine/dbal` dependency, so a later `->nullable(false)->change()` isn't available — "required" is enforced by application code (Livewire validation, and a factory `afterMaking` hook for tests) rather than a DB constraint. Do not add `doctrine/dbal` to solve this.
- New Alpine components are registered via `document.addEventListener('alpine:init', () => Alpine.data(...))` in their own file under `resources/js/`, imported from `resources/js/app.js` — the exact pattern `resources/js/barcode-scanner.js` already uses.
- New routes inside the `accounting.` prefix group use the array-callable form (`[ClassName::class, '__invoke']`), matching every existing route in that group — bare class-strings crash route registration if the target class doesn't exist yet at boot time.
- Policies are auto-discovered by Laravel's naming convention (`App\Models\Foo` → `App\Policies\FooPolicy`) — this codebase never registers policies explicitly anywhere, so don't add one.
- After each task, run the full suite: `php artisan test`. A task is not done until the full suite is green, not just its own new tests — several tasks in this plan touch shared model/factory code that other modules depend on.
- Every new/changed Macedonian string in this plan is final wording — do not paraphrase.

---

## Reference: current relevant files (before this plan)

- `app/Models/JournalEntry.php` — `booted()` auto-assigns `fiscal_year` + `entry_number` scoped by `(company_id, fiscal_year)` only.
- `database/migrations/2026_07_19_090200_create_journal_entries_table.php` — unique index on `(company_id, fiscal_year, entry_number)`.
- `database/migrations/2026_07_19_090300_create_journal_entry_lines_table.php` — no per-line date column.
- `app/Livewire/Accounting/JournalEntryForm.php` (206 lines) — plain `<select>` dropdowns, one shared `entryDate` for the whole entry, no journal group, no delete, no navigator, no PDF.
- `app/Livewire/Accounting/JournalEntryIndex.php` — flat paginated list, shows raw `entry_number`.
- `app/Services/Invoicing/SalesInvoiceService.php` / `PurchaseInvoiceService.php` — each has 3 `JournalEntry::create([...])` call sites (confirm/cancel/recordPayment) that bypass the form entirely.
- `app/Policies/JournalEntryPolicy.php` — already has `delete()` (delegates to `update()`, i.e. admin/accountant only) — no policy change needed for the delete button.
- No Settings section exists yet in this app; `app/Livewire/PartnerIndex.php` and `app/Livewire/Inventory/WarehouseIndex.php` are the closest "list + inline add form" precedents to follow.

---

### Task 1: Journal Groups schema, model, factory, policy

**Files:**
- Create: `database/migrations/2026_07_28_100000_create_journal_groups_table.php`
- Create: `app/Models/JournalGroup.php`
- Create: `database/factories/JournalGroupFactory.php`
- Create: `app/Policies/JournalGroupPolicy.php`
- Modify: `tests/Feature/AccountingPoliciesTest.php`

**Interfaces:**
- Produces: `JournalGroup` model (`id`, `company_id`, `code`, `name`, `sort_order`), `company(): BelongsTo`, `journalEntries(): HasMany`, `label(): string` (returns `"{code} — {name}"`). Consumed by every later task.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 2);
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_groups');
    }
};
```

- [ ] **Step 2: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalGroup extends Model
{
    use HasFactory;

    protected $fillable = ['company_id', 'code', 'name', 'sort_order'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function label(): string
    {
        return "{$this->code} — {$this->name}";
    }
}
```

- [ ] **Step 3: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class JournalGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => $this->faker->unique()->numerify('##'),
            'name' => ucfirst($this->faker->words(2, true)),
            'sort_order' => 0,
        ];
    }
}
```

- [ ] **Step 4: Write the policy**

```php
<?php

namespace App\Policies;

use App\Models\JournalGroup;
use App\Models\User;

class JournalGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JournalGroup $journalGroup): bool
    {
        return $user->visibleCompanies()->whereKey($journalGroup->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant']);
    }

    public function update(User $user, JournalGroup $journalGroup): bool
    {
        return $user->hasAnyRole(['admin', 'accountant'])
            && $user->visibleCompanies()->whereKey($journalGroup->company_id)->exists();
    }

    public function delete(User $user, JournalGroup $journalGroup): bool
    {
        return $this->update($user, $journalGroup);
    }
}
```

- [ ] **Step 5: Write the failing policy test**

Add to `tests/Feature/AccountingPoliciesTest.php` (inside the existing class, keep everything else as-is):

```php
    public function test_client_cannot_manage_journal_groups(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $group = \App\Models\JournalGroup::factory()->for($company)->create();

        $this->assertFalse($client->can('create', \App\Models\JournalGroup::class));
        $this->assertFalse($client->can('update', $group));
        $this->assertFalse($client->can('delete', $group));
    }

    public function test_accountant_assigned_to_a_company_can_manage_its_journal_groups(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($company);
        $group = \App\Models\JournalGroup::factory()->for($company)->create();

        $this->assertTrue($accountant->can('create', \App\Models\JournalGroup::class));
        $this->assertTrue($accountant->can('update', $group));
        $this->assertTrue($accountant->can('delete', $group));
    }
```

- [ ] **Step 6: Run migrations and the test**

Run: `php artisan migrate --env=testing` (or just `php artisan test --filter=AccountingPoliciesTest`, which runs migrations against the in-memory SQLite test DB automatically).
Expected: both new tests PASS (policy auto-discovery needs no registration — confirmed by every other policy in this codebase).

- [ ] **Step 7: Run the full suite and commit**

Run: `php artisan test`
Expected: all green (nothing yet references `journal_groups`, so no existing test is affected).

```bash
git add database/migrations/2026_07_28_100000_create_journal_groups_table.php app/Models/JournalGroup.php database/factories/JournalGroupFactory.php app/Policies/JournalGroupPolicy.php tests/Feature/AccountingPoliciesTest.php
git commit -m "feat: add journal_groups table, model, factory, and policy"
```

---

### Task 2: Journal Groups settings screen

**Files:**
- Create: `app/Livewire/Accounting/JournalGroupIndex.php`
- Create: `resources/views/livewire/accounting/journal-group-index.blade.php`
- Create: `tests/Feature/JournalGroupIndexTest.php`
- Modify: `routes/web.php`
- Modify: `resources/views/livewire/layout/sidebar.blade.php`

**Interfaces:**
- Consumes: `JournalGroup` (Task 1).
- Produces: route `accounting.journal-groups.index`, reachable from the Accounting sidebar submenu — used by Task 5's picker as "where to manage groups" (linked, not embedded).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Accounting\JournalGroupIndex;
use App\Models\Company;
use App\Models\JournalGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JournalGroupIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_lists_the_companys_journal_groups(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        JournalGroup::factory()->for($company)->create(['code' => '10', 'name' => 'Изводи-Денарски']);

        $this->actingAs($admin);

        Livewire::test(JournalGroupIndex::class, ['company' => $company])
            ->assertSee('10')
            ->assertSee('Изводи-Денарски');
    }

    public function test_admin_can_add_a_journal_group(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(JournalGroupIndex::class, ['company' => $company])
            ->set('newCode', '20')
            ->set('newName', 'Купувачи')
            ->call('addGroup')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('journal_groups', ['company_id' => $company->id, 'code' => '20', 'name' => 'Купувачи']);
    }

    public function test_a_duplicate_code_for_the_same_company_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        JournalGroup::factory()->for($company)->create(['code' => '20']);

        $this->actingAs($admin);

        Livewire::test(JournalGroupIndex::class, ['company' => $company])
            ->set('newCode', '20')
            ->set('newName', 'Друга група')
            ->call('addGroup')
            ->assertHasErrors('newCode');

        $this->assertDatabaseCount('journal_groups', 1);
    }

    public function test_client_cannot_add_a_journal_group(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        Livewire::test(JournalGroupIndex::class, ['company' => $company])
            ->set('newCode', '20')
            ->set('newName', 'Купувачи')
            ->call('addGroup')
            ->assertForbidden();
    }

    public function test_deleting_an_unused_group_removes_it(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $group = JournalGroup::factory()->for($company)->create();

        $this->actingAs($admin);

        Livewire::test(JournalGroupIndex::class, ['company' => $company])
            ->call('deleteGroup', $group->id);

        $this->assertDatabaseCount('journal_groups', 0);
    }

    public function test_deleting_a_group_with_entries_is_blocked(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $group = JournalGroup::factory()->for($company)->create();
        \App\Models\JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'created_by' => $admin->id]);

        $this->actingAs($admin);

        Livewire::test(JournalGroupIndex::class, ['company' => $company])
            ->call('deleteGroup', $group->id)
            ->assertHasErrors('delete');

        $this->assertDatabaseCount('journal_groups', 1);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=JournalGroupIndexTest`
Expected: FAIL — `JournalGroupIndex` doesn't exist yet.

- [ ] **Step 3: Write the Livewire component**

```php
<?php

namespace App\Livewire\Accounting;

use App\Models\Company;
use App\Models\JournalGroup;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class JournalGroupIndex extends Component
{
    public Company $company;

    public string $newCode = '';

    public string $newName = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function addGroup(): void
    {
        Gate::authorize('create', JournalGroup::class);

        $validated = $this->validate([
            'newCode' => ['required', 'string', 'size:2', Rule::unique('journal_groups', 'code')->where('company_id', $this->company->id)],
            'newName' => ['required', 'string', 'max:255'],
        ]);

        JournalGroup::create([
            'company_id' => $this->company->id,
            'code' => $validated['newCode'],
            'name' => $validated['newName'],
            'sort_order' => (JournalGroup::where('company_id', $this->company->id)->max('sort_order') ?? 0) + 1,
        ]);

        $this->reset(['newCode', 'newName']);
    }

    public function deleteGroup(int $groupId): void
    {
        $group = JournalGroup::where('company_id', $this->company->id)->findOrFail($groupId);
        Gate::authorize('delete', $group);

        if ($group->journalEntries()->exists()) {
            $this->addError('delete', 'Овој журнал веќе има внесени налози и не може да се избрише.');

            return;
        }

        $group->delete();
    }

    public function render()
    {
        return view('livewire.accounting.journal-group-index', [
            'groups' => JournalGroup::where('company_id', $this->company->id)->orderBy('sort_order')->orderBy('code')->get(),
        ]);
    }
}
```

- [ ] **Step 4: Write the view**

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Журнали — {{ $company->name }}</h1>

    @error('delete') <p class="text-red-600 text-sm mb-4">{{ $message }}</p> @enderror

    @can('create', \App\Models\JournalGroup::class)
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Додади журнал</h2>
            <form wire:submit="addGroup" class="flex flex-wrap gap-3 items-end">
                <div>
                    <x-input-label for="newCode" value="Код (2 цифри)" />
                    <x-text-input id="newCode" wire:model="newCode" maxlength="2" class="w-20" />
                    @error('newCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newName" value="Име" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <x-primary-button type="submit">Додади</x-primary-button>
            </form>
        </x-card>
    @endcan

    <x-card padding="p-0" class="overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Код</th>
                <th class="py-2 px-4">Име</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($groups as $group)
                <tr class="text-sm">
                    <td class="py-2 px-4 font-mono">{{ $group->code }}</td>
                    <td class="py-2 px-4">{{ $group->name }}</td>
                    <td class="py-2 px-4">
                        @can('delete', $group)
                            <button type="button" wire:click="deleteGroup({{ $group->id }})" wire:confirm="Да се избрише журналот {{ $group->code }}?" class="text-red-600 text-sm hover:underline">Избриши</button>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="py-4 px-4 text-gray-500">Нема додадено журнали.</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>
</div>
```

Note: `wire:confirm` is a built-in Livewire 3 directive (ships with the framework already used in this project's version) that shows the browser's native confirm dialog before dispatching the action — no custom JS needed.

- [ ] **Step 5: Register the route**

In `routes/web.php`, add `use App\Livewire\Accounting\JournalGroupIndex;` to the imports, then inside the existing `accounting.` route group (right after the `accounts.index` line):

```php
    Route::get('/journal-groups', [JournalGroupIndex::class, '__invoke'])->name('journal-groups.index');
```

- [ ] **Step 6: Add the sidebar link**

In `resources/views/livewire/layout/sidebar.blade.php`, inside the Accounting submenu block (right after the "Контен план" link, before "Налози"):

```blade
                        <a href="{{ route('accounting.journal-groups.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('accounting.journal-groups.*') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Журнали</a>
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --filter=JournalGroupIndexTest`
Expected: all 6 tests PASS.

- [ ] **Step 8: Run the full suite and commit**

Run: `php artisan test`
Expected: all green.

```bash
git add app/Livewire/Accounting/JournalGroupIndex.php resources/views/livewire/accounting/journal-group-index.blade.php tests/Feature/JournalGroupIndexTest.php routes/web.php resources/views/livewire/layout/sidebar.blade.php
git commit -m "feat: add Journal Groups settings screen"
```

---

### Task 3: Per-journal numbering on journal_entries + migration backfill

**Files:**
- Create: `database/migrations/2026_07_28_100100_add_journal_group_id_to_journal_entries_table.php`
- Modify: `app/Models/JournalEntry.php`
- Modify: `database/factories/JournalEntryFactory.php`
- Modify: `app/Livewire/Accounting/JournalEntryIndex.php`
- Modify: `resources/views/livewire/accounting/journal-entry-index.blade.php`
- Modify: `tests/Unit/JournalEntryTest.php`
- Modify: `tests/Feature/JournalEntryIndexTest.php`

**Interfaces:**
- Consumes: `JournalGroup` (Task 1).
- Produces: `JournalEntry::journalGroup(): BelongsTo`, `JournalEntry::displayNumber(): string` (returns `"{code}-{entry_number padded to 4 digits}"`) — consumed by every later task that displays or navigates entries. `JournalEntryFactory` now auto-provisions a `journal_group_id` when the caller doesn't supply one, so every other test file in the app that uses `JournalEntry::factory()` keeps working unchanged.

- [ ] **Step 1: Write the migration**

```php
<?php

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('journal_group_id')->nullable()->after('company_id')->constrained();
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'fiscal_year', 'entry_number']);
        });

        // Every existing entry predates journal groups — fold them all into
        // one auto-created "00 — Стари налози" group per company and
        // renumber sequentially per fiscal year within that group, so the
        // new unique index below never collides.
        Company::query()->get()->each(function (Company $company) {
            $legacyGroup = JournalGroup::create([
                'company_id' => $company->id,
                'code' => '00',
                'name' => 'Стари налози',
                'sort_order' => 0,
            ]);

            JournalEntry::where('company_id', $company->id)
                ->orderBy('fiscal_year')
                ->orderBy('entry_date')
                ->orderBy('id')
                ->get()
                ->groupBy('fiscal_year')
                ->each(function ($entriesInYear) use ($legacyGroup) {
                    $number = 0;
                    foreach ($entriesInYear as $entry) {
                        $number++;
                        $entry->update([
                            'journal_group_id' => $legacyGroup->id,
                            'entry_number' => $number,
                        ]);
                    }
                });
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unique(['company_id', 'fiscal_year', 'journal_group_id', 'entry_number']);
        });
    }

    public function down(): void
    {
        // Intentional no-op: collapsing back would mean deleting the
        // auto-created "00" journal groups and reverting entry_number to
        // its pre-migration values, which this migration doesn't retain
        // anywhere. Matches this project's established precedent for lossy
        // backfill migrations (see the company_bank_accounts migration from
        // the Company Profile sub-project).
    }
};
```

- [ ] **Step 2: Update the JournalEntry model**

In `app/Models/JournalEntry.php`, change the `$fillable` array and `booted()` method:

```php
    protected $fillable = ['company_id', 'journal_group_id', 'entry_date', 'description', 'created_by'];
```

```php
    protected static function booted(): void
    {
        static::creating(function (JournalEntry $entry) {
            $entry->fiscal_year = Carbon::parse($entry->entry_date)->year;

            $max = static::where('company_id', $entry->company_id)
                ->where('fiscal_year', $entry->fiscal_year)
                ->where('journal_group_id', $entry->journal_group_id)
                ->lockForUpdate()
                ->max('entry_number');

            $entry->entry_number = ($max ?? 0) + 1;
        });
    }
```

Add the relation and display helper (anywhere among the other relation methods):

```php
    public function journalGroup(): BelongsTo
    {
        return $this->belongsTo(JournalGroup::class);
    }

    public function displayNumber(): string
    {
        return sprintf('%s-%04d', $this->journalGroup->code, $this->entry_number);
    }
```

- [ ] **Step 3: Update the factory to auto-provision a journal group**

Replace `database/factories/JournalEntryFactory.php` entirely:

```php
<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class JournalEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'entry_date' => $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'description' => $this->faker->sentence(),
            'created_by' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (JournalEntry $entry) {
            if (! $entry->journal_group_id) {
                $entry->journal_group_id = JournalGroup::factory()->create(['company_id' => $entry->company_id])->id;
            }
        });
    }
}
```

- [ ] **Step 4: Fix the three raw `JournalEntry::create()` calls in the Unit test**

Replace `tests/Unit/JournalEntryTest.php` entirely:

```php
<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_entry_number_and_fiscal_year_are_assigned_automatically(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $user = User::factory()->create();

        $first = JournalEntry::create([
            'company_id' => $company->id,
            'journal_group_id' => $group->id,
            'entry_date' => '2026-03-15',
            'description' => 'First entry',
            'created_by' => $user->id,
        ]);

        $second = JournalEntry::create([
            'company_id' => $company->id,
            'journal_group_id' => $group->id,
            'entry_date' => '2026-06-01',
            'description' => 'Second entry',
            'created_by' => $user->id,
        ]);

        $this->assertSame(2026, $first->fiscal_year);
        $this->assertSame(1, $first->entry_number);
        $this->assertSame(2, $second->entry_number);
    }

    public function test_entry_numbering_resets_per_fiscal_year(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $user = User::factory()->create();

        JournalEntry::create(['company_id' => $company->id, 'journal_group_id' => $group->id, 'entry_date' => '2025-12-31', 'description' => 'Old year', 'created_by' => $user->id]);
        $newYearEntry = JournalEntry::create(['company_id' => $company->id, 'journal_group_id' => $group->id, 'entry_date' => '2026-01-01', 'description' => 'New year', 'created_by' => $user->id]);

        $this->assertSame(1, $newYearEntry->entry_number);
        $this->assertSame(2026, $newYearEntry->fiscal_year);
    }

    public function test_entry_numbering_is_independent_per_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $groupA = JournalGroup::factory()->for($companyA)->create();
        $groupB = JournalGroup::factory()->for($companyB)->create();
        $user = User::factory()->create();

        JournalEntry::create(['company_id' => $companyA->id, 'journal_group_id' => $groupA->id, 'entry_date' => '2026-01-01', 'description' => 'A1', 'created_by' => $user->id]);
        $bEntry = JournalEntry::create(['company_id' => $companyB->id, 'journal_group_id' => $groupB->id, 'entry_date' => '2026-01-01', 'description' => 'B1', 'created_by' => $user->id]);

        $this->assertSame(1, $bEntry->entry_number);
    }

    public function test_entry_numbering_is_independent_per_journal_group(): void
    {
        $company = Company::factory()->create();
        $groupA = JournalGroup::factory()->for($company)->create(['code' => '10']);
        $groupB = JournalGroup::factory()->for($company)->create(['code' => '20']);
        $user = User::factory()->create();

        JournalEntry::create(['company_id' => $company->id, 'journal_group_id' => $groupA->id, 'entry_date' => '2026-01-01', 'description' => 'A1', 'created_by' => $user->id]);
        JournalEntry::create(['company_id' => $company->id, 'journal_group_id' => $groupA->id, 'entry_date' => '2026-01-02', 'description' => 'A2', 'created_by' => $user->id]);
        $firstInGroupB = JournalEntry::create(['company_id' => $company->id, 'journal_group_id' => $groupB->id, 'entry_date' => '2026-01-03', 'description' => 'B1', 'created_by' => $user->id]);

        $this->assertSame(1, $firstInGroupB->entry_number);
    }

    public function test_display_number_combines_group_code_and_padded_entry_number(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create(['code' => '10']);
        $entry = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id]);

        $this->assertSame('10-'.str_pad((string) $entry->entry_number, 4, '0', STR_PAD_LEFT), $entry->displayNumber());
    }

    public function test_is_balanced_returns_true_when_debits_equal_credits(): void
    {
        $company = Company::factory()->create();
        $cash = Account::factory()->for($company)->create(['code' => '1001']);
        $revenue = Account::factory()->for($company)->create(['code' => '7401']);
        $entry = JournalEntry::factory()->for($company)->create();

        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $revenue->id, 'debit' => 0, 'credit' => 1000]);

        $this->assertTrue($entry->isBalanced());
    }

    public function test_is_balanced_returns_false_when_debits_do_not_equal_credits(): void
    {
        $company = Company::factory()->create();
        $cash = Account::factory()->for($company)->create(['code' => '1001']);
        $revenue = Account::factory()->for($company)->create(['code' => '7401']);
        $entry = JournalEntry::factory()->for($company)->create();

        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $revenue->id, 'debit' => 0, 'credit' => 900]);

        $this->assertFalse($entry->isBalanced());
    }
}
```

- [ ] **Step 5: Update JournalEntryIndex to display the new number format**

In `app/Livewire/Accounting/JournalEntryIndex.php`, change the query in `render()`:

```php
        $entries = JournalEntry::where('company_id', $this->company->id)
            ->with(['creator', 'journalGroup'])
            ->orderByDesc('entry_date')
            ->orderByDesc('entry_number')
            ->paginate(25);
```

In `resources/views/livewire/accounting/journal-entry-index.blade.php`, change the `#` cell:

```blade
                    <td class="py-2 px-4 font-mono">{{ $entry->displayNumber() }}</td>
```

- [ ] **Step 6: Update JournalEntryIndexTest to expect the new format**

In `tests/Feature/JournalEntryIndexTest.php`, change `test_it_lists_the_companys_journal_entries`:

```php
    public function test_it_lists_the_companys_journal_entries(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $entry = JournalEntry::factory()->for($company)->create(['description' => 'Opening balances']);

        $this->actingAs($admin);

        Livewire::test(JournalEntryIndex::class, ['company' => $company])
            ->assertSee('Opening balances')
            ->assertSee($entry->displayNumber());
    }
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --filter=JournalEntryTest`
Run: `php artisan test --filter=JournalEntryIndexTest`
Expected: all PASS, including the two new numbering-independence/display-number tests.

- [ ] **Step 8: Run the full suite and commit**

Run: `php artisan test`
Expected: all green — every other file using `JournalEntry::factory()` (Feature tests across accounting/invoicing/documents) should be unaffected, since the factory now silently attaches a journal group when none is given.

```bash
git add database/migrations/2026_07_28_100100_add_journal_group_id_to_journal_entries_table.php app/Models/JournalEntry.php database/factories/JournalEntryFactory.php app/Livewire/Accounting/JournalEntryIndex.php resources/views/livewire/accounting/journal-entry-index.blade.php tests/Unit/JournalEntryTest.php tests/Feature/JournalEntryIndexTest.php
git commit -m "feat: scope journal entry numbering per journal group, with legacy-data migration"
```

---

### Task 4: Fix invoice GL-posting to assign a system journal group

**Why this task exists:** `SalesInvoiceService` and `PurchaseInvoiceService` each call `JournalEntry::create([...])` directly at three points (confirm/cancel/recordPayment), bypassing the form entirely. After Task 3, a `JournalEntry` with no `journal_group_id` renders a fatal error the moment anything calls `displayNumber()` (`$this->journalGroup->code` on a null relation) — which would break `JournalEntryIndex` and every future task that lists/prints entries, the instant an invoice is confirmed. This is a direct, necessary consequence of Task 3's schema change, not new scope.

**Files:**
- Modify: `app/Services/Invoicing/SalesInvoiceService.php`
- Modify: `app/Services/Invoicing/PurchaseInvoiceService.php`
- Modify: `tests/Unit/SalesInvoiceServiceTest.php`
- Modify: `tests/Unit/PurchaseInvoiceServiceTest.php`

**Interfaces:**
- Consumes: `JournalGroup` (Task 1), `JournalEntry::journalGroup()`/`displayNumber()` (Task 3).
- Produces: every invoice-posted `JournalEntry` now has a valid `journal_group_id` pointing at a per-company `code => '99', name => 'Автоматски (фактури)'` group, auto-created on first use via `firstOrCreate`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/SalesInvoiceServiceTest.php` (inside the existing class):

```php
    public function test_confirming_an_invoice_posts_to_the_system_journal_group(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $entry = $confirmed->journalEntry()->with('journalGroup')->first();
        $this->assertNotNull($entry->journalGroup);
        $this->assertSame('99', $entry->journalGroup->code);
        $this->assertSame('Автоматски (фактури)', $entry->journalGroup->name);
    }

    public function test_confirming_two_invoices_for_the_same_company_reuses_the_same_system_group(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();

        $first = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $first->lines()->create(['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);
        $second = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-02']);
        $second->lines()->create(['description' => 'B', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $confirmedFirst = $this->service->confirm($first->fresh(), $user->id);
        $confirmedSecond = $this->service->confirm($second->fresh(), $user->id);

        $this->assertSame(
            $confirmedFirst->journalEntry->journal_group_id,
            $confirmedSecond->journalEntry->journal_group_id
        );
        $this->assertDatabaseCount('journal_groups', 1);
    }
```

Add the equivalent pair to `tests/Unit/PurchaseInvoiceServiceTest.php`, adapting to that file's existing `$this->service`/`seedAccounts()`/factory setup (same assertions against `PurchaseInvoice::confirm()`).

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=SalesInvoiceServiceTest`
Run: `php artisan test --filter=PurchaseInvoiceServiceTest`
Expected: the new tests FAIL — `journal_group_id` is currently null on invoice-posted entries, so `$entry->journalGroup` is null and the assertions blow up.

- [ ] **Step 3: Fix SalesInvoiceService**

In `app/Services/Invoicing/SalesInvoiceService.php`, add the import:

```php
use App\Models\JournalGroup;
```

Add this private method (next to the existing `account()` helper at the bottom of the class):

```php
    private function systemJournalGroup(Company $company): JournalGroup
    {
        return JournalGroup::firstOrCreate(
            ['company_id' => $company->id, 'code' => '99'],
            ['name' => 'Автоматски (фактури)', 'sort_order' => 99]
        );
    }
```

Add `'journal_group_id' => $this->systemJournalGroup($invoice->company)->id,` to each of the three `JournalEntry::create([...])` arrays (in `confirm()`, `cancel()`, and `recordPayment()`) — e.g. the one in `confirm()` becomes:

```php
            $entry = JournalEntry::create([
                'company_id' => $invoice->company_id,
                'journal_group_id' => $this->systemJournalGroup($invoice->company)->id,
                'entry_date' => $invoice->invoice_date,
                'description' => "Sales {$label}",
                'created_by' => $userId,
            ]);
```

Apply the same one-line addition to the `$reversal = JournalEntry::create([...])` call in `cancel()` and the `$entry = JournalEntry::create([...])` call in `recordPayment()`.

- [ ] **Step 4: Fix PurchaseInvoiceService**

Apply the identical change to `app/Services/Invoicing/PurchaseInvoiceService.php`: add the `use App\Models\JournalGroup;` import, add the same `systemJournalGroup()` private method next to its own `account()` helper, and add `'journal_group_id' => $this->systemJournalGroup($invoice->company)->id,` to its three `JournalEntry::create([...])` calls in `confirm()`, `cancel()`, and `recordPayment()`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=SalesInvoiceServiceTest`
Run: `php artisan test --filter=PurchaseInvoiceServiceTest`
Expected: all PASS.

- [ ] **Step 6: Run the full suite and commit**

Run: `php artisan test`
Expected: all green.

```bash
git add app/Services/Invoicing/SalesInvoiceService.php app/Services/Invoicing/PurchaseInvoiceService.php tests/Unit/SalesInvoiceServiceTest.php tests/Unit/PurchaseInvoiceServiceTest.php
git commit -m "fix: assign a system journal group to invoice-posted GL entries"
```

---

### Task 5: Journal group picker in the entry form

**Files:**
- Modify: `app/Livewire/Accounting/JournalEntryForm.php`
- Modify: `resources/views/livewire/accounting/journal-entry-form.blade.php`
- Modify: `tests/Feature/JournalEntryFormTest.php`

**Interfaces:**
- Consumes: `JournalGroup` (Task 1), `JournalEntry::journalGroup()` (Task 3).
- Produces: `public string $journalGroupId` property — required on create, ignored on edit (group is immutable once an entry exists). Consumed by Task 9 (navigator needs `$journalEntry->journal_group_id`) and Task 12 (PDF filename/header).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/JournalEntryFormTest.php` (add the `use App\Models\JournalGroup;` import at the top):

```php
    public function test_creating_an_entry_requires_a_journal_group(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1000')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '1000')
            ->call('save')
            ->assertHasErrors('journalGroupId');

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_creating_an_entry_assigns_the_selected_group_and_numbers_within_it(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create(['code' => '10']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
            ->set('journalGroupId', $group->id)
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1000')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $entry = JournalEntry::where('company_id', $company->id)->firstOrFail();
        $this->assertSame($group->id, $entry->journal_group_id);
        $this->assertSame(1, $entry->entry_number);
    }

    public function test_numbering_is_independent_across_two_journal_groups(): void
    {
        $company = Company::factory()->create();
        $groupA = JournalGroup::factory()->for($company)->create(['code' => '10']);
        $groupB = JournalGroup::factory()->for($company)->create(['code' => '20']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')->set('journalGroupId', $groupA->id)
            ->set('lines.0.account_id', $cash->id)->set('lines.0.debit', '1000')
            ->set('lines.1.account_id', $revenue->id)->set('lines.1.credit', '1000')
            ->call('save');

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-16')->set('journalGroupId', $groupB->id)
            ->set('lines.0.account_id', $cash->id)->set('lines.0.debit', '500')
            ->set('lines.1.account_id', $revenue->id)->set('lines.1.credit', '500')
            ->call('save');

        $entryInB = JournalEntry::where('journal_group_id', $groupB->id)->firstOrFail();
        $this->assertSame(1, $entryInB->entry_number);
    }

    public function test_the_journal_group_cannot_be_changed_when_editing_an_existing_entry(): void
    {
        $company = Company::factory()->create();
        $originalGroup = JournalGroup::factory()->for($company)->create(['code' => '10']);
        $otherGroup = JournalGroup::factory()->for($company)->create(['code' => '20']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();
        $entry = JournalEntry::factory()->for($company)->create(['journal_group_id' => $originalGroup->id, 'created_by' => $admin->id]);
        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 500, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $revenue->id, 'debit' => 0, 'credit' => 500]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry])
            ->set('journalGroupId', $otherGroup->id)
            ->set('lines.0.debit', '750')
            ->set('lines.1.credit', '750')
            ->call('save')
            ->assertHasNoErrors();

        $entry->refresh();
        $this->assertSame($originalGroup->id, $entry->journal_group_id);
    }
```

Update the six existing tests that create a new entry via `save()` so they still pass — each needs a group created and set. Replace these six methods in place with:

```php
    public function test_a_balanced_entry_saves_and_posts_immediately(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
            ->set('journalGroupId', $group->id)
            ->set('description', 'Cash sale')
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1000')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '1000')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('accounting.journal-entries.index', $company));

        $entry = JournalEntry::where('company_id', $company->id)->where('description', 'Cash sale')->firstOrFail();
        $this->assertTrue($entry->isBalanced());
        $this->assertCount(2, $entry->lines);
    }

    public function test_an_unbalanced_entry_is_rejected(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
            ->set('journalGroupId', $group->id)
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1000')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '900')
            ->call('save')
            ->assertHasErrors('lines');

        $this->assertDatabaseCount('journal_entries', 0);
    }
```

```php
    public function test_an_account_belonging_to_a_different_company_is_rejected(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $foreignRevenue = Account::where('company_id', $otherCompany->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
            ->set('journalGroupId', $group->id)
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1000')
            ->set('lines.1.account_id', $foreignRevenue->id)
            ->set('lines.1.credit', '1000')
            ->call('save')
            ->assertHasErrors(['lines.1.account_id']);

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_a_partner_belonging_to_a_different_company_is_rejected(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();
        $foreignPartner = Partner::factory()->for($otherCompany)->create();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
            ->set('journalGroupId', $group->id)
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1000')
            ->set('lines.0.partner_id', $foreignPartner->id)
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '1000')
            ->call('save')
            ->assertHasErrors(['lines.0.partner_id']);

        $this->assertDatabaseCount('journal_entries', 0);
    }
```

```php
    public function test_a_foreign_currency_line_without_a_fetched_rate_computes_mkd_value_on_save(): void
    {
        Http::fake([
            'nbrm.mk/*' => Http::response([
                ['oznaka' => 'EUR', 'sreden' => 61.6917, 'nomin' => 1, 'datum' => '2026-07-01T00:00:00'],
            ], 200),
        ]);

        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-07-01')
            ->set('journalGroupId', $group->id)
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.currency_code', 'EUR')
            ->set('lines.0.foreign_amount', '100')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '6169.17')
            ->call('save')
            ->assertHasNoErrors();

        $entry = JournalEntry::where('company_id', $company->id)->firstOrFail();
        $line = $entry->lines()->where('account_id', $cash->id)->firstOrFail();
        $this->assertSame('6169.17', $line->debit);
        $this->assertSame('0.00', $line->credit);
    }

    public function test_a_line_with_both_debit_and_credit_nonzero_is_rejected(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
            ->set('journalGroupId', $group->id)
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1000')
            ->set('lines.0.credit', '1000')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '1000')
            ->call('save')
            ->assertHasErrors('lines');

        $this->assertDatabaseCount('journal_entries', 0);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=JournalEntryFormTest`
Expected: the 4 new tests FAIL (no `journalGroupId` property exists); the 6 updated tests still PASS at this point since setting a non-existent public property via Livewire's `set()` is silently ignored, not an error — so they were unaffected by having no group before this task, and will start requiring it once Step 3 lands.

- [ ] **Step 3: Add the property and wire it into mount/save**

In `app/Livewire/Accounting/JournalEntryForm.php`, add the import and property:

```php
use App\Models\JournalGroup;
```

```php
    public string $journalGroupId = '';
```

In `mount()`, inside the `if ($journalEntry)` branch, add:

```php
            $this->journalGroupId = (string) $journalEntry->journal_group_id;
```

In `save()`, add to the validation array (right after `'entryDate' => 'required|date',`):

```php
            'journalGroupId' => [$this->journalEntry ? 'nullable' : 'required', Rule::exists('journal_groups', 'id')->where('company_id', $this->company->id)],
```

In the `DB::transaction()` closure, change the `if (! $entry->exists)` block to also assign the group only at creation time:

```php
            if (! $entry->exists) {
                $entry->created_by = auth()->id();
                $entry->journal_group_id = $this->journalGroupId;
            }
```

- [ ] **Step 4: Add the picker to the view**

In `resources/views/livewire/accounting/journal-entry-form.blade.php`, replace the top `<div class="grid grid-cols-2 gap-4 mb-4">` block with a three-column version that adds the group picker first:

```blade
        <div class="grid grid-cols-3 gap-4 mb-4">
            <div>
                <x-input-label for="journalGroupId" value="Журнал" />
                @if ($journalEntry)
                    <div class="text-sm text-gray-700 py-2">{{ $journalEntry->journalGroup->code }} — {{ $journalEntry->journalGroup->name }}</div>
                @else
                    <select id="journalGroupId" wire:model="journalGroupId" class="border-gray-300 rounded-md text-sm w-full">
                        <option value="">—</option>
                        @foreach ($groups->groupBy(fn ($g) => substr($g->code, 0, 1)) as $digit => $groupsInDigit)
                            <optgroup label="{{ $digit }}">
                                @foreach ($groupsInDigit as $g)
                                    <option value="{{ $g->id }}">{{ $g->code }} — {{ $g->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('journalGroupId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                @endif
            </div>
            <div>
                <x-input-label for="entryDate" value="Датум" />
                <input type="date" id="entryDate" wire:model="entryDate" class="border-gray-300 rounded-md shadow-sm w-full" @disabled(! $canEdit) />
                @error('entryDate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="description" value="Опис" />
                <x-text-input id="description" wire:model="description" class="w-full" @disabled(! $canEdit) />
            </div>
        </div>
```

Also change the page heading to use the new display format:

```blade
        {{ $journalEntry ? 'Измени налог '.$journalEntry->displayNumber() : 'Нов налог' }} — {{ $company->name }}
```

- [ ] **Step 5: Pass `$groups` from render()**

In `app/Livewire/Accounting/JournalEntryForm.php`, add to the array returned by `render()`:

```php
            'groups' => JournalGroup::where('company_id', $this->company->id)->orderBy('sort_order')->orderBy('code')->get(),
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=JournalEntryFormTest`
Expected: all tests PASS, including the 4 new ones.

Also update the one test in `tests/Feature/JournalEntryFormTest.php` that asserts the old heading string — `test_client_can_view_an_existing_entry_belonging_to_their_company` currently does `->assertSee('Измени налог #'.$entry->entry_number)`; change that line to `->assertSee('Измени налог '.$entry->displayNumber())`.

- [ ] **Step 7: Run the full suite and commit**

Run: `php artisan test`
Expected: all green.

```bash
git add app/Livewire/Accounting/JournalEntryForm.php resources/views/livewire/accounting/journal-entry-form.blade.php tests/Feature/JournalEntryFormTest.php
git commit -m "feat: add required journal group picker to the entry form, locked after creation"
```

---

### Task 6: Per-line dates with late-date highlighting

**Files:**
- Create: `database/migrations/2026_07_28_100200_add_line_date_to_journal_entry_lines_table.php`
- Modify: `app/Models/JournalEntryLine.php`
- Modify: `app/Livewire/Accounting/JournalEntryForm.php`
- Modify: `resources/views/livewire/accounting/journal-entry-form.blade.php`
- Modify: `tests/Feature/JournalEntryFormTest.php`

**Interfaces:**
- Produces: `JournalEntryLine::line_date` (date), defaults to the entry's `entryDate` on both new and existing lines. Consumed by Task 12's PDF (prints each line's own date).

- [ ] **Step 1: Write the migration**

```php
<?php

use App\Models\JournalEntryLine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->date('line_date')->nullable()->after('journal_entry_id');
        });

        JournalEntryLine::with('journalEntry')->get()->each(function (JournalEntryLine $line) {
            $line->update(['line_date' => $line->journalEntry->entry_date]);
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropColumn('line_date');
        });
    }
};
```

- [ ] **Step 2: Update the JournalEntryLine model**

In `app/Models/JournalEntryLine.php`, add `'line_date'` to `$fillable` and a cast:

```php
    protected $fillable = [
        'journal_entry_id', 'account_id', 'partner_id', 'description', 'line_date',
        'debit', 'credit', 'currency_code', 'exchange_rate', 'foreign_amount',
    ];

    protected function casts(): array
    {
        return [
            'line_date' => 'date',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'foreign_amount' => 'decimal:2',
        ];
    }
```

- [ ] **Step 3: Write the failing tests**

Add to `tests/Feature/JournalEntryFormTest.php`:

```php
    public function test_a_new_lines_date_defaults_to_the_entry_date(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-05-10')
            ->set('journalGroupId', $group->id)
            ->assertSet('lines.0.line_date', '2026-05-10');
    }

    public function test_saving_persists_each_lines_own_date(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-05-10')
            ->set('journalGroupId', $group->id)
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1000')
            ->set('lines.0.line_date', '2026-05-08')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $entry = JournalEntry::where('company_id', $company->id)->firstOrFail();
        $this->assertSame('2026-05-08', $entry->lines()->where('account_id', $cash->id)->first()->line_date->toDateString());
    }

    public function test_a_line_dated_after_the_entry_date_is_flagged_red(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        $component = Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-05-10')
            ->set('journalGroupId', $group->id)
            ->set('lines.0.line_date', '2026-05-15');

        $component->assertSeeHtml('bg-red-50');
    }

    public function test_a_line_dated_on_or_before_the_entry_date_is_not_flagged(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        $component = Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-05-10')
            ->set('journalGroupId', $group->id)
            ->set('lines.0.line_date', '2026-05-10');

        $component->assertDontSeeHtml('bg-red-50');
    }
```

- [ ] **Step 4: Run the tests to verify they fail**

Run: `php artisan test --filter=JournalEntryFormTest`
Expected: the 4 new tests FAIL — `line_date` isn't part of `emptyLine()`/`mount()`/`save()` yet, and no red-highlight markup exists.

- [ ] **Step 5: Wire line_date through the component**

In `app/Livewire/Accounting/JournalEntryForm.php`, update `emptyLine()`:

```php
    protected function emptyLine(): array
    {
        return [
            'account_id' => '',
            'partner_id' => '',
            'description' => '',
            'line_date' => $this->entryDate,
            'debit' => '0',
            'credit' => '0',
            'currency_code' => 'MKD',
            'exchange_rate' => '1',
            'foreign_amount' => null,
        ];
    }
```

In `mount()`, add `'line_date' => $line->line_date->toDateString(),` to the `$journalEntry->lines->map(...)` array (right after `'description' => $line->description,`).

In `save()`, add `'lines.*.line_date' => 'required|date',` to the validation array, and add `'line_date' => $line['line_date'],` to the `$entry->lines()->create([...])` array inside the `foreach ($this->lines as $line)` loop.

- [ ] **Step 6: Add the column and red-highlight to the view**

In `resources/views/livewire/accounting/journal-entry-form.blade.php`, add a header cell right after "Опис":

```blade
                    <th class="py-1 pr-2">Датум</th>
```

And a data cell in the same position, with the red-highlight condition:

```blade
                        @php $isLate = $line['line_date'] > $entryDate; @endphp
                        <td class="py-1 pr-2 {{ $isLate ? 'bg-red-50' : '' }}">
                            <input type="date" wire:model="lines.{{ $index }}.line_date"
                                   class="rounded-md text-sm {{ $isLate ? 'border-red-400 text-red-700' : 'border-gray-300' }}"
                                   @disabled(! $canEdit) />
                        </td>
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --filter=JournalEntryFormTest`
Expected: all tests PASS.

- [ ] **Step 8: Run the full suite and commit**

Run: `php artisan test`
Expected: all green.

```bash
git add database/migrations/2026_07_28_100200_add_line_date_to_journal_entry_lines_table.php app/Models/JournalEntryLine.php app/Livewire/Accounting/JournalEntryForm.php resources/views/livewire/accounting/journal-entry-form.blade.php tests/Feature/JournalEntryFormTest.php
git commit -m "feat: add per-line dates with red highlighting for dates after the entry date"
```

---

### Task 7: FX (devizni) whole-entry checkbox

**Files:**
- Modify: `app/Livewire/Accounting/JournalEntryForm.php`
- Modify: `resources/views/livewire/accounting/journal-entry-form.blade.php`
- Modify: `tests/Feature/JournalEntryFormTest.php`

**Interfaces:**
- Produces: `public bool $isForeignCurrency` — pure display toggle, no schema change (the currency/rate/foreign-amount fields already exist per line since Phase 1).

- [ ] **Step 1: Write the failing tests**

```php
    public function test_fx_checkbox_is_unchecked_by_default_for_a_new_entry(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->assertSet('isForeignCurrency', false)
            ->assertDontSeeHtml('lines.0.currency_code');
    }

    public function test_checking_the_fx_box_reveals_the_currency_columns(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('isForeignCurrency', true)
            ->assertSeeHtml('lines.0.currency_code');
    }

    public function test_fx_checkbox_auto_checks_when_loading_an_entry_with_a_foreign_currency_line(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $entry = JournalEntry::factory()->for($company)->create(['created_by' => $admin->id]);
        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 100, 'credit' => 0, 'currency_code' => 'EUR', 'exchange_rate' => '61.5', 'foreign_amount' => '1.63']);
        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 0, 'credit' => 100]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry])
            ->assertSet('isForeignCurrency', true);
    }

    public function test_fx_checkbox_stays_unchecked_when_loading_an_all_mkd_entry(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $entry = JournalEntry::factory()->for($company)->create(['created_by' => $admin->id]);
        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 100, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 0, 'credit' => 100]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry])
            ->assertSet('isForeignCurrency', false);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=JournalEntryFormTest`
Expected: FAIL — `isForeignCurrency` doesn't exist yet, and the currency columns are always rendered.

- [ ] **Step 3: Add the property and auto-check logic**

In `app/Livewire/Accounting/JournalEntryForm.php`, add the property:

```php
    public bool $isForeignCurrency = false;
```

In `mount()`, inside the `if ($journalEntry)` branch, after the `$this->lines = ...` assignment:

```php
            $this->isForeignCurrency = collect($this->lines)->contains(fn ($line) => $line['currency_code'] !== 'MKD');
```

- [ ] **Step 4: Make the currency columns conditional in the view**

In `resources/views/livewire/accounting/journal-entry-form.blade.php`, add the checkbox right below the 3-column grid added in Task 5 (before the `@error('lines')` line):

```blade
        <div class="mb-4">
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model.live="isForeignCurrency" @disabled(! $canEdit) />
                Овој налог е во девизи
            </label>
        </div>
```

Wrap the three FX-related header cells (Валута, Износ во валута, Курс) in `@if ($isForeignCurrency)`:

```blade
                    @if ($isForeignCurrency)
                        <th class="py-1 pr-2">Валута</th>
                        <th class="py-1 pr-2">Износ во валута</th>
                        <th class="py-1 pr-2">Курс</th>
                    @endif
```

And wrap the corresponding three `<td>` cells per row (currency select, foreign_amount input, exchange_rate+NBRM button) the same way:

```blade
                        @if ($isForeignCurrency)
                            <td class="py-1 pr-2">
                                <select wire:model="lines.{{ $index }}.currency_code" class="border-gray-300 rounded-md text-sm" @disabled(! $canEdit)>
                                    <option value="MKD">MKD</option>
                                    <option value="EUR">EUR</option>
                                    <option value="USD">USD</option>
                                    <option value="GBP">GBP</option>
                                    <option value="CHF">CHF</option>
                                </select>
                            </td>
                            <td class="py-1 pr-2"><input type="number" step="0.01" wire:model="lines.{{ $index }}.foreign_amount" class="border-gray-300 rounded-md text-sm w-20" @disabled(! $canEdit) /></td>
                            <td class="py-1 pr-2 flex items-center gap-1">
                                <input type="number" step="0.000001" wire:model="lines.{{ $index }}.exchange_rate" class="border-gray-300 rounded-md text-sm w-20" @disabled(! $canEdit) />
                                @if ($line['currency_code'] !== 'MKD' && $canEdit)
                                    <button type="button" wire:click="fetchRate({{ $index }})" class="text-xs text-brand hover:underline">НБРМ</button>
                                @endif
                            </td>
                        @endif
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=JournalEntryFormTest`
Expected: all PASS.

- [ ] **Step 6: Run the full suite and commit**

Run: `php artisan test`
Expected: all green.

```bash
git add app/Livewire/Accounting/JournalEntryForm.php resources/views/livewire/accounting/journal-entry-form.blade.php tests/Feature/JournalEntryFormTest.php
git commit -m "feat: add whole-entry FX checkbox that toggles currency columns"
```

---

### Task 8: Permanent delete button

**Files:**
- Modify: `app/Livewire/Accounting/JournalEntryForm.php`
- Modify: `resources/views/livewire/accounting/journal-entry-form.blade.php`
- Modify: `tests/Feature/JournalEntryFormTest.php`

**Interfaces:**
- Consumes: `JournalEntryPolicy::delete()` (already exists, no change needed).
- Produces: `delete()` action method — hard delete, blocked when the entry is linked to a `SalesInvoice`/`PurchaseInvoice` via `journal_entry_id` (that FK has no cascade rule, so an unguarded delete would throw a DB constraint violation).

- [ ] **Step 1: Write the failing tests**

```php
    public function test_admin_can_permanently_delete_a_journal_entry(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $entry = JournalEntry::factory()->for($company)->create(['created_by' => $admin->id]);
        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 100, 'credit' => 0]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry])
            ->call('delete')
            ->assertRedirect(route('accounting.journal-entries.index', $company));

        $this->assertDatabaseMissing('journal_entries', ['id' => $entry->id]);
        $this->assertDatabaseMissing('journal_entry_lines', ['journal_entry_id' => $entry->id]);
    }

    public function test_client_cannot_delete_a_journal_entry(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $entry = JournalEntry::factory()->for($company)->create();

        $this->actingAs($client);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry])
            ->call('delete')
            ->assertForbidden();

        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id]);
    }

    public function test_deleting_an_entry_linked_to_a_sales_invoice_is_blocked(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $partner = \App\Models\Partner::factory()->for($company)->create();
        $entry = JournalEntry::factory()->for($company)->create(['created_by' => $admin->id]);
        \App\Models\SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'journal_entry_id' => $entry->id]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry])
            ->call('delete')
            ->assertHasErrors('delete');

        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id]);
    }

    public function test_deleting_a_non_last_entry_leaves_a_numbering_gap(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $entry1 = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'entry_date' => '2026-01-01', 'created_by' => $admin->id]);
        $entry2 = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'entry_date' => '2026-01-02', 'created_by' => $admin->id]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry1])
            ->call('delete');

        $entry3 = JournalEntry::create([
            'company_id' => $company->id,
            'journal_group_id' => $group->id,
            'entry_date' => '2026-01-03',
            'description' => 'Third',
            'created_by' => $admin->id,
        ]);

        $this->assertGreaterThan($entry2->entry_number, $entry3->entry_number);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=JournalEntryFormTest`
Expected: FAIL — no `delete()` method exists on the component yet.

- [ ] **Step 3: Add the delete method**

In `app/Livewire/Accounting/JournalEntryForm.php`, add the imports:

```php
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
```

Add the method (near `save()`):

```php
    public function delete(): void
    {
        Gate::authorize('delete', $this->journalEntry);

        if (SalesInvoice::where('journal_entry_id', $this->journalEntry->id)->exists()
            || PurchaseInvoice::where('journal_entry_id', $this->journalEntry->id)->exists()) {
            $this->addError('delete', 'Овој налог е автоматски создаден од фактура и не може да се избрише директно — прво откажете ја фактурата.');

            return;
        }

        $company = $this->company;

        // Documents are a polymorphic relation with no FK constraint, so
        // they would otherwise survive as orphaned rows pointing at a
        // deleted entry.
        $this->journalEntry->documents()->delete();
        $this->journalEntry->delete();

        $this->redirect(route('accounting.journal-entries.index', $company));
    }
```

- [ ] **Step 4: Add the button to the view**

In `resources/views/livewire/accounting/journal-entry-form.blade.php`, add right after the `@error('lines')` line:

```blade
        @error('delete') <p class="text-red-600 text-sm mb-2">{{ $message }}</p> @enderror
```

And add the button next to the "Зачувај" button, inside the `@if ($canEdit)` block:

```blade
                <x-primary-button type="submit">Зачувај</x-primary-button>
                @if ($journalEntry)
                    <x-danger-button type="button" wire:click="delete" wire:confirm="Да се избрише трајно овој налог? Ова не може да се врати." class="ml-2">Избриши</x-danger-button>
                @endif
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=JournalEntryFormTest`
Expected: all PASS.

- [ ] **Step 6: Run the full suite and commit**

Run: `php artisan test`
Expected: all green.

```bash
git add app/Livewire/Accounting/JournalEntryForm.php resources/views/livewire/accounting/journal-entry-form.blade.php tests/Feature/JournalEntryFormTest.php
git commit -m "feat: add permanent delete button, blocked when linked to an invoice"
```

---

### Task 9: Prev/next/first/last navigator

**Files:**
- Modify: `app/Livewire/Accounting/JournalEntryForm.php`
- Modify: `resources/views/livewire/accounting/journal-entry-form.blade.php`
- Modify: `tests/Feature/JournalEntryFormTest.php`

**Interfaces:**
- Consumes: `JournalEntry::journal_group_id`/`fiscal_year`/`entry_number` (Task 3).
- Produces: `goToFirst()`/`goToPrevious()`/`goToNext()`/`goToLast()` action methods; `hasPrevious`/`hasNext` booleans passed from `render()` for button disabling.

- [ ] **Step 1: Write the failing tests**

```php
    public function test_next_navigates_to_the_next_entry_in_the_same_journal_group(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $entry1 = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'entry_date' => '2026-01-01', 'created_by' => $admin->id]);
        $entry2 = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'entry_date' => '2026-01-02', 'created_by' => $admin->id]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry1])
            ->call('goToNext')
            ->assertRedirect(route('accounting.journal-entries.edit', [$company, $entry2]));
    }

    public function test_previous_navigates_to_the_previous_entry_in_the_same_journal_group(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $entry1 = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'entry_date' => '2026-01-01', 'created_by' => $admin->id]);
        $entry2 = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'entry_date' => '2026-01-02', 'created_by' => $admin->id]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry2])
            ->call('goToPrevious')
            ->assertRedirect(route('accounting.journal-entries.edit', [$company, $entry1]));
    }

    public function test_navigator_does_not_cross_into_a_different_journal_group(): void
    {
        $company = Company::factory()->create();
        $groupA = JournalGroup::factory()->for($company)->create(['code' => '10']);
        $groupB = JournalGroup::factory()->for($company)->create(['code' => '20']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $entryInA = JournalEntry::factory()->for($company)->create(['journal_group_id' => $groupA->id, 'entry_date' => '2026-01-01', 'created_by' => $admin->id]);
        JournalEntry::factory()->for($company)->create(['journal_group_id' => $groupB->id, 'entry_date' => '2026-01-02', 'created_by' => $admin->id]);

        $this->actingAs($admin);

        $before = JournalEntry::count();

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entryInA])
            ->call('goToNext');

        $this->assertSame($before, JournalEntry::count());
    }

    public function test_navigator_rolls_from_the_last_entry_of_one_fiscal_year_into_the_next_years_first_entry(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $lastOf2025 = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'entry_date' => '2025-12-31', 'created_by' => $admin->id]);
        $firstOf2026 = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'entry_date' => '2026-01-01', 'created_by' => $admin->id]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $lastOf2025])
            ->call('goToNext')
            ->assertRedirect(route('accounting.journal-entries.edit', [$company, $firstOf2026]));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=JournalEntryFormTest`
Expected: FAIL — no navigator methods exist yet.

- [ ] **Step 3: Add the navigator methods**

In `app/Livewire/Accounting/JournalEntryForm.php`, add:

```php
    public function goToFirst(): void
    {
        $this->navigateTo($this->firstEntry());
    }

    public function goToPrevious(): void
    {
        $this->navigateTo($this->previousEntry());
    }

    public function goToNext(): void
    {
        $this->navigateTo($this->nextEntry());
    }

    public function goToLast(): void
    {
        $this->navigateTo($this->lastEntry());
    }

    private function firstEntry(): ?JournalEntry
    {
        if (! $this->journalEntry) {
            return null;
        }

        return JournalEntry::where('company_id', $this->company->id)
            ->where('journal_group_id', $this->journalEntry->journal_group_id)
            ->orderBy('fiscal_year')->orderBy('entry_number')
            ->first();
    }

    private function lastEntry(): ?JournalEntry
    {
        if (! $this->journalEntry) {
            return null;
        }

        return JournalEntry::where('company_id', $this->company->id)
            ->where('journal_group_id', $this->journalEntry->journal_group_id)
            ->orderByDesc('fiscal_year')->orderByDesc('entry_number')
            ->first();
    }

    private function previousEntry(): ?JournalEntry
    {
        if (! $this->journalEntry) {
            return null;
        }

        return JournalEntry::where('company_id', $this->company->id)
            ->where('journal_group_id', $this->journalEntry->journal_group_id)
            ->where(fn ($q) => $q->where('fiscal_year', '<', $this->journalEntry->fiscal_year)
                ->orWhere(fn ($q) => $q->where('fiscal_year', $this->journalEntry->fiscal_year)->where('entry_number', '<', $this->journalEntry->entry_number)))
            ->orderByDesc('fiscal_year')->orderByDesc('entry_number')
            ->first();
    }

    private function nextEntry(): ?JournalEntry
    {
        if (! $this->journalEntry) {
            return null;
        }

        return JournalEntry::where('company_id', $this->company->id)
            ->where('journal_group_id', $this->journalEntry->journal_group_id)
            ->where(fn ($q) => $q->where('fiscal_year', '>', $this->journalEntry->fiscal_year)
                ->orWhere(fn ($q) => $q->where('fiscal_year', $this->journalEntry->fiscal_year)->where('entry_number', '>', $this->journalEntry->entry_number)))
            ->orderBy('fiscal_year')->orderBy('entry_number')
            ->first();
    }

    private function navigateTo(?JournalEntry $target): void
    {
        if ($target) {
            $this->redirect(route('accounting.journal-entries.edit', [$this->company, $target]));
        }
    }
```

- [ ] **Step 4: Expose hasPrevious/hasNext and add the buttons**

In `render()`, add to the returned array:

```php
            'hasPrevious' => $this->previousEntry() !== null,
            'hasNext' => $this->nextEntry() !== null,
```

In the view, add the navigator next to the heading, only when editing an existing entry:

```blade
        @if ($journalEntry)
            <div class="flex gap-1 text-sm">
                <button type="button" wire:click="goToFirst" @disabled(! $hasPrevious) class="px-2 py-1 border rounded-md disabled:opacity-30">⏮</button>
                <button type="button" wire:click="goToPrevious" @disabled(! $hasPrevious) class="px-2 py-1 border rounded-md disabled:opacity-30">◁</button>
                <button type="button" wire:click="goToNext" @disabled(! $hasNext) class="px-2 py-1 border rounded-md disabled:opacity-30">▷</button>
                <button type="button" wire:click="goToLast" @disabled(! $hasNext) class="px-2 py-1 border rounded-md disabled:opacity-30">⏭</button>
            </div>
        @endif
```

Place this `<div>` inside the same header row as the `<h1>` (wrap both in a `flex items-center justify-between` container).

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=JournalEntryFormTest`
Expected: all PASS.

- [ ] **Step 6: Run the full suite and commit**

Run: `php artisan test`
Expected: all green.

```bash
git add app/Livewire/Accounting/JournalEntryForm.php resources/views/livewire/accounting/journal-entry-form.blade.php tests/Feature/JournalEntryFormTest.php
git commit -m "feat: add prev/next/first/last navigator scoped to the current journal group"
```

---

### Task 10: Alpine-driven autocomplete for account and partner

**Files:**
- Create: `resources/js/journal-entry-picker.js`
- Modify: `resources/js/app.js`
- Modify: `app/Livewire/Accounting/JournalEntryForm.php`
- Modify: `resources/views/livewire/accounting/journal-entry-form.blade.php`
- Modify: `tests/Feature/JournalEntryFormTest.php`

**Interfaces:**
- Produces: an `Alpine.data('journalEntryPicker', ...)` factory taking `(items, wireModel, initialLabel)` — reusable for both account and partner cells.

- [ ] **Step 1: Write the Alpine component**

```js
document.addEventListener('alpine:init', () => {
    Alpine.data('journalEntryPicker', (items, wireModel, initialLabel) => ({
        items,
        query: initialLabel ?? '',
        open: false,

        get filtered() {
            const q = this.query.trim().toLowerCase();
            if (q === '') {
                return [];
            }
            return this.items
                .filter((item) => item.label.toLowerCase().includes(q))
                .slice(0, 15);
        },

        select(item) {
            this.query = item.label;
            this.open = false;
            this.$wire.set(wireModel, item.id);
        },
    }));
});
```

- [ ] **Step 2: Register it**

In `resources/js/app.js`, add the import (this file currently only has `import './barcode-scanner';`):

```js
import './barcode-scanner';
import './journal-entry-picker';
```

- [ ] **Step 3: Write the failing test for the view data shape**

```php
    public function test_render_exposes_account_and_partner_options_for_the_autocomplete(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $partner = \App\Models\Partner::factory()->for($company)->create(['name' => 'ABC Trading']);

        $this->actingAs($admin);

        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->assertViewHas('accountsForJs', fn ($accounts) => collect($accounts)->contains(fn ($a) => $a['id'] === $cash->id && str_contains($a['label'], $cash->code)))
            ->assertViewHas('partnersForJs', fn ($partners) => collect($partners)->contains(fn ($p) => $p['id'] === $partner->id && $p['label'] === 'ABC Trading'));
    }
```

- [ ] **Step 4: Run the test to verify it fails**

Run: `php artisan test --filter=JournalEntryFormTest`
Expected: FAIL — `accountsForJs`/`partnersForJs` aren't exposed yet.

- [ ] **Step 5: Expose the JS-friendly collections and replace the selects**

In `app/Livewire/Accounting/JournalEntryForm.php`, add to the array returned by `render()` (reusing the already-fetched `$accounts`/`$partners` variables — restructure `render()` to build them once, then map):

```php
    public function render()
    {
        $accounts = Account::where('company_id', $this->company->id)->where('is_active', true)->orderBy('code')->get();
        $partners = Partner::where('company_id', $this->company->id)->orderBy('name')->get();

        return view('livewire.accounting.journal-entry-form', [
            'accounts' => $accounts,
            'partners' => $partners,
            'accountsForJs' => $accounts->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->code} — {$a->name}"])->values(),
            'partnersForJs' => $partners->map(fn ($p) => ['id' => $p->id, 'label' => $p->name])->values(),
            'groups' => JournalGroup::where('company_id', $this->company->id)->orderBy('sort_order')->orderBy('code')->get(),
            'hasPrevious' => $this->previousEntry() !== null,
            'hasNext' => $this->nextEntry() !== null,
        ]);
    }
```

In `resources/views/livewire/accounting/journal-entry-form.blade.php`, replace the account `<select>` cell:

```blade
                        @php
                            $selectedAccount = $accounts->firstWhere('id', $line['account_id']);
                            $accountLabel = $selectedAccount ? $selectedAccount->code.' — '.$selectedAccount->name : '';
                        @endphp
                        <td class="py-1 pr-2 relative" x-data="journalEntryPicker(@js($accountsForJs), 'lines.{{ $index }}.account_id', @js($accountLabel))" @click.outside="open = false">
                            <input type="text" x-model="query" @focus="open = true" @input="open = true"
                                   placeholder="Код или име..." class="border-gray-300 rounded-md text-sm w-40" @disabled(! $canEdit) />
                            <div x-show="open && filtered.length" x-cloak class="absolute z-10 bg-white border border-gray-200 rounded-md shadow-md mt-1 max-h-48 overflow-y-auto w-64">
                                <template x-for="item in filtered" :key="item.id">
                                    <div @click="select(item)" class="px-2 py-1 text-sm hover:bg-gray-100 cursor-pointer" x-text="item.label"></div>
                                </template>
                            </div>
                        </td>
```

And the partner `<select>` cell:

```blade
                        @php
                            $selectedPartner = $partners->firstWhere('id', $line['partner_id']);
                            $partnerLabel = $selectedPartner?->name ?? '';
                        @endphp
                        <td class="py-1 pr-2 relative" x-data="journalEntryPicker(@js($partnersForJs), 'lines.{{ $index }}.partner_id', @js($partnerLabel))" @click.outside="open = false">
                            <input type="text" x-model="query" @focus="open = true" @input="open = true"
                                   placeholder="Партнер..." class="border-gray-300 rounded-md text-sm w-40" @disabled(! $canEdit) />
                            <div x-show="open && filtered.length" x-cloak class="absolute z-10 bg-white border border-gray-200 rounded-md shadow-md mt-1 max-h-48 overflow-y-auto w-64">
                                <template x-for="item in filtered" :key="item.id">
                                    <div @click="select(item)" class="px-2 py-1 text-sm hover:bg-gray-100 cursor-pointer" x-text="item.label"></div>
                                </template>
                            </div>
                        </td>
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=JournalEntryFormTest`
Expected: all PASS. The existing tests that `->set('lines.0.account_id', ...)`/`->set('lines.0.partner_id', ...)` directly still work unchanged — the Alpine layer only affects how a real browser sets those same underlying properties, which Livewire's test helper bypasses entirely by design.

- [ ] **Step 7: Run the full suite and commit**

Run: `php artisan test`
Expected: all green.

**Manual verification note:** this task's actual UX (typing, filtering, clicking a suggestion) can't be exercised by PHPUnit — it must be checked in a real browser before this sub-project is considered done (see the final manual verification step in Task 12).

```bash
git add resources/js/journal-entry-picker.js resources/js/app.js app/Livewire/Accounting/JournalEntryForm.php resources/views/livewire/accounting/journal-entry-form.blade.php tests/Feature/JournalEntryFormTest.php
git commit -m "feat: add Alpine-driven autocomplete for account and partner selection"
```

---

### Task 11: Sticky totals footer + responsive layout

**Files:**
- Modify: `app/Livewire/Accounting/JournalEntryForm.php`
- Modify: `resources/views/livewire/accounting/journal-entry-form.blade.php`
- Modify: `tests/Feature/JournalEntryFormTest.php`

**Interfaces:**
- Produces: `totalDebit`/`totalCredit` view data (live-recomputed on every render, since Livewire re-renders on every `wire:model` change).

- [ ] **Step 1: Write the failing tests**

```php
    public function test_footer_shows_running_totals(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1500')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '1000')
            ->assertSeeText(\App\Support\Format::money('1500.00'))
            ->assertSeeText(\App\Support\Format::money('1000.00'));
    }

    public function test_footer_flags_red_when_unbalanced(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '500')
            ->assertSeeHtml('text-red-600');
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=JournalEntryFormTest`
Expected: FAIL — no footer exists yet.

- [ ] **Step 3: Compute the totals**

In `app/Livewire/Accounting/JournalEntryForm.php`, add to the array returned by `render()`:

```php
            'totalDebit' => collect($this->lines)->sum(fn ($line) => (float) $line['debit']),
            'totalCredit' => collect($this->lines)->sum(fn ($line) => (float) $line['credit']),
```

- [ ] **Step 4: Add the sticky footer to the view**

In `resources/views/livewire/accounting/journal-entry-form.blade.php`, add right before the closing `</form>` tag:

```blade
        @php $isBalanced = bccomp((string) $totalDebit, (string) $totalCredit, 2) === 0; @endphp
        <div class="sticky bottom-0 bg-white border-t border-gray-200 px-4 py-3 flex flex-wrap justify-end gap-6 text-sm font-semibold {{ $isBalanced ? 'text-gray-800' : 'text-red-600' }}">
            <span>Вкупно должи: {{ \App\Support\Format::money($totalDebit) }}</span>
            <span>Вкупно побарува: {{ \App\Support\Format::money($totalCredit) }}</span>
            <span>Салдо: {{ \App\Support\Format::money($totalDebit - $totalCredit) }}</span>
        </div>
```

- [ ] **Step 5: Make the line table responsive**

Wrap the existing `<table>` (the one with `Сметка/Партнер/Опис/...` columns) in a wide-screen-only container, and add a stacked-card version for narrow screens right after it. Change the table's opening tag:

```blade
        <table class="min-w-full divide-y divide-gray-200 mb-4 hidden md:table">
```

Add, immediately after that table's closing `</table>`:

```blade
        <div class="md:hidden space-y-3 mb-4">
            @foreach ($lines as $index => $line)
                @php $isLate = $line['line_date'] > $entryDate; @endphp
                <div class="border border-gray-200 rounded-lg p-3 text-sm {{ $isLate ? 'bg-red-50' : '' }}">
                    <div class="flex justify-between items-start mb-2">
                        <span class="font-medium text-gray-500">Ставка {{ $index + 1 }}</span>
                        @if ($canEdit)
                            <button type="button" wire:click="removeLine({{ $index }})" class="text-red-600 text-xs">Отстрани</button>
                        @endif
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="text-xs text-gray-500">Сметка</label>
                            <div x-data="journalEntryPicker(@js($accountsForJs), 'lines.{{ $index }}.account_id', @js($accounts->firstWhere('id', $line['account_id'])?->code.' — '.$accounts->firstWhere('id', $line['account_id'])?->name ?? ''))" @click.outside="open = false" class="relative">
                                <input type="text" x-model="query" @focus="open = true" @input="open = true" class="border-gray-300 rounded-md text-sm w-full" @disabled(! $canEdit) />
                                <div x-show="open && filtered.length" x-cloak class="absolute z-10 bg-white border border-gray-200 rounded-md shadow-md mt-1 max-h-40 overflow-y-auto w-full">
                                    <template x-for="item in filtered" :key="item.id">
                                        <div @click="select(item)" class="px-2 py-1 text-sm hover:bg-gray-100 cursor-pointer" x-text="item.label"></div>
                                    </template>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="text-xs text-gray-500">Датум</label>
                            <input type="date" wire:model="lines.{{ $index }}.line_date" class="rounded-md text-sm w-full {{ $isLate ? 'border-red-400 text-red-700' : 'border-gray-300' }}" @disabled(! $canEdit) />
                        </div>
                        <div>
                            <label class="text-xs text-gray-500">Должи</label>
                            <input type="number" step="0.01" wire:model="lines.{{ $index }}.debit" class="border-gray-300 rounded-md text-sm w-full" @disabled(! $canEdit) />
                        </div>
                        <div>
                            <label class="text-xs text-gray-500">Побарува</label>
                            <input type="number" step="0.01" wire:model="lines.{{ $index }}.credit" class="border-gray-300 rounded-md text-sm w-full" @disabled(! $canEdit) />
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
```

This mobile block deliberately omits the partner picker and the FX-only fields to keep phone-width cards short — those remain reachable via the desktop table (a phone user needing them can rotate to landscape, where `md:` breakpoint shows the full table). This trade-off is acceptable per the design spec's own framing (vertical scrolling per line is the accepted cost of avoiding horizontal scroll).

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=JournalEntryFormTest`
Expected: all PASS.

- [ ] **Step 7: Run the full suite and commit**

Run: `php artisan test`
Expected: all green.

```bash
git add app/Livewire/Accounting/JournalEntryForm.php resources/views/livewire/accounting/journal-entry-form.blade.php tests/Feature/JournalEntryFormTest.php
git commit -m "feat: add sticky totals footer and a responsive stacked-card layout for phone width"
```

---

### Task 12: Single-entry PDF printing + full regression pass

**Files:**
- Create: `app/Http/Controllers/JournalEntryPdfController.php`
- Create: `resources/views/pdf/journal-entry.blade.php`
- Create: `tests/Feature/JournalEntryPdfTest.php`
- Modify: `routes/web.php`
- Modify: `resources/views/livewire/accounting/journal-entry-form.blade.php`

**Interfaces:**
- Consumes: `JournalEntry::displayNumber()` (Task 3), `JournalEntryLine::line_date` (Task 6).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JournalEntryPdfTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_renders_the_header_and_line_items(): void
    {
        $company = Company::factory()->create(['name' => 'Fajnens Badi DOOEL']);
        $group = JournalGroup::factory()->for($company)->create(['code' => '10', 'name' => 'Изводи']);
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();
        $partner = Partner::factory()->for($company)->create(['name' => 'ABC Trading']);
        $entry = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'entry_date' => '2026-03-15', 'description' => 'Cash sale']);
        $entry->lines()->create(['account_id' => $cash->id, 'partner_id' => $partner->id, 'line_date' => '2026-03-15', 'debit' => 1000, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $revenue->id, 'line_date' => '2026-03-15', 'debit' => 0, 'credit' => 1000]);

        $html = view('pdf.journal-entry', ['entry' => $entry->fresh(['lines.account', 'lines.partner', 'journalGroup', 'company'])])->render();

        $this->assertStringContainsString('Fajnens Badi DOOEL', $html);
        $this->assertStringContainsString('10-'.str_pad((string) $entry->entry_number, 4, '0', STR_PAD_LEFT), $html);
        $this->assertStringContainsString('Изводи', $html);
        $this->assertStringContainsString('Cash sale', $html);
        $this->assertStringContainsString('ABC Trading', $html);
        $this->assertStringContainsString(\App\Support\Format::money('1000.00'), $html);
    }

    public function test_admin_can_download_the_pdf(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $entry = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id]);
        $entry->lines()->create(['account_id' => $cash->id, 'line_date' => $entry->entry_date, 'debit' => 100, 'credit' => 0]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('accounting.journal-entries.pdf', [$company, $entry]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_a_different_companys_entry_is_not_found(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $entry = JournalEntry::factory()->for($otherCompany)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('accounting.journal-entries.pdf', [$company, $entry]));

        $response->assertNotFound();
    }

    public function test_client_cannot_download_a_pdf_for_another_companys_entry(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $entry = JournalEntry::factory()->for($otherCompany)->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');

        $response = $this->actingAs($client)->get(route('accounting.journal-entries.pdf', [$otherCompany, $entry]));

        $response->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=JournalEntryPdfTest`
Expected: FAIL — controller, view, and route don't exist yet.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\JournalEntry;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;

class JournalEntryPdfController extends Controller
{
    public function __invoke(Company $company, JournalEntry $journalEntry)
    {
        Gate::authorize('view', $journalEntry);

        abort_if($journalEntry->company_id !== $company->id, 404);

        $journalEntry->load(['lines.account', 'lines.partner', 'journalGroup', 'company']);

        $pdf = Pdf::loadView('pdf.journal-entry', ['entry' => $journalEntry]);

        return $pdf->download("nalog-{$journalEntry->displayNumber()}.pdf");
    }
}
```

- [ ] **Step 4: Write the PDF view**

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans'; font-size: 11px; color: #1f2937; margin: 0; padding: 0; }
        .accent-bar { height: 6px; background-color: #ff6600; }
        .content { padding: 18px 24px; }
        .muted { color: #6b7280; }
        table.header-table { width: 100%; margin-bottom: 10px; }
        table.header-table td { vertical-align: top; }
        .badge { display: inline-block; background-color: #fff3ea; color: #ff6600; font-weight: bold; font-size: 14px; padding: 6px 14px; border-radius: 8px; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.lines th { text-align: left; font-size: 10px; color: #6b7280; background-color: #f9fafb; padding: 6px; }
        table.lines td { padding: 6px; border-bottom: 1px solid #f3f4f6; }
        table.totals-table { width: 260px; margin-top: 12px; margin-left: auto; background-color: #fff3ea; border-radius: 8px; }
        table.totals-table td { padding: 6px 14px; font-size: 11px; }
    </style>
</head>
<body>
    <div class="accent-bar"></div>
    <div class="content">
        <table class="header-table">
            <tr>
                <td><strong>{{ $entry->company->name }}</strong></td>
                <td style="text-align: right;">
                    <span class="badge">НАЛОГ {{ $entry->displayNumber() }}</span>
                </td>
            </tr>
        </table>

        <table class="header-table">
            <tr>
                <td class="muted">Журнал: {{ $entry->journalGroup->code }} — {{ $entry->journalGroup->name }}</td>
                <td class="muted" style="text-align: right;">Датум: {{ \App\Support\Format::date($entry->entry_date) }}</td>
            </tr>
            @if ($entry->description)
                <tr>
                    <td colspan="2">Опис: {{ $entry->description }}</td>
                </tr>
            @endif
        </table>

        <table class="lines">
            <thead>
                <tr>
                    <th>Сметка</th>
                    <th>Партнер</th>
                    <th>Опис</th>
                    <th>Датум</th>
                    <th style="text-align: right;">Должи</th>
                    <th style="text-align: right;">Побарува</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($entry->lines as $line)
                    <tr>
                        <td>{{ $line->account->code }} — {{ $line->account->name }}</td>
                        <td>{{ $line->partner?->name }}</td>
                        <td>{{ $line->description }}</td>
                        <td>{{ \App\Support\Format::date($line->line_date) }}</td>
                        <td style="text-align: right;">{{ (float) $line->debit > 0 ? \App\Support\Format::money($line->debit) : '' }}</td>
                        <td style="text-align: right;">{{ (float) $line->credit > 0 ? \App\Support\Format::money($line->credit) : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals-table">
            <tr>
                <td>Вкупно</td>
                <td style="text-align: right;">{{ \App\Support\Format::money($entry->lines->sum('debit')) }}</td>
                <td style="text-align: right;">{{ \App\Support\Format::money($entry->lines->sum('credit')) }}</td>
            </tr>
        </table>
    </div>
</body>
</html>
```

- [ ] **Step 5: Register the route**

In `routes/web.php`, add the import `use App\Http\Controllers\JournalEntryPdfController;`, then inside the `accounting.` route group, right after the `journal-entries.edit` line:

```php
    Route::get('/journal-entries/{journalEntry}/pdf', [JournalEntryPdfController::class, '__invoke'])->name('journal-entries.pdf');
```

- [ ] **Step 6: Add the print button**

In `resources/views/livewire/accounting/journal-entry-form.blade.php`, add next to the navigator buttons (only visible when editing an existing entry):

```blade
                <a href="{{ route('accounting.journal-entries.pdf', [$company, $journalEntry]) }}" target="_blank">
                    <x-secondary-button type="button">Печати</x-secondary-button>
                </a>
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --filter=JournalEntryPdfTest`
Expected: all 4 PASS.

- [ ] **Step 8: Run the full automated test suite**

Run: `php artisan test`
Expected: full suite green — this is the last task of the plan, so this is the final proof that every prior task (schema changes, invoice-service fix, form rewrite) still integrates cleanly.

- [ ] **Step 9: Manual browser verification**

Before considering this sub-project done, verify in a real browser (PHPUnit cannot exercise Alpine.js, dompdf rendering, or actual responsive breakpoints):

1. Create a new entry: journal-group picker shows optgroups, account/partner typing filters live and shows the picked name, FX checkbox reveals/hides currency columns, saving redirects to the index with the entry now shown as `{code}-{number}`.
2. Open that entry: navigator prev/next work and stay within the same journal group; delete button asks for confirmation and removes it; "Печати" downloads a real PDF and its layout (not just its HTML) looks right — per the sub-project #3 lesson, actually open the downloaded PDF file, don't just trust the Blade output.
3. Resize the browser to phone width: the line table becomes stacked cards, the page never scrolls horizontally, and the totals footer stays pinned to the bottom while the lines scroll.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/JournalEntryPdfController.php resources/views/pdf/journal-entry.blade.php tests/Feature/JournalEntryPdfTest.php routes/web.php resources/views/livewire/accounting/journal-entry-form.blade.php
git commit -m "feat: add single-entry PDF printing for journal entries"
```

---

## Self-Review Notes

- **Spec coverage:** journal groups + per-journal-per-fiscal-year numbering + legacy backfill → Tasks 1, 3; settings CRUD → Task 2; last-valid-date/line-date highlighting → Task 6; group picker (required-on-create, locked-on-edit) → Task 5; navigator → Task 9; PDF → Task 12; FX checkbox → Task 7; delete → Task 8; autocomplete → Task 10; sticky footer + responsive → Task 11. Task 4 isn't in the original design spec's numbered sections but is a direct, unavoidable consequence of Task 3's schema change (invoice GL-posting bypasses the form) — flagged explicitly as in-scope rather than silently patched.
- **Placeholder scan:** none — every step has complete, real code or an exact command.
- **Type/name consistency:** `journal_group_id`, `displayNumber()`, `journalGroup()`, `line_date`, `isForeignCurrency`, `accountsForJs`/`partnersForJs`, `hasPrevious`/`hasNext` are each defined once (Tasks 1/3/5/6/7/10/9 respectively) and reused unchanged by every later task that touches `JournalEntryForm.php`.
- **Cross-cutting risk addressed proactively:** the `sales_invoices.journal_entry_id`/`purchase_invoices.journal_entry_id` foreign keys have no cascade rule, so Task 8's delete button would throw a raw DB constraint exception on an invoice-linked entry without the explicit guard added there — caught during planning by reading the actual migration, not left for a later review cycle to discover.
- **Test churn is real and enumerated, not hand-waved:** Task 3 rewrites all of `tests/Unit/JournalEntryTest.php`; Task 5 rewrites 6 existing methods in `tests/Feature/JournalEntryFormTest.php` in full (shown, not described) plus adds 4 new ones — this mirrors the churn every prior sub-project in this batch has needed when a schema-level scoping key changes.
