# Phase 2: Inventory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build full warehouse-operations inventory management for tami-web-app: items, per-client warehouses, weighted-average-costed stock movements (receipt/issue/transfer/adjustment), camera barcode scanning, and the stock on hand / item movement card / valuation reports.

**Architecture:** Standard Laravel 13 + Livewire 3 feature module under `app/Livewire/Inventory/`, following the exact conventions established in Phase 1 (`app/Models/`, `app/Policies/` auto-discovered by Laravel's naming convention — no manual registration needed, confirmed by `AccountPolicy`/`PartnerPolicy`/`JournalEntryPolicy` already working without any `Gate::policy()` call — manual `company_id` tenancy scoping via `User::visibleCompanies()`, PHPUnit + `RefreshDatabase` tests). A single ledger-style table (`stock_movements`, mirroring `journal_entry_lines`) plus a maintained running-balance cache table (`stock_levels`), both driven through one plain PHP service class (`StockMovementService`) that is Livewire-independent and unit-testable on its own — mirroring how `LedgerCardQuery`/`TrialBalanceQuery` were kept separate from Livewire in Phase 1. Two further query-service classes (`StockLevelQuery`, `ItemMovementCardQuery`) power the three reports.

**Tech Stack:** Laravel 13.8, Livewire 3.6.4, PHP 8.3, MySQL (SQLite in tests), PHPUnit 12, `@zxing/browser` (new — first camera/barcode JS dependency in this codebase) via Vite/npm.

## Global Constraints

- PHP `^8.3`, Laravel `^13.8`, Livewire `^3.6.4` — match versions already in `composer.json`; the only new dependency this phase adds is `@zxing/browser` (+ its peer `@zxing/library`) in `package.json`, for camera barcode scanning.
- Role names are the plain strings `'admin'`, `'accountant'`, `'client'` — no enum/constants wrapper, consistent with Phase 1.
- Tenancy is scoped manually per-query via `$user->visibleCompanies()` — no Eloquent global scopes exist anywhere in this codebase; do not introduce one.
- Tests are PHPUnit (not Pest), class-based, `use RefreshDatabase;`, snake_case `test_*` methods, roles re-seeded per test class via `Role::findOrCreate(...)` in `setUp()`.
- Policies delegate to `$user->visibleCompanies()->whereKey($company_id)->exists()` for read access. **Departure from Phase 1:** per the approved spec (`docs/superpowers/specs/2026-07-19-phase2-inventory-design.md`), Clients have full read-write on Inventory (not read-only as in accounting) — so `create`/`update` policy checks include `'client'` in the allowed-roles list, unlike `AccountPolicy`/`JournalEntryPolicy`.
- **Stock movements are immutable once created** — there is no edit or delete UI, and `StockMovementPolicy` deliberately has no `update`/`delete` methods. This isn't explicitly stated in the spec but is the only sane implementation given weighted-average costing: reversing a movement would require un-averaging a cost that later movements may have already built on. Corrections happen by recording a new `adjustment` movement, consistent with the spec's adjustment description ("physical count correction", "damaged goods").
- **Weighted-average cost is tracked per item, per warehouse** — `stock_levels` has one row per `(item_id, warehouse_id)` pair, updated transactionally inside the same `DB::transaction()` as each `stock_movements` insert, using `lockForUpdate()` (same pattern as `JournalEntry`'s `entry_number` sequencing in Phase 1).
- **`stock_movements` has no `company_id` column** — scoped indirectly via `item_id`/`warehouse_id` (both of which do have `company_id`), per the approved spec. Policies check `$stockMovement->item->company_id`.
- **Adjustment quantity is signed** (can be negative), unlike `receipt`/`issue`/`transfer` quantities which are always positive with direction implied by `type`. This resolves an internal wording gap in the spec (the data-model bullet says "quantity always positive, direction implied by type" as a general statement, but the movement-logic section explicitly requires "a signed quantity delta" for adjustments) — signed adjustment quantity is the only way to satisfy both without introducing new `type` values the spec doesn't list.
- **The item movement card report is per item, per warehouse** (both required parameters, not just per item) — this matches the grain of `stock_levels` and `average_cost` itself (which is also per item per warehouse), so a card's running quantity reconciles exactly against `stock_levels.quantity_on_hand` for that pair. This is a refinement within the spec's stated purpose ("per-item transaction history"), not a scope change.
- Decimal precision: quantities are `decimal(15,3)` (supports fractional units like kg/liter), unit costs and average costs are `decimal(15,4)` (extra precision to limit rounding drift across many weighted-average recalculations), matching MKD currency handling elsewhere in the app. All arithmetic in `StockMovementService` uses PHP's `bcmath` functions (`bcadd`/`bcsub`/`bcmul`/`bcdiv`/`bccomp`) on string values — never native float arithmetic — for the same reason `JournalEntryForm` uses `bccomp` for balance checking.
- `Route::get($uri, [ClassName::class, '__invoke'])` (array-callable form, not bare class-string) is required for every inventory route, for the same reason documented in the existing `routes/web.php` comment: bare class-strings resolve `method_exists()` eagerly at route *registration* time and crash the whole app boot if the target class doesn't exist yet. This lets all six inventory routes be registered in Task 1 even though five of their target classes are only built in later tasks.
- Per the approved spec: **no ledger/journal-entry auto-posting** from stock movements in this phase (deferred to Invoicing); **no FIFO/lot tracking** (weighted average only); **no negative/backordered stock** (issues/transfers/negative-adjustments that would exceed on-hand quantity are rejected via `App\Exceptions\InsufficientStockException`); **camera-based barcode scanning only** (no handheld scanner support).

---

### Task 1: Warehouses

**Files:**
- Create: `database/migrations/2026_07_20_090000_create_warehouses_table.php`
- Create: `app/Models/Warehouse.php`
- Create: `database/factories/WarehouseFactory.php`
- Create: `app/Policies/WarehousePolicy.php`
- Create: `app/Livewire/Inventory/WarehouseIndex.php`
- Create: `resources/views/livewire/inventory/warehouse-index.blade.php`
- Modify: `routes/web.php` (add the full `inventory.` route group — all six routes, per the Global Constraints note on array-callable routing)
- Test: `tests/Unit/WarehouseTest.php`
- Test: `tests/Feature/WarehouseIndexTest.php`

**Interfaces:**
- Produces: `Warehouse` model, fillable `['company_id', 'name', 'is_active']`, casts `is_active` to boolean, relation `company(): BelongsTo`. `WarehouseFactory` with `company_id`, unique `name`, `is_active = true` defaults.
- Produces: route names `inventory.warehouses.index`, `inventory.items.index`, `inventory.stock-movements.create`, `inventory.reports.stock-on-hand`, `inventory.reports.item-movement-card`, `inventory.reports.stock-valuation` — all registered now (targets for the latter five don't exist until later tasks).

- [ ] **Step 1: Write the failing unit test**

```php
<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_belongs_to_a_company(): void
    {
        $company = Company::factory()->create();
        $warehouse = Warehouse::factory()->for($company)->create();

        $this->assertTrue($warehouse->company->is($company));
    }

    public function test_is_active_is_cast_to_boolean(): void
    {
        $warehouse = Warehouse::factory()->create(['is_active' => 1]);

        $this->assertIsBool($warehouse->fresh()->is_active);
        $this->assertTrue($warehouse->fresh()->is_active);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WarehouseTest`
Expected: FAIL — `Class "App\Models\Warehouse" not found` (or migration table missing).

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
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
```

- [ ] **Step 4: Write the `Warehouse` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Warehouse extends Model
{
    use HasFactory;

    protected $fillable = ['company_id', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class WarehouseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => ucfirst($this->faker->unique()->word()).' Warehouse',
            'is_active' => true,
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=WarehouseTest`
Expected: PASS

- [ ] **Step 7: Write the policy**

```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Warehouse;

class WarehousePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return $user->visibleCompanies()->whereKey($warehouse->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'client']);
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'client'])
            && $user->visibleCompanies()->whereKey($warehouse->company_id)->exists();
    }
}
```

- [ ] **Step 8: Write the `WarehouseIndex` Livewire component**

```php
<?php

namespace App\Livewire\Inventory;

use App\Models\Company;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WarehouseIndex extends Component
{
    public Company $company;

    public string $newName = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function addWarehouse(): void
    {
        Gate::authorize('create', Warehouse::class);

        $validated = $this->validate([
            'newName' => ['required', 'string', 'max:255', Rule::unique('warehouses', 'name')->where('company_id', $this->company->id)],
        ]);

        Warehouse::create([
            'company_id' => $this->company->id,
            'name' => $validated['newName'],
            'is_active' => true,
        ]);

        $this->reset(['newName']);
    }

    public function toggleActive(int $warehouseId): void
    {
        $warehouse = Warehouse::where('company_id', $this->company->id)->findOrFail($warehouseId);
        Gate::authorize('update', $warehouse);

        $warehouse->update(['is_active' => ! $warehouse->is_active]);
    }

    public function render()
    {
        return view('livewire.inventory.warehouse-index', [
            'warehouses' => Warehouse::where('company_id', $this->company->id)->orderBy('name')->get(),
        ]);
    }
}
```

- [ ] **Step 9: Write the view**

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Warehouses — {{ $company->name }}</h1>

    @can('create', \App\Models\Warehouse::class)
        <div class="bg-white shadow rounded-md p-4 mb-6">
            <form wire:submit="addWarehouse" class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newName" value="Warehouse name" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <x-primary-button type="submit">Add</x-primary-button>
            </form>
        </div>
    @endcan

    <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Name</th>
                <th class="py-2 px-4">Active</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($warehouses as $warehouse)
                <tr class="text-sm {{ $warehouse->is_active ? '' : 'text-gray-400' }}">
                    <td class="py-2 px-4">{{ $warehouse->name }}</td>
                    <td class="py-2 px-4">{{ $warehouse->is_active ? 'Yes' : 'No' }}</td>
                    <td class="py-2 px-4">
                        @can('update', $warehouse)
                            <button type="button" wire:click="toggleActive({{ $warehouse->id }})" class="text-indigo-600 hover:underline text-sm">
                                {{ $warehouse->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="py-4 px-4 text-gray-500">No warehouses yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
```

- [ ] **Step 10: Add the full inventory route group**

Modify `routes/web.php` — add these imports near the top alongside the existing `App\Livewire\Accounting\*` imports:

```php
use App\Livewire\Inventory\ItemIndex;
use App\Livewire\Inventory\ItemMovementCardReport;
use App\Livewire\Inventory\StockMovementForm;
use App\Livewire\Inventory\StockOnHandReport;
use App\Livewire\Inventory\StockValuationReport;
use App\Livewire\Inventory\WarehouseIndex;
```

Add this route group after the existing `accounting.` group (before `require __DIR__.'/auth.php';`):

```php
// Array-callable form (not bare class-string) for the same reason as the
// accounting.* group above: five of these six target classes don't exist
// until later Inventory tasks, and a bare class-string would crash route
// registration immediately.
Route::middleware(['auth'])->prefix('companies/{company}')->name('inventory.')->group(function () {
    Route::get('/warehouses', [WarehouseIndex::class, '__invoke'])->name('warehouses.index');
    Route::get('/items', [ItemIndex::class, '__invoke'])->name('items.index');
    Route::get('/stock-movements/create/{type}', [StockMovementForm::class, '__invoke'])->name('stock-movements.create');
    Route::get('/reports/stock-on-hand', [StockOnHandReport::class, '__invoke'])->name('reports.stock-on-hand');
    Route::get('/reports/item-movement-card', [ItemMovementCardReport::class, '__invoke'])->name('reports.item-movement-card');
    Route::get('/reports/stock-valuation', [StockValuationReport::class, '__invoke'])->name('reports.stock-valuation');
});
```

- [ ] **Step 11: Write the failing feature test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Inventory\WarehouseIndex;
use App\Models\Company;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WarehouseIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_it_lists_the_companys_warehouses(): void
    {
        $company = Company::factory()->create();
        Warehouse::factory()->for($company)->create(['name' => 'Main Store']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(WarehouseIndex::class, ['company' => $company])
            ->assertSee('Main Store');
    }

    public function test_client_can_add_a_warehouse_for_their_own_company(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        Livewire::test(WarehouseIndex::class, ['company' => $company])
            ->set('newName', 'Second Location')
            ->call('addWarehouse')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('warehouses', ['company_id' => $company->id, 'name' => 'Second Location']);
    }

    public function test_duplicate_warehouse_name_in_the_same_company_is_rejected(): void
    {
        $company = Company::factory()->create();
        Warehouse::factory()->for($company)->create(['name' => 'Main Store']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(WarehouseIndex::class, ['company' => $company])
            ->set('newName', 'Main Store')
            ->call('addWarehouse')
            ->assertHasErrors(['newName' => 'unique']);
    }

    public function test_the_warehouses_page_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('inventory.warehouses.index', $company))
            ->assertOk();
    }
}
```

- [ ] **Step 12: Run test to verify it passes**

Run: `php artisan test --filter=WarehouseIndexTest`
Expected: PASS

- [ ] **Step 13: Commit**

```bash
git add database/migrations/2026_07_20_090000_create_warehouses_table.php app/Models/Warehouse.php database/factories/WarehouseFactory.php app/Policies/WarehousePolicy.php app/Livewire/Inventory/WarehouseIndex.php resources/views/livewire/inventory/warehouse-index.blade.php routes/web.php tests/Unit/WarehouseTest.php tests/Feature/WarehouseIndexTest.php
git commit -m "feat: add warehouses (schema, policy, CRUD screen) and inventory route group"
```

---

### Task 2: Items

**Files:**
- Create: `database/migrations/2026_07_20_090100_create_items_table.php`
- Create: `app/Models/Item.php`
- Create: `database/factories/ItemFactory.php`
- Create: `app/Policies/ItemPolicy.php`
- Create: `app/Livewire/Inventory/ItemIndex.php`
- Create: `resources/views/livewire/inventory/item-index.blade.php`
- Test: `tests/Unit/ItemTest.php`
- Test: `tests/Feature/ItemIndexTest.php`

**Interfaces:**
- Consumes: `inventory.items.index` route name (Task 1).
- Produces: `Item` model, fillable `['company_id', 'code', 'name', 'unit_of_measure', 'category', 'vat_rate', 'preferred_partner_id', 'is_active']`, casts `vat_rate` to `decimal:2` and `is_active` to boolean, relations `company(): BelongsTo`, `preferredPartner(): BelongsTo`. `ItemFactory` with unique `code`, `unit_of_measure = 'piece'`, `vat_rate = 18.00` defaults.

- [ ] **Step 1: Write the failing unit test**

```php
<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_belongs_to_a_company(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();

        $this->assertTrue($item->company->is($company));
    }

    public function test_item_can_have_a_preferred_partner(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create(['preferred_partner_id' => $partner->id]);

        $this->assertTrue($item->preferredPartner->is($partner));
    }

    public function test_code_is_unique_per_company(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['code' => 'SKU-1']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Item::factory()->for($company)->create(['code' => 'SKU-1']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ItemTest`
Expected: FAIL — `Class "App\Models\Item" not found`.

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
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->string('unit_of_measure', 20);
            $table->string('category')->nullable();
            $table->decimal('vat_rate', 5, 2)->default(18.00);
            $table->foreignId('preferred_partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
```

- [ ] **Step 4: Write the `Item` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Item extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'code', 'name', 'unit_of_measure', 'category',
        'vat_rate', 'preferred_partner_id', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'vat_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function preferredPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'preferred_partner_id');
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => strtoupper($this->faker->unique()->bothify('SKU-####')),
            'name' => $this->faker->words(3, true),
            'unit_of_measure' => 'piece',
            'category' => null,
            'vat_rate' => 18.00,
            'preferred_partner_id' => null,
            'is_active' => true,
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=ItemTest`
Expected: PASS

- [ ] **Step 7: Write the policy**

```php
<?php

namespace App\Policies;

use App\Models\Item;
use App\Models\User;

class ItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Item $item): bool
    {
        return $user->visibleCompanies()->whereKey($item->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'client']);
    }

    public function update(User $user, Item $item): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'client'])
            && $user->visibleCompanies()->whereKey($item->company_id)->exists();
    }
}
```

- [ ] **Step 8: Write the `ItemIndex` Livewire component**

```php
<?php

namespace App\Livewire\Inventory;

use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ItemIndex extends Component
{
    public Company $company;

    public string $search = '';

    public string $newCode = '';

    public string $newName = '';

    public string $newUnitOfMeasure = 'piece';

    public string $newCategory = '';

    public string $newVatRate = '18.00';

    public string $newPreferredPartnerId = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function addItem(): void
    {
        Gate::authorize('create', Item::class);

        $validated = $this->validate([
            'newCode' => ['required', 'string', 'max:50', Rule::unique('items', 'code')->where('company_id', $this->company->id)],
            'newName' => 'required|string|max:255',
            'newUnitOfMeasure' => 'required|string|max:20',
            'newCategory' => 'nullable|string|max:255',
            'newVatRate' => 'required|numeric|min:0|max:100',
            'newPreferredPartnerId' => ['nullable', Rule::exists('partners', 'id')->where('company_id', $this->company->id)],
        ]);

        Item::create([
            'company_id' => $this->company->id,
            'code' => $validated['newCode'],
            'name' => $validated['newName'],
            'unit_of_measure' => $validated['newUnitOfMeasure'],
            'category' => $validated['newCategory'] ?: null,
            'vat_rate' => $validated['newVatRate'],
            'preferred_partner_id' => $validated['newPreferredPartnerId'] ?: null,
            'is_active' => true,
        ]);

        $this->reset(['newCode', 'newName', 'newCategory', 'newPreferredPartnerId']);
        $this->newUnitOfMeasure = 'piece';
        $this->newVatRate = '18.00';
    }

    public function toggleActive(int $itemId): void
    {
        $item = Item::where('company_id', $this->company->id)->findOrFail($itemId);
        Gate::authorize('update', $item);

        $item->update(['is_active' => ! $item->is_active]);
    }

    public function render()
    {
        $items = Item::where('company_id', $this->company->id)
            ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2->where('name', 'like', "%{$this->search}%")->orWhere('code', 'like', "%{$this->search}%")))
            ->orderBy('name')
            ->get();

        return view('livewire.inventory.item-index', [
            'items' => $items,
            'partners' => Partner::where('company_id', $this->company->id)->orderBy('name')->get(),
        ]);
    }
}
```

- [ ] **Step 9: Write the view**

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Items — {{ $company->name }}</h1>

    @can('create', \App\Models\Item::class)
        <div class="bg-white shadow rounded-md p-4 mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Add item</h2>
            <form wire:submit="addItem" class="flex flex-wrap gap-3 items-end">
                <div>
                    <x-input-label for="newCode" value="Code / barcode" />
                    <x-text-input id="newCode" wire:model="newCode" class="w-40" />
                    @error('newCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="flex-1 min-w-[12rem]">
                    <x-input-label for="newName" value="Name" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newUnitOfMeasure" value="Unit" />
                    <x-text-input id="newUnitOfMeasure" wire:model="newUnitOfMeasure" class="w-24" />
                </div>
                <div>
                    <x-input-label for="newCategory" value="Category" />
                    <x-text-input id="newCategory" wire:model="newCategory" class="w-32" />
                </div>
                <div>
                    <x-input-label for="newVatRate" value="VAT %" />
                    <x-text-input id="newVatRate" wire:model="newVatRate" class="w-20" />
                </div>
                <div>
                    <x-input-label for="newPreferredPartnerId" value="Preferred supplier" />
                    <select id="newPreferredPartnerId" wire:model="newPreferredPartnerId" class="border-gray-300 rounded-md text-sm">
                        <option value="">—</option>
                        @foreach ($partners as $partner)
                            <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                        @endforeach
                    </select>
                </div>
                <x-primary-button type="submit">Add</x-primary-button>
            </form>
        </div>
    @endcan

    <div class="mb-4">
        <x-text-input wire:model.live="search" placeholder="Search by name or code" class="w-full max-w-sm" />
    </div>

    <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Code</th>
                <th class="py-2 px-4">Name</th>
                <th class="py-2 px-4">Unit</th>
                <th class="py-2 px-4">Category</th>
                <th class="py-2 px-4">VAT %</th>
                <th class="py-2 px-4">Active</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($items as $item)
                <tr class="text-sm {{ $item->is_active ? '' : 'text-gray-400' }}">
                    <td class="py-2 px-4 font-mono">{{ $item->code }}</td>
                    <td class="py-2 px-4">{{ $item->name }}</td>
                    <td class="py-2 px-4">{{ $item->unit_of_measure }}</td>
                    <td class="py-2 px-4">{{ $item->category }}</td>
                    <td class="py-2 px-4">{{ $item->vat_rate }}</td>
                    <td class="py-2 px-4">{{ $item->is_active ? 'Yes' : 'No' }}</td>
                    <td class="py-2 px-4">
                        @can('update', $item)
                            <button type="button" wire:click="toggleActive({{ $item->id }})" class="text-indigo-600 hover:underline text-sm">
                                {{ $item->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-4 px-4 text-gray-500">No items yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
```

- [ ] **Step 10: Write the failing feature test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Inventory\ItemIndex;
use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ItemIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_lists_the_companys_items(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['name' => 'Widget A']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->assertSee('Widget A');
    }

    public function test_client_can_add_an_item_for_their_own_company(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->set('newCode', 'SKU-100')
            ->set('newName', 'Widget B')
            ->set('newUnitOfMeasure', 'kg')
            ->call('addItem')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('items', ['company_id' => $company->id, 'code' => 'SKU-100', 'unit_of_measure' => 'kg']);
    }

    public function test_search_filters_by_name_or_code(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['name' => 'Widget A', 'code' => 'SKU-1']);
        Item::factory()->for($company)->create(['name' => 'Gadget B', 'code' => 'SKU-2']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->set('search', 'Widget')
            ->assertSee('Widget A')
            ->assertDontSee('Gadget B');
    }

    public function test_the_items_page_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('inventory.items.index', $company))
            ->assertOk();
    }
}
```

- [ ] **Step 11: Run test to verify it passes**

Run: `php artisan test --filter=ItemIndexTest`
Expected: PASS

- [ ] **Step 12: Commit**

```bash
git add database/migrations/2026_07_20_090100_create_items_table.php app/Models/Item.php database/factories/ItemFactory.php app/Policies/ItemPolicy.php app/Livewire/Inventory/ItemIndex.php resources/views/livewire/inventory/item-index.blade.php tests/Unit/ItemTest.php tests/Feature/ItemIndexTest.php
git commit -m "feat: add item catalog (schema, policy, CRUD screen with search)"
```

---

### Task 3: Stock Movement & Stock Level Schema

**Files:**
- Create: `database/migrations/2026_07_20_090200_create_stock_movements_table.php`
- Create: `database/migrations/2026_07_20_090300_create_stock_levels_table.php`
- Create: `app/Models/StockMovement.php`
- Create: `app/Models/StockLevel.php`
- Create: `database/factories/StockMovementFactory.php`
- Create: `database/factories/StockLevelFactory.php`
- Test: `tests/Unit/StockMovementTest.php`
- Test: `tests/Unit/StockLevelTest.php`

**Interfaces:**
- Produces: `StockMovement` model, fillable `['item_id', 'warehouse_id', 'to_warehouse_id', 'type', 'quantity', 'unit_cost', 'reason', 'movement_date', 'created_by']`, casts `quantity` to `decimal:3`, `unit_cost` to `decimal:4`, `movement_date` to `date`; relations `item()`, `warehouse()`, `toWarehouse()` (FK `to_warehouse_id`), `creator()` (FK `created_by`).
- Produces: `StockLevel` model, fillable `['item_id', 'warehouse_id', 'quantity_on_hand', 'average_cost']`, casts `quantity_on_hand` to `decimal:3`, `average_cost` to `decimal:4`; relations `item()`, `warehouse()`. Unique on `(item_id, warehouse_id)`.

- [ ] **Step 1: Write the failing unit tests**

```php
<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_an_item_and_warehouse(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $movement = StockMovement::factory()->create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id]);

        $this->assertTrue($movement->item->is($item));
        $this->assertTrue($movement->warehouse->is($warehouse));
    }

    public function test_transfer_movement_has_a_to_warehouse(): void
    {
        $destination = Warehouse::factory()->create();
        $movement = StockMovement::factory()->create(['type' => 'transfer', 'to_warehouse_id' => $destination->id]);

        $this->assertTrue($movement->toWarehouse->is($destination));
    }

    public function test_quantity_and_unit_cost_are_cast_to_decimals(): void
    {
        $movement = StockMovement::factory()->create(['quantity' => '10.500', 'unit_cost' => '99.9900']);

        $this->assertSame('10.500', (string) $movement->fresh()->quantity);
        $this->assertSame('99.9900', (string) $movement->fresh()->unit_cost);
    }
}
```

```php
<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\StockLevel;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockLevelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_an_item_and_warehouse(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $level = StockLevel::factory()->create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id]);

        $this->assertTrue($level->item->is($item));
        $this->assertTrue($level->warehouse->is($warehouse));
    }

    public function test_item_and_warehouse_pair_is_unique(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        StockLevel::factory()->create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        StockLevel::factory()->create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id]);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=StockMovementTest`
Run: `php artisan test --filter=StockLevelTest`
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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->foreignId('to_warehouse_id')->nullable()->constrained('warehouses');
            $table->string('type', 20);
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_cost', 15, 4);
            $table->string('reason')->nullable();
            $table->date('movement_date');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['item_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
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
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->decimal('quantity_on_hand', 15, 3)->default(0);
            $table->decimal('average_cost', 15, 4)->default(0);
            $table->timestamps();

            $table->unique(['item_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};
```

- [ ] **Step 4: Write the models**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_id', 'warehouse_id', 'to_warehouse_id', 'type',
        'quantity', 'unit_cost', 'reason', 'movement_date', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'movement_date' => 'date',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLevel extends Model
{
    use HasFactory;

    protected $fillable = ['item_id', 'warehouse_id', 'quantity_on_hand', 'average_cost'];

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:3',
            'average_cost' => 'decimal:4',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
```

- [ ] **Step 5: Write the factories**

```php
<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockMovementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'warehouse_id' => Warehouse::factory(),
            'to_warehouse_id' => null,
            'type' => 'receipt',
            'quantity' => '10.000',
            'unit_cost' => '100.0000',
            'reason' => null,
            'movement_date' => now()->toDateString(),
            'created_by' => User::factory(),
        ];
    }
}
```

```php
<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockLevelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'warehouse_id' => Warehouse::factory(),
            'quantity_on_hand' => '0.000',
            'average_cost' => '0.0000',
        ];
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=StockMovementTest`
Run: `php artisan test --filter=StockLevelTest`
Expected: Both PASS

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_20_090200_create_stock_movements_table.php database/migrations/2026_07_20_090300_create_stock_levels_table.php app/Models/StockMovement.php app/Models/StockLevel.php database/factories/StockMovementFactory.php database/factories/StockLevelFactory.php tests/Unit/StockMovementTest.php tests/Unit/StockLevelTest.php
git commit -m "feat: add stock_movements and stock_levels schema and models"
```

---

### Task 4: Stock Movement Service — Receipts

**Files:**
- Create: `app/Exceptions/InsufficientStockException.php`
- Create: `app/Services/Inventory/StockMovementService.php`
- Test: `tests/Unit/StockMovementServiceTest.php`

**Interfaces:**
- Consumes: `Item`, `Warehouse`, `StockMovement`, `StockLevel` models (Tasks 2–3).
- Produces: `App\Exceptions\InsufficientStockException extends \RuntimeException`. `StockMovementService::receipt(Item $item, Warehouse $warehouse, string $quantity, string $unitCost, string $movementDate, int $createdBy): StockMovement` — creates/updates the `(item, warehouse)` `StockLevel` row with a weighted-average recalculation, and inserts a `stock_movements` row with `type = 'receipt'`. Private helper `lockedLevel(Item $item, Warehouse $warehouse): StockLevel` (used by all later service methods too — do not duplicate this helper in later tasks, extend this same class).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockMovementServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockMovementService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = app(StockMovementService::class);
    }

    public function test_first_receipt_sets_quantity_and_average_cost(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $user = User::factory()->create();

        $movement = $this->service->receipt($item, $warehouse, '10', '100.00', '2026-01-10', $user->id);

        $this->assertSame('receipt', $movement->type);
        $this->assertSame('10.000', (string) $movement->quantity);
        $this->assertSame('100.0000', (string) $movement->unit_cost);

        $level = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame('10.000', (string) $level->quantity_on_hand);
        $this->assertSame('100.0000', (string) $level->average_cost);
    }

    public function test_second_receipt_at_a_different_cost_recalculates_weighted_average(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouse, '10', '100.00', '2026-01-10', $user->id);
        $this->service->receipt($item, $warehouse, '5', '130.00', '2026-01-12', $user->id);

        $level = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first();

        // ((10 * 100) + (5 * 130)) / 15 = 110.00
        $this->assertSame('15.000', (string) $level->quantity_on_hand);
        $this->assertSame('110.0000', (string) $level->average_cost);
    }

    public function test_receipts_for_the_same_item_in_different_warehouses_are_independent(): void
    {
        $item = Item::factory()->create();
        $warehouseA = Warehouse::factory()->create();
        $warehouseB = Warehouse::factory()->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouseA, '10', '100.00', '2026-01-10', $user->id);
        $this->service->receipt($item, $warehouseB, '20', '50.00', '2026-01-10', $user->id);

        $levelA = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouseA->id)->first();
        $levelB = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouseB->id)->first();

        $this->assertSame('100.0000', (string) $levelA->average_cost);
        $this->assertSame('50.0000', (string) $levelB->average_cost);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StockMovementServiceTest`
Expected: FAIL — `Class "App\Services\Inventory\StockMovementService" not found`.

- [ ] **Step 3: Write the exception class**

```php
<?php

namespace App\Exceptions;

class InsufficientStockException extends \RuntimeException
{
}
```

- [ ] **Step 4: Write the service**

```php
<?php

namespace App\Services\Inventory;

use App\Models\Item;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class StockMovementService
{
    private const QTY_SCALE = 3;

    private const COST_SCALE = 4;

    private const VALUE_SCALE = 6;

    public function receipt(Item $item, Warehouse $warehouse, string $quantity, string $unitCost, string $movementDate, int $createdBy): StockMovement
    {
        return DB::transaction(function () use ($item, $warehouse, $quantity, $unitCost, $movementDate, $createdBy) {
            $level = $this->lockedLevel($item, $warehouse);

            $oldValue = bcmul($level->quantity_on_hand, $level->average_cost, self::VALUE_SCALE);
            $newValue = bcmul($quantity, $unitCost, self::VALUE_SCALE);
            $newQty = bcadd($level->quantity_on_hand, $quantity, self::QTY_SCALE);
            $newAvgCost = bccomp($newQty, '0', self::QTY_SCALE) > 0
                ? bcdiv(bcadd($oldValue, $newValue, self::VALUE_SCALE), $newQty, self::COST_SCALE)
                : '0.0000';

            $level->update(['quantity_on_hand' => $newQty, 'average_cost' => $newAvgCost]);

            return StockMovement::create([
                'item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'type' => 'receipt',
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'movement_date' => $movementDate,
                'created_by' => $createdBy,
            ]);
        });
    }

    /**
     * NOTE: lockForUpdate() here only holds a real lock because every public
     * method wraps its call to this helper in DB::transaction() — same
     * caveat as JournalEntry's entry_number sequencing in Phase 1.
     */
    private function lockedLevel(Item $item, Warehouse $warehouse): StockLevel
    {
        StockLevel::firstOrCreate(
            ['item_id' => $item->id, 'warehouse_id' => $warehouse->id],
            ['quantity_on_hand' => '0', 'average_cost' => '0']
        );

        return StockLevel::where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->lockForUpdate()
            ->first();
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=StockMovementServiceTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Exceptions/InsufficientStockException.php app/Services/Inventory/StockMovementService.php tests/Unit/StockMovementServiceTest.php
git commit -m "feat: add StockMovementService with weighted-average receipt logic"
```

---

### Task 5: Stock Movement Service — Issues

**Files:**
- Modify: `app/Services/Inventory/StockMovementService.php`
- Modify: `tests/Unit/StockMovementServiceTest.php`

**Interfaces:**
- Consumes: `InsufficientStockException` (Task 4).
- Produces: `StockMovementService::issue(Item $item, Warehouse $warehouse, string $quantity, string $movementDate, int $createdBy): StockMovement` — decrements `quantity_on_hand` at the warehouse's current `average_cost` (unchanged by issues), throws `InsufficientStockException` if `$quantity` exceeds what's on hand.

- [ ] **Step 1: Add the failing tests**

Append to `tests/Unit/StockMovementServiceTest.php`, inside the class, after the existing test methods:

```php
    public function test_issue_decrements_quantity_at_current_average_cost(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouse, '10', '100.00', '2026-01-10', $user->id);
        $this->service->receipt($item, $warehouse, '5', '130.00', '2026-01-12', $user->id);
        $movement = $this->service->issue($item, $warehouse, '6', '2026-01-15', $user->id);

        $this->assertSame('issue', $movement->type);
        $this->assertSame('6.000', (string) $movement->quantity);
        $this->assertSame('110.0000', (string) $movement->unit_cost);

        $level = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame('9.000', (string) $level->quantity_on_hand);
        $this->assertSame('110.0000', (string) $level->average_cost);
    }

    public function test_issue_exceeding_quantity_on_hand_is_rejected(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouse, '10', '100.00', '2026-01-10', $user->id);

        $this->expectException(\App\Exceptions\InsufficientStockException::class);

        $this->service->issue($item, $warehouse, '11', '2026-01-15', $user->id);
    }
```

Add `use App\Models\StockMovement;` is not needed since it's already resolved via `\App\Models\StockLevel::` fully-qualified name in this file; leave the existing `use` statements as they are.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StockMovementServiceTest`
Expected: FAIL — `Call to undefined method App\Services\Inventory\StockMovementService::issue()`.

- [ ] **Step 3: Add the `issue` method**

Modify `app/Services/Inventory/StockMovementService.php` — add this method after `receipt()` and before the `lockedLevel()` helper. Also add `use App\Exceptions\InsufficientStockException;` to the `use` block at the top of the file.

```php
    public function issue(Item $item, Warehouse $warehouse, string $quantity, string $movementDate, int $createdBy): StockMovement
    {
        return DB::transaction(function () use ($item, $warehouse, $quantity, $movementDate, $createdBy) {
            $level = $this->lockedLevel($item, $warehouse);

            if (bccomp($level->quantity_on_hand, $quantity, self::QTY_SCALE) < 0) {
                throw new InsufficientStockException(
                    "Cannot issue {$quantity} of item #{$item->id} from warehouse #{$warehouse->id}: only {$level->quantity_on_hand} on hand."
                );
            }

            $unitCost = $level->average_cost;
            $newQty = bcsub($level->quantity_on_hand, $quantity, self::QTY_SCALE);
            $level->update(['quantity_on_hand' => $newQty]);

            return StockMovement::create([
                'item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'type' => 'issue',
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'movement_date' => $movementDate,
                'created_by' => $createdBy,
            ]);
        });
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=StockMovementServiceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Inventory/StockMovementService.php tests/Unit/StockMovementServiceTest.php
git commit -m "feat: add issue logic to StockMovementService with insufficient-stock rejection"
```

---

### Task 6: Stock Movement Service — Transfers

**Files:**
- Modify: `app/Services/Inventory/StockMovementService.php`
- Modify: `tests/Unit/StockMovementServiceTest.php`

**Interfaces:**
- Produces: `StockMovementService::transfer(Item $item, Warehouse $fromWarehouse, Warehouse $toWarehouse, string $quantity, string $movementDate, int $createdBy): StockMovement` — one `stock_movements` row with `type = 'transfer'`, `warehouse_id` = source, `to_warehouse_id` = destination. Decrements source at its current average cost; recalculates the destination's weighted average as if it received stock at that cost. Throws `InsufficientStockException` if `$quantity` exceeds the source's on-hand quantity, or `\InvalidArgumentException` if source and destination are the same warehouse.

- [ ] **Step 1: Add the failing tests**

Append to `tests/Unit/StockMovementServiceTest.php`:

```php
    public function test_transfer_moves_quantity_and_carries_source_cost_into_destination(): void
    {
        $item = Item::factory()->create();
        $warehouseA = Warehouse::factory()->create();
        $warehouseB = Warehouse::factory()->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouseA, '10', '100.00', '2026-01-10', $user->id);
        $this->service->receipt($item, $warehouseA, '5', '130.00', '2026-01-12', $user->id);
        // Warehouse A is now 15 units @ 110.00 average.

        $movement = $this->service->transfer($item, $warehouseA, $warehouseB, '5', '2026-01-15', $user->id);

        $this->assertSame('transfer', $movement->type);
        $this->assertSame($warehouseA->id, $movement->warehouse_id);
        $this->assertSame($warehouseB->id, $movement->to_warehouse_id);
        $this->assertSame('110.0000', (string) $movement->unit_cost);

        $levelA = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouseA->id)->first();
        $levelB = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouseB->id)->first();

        $this->assertSame('10.000', (string) $levelA->quantity_on_hand);
        $this->assertSame('110.0000', (string) $levelA->average_cost);
        $this->assertSame('5.000', (string) $levelB->quantity_on_hand);
        $this->assertSame('110.0000', (string) $levelB->average_cost);
    }

    public function test_transfer_into_a_warehouse_with_existing_stock_recalculates_its_average(): void
    {
        $item = Item::factory()->create();
        $warehouseA = Warehouse::factory()->create();
        $warehouseB = Warehouse::factory()->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouseA, '10', '120.00', '2026-01-10', $user->id);
        $this->service->receipt($item, $warehouseB, '10', '80.00', '2026-01-10', $user->id);

        $this->service->transfer($item, $warehouseA, $warehouseB, '10', '2026-01-15', $user->id);

        // Warehouse B: ((10 * 80) + (10 * 120)) / 20 = 100.00
        $levelB = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouseB->id)->first();
        $this->assertSame('20.000', (string) $levelB->quantity_on_hand);
        $this->assertSame('100.0000', (string) $levelB->average_cost);
    }

    public function test_transfer_exceeding_source_quantity_is_rejected(): void
    {
        $item = Item::factory()->create();
        $warehouseA = Warehouse::factory()->create();
        $warehouseB = Warehouse::factory()->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouseA, '5', '100.00', '2026-01-10', $user->id);

        $this->expectException(\App\Exceptions\InsufficientStockException::class);

        $this->service->transfer($item, $warehouseA, $warehouseB, '6', '2026-01-15', $user->id);
    }

    public function test_transfer_to_the_same_warehouse_is_rejected(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouse, '5', '100.00', '2026-01-10', $user->id);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->transfer($item, $warehouse, $warehouse, '1', '2026-01-15', $user->id);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StockMovementServiceTest`
Expected: FAIL — `Call to undefined method App\Services\Inventory\StockMovementService::transfer()`.

- [ ] **Step 3: Add the `transfer` method**

Modify `app/Services/Inventory/StockMovementService.php` — add this method after `issue()` and before `lockedLevel()`:

```php
    public function transfer(Item $item, Warehouse $fromWarehouse, Warehouse $toWarehouse, string $quantity, string $movementDate, int $createdBy): StockMovement
    {
        if ($fromWarehouse->is($toWarehouse)) {
            throw new \InvalidArgumentException('Cannot transfer stock to the same warehouse.');
        }

        return DB::transaction(function () use ($item, $fromWarehouse, $toWarehouse, $quantity, $movementDate, $createdBy) {
            // Lock both warehouse levels in a fixed order (ascending warehouse
            // id) regardless of transfer direction, so two concurrent
            // transfers between the same pair of warehouses can never
            // deadlock on each other's locks.
            [$firstWarehouse, $secondWarehouse] = $fromWarehouse->id <= $toWarehouse->id
                ? [$fromWarehouse, $toWarehouse]
                : [$toWarehouse, $fromWarehouse];

            $firstLevel = $this->lockedLevel($item, $firstWarehouse);
            $secondLevel = $this->lockedLevel($item, $secondWarehouse);

            $fromLevel = $firstWarehouse->is($fromWarehouse) ? $firstLevel : $secondLevel;
            $toLevel = $firstWarehouse->is($fromWarehouse) ? $secondLevel : $firstLevel;

            if (bccomp($fromLevel->quantity_on_hand, $quantity, self::QTY_SCALE) < 0) {
                throw new InsufficientStockException(
                    "Cannot transfer {$quantity} of item #{$item->id} from warehouse #{$fromWarehouse->id}: only {$fromLevel->quantity_on_hand} on hand."
                );
            }

            $costAtSource = $fromLevel->average_cost;
            $fromLevel->update(['quantity_on_hand' => bcsub($fromLevel->quantity_on_hand, $quantity, self::QTY_SCALE)]);

            $oldValue = bcmul($toLevel->quantity_on_hand, $toLevel->average_cost, self::VALUE_SCALE);
            $incomingValue = bcmul($quantity, $costAtSource, self::VALUE_SCALE);
            $newToQty = bcadd($toLevel->quantity_on_hand, $quantity, self::QTY_SCALE);
            $newToAvgCost = bccomp($newToQty, '0', self::QTY_SCALE) > 0
                ? bcdiv(bcadd($oldValue, $incomingValue, self::VALUE_SCALE), $newToQty, self::COST_SCALE)
                : '0.0000';
            $toLevel->update(['quantity_on_hand' => $newToQty, 'average_cost' => $newToAvgCost]);

            return StockMovement::create([
                'item_id' => $item->id,
                'warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'type' => 'transfer',
                'quantity' => $quantity,
                'unit_cost' => $costAtSource,
                'movement_date' => $movementDate,
                'created_by' => $createdBy,
            ]);
        });
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=StockMovementServiceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Inventory/StockMovementService.php tests/Unit/StockMovementServiceTest.php
git commit -m "feat: add transfer logic to StockMovementService with deadlock-safe locking"
```

---

### Task 7: Stock Movement Service — Adjustments

**Files:**
- Modify: `app/Services/Inventory/StockMovementService.php`
- Modify: `tests/Unit/StockMovementServiceTest.php`

**Interfaces:**
- Produces: `StockMovementService::adjustment(Item $item, Warehouse $warehouse, string $quantityDelta, string $reason, string $movementDate, int $createdBy): StockMovement` — `$quantityDelta` is a signed numeric string (e.g. `'5'` or `'-3'`). Positive deltas increase `quantity_on_hand` at the current `average_cost` (unchanged); negative deltas decrease it the same way issues do. Throws `InsufficientStockException` if the resulting quantity would go negative.

- [ ] **Step 1: Add the failing tests**

Append to `tests/Unit/StockMovementServiceTest.php`:

```php
    public function test_positive_adjustment_increases_quantity_without_changing_average_cost(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouse, '10', '100.00', '2026-01-10', $user->id);
        $movement = $this->service->adjustment($item, $warehouse, '5', 'Physical count correction', '2026-01-20', $user->id);

        $this->assertSame('adjustment', $movement->type);
        $this->assertSame('5.000', (string) $movement->quantity);
        $this->assertSame('100.0000', (string) $movement->unit_cost);
        $this->assertSame('Physical count correction', $movement->reason);

        $level = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame('15.000', (string) $level->quantity_on_hand);
        $this->assertSame('100.0000', (string) $level->average_cost);
    }

    public function test_negative_adjustment_decreases_quantity_without_changing_average_cost(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouse, '10', '100.00', '2026-01-10', $user->id);
        $movement = $this->service->adjustment($item, $warehouse, '-3', 'Damaged goods', '2026-01-20', $user->id);

        $this->assertSame('-3.000', (string) $movement->quantity);

        $level = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertSame('7.000', (string) $level->quantity_on_hand);
        $this->assertSame('100.0000', (string) $level->average_cost);
    }

    public function test_negative_adjustment_exceeding_quantity_on_hand_is_rejected(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $user = User::factory()->create();

        $this->service->receipt($item, $warehouse, '5', '100.00', '2026-01-10', $user->id);

        $this->expectException(\App\Exceptions\InsufficientStockException::class);

        $this->service->adjustment($item, $warehouse, '-6', 'Miscount', '2026-01-20', $user->id);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StockMovementServiceTest`
Expected: FAIL — `Call to undefined method App\Services\Inventory\StockMovementService::adjustment()`.

- [ ] **Step 3: Add the `adjustment` method**

Modify `app/Services/Inventory/StockMovementService.php` — add this method after `transfer()` and before `lockedLevel()`:

```php
    public function adjustment(Item $item, Warehouse $warehouse, string $quantityDelta, string $reason, string $movementDate, int $createdBy): StockMovement
    {
        return DB::transaction(function () use ($item, $warehouse, $quantityDelta, $reason, $movementDate, $createdBy) {
            $level = $this->lockedLevel($item, $warehouse);

            $newQty = bcadd($level->quantity_on_hand, $quantityDelta, self::QTY_SCALE);

            if (bccomp($newQty, '0', self::QTY_SCALE) < 0) {
                throw new InsufficientStockException(
                    "Cannot adjust item #{$item->id} at warehouse #{$warehouse->id} by {$quantityDelta}: only {$level->quantity_on_hand} on hand."
                );
            }

            $unitCost = $level->average_cost;
            $level->update(['quantity_on_hand' => $newQty]);

            return StockMovement::create([
                'item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'type' => 'adjustment',
                'quantity' => $quantityDelta,
                'unit_cost' => $unitCost,
                'reason' => $reason,
                'movement_date' => $movementDate,
                'created_by' => $createdBy,
            ]);
        });
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=StockMovementServiceTest`
Expected: PASS — all `StockMovementServiceTest` methods (receipt, issue, transfer, adjustment) now green.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Inventory/StockMovementService.php tests/Unit/StockMovementServiceTest.php
git commit -m "feat: add adjustment logic to StockMovementService, completing all four movement types"
```

---

### Task 8: Stock Movement Entry Screen

**Files:**
- Create: `app/Policies/StockMovementPolicy.php`
- Create: `app/Livewire/Inventory/StockMovementForm.php`
- Create: `resources/views/livewire/inventory/stock-movement-form.blade.php`
- Test: `tests/Feature/StockMovementFormTest.php`

**Interfaces:**
- Consumes: `StockMovementService` (Tasks 4–7), route `inventory.stock-movements.create` (Task 1, expects a `{type}` route segment).
- Produces: `StockMovementForm` Livewire component — one form parameterized by `$type` (`receipt`/`issue`/`transfer`/`adjustment`), calling into `StockMovementService`. `StockMovementPolicy` with `viewAny`/`view`/`create` only (no `update`/`delete` — movements are immutable, per Global Constraints).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Inventory\StockMovementForm;
use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockMovementFormTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_records_a_receipt(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'receipt'])
            ->set('itemId', (string) $item->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('quantity', '10')
            ->set('unitCost', '50.00')
            ->set('movementDate', '2026-01-10')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('stock_movements', ['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'type' => 'receipt']);
        $this->assertDatabaseHas('stock_levels', ['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity_on_hand' => 10]);
    }

    public function test_it_records_a_transfer_between_two_warehouses(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouseA = Warehouse::factory()->for($company)->create();
        $warehouseB = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(\App\Services\Inventory\StockMovementService::class)->receipt($item, $warehouseA, '10', '100.00', '2026-01-01', $admin->id);

        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'transfer'])
            ->set('itemId', (string) $item->id)
            ->set('warehouseId', (string) $warehouseA->id)
            ->set('toWarehouseId', (string) $warehouseB->id)
            ->set('quantity', '4')
            ->set('movementDate', '2026-01-15')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('stock_levels', ['item_id' => $item->id, 'warehouse_id' => $warehouseB->id, 'quantity_on_hand' => 4]);
    }

    public function test_transfer_requires_a_different_destination_warehouse(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'transfer'])
            ->set('itemId', (string) $item->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('toWarehouseId', (string) $warehouse->id)
            ->set('quantity', '1')
            ->set('movementDate', '2026-01-15')
            ->call('save')
            ->assertHasErrors(['toWarehouseId']);
    }

    public function test_it_records_a_negative_adjustment_with_a_reason(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(\App\Services\Inventory\StockMovementService::class)->receipt($item, $warehouse, '10', '100.00', '2026-01-01', $admin->id);

        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'adjustment'])
            ->set('itemId', (string) $item->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('direction', 'decrease')
            ->set('quantity', '2')
            ->set('reason', 'Damaged goods')
            ->set('movementDate', '2026-01-15')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('stock_movements', ['item_id' => $item->id, 'type' => 'adjustment', 'quantity' => -2, 'reason' => 'Damaged goods']);
    }

    public function test_issuing_more_than_on_hand_shows_an_error_instead_of_a_500(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(\App\Services\Inventory\StockMovementService::class)->receipt($item, $warehouse, '5', '100.00', '2026-01-01', $admin->id);

        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'issue'])
            ->set('itemId', (string) $item->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('quantity', '6')
            ->set('movementDate', '2026-01-15')
            ->call('save')
            ->assertHasErrors(['quantity']);
    }

    public function test_client_can_record_a_movement_for_their_own_company(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'receipt'])
            ->set('itemId', (string) $item->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('quantity', '10')
            ->set('unitCost', '50.00')
            ->set('movementDate', '2026-01-10')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('stock_movements', ['item_id' => $item->id, 'type' => 'receipt']);
    }

    public function test_an_invalid_movement_type_in_the_url_is_a_404(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        $this->get(route('inventory.stock-movements.create', [$company, 'bogus-type']))->assertNotFound();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StockMovementFormTest`
Expected: FAIL — `Class "App\Livewire\Inventory\StockMovementForm" not found`.

- [ ] **Step 3: Write the policy**

```php
<?php

namespace App\Policies;

use App\Models\StockMovement;
use App\Models\User;

class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StockMovement $stockMovement): bool
    {
        return $user->visibleCompanies()->whereKey($stockMovement->item->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'client']);
    }
}
```

- [ ] **Step 4: Write the `StockMovementForm` Livewire component**

```php
<?php

namespace App\Livewire\Inventory;

use App\Exceptions\InsufficientStockException;
use App\Models\Company;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class StockMovementForm extends Component
{
    private const VALID_TYPES = ['receipt', 'issue', 'transfer', 'adjustment'];

    public Company $company;

    public string $type;

    public string $itemId = '';

    public string $warehouseId = '';

    public string $toWarehouseId = '';

    public string $quantity = '';

    public string $unitCost = '';

    public string $direction = 'increase';

    public string $reason = '';

    public string $movementDate = '';

    public function mount(Company $company, string $type): void
    {
        Gate::authorize('view', $company);

        if (! in_array($type, self::VALID_TYPES, true)) {
            abort(404);
        }

        $this->company = $company;
        $this->type = $type;
        $this->movementDate = now()->toDateString();
    }

    public function lookupByCode(string $code): void
    {
        $item = Item::where('company_id', $this->company->id)->where('code', $code)->first();

        if (! $item) {
            $this->addError('scannedCode', "No item found with code \"{$code}\".");

            return;
        }

        $this->itemId = (string) $item->id;
        $this->resetErrorBag('scannedCode');
    }

    public function save(): void
    {
        Gate::authorize('create', StockMovement::class);

        $rules = [
            'itemId' => ['required', Rule::exists('items', 'id')->where('company_id', $this->company->id)],
            'warehouseId' => ['required', Rule::exists('warehouses', 'id')->where('company_id', $this->company->id)],
            'movementDate' => 'required|date',
            'quantity' => 'required|numeric|gt:0',
        ];

        if ($this->type === 'receipt') {
            $rules['unitCost'] = 'required|numeric|min:0';
        }

        if ($this->type === 'transfer') {
            $rules['toWarehouseId'] = [
                'required',
                Rule::exists('warehouses', 'id')->where('company_id', $this->company->id),
                'different:warehouseId',
            ];
        }

        if ($this->type === 'adjustment') {
            $rules['reason'] = 'required|string|max:255';
        }

        $this->validate($rules);

        $item = Item::findOrFail($this->itemId);
        $warehouse = Warehouse::findOrFail($this->warehouseId);
        $service = app(StockMovementService::class);
        $userId = auth()->id();

        try {
            match ($this->type) {
                'receipt' => $service->receipt($item, $warehouse, $this->quantity, $this->unitCost, $this->movementDate, $userId),
                'issue' => $service->issue($item, $warehouse, $this->quantity, $this->movementDate, $userId),
                'transfer' => $service->transfer($item, $warehouse, Warehouse::findOrFail($this->toWarehouseId), $this->quantity, $this->movementDate, $userId),
                'adjustment' => $service->adjustment(
                    $item,
                    $warehouse,
                    $this->direction === 'decrease' ? '-'.$this->quantity : $this->quantity,
                    $this->reason,
                    $this->movementDate,
                    $userId
                ),
            };
        } catch (InsufficientStockException $e) {
            $this->addError('quantity', $e->getMessage());

            return;
        }

        $this->redirect(route('inventory.items.index', $this->company));
    }

    public function render()
    {
        return view('livewire.inventory.stock-movement-form', [
            'items' => Item::where('company_id', $this->company->id)->where('is_active', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('company_id', $this->company->id)->where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}
```

- [ ] **Step 5: Write the view**

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">
        Record {{ ucfirst($type) }} — {{ $company->name }}
    </h1>

    <form wire:submit="save" class="bg-white shadow rounded-md p-4 flex flex-wrap gap-4 items-end max-w-3xl">
        <div class="w-full">
            <x-input-label for="itemId" value="Item" />
            <select id="itemId" wire:model="itemId" class="border-gray-300 rounded-md text-sm w-full">
                <option value="">—</option>
                @foreach ($items as $item)
                    <option value="{{ $item->id }}">{{ $item->code }} — {{ $item->name }}</option>
                @endforeach
            </select>
            @error('itemId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            @error('scannedCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <div>
            <x-input-label for="warehouseId" value="{{ $type === 'transfer' ? 'From warehouse' : 'Warehouse' }}" />
            <select id="warehouseId" wire:model="warehouseId" class="border-gray-300 rounded-md text-sm">
                <option value="">—</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                @endforeach
            </select>
            @error('warehouseId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        @if ($type === 'transfer')
            <div>
                <x-input-label for="toWarehouseId" value="To warehouse" />
                <select id="toWarehouseId" wire:model="toWarehouseId" class="border-gray-300 rounded-md text-sm">
                    <option value="">—</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </select>
                @error('toWarehouseId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
        @endif

        @if ($type === 'adjustment')
            <div>
                <x-input-label for="direction" value="Direction" />
                <select id="direction" wire:model="direction" class="border-gray-300 rounded-md text-sm">
                    <option value="increase">Increase</option>
                    <option value="decrease">Decrease</option>
                </select>
            </div>
        @endif

        <div>
            <x-input-label for="quantity" value="Quantity" />
            <x-text-input id="quantity" wire:model="quantity" class="w-32" />
            @error('quantity') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        @if ($type === 'receipt')
            <div>
                <x-input-label for="unitCost" value="Unit cost" />
                <x-text-input id="unitCost" wire:model="unitCost" class="w-32" />
                @error('unitCost') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
        @endif

        @if ($type === 'adjustment')
            <div class="flex-1 min-w-[16rem]">
                <x-input-label for="reason" value="Reason" />
                <x-text-input id="reason" wire:model="reason" class="w-full" />
                @error('reason') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
        @endif

        <div>
            <x-input-label for="movementDate" value="Date" />
            <input type="date" id="movementDate" wire:model="movementDate" class="border-gray-300 rounded-md text-sm" />
            @error('movementDate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>

        <x-primary-button type="submit">Save</x-primary-button>
    </form>
</div>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=StockMovementFormTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Policies/StockMovementPolicy.php app/Livewire/Inventory/StockMovementForm.php resources/views/livewire/inventory/stock-movement-form.blade.php tests/Feature/StockMovementFormTest.php
git commit -m "feat: add stock movement entry screen for all four movement types"
```

---

### Task 9: Camera Barcode Scanning

**Files:**
- Modify: `package.json` (add `@zxing/browser` and `@zxing/library`)
- Create: `resources/js/barcode-scanner.js`
- Modify: `resources/js/app.js`
- Modify: `resources/views/livewire/inventory/stock-movement-form.blade.php`
- Modify: `tests/Feature/StockMovementFormTest.php`

**Interfaces:**
- Consumes: `StockMovementForm::lookupByCode(string $code)` (already written in Task 8 — this task wires the camera UI to call it).
- Produces: a `barcodeScanner` Alpine component (registered globally via `Alpine.data`), usable as `x-data="barcodeScanner"` in any Blade view that needs camera scanning.

- [ ] **Step 1: Add the failing server-side tests**

Append to `tests/Feature/StockMovementFormTest.php`:

```php
    public function test_scanning_a_known_code_selects_the_item(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['code' => 'SKU-999']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'receipt'])
            ->call('lookupByCode', 'SKU-999')
            ->assertSet('itemId', (string) $item->id)
            ->assertHasNoErrors();
    }

    public function test_scanning_an_unknown_code_shows_an_error(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'receipt'])
            ->call('lookupByCode', 'DOES-NOT-EXIST')
            ->assertHasErrors(['scannedCode'])
            ->assertSet('itemId', '');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StockMovementFormTest`
Expected: PASS already — `lookupByCode` was written in Task 8. This confirms the server-side half of scanning before wiring up the camera UI on top of it.

- [ ] **Step 3: Install the barcode scanning library**

Run: `npm install @zxing/browser @zxing/library`

Verify `package.json`'s `dependencies` now includes both packages (npm adds this section automatically since neither existed before).

- [ ] **Step 4: Write the Alpine barcode scanner component**

```js
import { BrowserMultiFormatReader } from '@zxing/browser';

document.addEventListener('alpine:init', () => {
    Alpine.data('barcodeScanner', () => ({
        scanning: false,
        reader: null,
        controls: null,

        async start() {
            this.scanning = true;
            this.reader = new BrowserMultiFormatReader();

            try {
                this.controls = await this.reader.decodeFromVideoDevice(
                    undefined,
                    this.$refs.video,
                    (result) => {
                        if (result) {
                            this.$wire.call('lookupByCode', result.getText());
                            this.stop();
                        }
                    }
                );
            } catch (error) {
                this.scanning = false;
            }
        },

        stop() {
            this.controls?.stop();
            this.scanning = false;
        },
    }));
});
```

- [ ] **Step 5: Import the scanner module in `app.js`**

```js
import './barcode-scanner';
```

- [ ] **Step 6: Add the scan button and video element to the form view**

Modify `resources/views/livewire/inventory/stock-movement-form.blade.php` — add this block directly above the `<div class="w-full">` item-select block from Task 8:

```blade
        <div x-data="barcodeScanner" class="w-full">
            <button type="button" x-show="!scanning" @click="start()" class="text-sm text-indigo-600 hover:underline">
                Scan barcode
            </button>
            <button type="button" x-show="scanning" @click="stop()" class="text-sm text-red-600 hover:underline">
                Stop scanning
            </button>
            <video x-ref="video" x-show="scanning" wire:ignore class="w-full max-w-sm mt-2 rounded-md"></video>
        </div>

```

- [ ] **Step 7: Build frontend assets and run the full form test file**

Run: `npm run build`
Run: `php artisan test --filter=StockMovementFormTest`
Expected: PASS (all 9 tests, including the two new scan tests)

Note: the camera capture and decode loop itself (`getUserMedia`, `BrowserMultiFormatReader`) has no PHPUnit coverage — it's browser-only behavior. Verify it manually in a real browser (or the project's browser preview tooling) on a device with a camera before treating this task as fully done: open the stock movement form, click "Scan barcode", grant camera permission, and confirm scanning a barcode fills in the item.

- [ ] **Step 8: Commit**

```bash
git add package.json package-lock.json resources/js/barcode-scanner.js resources/js/app.js resources/views/livewire/inventory/stock-movement-form.blade.php tests/Feature/StockMovementFormTest.php
git commit -m "feat: add camera barcode scanning to the stock movement form"
```

---

### Task 10: Stock On Hand Report

**Files:**
- Create: `app/Services/Inventory/StockLevelQuery.php`
- Create: `app/Livewire/Inventory/StockOnHandReport.php`
- Create: `resources/views/livewire/inventory/stock-on-hand-report.blade.php`
- Test: `tests/Unit/StockLevelQueryTest.php`
- Test: `tests/Feature/StockOnHandReportTest.php`

**Interfaces:**
- Produces: `StockLevelQuery::stockOnHand(Company $company, ?int $warehouseId = null): Collection` (rows per item+warehouse pair), `StockLevelQuery::stockOnHandTotals(Company $company): Collection` (rows per item, summed across all of a company's warehouses) — both return plain associative arrays via `Collection::map()`, same shape convention as `LedgerCardQuery`.

- [ ] **Step 1: Write the failing unit test**

```php
<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockLevelQuery;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockLevelQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_on_hand_lists_rows_per_item_and_warehouse(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Widget']);
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', '2026-01-01', $user->id);

        $rows = StockLevelQuery::stockOnHand($company);

        $this->assertCount(1, $rows);
        $this->assertSame('Widget', $rows[0]['item_name']);
        $this->assertSame(10.0, $rows[0]['quantity_on_hand']);
        $this->assertSame(500.0, $rows[0]['value']);
    }

    public function test_stock_on_hand_can_be_filtered_by_warehouse(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouseA = Warehouse::factory()->for($company)->create();
        $warehouseB = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();
        app(StockMovementService::class)->receipt($item, $warehouseA, '10', '50.00', '2026-01-01', $user->id);
        app(StockMovementService::class)->receipt($item, $warehouseB, '20', '50.00', '2026-01-01', $user->id);

        $rows = StockLevelQuery::stockOnHand($company, $warehouseA->id);

        $this->assertCount(1, $rows);
        $this->assertSame(10.0, $rows[0]['quantity_on_hand']);
    }

    public function test_stock_on_hand_totals_sums_across_warehouses(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouseA = Warehouse::factory()->for($company)->create();
        $warehouseB = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();
        app(StockMovementService::class)->receipt($item, $warehouseA, '10', '50.00', '2026-01-01', $user->id);
        app(StockMovementService::class)->receipt($item, $warehouseB, '20', '50.00', '2026-01-01', $user->id);

        $rows = StockLevelQuery::stockOnHandTotals($company);

        $this->assertCount(1, $rows);
        $this->assertSame(30.0, $rows[0]['total_quantity']);
        $this->assertSame(1500.0, $rows[0]['total_value']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StockLevelQueryTest`
Expected: FAIL — `Class "App\Services\Inventory\StockLevelQuery" not found`.

- [ ] **Step 3: Write the query service**

```php
<?php

namespace App\Services\Inventory;

use App\Models\Company;
use App\Models\StockLevel;
use Illuminate\Support\Collection;

class StockLevelQuery
{
    public static function stockOnHand(Company $company, ?int $warehouseId = null): Collection
    {
        return StockLevel::query()
            ->join('items', 'items.id', '=', 'stock_levels.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_levels.warehouse_id')
            ->where('items.company_id', $company->id)
            ->when($warehouseId, fn ($q) => $q->where('stock_levels.warehouse_id', $warehouseId))
            ->orderBy('items.name')
            ->get([
                'items.id as item_id',
                'items.code as item_code',
                'items.name as item_name',
                'warehouses.id as warehouse_id',
                'warehouses.name as warehouse_name',
                'stock_levels.quantity_on_hand',
                'stock_levels.average_cost',
            ])
            ->map(fn ($row) => [
                'item_id' => (int) $row->item_id,
                'item_code' => $row->item_code,
                'item_name' => $row->item_name,
                'warehouse_id' => (int) $row->warehouse_id,
                'warehouse_name' => $row->warehouse_name,
                'quantity_on_hand' => (float) $row->quantity_on_hand,
                'average_cost' => (float) $row->average_cost,
                'value' => round((float) $row->quantity_on_hand * (float) $row->average_cost, 2),
            ])
            ->values();
    }

    public static function stockOnHandTotals(Company $company): Collection
    {
        return StockLevel::query()
            ->join('items', 'items.id', '=', 'stock_levels.item_id')
            ->where('items.company_id', $company->id)
            ->selectRaw('items.id as item_id, items.code as item_code, items.name as item_name, SUM(stock_levels.quantity_on_hand) as total_quantity, SUM(stock_levels.quantity_on_hand * stock_levels.average_cost) as total_value')
            ->groupBy('items.id', 'items.code', 'items.name')
            ->orderBy('items.name')
            ->get()
            ->map(fn ($row) => [
                'item_id' => (int) $row->item_id,
                'item_code' => $row->item_code,
                'item_name' => $row->item_name,
                'total_quantity' => (float) $row->total_quantity,
                'total_value' => round((float) $row->total_value, 2),
            ])
            ->values();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=StockLevelQueryTest`
Expected: PASS

- [ ] **Step 5: Write the `StockOnHandReport` Livewire component**

```php
<?php

namespace App\Livewire\Inventory;

use App\Models\Company;
use App\Models\Warehouse;
use App\Services\Inventory\StockLevelQuery;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class StockOnHandReport extends Component
{
    public Company $company;

    public ?int $warehouseId = null;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        return view('livewire.inventory.stock-on-hand-report', [
            'rows' => StockLevelQuery::stockOnHand($this->company, $this->warehouseId),
            'totals' => StockLevelQuery::stockOnHandTotals($this->company),
            'warehouses' => Warehouse::where('company_id', $this->company->id)->orderBy('name')->get(),
        ]);
    }
}
```

- [ ] **Step 6: Write the view**

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Stock On Hand — {{ $company->name }}</h1>

    <div class="bg-white shadow rounded-md p-4 mb-4 flex flex-wrap gap-4 items-end">
        <div>
            <x-input-label for="warehouseId" value="Warehouse" />
            <select id="warehouseId" wire:model.live="warehouseId" class="border-gray-300 rounded-md text-sm">
                <option value="">All warehouses (totals)</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if ($warehouseId)
        <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-2 px-4">Code</th>
                    <th class="py-2 px-4">Item</th>
                    <th class="py-2 px-4 text-right">Quantity</th>
                    <th class="py-2 px-4 text-right">Avg. Cost</th>
                    <th class="py-2 px-4 text-right">Value</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    <tr class="text-sm">
                        <td class="py-2 px-4 font-mono">{{ $row['item_code'] }}</td>
                        <td class="py-2 px-4">{{ $row['item_name'] }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['quantity_on_hand'], 3) }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['average_cost'], 4) }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['value'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-4 px-4 text-gray-500">No stock in this warehouse.</td></tr>
                @endforelse
            </tbody>
        </table>
    @else
        <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-2 px-4">Code</th>
                    <th class="py-2 px-4">Item</th>
                    <th class="py-2 px-4 text-right">Total Quantity</th>
                    <th class="py-2 px-4 text-right">Total Value</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($totals as $row)
                    <tr class="text-sm">
                        <td class="py-2 px-4 font-mono">{{ $row['item_code'] }}</td>
                        <td class="py-2 px-4">{{ $row['item_name'] }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['total_quantity'], 3) }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['total_value'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-4 px-4 text-gray-500">No stock recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif
</div>
```

- [ ] **Step 7: Write the failing feature test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Inventory\StockOnHandReport;
use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockOnHandReportTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_it_shows_totals_across_warehouses_by_default(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Widget']);
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', '2026-01-01', $admin->id);

        $this->actingAs($admin);

        Livewire::test(StockOnHandReport::class, ['company' => $company])
            ->assertSee('Widget')
            ->assertSee('500.00');
    }

    public function test_the_stock_on_hand_page_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('inventory.reports.stock-on-hand', $company))
            ->assertOk();
    }
}
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test --filter=StockOnHandReportTest`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add app/Services/Inventory/StockLevelQuery.php app/Livewire/Inventory/StockOnHandReport.php resources/views/livewire/inventory/stock-on-hand-report.blade.php tests/Unit/StockLevelQueryTest.php tests/Feature/StockOnHandReportTest.php
git commit -m "feat: add stock on hand report (per-warehouse and totaled)"
```

---

### Task 11: Item Movement Card Report

**Files:**
- Create: `app/Services/Inventory/ItemMovementCardQuery.php`
- Create: `app/Livewire/Inventory/ItemMovementCardReport.php`
- Create: `resources/views/livewire/inventory/item-movement-card-report.blade.php`
- Test: `tests/Unit/ItemMovementCardQueryTest.php`
- Test: `tests/Feature/ItemMovementCardReportTest.php`

**Interfaces:**
- Produces: `ItemMovementCardQuery::run(Item $item, Warehouse $warehouse, array $filters): Collection` where `$filters = ['from' => Carbon, 'to' => Carbon]` — per Global Constraints, this report is per item **and** per warehouse (matching `stock_levels`' own grain), returning rows with `date`, `type`, `counterpart_warehouse` (the other warehouse involved in a transfer, else null), `quantity` (signed delta), `unit_cost`, `reason`, `running_quantity`.

- [ ] **Step 1: Write the failing unit test**

```php
<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\ItemMovementCardQuery;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ItemMovementCardQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_movements_with_a_running_quantity(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $user = User::factory()->create();
        $service = app(StockMovementService::class);

        $service->receipt($item, $warehouse, '10', '50.00', '2026-01-05', $user->id);
        $service->issue($item, $warehouse, '4', '2026-01-10', $user->id);

        $rows = ItemMovementCardQuery::run($item, $warehouse, [
            'from' => Carbon::parse('2026-01-01'),
            'to' => Carbon::parse('2026-01-31'),
        ]);

        $this->assertCount(2, $rows);
        $this->assertSame(10.0, $rows[0]['quantity']);
        $this->assertSame(10.0, $rows[0]['running_quantity']);
        $this->assertSame(-4.0, $rows[1]['quantity']);
        $this->assertSame(6.0, $rows[1]['running_quantity']);
    }

    public function test_opening_quantity_before_the_range_carries_into_the_running_quantity(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $user = User::factory()->create();
        $service = app(StockMovementService::class);

        $service->receipt($item, $warehouse, '10', '50.00', '2025-12-15', $user->id);
        $service->receipt($item, $warehouse, '5', '50.00', '2026-01-10', $user->id);

        $rows = ItemMovementCardQuery::run($item, $warehouse, [
            'from' => Carbon::parse('2026-01-01'),
            'to' => Carbon::parse('2026-01-31'),
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(15.0, $rows[0]['running_quantity']);
    }

    public function test_a_transfer_shows_as_a_decrease_at_the_source_and_increase_at_the_destination(): void
    {
        $item = Item::factory()->create();
        $warehouseA = Warehouse::factory()->create();
        $warehouseB = Warehouse::factory()->create();
        $user = User::factory()->create();
        $service = app(StockMovementService::class);

        $service->receipt($item, $warehouseA, '10', '50.00', '2026-01-05', $user->id);
        $service->transfer($item, $warehouseA, $warehouseB, '4', '2026-01-10', $user->id);

        $rowsA = ItemMovementCardQuery::run($item, $warehouseA, ['from' => Carbon::parse('2026-01-01'), 'to' => Carbon::parse('2026-01-31')]);
        $rowsB = ItemMovementCardQuery::run($item, $warehouseB, ['from' => Carbon::parse('2026-01-01'), 'to' => Carbon::parse('2026-01-31')]);

        $this->assertSame(6.0, $rowsA[1]['running_quantity']);
        $this->assertSame(4.0, $rowsB[0]['running_quantity']);
        $this->assertSame($warehouseB->name, $rowsA[1]['counterpart_warehouse']);
        $this->assertSame($warehouseA->name, $rowsB[0]['counterpart_warehouse']);
    }

    public function test_the_final_running_quantity_reconciles_with_the_stock_level(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $user = User::factory()->create();
        $service = app(StockMovementService::class);

        $service->receipt($item, $warehouse, '10', '50.00', '2026-01-05', $user->id);
        $service->issue($item, $warehouse, '3', '2026-01-10', $user->id);
        $service->adjustment($item, $warehouse, '-1', 'Damage', '2026-01-15', $user->id);

        $rows = ItemMovementCardQuery::run($item, $warehouse, ['from' => Carbon::parse('2026-01-01'), 'to' => Carbon::parse('2026-01-31')]);
        $level = \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first();

        $this->assertSame((float) $level->quantity_on_hand, $rows->last()['running_quantity']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ItemMovementCardQueryTest`
Expected: FAIL — `Class "App\Services\Inventory\ItemMovementCardQuery" not found`.

- [ ] **Step 3: Write the query service**

```php
<?php

namespace App\Services\Inventory;

use App\Models\Item;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ItemMovementCardQuery
{
    public static function run(Item $item, Warehouse $warehouse, array $filters): Collection
    {
        /** @var Carbon $from */
        $from = $filters['from'];
        /** @var Carbon $to */
        $to = $filters['to'];

        $baseQuery = fn () => StockMovement::query()
            ->where('item_id', $item->id)
            ->where(function ($q) use ($warehouse) {
                $q->where('warehouse_id', $warehouse->id)->orWhere('to_warehouse_id', $warehouse->id);
            });

        $openingQuantity = (clone $baseQuery())
            ->where('movement_date', '<', $from->toDateString())
            ->get()
            ->sum(fn (StockMovement $m) => self::signedDelta($m, $warehouse->id));

        $movements = (clone $baseQuery())
            ->whereBetween('movement_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('movement_date')
            ->orderBy('id')
            ->with(['warehouse', 'toWarehouse'])
            ->get();

        $runningQuantity = $openingQuantity;

        return $movements->map(function (StockMovement $movement) use (&$runningQuantity, $warehouse) {
            $delta = self::signedDelta($movement, $warehouse->id);
            $runningQuantity += $delta;

            return [
                'date' => $movement->movement_date,
                'type' => $movement->type,
                'counterpart_warehouse' => $movement->warehouse_id === $warehouse->id
                    ? $movement->toWarehouse?->name
                    : $movement->warehouse->name,
                'quantity' => $delta,
                'unit_cost' => (float) $movement->unit_cost,
                'reason' => $movement->reason,
                'running_quantity' => $runningQuantity,
            ];
        })->values();
    }

    private static function signedDelta(StockMovement $movement, int $warehouseId): float
    {
        $quantity = (float) $movement->quantity;

        return match ($movement->type) {
            'receipt' => $quantity,
            'issue' => -$quantity,
            'adjustment' => $quantity,
            'transfer' => $movement->to_warehouse_id === $warehouseId ? $quantity : -$quantity,
            default => 0.0,
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ItemMovementCardQueryTest`
Expected: PASS

- [ ] **Step 5: Write the `ItemMovementCardReport` Livewire component**

```php
<?php

namespace App\Livewire\Inventory;

use App\Models\Company;
use App\Models\Item;
use App\Models\Warehouse;
use App\Services\Inventory\ItemMovementCardQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ItemMovementCardReport extends Component
{
    public Company $company;

    public ?int $itemId = null;

    public ?int $warehouseId = null;

    public string $from = '';

    public string $to = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
        $this->from = now()->startOfYear()->toDateString();
        $this->to = now()->toDateString();
    }

    public function render()
    {
        $rows = collect();

        if ($this->itemId && $this->warehouseId) {
            $item = Item::where('company_id', $this->company->id)->findOrFail($this->itemId);
            $warehouse = Warehouse::where('company_id', $this->company->id)->findOrFail($this->warehouseId);

            $rows = ItemMovementCardQuery::run($item, $warehouse, [
                'from' => Carbon::parse($this->from),
                'to' => Carbon::parse($this->to),
            ]);
        }

        return view('livewire.inventory.item-movement-card-report', [
            'rows' => $rows,
            'items' => Item::where('company_id', $this->company->id)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('company_id', $this->company->id)->orderBy('name')->get(),
        ]);
    }
}
```

- [ ] **Step 6: Write the view**

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Item Movement Card — {{ $company->name }}</h1>

    <div class="bg-white shadow rounded-md p-4 mb-4 flex flex-wrap gap-4 items-end">
        <div>
            <x-input-label for="itemId" value="Item" />
            <select id="itemId" wire:model.live="itemId" class="border-gray-300 rounded-md text-sm">
                <option value="">—</option>
                @foreach ($items as $item)
                    <option value="{{ $item->id }}">{{ $item->code }} — {{ $item->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="warehouseId" value="Warehouse" />
            <select id="warehouseId" wire:model.live="warehouseId" class="border-gray-300 rounded-md text-sm">
                <option value="">—</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="from" value="From" />
            <input type="date" id="from" wire:model.live="from" class="border-gray-300 rounded-md text-sm" />
        </div>
        <div>
            <x-input-label for="to" value="To" />
            <input type="date" id="to" wire:model.live="to" class="border-gray-300 rounded-md text-sm" />
        </div>
    </div>

    @if ($itemId && $warehouseId)
        <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-2 px-4">Date</th>
                    <th class="py-2 px-4">Type</th>
                    <th class="py-2 px-4">Counterpart</th>
                    <th class="py-2 px-4 text-right">Quantity</th>
                    <th class="py-2 px-4 text-right">Unit Cost</th>
                    <th class="py-2 px-4 text-right">Running Qty</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    <tr class="text-sm">
                        <td class="py-2 px-4">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d.m.y') }}</td>
                        <td class="py-2 px-4">{{ ucfirst($row['type']) }}{{ $row['reason'] ? ' — '.$row['reason'] : '' }}</td>
                        <td class="py-2 px-4">{{ $row['counterpart_warehouse'] }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['quantity'], 3) }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['unit_cost'], 4) }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['running_quantity'], 3) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-4 px-4 text-gray-500">No movements in this range.</td></tr>
                @endforelse
            </tbody>
        </table>
    @else
        <p class="text-gray-500">Select an item and a warehouse to see the movement card.</p>
    @endif
</div>
```

- [ ] **Step 7: Write the failing feature test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Inventory\ItemMovementCardReport;
use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ItemMovementCardReportTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_selecting_an_item_and_warehouse_shows_its_movements(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Widget']);
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', now()->toDateString(), $admin->id);

        $this->actingAs($admin);

        Livewire::test(ItemMovementCardReport::class, ['company' => $company])
            ->set('itemId', $item->id)
            ->set('warehouseId', $warehouse->id)
            ->assertSee('Receipt');
    }

    public function test_the_movement_card_page_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('inventory.reports.item-movement-card', $company))
            ->assertOk();
    }
}
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test --filter=ItemMovementCardReportTest`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add app/Services/Inventory/ItemMovementCardQuery.php app/Livewire/Inventory/ItemMovementCardReport.php resources/views/livewire/inventory/item-movement-card-report.blade.php tests/Unit/ItemMovementCardQueryTest.php tests/Feature/ItemMovementCardReportTest.php
git commit -m "feat: add item movement card report (per item per warehouse)"
```

---

### Task 12: Stock Valuation Summary Report

**Files:**
- Modify: `app/Services/Inventory/StockLevelQuery.php`
- Create: `app/Livewire/Inventory/StockValuationReport.php`
- Create: `resources/views/livewire/inventory/stock-valuation-report.blade.php`
- Modify: `tests/Unit/StockLevelQueryTest.php`
- Test: `tests/Feature/StockValuationReportTest.php`

**Interfaces:**
- Consumes: `StockLevelQuery` (Task 10).
- Produces: `StockLevelQuery::valuationSummary(Company $company, ?string $groupBy = null): Collection` — `$groupBy` is `null` (single company-wide total), `'warehouse'`, or `'category'`; each row is `['label' => string, 'total_value' => float]`.

- [ ] **Step 1: Add the failing unit tests**

Append to `tests/Unit/StockLevelQueryTest.php`:

```php
    public function test_valuation_summary_with_no_grouping_returns_a_single_total(): void
    {
        $company = Company::factory()->create();
        $itemA = Item::factory()->for($company)->create();
        $itemB = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();
        app(StockMovementService::class)->receipt($itemA, $warehouse, '10', '50.00', '2026-01-01', $user->id);
        app(StockMovementService::class)->receipt($itemB, $warehouse, '4', '25.00', '2026-01-01', $user->id);

        $rows = StockLevelQuery::valuationSummary($company);

        $this->assertCount(1, $rows);
        $this->assertSame('Total', $rows[0]['label']);
        $this->assertSame(600.0, $rows[0]['total_value']);
    }

    public function test_valuation_summary_can_be_grouped_by_warehouse(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouseA = Warehouse::factory()->for($company)->create(['name' => 'Main']);
        $warehouseB = Warehouse::factory()->for($company)->create(['name' => 'Annex']);
        $user = User::factory()->create();
        app(StockMovementService::class)->receipt($item, $warehouseA, '10', '50.00', '2026-01-01', $user->id);
        app(StockMovementService::class)->receipt($item, $warehouseB, '4', '25.00', '2026-01-01', $user->id);

        $rows = StockLevelQuery::valuationSummary($company, 'warehouse')->keyBy('label');

        $this->assertSame(500.0, $rows['Main']['total_value']);
        $this->assertSame(100.0, $rows['Annex']['total_value']);
    }

    public function test_valuation_summary_can_be_grouped_by_category(): void
    {
        $company = Company::factory()->create();
        $itemA = Item::factory()->for($company)->create(['category' => 'Raw materials']);
        $itemB = Item::factory()->for($company)->create(['category' => 'Finished goods']);
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();
        app(StockMovementService::class)->receipt($itemA, $warehouse, '10', '50.00', '2026-01-01', $user->id);
        app(StockMovementService::class)->receipt($itemB, $warehouse, '4', '25.00', '2026-01-01', $user->id);

        $rows = StockLevelQuery::valuationSummary($company, 'category')->keyBy('label');

        $this->assertSame(500.0, $rows['Raw materials']['total_value']);
        $this->assertSame(100.0, $rows['Finished goods']['total_value']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StockLevelQueryTest`
Expected: FAIL — `Call to undefined method App\Services\Inventory\StockLevelQuery::valuationSummary()`.

- [ ] **Step 3: Add the `valuationSummary` method**

Modify `app/Services/Inventory/StockLevelQuery.php` — add this method after `stockOnHandTotals()`:

```php
    public static function valuationSummary(Company $company, ?string $groupBy = null): Collection
    {
        $query = StockLevel::query()
            ->join('items', 'items.id', '=', 'stock_levels.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_levels.warehouse_id')
            ->where('items.company_id', $company->id);

        if ($groupBy === 'warehouse') {
            return $query
                ->selectRaw('warehouses.name as label, SUM(stock_levels.quantity_on_hand * stock_levels.average_cost) as total_value')
                ->groupBy('warehouses.id', 'warehouses.name')
                ->orderBy('warehouses.name')
                ->get()
                ->map(fn ($row) => ['label' => $row->label, 'total_value' => round((float) $row->total_value, 2)])
                ->values();
        }

        if ($groupBy === 'category') {
            return $query
                ->selectRaw("COALESCE(items.category, 'Uncategorized') as label, SUM(stock_levels.quantity_on_hand * stock_levels.average_cost) as total_value")
                ->groupBy('label')
                ->orderBy('label')
                ->get()
                ->map(fn ($row) => ['label' => $row->label, 'total_value' => round((float) $row->total_value, 2)])
                ->values();
        }

        $total = (clone $query)->selectRaw('SUM(stock_levels.quantity_on_hand * stock_levels.average_cost) as total_value')->value('total_value');

        return collect([['label' => 'Total', 'total_value' => round((float) $total, 2)]]);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=StockLevelQueryTest`
Expected: PASS

- [ ] **Step 5: Write the `StockValuationReport` Livewire component**

```php
<?php

namespace App\Livewire\Inventory;

use App\Models\Company;
use App\Services\Inventory\StockLevelQuery;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class StockValuationReport extends Component
{
    public Company $company;

    public string $groupBy = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        return view('livewire.inventory.stock-valuation-report', [
            'rows' => StockLevelQuery::valuationSummary($this->company, $this->groupBy ?: null),
        ]);
    }
}
```

- [ ] **Step 6: Write the view**

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Stock Valuation — {{ $company->name }}</h1>

    <div class="bg-white shadow rounded-md p-4 mb-4 flex flex-wrap gap-4 items-end">
        <div>
            <x-input-label for="groupBy" value="Break down by" />
            <select id="groupBy" wire:model.live="groupBy" class="border-gray-300 rounded-md text-sm">
                <option value="">Total only</option>
                <option value="warehouse">Warehouse</option>
                <option value="category">Category</option>
            </select>
        </div>
    </div>

    <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">{{ $groupBy ? ucfirst($groupBy) : '' }}</th>
                <th class="py-2 px-4 text-right">Total Value</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($rows as $row)
                <tr class="text-sm">
                    <td class="py-2 px-4">{{ $row['label'] }}</td>
                    <td class="py-2 px-4 text-right">{{ number_format($row['total_value'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="py-4 px-4 text-gray-500">No stock recorded yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
```

- [ ] **Step 7: Write the failing feature test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Inventory\StockValuationReport;
use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockValuationReportTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_it_shows_the_company_wide_total_by_default(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', now()->toDateString(), $admin->id);

        $this->actingAs($admin);

        Livewire::test(StockValuationReport::class, ['company' => $company])
            ->assertSee('Total')
            ->assertSee('500.00');
    }

    public function test_the_valuation_page_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('inventory.reports.stock-valuation', $company))
            ->assertOk();
    }
}
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test --filter=StockValuationReportTest`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add app/Services/Inventory/StockLevelQuery.php app/Livewire/Inventory/StockValuationReport.php resources/views/livewire/inventory/stock-valuation-report.blade.php tests/Unit/StockLevelQueryTest.php tests/Feature/StockValuationReportTest.php
git commit -m "feat: add stock valuation summary report grouped by warehouse or category"
```

---

### Task 13: Navigation, Cross-Cutting Policy Tests, and Whole-Module Route Test

**Files:**
- Modify: `resources/views/livewire/layout/navigation.blade.php`
- Test: `tests/Feature/InventoryPoliciesTest.php`
- Test: `tests/Feature/InventoryRoutesTest.php`

**Interfaces:**
- Consumes: every model, policy, and route from Tasks 1–12. This task adds no new production code beyond the navigation link — it's the whole-module wiring and permission-boundary check, mirroring `AccountingPoliciesTest`/`AccountingRoutesTest` from Phase 1.

- [ ] **Step 1: Extend the Companies nav link's active state to cover Inventory**

There's no company-independent Inventory landing page — like Accounting, the flow is: pick a company from the Companies list, then navigate into its Warehouses/Items/Reports screens. A second nav link pointing at the same `companies.index` URL would be a redundant, confusing duplicate, so instead extend the existing single link's active-state check, the same way Phase 1 added `accounting.*` to it.

Modify `resources/views/livewire/layout/navigation.blade.php` — change:

```blade
                    <x-nav-link :href="route('companies.index')" :active="request()->routeIs('companies.*') || request()->routeIs('accounting.*')">
                        {{ __('Companies') }}
                    </x-nav-link>
```

to:

```blade
                    <x-nav-link :href="route('companies.index')" :active="request()->routeIs('companies.*') || request()->routeIs('accounting.*') || request()->routeIs('inventory.*')">
                        {{ __('Companies') }}
                    </x-nav-link>
```

- [ ] **Step 2: Write the failing cross-cutting policy test**

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventoryPoliciesTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_client_can_manage_their_own_companys_warehouses_and_items(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();

        $this->assertTrue($client->can('update', $warehouse));
        $this->assertTrue($client->can('update', $item));
        $this->assertTrue($client->can('create', Warehouse::class));
        $this->assertTrue($client->can('create', Item::class));
        $this->assertTrue($client->can('create', StockMovement::class));
    }

    public function test_client_cannot_manage_another_companys_warehouses_or_items(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $warehouse = Warehouse::factory()->for($otherCompany)->create();
        $item = Item::factory()->for($otherCompany)->create();

        $this->assertFalse($client->can('view', $warehouse));
        $this->assertFalse($client->can('view', $item));
        $this->assertFalse($client->can('update', $warehouse));
        $this->assertFalse($client->can('update', $item));
    }

    public function test_accountant_not_assigned_to_a_company_cannot_view_its_inventory(): void
    {
        $companyTheyManage = Company::factory()->create();
        $companyTheyDoNotManage = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($companyTheyManage);

        $warehouse = Warehouse::factory()->for($companyTheyDoNotManage)->create();
        $item = Item::factory()->for($companyTheyDoNotManage)->create();

        $this->assertFalse($accountant->can('view', $warehouse));
        $this->assertFalse($accountant->can('view', $item));
    }

    public function test_admin_can_manage_inventory_for_any_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();

        $this->assertTrue($admin->can('update', $warehouse));
        $this->assertTrue($admin->can('update', $item));
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=InventoryPoliciesTest`
Expected: at this point every assertion should already PASS, since all policies were written in earlier tasks — this test's purpose is to catch cross-cutting regressions, not to drive new code. If anything fails here, it means an earlier task's policy has a bug; fix that policy now rather than changing this test.

- [ ] **Step 4: Write the whole-module route test**

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventoryRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_all_inventory_routes_render_successfully_for_an_admin(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create();
        Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('inventory.warehouses.index', $company))->assertOk();
        $this->get(route('inventory.items.index', $company))->assertOk();
        $this->get(route('inventory.stock-movements.create', [$company, 'receipt']))->assertOk();
        $this->get(route('inventory.stock-movements.create', [$company, 'issue']))->assertOk();
        $this->get(route('inventory.stock-movements.create', [$company, 'transfer']))->assertOk();
        $this->get(route('inventory.stock-movements.create', [$company, 'adjustment']))->assertOk();
        $this->get(route('inventory.reports.stock-on-hand', $company))->assertOk();
        $this->get(route('inventory.reports.item-movement-card', $company))->assertOk();
        $this->get(route('inventory.reports.stock-valuation', $company))->assertOk();
    }

    public function test_inventory_routes_require_authentication(): void
    {
        $company = Company::factory()->create();

        $this->get(route('inventory.warehouses.index', $company))->assertRedirect(route('login'));
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=InventoryRoutesTest`
Expected: PASS

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test`
Expected: PASS — every test from Tasks 1–13 green, no regressions in Phase 0/1 tests.

- [ ] **Step 7: Commit**

```bash
git add resources/views/livewire/layout/navigation.blade.php tests/Feature/InventoryPoliciesTest.php tests/Feature/InventoryRoutesTest.php
git commit -m "feat: add Inventory navigation link and whole-module policy/route tests"
```
