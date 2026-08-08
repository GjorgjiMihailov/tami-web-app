# UI/UX Redesign — Plan B: Accounting Module Rollout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply Plan A's approved design tokens/components to the Accounting module's 6 screens, establishing a consistent "data table" visual pattern (header background + row hover) that Plan C/D/... will reuse for the remaining modules.

**Architecture:** Mechanical application of already-shipped tokens — no new tokens, no new shared components. Investigation found the Accounting module is already unusually consistent: 4 of its 5 table screens already wrap their `<table>` in `<x-card padding="p-0" class="overflow-hidden">` (so they already inherit Plan A's `rounded-2xl`/`shadow-card` for free). The one outlier (`account-index.blade.php`) gets brought in line. On top of that existing consistency, this plan adds one new, deliberately small polish layer — a subtle header background and row hover — applied identically across every table in the module.

**Tech Stack:** Laravel 13 + Livewire (class-based components, not Volt, for this module) + Blade, Tailwind CSS 3 (JIT), Pest/PHPUnit `Livewire::test(...)` component tests.

## Global Constraints

- Visual/UX changes only. Never touch controllers, Livewire component PHP logic (`App\Livewire\Accounting\*`), calculations, validations, or the content/structure of any legal document. (Design spec, "Scope boundary")
- No new business logic or computed data.
- Keep every existing `wire:click`, `wire:model`, `wire:confirm`, route name, and Alpine.js `x-data` block byte-identical — only class strings and, where explicitly stated below, structural wrapper tags change.
- New header/row treatment: `bg-gray-50` on `<thead><tr>`, `hover:bg-orange-50` on `<tbody><tr>` — chosen to match tokens already established in Plan A (`hover:bg-orange-50` is the same class used by `dropdown-link`, `responsive-nav-link`, and the sidebar) and in this module's own already-shipped PDF template (`resources/views/pdf/journal-entry.blade.php`'s `table.lines th { background-color: #f9fafb }`, which is the literal hex value of Tailwind's `gray-50`).
- `resources/views/pdf/journal-entry.blade.php` needs **no changes in this plan** — it already has the full warm treatment (`#ff6600` accent bar, `#fff3ea` warm badge/totals background, rounded corners) from an earlier phase. Verify this while reading the file in Task 4, don't skip it, but do not edit it unless you find it's actually missing something this plan's own constraints require (unlikely).
- Existing tests in `AccountIndexTest.php`, `JournalGroupIndexTest.php`, `JournalEntryIndexTest.php`, `LedgerCardReportTest.php`, `TrialBalanceReportTest.php`, `JournalEntryFormTest.php` must keep passing unless a task explicitly changes that exact behavior (none do — every change here is additive to class strings).

---

## Task 1: Table pattern foundation + `account-index.blade.php`

`account-index.blade.php` is the only one of the 6 Accounting screens whose tables aren't wrapped in `<x-card>` — it renders one raw, unstyled `<table>` per account class (loop). This task establishes the header/row pattern (used by every later task in this plan) and applies it here first, alongside wrapping each per-class table in a card to match its siblings.

**Files:**
- Modify: `resources/views/livewire/accounting/account-index.blade.php`
- Test: `tests/Feature/AccountIndexTest.php`

**Interfaces:**
- No PHP changes — `App\Livewire\Accounting\AccountIndex`'s public properties/methods (`$accountsByClass`, `toggleActive($id)`, `addAnalyticalAccount`) are untouched.

- [ ] **Step 1: Read `tests/Feature/AccountIndexTest.php` in full first**

It already has `setUp()` registering `admin`/`accountant`/`client` roles, and existing tests use `Livewire::test(AccountIndex::class, ['company' => $company])->assertSee(...)`. Mirror this exact setup style for the new test — don't invent a different one.

- [ ] **Step 2: Write the failing test**

Add to `AccountIndexTest.php` (inside the existing class). Note: this codebase auto-seeds every new `Company` with its official chart of accounts (see `test_it_lists_the_companys_accounts` above, which asserts on seeded code `'120'` with no manual `Account::factory()` call at all — mirror that, don't create an `Account` manually):

```php
    public function test_the_account_table_is_wrapped_in_a_card_with_the_new_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(AccountIndex::class, ['company' => $company])
            ->assertSee('shadow-card', false)
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
    }
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=AccountIndexTest`
Expected: the new test FAILS — the current file has no `shadow-card` (no `<x-card>` at all), no `bg-gray-50`, no `hover:bg-orange-50`.

- [ ] **Step 4: Rewrite `account-index.blade.php`**

Replace the whole file with:

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Контен план — {{ $company->name }}</h1>

    @can('create', \App\Models\Account::class)
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Додади аналитичка сметка</h2>
            <form wire:submit="addAnalyticalAccount" class="flex flex-wrap gap-3 items-end">
                <div>
                    <x-input-label for="newParentCode" value="Синтетичка сметка (3 цифри)" />
                    <x-text-input id="newParentCode" wire:model="newParentCode" class="w-32" />
                    @error('newParentCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newCode" value="Нова шифра (4+ цифри)" />
                    <x-text-input id="newCode" wire:model="newCode" class="w-32" />
                    @error('newCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newName" value="Назив" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <x-primary-button type="submit">Додади</x-primary-button>
            </form>
        </x-card>
    @endcan

    @foreach ($accountsByClass as $class => $accounts)
        <div class="mb-6">
            <h3 class="text-lg font-semibold text-gray-700 mb-2">Класа {{ $class }}</h3>
            <x-card padding="p-0" class="overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead>
                    <tr class="text-left text-sm text-gray-500 bg-gray-50">
                        <th class="py-2 px-4">Шифра</th>
                        <th class="py-2 px-4">Назив</th>
                        <th class="py-2 px-4">Активна</th>
                        <th class="py-2 px-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($accounts as $account)
                        <tr class="text-sm hover:bg-orange-50 {{ $account->is_active ? '' : 'text-gray-400' }}">
                            <td class="py-2 px-4 font-mono">{{ $account->code }}</td>
                            <td class="py-2 px-4">{{ $account->name }}</td>
                            <td class="py-2 px-4">{{ $account->is_active ? 'Да' : 'Не' }}</td>
                            <td class="py-2 px-4">
                                @can('update', $account)
                                    <button type="button" wire:click="toggleActive({{ $account->id }})" class="text-brand hover:underline text-sm">
                                        {{ $account->is_active ? 'Деактивирај' : 'Активирај' }}
                                    </button>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </x-card>
        </div>
    @endforeach
</div>
```

(Note: row padding changed from `py-1 pr-4` to `py-2 px-4` to match the other 4 table screens in this module exactly — see Task 2/3.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AccountIndexTest`
Expected: PASS (new test and all pre-existing ones in the file).

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add resources/views/livewire/accounting/account-index.blade.php tests/Feature/AccountIndexTest.php
git commit -m "feat(ui): wrap account-index tables in cards, add header/hover pattern"
```

---

## Task 2: `journal-group-index.blade.php` + `journal-entry-index.blade.php`

Both already wrap their table in `<x-card padding="p-0" class="overflow-hidden">` — this task only adds the header background and row hover established in Task 1.

**Files:**
- Modify: `resources/views/livewire/accounting/journal-group-index.blade.php`
- Modify: `resources/views/livewire/accounting/journal-entry-index.blade.php`
- Test: `tests/Feature/JournalGroupIndexTest.php`
- Test: `tests/Feature/JournalEntryIndexTest.php`

**Interfaces:**
- No PHP changes to either component.

- [ ] **Step 1: Read both existing test files first**

Both use the same `Livewire::test(ComponentClass::class, ['company' => $company])` pattern seen in Task 1 — mirror it exactly.

- [ ] **Step 2: Write the failing tests**

Add to `JournalGroupIndexTest.php`:

```php
    public function test_the_journal_group_table_has_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(JournalGroupIndex::class, ['company' => $company])
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
    }
```

Add to `JournalEntryIndexTest.php` (check its existing imports/use statements — it will already import `Company`, `User`, `Livewire`, `Role` following the same pattern):

```php
    public function test_the_journal_entry_table_has_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(JournalEntryIndex::class, ['company' => $company])
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
    }
```

- [ ] **Step 3: Run both tests to verify they fail**

Run: `php artisan test --filter=JournalGroupIndexTest` and `php artisan test --filter=JournalEntryIndexTest`
Expected: both new tests FAIL — neither file currently has `bg-gray-50` or `hover:bg-orange-50` anywhere.

- [ ] **Step 4: Update `journal-group-index.blade.php`'s table**

Find:
```blade
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
                <tr class="text-sm" wire:key="group-{{ $group->id }}">
```
Replace with:
```blade
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500 bg-gray-50">
                <th class="py-2 px-4">Код</th>
                <th class="py-2 px-4">Име</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($groups as $group)
                <tr class="text-sm hover:bg-orange-50" wire:key="group-{{ $group->id }}">
```

- [ ] **Step 5: Update `journal-entry-index.blade.php`'s table**

Find:
```blade
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">#</th>
                <th class="py-2 px-4">Датум</th>
                <th class="py-2 px-4">Опис</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($entries as $entry)
                <tr class="text-sm">
```
Replace with:
```blade
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500 bg-gray-50">
                <th class="py-2 px-4">#</th>
                <th class="py-2 px-4">Датум</th>
                <th class="py-2 px-4">Опис</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($entries as $entry)
                <tr class="text-sm hover:bg-orange-50">
```

- [ ] **Step 6: Run both tests to verify they pass**

Run: `php artisan test --filter=JournalGroupIndexTest` and `php artisan test --filter=JournalEntryIndexTest`
Expected: PASS (new and pre-existing).

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add resources/views/livewire/accounting/journal-group-index.blade.php resources/views/livewire/accounting/journal-entry-index.blade.php tests/Feature/JournalGroupIndexTest.php tests/Feature/JournalEntryIndexTest.php
git commit -m "feat(ui): add header/hover pattern to journal group and entry tables"
```

---

## Task 3: `ledger-card-report.blade.php` + `trial-balance-report.blade.php`

Same treatment as Task 2. `trial-balance-report.blade.php` also has a `<tfoot>` totals row — give it the header-style background too, since it's the visual "total" anchor of the table, not a hoverable data row (no `hover:` class on it).

**Files:**
- Modify: `resources/views/livewire/accounting/ledger-card-report.blade.php`
- Modify: `resources/views/livewire/accounting/trial-balance-report.blade.php`
- Test: `tests/Feature/LedgerCardReportTest.php`
- Test: `tests/Feature/TrialBalanceReportTest.php`

**Interfaces:**
- No PHP changes to either component.

- [ ] **Step 1: Read both existing test files first**

Same `Livewire::test(...)` pattern as Tasks 1-2. `LedgerCardReportTest.php`'s tests likely set `accountId` or `partnerId` via `->set(...)` before asserting table content, since the table only renders `@if ($accountId || $partnerId)` — check this and mirror it in the new test (the new test needs to trigger that same condition to see the table's markup at all).

- [ ] **Step 2: Write the failing tests**

Add to `LedgerCardReportTest.php`. Note: this codebase auto-seeds every new `Company` with its official chart of accounts — do not create an `Account` via factory, look up an already-seeded one by code, exactly like this file's existing `test_it_shows_lines_once_an_account_is_selected` does:

```php
    public function test_the_ledger_card_table_has_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $account = Account::where('company_id', $company->id)->where('code', '120')->first();

        $this->actingAs($admin);

        Livewire::test(LedgerCardReport::class, ['company' => $company])
            ->set('accountId', $account->id)
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
    }
```

Add to `TrialBalanceReportTest.php`:

```php
    public function test_the_trial_balance_table_has_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(TrialBalanceReport::class, ['company' => $company])
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
    }
```

- [ ] **Step 3: Run both tests to verify they fail**

Run: `php artisan test --filter=LedgerCardReportTest` and `php artisan test --filter=TrialBalanceReportTest`
Expected: both new tests FAIL.

- [ ] **Step 4: Update `ledger-card-report.blade.php`'s table**

Find:
```blade
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-2 px-4">Датум</th>
                    <th class="py-2 px-4">Опис</th>
                    <th class="py-2 px-4">Партнер</th>
                    <th class="py-2 px-4 text-right">Должи</th>
                    <th class="py-2 px-4 text-right">Побарува</th>
                    <th class="py-2 px-4 text-right">Салдо</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    <tr class="text-sm">
```
Replace with:
```blade
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-2 px-4">Датум</th>
                    <th class="py-2 px-4">Опис</th>
                    <th class="py-2 px-4">Партнер</th>
                    <th class="py-2 px-4 text-right">Должи</th>
                    <th class="py-2 px-4 text-right">Побарува</th>
                    <th class="py-2 px-4 text-right">Салдо</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    <tr class="text-sm hover:bg-orange-50">
```

- [ ] **Step 5: Update `trial-balance-report.blade.php`'s table**

Find:
```blade
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Шифра</th>
                <th class="py-2 px-4">Назив</th>
                <th class="py-2 px-4 text-right">Почетно салдо</th>
                <th class="py-2 px-4 text-right">Промет должи</th>
                <th class="py-2 px-4 text-right">Промет побарува</th>
                <th class="py-2 px-4 text-right">Крајно салдо</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($rows as $row)
                <tr class="text-sm">
```
Replace with:
```blade
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500 bg-gray-50">
                <th class="py-2 px-4">Шифра</th>
                <th class="py-2 px-4">Назив</th>
                <th class="py-2 px-4 text-right">Почетно салдо</th>
                <th class="py-2 px-4 text-right">Промет должи</th>
                <th class="py-2 px-4 text-right">Промет побарува</th>
                <th class="py-2 px-4 text-right">Крајно салдо</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($rows as $row)
                <tr class="text-sm hover:bg-orange-50">
```

Also find the `<tfoot>` totals row:
```blade
        <tfoot>
            <tr class="text-sm font-bold border-t border-gray-300">
```
Replace with:
```blade
        <tfoot>
            <tr class="text-sm font-bold border-t border-gray-300 bg-gray-50">
```

- [ ] **Step 6: Run both tests to verify they pass**

Run: `php artisan test --filter=LedgerCardReportTest` and `php artisan test --filter=TrialBalanceReportTest`
Expected: PASS (new and pre-existing).

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add resources/views/livewire/accounting/ledger-card-report.blade.php resources/views/livewire/accounting/trial-balance-report.blade.php tests/Feature/LedgerCardReportTest.php tests/Feature/TrialBalanceReportTest.php
git commit -m "feat(ui): add header/hover pattern to ledger card and trial balance tables"
```

---

## Task 4: `journal-entry-form.blade.php` (careful, minimal touch)

This is the module's most complex screen — a live, in-place-editable table with Alpine.js autocomplete pickers (`journalEntryPicker`), foreign-currency toggle columns, keyboard-style prev/next navigation, and a mobile card fallback. **Do not restructure any of this.** The only change is applying the same header background to the desktop table (for visual consistency with the other 5 screens in this module) and bumping the mobile card view's corner radius from `rounded-lg` to `rounded-xl` to match Task 4 of Plan A (dropdown got the same bump, establishing `rounded-xl` as the "secondary container" radius one step below `rounded-2xl` cards).

No row-hover is added here — unlike the other 5 screens, these rows contain editable inputs the user is actively typing into, and a hover-highlight on an input-heavy editable row is visual noise, not a helpful affordance (this is a deliberate exception to the pattern, not an oversight).

**Files:**
- Modify: `resources/views/livewire/accounting/journal-entry-form.blade.php`
- Test: `tests/Feature/JournalEntryFormTest.php`

**Interfaces:**
- No PHP changes. Every `wire:model`, `wire:click`, `wire:key`, and the `journalEntryPicker(...)` Alpine component call must remain byte-identical.

- [ ] **Step 1: Read `tests/Feature/JournalEntryFormTest.php` in full first**

Note whether it tests via `Livewire::test(JournalEntryForm::class, ...)` or via HTTP route (`get(route('accounting.journal-entries.create', $company))`), since this component may be routed differently than the index screens. Mirror whichever pattern it already uses.

- [ ] **Step 2: Write the failing test**

Adapt to match the file's actual existing pattern from Step 1. If it uses `Livewire::test`:

```php
    public function test_the_desktop_lines_table_has_the_new_header_background(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->assertSee('bg-gray-50', false);
    }
```

If it uses HTTP route testing instead, use the same `assertSee('bg-gray-50', false)` assertion against `$this->actingAs($admin)->get(route('accounting.journal-entries.create', $company))`.

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=JournalEntryFormTest`
Expected: the new test FAILS — no `bg-gray-50` currently exists anywhere in this file.

- [ ] **Step 4: Update the desktop table's header row**

Find (inside the `<table class="min-w-full divide-y divide-gray-200 mb-4 hidden md:table">` block):
```blade
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-1 pr-2">Сметка</th>
```
Replace with:
```blade
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-1 pr-2">Сметка</th>
```

(Only the `<tr class="...">` on that one line changes — every other `<th>` in that same header row keeps its existing classes untouched, and the row's background alone is enough to visually unify the whole header.)

- [ ] **Step 5: Update the mobile card view's corner radius**

Find:
```blade
                <div wire:key="m-line-{{ $line['_key'] }}" class="border border-gray-200 rounded-lg p-3 text-sm {{ $isLate ? 'bg-red-50' : '' }}">
```
Replace with:
```blade
                <div wire:key="m-line-{{ $line['_key'] }}" class="border border-gray-200 rounded-xl p-3 text-sm {{ $isLate ? 'bg-red-50' : '' }}">
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=JournalEntryFormTest`
Expected: PASS (new and all pre-existing tests — this component has real business-logic tests around balancing/validation/rate-fetching that must be completely unaffected by these two class-string changes).

- [ ] **Step 7: Confirm the PDF template needs no changes**

Open `resources/views/pdf/journal-entry.blade.php` and confirm it already has the warm accent bar (`#ff6600`), the peach badge/totals background (`#fff3ea`), and the `#f9fafb` (gray-50) table header — per this plan's Global Constraints, it does, from an earlier phase. Do not edit this file. (If, contrary to expectation, you find it does NOT already have this styling, STOP and report back rather than guessing at a fix — that would mean this plan's premise about that file is wrong.)

- [ ] **Step 8: Run the full test suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add resources/views/livewire/accounting/journal-entry-form.blade.php tests/Feature/JournalEntryFormTest.php
git commit -m "feat(ui): add header background to journal entry form table, round mobile cards"
```

---

## After this plan

This completes the Accounting module. The next module in the design's stated rollout order is Инвентар (Inventory) — write it as its own plan (Plan C) once this one is executed and reviewed, following the same investigate-first approach this plan used (most of Inventory's screens may already follow similar conventions; don't assume, check).
