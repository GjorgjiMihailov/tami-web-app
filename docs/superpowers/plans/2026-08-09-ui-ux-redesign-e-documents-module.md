# UI/UX Redesign — Plan E: Documents Module Rollout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply the established "data table" pattern (`bg-gray-50` header, `hover:bg-orange-50` rows) to the Documents module's two table screens.

**Architecture:** The smallest plan in this rollout so far. Investigation found only 2 screens actually need changes; a 3rd screen (`ddv04-report.blade.php`) was investigated and deliberately excluded — its "tables" are label/value regulatory field pairs with no header row at all, not scannable record lists, so the header/hover pattern has nothing to attach to and would add no value. `document-manager.blade.php` is a shared component embedded inside 4 already-redesigned screens from Plan D (`journal-entry-form`, `partner-show`, `sales-invoice-show`, `purchase-invoice-show`) — changing it is expected to visibly affect its rendering wherever it's embedded, which is the whole point of it being a shared component, not a scope leak.

**Tech Stack:** Laravel 13 + Livewire (class-based components) + Blade, Tailwind CSS 3 (JIT), Pest/PHPUnit `Livewire::test(...)` component tests.

## Global Constraints

- Visual/UX changes only. Never touch controllers, Livewire component PHP logic (`App\Livewire\DocumentIndex`, `App\Livewire\DocumentManager`), calculations, validations, or the content/structure of any legal document.
- No new business logic or computed data.
- Header/row pattern must be exactly `bg-gray-50` (header) and `hover:bg-orange-50` (data rows) — matching the precedent from Plans B/C/D exactly.
- **`resources/views/livewire/reports/ddv04-report.blade.php` is verified to need zero changes and is explicitly NOT a task in this plan.** Its two tables have no `<thead>` — every row is a `<td>label</td><td>value</td>` pair for one specific, legally-defined ДДВ-04 field code (01, 02, 03… 31), not a repeating list of records. There is no header row to color and no benefit to hovering a single fixed-position field row. This is also the module's most legally-sensitive screen (ДДВ-04 field codes/values are statutory) — leaving it completely untouched is both the correct visual call and the safest one.
- `document-manager.blade.php`'s table sits inside a normally-padded `<x-card class="mt-4">` (not `padding="p-0" class="overflow-hidden"`), the same structural shape as the invoice line-items tables from Plan D Task 4 — apply the header/hover classes directly to the existing `<tr>` elements, do not add a new card wrapper or change the surrounding card's padding.
- Existing tests in `DocumentIndexTest.php` and `DocumentManagerTest.php` must keep passing unless a task explicitly changes that exact behavior (neither does — every change here is additive to class strings).

---

## Task 1: `document-index.blade.php`

**Files:**
- Modify: `resources/views/livewire/document-index.blade.php`
- Test: `tests/Feature/DocumentIndexTest.php`

**Interfaces:**
- No PHP changes to `App\Livewire\DocumentIndex`.

- [ ] **Step 1: Read the existing test file first**

Uses `Livewire::test(DocumentIndex::class, ['company' => $company])`. Its existing `test_it_lists_documents_across_entity_types_for_the_company` shows the exact pattern for seeding a document: `Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id, 'category' => 'Invoice', 'original_filename' => 'bill.pdf'])`. Mirror this exactly — `Document::factory()` already exists, don't hand-write a `::create()` call.

- [ ] **Step 2: Write the failing test**

Add to `DocumentIndexTest.php`:

```php
    public function test_the_document_table_has_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create();
        Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id, 'category' => 'Invoice', 'original_filename' => 'bill.pdf']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        $html = Livewire::test(DocumentIndex::class, ['company' => $company])->html();

        $this->assertSame(1, substr_count($html, 'bg-gray-50'));
        $this->assertSame(1, substr_count($html, 'hover:bg-orange-50'));
    }
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=DocumentIndexTest`
Expected: FAIL — the file currently has neither class anywhere, so both `substr_count` calls return 0.

- [ ] **Step 4: Update `document-index.blade.php`'s table**

Find:
```blade
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Датотека</th>
                <th class="py-2 px-4">Категорија</th>
                <th class="py-2 px-4">Запис</th>
                <th class="py-2 px-4">Прикачено од</th>
                <th class="py-2 px-4">Датум</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($documents as $document)
                @php
```
Replace with:
```blade
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500 bg-gray-50">
                <th class="py-2 px-4">Датотека</th>
                <th class="py-2 px-4">Категорија</th>
                <th class="py-2 px-4">Запис</th>
                <th class="py-2 px-4">Прикачено од</th>
                <th class="py-2 px-4">Датум</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($documents as $document)
                @php
```

Find:
```blade
                @endphp
                <tr class="text-sm">
                    <td class="py-2 px-4">
                        <a href="{{ route('documents.download', [$company, $document]) }}" class="text-brand hover:underline">{{ $document->original_filename }}</a>
```
Replace with:
```blade
                @endphp
                <tr class="text-sm hover:bg-orange-50">
                    <td class="py-2 px-4">
                        <a href="{{ route('documents.download', [$company, $document]) }}" class="text-brand hover:underline">{{ $document->original_filename }}</a>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=DocumentIndexTest`
Expected: PASS (new and pre-existing).

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add resources/views/livewire/document-index.blade.php tests/Feature/DocumentIndexTest.php
git commit -m "feat(ui): add header/hover pattern to document table"
```

---

## Task 2: `document-manager.blade.php` (shared component — embedded in 4 already-redesigned screens)

This component's table lives inside a normally-padded card, not a `padding="p-0"` one — same structural shape as Plan D's invoice line-items tables. Changing it will visibly change the embedded document lists on `journal-entry-form.blade.php`, `partner-show.blade.php`, `sales-invoice-show.blade.php`, and `purchase-invoice-show.blade.php` — that's expected, not a scope leak, since none of those 4 screens' own plans (B and D) touched this shared sub-component.

**Files:**
- Modify: `resources/views/livewire/document-manager.blade.php`
- Test: `tests/Feature/DocumentManagerTest.php`

**Interfaces:**
- No PHP changes to `App\Livewire\DocumentManager` (the `upload`/`delete` methods and the `newFile`/`newCategory`/`newNote` public properties stay untouched).

- [ ] **Step 1: Read the existing test file first**

Uses `Livewire::test(DocumentManager::class, ['documentable' => $invoice])`. Its existing `test_uploading_a_document_attaches_it_to_the_purchase_invoice` test needs `Storage::fake('google')` because it actually uploads a file — your new test doesn't need to upload anything, just seed an existing `Document` the same way `DocumentIndexTest` does (`Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id, ...])`), so `Storage::fake('google')` is not required for it.

- [ ] **Step 2: Write the failing test**

Add to `DocumentManagerTest.php`:

```php
    public function test_the_document_table_has_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create();
        Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id, 'category' => 'Invoice', 'original_filename' => 'bill.pdf']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $html = Livewire::test(DocumentManager::class, ['documentable' => $invoice])->html();

        $this->assertSame(1, substr_count($html, 'bg-gray-50'));
        $this->assertSame(1, substr_count($html, 'hover:bg-orange-50'));
    }
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=DocumentManagerTest`
Expected: FAIL — neither class exists in the file yet.

- [ ] **Step 4: Update `document-manager.blade.php`'s table**

Find:
```blade
    <table class="min-w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500">
                <th class="py-1">Датотека</th>
```
Replace with:
```blade
    <table class="min-w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 bg-gray-50">
                <th class="py-1">Датотека</th>
```

Find:
```blade
        <tbody>
            @forelse ($documents as $document)
                <tr>
                    <td class="py-1">
                        <a href="{{ route('documents.download', [$documentable->company_id, $document]) }}" class="text-brand hover:underline">
```
Replace with:
```blade
        <tbody>
            @forelse ($documents as $document)
                <tr class="hover:bg-orange-50">
                    <td class="py-1">
                        <a href="{{ route('documents.download', [$documentable->company_id, $document]) }}" class="text-brand hover:underline">
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=DocumentManagerTest`
Expected: PASS (new and pre-existing — including `test_uploading_a_document_attaches_it_to_the_purchase_invoice`, which doesn't assert on table classes and should be unaffected).

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test`
Expected: PASS. Since this component is embedded in `journal-entry-form.blade.php`, `partner-show.blade.php`, `sales-invoice-show.blade.php`, and `purchase-invoice-show.blade.php`, this full-suite run is the check that nothing in those 4 screens' own tests broke — read any failure there carefully rather than assuming it's unrelated.

- [ ] **Step 7: Commit**

```bash
git add resources/views/livewire/document-manager.blade.php tests/Feature/DocumentManagerTest.php
git commit -m "feat(ui): add header/hover pattern to shared document-manager table"
```

---

## After this plan

This completes the Documents module. `ddv04-report.blade.php` was investigated and intentionally excluded (no header rows to style, legally-sensitive content, pattern doesn't fit its label/value layout). At this point every module in the design's stated rollout order (Сметководство, Инвентар, Фактурирање, Документи/Извештаи) has received the data-table pattern except the still-open cross-plan item flagged in Plans C and D's final reviews: the design spec's compact-density treatment for data-heavy screens has not been delivered by any plan so far. Before starting a new module, consider raising that decision with the user explicitly (fold it into a dedicated density task now that every module's tables share one proven pattern, or formally drop it from spec) rather than letting a 5th plan ship without it.
