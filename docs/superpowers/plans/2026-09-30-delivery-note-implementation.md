# Испратница (Delivery Note) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an "Испратница" (delivery note) PDF that can be issued, from a button, off a confirmed proforma or a confirmed sales invoice — its own numbered series, no prices/VAT, quantities only.

**Architecture:** A new `DeliveryNote` model (polymorphic `deliverable` pointing at either `ProformaInvoice` or `SalesInvoice`) gets its own number series per company/year, exactly mirroring how `ProformaInvoice` already gets its own series independent of `SalesInvoice`. A `DeliveryNoteService::createOrGetFor()` is idempotent per source — a second click on the same document returns the same delivery note instead of minting a new number. Two thin PDF controllers (one per source type) reuse a single `pdf.delivery-note` Blade view, itself a stripped-down copy of the existing `pdf.sales-invoice` letterhead (no price/VAT columns, no payment box).

**Tech Stack:** Laravel 11, Livewire/Volt, dompdf (`barryvdh/laravel-dompdf`), PHPUnit, existing `App\Support\InvoiceNumber` formatter.

## Global Constraints

- Испратница content is Macedonian only — no `InvoiceLanguage`/bilingual handling.
- No prices, no VAT, no discount columns anywhere on the delivery note (per spec — a document with a price is legally an invoice, not a delivery note).
- The delivery note button/route is gated behind the existing `EnsureCompanyModule::class.':material'` middleware group — same as proformas and sales invoices.
- A delivery note is only issuable from a **confirmed** proforma or a **confirmed** sales invoice.
- One delivery note per source document — a second request for the same source returns the existing record, never a new number (unique DB constraint backs this).
- Follow `docs/superpowers/specs/2026-09-30-delivery-note-design.md` for anything not explicitly repeated here.

---

### Task 1: Data model — migration, `DeliveryNote` model, `Company` prefix column, factory

**Files:**
- Create: `database/migrations/2026_09_30_100000_create_delivery_notes_table.php`
- Create: `app/Models/DeliveryNote.php`
- Create: `database/factories/DeliveryNoteFactory.php`
- Modify: `app/Models/Company.php` (fillable + default attribute)
- Test: `tests/Feature/DeliveryNoteTest.php` (new file — this task only adds the first test; later tasks append to it)

**Interfaces:**
- Produces: `App\Models\DeliveryNote` with columns `id, company_id, deliverable_type, deliverable_id, fiscal_year, delivery_note_number, delivery_note_number_formatted, delivery_date, created_by, timestamps`; relations `company(): BelongsTo`, `deliverable(): MorphTo`, `creator(): BelongsTo`.
- Produces: `Company::$delivery_note_number_prefix` (string, default `'ИСП-'`), fillable and with a default in `Company::$attributes` (same pattern as `proforma_number_prefix`).

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
        Schema::table('companies', function (Blueprint $table) {
            // Иста логика како проforma_number_prefix: годината, разделникот и
            // должината на бројот се исти како кај фактурата, само префиксот е свој.
            $table->string('delivery_note_number_prefix', 10)->nullable()->default('ИСП-')->after('proforma_number_prefix');
        });

        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('deliverable_type');
            $table->unsignedBigInteger('deliverable_id');
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedInteger('delivery_note_number');
            $table->string('delivery_note_number_formatted', 40);
            $table->date('delivery_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'fiscal_year', 'delivery_note_number']);
            // Спречува втора испратница за истиот извор — секое второ барање
            // мора да ја врати постојната наместо да créira нова.
            $table->unique(['deliverable_type', 'deliverable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_notes');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('delivery_note_number_prefix');
        });
    }
};
```

Save this as `database/migrations/2026_09_30_100000_create_delivery_notes_table.php`.

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: `2026_09_30_100000_create_delivery_notes_table` reports `DONE`.

- [ ] **Step 3: Add the `DeliveryNote` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Испратница: доказ за движење стока, издадена од потврдена профактура или
 * потврдена фактура (deliverable). Нема сопствени ставки — количините се
 * читаат живо од изворот при секое прикажување/печатење.
 */
class DeliveryNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'deliverable_type', 'deliverable_id', 'fiscal_year',
        'delivery_note_number', 'delivery_note_number_formatted', 'delivery_date', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function deliverable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

Save as `app/Models/DeliveryNote.php`.

- [ ] **Step 4: Add the factory**

```php
<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\ProformaInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeliveryNoteFactory extends Factory
{
    public function definition(): array
    {
        $company = Company::factory();
        $number = $this->faker->unique()->numberBetween(1, 100000);

        return [
            'company_id' => $company,
            'deliverable_type' => ProformaInvoice::class,
            'deliverable_id' => ProformaInvoice::factory()->for($company),
            'fiscal_year' => (int) now()->year,
            'delivery_note_number' => $number,
            'delivery_note_number_formatted' => 'ИСП-'.now()->year.'/'.$number,
            'delivery_date' => now()->toDateString(),
            'created_by' => User::factory(),
        ];
    }
}
```

Save as `database/factories/DeliveryNoteFactory.php`. Tests that need a specific `deliverable_type`/`deliverable_id` pair override both explicitly in `create([...])`, same as this plan's later tests do.

- [ ] **Step 5: Add the `Company` prefix field**

In `app/Models/Company.php`, change:

```php
        'invoice_number_prefix', 'invoice_number_include_year', 'invoice_number_year_first',
        'invoice_number_year_digits', 'invoice_number_separator', 'invoice_number_padding', 'proforma_number_prefix',
    ];
```

to:

```php
        'invoice_number_prefix', 'invoice_number_include_year', 'invoice_number_year_first',
        'invoice_number_year_digits', 'invoice_number_separator', 'invoice_number_padding', 'proforma_number_prefix',
        'delivery_note_number_prefix',
    ];
```

And change:

```php
        'invoice_number_padding' => 1,
        'proforma_number_prefix' => 'ПФ-',
    ];
```

to:

```php
        'invoice_number_padding' => 1,
        'proforma_number_prefix' => 'ПФ-',
        'delivery_note_number_prefix' => 'ИСП-',
    ];
```

- [ ] **Step 6: Write the failing test**

Create `tests/Feature/DeliveryNoteTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Partner;
use App\Models\ProformaInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeliveryNoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function admin(): \App\Models\User
    {
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        return $admin;
    }

    // ---- Модел ----

    public function test_a_delivery_note_belongs_to_its_company_and_its_deliverable(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $proforma = ProformaInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed']);

        $note = DeliveryNote::factory()->create([
            'company_id' => $company->id,
            'deliverable_type' => ProformaInvoice::class,
            'deliverable_id' => $proforma->id,
            'delivery_note_number_formatted' => 'ИСП-2026/1',
        ]);

        $note->refresh();
        $this->assertTrue($note->company->is($company));
        $this->assertTrue($note->deliverable->is($proforma));
        $this->assertSame('ИСП-2026/1', $note->delivery_note_number_formatted);
    }

    public function test_the_company_defaults_its_delivery_note_prefix(): void
    {
        $company = Company::factory()->create();

        $this->assertSame('ИСП-', $company->delivery_note_number_prefix);
    }
}
```

- [ ] **Step 7: Run the tests to verify they fail**

Run: `php artisan test --filter=DeliveryNoteTest`
Expected: FAIL — `Class "App\Models\DeliveryNote" not found` (before Step 3) or table-not-found (before Step 2). Run this AFTER steps 1–5 are in place to confirm it actually passes instead; if you're following steps in order, this step should now PASS since steps 1–5 already exist. Run it anyway to confirm green.

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --filter=DeliveryNoteTest`
Expected: PASS (2 tests).

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_09_30_100000_create_delivery_notes_table.php app/Models/DeliveryNote.php database/factories/DeliveryNoteFactory.php app/Models/Company.php tests/Feature/DeliveryNoteTest.php
git commit -m "Add delivery_notes table, DeliveryNote model, and company prefix"
```

---

### Task 2: `DeliveryNoteService` — numbering and idempotency

**Files:**
- Create: `app/Services/Invoicing/DeliveryNoteService.php`
- Modify: `tests/Feature/DeliveryNoteTest.php` (append tests)

**Interfaces:**
- Consumes: `App\Models\DeliveryNote` (Task 1), `App\Support\InvoiceNumber::format(Company $company, int $fiscalYear, int $sequence, ?string $prefix = null): string` (existing), `App\Exceptions\InvalidInvoiceStateException` (existing, used by `ProformaService`).
- Produces: `DeliveryNoteService::createOrGetFor(ProformaInvoice|SalesInvoice $source): DeliveryNote` — later tasks (controllers) call this exact method.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/DeliveryNoteTest.php` (add these `use` statements at the top alongside the existing ones: `use App\Exceptions\InvalidInvoiceStateException;`, `use App\Models\SalesInvoice;`, `use App\Services\Invoicing\DeliveryNoteService;`):

```php
    // ---- Нумерирање ----

    public function test_it_numbers_with_its_own_prefix_and_series_across_both_source_types(): void
    {
        $company = Company::factory()->create(['delivery_note_number_prefix' => 'ISP/']);
        $partner = Partner::factory()->for($company)->create();
        $proforma = ProformaInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice = SalesInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed', 'fiscal_year' => now()->year, 'invoice_number' => 1]);

        $service = app(DeliveryNoteService::class);
        $first = $service->createOrGetFor($proforma);
        $second = $service->createOrGetFor($invoice);

        $this->assertSame('ISP/'.now()->year.'/1', $first->delivery_note_number_formatted);
        $this->assertSame('ISP/'.now()->year.'/2', $second->delivery_note_number_formatted);
    }

    public function test_each_company_has_its_own_series(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $proformaA = ProformaInvoice::factory()->create(['company_id' => $a->id, 'partner_id' => Partner::factory()->for($a)->create()->id, 'status' => 'confirmed']);
        $proformaB = ProformaInvoice::factory()->create(['company_id' => $b->id, 'partner_id' => Partner::factory()->for($b)->create()->id, 'status' => 'confirmed']);
        $service = app(DeliveryNoteService::class);

        $service->createOrGetFor($proformaA);
        $first = $service->createOrGetFor($proformaB);

        $this->assertSame(1, $first->delivery_note_number);
    }

    public function test_calling_it_twice_for_the_same_source_returns_the_same_delivery_note(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $proforma = ProformaInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed']);
        $service = app(DeliveryNoteService::class);

        $first = $service->createOrGetFor($proforma);
        $second = $service->createOrGetFor($proforma);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DeliveryNote::count());
    }

    public function test_a_draft_proforma_or_invoice_cannot_get_a_delivery_note(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $draft = ProformaInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'draft']);

        $this->expectException(InvalidInvoiceStateException::class);
        app(DeliveryNoteService::class)->createOrGetFor($draft);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=DeliveryNoteTest`
Expected: FAIL — `Class "App\Services\Invoicing\DeliveryNoteService" not found`.

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services\Invoicing;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\DeliveryNote;
use App\Models\ProformaInvoice;
use App\Models\SalesInvoice;
use App\Support\InvoiceNumber;
use Illuminate\Support\Facades\DB;

class DeliveryNoteService
{
    /**
     * Секој извор (профактура или фактура) добива најмногу една испратница —
     * повторен повик за истиот извор ја враќа истата, наместо да создава нова
     * со нов број. Уникатниот индекс на (deliverable_type, deliverable_id) е
     * заштитата ако два повика се случат во исто време.
     */
    public function createOrGetFor(ProformaInvoice|SalesInvoice $source): DeliveryNote
    {
        if ($source->status !== 'confirmed') {
            throw new InvalidInvoiceStateException('Испратница може да се издаде само од потврдена профактура или фактура.');
        }

        $existing = DeliveryNote::where('deliverable_type', $source::class)
            ->where('deliverable_id', $source->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $company = $source->company;

        return DB::transaction(function () use ($company, $source) {
            $fiscalYear = (int) now()->year;

            $query = DeliveryNote::where('company_id', $company->id);

            if ($company->invoice_number_include_year) {
                $query->where('fiscal_year', $fiscalYear);
            }

            $next = ($query->lockForUpdate()->max('delivery_note_number') ?? 0) + 1;

            return DeliveryNote::create([
                'company_id' => $company->id,
                'deliverable_type' => $source::class,
                'deliverable_id' => $source->id,
                'fiscal_year' => $fiscalYear,
                'delivery_note_number' => $next,
                'delivery_note_number_formatted' => InvoiceNumber::format($company, $fiscalYear, $next, (string) ($company->delivery_note_number_prefix ?? '')),
                'delivery_date' => now()->toDateString(),
                'created_by' => auth()->id(),
            ]);
        });
    }
}
```

Save as `app/Services/Invoicing/DeliveryNoteService.php`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=DeliveryNoteTest`
Expected: PASS (6 tests total).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Invoicing/DeliveryNoteService.php tests/Feature/DeliveryNoteTest.php
git commit -m "Add DeliveryNoteService with its own numbering and idempotent issuing"
```

---

### Task 3: PDF template

**Files:**
- Create: `resources/views/pdf/delivery-note.blade.php`
- Modify: `tests/Feature/DeliveryNoteTest.php` (append test)

**Interfaces:**
- Consumes: the view is rendered with `deliveryNote` (`DeliveryNote`), `company` (`Company`), `partner` (`Partner`), `lines` (a `Collection` of `ProformaInvoiceLine` or `SalesInvoiceLine` — both expose `->item` (nullable `Item`), `->description` (string), `->quantity` (string)), `sourceLabel` (string, e.g. `'профактура'`), `sourceNumber` (string, e.g. `'ПФ-2026/3'`).
- These five keys are the exact contract Task 4's controllers must pass in — no other keys are read by this view.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/DeliveryNoteTest.php` (add `use App\Models\ProformaInvoiceLine;` to the top):

```php
    // ---- PDF-шаблон ----

    public function test_the_pdf_view_shows_quantities_without_any_price_or_vat(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Купувач Ана']);
        $proforma = ProformaInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed']);
        ProformaInvoiceLine::factory()->create(['proforma_invoice_id' => $proforma->id, 'description' => 'Канцелариски стол', 'quantity' => '3.000', 'unit_price' => '999.00']);
        $note = DeliveryNote::factory()->create([
            'company_id' => $company->id,
            'deliverable_type' => ProformaInvoice::class,
            'deliverable_id' => $proforma->id,
            'delivery_note_number_formatted' => 'ИСП-2026/1',
        ]);

        $html = view('pdf.delivery-note', [
            'deliveryNote' => $note,
            'company' => $company,
            'partner' => $partner,
            'lines' => $proforma->load('lines.item')->lines,
            'sourceLabel' => 'профактура',
            'sourceNumber' => $proforma->proforma_number_formatted,
        ])->render();

        $this->assertStringContainsString('Испратница', $html);
        $this->assertStringContainsString('ИСП-2026/1', $html);
        $this->assertStringContainsString('Купувач Ана', $html);
        $this->assertStringContainsString('Канцелариски стол', $html);
        $this->assertStringContainsString('3.000', $html);
        $this->assertStringContainsString('ПРЕДАЛ', $html);
        $this->assertStringContainsString('ПРИМИЛ', $html);
        $this->assertStringNotContainsString('999.00', $html);
        $this->assertStringNotContainsString('ДДВ', $html);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=test_the_pdf_view_shows_quantities_without_any_price_or_vat`
Expected: FAIL — view `pdf.delivery-note` not found.

- [ ] **Step 3: Write the view**

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @php
        $logoPosition = ($company->logo_position ?? null) ?: 'left';
        $logoPath = $company->logo_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->path($company->logo_path)
            : null;
        $hasLogo = $logoPath && file_exists($logoPath);
    @endphp
    <style>
        /* Ист принцип како pdf/sales-invoice.blade.php: dompdf 3.1.6 нема flex,
           па секој повеќеколонски дел е табела со фиксни широчини. */
        body { font-family: 'DejaVu Sans'; font-size: 11px; color: #000000; margin: 0; padding: 0; }
        .accent-bar { height: 6px; background-color: #000000; }
        .content { padding: 18px 24px; }
        .badge { display: inline-block; font-weight: bold; font-size: 18px; color: #000000; }
        .muted { color: #000000; }
        .small { font-size: 10px; }
        .label { font-size: 9px; text-transform: uppercase; letter-spacing: .05em; color: #ff6600; margin: 0 0 4px; }
        table.info-table { border-collapse: collapse; }
        table.info-table td { padding: 0 0 1px; vertical-align: top; }
        td.info-label { color: #000000; padding-right: 6px; white-space: nowrap; }
        table.letterhead { width: 100%; border-collapse: collapse; }
        table.letterhead td { vertical-align: top; padding: 0; }
        table.party-row { width: 100%; border-collapse: collapse; margin-top: 16px; }
        table.party-row td { vertical-align: top; width: 47%; }
        table.party-row td.party-gap { width: 6%; }
        .address-window, .issuer-box { height: 40mm; font-size: 10px; border: 1px solid #d1d5db; border-radius: 6px; padding: 6px 10px; }
        .reference { margin-top: 10px; font-size: 10px; color: #000000; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.items th { text-align: left; font-size: 10px; color: #000000; font-weight: bold; border-bottom: 1.5px solid #111827; padding: 6px; }
        table.items td { padding: 6px; border-bottom: 1px solid #e5e7eb; }
        table.signatures { width: 100%; border-collapse: collapse; margin-top: 90px; page-break-inside: avoid; }
        table.signatures td { width: 50%; padding: 0 24px; vertical-align: bottom; }
        .sig-line { border-top: 1px solid #9ca3af; height: 0; font-size: 0; }
        .sig-label { text-align: center; font-size: 9px; color: #000000; margin-top: 4px; letter-spacing: .05em; }
        @if ($hasLogo && $logoPosition === 'center')
            .logo-row { text-align: center; margin-bottom: 10px; }
        @endif
    </style>
</head>
<body>
    <div class="accent-bar"></div>
    <div class="content">
        @if ($hasLogo && $logoPosition === 'center')
            <div class="logo-row">
                <img src="{{ $logoPath }}" style="max-height: 40px;">
            </div>
        @endif

        <table class="letterhead">
            <tr>
                @if ($logoPosition === 'right')
                    <td style="width: 50%;">
                        <span class="badge">Испратница {{ $deliveryNote->delivery_note_number_formatted }}</span>
                        <div class="small muted" style="margin-top: 6px;">Датум: {{ $deliveryNote->delivery_date->format('d.m.Y') }}</div>
                    </td>
                    <td style="width: 50%; text-align: right;">
                        @if ($hasLogo)
                            <img src="{{ $logoPath }}" style="max-height: 40px;">
                        @endif
                    </td>
                @else
                    <td style="width: 50%;">
                        @if ($hasLogo && $logoPosition !== 'center')
                            <img src="{{ $logoPath }}" style="max-height: 40px;">
                        @endif
                    </td>
                    <td style="width: 50%; text-align: right;">
                        <span class="badge">Испратница {{ $deliveryNote->delivery_note_number_formatted }}</span>
                        <div class="small muted" style="margin-top: 6px;">Датум: {{ $deliveryNote->delivery_date->format('d.m.Y') }}</div>
                    </td>
                @endif
            </tr>
        </table>

        <table class="party-row">
            <tr>
                <td>
                    <div class="address-window">
                        <div class="label">Примач</div>
                        <div><strong>{{ $partner->name }}</strong></div>
                        <div>{{ $partner->printedAddress() }}</div>
                        @if ($partner->tax_id)
                            <div style="margin-top: 4px;">ЕДБ: {{ $partner->tax_id }}</div>
                        @endif
                    </div>
                </td>
                <td class="party-gap"></td>
                <td>
                    <div class="issuer-box">
                        <div class="label">Издавач</div>
                        <div style="margin-bottom: 3px;"><strong>{{ $company->name }}</strong></div>
                        <table class="info-table small">
                            <tr>
                                <td class="info-label">Адреса:</td>
                                <td>{{ $company->address }}</td>
                            </tr>
                            <tr>
                                <td class="info-label">ЕДБ:</td>
                                <td>{{ $company->tax_id }}</td>
                            </tr>
                            @if ($company->registration_number)
                                <tr>
                                    <td class="info-label">Матичен број:</td>
                                    <td>{{ $company->registration_number }}</td>
                                </tr>
                            @endif
                        </table>
                    </div>
                </td>
            </tr>
        </table>

        <div class="reference">По {{ $sourceLabel }} бр. {{ $sourceNumber }}</div>

        <table class="items">
            <thead>
                <tr>
                    <th style="width: 30px;">Ред. бр.</th>
                    <th style="width: 70px;">Шифра</th>
                    <th>Опис</th>
                    <th style="width: 60px;">Ед. мера</th>
                    <th style="width: 70px;">Количина</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $index => $line)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $line->item?->code }}</td>
                        <td>{{ $line->description }}</td>
                        <td>{{ $line->item?->unit_of_measure ?: 'бр.' }}</td>
                        <td>{{ $line->quantity }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="signatures">
            <tr>
                <td>
                    <div class="sig-line"></div>
                    <div class="sig-label">ПРЕДАЛ</div>
                </td>
                <td>
                    <div class="sig-line"></div>
                    <div class="sig-label">ПРИМИЛ</div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
```

Save as `resources/views/pdf/delivery-note.blade.php`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=DeliveryNoteTest`
Expected: PASS (7 tests total).

- [ ] **Step 5: Commit**

```bash
git add resources/views/pdf/delivery-note.blade.php tests/Feature/DeliveryNoteTest.php
git commit -m "Add delivery-note PDF view without prices or VAT"
```

---

### Task 4: Routes and controllers

**Files:**
- Create: `app/Http/Controllers/ProformaDeliveryNotePdfController.php`
- Create: `app/Http/Controllers/SalesInvoiceDeliveryNotePdfController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/DeliveryNoteTest.php` (append tests)

**Interfaces:**
- Consumes: `DeliveryNoteService::createOrGetFor()` (Task 2), `pdf.delivery-note` view contract (Task 3).
- Produces: named routes `proformas.delivery-note` and `sales-invoices.delivery-note`, each taking `(Company $company, ProformaInvoice|SalesInvoice $document)` — Task 5's Blade buttons link to these by name.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/DeliveryNoteTest.php` (add `use App\Models\SalesInvoiceLine;` to the top):

```php
    // ---- Рути и PDF-преземање ----

    public function test_the_proforma_delivery_note_route_downloads_a_pdf_and_is_idempotent(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $proforma = ProformaInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed']);
        ProformaInvoiceLine::factory()->create(['proforma_invoice_id' => $proforma->id]);
        $this->admin();

        $first = $this->get(route('proformas.delivery-note', [$company, $proforma]));
        $first->assertOk();
        $this->assertStringStartsWith('%PDF-', $first->getContent());

        $this->get(route('proformas.delivery-note', [$company, $proforma]))->assertOk();

        $this->assertSame(1, DeliveryNote::count());
    }

    public function test_a_draft_proforma_refuses_a_delivery_note_over_http(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $draft = ProformaInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'draft']);
        $this->admin();

        $this->get(route('proformas.delivery-note', [$company, $draft]))->assertForbidden();
    }

    public function test_a_proforma_delivery_note_of_another_company_is_404_under_this_company(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $otherPartner = Partner::factory()->for($other)->create();
        $foreign = ProformaInvoice::factory()->create(['company_id' => $other->id, 'partner_id' => $otherPartner->id, 'status' => 'confirmed']);
        $this->admin();

        $this->get(route('proformas.delivery-note', [$company, $foreign]))->assertNotFound();
    }

    public function test_the_sales_invoice_delivery_note_route_downloads_a_pdf(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed', 'fiscal_year' => now()->year, 'invoice_number' => 5]);
        SalesInvoiceLine::factory()->create(['sales_invoice_id' => $invoice->id]);
        $this->admin();

        $response = $this->get(route('sales-invoices.delivery-note', [$company, $invoice]));
        $response->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_a_draft_sales_invoice_refuses_a_delivery_note_over_http(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $draft = SalesInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'draft']);
        $this->admin();

        $this->get(route('sales-invoices.delivery-note', [$company, $draft]))->assertForbidden();
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=DeliveryNoteTest`
Expected: FAIL — `Route [proformas.delivery-note] not defined`.

- [ ] **Step 3: Write the controllers**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ProformaInvoice;
use App\Services\Invoicing\DeliveryNoteService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ProformaDeliveryNotePdfController extends Controller
{
    public function __invoke(Company $company, ProformaInvoice $proforma, DeliveryNoteService $deliveryNotes)
    {
        Gate::authorize('view', $company);
        Gate::authorize('view', $proforma);

        abort_unless($proforma->company_id === $company->id, 404);
        abort_unless($proforma->status === 'confirmed', 403, 'Само потврдена профактура може да добие испратница.');

        $proforma->load(['lines.item', 'partner']);

        $deliveryNote = $deliveryNotes->createOrGetFor($proforma);

        $pdf = Pdf::loadView('pdf.delivery-note', [
            'deliveryNote' => $deliveryNote,
            'company' => $company,
            'partner' => $proforma->partner,
            'lines' => $proforma->lines,
            'sourceLabel' => 'профактура',
            'sourceNumber' => $proforma->proforma_number_formatted,
        ]);

        return $pdf->download('ispratnica-'.Str::slug($deliveryNote->delivery_note_number_formatted, '-', 'mk').'.pdf');
    }
}
```

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\SalesInvoice;
use App\Services\Invoicing\DeliveryNoteService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SalesInvoiceDeliveryNotePdfController extends Controller
{
    public function __invoke(Company $company, SalesInvoice $salesInvoice, DeliveryNoteService $deliveryNotes)
    {
        Gate::authorize('view', $salesInvoice);

        abort_if($salesInvoice->company_id !== $company->id, 404);
        abort_unless($salesInvoice->status === 'confirmed', 403, 'Само потврдена фактура може да добие испратница.');

        $salesInvoice->load(['lines.item', 'partner']);

        $deliveryNote = $deliveryNotes->createOrGetFor($salesInvoice);

        $pdf = Pdf::loadView('pdf.delivery-note', [
            'deliveryNote' => $deliveryNote,
            'company' => $company,
            'partner' => $salesInvoice->partner,
            'lines' => $salesInvoice->lines,
            'sourceLabel' => 'фактура',
            'sourceNumber' => $salesInvoice->formattedNumber(),
        ]);

        return $pdf->download('ispratnica-'.Str::slug($deliveryNote->delivery_note_number_formatted, '-', 'mk').'.pdf');
    }
}
```

Save these as `app/Http/Controllers/ProformaDeliveryNotePdfController.php` and `app/Http/Controllers/SalesInvoiceDeliveryNotePdfController.php`.

- [ ] **Step 4: Wire the routes**

In `routes/web.php`, add these two `use` statements next to the existing `use App\Http\Controllers\ProformaPdfController;` / `use App\Http\Controllers\SalesInvoicePdfController;` lines (keep alphabetical order):

```php
use App\Http\Controllers\ProformaDeliveryNotePdfController;
use App\Http\Controllers\SalesInvoiceDeliveryNotePdfController;
```

Then, in the `sales-invoices.` route group, change:

```php
        Route::get('/sales-invoices/{salesInvoice}/pdf', [SalesInvoicePdfController::class, '__invoke'])->name('pdf');
    });
```

to:

```php
        Route::get('/sales-invoices/{salesInvoice}/pdf', [SalesInvoicePdfController::class, '__invoke'])->name('pdf');
        Route::get('/sales-invoices/{salesInvoice}/delivery-note', [SalesInvoiceDeliveryNotePdfController::class, '__invoke'])->name('delivery-note');
    });
```

And in the `proformas.` route group, change:

```php
        Route::get('/proformas/{proforma}/pdf', [ProformaPdfController::class, '__invoke'])->name('pdf');
    });
```

to:

```php
        Route::get('/proformas/{proforma}/pdf', [ProformaPdfController::class, '__invoke'])->name('pdf');
        Route::get('/proformas/{proforma}/delivery-note', [ProformaDeliveryNotePdfController::class, '__invoke'])->name('delivery-note');
    });
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=DeliveryNoteTest`
Expected: PASS (12 tests total).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ProformaDeliveryNotePdfController.php app/Http/Controllers/SalesInvoiceDeliveryNotePdfController.php routes/web.php tests/Feature/DeliveryNoteTest.php
git commit -m "Add delivery-note routes and controllers for proforma and sales invoice"
```

---

### Task 5: Buttons on the proforma and sales invoice screens

**Files:**
- Modify: `resources/views/livewire/invoicing/proforma-index.blade.php:89-92`
- Modify: `resources/views/livewire/invoicing/sales-invoice-show.blade.php:159-163`
- Modify: `tests/Feature/ProformaInvoiceTest.php` (append test)
- Modify: `tests/Feature/DeliveryNoteTest.php` (append test)

**Interfaces:**
- Consumes: route names `proformas.delivery-note` and `sales-invoices.delivery-note` (Task 4).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/ProformaInvoiceTest.php` (uses the existing private `admin()` and `make()` helpers already in that file):

```php
    public function test_the_detail_shows_the_delivery_note_button_only_when_confirmed(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $draft = $this->make($company, $partner, 'draft');
        $confirmed = $this->make($company, $partner, 'confirmed');
        $this->admin();

        Livewire::test(ProformaIndex::class, ['company' => $company])
            ->call('select', $confirmed->id)
            ->assertSeeHtml(route('proformas.delivery-note', [$company, $confirmed]))
            ->call('select', $draft->id)
            ->assertDontSeeHtml(route('proformas.delivery-note', [$company, $draft]));
    }
```

Append to `tests/Feature/DeliveryNoteTest.php` (add `use App\Livewire\Invoicing\SalesInvoiceShow;` and `use Livewire\Livewire;` to the top):

```php
    // ---- Копче на екраните ----

    public function test_the_sales_invoice_screen_shows_the_delivery_note_button_only_when_confirmed(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $draft = SalesInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'draft']);
        $confirmed = SalesInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed', 'fiscal_year' => now()->year, 'invoice_number' => 1]);
        SalesInvoiceLine::factory()->create(['sales_invoice_id' => $confirmed->id]);
        $this->admin();

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $confirmed])
            ->assertSeeHtml(route('sales-invoices.delivery-note', [$company, $confirmed]));

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $draft])
            ->assertDontSeeHtml(route('sales-invoices.delivery-note', [$company, $draft]));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=test_the_detail_shows_the_delivery_note_button_only_when_confirmed`
Run: `php artisan test --filter=test_the_sales_invoice_screen_shows_the_delivery_note_button_only_when_confirmed`
Expected: both FAIL — the route exists (Task 4) but no link to it is rendered yet, so `assertSeeHtml` fails.

- [ ] **Step 3: Add the proforma button**

In `resources/views/livewire/invoicing/proforma-index.blade.php`, change:

```blade
                            <a href="{{ route('proformas.pdf', [$company, $selected]) }}" class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 rounded-full font-semibold text-sm text-gray-700 shadow-sm hover:bg-paper focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2 transition">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v11m0 0l-4-4m4 4l4-4" /></svg>
                                Преземи PDF
                            </a>
                            @can('update', $selected)
```

to:

```blade
                            <a href="{{ route('proformas.pdf', [$company, $selected]) }}" class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 rounded-full font-semibold text-sm text-gray-700 shadow-sm hover:bg-paper focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2 transition">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v11m0 0l-4-4m4 4l4-4" /></svg>
                                Преземи PDF
                            </a>
                            @if ($selected->status === 'confirmed')
                                <a href="{{ route('proformas.delivery-note', [$company, $selected]) }}" class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 rounded-full font-semibold text-sm text-gray-700 shadow-sm hover:bg-paper focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2 transition">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20 13V7a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 002 7v6a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4a2 2 0 001-1.73z" /></svg>
                                    Испратница
                                </a>
                            @endif
                            @can('update', $selected)
```

- [ ] **Step 4: Add the sales invoice button**

In `resources/views/livewire/invoicing/sales-invoice-show.blade.php`, change:

```blade
        @if ($invoice->status === 'confirmed')
            <a href="{{ route('sales-invoices.pdf', [$company, $invoice]) }}" class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 rounded-full font-semibold text-sm text-gray-700 shadow-sm hover:bg-paper focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2 transition">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v11m0 0l-4-4m4 4l4-4" /></svg>
                Преземи PDF
            </a>
            @if (! $invoice->sent_at)
```

to:

```blade
        @if ($invoice->status === 'confirmed')
            <a href="{{ route('sales-invoices.pdf', [$company, $invoice]) }}" class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 rounded-full font-semibold text-sm text-gray-700 shadow-sm hover:bg-paper focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2 transition">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v11m0 0l-4-4m4 4l4-4" /></svg>
                Преземи PDF
            </a>
            <a href="{{ route('sales-invoices.delivery-note', [$company, $invoice]) }}" class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 rounded-full font-semibold text-sm text-gray-700 shadow-sm hover:bg-paper focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2 transition">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20 13V7a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 002 7v6a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4a2 2 0 001-1.73z" /></svg>
                Испратница
            </a>
            @if (! $invoice->sent_at)
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=ProformaInvoiceTest`
Run: `php artisan test --filter=DeliveryNoteTest`
Expected: both suites PASS in full (this also re-confirms nothing in Tasks 1–4 broke).

- [ ] **Step 6: Commit**

```bash
git add resources/views/livewire/invoicing/proforma-index.blade.php resources/views/livewire/invoicing/sales-invoice-show.blade.php tests/Feature/ProformaInvoiceTest.php tests/Feature/DeliveryNoteTest.php
git commit -m "Add Испратница button to confirmed proformas and sales invoices"
```

---

### Task 6: Company setting — delivery note number prefix

**Files:**
- Modify: `app/Livewire/Invoicing/InvoiceSettings.php`
- Modify: `resources/views/livewire/invoicing/invoice-settings.blade.php`
- Modify: `tests/Feature/ProformaInvoiceTest.php` (append test — this screen's tests already live there)

**Interfaces:**
- Consumes: `Company::$delivery_note_number_prefix` (Task 1), `InvoiceNumber::format()` (existing).

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/ProformaInvoiceTest.php`:

```php
    public function test_the_invoice_settings_screen_edits_the_delivery_note_prefix_and_previews_it(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(InvoiceSettings::class, ['company' => $company])
            ->assertSet('deliveryNotePrefix', 'ИСП-')
            ->set('deliveryNotePrefix', 'ISP-')
            ->assertSee('ISP-'.now()->year.'/1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('ISP-', $company->fresh()->delivery_note_number_prefix);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=test_the_invoice_settings_screen_edits_the_delivery_note_prefix_and_previews_it`
Expected: FAIL — `deliveryNotePrefix` property does not exist on the Livewire component.

- [ ] **Step 3: Update the Livewire component**

In `app/Livewire/Invoicing/InvoiceSettings.php`, change:

```php
    /** Префикс на профактурата — годината, разделникот и должината се исти како кај фактурата. */
    public string $proformaPrefix = 'ПФ-';

    public bool $saved = false;
```

to:

```php
    /** Префикс на профактурата — годината, разделникот и должината се исти како кај фактурата. */
    public string $proformaPrefix = 'ПФ-';

    /** Префикс на испратницата — иста логика како профактурата. */
    public string $deliveryNotePrefix = 'ИСП-';

    public bool $saved = false;
```

Change:

```php
        $this->prefix = (string) ($company->invoice_number_prefix ?? '');
        $this->proformaPrefix = (string) ($company->proforma_number_prefix ?? '');
    }
```

to:

```php
        $this->prefix = (string) ($company->invoice_number_prefix ?? '');
        $this->proformaPrefix = (string) ($company->proforma_number_prefix ?? '');
        $this->deliveryNotePrefix = (string) ($company->delivery_note_number_prefix ?? '');
    }
```

Change:

```php
            'prefix' => 'nullable|string|max:10',
            'proformaPrefix' => 'nullable|string|max:10',
        ]);
```

to:

```php
            'prefix' => 'nullable|string|max:10',
            'proformaPrefix' => 'nullable|string|max:10',
            'deliveryNotePrefix' => 'nullable|string|max:10',
        ]);
```

Change:

```php
            'invoice_number_prefix' => $validated['prefix'] !== '' ? $validated['prefix'] : null,
            'proforma_number_prefix' => $validated['proformaPrefix'] !== '' ? $validated['proformaPrefix'] : null,
        ]);

        $this->saved = true;
```

to:

```php
            'invoice_number_prefix' => $validated['prefix'] !== '' ? $validated['prefix'] : null,
            'proforma_number_prefix' => $validated['proformaPrefix'] !== '' ? $validated['proformaPrefix'] : null,
            'delivery_note_number_prefix' => $validated['deliveryNotePrefix'] !== '' ? $validated['deliveryNotePrefix'] : null,
        ]);

        $this->saved = true;
```

Change:

```php
        return view('livewire.invoicing.invoice-settings', [
            'preview' => InvoiceNumber::format($this->previewCompany(), (int) now()->year, 1),
            'proformaPreview' => InvoiceNumber::format($this->previewCompany(), (int) now()->year, 1, $this->proformaPrefix),
        ]);
```

to:

```php
        return view('livewire.invoicing.invoice-settings', [
            'preview' => InvoiceNumber::format($this->previewCompany(), (int) now()->year, 1),
            'proformaPreview' => InvoiceNumber::format($this->previewCompany(), (int) now()->year, 1, $this->proformaPrefix),
            'deliveryNotePreview' => InvoiceNumber::format($this->previewCompany(), (int) now()->year, 1, $this->deliveryNotePrefix),
        ]);
```

- [ ] **Step 4: Update the Blade view**

In `resources/views/livewire/invoicing/invoice-settings.blade.php`, change:

```blade
            <div>
                <x-input-label for="proformaPrefix" value="Префикс на профактурата (незадолжително)" />
                <x-text-input id="proformaPrefix" wire:model.live="proformaPrefix" class="w-full md:w-1/2" placeholder="пр. ПФ-" />
                <p class="text-xs text-gray-500 mt-1">Профактурите имаат своја серија, а годината, разделникот и должината се истите како кај фактурите.</p>
                @error('proformaPrefix') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                <div class="bg-gray-50 rounded-lg px-4 py-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Фактура</div>
                    <div class="text-xl font-semibold text-gray-800 mt-1">{{ $preview }}</div>
                </div>
                <div class="bg-gray-50 rounded-lg px-4 py-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Профактура</div>
                    <div class="text-xl font-semibold text-gray-800 mt-1">{{ $proformaPreview }}</div>
                </div>
            </div>
```

to:

```blade
            <div>
                <x-input-label for="proformaPrefix" value="Префикс на профактурата (незадолжително)" />
                <x-text-input id="proformaPrefix" wire:model.live="proformaPrefix" class="w-full md:w-1/2" placeholder="пр. ПФ-" />
                <p class="text-xs text-gray-500 mt-1">Профактурите имаат своја серија, а годината, разделникот и должината се истите како кај фактурите.</p>
                @error('proformaPrefix') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>

            <div>
                <x-input-label for="deliveryNotePrefix" value="Префикс на испратницата (незадолжително)" />
                <x-text-input id="deliveryNotePrefix" wire:model.live="deliveryNotePrefix" class="w-full md:w-1/2" placeholder="пр. ИСП-" />
                <p class="text-xs text-gray-500 mt-1">Испратниците имаат своја серија, а годината, разделникот и должината се истите како кај фактурите.</p>
                @error('deliveryNotePrefix') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>

            <div class="grid gap-3 md:grid-cols-3">
                <div class="bg-gray-50 rounded-lg px-4 py-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Фактура</div>
                    <div class="text-xl font-semibold text-gray-800 mt-1">{{ $preview }}</div>
                </div>
                <div class="bg-gray-50 rounded-lg px-4 py-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Профактура</div>
                    <div class="text-xl font-semibold text-gray-800 mt-1">{{ $proformaPreview }}</div>
                </div>
                <div class="bg-gray-50 rounded-lg px-4 py-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Испратница</div>
                    <div class="text-xl font-semibold text-gray-800 mt-1">{{ $deliveryNotePreview }}</div>
                </div>
            </div>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=ProformaInvoiceTest`
Expected: PASS (full file, including the new test).

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Invoicing/InvoiceSettings.php resources/views/livewire/invoicing/invoice-settings.blade.php tests/Feature/ProformaInvoiceTest.php
git commit -m "Add delivery-note prefix field to invoice settings"
```

---

### Task 7: Full regression run

**Files:** none (verification only)

- [ ] **Step 1: Run the entire suite**

Run: `php artisan test`
Expected: PASS, 0 failures. This confirms the new tables/columns/views/policies didn't disturb `ProformaInvoiceTest`, `SidebarTest`, or any e-Фактура/PDF test that touches the same models.

- [ ] **Step 2: Merge and deploy**

Per the user's standing instruction ("зелена серија → спој во main и пушти"): once this run is green, push straight to `main` without asking for a merge/PR decision.

```bash
git push origin main
```
