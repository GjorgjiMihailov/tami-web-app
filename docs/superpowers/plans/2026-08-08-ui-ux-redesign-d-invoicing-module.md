# UI/UX Redesign — Plan D: Invoicing Module Rollout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply the established "data table" pattern (`bg-gray-50` header, `hover:bg-orange-50` rows) to the Invoicing module's table screens (Partners, Sales Invoices, Purchase Invoices).

**Architecture:** Mechanical rollout, same as Plans B and C, but with one added risk class: `sales-invoice-index.blade.php` and `purchase-invoice-index.blade.php` embed large `@script` blocks with real, hard-won е-Фактура signing/discovery/accept/reject Alpine.js logic (local hardware-token bridge calls, JWS signing round-trips). Every task touching these two files must treat the `@script` block as off-limits — read-only context, never edited, and independently re-verified untouched in review. Investigation found 3 of the module's 8 screens (`partner-show.blade.php`, `sales-invoice-form.blade.php`, `purchase-invoice-form.blade.php`) have **no `<table>` element at all** — their line-item editors use flex/div layouts, not tables — and both PDF templates (`sales-invoice.blade.php`, `partner-list.blade.php`) are already fully warm-styled from an earlier phase. None of those 5 files are tasks in this plan.

**Tech Stack:** Laravel 13 + Livewire (class-based components) + Blade, Alpine.js (`@script` blocks, untouched), Tailwind CSS 3 (JIT), Pest/PHPUnit `Livewire::test(...)` component tests.

## Global Constraints

- Visual/UX changes only. Never touch controllers, Livewire component PHP logic (`App\Livewire\PartnerIndex`, `App\Livewire\Invoicing\*`), calculations, validations, or the content/structure of any legal document (е-Фактура JSON/JWS format, ДДВ fields).
- No new business logic or computed data.
- Header/row pattern must be exactly `bg-gray-50` (header) and `hover:bg-orange-50` (data rows) — matching Plans B/C's precedent exactly.
- **Every `@script`/`@endscript` block, every `x-data="..."` Alpine component definition, every `wire:click`/`wire:model`/`wire:submit`, and every route name in `sales-invoice-index.blade.php` and `purchase-invoice-index.blade.php` must be byte-identical before and after.** These blocks implement the local signing-bridge integration (`http://127.0.0.1:9847/...` calls) — a single accidental character change here is a functional regression with no visual symptom, and is one of the highest-value pieces of business logic in this entire project. Only `<tr>`/`<thead>` class attributes inside the `<table>` markup change; nothing inside or below the `@script` tag is ever touched.
- `sales-invoice-show.blade.php` and `purchase-invoice-show.blade.php` each have TWO tables: a line-items table (has a `<thead>`, gets the full header/hover pattern) and a payments table (no `<thead>`, bare `<tr>` rows, listing recorded payments). **The payments table is a deliberate exception — it does NOT get the pattern** (it's a short, simple list with no header row to visually anchor, and adding hover to 1-3 rows of payment history adds no scanning value). Per the lesson from Plan C's final review: this exception must ship with a real test proving the payments table's rows stay unstyled, not just a comment — Task 4 below specifies exactly how.
- The following files are **verified to need zero changes** and are explicitly NOT tasks in this plan: `partner-show.blade.php` (no table — pure form/info display), `sales-invoice-form.blade.php` (no table — div/flex-based line editor), `purchase-invoice-form.blade.php` (no table — div/flex-based line editor), `resources/views/pdf/sales-invoice.blade.php` and `resources/views/pdf/partner-list.blade.php` (both already have the `#ff6600` accent bar, `#fff3ea` peach boxes, and `#f9fafb` table headers from an earlier phase).
- Existing tests in `PartnerIndexTest.php`, `SalesInvoiceIndexTest.php`, `PurchaseInvoiceIndexTest.php`, `SalesInvoiceShowTest.php`, `PurchaseInvoiceShowTest.php` must keep passing unless a task explicitly changes that exact behavior (none do — every change here is additive to class strings).

---

## Task 1: `partner-index.blade.php`

The lowest-risk task in this plan — a plain list screen, already `<x-card>`-wrapped, no embedded scripts.

**Files:**
- Modify: `resources/views/livewire/partner-index.blade.php`
- Test: `tests/Feature/PartnerIndexTest.php`

**Interfaces:**
- No PHP changes to `App\Livewire\PartnerIndex`.

- [ ] **Step 1: Read the existing test file first**

Uses `Livewire::test(PartnerIndex::class, ['company' => $company])` with `Role::findOrCreate(...)` in `setUp()` — mirror this exact pattern.

- [ ] **Step 2: Write the failing test**

Add to `PartnerIndexTest.php`:

```php
    public function test_the_partner_table_has_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->for($company)->create(['name' => 'Acme DOOEL']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(PartnerIndex::class, ['company' => $company])
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
    }
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=PartnerIndexTest`
Expected: FAIL — the file currently has neither class anywhere.

- [ ] **Step 4: Update `partner-index.blade.php`'s table**

Find:
```blade
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Назив</th>
                <th class="py-2 px-4">ЕДБ</th>
                <th class="py-2 px-4">Е-пошта</th>
                <th class="py-2 px-4">Телефон</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($partners as $partner)
                <tr class="text-sm">
```
Replace with:
```blade
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500 bg-gray-50">
                <th class="py-2 px-4">Назив</th>
                <th class="py-2 px-4">ЕДБ</th>
                <th class="py-2 px-4">Е-пошта</th>
                <th class="py-2 px-4">Телефон</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($partners as $partner)
                <tr class="text-sm hover:bg-orange-50">
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=PartnerIndexTest`
Expected: PASS (new and pre-existing).

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add resources/views/livewire/partner-index.blade.php tests/Feature/PartnerIndexTest.php
git commit -m "feat(ui): add header/hover pattern to partner table"
```

---

## Task 2: `sales-invoice-index.blade.php` (contains a large `@script` block — read-only, do not touch)

**Files:**
- Modify: `resources/views/livewire/invoicing/sales-invoice-index.blade.php`
- Test: `tests/Feature/SalesInvoiceIndexTest.php`

**Interfaces:**
- No PHP changes. The `@script` block (Alpine components `efakturaStatusRefresh` and `efakturaPdfFetch`, calling `http://127.0.0.1:9847/...` and several `route('sales-invoices.efaktura.*')` endpoints) is completely off-limits — it lives far below the `<table>` in this file and this task's edit does not go anywhere near it, but you must visually confirm the file's `@script`...`@endscript` block is byte-identical before committing (a quick `diff` mental check against what you read in Step 1 is enough — don't paste/retype that block).

- [ ] **Step 1: Read the whole file first**

Confirm the table (lines ~26-83) is the only thing you'll touch, and note where `@script` starts (line ~85) so you know exactly where your edit must stop.

- [ ] **Step 2: Read the existing test file**

Uses `Livewire::test(SalesInvoiceIndex::class, ['company' => $company])` — check its exact `SalesInvoice::factory()` usage pattern (likely needs a `Partner` too, since the table shows `$invoice->partner->name`).

- [ ] **Step 3: Write the failing test**

Add to `SalesInvoiceIndexTest.php` (adapt exact factory calls to match this file's existing convention if it differs):

```php
    public function test_the_invoice_table_has_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(SalesInvoiceIndex::class, ['company' => $company])
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
    }
```

- [ ] **Step 4: Run test to verify it fails**

Run: `php artisan test --filter=SalesInvoiceIndexTest`
Expected: FAIL.

- [ ] **Step 5: Update ONLY the table's header/row classes**

Find:
```blade
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Број</th>
```
Replace with:
```blade
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500 bg-gray-50">
                <th class="py-2 px-4">Број</th>
```

Find:
```blade
            @forelse ($invoices as $invoice)
                <tr class="text-sm">
                    <td class="py-2 px-4">{{ $invoice->invoice_number ? "{$invoice->fiscal_year}/{$invoice->invoice_number}" : '—' }}</td>
```
Replace with:
```blade
            @forelse ($invoices as $invoice)
                <tr class="text-sm hover:bg-orange-50">
                    <td class="py-2 px-4">{{ $invoice->invoice_number ? "{$invoice->fiscal_year}/{$invoice->invoice_number}" : '—' }}</td>
```

- [ ] **Step 6: Verify the `@script` block is unchanged**

Read the file again after your edit. Confirm the `@script`/`@endscript` block (the `efakturaStatusRefresh`/`efakturaPdfFetch` Alpine components) is present, complete, and identical to what you read in Step 1 — same line count, same content. If you notice ANY difference, you made an unintended edit — revert and redo Step 5 more carefully.

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=SalesInvoiceIndexTest`
Expected: PASS (new and pre-existing).

- [ ] **Step 8: Run the full test suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add resources/views/livewire/invoicing/sales-invoice-index.blade.php tests/Feature/SalesInvoiceIndexTest.php
git commit -m "feat(ui): add header/hover pattern to sales invoice table"
```

---

## Task 3: `purchase-invoice-index.blade.php` (2 tables, contains an even larger `@script` block — read-only, do not touch)

This file has TWO tables: the "Неодлучени е-Фактури" pending-documents table (only rendered `@if ($pendingDocuments->isNotEmpty())`) and the main purchase-invoices table (always rendered). Both need the pattern. The `@script` block here (4 Alpine components: discover/accept/reject/pdf-fetch, plus 3 shared helper functions) is the largest and most business-critical in the whole app — same off-limits treatment as Task 2, even more carefully.

**Files:**
- Modify: `resources/views/livewire/invoicing/purchase-invoice-index.blade.php`
- Test: `tests/Feature/PurchaseInvoiceIndexTest.php`

**Interfaces:**
- No PHP changes. The `@script` block (helpers `signViaBridge`/`readTokenCertificate`/`postJson`, Alpine components `incomingEfakturaDiscover`/`incomingEfakturaAccept`/`incomingEfakturaReject`/`incomingEfakturaPdfFetch`) is completely off-limits.

- [ ] **Step 1: Read the whole file first**

Identify both tables (the pending-documents one starts ~line 27, the main one ~line 102) and note where `@script` starts (~line 146).

- [ ] **Step 2: Read the existing test file**

Check `PurchaseInvoiceIndexTest.php`'s exact `Livewire::test(PurchaseInvoiceIndex::class, ...)` setup and `PurchaseInvoice::factory()`/`Partner::factory()` usage.

- [ ] **Step 3: Write the failing test**

Add to `PurchaseInvoiceIndexTest.php`:

```php
    public function test_the_purchase_invoice_table_has_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
    }
```

(This test only exercises the main table — the pending-documents table only renders when `$pendingDocuments` is non-empty, which requires seeding an `IncomingEfakturaDocument`; that's covered separately in Step 3b below.)

- [ ] **Step 3b: Write a second failing test for the pending-documents table**

The `incoming_efaktura_documents` table's migration (`database/migrations/2026_08_06_100000_create_incoming_efaktura_documents_table.php`) has two required-but-easy-to-forget non-nullable columns beyond the obvious ones: `payload_json` (no default) and `discovered_at` (a timestamp, no default). Include them:

```php
    public function test_the_pending_documents_table_has_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        \App\Models\IncomingEfakturaDocument::create([
            'company_id' => $company->id,
            'euid' => 'TEST-EUID-1',
            'seller_name' => 'Test Seller',
            'payload_json' => '{}',
            'discovered_at' => now(),
            'decision' => null,
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        $html = Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])->html();

        $this->assertSame(2, substr_count($html, 'bg-gray-50'));
        $this->assertGreaterThanOrEqual(1, substr_count($html, 'hover:bg-orange-50'));
    }
```

(The `substr_count($html, 'bg-gray-50')` of exactly 2 confirms BOTH table headers got the class — one miss would show as 1.)

- [ ] **Step 4: Run both tests to verify they fail**

Run: `php artisan test --filter=PurchaseInvoiceIndexTest`
Expected: both new tests FAIL.

- [ ] **Step 5: Update the pending-documents table's header/row classes**

Find:
```blade
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-2 px-4">Добавувач</th>
```
Replace with:
```blade
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-2 px-4">Добавувач</th>
```

Find:
```blade
                @foreach ($pendingDocuments as $document)
                    <tr class="text-sm">
                        <td class="py-2 px-4">{{ $document->seller_name }} <span class="text-gray-400">({{ $document->seller_tax_id }})</span></td>
```
Replace with:
```blade
                @foreach ($pendingDocuments as $document)
                    <tr class="text-sm hover:bg-orange-50">
                        <td class="py-2 px-4">{{ $document->seller_name }} <span class="text-gray-400">({{ $document->seller_tax_id }})</span></td>
```

- [ ] **Step 6: Update the main purchase-invoices table's header/row classes**

Find:
```blade
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Бр. кај добавувач</th>
```
Replace with:
```blade
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500 bg-gray-50">
                <th class="py-2 px-4">Бр. кај добавувач</th>
```

Find:
```blade
            @forelse ($invoices as $invoice)
                <tr class="text-sm">
                    <td class="py-2 px-4">{{ $invoice->supplier_invoice_number }}</td>
```
Replace with:
```blade
            @forelse ($invoices as $invoice)
                <tr class="text-sm hover:bg-orange-50">
                    <td class="py-2 px-4">{{ $invoice->supplier_invoice_number }}</td>
```

- [ ] **Step 7: Verify the `@script` block is unchanged**

Read the file again after your edits. Confirm the entire `@script`/`@endscript` block (all 4 Alpine components and the 3 shared helper functions) is present, complete, and identical to Step 1 — same line count, same content, including the code comment about `const ... = (...) =>` vs `function name(){}` (this comment documents a real bug fixed in an earlier phase; do not "clean it up" or move it).

- [ ] **Step 8: Run both tests to verify they pass**

Run: `php artisan test --filter=PurchaseInvoiceIndexTest`
Expected: PASS (both new tests and all pre-existing ones).

- [ ] **Step 9: Run the full test suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add resources/views/livewire/invoicing/purchase-invoice-index.blade.php tests/Feature/PurchaseInvoiceIndexTest.php
git commit -m "feat(ui): add header/hover pattern to both purchase invoice tables"
```

---

## Task 4: `sales-invoice-show.blade.php` + `purchase-invoice-show.blade.php` (line-items table gets the pattern; payments table deliberately does not — and that exception ships with a real test)

**Files:**
- Modify: `resources/views/livewire/invoicing/sales-invoice-show.blade.php`
- Modify: `resources/views/livewire/invoicing/purchase-invoice-show.blade.php`
- Test: `tests/Feature/SalesInvoiceShowTest.php`
- Test: `tests/Feature/PurchaseInvoiceShowTest.php`

**Interfaces:**
- No PHP changes to either component. Neither file has a `@script` block, but `sales-invoice-show.blade.php` does have one small `x-data="efakturaSend()"` Alpine block further down (the "Потпиши и испрати" button) — same off-limits treatment as Tasks 2/3, it's just smaller here.

- [ ] **Step 1: Read both existing test files first**

`SalesInvoiceShowTest.php`'s `test_recording_a_payment_and_cancel_button_is_hidden_once_paid` shows the exact setup for BOTH a line item and a recorded payment on the same invoice (confirmed status, `seedAccounts()` helper, `JournalEntry` linkage) — this is exactly the setup Step 2's new test needs, since it must prove the hover class appears on the line-items row but NOT on the payments row.

- [ ] **Step 2: Write the failing tests**

Add to `SalesInvoiceShowTest.php` (mirroring `test_recording_a_payment_and_cancel_button_is_hidden_once_paid`'s exact setup):

```php
    public function test_the_line_items_table_gets_the_hover_treatment_but_the_payments_table_does_not(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01', 'status' => 'confirmed', 'fiscal_year' => 2026, 'invoice_number' => 1]);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $entry = \App\Models\JournalEntry::factory()->for($company)->create();
        $invoice->update(['journal_entry_id' => $entry->id]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        // sales_invoice_payments.created_by is a required (non-nullable) foreign key to users —
        // create the payment AFTER $admin exists, and pass created_by explicitly.
        $invoice->payments()->create(['amount' => '50.00', 'payment_date' => '2026-03-10', 'payment_method' => 'bank', 'created_by' => $admin->id]);
        $this->actingAs($admin);

        $html = Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])->html();

        // The page has exactly one table with a <thead> (line items) — its header
        // and its one data row should carry the pattern, and nothing else on the
        // page should, or the payments table picked it up by accident.
        $this->assertSame(1, substr_count($html, 'bg-gray-50'));
        $this->assertSame(1, substr_count($html, 'hover:bg-orange-50'));
    }
```

Add to `PurchaseInvoiceShowTest.php` (mirror whatever equivalent payment-recording test already exists there, or the same pattern as above adapted to `PurchaseInvoice`/`purchase_invoice_payments`):

```php
    public function test_the_line_items_table_gets_the_hover_treatment_but_the_payments_table_does_not(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        // purchase_invoice_payments.created_by is a required (non-nullable) foreign key to users —
        // create the payment AFTER $admin exists, and pass created_by explicitly.
        $invoice->payments()->create(['amount' => '50.00', 'payment_date' => '2026-03-10', 'payment_method' => 'bank', 'created_by' => $admin->id]);
        $this->actingAs($admin);

        $html = Livewire::test(PurchaseInvoiceShow::class, ['company' => $company, 'purchaseInvoice' => $invoice])->html();

        $this->assertSame(1, substr_count($html, 'bg-gray-50'));
        $this->assertSame(1, substr_count($html, 'hover:bg-orange-50'));
    }
```

- [ ] **Step 3: Run both tests to verify they fail**

Run: `php artisan test --filter=SalesInvoiceShowTest` and `php artisan test --filter=PurchaseInvoiceShowTest`
Expected: both new tests FAIL with `substr_count` returning 0 for both classes (neither file has them yet).

- [ ] **Step 4: Update `sales-invoice-show.blade.php`'s line-items table ONLY**

Find:
```blade
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-1">Опис</th>
                    <th class="py-1">Кол.</th>
                    <th class="py-1">Ед. цена</th>
                    <th class="py-1">ДДВ %</th>
                    <th class="py-1">Вкупно за ставка</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->lines as $line)
                    <tr>
```
Replace with:
```blade
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 bg-gray-50">
                    <th class="py-1">Опис</th>
                    <th class="py-1">Кол.</th>
                    <th class="py-1">Ед. цена</th>
                    <th class="py-1">ДДВ %</th>
                    <th class="py-1">Вкупно за ставка</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->lines as $line)
                    <tr class="hover:bg-orange-50">
```

**Do NOT touch the payments `<table class="min-w-full text-sm mb-3">` further down** (the one inside `<h2>Плаќања</h2>`'s card) — its `<tr>` stays bare, with no class attribute, exactly as it is today.

- [ ] **Step 5: Update `purchase-invoice-show.blade.php`'s line-items table ONLY**

Find:
```blade
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-1">Опис</th>
                    <th class="py-1">Артикл/Сметка</th>
```
Replace with:
```blade
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 bg-gray-50">
                    <th class="py-1">Опис</th>
                    <th class="py-1">Артикл/Сметка</th>
```

Find:
```blade
            <tbody>
                @foreach ($invoice->lines as $line)
                    <tr>
                        <td class="py-1">{{ $line->description }}</td>
                        <td class="py-1">{{ $line->item?->name ?? $line->account?->code.' — '.$line->account?->name }}</td>
```
Replace with:
```blade
            <tbody>
                @foreach ($invoice->lines as $line)
                    <tr class="hover:bg-orange-50">
                        <td class="py-1">{{ $line->description }}</td>
                        <td class="py-1">{{ $line->item?->name ?? $line->account?->code.' — '.$line->account?->name }}</td>
```

**Do NOT touch the payments table further down** — same rule as Step 4.

- [ ] **Step 6: Verify the `efakturaSend()` Alpine block in `sales-invoice-show.blade.php` is unchanged**

Read the file again. Confirm the `x-data="efakturaSend()"` div and its `@script`/`@endscript` block are present, complete, and identical to what existed before your edit.

- [ ] **Step 7: Run both tests to verify they pass**

Run: `php artisan test --filter=SalesInvoiceShowTest` and `php artisan test --filter=PurchaseInvoiceShowTest`
Expected: PASS (both new tests and all pre-existing ones in both files).

- [ ] **Step 8: Run the full test suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add resources/views/livewire/invoicing/sales-invoice-show.blade.php resources/views/livewire/invoicing/purchase-invoice-show.blade.php tests/Feature/SalesInvoiceShowTest.php tests/Feature/PurchaseInvoiceShowTest.php
git commit -m "feat(ui): add header/hover to invoice line-items tables, leave payments table unstyled"
```

---

## After this plan

This completes the Invoicing module's table screens. `partner-show.blade.php`, `sales-invoice-form.blade.php`, and `purchase-invoice-form.blade.php` were verified to need no changes and were intentionally excluded. The next module in the design's stated rollout order is Документи/Извештаи (Documents & the ДДВ-04 report) — write it as its own plan (Plan E) once this one is executed and reviewed, following the same investigate-first approach.
