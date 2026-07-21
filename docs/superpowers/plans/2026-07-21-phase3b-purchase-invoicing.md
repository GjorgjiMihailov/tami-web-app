# Phase 3b: Purchase Invoicing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build core incoming (purchase/AP) invoicing for tami-web-app: record supplier bills with a per-line expense-account picker or stock-item link, auto-post the GL and receive stock on confirm, cancel with full reversal, record payments made, and attach the supplier's own source document.

**Architecture:** Mirrors Phase 3a's shape exactly — `app/Livewire/Invoicing/` Livewire components, a plain PHP `PurchaseInvoiceService` (Livewire-independent, composes the existing `JournalEntry` model and `StockMovementService`, same as `SalesInvoiceService`), manual `company_id` tenancy scoping, PHPUnit tests. The one piece of new cross-module sharing is `App\Models\Concerns\HasInvoiceTotals`, a trait holding the seven money-math methods that are byte-identical between `SalesInvoice` and `PurchaseInvoice` (`subtotal`, `vatTotal`, `grandTotal`, `paidTotal`, `balanceDue`, `paymentStatus`, `isOverdue`) — extracted from `SalesInvoice` in Task 1 and reused by both. Everything else (schema, service, GL posting, policy, screens) is fully separate, no polymorphic tables.

**Tech Stack:** Laravel 13.8, Livewire 3.6.4, PHP 8.3, MySQL (SQLite in tests), PHPUnit 12. No new Composer dependency — file uploads use Livewire 3's built-in `WithFileUploads` trait, and storage uses the `google` disk already configured since Phase 0b (`masbug/flysystem-google-drive-ext`, already in `composer.json`).

## Global Constraints

- PHP `^8.3`, Laravel `^13.8`, Livewire `^3.6.4` — no new dependency this phase.
- Role names are the plain strings `'admin'`, `'accountant'`, `'client'` — no enum wrapper.
- Tenancy is scoped manually per-query via `$user->visibleCompanies()` — no Eloquent global scopes.
- Tests are PHPUnit (not Pest), class-based, `use RefreshDatabase;`, snake_case `test_*` methods, roles re-seeded per test class via `Role::findOrCreate(...)` in `setUp()`.
- `Route::get($uri, [ClassName::class, '__invoke'])` (array-callable form) is required for every new route — bare class-strings crash route registration if the target class doesn't exist yet at boot time. Task 3 registers the full `purchase-invoices.` route group up front, before the Livewire classes it targets exist.
- **No auto-sequential numbering.** Unlike `SalesInvoice` (which legally must assign a sequential number to invoices it issues), `PurchaseInvoice` stores the supplier's own `supplier_invoice_number` (required string) — there is no legal numbering requirement on the receiving side. A unique constraint on `(company_id, partner_id, supplier_invoice_number)` guards against booking the same bill twice.
- **Every invoice line requires a quantity**, whether it references an Item or not — same rule as `SalesInvoiceLine` (a line's total is always `quantity × unit_price`; a flat-fee expense line just defaults to quantity `1`).
- **Per-line expense account picker.** `purchase_invoice_lines.account_id` (nullable FK to `accounts`) is required when `item_id` is null and ignored when `item_id` is set (stock lines always post to `660`). There is no fixed default expense account — the chart has ~30 distinct expense accounts across groups 40-45 and no single one produces an accurate P&L the way `740` does for sales revenue.
- **Per-line VAT-deductible flag.** `purchase_invoice_lines.vat_deductible` (boolean, default `true`). Macedonian VAT law disallows deducting input VAT on some purchases (e.g. entertainment expenses, passenger vehicles) — when `false`, that line's VAT amount is folded into its own expense/inventory debit instead of being split to account `130`.
- **GL account codes resolved by fixed convention** except the per-line expense account (user-selected): `130` (Данок на додадена вредност, used here as input/deductible VAT), `220` (Обврски спрема добавувачи во земјата — AP), `660` (Стоки на залиска — inventory asset), `100` (bank), `102` (cash). Confirmed present in `docs/reference/official-chart-of-accounts.json`.
- **`PurchaseInvoiceService::cancel()` must translate `InsufficientStockException` into `InvalidInvoiceStateException`.** This is a real divergence from `SalesInvoiceService::cancel()`, which reverses stock via `receipt()` (always succeeds — you can always add stock back). Purchase's `cancel()` must reverse stock via `issue()` (the inverse of the original `receipt()`), and `issue()` throws `InsufficientStockException` if the on-hand quantity has since dropped below what needs to be removed (e.g. the received stock was already sold before the bill was cancelled). Left uncaught, this would surface as an unhandled 500 in `PurchaseInvoiceShow::cancel()` (which — mirroring `SalesInvoiceShow::cancel()` — only catches `InvalidInvoiceStateException`). Task 5 catches it and rethrows with a clear message instead.
- **Source document attachment**: a single nullable `source_document_path` column on `purchase_invoices`, not a new generic `Document`/attachments model — that broader design belongs to Phase 4 (Documents & Reports). Files upload via Livewire's `WithFileUploads` trait straight to the `google` disk, path convention `purchase-invoices/{company_id}/{invoice_id}/{original filename}`.
- **No PDF generation, no `sent_at`/"mark as sent" concept** — the supplier's own document is the artifact; those are sales-only concerns.
- **Editability rule** (same as sales): only `draft` invoices can have lines added/edited/removed. A `confirmed` invoice is immutable except recording a payment or cancelling — cancellation blocked entirely once any payment exists.
- **Clients get full read-write** on their own company's purchase invoices — same policy shape as `SalesInvoicePolicy`, matching `WarehousePolicy`/`ItemPolicy`, not `JournalEntryPolicy`.
- Per the approved spec: **no purchase orders / goods-receipt-before-billing split** — single "Confirm" action posts GL and receives stock atomically, same state machine as sales (`draft` → `confirmed` → `cancelled`). **MKD only.** No credit notes / partial cancellation of a paid invoice.

---

### Task 1: Extract Shared `HasInvoiceTotals` Trait

**Files:**
- Create: `app/Models/Concerns/HasInvoiceTotals.php`
- Modify: `app/Models/SalesInvoice.php`
- Test: `tests/Unit/SalesInvoiceTest.php` (no changes — existing tests must pass unchanged, proving the refactor is behavior-preserving)

**Interfaces:**
- Produces: `App\Models\Concerns\HasInvoiceTotals` trait with `subtotal(): string`, `vatTotal(): string`, `grandTotal(): string`, `paidTotal(): string`, `balanceDue(): string`, `paymentStatus(): string`, `isOverdue(): bool`. Requires the consuming model to define `lines()` (a `HasMany` whose related model has `lineTotal(): string` and `vatAmount(): string`), `payments()` (a `HasMany` whose related model has an `amount` attribute), and have `status` and `due_date` (Carbon-cast) attributes.

This is a behavior-preserving refactor, not new behavior — there is no new failing test to write. Instead: confirm the existing suite is green, extract the trait, confirm it's still green.

- [ ] **Step 1: Run the existing `SalesInvoice` test suite to confirm a green baseline**

Run: `php artisan test --filter=SalesInvoiceTest`
Expected: PASS (all existing tests, e.g. 5/5 from Phase 3a Tasks 3–4)

- [ ] **Step 2: Write the trait**

```php
<?php

namespace App\Models\Concerns;

trait HasInvoiceTotals
{
    public function subtotal(): string
    {
        return $this->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->lineTotal(), 2), '0.00');
    }

    public function vatTotal(): string
    {
        return $this->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->vatAmount(), 2), '0.00');
    }

    public function grandTotal(): string
    {
        return bcadd($this->subtotal(), $this->vatTotal(), 2);
    }

    public function paidTotal(): string
    {
        return $this->payments->reduce(fn ($carry, $payment) => bcadd($carry, (string) $payment->amount, 2), '0.00');
    }

    public function balanceDue(): string
    {
        return bcsub($this->grandTotal(), $this->paidTotal(), 2);
    }

    public function paymentStatus(): string
    {
        if ($this->status !== 'confirmed') {
            return 'n/a';
        }

        $paid = $this->paidTotal();

        if (bccomp($paid, '0', 2) <= 0) {
            return 'unpaid';
        }

        if (bccomp($paid, $this->grandTotal(), 2) >= 0) {
            return 'paid';
        }

        return 'partially_paid';
    }

    public function isOverdue(): bool
    {
        return in_array($this->paymentStatus(), ['unpaid', 'partially_paid'], true)
            && $this->due_date->isPast();
    }
}
```

- [ ] **Step 3: Update `SalesInvoice` to use the trait**

Modify `app/Models/SalesInvoice.php` — replace the whole file:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasInvoiceTotals;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesInvoice extends Model
{
    use HasFactory;
    use HasInvoiceTotals;

    protected $fillable = [
        'company_id', 'partner_id', 'warehouse_id', 'journal_entry_id',
        'fiscal_year', 'invoice_number', 'invoice_date', 'due_date',
        'status', 'sent_at', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalesInvoicePayment::class);
    }
}
```

- [ ] **Step 4: Run the test suite to verify it still passes**

Run: `php artisan test --filter=SalesInvoiceTest`
Expected: PASS — identical results to Step 1, proving the refactor is behavior-preserving.

Also run the broader invoicing suite to catch any other consumer of the removed methods:

Run: `php artisan test --filter=SalesInvoice`
Expected: PASS (covers `SalesInvoiceTest`, `SalesInvoiceServiceTest`, `SalesInvoiceLineTest`, `SalesInvoicePaymentTest`)

- [ ] **Step 5: Commit**

```bash
git add app/Models/Concerns/HasInvoiceTotals.php app/Models/SalesInvoice.php
git commit -m "refactor: extract HasInvoiceTotals trait from SalesInvoice for reuse by PurchaseInvoice"
```

---

### Task 2: Purchase Invoice & Line Schema

**Files:**
- Create: `database/migrations/2026_07_21_100000_create_purchase_invoices_table.php`
- Create: `database/migrations/2026_07_21_100100_create_purchase_invoice_lines_table.php`
- Create: `app/Models/PurchaseInvoice.php`
- Create: `app/Models/PurchaseInvoiceLine.php`
- Create: `database/factories/PurchaseInvoiceFactory.php`
- Create: `database/factories/PurchaseInvoiceLineFactory.php`
- Test: `tests/Unit/PurchaseInvoiceTest.php`
- Test: `tests/Unit/PurchaseInvoiceLineTest.php`

**Interfaces:**
- Produces: `PurchaseInvoice` model — fillable `['company_id', 'partner_id', 'warehouse_id', 'journal_entry_id', 'supplier_invoice_number', 'invoice_date', 'due_date', 'status', 'notes', 'source_document_path', 'created_by']`; relations `company()`, `partner()`, `warehouse()`, `journalEntry()`, `creator()`, `lines()`, `payments()` (references `PurchaseInvoicePayment`, created in Task 3 — harmless, same lazy-class-resolution reason as Phase 3a Task 3); uses `HasInvoiceTotals` for `subtotal()`/`vatTotal()`/`grandTotal()`/`paidTotal()`/`balanceDue()`/`paymentStatus()`/`isOverdue()`.
- Produces: `PurchaseInvoiceLine` model — fillable `['purchase_invoice_id', 'item_id', 'account_id', 'stock_movement_id', 'description', 'quantity', 'unit_price', 'vat_rate', 'vat_deductible']`; relations `purchaseInvoice()`, `item()`, `account()`, `stockMovement()`; methods `lineTotal(): string`, `vatAmount(): string` — same formulas as `SalesInvoiceLine`, kept separate per the approved design's "everything else stays fully separate" decision.

- [ ] **Step 1: Write the failing unit tests**

```php
<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_a_company_and_partner(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);

        $this->assertTrue($invoice->company->is($company));
        $this->assertTrue($invoice->partner->is($partner));
    }

    public function test_totals_sum_across_lines_correctly(): void
    {
        $invoice = PurchaseInvoice::factory()->create();
        $invoice->lines()->create(['description' => 'Line A', 'quantity' => '2', 'unit_price' => '100.00', 'vat_rate' => '18.00']);
        $invoice->lines()->create(['description' => 'Line B', 'quantity' => '1', 'unit_price' => '50.00', 'vat_rate' => '18.00']);

        // Line A: 2 * 100 = 200.00 net, VAT 36.00
        // Line B: 1 * 50  = 50.00 net,  VAT 9.00
        $this->assertSame('250.00', $invoice->fresh(['lines'])->subtotal());
        $this->assertSame('45.00', $invoice->fresh(['lines'])->vatTotal());
        $this->assertSame('295.00', $invoice->fresh(['lines'])->grandTotal());
    }

    public function test_draft_invoices_report_payment_status_as_not_applicable(): void
    {
        $invoice = PurchaseInvoice::factory()->create(['status' => 'draft']);

        $this->assertSame('n/a', $invoice->paymentStatus());
    }

    public function test_supplier_invoice_number_is_unique_per_company_and_partner(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'supplier_invoice_number' => 'INV-001']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'supplier_invoice_number' => 'INV-001']);
    }
}
```

```php
<?php

namespace Tests\Unit;

use App\Models\PurchaseInvoiceLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoiceLineTest extends TestCase
{
    use RefreshDatabase;

    public function test_line_total_is_quantity_times_unit_price(): void
    {
        $line = PurchaseInvoiceLine::factory()->create(['quantity' => '3', 'unit_price' => '19.99']);

        $this->assertSame('59.97', $line->lineTotal());
    }

    public function test_vat_amount_is_line_total_times_vat_rate(): void
    {
        $line = PurchaseInvoiceLine::factory()->create(['quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $this->assertSame('18.00', $line->vatAmount());
    }

    public function test_zero_vat_rate_produces_zero_vat_amount(): void
    {
        $line = PurchaseInvoiceLine::factory()->create(['quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0.00']);

        $this->assertSame('0.00', $line->vatAmount());
    }

    public function test_vat_deductible_defaults_to_true(): void
    {
        $line = PurchaseInvoiceLine::factory()->create();

        $this->assertTrue($line->fresh()->vat_deductible);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=PurchaseInvoiceTest`
Run: `php artisan test --filter=PurchaseInvoiceLineTest`
Expected: Both FAIL — classes/tables not found.

- [ ] **Step 3: Write the migrations**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $table->string('supplier_invoice_number');
            $table->date('invoice_date');
            $table->date('due_date');
            $table->string('status', 20)->default('draft');
            $table->string('notes')->nullable();
            $table->string('source_document_path')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'partner_id', 'supplier_invoice_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoices');
    }
};
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items');
            $table->foreignId('account_id')->nullable()->constrained('accounts');
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements');
            $table->string('description')->nullable();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('vat_rate', 5, 2);
            $table->boolean('vat_deductible')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_lines');
    }
};
```

- [ ] **Step 4: Write the `PurchaseInvoice` model**

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasInvoiceTotals;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseInvoice extends Model
{
    use HasFactory;
    use HasInvoiceTotals;

    protected $fillable = [
        'company_id', 'partner_id', 'warehouse_id', 'journal_entry_id',
        'supplier_invoice_number', 'invoice_date', 'due_date',
        'status', 'notes', 'source_document_path', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchaseInvoicePayment::class);
    }
}
```

- [ ] **Step 5: Write the `PurchaseInvoiceLine` model**

```php
<?php

namespace App\Models;

use App\Support\Bcmath;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = ['purchase_invoice_id', 'item_id', 'account_id', 'stock_movement_id', 'description', 'quantity', 'unit_price', 'vat_rate', 'vat_deductible'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_deductible' => 'boolean',
        ];
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    public function lineTotal(): string
    {
        return Bcmath::roundHalfUp(bcmul((string) $this->quantity, (string) $this->unit_price, 10), 2);
    }

    public function vatAmount(): string
    {
        $rate = bcdiv((string) $this->vat_rate, '100', 10);

        return Bcmath::roundHalfUp(bcmul($this->lineTotal(), $rate, 10), 2);
    }
}
```

- [ ] **Step 6: Write the factories**

```php
<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseInvoiceFactory extends Factory
{
    public function definition(): array
    {
        $company = Company::factory();

        return [
            'company_id' => $company,
            'partner_id' => Partner::factory()->for($company),
            'warehouse_id' => null,
            'journal_entry_id' => null,
            'supplier_invoice_number' => (string) $this->faker->unique()->numberBetween(1000, 999999),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(15)->toDateString(),
            'status' => 'draft',
            'notes' => null,
            'source_document_path' => null,
            'created_by' => User::factory(),
        ];
    }
}
```

```php
<?php

namespace Database\Factories;

use App\Models\PurchaseInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseInvoiceLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'purchase_invoice_id' => PurchaseInvoice::factory(),
            'item_id' => null,
            'account_id' => null,
            'stock_movement_id' => null,
            'description' => $this->faker->words(3, true),
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'vat_rate' => '18.00',
            'vat_deductible' => true,
        ];
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=PurchaseInvoiceTest`
Run: `php artisan test --filter=PurchaseInvoiceLineTest`
Expected: All PASS

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_21_100000_create_purchase_invoices_table.php database/migrations/2026_07_21_100100_create_purchase_invoice_lines_table.php app/Models/PurchaseInvoice.php app/Models/PurchaseInvoiceLine.php database/factories/PurchaseInvoiceFactory.php database/factories/PurchaseInvoiceLineFactory.php tests/Unit/PurchaseInvoiceTest.php tests/Unit/PurchaseInvoiceLineTest.php
git commit -m "feat: add purchase_invoices/purchase_invoice_lines schema and models"
```

---

### Task 3: Payment Schema, Policy & Route Registration

**Files:**
- Create: `database/migrations/2026_07_21_100200_create_purchase_invoice_payments_table.php`
- Create: `app/Models/PurchaseInvoicePayment.php`
- Create: `database/factories/PurchaseInvoicePaymentFactory.php`
- Create: `app/Policies/PurchaseInvoicePolicy.php`
- Modify: `routes/web.php`
- Test: `tests/Unit/PurchaseInvoicePaymentTest.php`
- Test: `tests/Unit/PurchaseInvoiceTest.php` (modify — add the deferred payment-status tests, same reason as Phase 3a Task 4)
- Test: `tests/Feature/InvoicingRoutesTest.php` (modify — extend to cover the new routes)

**Interfaces:**
- Produces: `PurchaseInvoicePayment` model — fillable `['purchase_invoice_id', 'amount', 'payment_date', 'payment_method', 'created_by']`, casts `amount` to `decimal:2`, `payment_date` to `date`; relations `purchaseInvoice()`, `creator()`.
- Produces: `PurchaseInvoicePolicy` — `viewAny`/`view`/`create`/`update`, client-inclusive, identical shape to `SalesInvoicePolicy`. Reuses the existing `App\Exceptions\InvalidInvoiceStateException` (no new exception class needed).
- Produces: route names `purchase-invoices.index`, `purchase-invoices.create`, `purchase-invoices.edit`, `purchase-invoices.show`, `purchase-invoices.document` (source-document download) — all registered now (four of five target classes don't exist until later tasks).

- [ ] **Step 1: Write the failing unit test**

```php
<?php

namespace Tests\Unit;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoicePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoicePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_an_invoice_and_creator(): void
    {
        $invoice = PurchaseInvoice::factory()->create();
        $user = User::factory()->create();
        $payment = PurchaseInvoicePayment::factory()->for($invoice, 'purchaseInvoice')->create(['created_by' => $user->id]);

        $this->assertTrue($payment->purchaseInvoice->is($invoice));
        $this->assertTrue($payment->creator->is($user));
    }

    public function test_amount_is_cast_to_decimal(): void
    {
        $payment = PurchaseInvoicePayment::factory()->create(['amount' => '150.50']);

        $this->assertSame('150.50', (string) $payment->fresh()->amount);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PurchaseInvoicePaymentTest`
Expected: FAIL — `Class "App\Models\PurchaseInvoicePayment" not found`.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('payment_date');
            $table->string('payment_method', 20);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_payments');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoicePayment extends Model
{
    use HasFactory;

    protected $fillable = ['purchase_invoice_id', 'amount', 'payment_date', 'payment_method', 'created_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseInvoicePaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'purchase_invoice_id' => PurchaseInvoice::factory(),
            'amount' => '100.00',
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank',
            'created_by' => User::factory(),
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=PurchaseInvoicePaymentTest`
Expected: PASS

- [ ] **Step 7: Add the deferred `PurchaseInvoice` payment-status tests**

Same reason as Phase 3a Task 4: Task 2's `PurchaseInvoiceTest` only covered the `draft` short-circuit case of `paymentStatus()`, since the `unpaid`/`partially_paid`/`paid`/`isOverdue()` cases need the `purchase_invoice_payments` table this task just created. Add these two methods to `tests/Unit/PurchaseInvoiceTest.php`:

```php
    public function test_payment_status_reflects_recorded_payments(): void
    {
        $invoice = PurchaseInvoice::factory()->create(['status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Line A', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);

        $invoice = $invoice->fresh(['lines', 'payments']);
        $this->assertSame('unpaid', $invoice->paymentStatus());

        $invoice->payments()->create(['amount' => '40.00', 'payment_date' => now(), 'payment_method' => 'bank', 'created_by' => \App\Models\User::factory()->create()->id]);
        $invoice = $invoice->fresh(['lines', 'payments']);
        $this->assertSame('partially_paid', $invoice->paymentStatus());
        $this->assertSame('60.00', $invoice->balanceDue());

        $invoice->payments()->create(['amount' => '60.00', 'payment_date' => now(), 'payment_method' => 'bank', 'created_by' => \App\Models\User::factory()->create()->id]);
        $invoice = $invoice->fresh(['lines', 'payments']);
        $this->assertSame('paid', $invoice->paymentStatus());
    }

    public function test_is_overdue_when_unpaid_past_due_date(): void
    {
        $invoice = PurchaseInvoice::factory()->create(['status' => 'confirmed', 'due_date' => now()->subDay()]);
        $invoice->lines()->create(['description' => 'Line A', 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '0']);

        $this->assertTrue($invoice->fresh(['lines', 'payments'])->isOverdue());
    }
```

Run: `php artisan test --filter=PurchaseInvoiceTest`
Expected: PASS (6/6 — the 4 from Task 2 plus these 2)

- [ ] **Step 8: Write the policy**

```php
<?php

namespace App\Policies;

use App\Models\PurchaseInvoice;
use App\Models\User;

class PurchaseInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PurchaseInvoice $purchaseInvoice): bool
    {
        return $user->visibleCompanies()->whereKey($purchaseInvoice->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'client']);
    }

    public function update(User $user, PurchaseInvoice $purchaseInvoice): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'client'])
            && $user->visibleCompanies()->whereKey($purchaseInvoice->company_id)->exists();
    }
}
```

- [ ] **Step 9: Register routes**

Modify `routes/web.php` — add these imports alongside the existing ones:

```php
use App\Http\Controllers\PurchaseInvoiceDocumentController;
use App\Livewire\Invoicing\PurchaseInvoiceForm;
use App\Livewire\Invoicing\PurchaseInvoiceIndex;
use App\Livewire\Invoicing\PurchaseInvoiceShow;
```

Add this route group after the existing `sales-invoices.` group (before `require __DIR__.'/auth.php';`):

```php
// Array-callable form (not bare class-string) for the same reason as the
// accounting.*, inventory.*, and sales-invoices.* groups above: four of
// these five target classes don't exist until later Purchase Invoicing
// tasks, and a bare class-string would crash route registration immediately.
Route::middleware(['auth'])->prefix('companies/{company}')->name('purchase-invoices.')->group(function () {
    Route::get('/purchase-invoices', [PurchaseInvoiceIndex::class, '__invoke'])->name('index');
    Route::get('/purchase-invoices/create', [PurchaseInvoiceForm::class, '__invoke'])->name('create');
    Route::get('/purchase-invoices/{purchaseInvoice}/edit', [PurchaseInvoiceForm::class, '__invoke'])->name('edit');
    Route::get('/purchase-invoices/{purchaseInvoice}', [PurchaseInvoiceShow::class, '__invoke'])->name('show');
    Route::get('/purchase-invoices/{purchaseInvoice}/document', [PurchaseInvoiceDocumentController::class, '__invoke'])->name('document');
});
```

- [ ] **Step 10: Extend `InvoicingRoutesTest`**

Read the existing `tests/Feature/InvoicingRoutesTest.php` first to match its exact structure. Add a new test method:

```php
    public function test_purchase_invoice_index_and_create_routes_render_successfully_for_an_admin(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('purchase-invoices.index', $company))->assertOk();
        $this->get(route('purchase-invoices.create', $company))->assertOk();
    }
```

Run: `php artisan test --filter=InvoicingRoutesTest`
Expected: FAIL at this point — `PurchaseInvoiceIndex`/`PurchaseInvoiceForm`/`PurchaseInvoiceDocumentController` classes don't exist yet. This is expected; the route group registers successfully (array-callable form), but rendering fails until Tasks 7–9 build the actual classes. Leave this test in place — it will pass once those tasks land. Do not run the full suite expecting green yet; only confirm `php artisan route:list --name=purchase-invoices` succeeds (proves registration didn't crash boot):

Run: `php artisan route:list --name=purchase-invoices`
Expected: lists the 5 routes registered in Step 9.

- [ ] **Step 11: Commit**

```bash
git add database/migrations/2026_07_21_100200_create_purchase_invoice_payments_table.php app/Models/PurchaseInvoicePayment.php database/factories/PurchaseInvoicePaymentFactory.php app/Policies/PurchaseInvoicePolicy.php routes/web.php tests/Unit/PurchaseInvoicePaymentTest.php tests/Unit/PurchaseInvoiceTest.php tests/Feature/InvoicingRoutesTest.php
git commit -m "feat: add purchase invoice payments schema, policy, and route registration"
```

---

### Task 4: `PurchaseInvoiceService::confirm()`

**Files:**
- Create: `app/Services/Invoicing/PurchaseInvoiceService.php`
- Test: `tests/Unit/PurchaseInvoiceServiceTest.php`

**Interfaces:**
- Consumes: `StockMovementService::receipt(Item $item, Warehouse $warehouse, string $quantity, string $unitCost, string $movementDate, int $createdBy): StockMovement` (existing, Phase 2). `Account::where('company_id', ...)->where('code', ...)->firstOrFail()` lookup pattern (existing, Phase 3a).
- Produces: `PurchaseInvoiceService::confirm(PurchaseInvoice $invoice, int $userId): PurchaseInvoice` — throws `InvalidInvoiceStateException` on invalid state.

- [ ] **Step 1: Write the failing unit tests**

```php
<?php

namespace Tests\Unit;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Invoicing\PurchaseInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseInvoiceService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = app(PurchaseInvoiceService::class);
    }

    // NOTE: CompanyObserver already auto-seeds the full official chart (all
    // 428 accounts, including every code below) on Company::factory()->create().
    // firstOrCreate() keeps this helper's intent (guarantee these codes
    // exist) without violating the accounts(company_id, code) unique
    // constraint by double-inserting them.
    private function seedAccounts(Company $company): void
    {
        foreach ([
            ['code' => '130', 'name' => 'Input VAT'],
            ['code' => '220', 'name' => 'AP'],
            ['code' => '660', 'name' => 'Inventory Asset'],
            ['code' => '100', 'name' => 'Bank'],
            ['code' => '102', 'name' => 'Cash'],
            ['code' => '462', 'name' => 'Services expense'],
        ] as $account) {
            Account::firstOrCreate(
                ['company_id' => $company->id, 'code' => $account['code']],
                $account
            );
        }
    }

    public function test_confirming_an_expense_only_bill_posts_expense_account_and_ap(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $expenseAccount = Account::where('company_id', $company->id)->where('code', '462')->first();
        $user = User::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => $expenseAccount->id, 'description' => 'Consulting', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertNotNull($confirmed->journal_entry_id);

        $entry = $confirmed->journalEntry()->with('lines.account')->first();
        $this->assertCount(3, $entry->lines);

        $expense = $entry->lines->firstWhere('account.code', '462');
        $vat = $entry->lines->firstWhere('account.code', '130');
        $ap = $entry->lines->firstWhere('account.code', '220');

        $this->assertSame('1000.00', (string) $expense->debit);
        $this->assertSame('180.00', (string) $vat->debit);
        $this->assertSame('1180.00', (string) $ap->credit);
    }

    public function test_confirming_an_item_line_receives_stock_into_inventory_asset(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create(['vat_rate' => '18.00']);
        $user = User::factory()->create();

        $invoice = PurchaseInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'warehouse_id' => $warehouse->id,
            'invoice_date' => '2026-03-01',
        ]);
        $line = $invoice->lines()->create(['item_id' => $item->id, 'description' => $item->name, 'quantity' => '10', 'unit_price' => '50.00', 'vat_rate' => '18.00']);

        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $this->assertSame('10.000', (string) \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first()->quantity_on_hand);
        $this->assertSame('50.0000', (string) \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first()->average_cost);

        $entry = $confirmed->journalEntry()->with('lines.account')->first();
        $inventoryAsset = $entry->lines->firstWhere('account.code', '660');
        $this->assertSame('500.00', (string) $inventoryAsset->debit);

        $this->assertSame($line->fresh()->stock_movement_id, \App\Models\StockMovement::where('item_id', $item->id)->where('type', 'receipt')->first()->id);
    }

    public function test_non_deductible_vat_is_folded_into_the_line_debit_instead_of_split_to_130(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $expenseAccount = Account::where('company_id', $company->id)->where('code', '462')->first();
        $user = User::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => $expenseAccount->id, 'description' => 'Entertainment', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00', 'vat_deductible' => false]);

        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $entry = $confirmed->journalEntry()->with('lines.account')->first();
        $this->assertCount(2, $entry->lines);
        $this->assertNull($entry->lines->firstWhere('account.code', '130'));

        $expense = $entry->lines->firstWhere('account.code', '462');
        $ap = $entry->lines->firstWhere('account.code', '220');
        $this->assertSame('1180.00', (string) $expense->debit);
        $this->assertSame('1180.00', (string) $ap->credit);
    }

    public function test_confirming_skips_vat_when_company_is_not_vat_registered(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => false]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $expenseAccount = Account::where('company_id', $company->id)->where('code', '462')->first();
        $user = User::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => $expenseAccount->id, 'description' => 'Consulting', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $entry = $confirmed->journalEntry()->with('lines.account')->first();
        $this->assertCount(2, $entry->lines);
        $this->assertNull($entry->lines->firstWhere('account.code', '130'));
    }

    public function test_confirming_requires_at_least_one_line(): void
    {
        $invoice = PurchaseInvoice::factory()->create();
        $user = User::factory()->create();

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->confirm($invoice, $user->id);
    }

    public function test_confirming_an_already_confirmed_invoice_throws(): void
    {
        $invoice = PurchaseInvoice::factory()->create(['status' => 'confirmed']);
        $user = User::factory()->create();

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->confirm($invoice, $user->id);
    }

    public function test_confirming_an_item_line_without_a_warehouse_throws(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['warehouse_id' => null]);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => $item->name, 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '0']);
        $user = User::factory()->create();

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->confirm($invoice->fresh(), $user->id);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=PurchaseInvoiceServiceTest`
Expected: FAIL — `Class "App\Services\Invoicing\PurchaseInvoiceService" not found`.

- [ ] **Step 3: Write the service's `confirm()` method**

```php
<?php

namespace App\Services\Invoicing;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Services\Inventory\StockMovementService;
use Illuminate\Support\Facades\DB;

class PurchaseInvoiceService
{
    public function __construct(private StockMovementService $stockMovementService)
    {
    }

    public function confirm(PurchaseInvoice $invoice, int $userId): PurchaseInvoice
    {
        if ($invoice->status !== 'draft') {
            throw new InvalidInvoiceStateException("Purchase invoice #{$invoice->id} is not a draft and cannot be confirmed.");
        }

        $invoice->loadMissing(['lines', 'company']);

        if ($invoice->lines->isEmpty()) {
            throw new InvalidInvoiceStateException('A purchase invoice needs at least one line before it can be confirmed.');
        }

        $hasItemLines = $invoice->lines->contains(fn ($line) => $line->item_id !== null);

        if ($hasItemLines && $invoice->warehouse_id === null) {
            throw new InvalidInvoiceStateException('A warehouse is required to confirm a purchase invoice with item lines.');
        }

        return DB::transaction(function () use ($invoice, $userId) {
            $vatRegistered = $invoice->company->is_vat_registered;
            $vatTotal = '0.00';
            $debitsByAccountId = [];

            foreach ($invoice->lines as $line) {
                $lineNet = $line->lineTotal();
                $lineVat = $vatRegistered ? $line->vatAmount() : '0.00';
                $deductible = $vatRegistered && $line->vat_deductible;

                if ($line->item_id !== null) {
                    $movement = $this->stockMovementService->receipt(
                        $line->item,
                        $invoice->warehouse,
                        (string) $line->quantity,
                        (string) $line->unit_price,
                        $invoice->invoice_date->toDateString(),
                        $userId
                    );

                    $line->update(['stock_movement_id' => $movement->id]);
                    $targetAccount = $this->account($invoice->company, '660');
                } else {
                    $targetAccount = $line->account;
                }

                $debitAmount = $deductible ? $lineNet : bcadd($lineNet, $lineVat, 2);
                $debitsByAccountId[$targetAccount->id] = bcadd($debitsByAccountId[$targetAccount->id] ?? '0.00', $debitAmount, 2);

                if ($deductible) {
                    $vatTotal = bcadd($vatTotal, $lineVat, 2);
                }
            }

            $supplierRef = "{$invoice->partner->name} #{$invoice->supplier_invoice_number}";
            $label = "Purchase bill {$supplierRef}";

            $entry = JournalEntry::create([
                'company_id' => $invoice->company_id,
                'entry_date' => $invoice->invoice_date,
                'description' => $label,
                'created_by' => $userId,
            ]);

            $grossTotal = '0.00';

            foreach ($debitsByAccountId as $accountId => $amount) {
                $entry->lines()->create([
                    'account_id' => $accountId,
                    'partner_id' => $invoice->partner_id,
                    'description' => $label,
                    'debit' => $amount,
                    'credit' => '0',
                ]);
                $grossTotal = bcadd($grossTotal, $amount, 2);
            }

            if (bccomp($vatTotal, '0', 2) > 0) {
                $entry->lines()->create([
                    'account_id' => $this->account($invoice->company, '130')->id,
                    'partner_id' => $invoice->partner_id,
                    'description' => "Input VAT on {$label}",
                    'debit' => $vatTotal,
                    'credit' => '0',
                ]);
                $grossTotal = bcadd($grossTotal, $vatTotal, 2);
            }

            $entry->lines()->create([
                'account_id' => $this->account($invoice->company, '220')->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'debit' => '0',
                'credit' => $grossTotal,
            ]);

            $invoice->update([
                'journal_entry_id' => $entry->id,
                'status' => 'confirmed',
            ]);

            return $invoice->fresh(['lines', 'payments']);
        });
    }

    private function account(Company $company, string $code): Account
    {
        return Account::where('company_id', $company->id)->where('code', $code)->firstOrFail();
    }
}
```

Note: `$invoice->partner` and `$line->account` need eager-loading for the loop above to avoid N+1 queries under real usage; add `$invoice->loadMissing(['lines.account', 'lines.item', 'partner', 'company'])` as the first line inside the `DB::transaction` closure. Update Step 3's code to include this:

```php
        return DB::transaction(function () use ($invoice, $userId) {
            $invoice->loadMissing(['lines.account', 'lines.item', 'partner', 'company']);

            $vatRegistered = $invoice->company->is_vat_registered;
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=PurchaseInvoiceServiceTest`
Expected: PASS (all 7 tests written in Step 1)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Invoicing/PurchaseInvoiceService.php tests/Unit/PurchaseInvoiceServiceTest.php
git commit -m "feat: add PurchaseInvoiceService::confirm() with per-line expense/inventory GL posting and stock receipt"
```

---

### Task 5: `PurchaseInvoiceService::cancel()`

**Files:**
- Modify: `app/Services/Invoicing/PurchaseInvoiceService.php`
- Test: `tests/Unit/PurchaseInvoiceServiceTest.php` (extend)

**Interfaces:**
- Consumes: `StockMovementService::issue(Item $item, Warehouse $warehouse, string $quantity, string $movementDate, int $createdBy): StockMovement` (existing, Phase 2) — throws `App\Exceptions\InsufficientStockException` if on-hand quantity is insufficient.
- Produces: `PurchaseInvoiceService::cancel(PurchaseInvoice $invoice, int $userId): PurchaseInvoice` — throws `InvalidInvoiceStateException` both for invalid state/existing-payments AND when reversing stock fails due to insufficient quantity (translated from `InsufficientStockException`).

- [ ] **Step 1: Write the failing unit tests**

Add to `tests/Unit/PurchaseInvoiceServiceTest.php`:

```php
    public function test_cancelling_a_confirmed_invoice_reverses_gl_and_stock(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();
        $user = User::factory()->create();

        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'warehouse_id' => $warehouse->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => $item->name, 'quantity' => '10', 'unit_price' => '50.00', 'vat_rate' => '18.00']);
        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $cancelled = $this->service->cancel($confirmed, $user->id);

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('0.000', (string) \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first()->quantity_on_hand);

        $reversal = \App\Models\JournalEntry::where('company_id', $company->id)->where('id', '!=', $confirmed->journal_entry_id)->with('lines')->first();
        $this->assertNotNull($reversal);

        $originalTotalDebit = $confirmed->journalEntry->lines->sum('debit');
        $reversalTotalCredit = $reversal->lines->sum('credit');
        $this->assertSame((string) $originalTotalDebit, (string) $reversalTotalCredit);
    }

    public function test_cancelling_a_draft_invoice_throws(): void
    {
        $invoice = PurchaseInvoice::factory()->create(['status' => 'draft']);
        $user = User::factory()->create();

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->cancel($invoice, $user->id);
    }

    public function test_cancelling_an_invoice_with_a_payment_throws(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $expenseAccount = Account::where('company_id', $company->id)->where('code', '462')->first();
        $user = User::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => $expenseAccount->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);
        $this->service->recordPayment($confirmed, '50.00', '2026-03-05', 'bank', $user->id);

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->cancel($confirmed->fresh(), $user->id);
    }

    public function test_cancelling_throws_a_clear_error_when_received_stock_was_already_used_elsewhere(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();
        $user = User::factory()->create();

        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'warehouse_id' => $warehouse->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => $item->name, 'quantity' => '10', 'unit_price' => '50.00', 'vat_rate' => '0']);
        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        // Sell off 6 of the 10 received units via a plain issue, leaving only 4 on hand.
        app(\App\Services\Inventory\StockMovementService::class)->issue($item, $warehouse, '6', '2026-03-02', $user->id);

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->cancel($confirmed->fresh(), $user->id);
    }
```

**Note on the last test:** this exercises the exact divergence documented in the Global Constraints — `cancel()` must reverse 10 units via `issue()`, but only 4 remain on hand, so `StockMovementService::issue()` throws `InsufficientStockException`. Task 5's implementation must catch that and rethrow `InvalidInvoiceStateException`, or this test fails with an uncaught exception instead of the expected one.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=PurchaseInvoiceServiceTest`
Expected: The 4 new tests FAIL — `cancel()` doesn't exist yet (`Error: Call to undefined method`).

- [ ] **Step 3: Add `cancel()` to the service**

Modify `app/Services/Invoicing/PurchaseInvoiceService.php` — add the import and method:

```php
use App\Exceptions\InsufficientStockException;
```

```php
    public function cancel(PurchaseInvoice $invoice, int $userId): PurchaseInvoice
    {
        if ($invoice->status !== 'confirmed') {
            throw new InvalidInvoiceStateException("Purchase invoice #{$invoice->id} is not confirmed and cannot be cancelled.");
        }

        if ($invoice->payments()->exists()) {
            throw new InvalidInvoiceStateException('A purchase invoice with recorded payments cannot be cancelled.');
        }

        $invoice->loadMissing(['lines.item', 'lines.stockMovement', 'journalEntry.lines', 'warehouse', 'company']);

        return DB::transaction(function () use ($invoice, $userId) {
            foreach ($invoice->lines as $line) {
                if ($line->item_id === null) {
                    continue;
                }

                try {
                    $this->stockMovementService->issue(
                        $line->item,
                        $invoice->warehouse,
                        (string) $line->quantity,
                        now()->toDateString(),
                        $userId
                    );
                } catch (InsufficientStockException $e) {
                    throw new InvalidInvoiceStateException(
                        "Cannot cancel purchase invoice #{$invoice->id}: stock received against it has already been used elsewhere ({$e->getMessage()})."
                    );
                }
            }

            $reversal = JournalEntry::create([
                'company_id' => $invoice->company_id,
                'entry_date' => now()->toDateString(),
                'description' => "Reversal of purchase bill {$invoice->partner->name} #{$invoice->supplier_invoice_number}",
                'created_by' => $userId,
            ]);

            foreach ($invoice->journalEntry->lines as $originalLine) {
                $reversal->lines()->create([
                    'account_id' => $originalLine->account_id,
                    'partner_id' => $originalLine->partner_id,
                    'description' => 'Reversal: '.$originalLine->description,
                    'debit' => $originalLine->credit,
                    'credit' => $originalLine->debit,
                ]);
            }

            $invoice->update(['status' => 'cancelled']);

            return $invoice->fresh(['lines', 'payments']);
        });
    }
```

Note: `$invoice->partner` must be loaded for the reversal's description — add `'partner'` to the `loadMissing()` call above:

```php
        $invoice->loadMissing(['lines.item', 'lines.stockMovement', 'journalEntry.lines', 'warehouse', 'company', 'partner']);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=PurchaseInvoiceServiceTest`
Expected: PASS (all 11 tests: 7 from Task 4 plus these 4)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Invoicing/PurchaseInvoiceService.php tests/Unit/PurchaseInvoiceServiceTest.php
git commit -m "feat: add PurchaseInvoiceService::cancel() with GL/stock reversal and insufficient-stock guard"
```

---

### Task 6: `PurchaseInvoiceService::recordPayment()`

**Files:**
- Modify: `app/Services/Invoicing/PurchaseInvoiceService.php`
- Test: `tests/Unit/PurchaseInvoiceServiceTest.php` (extend)

**Interfaces:**
- Produces: `PurchaseInvoiceService::recordPayment(PurchaseInvoice $invoice, string $amount, string $paymentDate, string $paymentMethod, int $userId): PurchaseInvoicePayment`.

- [ ] **Step 1: Write the failing unit tests**

Add to `tests/Unit/PurchaseInvoiceServiceTest.php`:

```php
    public function test_recording_a_payment_posts_ap_debit_and_bank_credit(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $expenseAccount = Account::where('company_id', $company->id)->where('code', '462')->first();
        $user = User::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => $expenseAccount->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $payment = $this->service->recordPayment($confirmed, '60.00', '2026-03-10', 'bank', $user->id);

        $this->assertSame('60.00', (string) $payment->amount);
        $this->assertSame('partially_paid', $confirmed->fresh(['lines', 'payments'])->paymentStatus());

        $entry = \App\Models\JournalEntry::where('company_id', $company->id)->where('id', '!=', $confirmed->journal_entry_id)->with('lines.account')->first();
        $bank = $entry->lines->firstWhere('account.code', '100');
        $ap = $entry->lines->firstWhere('account.code', '220');

        $this->assertSame('60.00', (string) $bank->credit);
        $this->assertSame('60.00', (string) $ap->debit);
    }

    public function test_recording_a_cash_payment_credits_the_cash_account(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $expenseAccount = Account::where('company_id', $company->id)->where('code', '462')->first();
        $user = User::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => $expenseAccount->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $this->service->recordPayment($confirmed, '100.00', '2026-03-10', 'cash', $user->id);

        $entry = \App\Models\JournalEntry::where('company_id', $company->id)->where('id', '!=', $confirmed->journal_entry_id)->with('lines.account')->first();
        $cash = $entry->lines->firstWhere('account.code', '102');
        $this->assertSame('100.00', (string) $cash->credit);
    }

    public function test_payment_cannot_exceed_the_remaining_balance(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $expenseAccount = Account::where('company_id', $company->id)->where('code', '462')->first();
        $user = User::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => $expenseAccount->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->recordPayment($confirmed, '150.00', '2026-03-10', 'bank', $user->id);
    }

    public function test_payment_on_a_draft_invoice_throws(): void
    {
        $invoice = PurchaseInvoice::factory()->create(['status' => 'draft']);
        $user = User::factory()->create();

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->recordPayment($invoice, '10.00', '2026-03-10', 'bank', $user->id);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=PurchaseInvoiceServiceTest`
Expected: The 4 new tests FAIL — `recordPayment()` doesn't exist yet.

- [ ] **Step 3: Add `recordPayment()` to the service**

Modify `app/Services/Invoicing/PurchaseInvoiceService.php` — add the import and method:

```php
use App\Models\PurchaseInvoicePayment;
```

```php
    public function recordPayment(PurchaseInvoice $invoice, string $amount, string $paymentDate, string $paymentMethod, int $userId): PurchaseInvoicePayment
    {
        if ($invoice->status !== 'confirmed') {
            throw new InvalidInvoiceStateException("Purchase invoice #{$invoice->id} is not confirmed; payments can only be recorded against confirmed invoices.");
        }

        $invoice->loadMissing(['lines', 'payments', 'company', 'partner']);

        if (bccomp($amount, $invoice->balanceDue(), 2) > 0) {
            throw new InvalidInvoiceStateException("Payment of {$amount} exceeds the remaining balance of {$invoice->balanceDue()}.");
        }

        return DB::transaction(function () use ($invoice, $amount, $paymentDate, $paymentMethod, $userId) {
            $payment = $invoice->payments()->create([
                'amount' => $amount,
                'payment_date' => $paymentDate,
                'payment_method' => $paymentMethod,
                'created_by' => $userId,
            ]);

            $cashOrBankCode = $paymentMethod === 'cash' ? '102' : '100';
            $label = "Payment for purchase bill {$invoice->partner->name} #{$invoice->supplier_invoice_number}";

            $entry = JournalEntry::create([
                'company_id' => $invoice->company_id,
                'entry_date' => $paymentDate,
                'description' => $label,
                'created_by' => $userId,
            ]);

            $entry->lines()->create([
                'account_id' => $this->account($invoice->company, '220')->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'debit' => $amount,
                'credit' => '0',
            ]);

            $entry->lines()->create([
                'account_id' => $this->account($invoice->company, $cashOrBankCode)->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'debit' => '0',
                'credit' => $amount,
            ]);

            return $payment;
        });
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=PurchaseInvoiceServiceTest`
Expected: PASS (all 15 tests: 11 from Tasks 4–5 plus these 4)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Invoicing/PurchaseInvoiceService.php tests/Unit/PurchaseInvoiceServiceTest.php
git commit -m "feat: add PurchaseInvoiceService::recordPayment()"
```

---

### Task 7: `PurchaseInvoiceForm` (Draft CRUD)

**Files:**
- Create: `app/Livewire/Invoicing/PurchaseInvoiceForm.php`
- Create: `resources/views/livewire/invoicing/purchase-invoice-form.blade.php`
- Test: `tests/Feature/PurchaseInvoiceFormTest.php`

**Interfaces:**
- Consumes: `PurchaseInvoicePolicy` (Task 3), `PurchaseInvoice`/`PurchaseInvoiceLine` models (Task 2).
- Produces: route `purchase-invoices.create`/`purchase-invoices.edit` now render.

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\PurchaseInvoiceForm;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoiceFormTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_creates_a_draft_purchase_invoice_with_an_expense_line(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $account = Account::where('company_id', $company->id)->where('code', '462')->first();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'SUP-2026-045')
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.account_id', (string) $account->id)
            ->set('lines.0.description', 'Office rent')
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '500.00')
            ->set('lines.0.vat_rate', '18.00')
            ->set('sourceDocument', UploadedFile::fake()->create('bill.pdf', 50))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('purchase_invoices', [
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'supplier_invoice_number' => 'SUP-2026-045',
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('purchase_invoice_lines', [
            'account_id' => $account->id,
            'description' => 'Office rent',
        ]);

        $invoice = \App\Models\PurchaseInvoice::where('supplier_invoice_number', 'SUP-2026-045')->firstOrFail();
        $this->assertNotNull($invoice->source_document_path);
        Storage::disk('google')->assertExists($invoice->source_document_path);
    }

    public function test_an_item_line_requires_no_account_but_a_non_item_line_does(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('supplierInvoiceNumber', 'SUP-2026-046')
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->call('selectItem', 0, (string) $item->id)
            ->set('lines.0.quantity', '5')
            ->set('lines.0.unit_price', '20.00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('purchase_invoice_lines', ['item_id' => $item->id, 'account_id' => null]);
    }

    public function test_a_non_item_line_without_an_account_is_rejected(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'SUP-2026-047')
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.description', 'Missing account')
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '50.00')
            ->call('save')
            ->assertHasErrors(['lines.0.account_id']);
    }

    public function test_duplicate_supplier_invoice_number_for_the_same_partner_is_rejected(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $account = Account::where('company_id', $company->id)->where('code', '462')->first();
        \App\Models\PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'supplier_invoice_number' => 'DUP-1']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'DUP-1')
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.account_id', (string) $account->id)
            ->set('lines.0.description', 'Line')
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '10.00')
            ->call('save')
            ->assertHasErrors(['supplierInvoiceNumber']);
    }

    public function test_client_can_create_a_purchase_invoice_for_their_own_company(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $account = Account::where('company_id', $company->id)->where('code', '462')->first();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'SUP-2026-048')
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.account_id', (string) $account->id)
            ->set('lines.0.description', 'Line')
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '10.00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('purchase_invoices', ['company_id' => $company->id, 'supplier_invoice_number' => 'SUP-2026-048']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PurchaseInvoiceFormTest`
Expected: FAIL — `Class "App\Livewire\Invoicing\PurchaseInvoiceForm" not found`.

- [ ] **Step 3: Write the `PurchaseInvoiceForm` Livewire component**

```php
<?php

namespace App\Livewire\Invoicing;

use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class PurchaseInvoiceForm extends Component
{
    use WithFileUploads;

    public Company $company;

    public ?PurchaseInvoice $purchaseInvoice = null;

    public string $partnerId = '';

    public string $warehouseId = '';

    public string $supplierInvoiceNumber = '';

    public string $invoiceDate = '';

    public string $dueDate = '';

    public string $notes = '';

    public array $lines = [];

    public $sourceDocument = null;

    public function mount(Company $company, ?PurchaseInvoice $purchaseInvoice = null): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;

        Gate::authorize($purchaseInvoice ? 'update' : 'create', $purchaseInvoice ?? PurchaseInvoice::class);

        if ($purchaseInvoice) {
            if ($purchaseInvoice->company_id !== $company->id) {
                abort(404);
            }

            if ($purchaseInvoice->status !== 'draft') {
                abort(403, 'Only draft purchase invoices can be edited.');
            }
        }

        $this->purchaseInvoice = $purchaseInvoice;

        if ($purchaseInvoice) {
            $this->partnerId = (string) $purchaseInvoice->partner_id;
            $this->warehouseId = $purchaseInvoice->warehouse_id === null ? '' : (string) $purchaseInvoice->warehouse_id;
            $this->supplierInvoiceNumber = $purchaseInvoice->supplier_invoice_number;
            $this->invoiceDate = $purchaseInvoice->invoice_date->toDateString();
            $this->dueDate = $purchaseInvoice->due_date->toDateString();
            $this->notes = (string) $purchaseInvoice->notes;
            $this->lines = $purchaseInvoice->lines->map(fn ($line) => [
                'item_id' => $line->item_id === null ? '' : (string) $line->item_id,
                'account_id' => $line->account_id === null ? '' : (string) $line->account_id,
                'description' => (string) $line->description,
                'quantity' => (string) $line->quantity,
                'unit_price' => (string) $line->unit_price,
                'vat_rate' => (string) $line->vat_rate,
                'vat_deductible' => $line->vat_deductible,
            ])->toArray();
        } else {
            $this->invoiceDate = now()->toDateString();
            $this->dueDate = now()->toDateString();
            $this->lines = [$this->emptyLine()];
        }
    }

    protected function emptyLine(): array
    {
        return [
            'item_id' => '',
            'account_id' => '',
            'description' => '',
            'quantity' => '1',
            'unit_price' => '0',
            'vat_rate' => $this->company->is_vat_registered ? '18.00' : '0.00',
            'vat_deductible' => true,
        ];
    }

    public function addLine(): void
    {
        $this->lines[] = $this->emptyLine();
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function selectItem(int $index, string $itemId): void
    {
        $this->lines[$index]['item_id'] = $itemId;

        if ($itemId === '') {
            return;
        }

        $this->lines[$index]['account_id'] = '';

        $item = Item::where('company_id', $this->company->id)->find($itemId);

        if ($item) {
            $this->lines[$index]['description'] = $item->name;
            $this->lines[$index]['vat_rate'] = $this->company->is_vat_registered ? (string) $item->vat_rate : '0.00';
        }
    }

    public function save(): void
    {
        Gate::authorize($this->purchaseInvoice ? 'update' : 'create', $this->purchaseInvoice ?? PurchaseInvoice::class);

        $this->validate([
            'partnerId' => ['required', Rule::exists('partners', 'id')->where('company_id', $this->company->id)],
            'warehouseId' => ['nullable', Rule::exists('warehouses', 'id')->where('company_id', $this->company->id)],
            'supplierInvoiceNumber' => [
                'required', 'string', 'max:255',
                Rule::unique('purchase_invoices', 'supplier_invoice_number')
                    ->where('company_id', $this->company->id)
                    ->where('partner_id', $this->partnerId)
                    ->ignore($this->purchaseInvoice?->id),
            ],
            'invoiceDate' => 'required|date',
            'dueDate' => 'required|date|after_or_equal:invoiceDate',
            'lines' => 'required|array|min:1',
            'lines.*.item_id' => ['nullable', Rule::exists('items', 'id')->where('company_id', $this->company->id)],
            'lines.*.account_id' => ['nullable', Rule::exists('accounts', 'id')->where('company_id', $this->company->id)],
            'lines.*.description' => 'nullable|string|max:255',
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.vat_rate' => 'required|numeric|min:0|max:100',
            'sourceDocument' => 'nullable|file|max:10240',
        ]);

        foreach ($this->lines as $index => $line) {
            if (($line['item_id'] ?? '') === '' && ($line['account_id'] ?? '') === '') {
                $this->addError("lines.{$index}.account_id", 'Each non-item line needs an expense account.');

                return;
            }
        }

        $hasItemLines = collect($this->lines)->contains(fn ($line) => ($line['item_id'] ?? '') !== '');

        if ($hasItemLines && $this->warehouseId === '') {
            $this->addError('warehouseId', 'A warehouse is required when any line references an item.');

            return;
        }

        DB::transaction(function () {
            $invoice = $this->purchaseInvoice ?? new PurchaseInvoice([
                'company_id' => $this->company->id,
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);
            $invoice->company_id = $this->company->id;
            $invoice->partner_id = $this->partnerId;
            $invoice->warehouse_id = $this->warehouseId ?: null;
            $invoice->supplier_invoice_number = $this->supplierInvoiceNumber;
            $invoice->invoice_date = $this->invoiceDate;
            $invoice->due_date = $this->dueDate;
            $invoice->notes = $this->notes ?: null;

            if (! $invoice->exists) {
                $invoice->status = 'draft';
                $invoice->created_by = auth()->id();
            }

            $invoice->save();

            if ($this->sourceDocument) {
                $path = $this->sourceDocument->storeAs(
                    "purchase-invoices/{$this->company->id}/{$invoice->id}",
                    $this->sourceDocument->getClientOriginalName(),
                    'google'
                );
                $invoice->update(['source_document_path' => $path]);
            }

            $invoice->lines()->delete();

            foreach ($this->lines as $line) {
                $invoice->lines()->create([
                    'item_id' => $line['item_id'] ?: null,
                    'account_id' => ($line['item_id'] ?? '') === '' ? ($line['account_id'] ?: null) : null,
                    'description' => $line['description'] ?: null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'vat_rate' => $line['vat_rate'],
                    'vat_deductible' => $line['vat_deductible'] ?? true,
                ]);
            }

            $this->purchaseInvoice = $invoice;
        });

        $this->redirect(route('purchase-invoices.show', [$this->company, $this->purchaseInvoice]));
    }

    public function render()
    {
        return view('livewire.invoicing.purchase-invoice-form', [
            'partners' => Partner::where('company_id', $this->company->id)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('company_id', $this->company->id)->where('is_active', true)->orderBy('name')->get(),
            'items' => Item::where('company_id', $this->company->id)->where('is_active', true)->orderBy('name')->get(),
            'accounts' => Account::where('company_id', $this->company->id)->where('is_active', true)->orderBy('code')->get(),
        ]);
    }
}
```

- [ ] **Step 4: Write the view**

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">
        {{ $purchaseInvoice ? 'Edit draft purchase invoice' : 'New purchase invoice' }} — {{ $company->name }}
    </h1>

    <form wire:submit="save" class="space-y-6">
        <div class="bg-white shadow rounded-md p-4 grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div>
                <x-input-label for="partnerId" value="Supplier" />
                <select id="partnerId" wire:model="partnerId" class="w-full border-gray-300 rounded-md text-sm">
                    <option value="">Select a supplier</option>
                    @foreach ($partners as $partner)
                        <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                    @endforeach
                </select>
                @error('partnerId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="supplierInvoiceNumber" value="Supplier invoice number" />
                <x-text-input id="supplierInvoiceNumber" wire:model="supplierInvoiceNumber" class="w-full" />
                @error('supplierInvoiceNumber') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="warehouseId" value="Warehouse (if any line has an item)" />
                <select id="warehouseId" wire:model="warehouseId" class="w-full border-gray-300 rounded-md text-sm">
                    <option value="">—</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </select>
                @error('warehouseId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="invoiceDate" value="Bill date" />
                <x-text-input id="invoiceDate" type="date" wire:model="invoiceDate" class="w-full" />
                @error('invoiceDate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="dueDate" value="Due date" />
                <x-text-input id="dueDate" type="date" wire:model="dueDate" class="w-full" />
                @error('dueDate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="sourceDocument" value="Attach supplier's bill (optional)" />
                <input type="file" id="sourceDocument" wire:model="sourceDocument" class="w-full text-sm">
                @error('sourceDocument') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="bg-white shadow rounded-md p-4">
            <h2 class="font-semibold text-gray-700 mb-3">Lines</h2>
            @foreach ($lines as $index => $line)
                <div class="flex flex-wrap gap-3 items-end mb-3 pb-3 border-b border-gray-100">
                    <div class="w-48">
                        <x-input-label value="Item (optional)" />
                        <select wire:change="selectItem({{ $index }}, $event.target.value)" class="w-full border-gray-300 rounded-md text-sm">
                            <option value="">— expense/service —</option>
                            @foreach ($items as $item)
                                <option value="{{ $item->id }}" @selected($line['item_id'] === (string) $item->id)>{{ $item->code }} — {{ $item->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if (($line['item_id'] ?? '') === '')
                        <div class="w-56">
                            <x-input-label value="Expense account" />
                            <select wire:model="lines.{{ $index }}.account_id" class="w-full border-gray-300 rounded-md text-sm">
                                <option value="">Select account</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                                @endforeach
                            </select>
                            @error("lines.{$index}.account_id") <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                        </div>
                    @endif
                    <div class="flex-1 min-w-[12rem]">
                        <x-input-label value="Description" />
                        <x-text-input wire:model="lines.{{ $index }}.description" class="w-full" />
                    </div>
                    <div class="w-24">
                        <x-input-label value="Qty" />
                        <x-text-input wire:model="lines.{{ $index }}.quantity" class="w-full" />
                    </div>
                    <div class="w-32">
                        <x-input-label value="Unit price" />
                        <x-text-input wire:model="lines.{{ $index }}.unit_price" class="w-full" />
                    </div>
                    <div class="w-24">
                        <x-input-label value="VAT %" />
                        <x-text-input wire:model="lines.{{ $index }}.vat_rate" class="w-full" />
                    </div>
                    <div class="flex items-center gap-1 pb-2">
                        <input type="checkbox" id="vatDeductible{{ $index }}" wire:model="lines.{{ $index }}.vat_deductible">
                        <label for="vatDeductible{{ $index }}" class="text-xs">VAT deductible</label>
                    </div>
                    <button type="button" wire:click="removeLine({{ $index }})" class="text-red-600 text-sm">Remove</button>
                </div>
            @endforeach

            <button type="button" wire:click="addLine" class="text-indigo-600 text-sm hover:underline">+ Add line</button>
        </div>

        <div class="bg-white shadow rounded-md p-4">
            <x-input-label for="notes" value="Notes" />
            <textarea id="notes" wire:model="notes" rows="2" class="w-full border-gray-300 rounded-md text-sm"></textarea>
        </div>

        <x-primary-button type="submit">Save draft</x-primary-button>
    </form>
</div>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=PurchaseInvoiceFormTest`
Expected: PASS (all 5 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Invoicing/PurchaseInvoiceForm.php resources/views/livewire/invoicing/purchase-invoice-form.blade.php tests/Feature/PurchaseInvoiceFormTest.php
git commit -m "feat: add PurchaseInvoiceForm draft CRUD screen with expense-account picker and file upload"
```

---

### Task 8: `PurchaseInvoiceShow` & Document Download

**Files:**
- Create: `app/Livewire/Invoicing/PurchaseInvoiceShow.php`
- Create: `resources/views/livewire/invoicing/purchase-invoice-show.blade.php`
- Create: `app/Http/Controllers/PurchaseInvoiceDocumentController.php`
- Test: `tests/Feature/PurchaseInvoiceShowTest.php`

**Interfaces:**
- Consumes: `PurchaseInvoiceService::confirm()`/`cancel()`/`recordPayment()` (Tasks 4–6).
- Produces: route `purchase-invoices.show` and `purchase-invoices.document` now render.

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\PurchaseInvoiceShow;
use App\Models\Account;
use App\Models\Company;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoiceShowTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function seedAccounts($company): void
    {
        foreach (['130', '220', '660', '100', '102'] as $code) {
            Account::firstOrCreate(['company_id' => $company->id, 'code' => $code], ['name' => $code]);
        }
    }

    public function test_confirm_action_posts_the_gl_entry(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $account = Account::where('company_id', $company->id)->where('code', '462')->first()
            ?? Account::factory()->for($company)->create(['code' => '462', 'name' => 'Services']);
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);
        $invoice->lines()->create(['account_id' => $account->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceShow::class, ['company' => $company, 'purchaseInvoice' => $invoice])
            ->call('confirm')
            ->assertHasNoErrors();

        $this->assertSame('confirmed', $invoice->fresh()->status);
    }

    public function test_cancel_action_is_available_only_when_unpaid(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $account = Account::factory()->for($company)->create(['code' => '462', 'name' => 'Services']);
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['account_id' => $account->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $journalEntry = \App\Models\JournalEntry::factory()->for($company)->create();
        $journalEntry->lines()->create(['account_id' => $account->id, 'debit' => '100.00', 'credit' => '0']);
        $invoice->update(['journal_entry_id' => $journalEntry->id]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceShow::class, ['company' => $company, 'purchaseInvoice' => $invoice])
            ->call('cancel')
            ->assertHasNoErrors();

        $this->assertSame('cancelled', $invoice->fresh()->status);
    }

    public function test_it_downloads_the_attached_source_document(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $path = "purchase-invoices/{$company->id}/1/bill.pdf";
        Storage::disk('google')->put($path, 'fake-pdf-content');
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'source_document_path' => $path]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('purchase-invoices.document', [$company, $invoice]));

        $response->assertOk();
    }

    public function test_document_download_requires_view_permission(): void
    {
        Storage::fake('google');
        Storage::disk('google')->put('purchase-invoices/1/1/bill.pdf', 'fake-pdf-content');

        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($otherCompany)->create(['source_document_path' => 'purchase-invoices/1/1/bill.pdf']);
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        Role::findOrCreate('client');
        $this->actingAs($client);

        $this->get(route('purchase-invoices.document', [$otherCompany, $invoice]))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PurchaseInvoiceShowTest`
Expected: FAIL — `Class "App\Livewire\Invoicing\PurchaseInvoiceShow" not found`.

- [ ] **Step 3: Write the `PurchaseInvoiceShow` Livewire component**

```php
<?php

namespace App\Livewire\Invoicing;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Company;
use App\Models\PurchaseInvoice;
use App\Services\Invoicing\PurchaseInvoiceService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseInvoiceShow extends Component
{
    public Company $company;

    public PurchaseInvoice $purchaseInvoice;

    public string $paymentAmount = '';

    public string $paymentDate = '';

    public string $paymentMethod = 'bank';

    public function mount(Company $company, PurchaseInvoice $purchaseInvoice): void
    {
        Gate::authorize('view', $purchaseInvoice);

        if ($purchaseInvoice->company_id !== $company->id) {
            abort(404);
        }

        $this->company = $company;
        $this->purchaseInvoice = $purchaseInvoice;
        $this->paymentDate = now()->toDateString();
    }

    public function confirm(PurchaseInvoiceService $service): void
    {
        Gate::authorize('update', $this->purchaseInvoice);

        try {
            $service->confirm($this->purchaseInvoice, auth()->id());
        } catch (InsufficientStockException|InvalidInvoiceStateException $e) {
            $this->addError('confirm', $e->getMessage());

            return;
        }

        $this->purchaseInvoice->refresh();
    }

    public function cancel(PurchaseInvoiceService $service): void
    {
        Gate::authorize('update', $this->purchaseInvoice);

        try {
            $service->cancel($this->purchaseInvoice, auth()->id());
        } catch (InvalidInvoiceStateException $e) {
            $this->addError('cancel', $e->getMessage());

            return;
        }

        $this->purchaseInvoice->refresh();
    }

    public function recordPayment(PurchaseInvoiceService $service): void
    {
        Gate::authorize('update', $this->purchaseInvoice);

        $this->validate([
            'paymentAmount' => 'required|numeric|min:0.01',
            'paymentDate' => 'required|date',
            'paymentMethod' => 'required|in:bank,cash',
        ]);

        try {
            $service->recordPayment($this->purchaseInvoice, $this->paymentAmount, $this->paymentDate, $this->paymentMethod, auth()->id());
        } catch (InvalidInvoiceStateException $e) {
            $this->addError('paymentAmount', $e->getMessage());

            return;
        }

        $this->reset(['paymentAmount']);
        $this->purchaseInvoice->refresh();
    }

    public function render()
    {
        $invoice = $this->purchaseInvoice->fresh(['lines.item', 'lines.account', 'payments', 'partner']);

        return view('livewire.invoicing.purchase-invoice-show', [
            'invoice' => $invoice,
        ]);
    }
}
```

- [ ] **Step 4: Write the document download controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PurchaseInvoice;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class PurchaseInvoiceDocumentController extends Controller
{
    public function __invoke(Company $company, PurchaseInvoice $purchaseInvoice)
    {
        Gate::authorize('view', $purchaseInvoice);

        if ($purchaseInvoice->company_id !== $company->id) {
            abort(404);
        }

        if ($purchaseInvoice->source_document_path === null) {
            abort(404, 'No document attached to this purchase invoice.');
        }

        return Storage::disk('google')->download($purchaseInvoice->source_document_path);
    }
}
```

- [ ] **Step 5: Write the view**

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">
        Purchase bill — {{ $invoice->partner->name }} #{{ $invoice->supplier_invoice_number }}
    </h1>
    <p class="text-sm text-gray-500 mb-4">status: {{ $invoice->status }}
        @if ($invoice->status === 'confirmed') ({{ $invoice->paymentStatus() }}@if($invoice->isOverdue()), overdue @endif) @endif
    </p>

    @error('confirm') <p class="text-red-600 text-sm mb-3">{{ $message }}</p> @enderror
    @error('cancel') <p class="text-red-600 text-sm mb-3">{{ $message }}</p> @enderror

    <div class="bg-white shadow rounded-md p-4 mb-4">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-1">Description</th>
                    <th class="py-1">Item/Account</th>
                    <th class="py-1">Qty</th>
                    <th class="py-1">Unit price</th>
                    <th class="py-1">VAT %</th>
                    <th class="py-1">Line total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->lines as $line)
                    <tr>
                        <td class="py-1">{{ $line->description }}</td>
                        <td class="py-1">{{ $line->item?->name ?? $line->account?->code.' — '.$line->account?->name }}</td>
                        <td class="py-1">{{ $line->quantity }}</td>
                        <td class="py-1">{{ $line->unit_price }}</td>
                        <td class="py-1">{{ $line->vat_rate }}{{ $line->vat_deductible ? '' : ' (non-ded.)' }}</td>
                        <td class="py-1">{{ $line->lineTotal() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="text-right text-sm mt-3 space-y-1">
            <div>Subtotal: {{ $invoice->subtotal() }}</div>
            <div>VAT: {{ $invoice->vatTotal() }}</div>
            <div class="font-semibold">Total: {{ $invoice->grandTotal() }}</div>
            @if ($invoice->status === 'confirmed')
                <div>Balance due: {{ $invoice->balanceDue() }}</div>
            @endif
        </div>
    </div>

    <div class="flex gap-3 mb-4">
        @if ($invoice->status === 'draft')
            <a href="{{ route('purchase-invoices.edit', [$company, $invoice]) }}" class="text-indigo-600 hover:underline text-sm">Edit</a>
            <button type="button" wire:click="confirm" class="bg-indigo-600 text-white px-3 py-1.5 rounded-md text-sm">Confirm</button>
        @endif
        @if ($invoice->source_document_path)
            <a href="{{ route('purchase-invoices.document', [$company, $invoice]) }}" class="text-indigo-600 hover:underline text-sm">Download original</a>
        @endif
        @if ($invoice->status === 'confirmed' && $invoice->payments->isEmpty())
            <button type="button" wire:click="cancel" class="text-red-600 hover:underline text-sm">Cancel invoice</button>
        @endif
    </div>

    @if ($invoice->status === 'confirmed')
        <div class="bg-white shadow rounded-md p-4">
            <h2 class="font-semibold text-gray-700 mb-2">Payments</h2>
            <table class="min-w-full text-sm mb-3">
                <tbody>
                    @foreach ($invoice->payments as $payment)
                        <tr>
                            <td class="py-1">{{ $payment->payment_date->toDateString() }}</td>
                            <td class="py-1">{{ ucfirst($payment->payment_method) }}</td>
                            <td class="py-1">{{ $payment->amount }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($invoice->paymentStatus() !== 'paid')
                <form wire:submit="recordPayment" class="flex flex-wrap gap-3 items-end">
                    <div>
                        <x-input-label for="paymentAmount" value="Amount" />
                        <x-text-input id="paymentAmount" wire:model="paymentAmount" class="w-32" />
                        @error('paymentAmount') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-input-label for="paymentDate" value="Date" />
                        <x-text-input id="paymentDate" type="date" wire:model="paymentDate" class="w-full" />
                    </div>
                    <div>
                        <x-input-label for="paymentMethod" value="Method" />
                        <select id="paymentMethod" wire:model="paymentMethod" class="border-gray-300 rounded-md text-sm">
                            <option value="bank">Bank</option>
                            <option value="cash">Cash</option>
                        </select>
                    </div>
                    <x-primary-button type="submit">Record payment</x-primary-button>
                </form>
            @endif
        </div>
    @endif
</div>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=PurchaseInvoiceShowTest`
Expected: PASS (all 4 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Invoicing/PurchaseInvoiceShow.php resources/views/livewire/invoicing/purchase-invoice-show.blade.php app/Http/Controllers/PurchaseInvoiceDocumentController.php tests/Feature/PurchaseInvoiceShowTest.php
git commit -m "feat: add PurchaseInvoiceShow screen with confirm/cancel/payment actions and document download"
```

---

### Task 9: `PurchaseInvoiceIndex` (List)

**Files:**
- Create: `app/Livewire/Invoicing/PurchaseInvoiceIndex.php`
- Create: `resources/views/livewire/invoicing/purchase-invoice-index.blade.php`
- Test: `tests/Feature/PurchaseInvoiceIndexTest.php`

**Interfaces:**
- Produces: route `purchase-invoices.index` now renders.

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\PurchaseInvoiceIndex;
use App\Models\Company;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoiceIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_it_lists_the_companys_purchase_invoices(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Acme Supplies DOOEL']);
        PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'supplier_invoice_number' => 'SUP-100']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->assertSee('Acme Supplies DOOEL')
            ->assertSee('SUP-100');
    }

    public function test_it_filters_by_status(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'draft', 'supplier_invoice_number' => 'DRAFT-1']);
        PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'supplier_invoice_number' => 'CONF-1']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->set('statusFilter', 'confirmed')
            ->assertSee('CONF-1')
            ->assertDontSee('DRAFT-1');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PurchaseInvoiceIndexTest`
Expected: FAIL — `Class "App\Livewire\Invoicing\PurchaseInvoiceIndex" not found`.

- [ ] **Step 3: Write the `PurchaseInvoiceIndex` Livewire component**

```php
<?php

namespace App\Livewire\Invoicing;

use App\Models\Company;
use App\Models\PurchaseInvoice;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseInvoiceIndex extends Component
{
    public Company $company;

    public string $statusFilter = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        $invoices = PurchaseInvoice::where('company_id', $this->company->id)
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->with(['partner', 'lines', 'payments'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->get();

        return view('livewire.invoicing.purchase-invoice-index', ['invoices' => $invoices]);
    }
}
```

- [ ] **Step 4: Write the view**

```blade
<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Purchase Invoices — {{ $company->name }}</h1>
        <a href="{{ route('purchase-invoices.create', $company) }}" class="bg-indigo-600 text-white px-3 py-1.5 rounded-md text-sm">New purchase invoice</a>
    </div>

    <div class="mb-4">
        <select wire:model.live="statusFilter" class="border-gray-300 rounded-md text-sm">
            <option value="">All statuses</option>
            <option value="draft">Draft</option>
            <option value="confirmed">Confirmed</option>
            <option value="cancelled">Cancelled</option>
        </select>
    </div>

    <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Supplier #</th>
                <th class="py-2 px-4">Supplier</th>
                <th class="py-2 px-4">Date</th>
                <th class="py-2 px-4">Status</th>
                <th class="py-2 px-4">Total</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($invoices as $invoice)
                <tr class="text-sm">
                    <td class="py-2 px-4">{{ $invoice->supplier_invoice_number }}</td>
                    <td class="py-2 px-4">{{ $invoice->partner->name }}</td>
                    <td class="py-2 px-4">{{ $invoice->invoice_date->toDateString() }}</td>
                    <td class="py-2 px-4">{{ $invoice->status }}</td>
                    <td class="py-2 px-4">{{ $invoice->grandTotal() }}</td>
                    <td class="py-2 px-4">
                        <a href="{{ route('purchase-invoices.show', [$company, $invoice]) }}" class="text-indigo-600 hover:underline">View</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-4 px-4 text-gray-500">No purchase invoices yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=PurchaseInvoiceIndexTest`
Expected: PASS (both tests)

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Invoicing/PurchaseInvoiceIndex.php resources/views/livewire/invoicing/purchase-invoice-index.blade.php tests/Feature/PurchaseInvoiceIndexTest.php
git commit -m "feat: add PurchaseInvoiceIndex list screen"
```

---

### Task 10: Companies-List Links & Cross-Cutting Tests

**Files:**
- Modify: `resources/views/livewire/company-index.blade.php`
- Create: `tests/Feature/PurchaseInvoicePoliciesTest.php`
- Modify: `tests/Feature/InvoicingRoutesTest.php` (extend — the full happy-path route check deferred from Task 3)

**Interfaces:** None new — this task wires up navigation and closes out cross-cutting coverage, mirroring Phase 3a Task 12.

- [ ] **Step 1: Write the failing cross-cutting policy test**

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoicePoliciesTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_client_can_manage_their_own_companys_purchase_invoices(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $invoice = PurchaseInvoice::factory()->for($company)->create();

        $this->assertTrue($client->can('create', PurchaseInvoice::class));
        $this->assertTrue($client->can('update', $invoice));
        $this->assertTrue($client->can('view', $invoice));
    }

    public function test_client_cannot_manage_another_companys_purchase_invoices(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $invoice = PurchaseInvoice::factory()->for($otherCompany)->create();

        $this->assertFalse($client->can('view', $invoice));
        $this->assertFalse($client->can('update', $invoice));
    }

    public function test_accountant_not_assigned_to_a_company_cannot_view_its_purchase_invoices(): void
    {
        $companyTheyManage = Company::factory()->create();
        $companyTheyDoNotManage = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($companyTheyManage);
        $invoice = PurchaseInvoice::factory()->for($companyTheyDoNotManage)->create();

        $this->assertFalse($accountant->can('view', $invoice));
    }

    public function test_accountant_assigned_to_a_company_can_manage_its_purchase_invoices(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($company);

        $invoice = PurchaseInvoice::factory()->for($company)->create();

        $this->assertTrue($accountant->can('view', $invoice));
        $this->assertTrue($accountant->can('update', $invoice));
        $this->assertTrue($accountant->can('create', PurchaseInvoice::class));
    }

    public function test_admin_can_manage_purchase_invoices_for_any_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $invoice = PurchaseInvoice::factory()->for($company)->create();

        $this->assertTrue($admin->can('view', $invoice));
        $this->assertTrue($admin->can('update', $invoice));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PurchaseInvoicePoliciesTest`
Expected: FAIL if `PurchaseInvoicePolicy` were missing — but it was already created in Task 3, so this should actually PASS immediately. Run it to confirm.

- [ ] **Step 3: Run test to verify it passes**

Run: `php artisan test --filter=PurchaseInvoicePoliciesTest`
Expected: PASS (all 5 tests) — this confirms Task 3's `PurchaseInvoicePolicy` was correct all along; no code change needed here, only the test.

- [ ] **Step 4: Add companies-list links**

Modify `resources/views/livewire/company-index.blade.php` — extend the existing "Invoicing:" block (added in Phase 3a Task 1):

```blade
                    <div class="mt-1 text-sm text-gray-500">Invoicing:</div>
                    <div class="space-x-3 text-sm">
                        <a href="{{ route('partners.index', $company) }}" class="text-indigo-600 hover:underline">Partners</a>
                        <a href="{{ route('sales-invoices.index', $company) }}" class="text-indigo-600 hover:underline">Sales Invoices</a>
                        <a href="{{ route('sales-invoices.create', $company) }}" class="text-indigo-600 hover:underline">New Invoice</a>
                        <a href="{{ route('purchase-invoices.index', $company) }}" class="text-indigo-600 hover:underline">Purchase Invoices</a>
                        <a href="{{ route('purchase-invoices.create', $company) }}" class="text-indigo-600 hover:underline">New Purchase Invoice</a>
                    </div>
```

- [ ] **Step 5: Complete the deferred full-route-set check in `InvoicingRoutesTest`**

Modify the `test_purchase_invoice_index_and_create_routes_render_successfully_for_an_admin` test added in Task 3, extending it to also hit `show` now that `PurchaseInvoiceShow` exists:

```php
    public function test_purchase_invoice_index_and_create_routes_render_successfully_for_an_admin(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = \App\Models\PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('purchase-invoices.index', $company))->assertOk();
        $this->get(route('purchase-invoices.create', $company))->assertOk();
        $this->get(route('purchase-invoices.show', [$company, $invoice]))->assertOk();
    }
```

Run: `php artisan test --filter=InvoicingRoutesTest`
Expected: PASS

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test`
Expected: PASS — every test in the codebase, including all of Phase 3a's 246 tests (proving the `HasInvoiceTotals` refactor in Task 1 didn't regress anything) plus every new Phase 3b test.

- [ ] **Step 7: Commit**

```bash
git add resources/views/livewire/company-index.blade.php tests/Feature/PurchaseInvoicePoliciesTest.php tests/Feature/InvoicingRoutesTest.php
git commit -m "feat: add purchase invoicing companies-list links and cross-cutting policy tests"
```

---

## Self-Review Notes

- **Spec coverage:** every section of `docs/superpowers/specs/2026-07-21-phase3b-purchase-invoicing-design.md` maps to a task — data model (Tasks 2–3), GL posting on confirm (Task 4), cancel/reversal incl. the `HasInvoiceTotals`/insufficient-stock edge case (Task 5), payments (Task 6), document attachment (Tasks 7–8), access control (Task 3, verified Task 10), screens (Tasks 7–9), companies-list links (Task 10). The shared-trait refactor (Task 1) implements the approved Approach B decision.
- **Placeholder scan:** no TBD/TODO; every step has complete, runnable code.
- **Type consistency:** `PurchaseInvoiceService::confirm()`/`cancel()`/`recordPayment()` signatures match what `PurchaseInvoiceShow` (Task 8) calls; `HasInvoiceTotals` (Task 1) method names match what `PurchaseInvoiceLine`/`PurchaseInvoiceShow`/`PurchaseInvoiceIndex` views call (`lineTotal()`, `vatAmount()`, `subtotal()`, `vatTotal()`, `grandTotal()`, `paidTotal()`, `balanceDue()`, `paymentStatus()`, `isOverdue()`); route names (`purchase-invoices.index/create/edit/show/document`) registered in Task 3 match every `route()` call in later tasks' views and tests.
