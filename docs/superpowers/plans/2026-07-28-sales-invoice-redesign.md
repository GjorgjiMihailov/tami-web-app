# Sales Invoice PDF Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the printed/downloaded Sales Invoice PDF (`resources/views/pdf/sales-invoice.blade.php`) into a branded, complete document — logo placement, compact issuer/recipient blocks, a corrected line-items table, a totals+payment-info card listing the company's bank accounts, and footnote handling for non-VAT-registered companies — without touching the on-screen `SalesInvoiceShow`/`SalesInvoiceForm`/`SalesInvoiceIndex` screens or any GL/numbering/payment logic.

**Architecture:** Single Blade view redesign across four tasks (header/parties, line-items table, totals+payment box, footnotes), each producing a complete, renderable file. `SalesInvoicePdfController` gets one small eager-loading addition (`company.bankAccounts`). No migrations, no new PHP classes — every field used already exists on `Company`, `Partner`, `SalesInvoice`/`SalesInvoiceLine` as of the Company Profile sub-project (2026-07-28).

**Tech Stack:** Laravel 13, `barryvdh/laravel-dompdf` (dompdf 3.1.6 — supports `display: flex`, used already by the existing template's `.header` rule), PHPUnit feature tests that render the Blade view directly via `view(...)->render()` for fast, content-level assertions (dompdf's binary PDF output isn't text-searchable in tests without an extra parsing dependency this project doesn't have, so structural/content correctness is verified at the HTML level — exactly as the existing `SalesInvoicePdfTest` already does at the HTTP-response level).

## Global Constraints

- Scope is `resources/views/pdf/sales-invoice.blade.php` + `app/Http/Controllers/SalesInvoicePdfController.php` (one eager-load line) + `tests/Feature/SalesInvoicePdfTest.php`. Do not touch `SalesInvoiceShow`, `SalesInvoiceForm`, `SalesInvoiceIndex`, or any service/model class.
- Brand color is `#ff6600` (already used app-wide since the 2026-07-23/24 visual redesign).
- Money formatting always goes through `\App\Support\Format::money()`; dates through `\App\Support\Format::date()`. Never format money/dates manually in the view.
- The line-items table must remain a real `<table><thead>…</thead><tbody>…</tbody></table>` (not divs/flex rows) so dompdf repeats the header row and flows rows correctly across a page break for long invoices.
- Logo images are embedded via an **absolute local filesystem path** (`Storage::disk('public')->path($path)`), never a URL — dompdf's remote-fetch is not enabled in this project, and this is the first PDF in the codebase to embed an image at all.
- Every money/date/label change must reuse the exact Macedonian wording already fixed in the approved design spec (`docs/superpowers/specs/2026-07-28-sales-invoice-redesign-design.md`) — do not paraphrase.
- After each task, run `php artisan test --filter=SalesInvoicePdfTest` and confirm all pass before committing.

---

## Reference: current file (before this plan)

`resources/views/pdf/sales-invoice.blade.php` (71 lines) renders: an `<h1>` invoice number, a flex `.header` row with issuer info (including bank accounts inline) / recipient info / dates, a plain items table (Опис, Кол., Ед. цена, ДДВ %, Вкупно — where "Вкупно" is actually the VAT-exclusive base, mislabeled), and a `.totals` block (Основа, ДДВ, Вкупно). No logo, no payment-info box, no footnotes, no page-break handling.

`app/Http/Controllers/SalesInvoicePdfController.php` currently does `$salesInvoice->load(['lines', 'partner', 'company'])` — no nested `bankAccounts` eager load.

---

### Task 1: Header, logo placement, and issuer/recipient blocks

**Files:**
- Modify: `resources/views/pdf/sales-invoice.blade.php` (full rewrite)
- Test: `tests/Feature/SalesInvoicePdfTest.php`

**Interfaces:**
- Consumes: `Company::logo_path`, `Company::logo_position` (`left`/`center`/`right`, default `left`), `Company::name/address/tax_id/registration_number/phone/email`, `Partner::name/address/tax_id`, `SalesInvoice::fiscal_year/invoice_number/invoice_date/due_date`, `\App\Support\Format::date()`.
- Produces: the page shell (`<div class="accent-bar">`, `<div class="content">`) and CSS classes (`.header-row`, `.logo-row`, `.badge`, `.party-box`) that Tasks 2–4 append inside `.content`, below the parties row.

- [ ] **Step 1: Write the failing tests**

Add these methods to `tests/Feature/SalesInvoicePdfTest.php` (keep the existing two methods and the `use`/`setUp` block as-is):

```php
    public function test_it_renders_the_logo_on_the_left_by_default(): void
    {
        $company = Company::factory()->create([
            'logo_path' => 'logos/1/logo.png',
            'logo_position' => 'left',
            'registration_number' => '7654321',
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'confirmed',
        ]);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $expectedPath = \Illuminate\Support\Facades\Storage::disk('public')->path('logos/1/logo.png');
        $this->assertStringContainsString($expectedPath, $html);
        $this->assertStringNotContainsString('row-reverse', $html);
        $this->assertStringNotContainsString('logo-row', $html);
        $this->assertSame(1, substr_count($html, '<img'));
    }

    public function test_it_renders_the_logo_on_the_right_when_configured(): void
    {
        $company = Company::factory()->create(['logo_path' => 'logos/1/logo.png', 'logo_position' => 'right']);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('row-reverse', $html);
        $this->assertSame(1, substr_count($html, '<img'));
    }

    public function test_it_renders_the_logo_centered_when_configured(): void
    {
        $company = Company::factory()->create(['logo_path' => 'logos/1/logo.png', 'logo_position' => 'center']);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('logo-row', $html);
        $this->assertSame(1, substr_count($html, '<img'));
    }

    public function test_it_renders_no_image_tag_when_company_has_no_logo(): void
    {
        $company = Company::factory()->create(['logo_path' => null]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_it_shows_compact_issuer_and_recipient_blocks(): void
    {
        $company = Company::factory()->create([
            'name' => 'Fajnens Badi DOOEL',
            'address' => 'ul. Primer 1, Skopje',
            'tax_id' => 'MK4032000000000',
            'registration_number' => '7654321',
            'phone' => '070123456',
            'email' => 'info@primer.mk',
        ]);
        $partner = Partner::factory()->for($company)->create([
            'name' => 'ABV Trgovija DOO',
            'address' => 'bul. Ilinden 5, Bitola',
            'tax_id' => 'MK4021000000000',
        ]);
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('Fajnens Badi DOOEL', $html);
        $this->assertStringContainsString('ul. Primer 1, Skopje', $html);
        $this->assertStringContainsString('ЕДБ: MK4032000000000', $html);
        $this->assertStringContainsString('ЕМБС: 7654321', $html);
        $this->assertStringContainsString('070123456', $html);
        $this->assertStringContainsString('info@primer.mk', $html);
        $this->assertStringContainsString('ABV Trgovija DOO', $html);
        $this->assertStringContainsString('bul. Ilinden 5, Bitola', $html);
        $this->assertStringContainsString('ЕДБ: MK4021000000000', $html);
    }

    public function test_it_omits_embs_segment_when_registration_number_is_blank(): void
    {
        $company = Company::factory()->create(['registration_number' => null]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringNotContainsString('ЕМБС', $html);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=SalesInvoicePdfTest`
Expected: the 6 new tests FAIL (old template has no `<img>`, no `logo-row`/`row-reverse` markers, no ЕМБС/phone/email in the header).

- [ ] **Step 3: Rewrite the view's header and parties section**

Replace the entire contents of `resources/views/pdf/sales-invoice.blade.php` with:

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans'; font-size: 11px; color: #1f2937; margin: 0; padding: 0; }
        .accent-bar { height: 6px; background-color: #ff6600; }
        .content { padding: 18px 24px; }
        .badge { display: inline-block; background-color: #fff3ea; color: #ff6600; font-weight: bold; font-size: 14px; padding: 6px 14px; border-radius: 8px; }
        .muted { color: #6b7280; }
        .small { font-size: 10px; }
        .header-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; }
        .logo-row { text-align: center; margin-bottom: 10px; }
        .parties-row { display: flex; gap: 12px; margin-bottom: 14px; }
        .party-box { flex: 1; background-color: #f9fafb; border-radius: 8px; padding: 8px 12px; }
        .party-box h4 { font-size: 9px; text-transform: uppercase; letter-spacing: .05em; color: #ff6600; margin: 0 0 4px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.items th { text-align: left; font-size: 10px; color: #6b7280; background-color: #f9fafb; padding: 6px; }
        table.items td { padding: 6px; border-bottom: 1px solid #f3f4f6; }
        .bottom-row { display: flex; gap: 12px; margin-top: 14px; }
        .pay-box { flex: 1; background-color: #f9fafb; border-radius: 8px; padding: 10px 12px; font-size: 10px; }
        .pay-box h4 { font-size: 9px; text-transform: uppercase; color: #6b7280; margin: 0 0 6px; }
        .totals-box { width: 210px; background-color: #fff3ea; border-radius: 8px; padding: 10px 14px; font-size: 11px; }
        .totals-box .row { display: flex; justify-content: space-between; padding: 2px 0; }
        .totals-box .grand { border-top: 1px solid #ffd4b0; font-weight: bold; margin-top: 4px; padding-top: 4px; color: #b34700; }
        .footnotes { margin-top: 16px; font-size: 9px; color: #6b7280; }
        .footnotes p { margin: 2px 0; }
    </style>
</head>
<body>
    <div class="accent-bar"></div>
    <div class="content">
        @php
            $company = $invoice->company;
            $logoPosition = $company->logo_position ?: 'left';
            $hasLogo = (bool) $company->logo_path;
            $logoPath = $hasLogo ? \Illuminate\Support\Facades\Storage::disk('public')->path($company->logo_path) : null;
            $vatRegistered = (bool) $company->is_vat_registered;
        @endphp

        @if ($hasLogo && $logoPosition === 'center')
            <div class="logo-row">
                <img src="{{ $logoPath }}" style="max-height: 56px;">
            </div>
        @endif

        <div class="header-row" style="{{ $logoPosition === 'right' ? 'flex-direction: row-reverse;' : '' }}">
            <div>
                @if ($hasLogo && $logoPosition !== 'center')
                    <img src="{{ $logoPath }}" style="max-height: 56px;">
                @endif
            </div>
            <div style="text-align: right;">
                <span class="badge">ФАКТУРА {{ $invoice->fiscal_year }}/{{ $invoice->invoice_number }}</span>
                <div class="small muted" style="margin-top: 6px;">
                    Датум на фактура: {{ \App\Support\Format::date($invoice->invoice_date) }}<br>
                    Датум на доспевање: {{ \App\Support\Format::date($invoice->due_date) }}
                </div>
            </div>
        </div>

        <div class="parties-row">
            <div class="party-box">
                <h4>Издавач</h4>
                <div><strong>{{ $company->name }}</strong></div>
                <div class="small muted">{{ $company->address }}</div>
                <div class="small muted">
                    ЕДБ: {{ $company->tax_id }}
                    @if ($company->registration_number)
                        · ЕМБС: {{ $company->registration_number }}
                    @endif
                </div>
                @if ($company->phone || $company->email)
                    <div class="small muted">{{ collect([$company->phone, $company->email])->filter()->implode(' · ') }}</div>
                @endif
            </div>
            <div class="party-box">
                <h4>Купувач</h4>
                <div><strong>{{ $invoice->partner->name }}</strong></div>
                <div class="small muted">{{ $invoice->partner->address }}</div>
                @if ($invoice->partner->tax_id)
                    <div class="small muted">ЕДБ: {{ $invoice->partner->tax_id }}</div>
                @endif
            </div>
        </div>

        {{-- Task 2 adds the items table here --}}
        {{-- Task 3 adds the bottom-row (payment info + totals) here --}}
        {{-- Task 4 adds footnotes here --}}
    </div>
</body>
</html>
```

Note: this intermediate state has no items table or totals — that's expected, Tasks 2–3 add them back. The two pre-existing tests (`test_it_downloads_a_pdf_for_a_confirmed_invoice`, `test_a_draft_invoice_cannot_be_downloaded_as_pdf`) only assert HTTP status/content-type, so they still pass against this intermediate file.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=SalesInvoicePdfTest`
Expected: all 8 tests PASS (2 pre-existing + 6 new).

- [ ] **Step 5: Commit**

```bash
git add resources/views/pdf/sales-invoice.blade.php tests/Feature/SalesInvoicePdfTest.php
git commit -m "feat: redesign sales invoice PDF header with logo placement and compact party blocks"
```

---

### Task 2: Line-items table (row numbers, VAT treatment label, gross total column)

**Files:**
- Modify: `resources/views/pdf/sales-invoice.blade.php`
- Test: `tests/Feature/SalesInvoicePdfTest.php`

**Interfaces:**
- Consumes: `$vatRegistered` (bool, defined in Task 1's `@php` block), `SalesInvoiceLine::description/quantity/unit_price/vat_rate/vat_treatment`, `SalesInvoiceLine::lineTotal(): string`, `SalesInvoiceLine::vatAmount(): string`, `\App\Support\Format::vatTreatment(string): string`, `\App\Support\Format::money()`.
- Produces: the `table.items` markup inside `.content`, between the parties row and the (not-yet-added) bottom row.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/SalesInvoicePdfTest.php`:

```php
    public function test_it_numbers_rows_and_shows_gross_total_per_line(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'First item', 'quantity' => '2', 'unit_price' => '500.00', 'vat_rate' => '18.00']);
        $invoice->lines()->create(['description' => 'Second item', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('Р.б.', $html);
        $this->assertStringContainsString('Вкупно со ДДВ', $html);
        // First line: 2 * 500.00 = 1000.00 base + 18% VAT (180.00) = 1180.00 gross
        $this->assertStringContainsString(\App\Support\Format::money('1180.00'), $html);
        // Second line: 1000.00 base + 18% VAT (180.00) = 1180.00 gross
        $this->assertStringContainsString(\App\Support\Format::money('1180.00'), $html);
    }

    public function test_it_shows_vat_treatment_label_for_non_standard_lines(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create([
            'description' => 'Export sale', 'quantity' => '1', 'unit_price' => '1000.00',
            'vat_rate' => '0.00', 'vat_treatment' => 'export',
        ]);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('(Извоз)', $html);
    }

    public function test_it_hides_the_vat_column_for_a_non_vat_registered_company(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => false]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringNotContainsString('ДДВ %', $html);
        $this->assertStringContainsString('>Вкупно<', $html);
        $this->assertStringNotContainsString('Вкупно со ДДВ', $html);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=SalesInvoicePdfTest`
Expected: the 3 new tests FAIL (no items table exists yet after Task 1's rewrite).

- [ ] **Step 3: Add the items table**

In `resources/views/pdf/sales-invoice.blade.php`, replace the `{{-- Task 2 adds the items table here --}}` comment with:

```blade
        <table class="items">
            <thead>
                <tr>
                    <th style="width: 24px;">Р.б.</th>
                    <th>Опис</th>
                    <th style="width: 50px;">Кол.</th>
                    <th style="width: 80px;">Ед. цена</th>
                    @if ($vatRegistered)
                        <th style="width: 100px;">ДДВ %</th>
                    @endif
                    <th style="width: 100px;">{{ $vatRegistered ? 'Вкупно со ДДВ' : 'Вкупно' }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->lines as $index => $line)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $line->description }}</td>
                        <td>{{ $line->quantity }}</td>
                        <td>{{ \App\Support\Format::money($line->unit_price) }}</td>
                        @if ($vatRegistered)
                            <td>{{ $line->vat_rate }}{{ $line->vat_treatment !== 'standard' ? ' ('.\App\Support\Format::vatTreatment($line->vat_treatment).')' : '' }}</td>
                        @endif
                        <td>{{ \App\Support\Format::money(bcadd($line->lineTotal(), $line->vatAmount(), 2)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=SalesInvoicePdfTest`
Expected: all 11 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/pdf/sales-invoice.blade.php tests/Feature/SalesInvoicePdfTest.php
git commit -m "feat: redesign sales invoice PDF line-items table with row numbers and gross-total column"
```

---

### Task 3: Totals + payment-info box (bank accounts)

**Files:**
- Modify: `resources/views/pdf/sales-invoice.blade.php`
- Modify: `app/Http/Controllers/SalesInvoicePdfController.php:19`
- Test: `tests/Feature/SalesInvoicePdfTest.php`

**Interfaces:**
- Consumes: `Company::bankAccounts()` (HasMany, ordered by `position`, fields `bank_name`/`account_number`), `SalesInvoice::subtotal()/vatTotal()/grandTotal()/balanceDue(): string` (from `HasInvoiceTotals`).
- Produces: the `.bottom-row` markup (payment box + totals box) inside `.content`, after the items table.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/SalesInvoicePdfTest.php`:

```php
    public function test_it_lists_all_company_bank_accounts_in_the_payment_box(): void
    {
        $company = Company::factory()->create();
        $company->bankAccounts()->create(['bank_name' => 'Комерцијална банка', 'account_number' => 'MK07300701104789126', 'position' => 0]);
        $company->bankAccounts()->create(['bank_name' => 'НЛБ Банка', 'account_number' => 'MK07210987654321098', 'position' => 1]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('Начин на плаќање', $html);
        $this->assertStringContainsString('Комерцијална банка', $html);
        $this->assertStringContainsString('MK07300701104789126', $html);
        $this->assertStringContainsString('НЛБ Банка', $html);
        $this->assertStringContainsString('MK07210987654321098', $html);
    }

    public function test_it_shows_all_four_totals_rows(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('Основа', $html);
        $this->assertStringContainsString('ДДВ', $html);
        $this->assertStringContainsString('Вкупно', $html);
        $this->assertStringContainsString('За доплата', $html);
        $this->assertStringContainsString(\App\Support\Format::money('1000.00'), $html); // subtotal
        $this->assertStringContainsString(\App\Support\Format::money('180.00'), $html); // vat total
        $this->assertStringContainsString(\App\Support\Format::money('1180.00'), $html); // grand total / balance due
    }

    public function test_pdf_controller_eager_loads_bank_accounts(): void
    {
        $company = Company::factory()->create();
        $company->bankAccounts()->create(['bank_name' => 'Комерцијална банка', 'account_number' => 'MK07300701104789126', 'position' => 0]);
        $partner = Partner::factory()->for($company)->create();
        $entry = \App\Models\JournalEntry::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'status' => 'confirmed',
            'fiscal_year' => 2026, 'invoice_number' => 2, 'journal_entry_id' => $entry->id,
        ]);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('sales-invoices.pdf', [$company, $invoice]));

        $response->assertOk();
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=SalesInvoicePdfTest`
Expected: `test_it_lists_all_company_bank_accounts_in_the_payment_box` and `test_it_shows_all_four_totals_rows` FAIL (no bottom-row exists yet). `test_pdf_controller_eager_loads_bank_accounts` currently PASSES already (it doesn't yet assert content, only HTTP 200) — that's fine, it becomes a real regression guard once Step 3 renders `bankAccounts` from the controller's eager-loaded relation.

- [ ] **Step 3: Add the bottom row and update the controller's eager load**

In `resources/views/pdf/sales-invoice.blade.php`, replace the `{{-- Task 3 adds the bottom-row (payment info + totals) here --}}` comment with:

```blade
        <div class="bottom-row">
            <div class="pay-box">
                <h4>Начин на плаќање</h4>
                @forelse ($company->bankAccounts as $bankAccount)
                    <div>{{ $bankAccount->bank_name ? $bankAccount->bank_name.': ' : '' }}{{ $bankAccount->account_number }}</div>
                @empty
                    <div class="muted">Нема внесена банкарска сметка.</div>
                @endforelse
            </div>
            <div class="totals-box">
                <div class="row"><span>Основа</span><span>{{ \App\Support\Format::money($invoice->subtotal()) }}</span></div>
                <div class="row"><span>ДДВ</span><span>{{ \App\Support\Format::money($invoice->vatTotal()) }}</span></div>
                <div class="row grand"><span>Вкупно</span><span>{{ \App\Support\Format::money($invoice->grandTotal()) }}</span></div>
                <div class="row"><span>За доплата</span><span>{{ \App\Support\Format::money($invoice->balanceDue()) }}</span></div>
            </div>
        </div>
```

In `app/Http/Controllers/SalesInvoicePdfController.php`, change line 19:

```php
        $salesInvoice->load(['lines', 'partner', 'company.bankAccounts']);
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=SalesInvoicePdfTest`
Expected: all 14 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/pdf/sales-invoice.blade.php app/Http/Controllers/SalesInvoicePdfController.php tests/Feature/SalesInvoicePdfTest.php
git commit -m "feat: add bank-account payment box and balance-due row to sales invoice PDF"
```

---

### Task 4: Footnotes (non-VAT-registered legal note + company footer note)

**Files:**
- Modify: `resources/views/pdf/sales-invoice.blade.php`
- Test: `tests/Feature/SalesInvoicePdfTest.php`

**Interfaces:**
- Consumes: `$vatRegistered` (from Task 1's `@php` block), `Company::invoice_footer_note` (nullable string).
- Produces: the `.footnotes` block at the end of `.content` — the final piece of the file.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/SalesInvoicePdfTest.php`:

```php
    public function test_it_shows_no_footnotes_when_vat_registered_and_no_custom_note(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true, 'invoice_footer_note' => null]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringNotContainsString('footnotes', $html);
    }

    public function test_it_shows_the_legal_note_for_a_non_vat_registered_company(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => false, 'invoice_footer_note' => null]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('Фирмава не е ДДВ обврзник.', $html);
    }

    public function test_it_shows_the_custom_footer_note(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true, 'invoice_footer_note' => 'Стоката не подлежи на рекламација.']);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('Стоката не подлежи на рекламација.', $html);
        $this->assertStringNotContainsString('Фирмава не е ДДВ обврзник.', $html);
    }

    public function test_it_shows_the_legal_note_before_the_custom_footer_note_when_both_apply(): void
    {
        $company = Company::factory()->create([
            'is_vat_registered' => false,
            'invoice_footer_note' => 'Стоката не подлежи на рекламација.',
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $legalPos = strpos($html, 'Фирмава не е ДДВ обврзник.');
        $customPos = strpos($html, 'Стоката не подлежи на рекламација.');
        $this->assertNotFalse($legalPos);
        $this->assertNotFalse($customPos);
        $this->assertLessThan($customPos, $legalPos);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=SalesInvoicePdfTest`
Expected: the 4 new tests FAIL (no footnotes block exists yet).

- [ ] **Step 3: Add the footnotes block**

In `resources/views/pdf/sales-invoice.blade.php`, replace the `{{-- Task 4 adds footnotes here --}}` comment with:

```blade
        @php
            $footnotes = [];
            if (! $vatRegistered) {
                $footnotes[] = 'Фирмава не е ДДВ обврзник.';
            }
            if ($company->invoice_footer_note) {
                $footnotes[] = $company->invoice_footer_note;
            }
        @endphp
        @if (count($footnotes))
            <div class="footnotes">
                @foreach ($footnotes as $note)
                    <p>{{ $note }}</p>
                @endforeach
            </div>
        @endif
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=SalesInvoicePdfTest`
Expected: all 18 tests PASS.

- [ ] **Step 5: Run the full automated test suite**

Run: `php artisan test`
Expected: full suite green (no regressions in unrelated modules — this plan never touched anything outside the three files listed in Global Constraints).

- [ ] **Step 6: Commit**

```bash
git add resources/views/pdf/sales-invoice.blade.php tests/Feature/SalesInvoicePdfTest.php
git commit -m "feat: add VAT-exemption and custom footer notes to sales invoice PDF"
```

---

## Self-Review Notes

- **Spec coverage:** every section of `docs/superpowers/specs/2026-07-28-sales-invoice-redesign-design.md` maps to a task — Header/logo/issuer/recipient → Task 1; line-items table (р.б., ДДВ% + treatment, Вкупно со ДДВ, hidden VAT column) → Task 2; totals + bank accounts → Task 3; footnotes → Task 4. Visual style (accent bar, rounded cards, brand color) is applied in Task 1's shared `<style>` block and reused by every later task's markup.
- **Placeholder scan:** none — every step has literal, complete code.
- **Type/name consistency:** `$company`, `$vatRegistered`, `$hasLogo`, `$logoPosition`, `$logoPath` are all defined once in Task 1's `@php` block and reused unchanged by Tasks 2–4; no renaming across tasks.
- **dompdf page-break behavior** (the user's mid-review request that the items table span full pages and continue rather than being cut off) is satisfied structurally by Task 2 keeping a genuine `<table><thead>` — dompdf repeats `<thead>` content on every page a table spans, and table rows are not artificially height-constrained anywhere in this template.
