# Увозни трошоци → магацинска вредност (landed cost) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On a purchase invoice with imported stock, let the accountant enter freight/forwarder costs and customs duty, and have them raise the warehouse value (`StockLevel.average_cost`) of the received items above the raw invoice price — without changing general-ledger posting.

**Architecture:** Two new child tables hang off `purchase_invoices` (import costs, customs-tariff lines). A new pure, stateless service (`LandedCostAllocator`) turns a set of stock lines + a total extra-cost figure into a landed unit cost per line, proportional to each line's net value, remainder-to-last-line. It is called from two places that never drift apart because they share it: the Livewire form's live preview (in-memory arrays, nothing saved yet) and `PurchaseInvoiceService::confirm()` (persisted model, right before stock receipt).

**Tech Stack:** Laravel 13, Livewire 3, Blade, bcmath via `App\Support\Bcmath`, SQLite in tests / MySQL in CI+prod.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-10-01-import-landed-cost-design.md` — read it before starting; this plan implements it exactly.
- No GL posting for import costs. Account `660` keeps being debited with only the raw invoice net amount — do not touch `PurchaseInvoiceService::confirm()`'s journal-entry-building loop beyond the one line described in Task 3.
- Landed cost math excludes VAT (`vat_amount` columns are informational only, never summed into the allocation total).
- Existing non-import purchase invoices must produce byte-identical `StockMovement.unit_cost` and journal entries after this change (`is_import` defaults to `false`).
- Tariff code is free text — no lookup/validation against a real customs nomenclature.
- Every new bcmath computation uses `App\Support\Bcmath::roundHalfUp()` for rounding, matching the codebase's existing convention (never bcmath's native truncation).
- Commit after each task's tests pass. Run the full suite once before the final commit of the last task, per this project's standing practice.

---

### Task 1: Schema, models, factories

**Files:**
- Create: `database/migrations/2026_10_01_100000_add_import_support_to_purchase_invoices.php`
- Create: `app/Models/PurchaseInvoiceImportCost.php`
- Create: `app/Models/PurchaseInvoiceTariffLine.php`
- Create: `database/factories/PurchaseInvoiceImportCostFactory.php`
- Create: `database/factories/PurchaseInvoiceTariffLineFactory.php`
- Modify: `app/Models/PurchaseInvoice.php`
- Test: `tests/Unit/Models/PurchaseInvoiceImportSupportTest.php`

**Interfaces:**
- Produces: `PurchaseInvoice::importCosts(): HasMany` (ordered by `sort_order`), `PurchaseInvoice::tariffLines(): HasMany` (ordered by `sort_order`), new columns `is_import` (bool), `customs_declaration_number` (?string), `import_date` (?Carbon date), `import_currency_code` (?string), `import_exchange_rate` (?string, decimal:4 cast). `PurchaseInvoiceImportCost` fields: `payee_name`, `reference_number`, `foreign_amount`, `base_amount`, `vat_amount`, `sort_order`. `PurchaseInvoiceTariffLine` fields: `tariff_code`, `foreign_amount`, `customs_duty`, `vat_amount`, `sort_order`.

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
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->boolean('is_import')->default(false)->after('order_number');
            $table->string('customs_declaration_number')->nullable()->after('is_import');
            $table->date('import_date')->nullable()->after('customs_declaration_number');
            $table->string('import_currency_code', 3)->nullable()->after('import_date');
            $table->decimal('import_exchange_rate', 12, 4)->nullable()->after('import_currency_code');
        });

        Schema::create('purchase_invoice_import_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('payee_name');
            $table->string('reference_number')->nullable();
            $table->decimal('foreign_amount', 14, 2)->nullable();
            $table->decimal('base_amount', 14, 2);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('purchase_invoice_tariff_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('tariff_code');
            $table->decimal('foreign_amount', 14, 2)->nullable();
            $table->decimal('customs_duty', 14, 2);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_tariff_lines');
        Schema::dropIfExists('purchase_invoice_import_costs');

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn(['is_import', 'customs_declaration_number', 'import_date', 'import_currency_code', 'import_exchange_rate']);
        });
    }
};
```

- [ ] **Step 2: Write the two new models**

`app/Models/PurchaseInvoiceImportCost.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceImportCost extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_invoice_id', 'payee_name', 'reference_number',
        'foreign_amount', 'base_amount', 'vat_amount', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'foreign_amount' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
        ];
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }
}
```

`app/Models/PurchaseInvoiceTariffLine.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceTariffLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_invoice_id', 'tariff_code',
        'foreign_amount', 'customs_duty', 'vat_amount', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'foreign_amount' => 'decimal:2',
            'customs_duty' => 'decimal:2',
            'vat_amount' => 'decimal:2',
        ];
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }
}
```

- [ ] **Step 3: Add the relations and fillable/casts to `PurchaseInvoice`**

Modify `app/Models/PurchaseInvoice.php`:

```php
    protected $fillable = [
        'company_id', 'partner_id', 'warehouse_id', 'journal_entry_id',
        'supplier_invoice_number', 'order_number', 'invoice_date', 'due_date',
        'status', 'notes', 'created_by',
        'is_import', 'customs_declaration_number', 'import_date',
        'import_currency_code', 'import_exchange_rate',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'is_import' => 'boolean',
            'import_date' => 'date',
            'import_exchange_rate' => 'decimal:4',
        ];
    }
```

Add, next to the existing `lines()`/`payments()` relations:

```php
    public function importCosts(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceImportCost::class)->orderBy('sort_order');
    }

    public function tariffLines(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceTariffLine::class)->orderBy('sort_order');
    }
```

(`HasMany` is already imported in this file.)

- [ ] **Step 4: Write the two new factories**

`database/factories/PurchaseInvoiceImportCostFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\PurchaseInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseInvoiceImportCostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'purchase_invoice_id' => PurchaseInvoice::factory(),
            'payee_name' => $this->faker->company(),
            'reference_number' => (string) $this->faker->numberBetween(1000, 9999),
            'foreign_amount' => null,
            'base_amount' => '1000.00',
            'vat_amount' => '0.00',
            'sort_order' => 0,
        ];
    }
}
```

`database/factories/PurchaseInvoiceTariffLineFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\PurchaseInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseInvoiceTariffLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'purchase_invoice_id' => PurchaseInvoice::factory(),
            'tariff_code' => (string) $this->faker->numberBetween(10000000, 99999999),
            'foreign_amount' => null,
            'customs_duty' => '100.00',
            'vat_amount' => '0.00',
            'sort_order' => 0,
        ];
    }
}
```

- [ ] **Step 5: Write the failing test**

Create `tests/Unit/Models/PurchaseInvoiceImportSupportTest.php`:

```php
<?php

namespace Tests\Unit\Models;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceImportCost;
use App\Models\PurchaseInvoiceTariffLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoiceImportSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_invoice_defaults_to_not_import(): void
    {
        $invoice = PurchaseInvoice::factory()->create();

        $this->assertFalse($invoice->is_import);
        $this->assertNull($invoice->import_date);
    }

    public function test_import_costs_and_tariff_lines_are_ordered_and_cascade_deleted(): void
    {
        $invoice = PurchaseInvoice::factory()->create(['is_import' => true]);

        PurchaseInvoiceImportCost::factory()->for($invoice, 'purchaseInvoice')->create(['sort_order' => 1, 'payee_name' => 'Second']);
        PurchaseInvoiceImportCost::factory()->for($invoice, 'purchaseInvoice')->create(['sort_order' => 0, 'payee_name' => 'First']);
        PurchaseInvoiceTariffLine::factory()->for($invoice, 'purchaseInvoice')->create(['sort_order' => 0, 'tariff_code' => '12345678']);

        $this->assertSame(['First', 'Second'], $invoice->importCosts->pluck('payee_name')->all());
        $this->assertSame('12345678', $invoice->tariffLines->first()->tariff_code);

        $invoice->delete();

        $this->assertSame(0, PurchaseInvoiceImportCost::count());
        $this->assertSame(0, PurchaseInvoiceTariffLine::count());
    }
}
```

- [ ] **Step 6: Run the test to verify it fails**

Run: `php artisan test tests/Unit/Models/PurchaseInvoiceImportSupportTest.php`
Expected: FAIL — migration/columns/relations don't exist yet (if Steps 1-4 weren't done first) or straightforward PASS once they are. Run it *before* Steps 1-4 against a clean checkout if you want to see the red state; otherwise just confirm it's red before your implementation and green after.

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Models/PurchaseInvoiceImportSupportTest.php`
Expected: PASS (2 tests)

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_10_01_100000_add_import_support_to_purchase_invoices.php app/Models/PurchaseInvoiceImportCost.php app/Models/PurchaseInvoiceTariffLine.php app/Models/PurchaseInvoice.php database/factories/PurchaseInvoiceImportCostFactory.php database/factories/PurchaseInvoiceTariffLineFactory.php tests/Unit/Models/PurchaseInvoiceImportSupportTest.php
git commit -m "Add import-cost and tariff-line schema to purchase invoices"
```

---

### Task 2: `LandedCostAllocator`

**Files:**
- Create: `app/Services/Inventory/LandedCostAllocator.php`
- Test: `tests/Unit/Services/Inventory/LandedCostAllocatorTest.php`

**Interfaces:**
- Consumes: `App\Support\Bcmath::roundHalfUp(string $value, int $scale): string` (already exists).
- Produces: `App\Services\Inventory\LandedCostAllocator::allocate(array $stockLines, string $totalImportCosts): array`, where `$stockLines` is `array<string, array{net: string, quantity: string}>` keyed by a caller-chosen id, and the return value is `array<string, string>` — same keys, each a landed unit cost string at 4 decimals. This exact signature is consumed by Task 3 (keyed by purchase-invoice-line id) and Task 4 (keyed by line array index).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Services/Inventory/LandedCostAllocatorTest.php`:

```php
<?php

namespace Tests\Unit\Services\Inventory;

use App\Services\Inventory\LandedCostAllocator;
use PHPUnit\Framework\TestCase;

class LandedCostAllocatorTest extends TestCase
{
    private LandedCostAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator = new LandedCostAllocator;
    }

    public function test_zero_import_costs_leaves_landed_cost_equal_to_net_over_quantity(): void
    {
        $result = $this->allocator->allocate([
            'a' => ['net' => '500.00', 'quantity' => '10'],
        ], '0.00');

        $this->assertSame(['a' => '50.0000'], $result);
    }

    public function test_no_stock_lines_returns_empty(): void
    {
        $this->assertSame([], $this->allocator->allocate([], '100.00'));
    }

    public function test_allocates_proportionally_with_remainder_on_the_last_line(): void
    {
        $result = $this->allocator->allocate([
            'a' => ['net' => '800.00', 'quantity' => '10'],
            'b' => ['net' => '200.00', 'quantity' => '5'],
        ], '100.00');

        // a: share = 100*800/1000 = 80.00 exactly; landed value 880.00 / 10
        $this->assertSame('88.0000', $result['a']);
        // b (last): share = 100.00 - 80.00 = 20.00; landed value 220.00 / 5
        $this->assertSame('44.0000', $result['b']);
    }

    public function test_rounding_remainder_lands_on_the_last_line_not_split_evenly(): void
    {
        $result = $this->allocator->allocate([
            'a' => ['net' => '100.00', 'quantity' => '1'],
            'b' => ['net' => '100.00', 'quantity' => '1'],
            'c' => ['net' => '100.00', 'quantity' => '1'],
        ], '100.00');

        // Naive equal thirds would be 33.33/33.33/33.33 = 99.99, losing a
        // cent. a and b each round to 33.33; c absorbs the remainder: 33.34.
        $this->assertSame('133.3300', $result['a']);
        $this->assertSame('133.3300', $result['b']);
        $this->assertSame('133.3400', $result['c']);
    }

    public function test_zero_quantity_line_gets_zero_landed_cost_instead_of_dividing_by_zero(): void
    {
        $result = $this->allocator->allocate([
            'a' => ['net' => '100.00', 'quantity' => '0'],
        ], '50.00');

        $this->assertSame('0.0000', $result['a']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/Services/Inventory/LandedCostAllocatorTest.php`
Expected: FAIL with "Class App\Services\Inventory\LandedCostAllocator not found"

- [ ] **Step 3: Write the implementation**

Create `app/Services/Inventory/LandedCostAllocator.php`:

```php
<?php

namespace App\Services\Inventory;

use App\Support\Bcmath;

/**
 * Distributes total import costs (freight, forwarder, customs duty — no
 * VAT) across a purchase invoice's stock lines, proportional to each
 * line's net value. Pure and stateless: works equally on an in-memory
 * draft (Livewire form preview) and on persisted lines (confirm-time
 * stock receipt) — both callers build the same small array shape and
 * never store a separately-computed landed cost anywhere.
 */
class LandedCostAllocator
{
    private const NET_SCALE = 2;

    private const COST_SCALE = 4;

    /**
     * @param  array<string, array{net: string, quantity: string}>  $stockLines
     * @return array<string, string>
     */
    public function allocate(array $stockLines, string $totalImportCosts): array
    {
        if ($stockLines === []) {
            return [];
        }

        $totalNet = array_reduce(
            $stockLines,
            fn (string $carry, array $line) => bcadd($carry, $line['net'], self::NET_SCALE),
            '0.00'
        );

        if (bccomp($totalImportCosts, '0', self::NET_SCALE) <= 0 || bccomp($totalNet, '0', self::NET_SCALE) <= 0) {
            return array_map(
                fn (array $line) => $this->unitCost($line['net'], $line['quantity']),
                $stockLines
            );
        }

        $keys = array_keys($stockLines);
        $lastKey = $keys[count($keys) - 1];
        $allocated = '0.00';
        $result = [];

        foreach ($stockLines as $key => $line) {
            if ($key === $lastKey) {
                $share = bcsub($totalImportCosts, $allocated, self::NET_SCALE);
            } else {
                $share = Bcmath::roundHalfUp(
                    bcdiv(bcmul($line['net'], $totalImportCosts, self::NET_SCALE + 10), $totalNet, self::NET_SCALE + 10),
                    self::NET_SCALE
                );
                $allocated = bcadd($allocated, $share, self::NET_SCALE);
            }

            $landedValue = bcadd($line['net'], $share, self::NET_SCALE);
            $result[$key] = $this->unitCost($landedValue, $line['quantity']);
        }

        return $result;
    }

    private function unitCost(string $value, string $quantity): string
    {
        if (bccomp($quantity, '0', 3) <= 0) {
            return '0.0000';
        }

        return Bcmath::roundHalfUp(bcdiv($value, $quantity, self::COST_SCALE + 10), self::COST_SCALE);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Services/Inventory/LandedCostAllocatorTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Inventory/LandedCostAllocator.php tests/Unit/Services/Inventory/LandedCostAllocatorTest.php
git commit -m "Add LandedCostAllocator for proportional import-cost distribution"
```

---

### Task 3: Wire landed cost into `PurchaseInvoiceService::confirm()`

**Files:**
- Modify: `app/Services/Invoicing/PurchaseInvoiceService.php`
- Test: `tests/Feature/PurchaseInvoiceImportLandedCostTest.php`

**Interfaces:**
- Consumes: `LandedCostAllocator::allocate()` from Task 2; `PurchaseInvoice::importCosts`/`tariffLines` relations and `is_import` column from Task 1; existing `PurchaseInvoiceLine::lineTotal()`, `PurchaseInvoiceService::movesStock()` (already private in this class).
- Produces: no new public API — `confirm()`'s behavior changes only when `$invoice->is_import` is true.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PurchaseInvoiceImportLandedCostTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Invoicing\PurchaseInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoiceImportLandedCostTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseInvoiceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PurchaseInvoiceService::class);
    }

    // Same helper as PurchaseInvoiceServiceTest — CompanyObserver already
    // seeds the full chart, firstOrCreate() keeps this idempotent.
    private function seedAccounts(Company $company): void
    {
        foreach ([
            ['code' => '130', 'name' => 'Input VAT'],
            ['code' => '220', 'name' => 'AP'],
            ['code' => '660', 'name' => 'Inventory Asset'],
        ] as $account) {
            Account::firstOrCreate(['company_id' => $company->id, 'code' => $account['code']], $account);
        }
    }

    public function test_import_invoice_receives_stock_at_landed_cost_but_books_the_raw_invoice_amount(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $itemA = Item::factory()->for($company)->create(['vat_rate' => '18.00']);
        $itemB = Item::factory()->for($company)->create(['vat_rate' => '18.00']);
        $user = User::factory()->create();

        $invoice = PurchaseInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'warehouse_id' => $warehouse->id,
            'invoice_date' => '2026-03-01',
            'is_import' => true,
        ]);
        $lineA = $invoice->lines()->create(['item_id' => $itemA->id, 'description' => 'A', 'quantity' => '10', 'unit_price' => '50.00', 'vat_rate' => '18.00']);
        $lineB = $invoice->lines()->create(['item_id' => $itemB->id, 'description' => 'B', 'quantity' => '5', 'unit_price' => '40.00', 'vat_rate' => '18.00']);

        $invoice->importCosts()->create(['payee_name' => 'Шпедитер', 'base_amount' => '70.00', 'vat_amount' => '12.60', 'sort_order' => 0]);
        $invoice->tariffLines()->create(['tariff_code' => '12345678', 'customs_duty' => '30.00', 'vat_amount' => '0', 'sort_order' => 0]);

        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        // Total net 700.00 (500 + 200), total import costs 100.00 (70 + 30).
        // A's share = round(100*500/700, 2) = 71.43; landed value 571.43 / 10.
        $levelA = StockLevel::where('item_id', $itemA->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame('57.1430', (string) $levelA->average_cost);
        $this->assertSame('10.000', (string) $levelA->quantity_on_hand);

        // B (last) absorbs the remainder: 100.00 - 71.43 = 28.57; landed value 228.57 / 5.
        $levelB = StockLevel::where('item_id', $itemB->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame('45.7140', (string) $levelB->average_cost);

        $movementA = StockMovement::where('item_id', $itemA->id)->where('type', 'receipt')->first();
        $this->assertSame('57.1430', (string) $movementA->unit_cost);

        // The journal entry still books only the raw invoice net amount —
        // import costs are deliberately not posted to the ledger yet.
        $entry = $confirmed->journalEntry()->with('lines.account')->first();
        $inventoryAsset = $entry->lines->firstWhere('account.code', '660');
        $this->assertSame('700.00', (string) $inventoryAsset->debit);
    }

    public function test_non_import_invoice_is_completely_unaffected(): void
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
        $invoice->lines()->create(['item_id' => $item->id, 'description' => 'A', 'quantity' => '10', 'unit_price' => '50.00', 'vat_rate' => '18.00']);

        $this->service->confirm($invoice->fresh(), $user->id);

        $level = StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame('50.0000', (string) $level->average_cost);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/PurchaseInvoiceImportLandedCostTest.php`
Expected: FAIL on the first test — `average_cost` comes back `'50.0000'` (raw price), not `'57.1430'`. The second test already passes (nothing to change there).

- [ ] **Step 3: Modify `PurchaseInvoiceService::confirm()`**

In `app/Services/Invoicing/PurchaseInvoiceService.php`, add the import:

```php
use App\Services\Inventory\LandedCostAllocator;
```

Change the `confirm()` transaction body. Replace:

```php
        return DB::transaction(function () use ($invoice, $userId) {
            $invoice->loadMissing(['lines.account', 'lines.item', 'partner', 'company']);

            $vatRegistered = $invoice->company->is_vat_registered;
            $vatTotal = '0.00';
            $debitsByAccountId = [];

            foreach ($invoice->lines as $line) {
                $lineNet = $line->lineTotal();
                $lineVat = $vatRegistered ? $line->vatAmount() : '0.00';
                $deductible = $vatRegistered && $line->vat_deductible;

                if ($this->movesStock($line)) {
                    $movement = $this->stockMovementService->receipt(
                        $line->item,
                        $invoice->warehouse,
                        (string) $line->quantity,
                        // Не `unit_price`: кај ставка внесена со бруто цена
                        // заокружената нето цена веќе не ја дава основицата,
                        // па залихата би примила 30,48 таму каде главната
                        // книга задолжува 30,51.
                        $line->effectiveUnitPrice(),
                        $invoice->invoice_date->toDateString(),
                        $userId
                    );
```

with:

```php
        return DB::transaction(function () use ($invoice, $userId) {
            $invoice->loadMissing(['lines.account', 'lines.item', 'partner', 'company', 'importCosts', 'tariffLines']);

            $vatRegistered = $invoice->company->is_vat_registered;
            $vatTotal = '0.00';
            $debitsByAccountId = [];
            $landedCosts = $invoice->is_import ? $this->landedCosts($invoice) : [];

            foreach ($invoice->lines as $line) {
                $lineNet = $line->lineTotal();
                $lineVat = $vatRegistered ? $line->vatAmount() : '0.00';
                $deductible = $vatRegistered && $line->vat_deductible;

                if ($this->movesStock($line)) {
                    $movement = $this->stockMovementService->receipt(
                        $line->item,
                        $invoice->warehouse,
                        (string) $line->quantity,
                        // На увозна фактура, залихата прима landed (магацинска)
                        // цена наместо чистата фактурна — распределени увозни
                        // трошоци/царина. Книжењето подолу останува на
                        // фактурниот износ, намерно (видете ја спецификацијата).
                        //
                        // Не `unit_price`: кај ставка внесена со бруто цена
                        // заокружената нето цена веќе не ја дава основицата,
                        // па залихата би примила 30,48 таму каде главната
                        // книга задолжува 30,51.
                        $landedCosts[$line->id] ?? $line->effectiveUnitPrice(),
                        $invoice->invoice_date->toDateString(),
                        $userId
                    );
```

Add two private helper methods near `movesStock()`:

```php
    /**
     * @return array<int, string> purchase_invoice_line id => landed unit cost
     */
    private function landedCosts(PurchaseInvoice $invoice): array
    {
        $stockLines = [];

        foreach ($invoice->lines as $line) {
            if ($this->movesStock($line)) {
                $stockLines[$line->id] = ['net' => $line->lineTotal(), 'quantity' => (string) $line->quantity];
            }
        }

        $costsTotal = $invoice->importCosts->reduce(
            fn (?string $carry, $cost) => bcadd($carry ?? '0.00', (string) $cost->base_amount, 2),
            '0.00'
        );
        $dutyTotal = $invoice->tariffLines->reduce(
            fn (?string $carry, $tariff) => bcadd($carry ?? '0.00', (string) $tariff->customs_duty, 2),
            '0.00'
        );

        return app(LandedCostAllocator::class)->allocate($stockLines, bcadd($costsTotal, $dutyTotal, 2));
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/PurchaseInvoiceImportLandedCostTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Run the full existing purchase-invoice suite to check for regressions**

Run: `php artisan test tests/Unit/PurchaseInvoiceServiceTest.php tests/Unit/PurchaseInvoiceTest.php tests/Feature/PurchaseInvoiceFormTest.php`
Expected: PASS, unchanged — `is_import` defaults to `false` so `landedCosts()` is never called for any of these.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Invoicing/PurchaseInvoiceService.php tests/Feature/PurchaseInvoiceImportLandedCostTest.php
git commit -m "Receive import stock at landed cost instead of raw invoice price"
```

---

### Task 4: `PurchaseInvoiceForm` — import fields, cost/tariff line editing, live preview

**Files:**
- Modify: `app/Livewire/Invoicing/PurchaseInvoiceForm.php`
- Test: `tests/Feature/PurchaseInvoiceImportFormTest.php`

**Interfaces:**
- Consumes: `LandedCostAllocator::allocate()` (Task 2), `ExchangeRateService::getRate(string $currencyCode, Carbon $date): float` (existing), `PurchaseInvoice::importCosts`/`tariffLines` (Task 1).
- Produces: public properties `isImport`, `customsDeclarationNumber`, `importDate`, `importCurrencyCode`, `importExchangeRate`, `importCosts` (array), `tariffLines` (array), `importTab` (string, `'costs'|'tariffs'`), `showLandedPreview` (bool); actions `addImportCost()`, `removeImportCost(int)`, `addTariffLine()`, `removeTariffLine(int)`, `fetchImportRate()`, `revealLandedPreview()`; `render()` passes `landedUnitCosts`, `importCostsBase`, `tariffDutyTotal`, `totalForAllocation` to the view — all consumed by Task 5's Blade file.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/PurchaseInvoiceImportFormTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoiceImportFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function actingAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);

        return $user;
    }

    public function test_import_checkbox_is_hidden_until_a_line_has_a_stock_item(): void
    {
        $company = Company::factory()->create();
        $this->actingAdmin();
        Partner::factory()->for($company)->create();

        Livewire::test(\App\Livewire\Invoicing\PurchaseInvoiceForm::class, ['company' => $company])
            ->assertDontSee('Фактура од увоз');
    }

    public function test_checking_import_reveals_the_import_section_and_fields_can_be_edited(): void
    {
        $company = Company::factory()->create();
        $this->actingAdmin();
        Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();

        Livewire::test(\App\Livewire\Invoicing\PurchaseInvoiceForm::class, ['company' => $company])
            ->set('warehouseId', (string) $warehouse->id)
            ->call('selectItem', 0, (string) $item->id)
            ->assertSee('Фактура од увоз')
            ->set('isImport', true)
            ->assertSee('Увоз')
            ->call('addImportCost')
            ->assertCount('importCosts', 1)
            ->call('addTariffLine')
            ->assertCount('tariffLines', 1)
            ->set('importCosts.0.payee_name', 'Шпедитер ДОО')
            ->set('importCosts.0.base_amount', '70.00')
            ->set('tariffLines.0.tariff_code', '12345678')
            ->set('tariffLines.0.customs_duty', '30.00')
            ->call('removeTariffLine', 0)
            ->assertCount('tariffLines', 0);
    }

    public function test_fetch_import_rate_fills_the_exchange_rate_field_from_nbrm(): void
    {
        Http::fake([
            'nbrm.mk/*' => Http::response([
                ['oznaka' => 'EUR', 'sreden' => 61.6917, 'nomin' => 1, 'datum' => '2026-09-15T00:00:00'],
            ], 200),
        ]);

        $company = Company::factory()->create();
        $this->actingAdmin();
        Partner::factory()->for($company)->create();

        Livewire::test(\App\Livewire\Invoicing\PurchaseInvoiceForm::class, ['company' => $company])
            ->set('isImport', true)
            ->set('importDate', '2026-09-15')
            ->set('importCurrencyCode', 'EUR')
            ->call('fetchImportRate')
            ->assertSet('importExchangeRate', '61.6917');
    }

    public function test_saving_an_import_invoice_persists_cost_and_tariff_rows(): void
    {
        $company = Company::factory()->create();
        $this->actingAdmin();
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();

        Livewire::test(\App\Livewire\Invoicing\PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'INV-1')
            ->set('invoiceDate', '2026-09-15')
            ->set('dueDate', '2026-09-30')
            ->set('warehouseId', (string) $warehouse->id)
            ->call('selectItem', 0, (string) $item->id)
            ->set('lines.0.quantity', '10')
            ->set('lines.0.unit_price', '50.00')
            ->set('isImport', true)
            ->set('customsDeclarationNumber', '26MKIM0000000')
            ->call('addImportCost')
            ->set('importCosts.0.payee_name', 'Шпедитер ДОО')
            ->set('importCosts.0.base_amount', '70.00')
            ->call('save');

        $invoice = \App\Models\PurchaseInvoice::where('company_id', $company->id)->firstOrFail();

        $this->assertTrue((bool) $invoice->is_import);
        $this->assertSame('26MKIM0000000', $invoice->customs_declaration_number);
        $this->assertSame(1, $invoice->importCosts()->count());
        $this->assertSame('Шпедитер ДОО', $invoice->importCosts()->first()->payee_name);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/PurchaseInvoiceImportFormTest.php`
Expected: FAIL — `isImport` property doesn't exist yet, checkbox not rendered.

- [ ] **Step 3: Add the new properties and `mount()` population**

In `app/Livewire/Invoicing/PurchaseInvoiceForm.php`, add imports:

```php
use App\Services\ExchangeRateService;
use App\Services\Inventory\LandedCostAllocator;
```

Add public properties (near the existing `public array $lines = [];`):

```php
    public bool $isImport = false;

    public string $customsDeclarationNumber = '';

    public string $importDate = '';

    public string $importCurrencyCode = 'EUR';

    public string $importExchangeRate = '';

    public array $importCosts = [];

    public array $tariffLines = [];

    public string $importTab = 'costs';

    public bool $showLandedPreview = false;
```

In `mount()`, inside the `if ($purchaseInvoice)` branch, after the existing `$this->lines = ...` assignment, add:

```php
            $this->isImport = (bool) $purchaseInvoice->is_import;
            $this->customsDeclarationNumber = (string) $purchaseInvoice->customs_declaration_number;
            $this->importDate = $purchaseInvoice->import_date?->toDateString() ?? '';
            $this->importCurrencyCode = $purchaseInvoice->import_currency_code ?? 'EUR';
            $this->importExchangeRate = $purchaseInvoice->import_exchange_rate === null ? '' : (string) $purchaseInvoice->import_exchange_rate;
            $this->importCosts = $purchaseInvoice->importCosts->map(fn ($cost) => [
                'payee_name' => $cost->payee_name,
                'reference_number' => (string) $cost->reference_number,
                'foreign_amount' => $cost->foreign_amount === null ? '' : (string) $cost->foreign_amount,
                'base_amount' => (string) $cost->base_amount,
                'vat_amount' => (string) $cost->vat_amount,
            ])->toArray();
            $this->tariffLines = $purchaseInvoice->tariffLines->map(fn ($tariff) => [
                'tariff_code' => $tariff->tariff_code,
                'foreign_amount' => $tariff->foreign_amount === null ? '' : (string) $tariff->foreign_amount,
                'customs_duty' => (string) $tariff->customs_duty,
                'vat_amount' => (string) $tariff->vat_amount,
            ])->toArray();
```

- [ ] **Step 4: Add the row-editing and rate-fetch actions**

Add these public methods (near `addLine()`/`removeLine()`):

```php
    public function addImportCost(): void
    {
        $this->importCosts[] = ['payee_name' => '', 'reference_number' => '', 'foreign_amount' => '', 'base_amount' => '0', 'vat_amount' => '0'];
    }

    public function removeImportCost(int $index): void
    {
        unset($this->importCosts[$index]);
        $this->importCosts = array_values($this->importCosts);
    }

    public function addTariffLine(): void
    {
        $this->tariffLines[] = ['tariff_code' => '', 'foreign_amount' => '', 'customs_duty' => '0', 'vat_amount' => '0'];
    }

    public function removeTariffLine(int $index): void
    {
        unset($this->tariffLines[$index]);
        $this->tariffLines = array_values($this->tariffLines);
    }

    public function fetchImportRate(): void
    {
        $this->resetErrorBag('importExchangeRate');

        if ($this->importCurrencyCode === '' || $this->importDate === '') {
            $this->addError('importExchangeRate', 'Внеси датум на увоз и валута пред да го повлечеш курсот.');

            return;
        }

        try {
            $rate = app(ExchangeRateService::class)->getRate($this->importCurrencyCode, Carbon::parse($this->importDate));
        } catch (\Throwable $e) {
            $this->addError('importExchangeRate', 'Не можев да го повлечам курсот — внеси го рачно.');

            return;
        }

        $this->importExchangeRate = (string) $rate;
    }

    public function revealLandedPreview(): void
    {
        $this->showLandedPreview = true;
    }

    public function updatedIsImport(bool $value): void
    {
        if (! $value) {
            $this->showLandedPreview = false;
        }
    }
```

(`Carbon` is already imported in this file.)

- [ ] **Step 5: Compute the live preview in `render()`**

In `render()`, after the existing `foreach ($this->lines as $index => $line)` loop that builds `$rows`/`$net`/`$vat`, add:

```php
        $stockLines = [];
        foreach ($rows as $index => $row) {
            if ($row['is_stock']) {
                $stockLines[(string) $index] = ['net' => $row['net'], 'quantity' => (string) ($this->lines[$index]['quantity'] ?? '0')];
            }
        }

        $importCostsBase = collect($this->importCosts)->reduce(
            fn (?string $carry, array $cost) => bcadd($carry ?? '0.00', $cost['base_amount'] !== '' ? $cost['base_amount'] : '0', 2),
            '0.00'
        );
        $tariffDutyTotal = collect($this->tariffLines)->reduce(
            fn (?string $carry, array $tariff) => bcadd($carry ?? '0.00', $tariff['customs_duty'] !== '' ? $tariff['customs_duty'] : '0', 2),
            '0.00'
        );
        $totalForAllocation = bcadd($importCostsBase, $tariffDutyTotal, 2);

        $landedUnitCosts = $this->isImport
            ? app(LandedCostAllocator::class)->allocate($stockLines, $totalForAllocation)
            : [];
```

Add the four new keys to the `view()` data array (next to `'rows' => $rows,`):

```php
            'landedUnitCosts' => $landedUnitCosts,
            'importCostsBase' => $importCostsBase,
            'tariffDutyTotal' => $tariffDutyTotal,
            'totalForAllocation' => $totalForAllocation,
```

- [ ] **Step 6: Add validation and persistence for the new fields**

In `persist()`, add to the `$this->validate([...])` array (alongside the existing `'orderNumber' => ...` line):

```php
            'isImport' => 'boolean',
            'customsDeclarationNumber' => 'nullable|string|max:255',
            'importDate' => 'nullable|date',
            'importCurrencyCode' => 'nullable|string|size:3',
            'importExchangeRate' => 'nullable|numeric|min:0',
            'importCosts.*.payee_name' => 'nullable|string|max:255',
            'importCosts.*.reference_number' => 'nullable|string|max:255',
            'importCosts.*.foreign_amount' => 'nullable|numeric|min:0',
            'importCosts.*.base_amount' => 'nullable|numeric|min:0',
            'importCosts.*.vat_amount' => 'nullable|numeric|min:0',
            'tariffLines.*.tariff_code' => 'nullable|string|max:255',
            'tariffLines.*.foreign_amount' => 'nullable|numeric|min:0',
            'tariffLines.*.customs_duty' => 'nullable|numeric|min:0',
            'tariffLines.*.vat_amount' => 'nullable|numeric|min:0',
```

In the `DB::transaction()` block of `persist()`, right after the existing `$invoice->order_number = $this->orderNumber ?: null;` line, add:

```php
            $invoice->is_import = $this->isImport;
            $invoice->customs_declaration_number = $this->customsDeclarationNumber ?: null;
            $invoice->import_date = $this->importDate ?: null;
            $invoice->import_currency_code = $this->isImport ? ($this->importCurrencyCode ?: null) : null;
            $invoice->import_exchange_rate = $this->importExchangeRate !== '' ? $this->importExchangeRate : null;
```

Right after the existing `foreach ($this->lines as $line) { ... }` block that recreates invoice lines (after its closing `}`, still inside the transaction, before `$this->purchaseInvoice = $invoice;`), add:

```php
            $invoice->importCosts()->delete();
            $invoice->tariffLines()->delete();

            if ($this->isImport) {
                foreach ($this->importCosts as $order => $cost) {
                    if (trim((string) ($cost['payee_name'] ?? '')) === '' && (float) ($cost['base_amount'] ?? 0) === 0.0) {
                        continue;
                    }

                    $invoice->importCosts()->create([
                        'payee_name' => $cost['payee_name'] ?: '—',
                        'reference_number' => $cost['reference_number'] ?: null,
                        'foreign_amount' => filled($cost['foreign_amount'] ?? null) ? $cost['foreign_amount'] : null,
                        'base_amount' => $cost['base_amount'] ?: '0',
                        'vat_amount' => $cost['vat_amount'] ?: '0',
                        'sort_order' => $order,
                    ]);
                }

                foreach ($this->tariffLines as $order => $tariff) {
                    if (trim((string) ($tariff['tariff_code'] ?? '')) === '' && (float) ($tariff['customs_duty'] ?? 0) === 0.0) {
                        continue;
                    }

                    $invoice->tariffLines()->create([
                        'tariff_code' => $tariff['tariff_code'] ?: '—',
                        'foreign_amount' => filled($tariff['foreign_amount'] ?? null) ? $tariff['foreign_amount'] : null,
                        'customs_duty' => $tariff['customs_duty'] ?: '0',
                        'vat_amount' => $tariff['vat_amount'] ?: '0',
                        'sort_order' => $order,
                    ]);
                }
            }
```

- [ ] **Step 7: Run the tests — expect a view error, that's expected at this point**

Run: `php artisan test tests/Feature/PurchaseInvoiceImportFormTest.php`
Expected: the `assertDontSee`/`assertSee` tests may error because the Blade view doesn't reference `isImport` yet (that's fine — `assertDontSee('Фактура од увоз')` trivially passes since the view never prints it; `assertSee('Увоз')` will FAIL). The `fetchImportRate` and `save`-persistence tests should already PASS since they don't depend on the view showing anything. This is expected — Task 5 makes the remaining assertions pass.

Expected concretely: `test_import_checkbox_is_hidden_until_a_line_has_a_stock_item` PASS, `test_checking_import_reveals_the_import_section_and_fields_can_be_edited` FAIL on `assertSee('Фактура од увоз')`, `test_fetch_import_rate_fills_the_exchange_rate_field_from_nbrm` PASS, `test_saving_an_import_invoice_persists_cost_and_tariff_rows` PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Invoicing/PurchaseInvoiceForm.php tests/Feature/PurchaseInvoiceImportFormTest.php
git commit -m "Add import fields, cost/tariff line editing and live landed-cost preview to PurchaseInvoiceForm"
```

---

### Task 5: Blade — the "Увоз" section

**Files:**
- Modify: `resources/views/livewire/invoicing/purchase-invoice-form.blade.php`
- Test: `tests/Feature/PurchaseInvoiceImportFormTest.php` (from Task 4 — no new file, this task makes its remaining assertions pass)

**Interfaces:**
- Consumes: every property/method from Task 4 (`isImport`, `customsDeclarationNumber`, `importDate`, `importCurrencyCode`, `importExchangeRate`, `importCosts`, `tariffLines`, `importTab`, `showLandedPreview`, `addImportCost`, `removeImportCost`, `addTariffLine`, `removeTariffLine`, `fetchImportRate`, `revealLandedPreview`) and the four `render()` view keys (`landedUnitCosts`, `importCostsBase`, `tariffDutyTotal`, `totalForAllocation`), plus the existing `$requiresWarehouse`, `$lines`, `$rows` already in this view.

- [ ] **Step 1: Insert the "Увоз" card**

In `resources/views/livewire/invoicing/purchase-invoice-form.blade.php`, insert this block right after the closing `</x-card>` of the first details card (line 136, the one containing `warehouseId`) and before the `<x-card padding="p-0" class="overflow-hidden">` that starts the Ставки (line items) table:

```blade
        @if ($requiresWarehouse)
            <x-card>
                <label class="flex items-start gap-3 rounded-lg border border-sand bg-paper-warm px-4 py-3 cursor-pointer">
                    <input type="checkbox" wire:model.live="isImport" class="mt-0.5 rounded border-gray-300 text-brand focus:ring-brand">
                    <span>
                        <span class="block font-semibold text-gray-800">Фактура од увоз</span>
                        <span class="block text-xs text-stone">Штиклирано — се отвора секторот подолу за увозни трошоци и царина, кои ја зголемуваат магацинската вредност над фактурната.</span>
                    </span>
                </label>

                @if ($isImport)
                    <div class="mt-4 rounded-lg border border-orange-200 border-l-4 border-l-brand bg-orange-50/50 px-4 py-4">
                        <div class="flex items-center gap-2 mb-3">
                            <h3 class="font-semibold text-gray-800">Увоз</h3>
                            <span class="bg-brand text-white text-[10px] font-bold px-2 py-0.5 rounded-full uppercase tracking-wide">ЕЦД</span>
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-1">
                            <div>
                                <x-input-label value="ЕЦД број" />
                                <x-text-input wire:model="customsDeclarationNumber" class="w-full text-sm" />
                            </div>
                            <div>
                                <x-input-label value="Датум на увоз" />
                                <x-text-input type="date" wire:model.live="importDate" class="w-full text-sm" />
                            </div>
                            <div>
                                <x-input-label value="Валута" />
                                <select wire:model.live="importCurrencyCode" class="w-full border-gray-300 focus:border-brand focus:ring-brand rounded-lg text-sm">
                                    <option value="EUR">EUR</option>
                                    <option value="USD">USD</option>
                                    <option value="GBP">GBP</option>
                                    <option value="CHF">CHF</option>
                                </select>
                            </div>
                            <div>
                                <x-input-label value="Курс (НБРМ на датумот)" />
                                <div class="flex gap-2">
                                    <x-text-input wire:model="importExchangeRate" class="w-full text-sm" />
                                    <x-secondary-button type="button" wire:click="fetchImportRate" class="shrink-0 whitespace-nowrap">↻ НБРМ</x-secondary-button>
                                </div>
                                @error('importExchangeRate') <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div class="flex gap-1 border-b border-orange-200 mt-4 mb-3 text-sm">
                            <button type="button" wire:click="$set('importTab', 'costs')"
                                class="px-3 py-1.5 {{ $importTab === 'costs' ? 'text-brand border-b-2 border-brand font-semibold' : 'text-stone' }}">Увозни трошоци</button>
                            <button type="button" wire:click="$set('importTab', 'tariffs')"
                                class="px-3 py-1.5 {{ $importTab === 'tariffs' ? 'text-brand border-b-2 border-brand font-semibold' : 'text-stone' }}">Распределба по царински тарифи</button>
                        </div>

                        @if ($importTab === 'costs')
                            <div class="overflow-x-auto">
                                <table class="min-w-[640px] w-full text-xs">
                                    <thead>
                                        <tr class="text-left text-stone bg-white/60">
                                            <th class="py-1 px-2">Добавувач / шпедитер</th>
                                            <th class="py-1 px-2">Фактура</th>
                                            <th class="py-1 px-2 text-right">Девизи</th>
                                            <th class="py-1 px-2 text-right">Основица</th>
                                            <th class="py-1 px-2 text-right">ДДВ</th>
                                            <th class="py-1 px-2"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($importCosts as $index => $cost)
                                            <tr wire:key="import-cost-{{ $index }}" class="border-t border-orange-100">
                                                <td class="py-1 px-2"><x-text-input wire:model="importCosts.{{ $index }}.payee_name" class="w-full text-xs py-1" /></td>
                                                <td class="py-1 px-2"><x-text-input wire:model="importCosts.{{ $index }}.reference_number" class="w-full text-xs py-1" /></td>
                                                <td class="py-1 px-2"><x-text-input wire:model="importCosts.{{ $index }}.foreign_amount" class="w-full text-xs py-1 text-right" /></td>
                                                <td class="py-1 px-2"><x-text-input wire:model="importCosts.{{ $index }}.base_amount" class="w-full text-xs py-1 text-right" /></td>
                                                <td class="py-1 px-2"><x-text-input wire:model="importCosts.{{ $index }}.vat_amount" class="w-full text-xs py-1 text-right" /></td>
                                                <td class="py-1 px-2 text-center">
                                                    <button type="button" wire:click="removeImportCost({{ $index }})" class="text-stone hover:text-red-600">✕</button>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" wire:click="addImportCost" class="mt-2 text-xs text-brand font-semibold hover:underline">+ Додади трошок</button>
                        @else
                            <div class="overflow-x-auto">
                                <table class="min-w-[560px] w-full text-xs">
                                    <thead>
                                        <tr class="text-left text-stone bg-white/60">
                                            <th class="py-1 px-2">Царинска тарифа</th>
                                            <th class="py-1 px-2 text-right">Девизи</th>
                                            <th class="py-1 px-2 text-right">Царина</th>
                                            <th class="py-1 px-2 text-right">ДДВ</th>
                                            <th class="py-1 px-2"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($tariffLines as $index => $tariff)
                                            <tr wire:key="tariff-{{ $index }}" class="border-t border-orange-100">
                                                <td class="py-1 px-2"><x-text-input wire:model="tariffLines.{{ $index }}.tariff_code" class="w-full text-xs py-1" /></td>
                                                <td class="py-1 px-2"><x-text-input wire:model="tariffLines.{{ $index }}.foreign_amount" class="w-full text-xs py-1 text-right" /></td>
                                                <td class="py-1 px-2"><x-text-input wire:model="tariffLines.{{ $index }}.customs_duty" class="w-full text-xs py-1 text-right" /></td>
                                                <td class="py-1 px-2"><x-text-input wire:model="tariffLines.{{ $index }}.vat_amount" class="w-full text-xs py-1 text-right" /></td>
                                                <td class="py-1 px-2 text-center">
                                                    <button type="button" wire:click="removeTariffLine({{ $index }})" class="text-stone hover:text-red-600">✕</button>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" wire:click="addTariffLine" class="mt-2 text-xs text-brand font-semibold hover:underline">+ Додади тарифа</button>
                        @endif

                        <div class="flex flex-wrap justify-end gap-4 mt-4 pt-3 border-t border-dashed border-orange-200 text-sm">
                            <div class="text-right">
                                <div class="text-[10px] uppercase text-stone">Трошоци</div>
                                <div class="font-semibold tabular-nums">{{ \App\Support\Format::money($importCostsBase, '') }}</div>
                            </div>
                            <div class="text-right">
                                <div class="text-[10px] uppercase text-stone">Царина</div>
                                <div class="font-semibold tabular-nums">{{ \App\Support\Format::money($tariffDutyTotal, '') }}</div>
                            </div>
                            <div class="text-right">
                                <div class="text-[10px] uppercase text-stone">За распределба</div>
                                <div class="font-bold text-brand text-base tabular-nums">{{ \App\Support\Format::money($totalForAllocation, '') }} ден.</div>
                            </div>
                        </div>

                        <x-primary-button type="button" wire:click="revealLandedPreview" class="w-full mt-4 justify-center">
                            Пресметај магацинска вредност →
                        </x-primary-button>
                    </div>

                    @if ($showLandedPreview)
                        <div class="mt-4">
                            <h3 class="font-semibold text-gray-700 mb-2 text-sm">Преглед по ставка</h3>
                            <div class="overflow-x-auto rounded-lg border border-sand">
                                <table class="min-w-[560px] w-full text-xs">
                                    <thead>
                                        <tr class="text-left text-stone bg-paper-warm">
                                            <th class="py-1 px-2">Артикл</th>
                                            <th class="py-1 px-2 text-right">Кол.</th>
                                            <th class="py-1 px-2 text-right">Набавна (фактурна)</th>
                                            <th class="py-1 px-2 text-right">Увоз (удел)</th>
                                            <th class="py-1 px-2 text-right">Магацинска вредност</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($lines as $index => $line)
                                            @continue (! ($rows[$index]['is_stock'] ?? false))
                                            @php
                                                $qty = (string) ($line['quantity'] !== '' ? $line['quantity'] : '0');
                                                $landedUnit = $landedUnitCosts[(string) $index] ?? null;
                                                $landedValue = $landedUnit !== null ? bcmul($landedUnit, $qty, 2) : $rows[$index]['net'];
                                                $share = bcsub($landedValue, $rows[$index]['net'], 2);
                                            @endphp
                                            <tr wire:key="landed-{{ $index }}" class="border-t border-sand/70">
                                                <td class="py-1 px-2">{{ $line['description'] ?: '—' }}</td>
                                                <td class="py-1 px-2 text-right tabular-nums">{{ $qty }}</td>
                                                <td class="py-1 px-2 text-right tabular-nums">{{ \App\Support\Format::money($rows[$index]['net'], '') }}</td>
                                                <td class="py-1 px-2 text-right tabular-nums">{{ \App\Support\Format::money($share, '') }}</td>
                                                <td class="py-1 px-2 text-right tabular-nums font-semibold text-brand">{{ \App\Support\Format::money($landedValue, '') }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                @endif
            </x-card>
        @endif

```

- [ ] **Step 2: Run the Task 4 test file to verify everything passes now**

Run: `php artisan test tests/Feature/PurchaseInvoiceImportFormTest.php`
Expected: PASS (4 tests)

- [ ] **Step 3: Run the confirm-time feature test again (end-to-end sanity)**

Run: `php artisan test tests/Feature/PurchaseInvoiceImportLandedCostTest.php tests/Unit/Services/Inventory/LandedCostAllocatorTest.php tests/Unit/Models/PurchaseInvoiceImportSupportTest.php`
Expected: PASS (all)

- [ ] **Step 4: Run the full test suite**

Run: `php artisan test`
Expected: PASS, same count as before this plan plus the ~13 new tests added across Tasks 1-5. No pre-existing test should have changed assertions.

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/invoicing/purchase-invoice-form.blade.php
git commit -m "Add the Увоз section UI to the purchase invoice form"
```

---

## Self-Review Notes

- **Spec coverage:** schema (Task 1) ✓, allocator/calculation rules incl. VAT exclusion and remainder-to-last-line (Task 2) ✓, confirm()-time wiring with GL left untouched (Task 3) ✓, НБРМ-by-import-date rate (Task 4 `fetchImportRate`) ✓, checkbox gated on having a stock line (Task 4/5, `$requiresWarehouse`) ✓, two tabs + totals + "Пресметај" reveal + per-line preview UI (Task 5) ✓. The spec's "known limitations" section needs no task — it's explicitly out of scope.
- **Type consistency checked:** `LandedCostAllocator::allocate()` signature (`array<string, array{net, quantity}>, string`) is identical in Task 2's implementation, Task 3's `landedCosts()` caller (keyed by line id, cast to array key — PHP arrays auto-stringify int keys, matching `(string) $index` on the form side), and Task 4's `render()` caller (keyed by `(string) $index`). The Blade file in Task 5 reads `$landedUnitCosts[(string) $index]`, matching.
- **No placeholders:** every step has runnable code, not prose descriptions.
