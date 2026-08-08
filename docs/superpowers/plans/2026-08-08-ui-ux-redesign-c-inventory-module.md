# UI/UX Redesign — Plan C: Inventory Module Rollout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply the "data table" pattern established in Plan B (`bg-gray-50` header, `hover:bg-orange-50` rows) to the Inventory module's 6 table screens.

**Architecture:** Purely mechanical — every table screen in this module (unlike Accounting's `account-index`) is ALREADY wrapped in `<x-card padding="p-0" class="overflow-hidden">`, so there is no "Task 1 establishes the wrapper" work this time. This plan is entirely the same two-line find/replace pattern repeated across 6 files. The module's 7th screen, `stock-movement-form.blade.php`, is a pure form with no table at all — it already fully inherited Plan A's tokens via `x-card`/`x-input-label`/`x-text-input` and needs zero changes; it is intentionally not a task in this plan.

**Tech Stack:** Laravel 13 + Livewire (class-based components) + Blade, Tailwind CSS 3 (JIT), Pest/PHPUnit `Livewire::test(...)` component tests.

## Global Constraints

- Visual/UX changes only. Never touch controllers, Livewire component PHP logic (`App\Livewire\Inventory\*`), calculations, validations, or the content/structure of any legal document.
- No new business logic or computed data.
- Header/row pattern must be exactly `bg-gray-50` (header) and `hover:bg-orange-50` (data rows) — matching Plan B's precedent exactly, not a close variant.
- `item-index.blade.php` has one deliberate exception: its inline edit-form row (`@if ($editingItemId === $item->id)`, a `<tr>` containing a full edit form, not passive data) does NOT get `hover:bg-orange-50` — it already happens to use `bg-gray-50` on its `<td>` as an editing indicator, which is a lucky pre-existing consistency with this plan's header color, not something to touch.
- `resources/views/pdf/stock-on-hand.blade.php` needs **no changes in this plan** — verify while reading it in Task 3, don't skip it, but it already has the `#ff6600` accent bar and `#f9fafb` (gray-50) table header from an earlier phase, matching the pattern already confirmed untouched in Plan B's `journal-entry.blade.php`.
- `stock-movement-form.blade.php` is explicitly out of scope for this plan — it has no table, and its form fields (inputs, selects, the barcode-scanner Alpine component) already render correctly via Plan A's shared components. Do not create a task for it.
- Existing tests in `WarehouseIndexTest.php`, `ItemIndexTest.php`, `ItemBulkImportTest.php`, `ItemMovementCardReportTest.php`, `StockOnHandReportTest.php`, `StockValuationReportTest.php` must keep passing unless a task explicitly changes that exact behavior (none do — every change here is additive to class strings).

---

## Task 1: `warehouse-index.blade.php` + `item-index.blade.php`

**Files:**
- Modify: `resources/views/livewire/inventory/warehouse-index.blade.php`
- Modify: `resources/views/livewire/inventory/item-index.blade.php`
- Test: `tests/Feature/WarehouseIndexTest.php`
- Test: `tests/Feature/ItemIndexTest.php`

**Interfaces:**
- No PHP changes to either component.

- [ ] **Step 1: Read both existing test files first**

Both use `Livewire::test(ComponentClass::class, ['company' => $company])` with `Role::findOrCreate(...)` in `setUp()` — mirror this exact pattern.

- [ ] **Step 2: Write the failing tests**

Add to `WarehouseIndexTest.php`:

```php
    public function test_the_warehouse_table_has_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        Warehouse::factory()->for($company)->create(['name' => 'Main Store']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(WarehouseIndex::class, ['company' => $company])
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
    }
```

Add to `ItemIndexTest.php`:

```php
    public function test_the_item_table_has_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['name' => 'Widget A']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
    }
```

- [ ] **Step 3: Run both tests to verify they fail**

Run: `php artisan test --filter=WarehouseIndexTest` and `php artisan test --filter=ItemIndexTest`
Expected: both new tests FAIL — neither file currently has `bg-gray-50` or `hover:bg-orange-50` anywhere.

- [ ] **Step 4: Update `warehouse-index.blade.php`'s table**

Find:
```blade
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Назив</th>
                <th class="py-2 px-4">Активен</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($warehouses as $warehouse)
                <tr class="text-sm {{ $warehouse->is_active ? '' : 'text-gray-400' }}">
```
Replace with:
```blade
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500 bg-gray-50">
                <th class="py-2 px-4">Назив</th>
                <th class="py-2 px-4">Активен</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($warehouses as $warehouse)
                <tr class="text-sm hover:bg-orange-50 {{ $warehouse->is_active ? '' : 'text-gray-400' }}">
```

- [ ] **Step 5: Update `item-index.blade.php`'s table**

Find:
```blade
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Шифра</th>
                <th class="py-2 px-4">Назив</th>
                <th class="py-2 px-4">Мерна единица</th>
                <th class="py-2 px-4">Категорија</th>
                <th class="py-2 px-4">ДДВ %</th>
                <th class="py-2 px-4">Продажна цена</th>
                <th class="py-2 px-4">Тип</th>
                <th class="py-2 px-4">МК-производство</th>
                <th class="py-2 px-4">Баркод</th>
                <th class="py-2 px-4">Активен</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($items as $item)
                <tr class="text-sm {{ $item->is_active ? '' : 'text-gray-400' }}" wire:key="item-{{ $item->id }}">
```
Replace with:
```blade
        <thead>
            <tr class="text-left text-sm text-gray-500 bg-gray-50">
                <th class="py-2 px-4">Шифра</th>
                <th class="py-2 px-4">Назив</th>
                <th class="py-2 px-4">Мерна единица</th>
                <th class="py-2 px-4">Категорија</th>
                <th class="py-2 px-4">ДДВ %</th>
                <th class="py-2 px-4">Продажна цена</th>
                <th class="py-2 px-4">Тип</th>
                <th class="py-2 px-4">МК-производство</th>
                <th class="py-2 px-4">Баркод</th>
                <th class="py-2 px-4">Активен</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($items as $item)
                <tr class="text-sm hover:bg-orange-50 {{ $item->is_active ? '' : 'text-gray-400' }}" wire:key="item-{{ $item->id }}">
```

**Do NOT touch the `@if ($editingItemId === $item->id)` block below it** (the inline edit-form row) — it stays exactly as-is, with no hover class, per this plan's documented exception.

- [ ] **Step 6: Run both tests to verify they pass**

Run: `php artisan test --filter=WarehouseIndexTest` and `php artisan test --filter=ItemIndexTest`
Expected: PASS (new and pre-existing).

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add resources/views/livewire/inventory/warehouse-index.blade.php resources/views/livewire/inventory/item-index.blade.php tests/Feature/WarehouseIndexTest.php tests/Feature/ItemIndexTest.php
git commit -m "feat(ui): add header/hover pattern to warehouse and item tables"
```

---

## Task 2: `item-bulk-import.blade.php` + `item-movement-card-report.blade.php`

**Files:**
- Modify: `resources/views/livewire/inventory/item-bulk-import.blade.php`
- Modify: `resources/views/livewire/inventory/item-movement-card-report.blade.php`
- Test: `tests/Feature/ItemBulkImportTest.php`
- Test: `tests/Feature/ItemMovementCardReportTest.php`

**Interfaces:**
- No PHP changes to either component.

- [ ] **Step 1: Read both existing test files first**

`ItemBulkImportTest.php`'s preview table only renders when `$parsedRows` is non-empty (set via calling `preview` after uploading a file, per the component) — check how its existing tests populate that state and mirror it. `ItemMovementCardReportTest.php`'s table only renders when both `itemId` and `warehouseId` are set (`@if ($itemId && $warehouseId)`) — check its existing tests' exact property names and mirror them (likely `->set('itemId', ...)->set('warehouseId', ...)`).

- [ ] **Step 2: Write the failing tests**

Add to `ItemMovementCardReportTest.php` (adapt the exact setup — item/warehouse/movement creation — to match whatever this file's existing passing test already does to get a non-empty `$rows` result; you need at least the `@if` condition true to see the table, rows themselves can be empty):

```php
    public function test_the_movement_table_has_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(ItemMovementCardReport::class, ['company' => $company])
            ->set('itemId', $item->id)
            ->set('warehouseId', $warehouse->id)
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
    }
```

For `ItemBulkImportTest.php`, first read the file's existing test that reaches the preview table (it will show you exactly how `$parsedRows` gets populated — likely via `UploadedFile::fake()` and calling `preview`). Write a new test using that exact same setup, then add:
```php
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
```
to the end of the assertion chain (or as a separate test — match whichever style keeps the file most readable given what you find).

- [ ] **Step 3: Run both tests to verify they fail**

Run: `php artisan test --filter=ItemBulkImportTest` and `php artisan test --filter=ItemMovementCardReportTest`
Expected: both new/modified tests FAIL — neither file currently has `bg-gray-50` or `hover:bg-orange-50`.

- [ ] **Step 4: Update `item-bulk-import.blade.php`'s table**

Find:
```blade
            <table class="min-w-full divide-y divide-gray-200">
                <thead>
                    <tr class="text-left text-sm text-gray-500">
                        <th class="py-2 px-4">Ред</th>
```
Replace with:
```blade
            <table class="min-w-full divide-y divide-gray-200">
                <thead>
                    <tr class="text-left text-sm text-gray-500 bg-gray-50">
                        <th class="py-2 px-4">Ред</th>
```

Find:
```blade
                    @foreach ($parsedRows as $row)
                        <tr class="text-sm" wire:key="preview-row-{{ $row['row_number'] }}">
```
Replace with:
```blade
                    @foreach ($parsedRows as $row)
                        <tr class="text-sm hover:bg-orange-50" wire:key="preview-row-{{ $row['row_number'] }}">
```

- [ ] **Step 5: Update `item-movement-card-report.blade.php`'s table**

Find:
```blade
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-2 px-4">Датум</th>
```
Replace with:
```blade
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-2 px-4">Датум</th>
```

Find:
```blade
                @forelse ($rows as $row)
                    <tr class="text-sm">
                        <td class="py-2 px-4">{{ \App\Support\Format::date($row['date']) }}</td>
```
Replace with:
```blade
                @forelse ($rows as $row)
                    <tr class="text-sm hover:bg-orange-50">
                        <td class="py-2 px-4">{{ \App\Support\Format::date($row['date']) }}</td>
```

- [ ] **Step 6: Run both tests to verify they pass**

Run: `php artisan test --filter=ItemBulkImportTest` and `php artisan test --filter=ItemMovementCardReportTest`
Expected: PASS (new and pre-existing).

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add resources/views/livewire/inventory/item-bulk-import.blade.php resources/views/livewire/inventory/item-movement-card-report.blade.php tests/Feature/ItemBulkImportTest.php tests/Feature/ItemMovementCardReportTest.php
git commit -m "feat(ui): add header/hover pattern to bulk-import preview and movement card tables"
```

---

## Task 3: `stock-on-hand-report.blade.php` (2 tables) + `stock-valuation-report.blade.php`

`stock-on-hand-report.blade.php` has two tables — one per branch of `@if ($warehouseId)` — both need the same treatment.

**Files:**
- Modify: `resources/views/livewire/inventory/stock-on-hand-report.blade.php`
- Modify: `resources/views/livewire/inventory/stock-valuation-report.blade.php`
- Test: `tests/Feature/StockOnHandReportTest.php`
- Test: `tests/Feature/StockValuationReportTest.php`

**Interfaces:**
- No PHP changes to either component.

- [ ] **Step 1: Read both existing test files first**

`StockOnHandReportTest.php`'s default (no `warehouseId` set) view renders the "totals across all warehouses" branch (the `@else` table) — confirm this by reading its existing `test_it_shows_totals_across_warehouses_by_default` test, and mirror its exact setup (uses `app(StockMovementService::class)->receipt(...)` to create real stock, not a raw model factory).

- [ ] **Step 2: Write the failing tests**

Add to `StockOnHandReportTest.php`:

```php
    public function test_both_the_totals_and_per_warehouse_tables_have_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Widget']);
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', '2026-01-01', $admin->id);

        $this->actingAs($admin);

        $test = Livewire::test(StockOnHandReport::class, ['company' => $company])
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);

        $test->set('warehouseId', $warehouse->id)
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
    }
```

Add to `StockValuationReportTest.php` (mirror its existing test's exact setup for populating a valuation row — likely also via `StockMovementService`):

```php
    public function test_the_valuation_table_has_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', '2026-01-01', $admin->id);

        $this->actingAs($admin);

        Livewire::test(StockValuationReport::class, ['company' => $company])
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
    }
```

(If `StockValuationReportTest.php`'s existing tests populate data differently — e.g. a different service call — use that exact pattern instead of guessing.)

- [ ] **Step 3: Run both tests to verify they fail**

Run: `php artisan test --filter=StockOnHandReportTest` and `php artisan test --filter=StockValuationReportTest`
Expected: both new tests FAIL.

- [ ] **Step 4: Update `stock-on-hand-report.blade.php`'s two tables**

Find (the `@if ($warehouseId)` branch's table):
```blade
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-2 px-4">Шифра</th>
                    <th class="py-2 px-4">Артикл</th>
                    <th class="py-2 px-4 text-right">Количина</th>
                    <th class="py-2 px-4 text-right">Просечна цена</th>
                    <th class="py-2 px-4 text-right">Набавна вредност</th>
                    <th class="py-2 px-4 text-right">Продажна вредност</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    <tr class="text-sm">
                        <td class="py-2 px-4 font-mono">{{ $row['item_code'] }}</td>
```
Replace with:
```blade
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-2 px-4">Шифра</th>
                    <th class="py-2 px-4">Артикл</th>
                    <th class="py-2 px-4 text-right">Количина</th>
                    <th class="py-2 px-4 text-right">Просечна цена</th>
                    <th class="py-2 px-4 text-right">Набавна вредност</th>
                    <th class="py-2 px-4 text-right">Продажна вредност</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    <tr class="text-sm hover:bg-orange-50">
                        <td class="py-2 px-4 font-mono">{{ $row['item_code'] }}</td>
```

Find (the `@else` branch's table):
```blade
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-2 px-4">Шифра</th>
                    <th class="py-2 px-4">Артикл</th>
                    <th class="py-2 px-4 text-right">Вкупна количина</th>
                    <th class="py-2 px-4 text-right">Вкупна набавна вредност</th>
                    <th class="py-2 px-4 text-right">Вкупна продажна вредност</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($totals as $row)
                    <tr class="text-sm">
                        <td class="py-2 px-4 font-mono">{{ $row['item_code'] }}</td>
```
Replace with:
```blade
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-2 px-4">Шифра</th>
                    <th class="py-2 px-4">Артикл</th>
                    <th class="py-2 px-4 text-right">Вкупна количина</th>
                    <th class="py-2 px-4 text-right">Вкупна набавна вредност</th>
                    <th class="py-2 px-4 text-right">Вкупна продажна вредност</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($totals as $row)
                    <tr class="text-sm hover:bg-orange-50">
                        <td class="py-2 px-4 font-mono">{{ $row['item_code'] }}</td>
```

- [ ] **Step 5: Update `stock-valuation-report.blade.php`'s table**

Find:
```blade
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">{{ $groupBy === 'warehouse' ? 'Магацин' : ($groupBy === 'category' ? 'Категорија' : '') }}</th>
                <th class="py-2 px-4 text-right">Вкупна вредност</th>
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
                <th class="py-2 px-4">{{ $groupBy === 'warehouse' ? 'Магацин' : ($groupBy === 'category' ? 'Категорија' : '') }}</th>
                <th class="py-2 px-4 text-right">Вкупна вредност</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($rows as $row)
                <tr class="text-sm hover:bg-orange-50">
```

- [ ] **Step 6: Run both tests to verify they pass**

Run: `php artisan test --filter=StockOnHandReportTest` and `php artisan test --filter=StockValuationReportTest`
Expected: PASS (new and pre-existing).

- [ ] **Step 7: Confirm the PDF template needs no changes**

Open `resources/views/pdf/stock-on-hand.blade.php` and confirm it already has the `#ff6600` accent bar and `#f9fafb` table header from an earlier phase (per this plan's Global Constraints). Do not edit this file. If, contrary to expectation, it does NOT already have this styling, STOP and report back rather than guessing at a fix.

- [ ] **Step 8: Run the full test suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add resources/views/livewire/inventory/stock-on-hand-report.blade.php resources/views/livewire/inventory/stock-valuation-report.blade.php tests/Feature/StockOnHandReportTest.php tests/Feature/StockValuationReportTest.php
git commit -m "feat(ui): add header/hover pattern to stock-on-hand and stock-valuation tables"
```

---

## After this plan

This completes the Inventory module. `stock-movement-form.blade.php` was verified to need no changes and was intentionally excluded. The next module in the design's stated rollout order is Фактурирање (Invoicing — Partners, Sales Invoices, Purchase Invoices) — write it as its own plan (Plan D) once this one is executed and reviewed, following the same investigate-first approach.
