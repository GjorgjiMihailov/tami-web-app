# Items — Bulk Import + New Fields Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add four new fields to `Item` (selling price, MK-made flag, product/service type, barcode), give Items an edit UI (none exists today), auto-fill invoice line prices and barcode-scanner lookups from the new fields, and let a company bulk-add/bulk-update its items by uploading an Excel/CSV spreadsheet with a preview-then-commit flow.

**Architecture:** One migration adds the four columns to `items`. `ItemIndex` gains an inline "Уреди" edit row (mirrors `JournalGroupIndex`'s `editingGroupId` pattern) plus new list columns. `StockMovementService` gains a service-type guard reused by both `StockMovementForm` and the two invoice services; `StockMovementForm` and `PurchaseInvoiceForm` exclude service-type items from their item pickers entirely (a purchase/movement can't "receive stock" of a service). `SalesInvoiceService` is the only place a service-type item is invoiceable — it already special-cases `item_id === null` lines, and gains a parallel case for `item->type === 'service'`. Bulk import is a new `ItemBulkImport` Livewire page: a plain, Livewire-independent `ItemImportParser` class turns raw spreadsheet rows into validated/erroring row descriptors (unit-tested with plain arrays, no Excel I/O involved), and the Livewire component wires file upload → parser → preview table → transactional commit.

**Tech Stack:** Laravel 13.20 + Livewire 3, SQLite for local/test, MySQL in CI/production, new dependency `maatwebsite/excel` ^3.1 (confirmed installable against this app via `composer require --dry-run`; pulls in `phpoffice/phpspreadsheet` 1.30.6, no conflicts).

## Global Constraints

- All user-facing UI text is Macedonian (Cyrillic) — no English strings in views, labels, error messages, or the spreadsheet template's headings.
- Field labels for validation errors go in `lang/mk/validation.php`'s `attributes` array (existing convention: `'newCode' => 'шифра'`, etc.) — add an entry for every new Livewire public property this plan introduces.
- Money/enum display goes through `App\Support\Format` (`money()`, and one `match`-based mapper per enum, e.g. `movementType()`, `vatTreatment()`) — never hand-format or inline a Macedonian label `match` in a Blade view.
- Livewire 3's `wire:model` (without `.live`) is deferred until the next network round-trip. This plan's forms have no field whose change must instantly reveal/hide another field in the same render, so plain `wire:model` is correct throughout — do not add `.live` speculatively (a real bug from the Journal Entry overhaul was the opposite mistake, but adding needless reactivity isn't free either and isn't what this plan needs).
- **The bulk-import "blank cell" rule, load-bearing for every parser test in Task 7:** on a row that will *create* a new item, a blank cell in an optional/defaulted column (ДДВ стапка, Продажна цена, Тип, МК-производство, Категорија, Баркод) takes that column's normal default (ДДВ 18.00, Тип "производ", МК-производство "Не") or stays `null` (Категорија/Продажна цена/Баркод). On a row that will *update* an existing item (matched by шифра), a blank cell in any of those same columns means **leave that column unchanged** — it is never silently reset. Шифра, Назив, and Мерна единица (for new rows only) are always required regardless of new/update. This is the safest interpretation for a live accounting app (a blank ДДВ cell must never silently reset a custom VAT rate), and is called out here because no single task's tests would otherwise make this rule visible as a deliberate choice rather than an oversight.
- Service-type items (`type = 'service'`) never appear in the item picker on `StockMovementForm` or `PurchaseInvoiceForm`, and never participate in `stock_movements`/`stock_levels`. They remain fully selectable on `SalesInvoiceForm` — invoicing a service just skips the automatic stock-issue step that a product-type item line triggers.
- `selling_price` on `Item` is VAT-**exclusive** (matches `SalesInvoiceLine::unit_price`, which is likewise the pre-VAT base amount) — never treat it as VAT-inclusive anywhere.
- The local/CI test suite runs on SQLite; MySQL-specific constraints (identifier length ≤ 64 chars, every FK/unique needs an explicit name once compound) have bitten this project three times already (Phase 3b, Journal Entry overhaul). This plan's new `items_company_barcode_unique` index is given an explicit name proactively even though the auto-generated name would fit under 64 chars — cheap insurance, matches the established convention.
- Run the full suite (`php artisan test`) at the end of every task, not just the task's own filtered tests — several of this project's real regressions (see `tami-web-app-project` memory, e.g. the morph-map break in Phase 4a) were only caught by a full run.

---

### Task 1: Schema + `Item` model + factory

**Files:**
- Create: `database/migrations/2026_07_29_100000_add_new_fields_to_items_table.php`
- Modify: `app/Models/Item.php`
- Modify: `database/factories/ItemFactory.php`
- Test: `tests/Unit/ItemTest.php`

**Interfaces:**
- Produces: `items.selling_price` (nullable decimal(12,2)), `items.type` (string, default `'product'`), `items.is_made_in_mk` (boolean, default `false`), `items.barcode` (nullable string(50), unique per `company_id`). `Item::TYPES = ['product', 'service']`. `Item::isService(): bool`. `ItemFactory::service()` state.

- [ ] **Step 1: Write the failing unit tests**

Append to `tests/Unit/ItemTest.php` (inside the existing `ItemTest` class):

```php
    public function test_new_items_default_to_product_type_with_no_price_or_barcode(): void
    {
        $item = Item::factory()->create();

        $this->assertSame('product', $item->type);
        $this->assertFalse($item->is_made_in_mk);
        $this->assertNull($item->selling_price);
        $this->assertNull($item->barcode);
        $this->assertFalse($item->isService());
    }

    public function test_service_factory_state_sets_type_to_service(): void
    {
        $item = Item::factory()->service()->create();

        $this->assertSame('service', $item->type);
        $this->assertTrue($item->isService());
    }

    public function test_item_stores_selling_price_and_made_in_mk_flag(): void
    {
        $item = Item::factory()->create(['selling_price' => '199.99', 'is_made_in_mk' => true]);

        $this->assertSame('199.99', (string) $item->selling_price);
        $this->assertTrue($item->is_made_in_mk);
    }

    public function test_barcode_is_unique_per_company(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['barcode' => '3800000000017']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Item::factory()->for($company)->create(['barcode' => '3800000000017']);
    }

    public function test_barcode_can_repeat_across_different_companies(): void
    {
        Item::factory()->create(['barcode' => '3800000000017']);
        $item = Item::factory()->create(['barcode' => '3800000000017']);

        $this->assertSame('3800000000017', $item->barcode);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=ItemTest`
Expected: FAIL (unknown column `type` / `selling_price` / `is_made_in_mk` / `barcode`; call to undefined method `Item::isService()` / `ItemFactory::service()`)

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_29_100000_add_new_fields_to_items_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->decimal('selling_price', 12, 2)->nullable()->after('vat_rate');
            $table->string('type', 20)->default('product')->after('selling_price');
            $table->boolean('is_made_in_mk')->default(false)->after('type');
            $table->string('barcode', 50)->nullable()->after('is_made_in_mk');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->unique(['company_id', 'barcode'], 'items_company_barcode_unique');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropUnique('items_company_barcode_unique');
            $table->dropColumn(['selling_price', 'type', 'is_made_in_mk', 'barcode']);
        });
    }
};
```

- [ ] **Step 4: Update the `Item` model**

Replace the contents of `app/Models/Item.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Item extends Model
{
    use HasFactory;

    public const TYPES = ['product', 'service'];

    protected $fillable = [
        'company_id', 'code', 'name', 'unit_of_measure', 'category',
        'vat_rate', 'preferred_partner_id', 'is_active',
        'selling_price', 'type', 'is_made_in_mk', 'barcode',
    ];

    protected function casts(): array
    {
        return [
            'vat_rate' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_made_in_mk' => 'boolean',
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

    public function isService(): bool
    {
        return $this->type === 'service';
    }
}
```

- [ ] **Step 5: Update the factory**

Replace the contents of `database/factories/ItemFactory.php`:

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
            'selling_price' => null,
            'type' => 'product',
            'is_made_in_mk' => false,
            'barcode' => null,
        ];
    }

    public function service(): Factory
    {
        return $this->state(fn () => ['type' => 'service']);
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=ItemTest`
Expected: PASS (all tests including the 4 new ones)

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: PASS (no regressions — nothing else references `Item::$fillable`/`casts()` in a way the new nullable/defaulted columns could break)

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_29_100000_add_new_fields_to_items_table.php app/Models/Item.php database/factories/ItemFactory.php tests/Unit/ItemTest.php
git commit -m "feat: add selling_price, type, is_made_in_mk, barcode to items"
```

---

### Task 2: `Format::itemType` + Item edit form + new list columns

**Files:**
- Modify: `app/Support/Format.php`
- Modify: `app/Livewire/Inventory/ItemIndex.php`
- Modify: `resources/views/livewire/inventory/item-index.blade.php`
- Modify: `lang/mk/validation.php`
- Test: `tests/Unit/FormatTest.php`
- Test: `tests/Feature/ItemIndexTest.php`

**Interfaces:**
- Consumes: `Item::TYPES` (Task 1).
- Produces: `Format::itemType(string $type): string`. `ItemIndex` public properties `editingItemId` (`?int`), `editCode`, `editName`, `editUnitOfMeasure`, `editCategory`, `editVatRate`, `editPreferredPartnerId`, `editSellingPrice`, `editType`, `editBarcode` (all `string`), `editIsMadeInMk` (`bool`). Methods `startEditingItem(int $itemId): void`, `cancelEditingItem(): void`, `updateItem(int $itemId): void`. The quick-add form also gains `newSellingPrice`, `newType`, `newIsMadeInMk`, `newBarcode` matching the same names/types.

- [ ] **Step 1: Write the failing `Format` test**

Append to `tests/Unit/FormatTest.php` (inside the existing class):

```php
    public function test_item_type_maps_to_macedonian_labels(): void
    {
        $this->assertSame('Производ', Format::itemType('product'));
        $this->assertSame('Услуга', Format::itemType('service'));
    }
```

- [ ] **Step 2: Write the failing `ItemIndex` tests**

Append to `tests/Feature/ItemIndexTest.php` (inside the existing class):

```php
    public function test_add_item_form_accepts_the_new_fields(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->set('newCode', 'SKU-200')
            ->set('newName', 'Service Item')
            ->set('newUnitOfMeasure', 'hour')
            ->set('newSellingPrice', '150.00')
            ->set('newType', 'service')
            ->set('newIsMadeInMk', true)
            ->set('newBarcode', '3800000000024')
            ->call('addItem')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('items', [
            'company_id' => $company->id,
            'code' => 'SKU-200',
            'selling_price' => '150.00',
            'type' => 'service',
            'is_made_in_mk' => true,
            'barcode' => '3800000000024',
        ]);
    }

    public function test_a_duplicate_barcode_is_rejected_on_add(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['barcode' => '3800000000017']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->set('newCode', 'SKU-201')
            ->set('newName', 'Widget')
            ->set('newUnitOfMeasure', 'piece')
            ->set('newBarcode', '3800000000017')
            ->call('addItem')
            ->assertHasErrors(['newBarcode']);
    }

    public function test_editing_an_item_updates_all_fields(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Old Name', 'type' => 'product']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->call('startEditingItem', $item->id)
            ->assertSet('editName', 'Old Name')
            ->set('editName', 'New Name')
            ->set('editSellingPrice', '75.50')
            ->set('editType', 'service')
            ->set('editIsMadeInMk', true)
            ->set('editBarcode', '3800000000031')
            ->call('updateItem', $item->id)
            ->assertHasNoErrors();

        $item->refresh();
        $this->assertSame('New Name', $item->name);
        $this->assertSame('75.50', (string) $item->selling_price);
        $this->assertSame('service', $item->type);
        $this->assertTrue($item->is_made_in_mk);
        $this->assertSame('3800000000031', $item->barcode);
    }

    public function test_a_client_can_edit_their_own_companys_item(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->call('startEditingItem', $item->id)
            ->set('editName', 'Renamed by client')
            ->call('updateItem', $item->id)
            ->assertHasNoErrors();

        $this->assertSame('Renamed by client', $item->fresh()->name);
    }

    public function test_the_list_shows_type_and_made_in_mk_columns(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['name' => 'Service X', 'type' => 'service', 'is_made_in_mk' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(ItemIndex::class, ['company' => $company])
            ->assertSee('Услуга')
            ->assertSee('Да');
    }
```

- [ ] **Step 3: Run the new tests to verify they fail**

Run: `php artisan test --filter=FormatTest`
Expected: FAIL (undefined method `Format::itemType()`)

Run: `php artisan test --filter=ItemIndexTest`
Expected: FAIL (unknown properties `newSellingPrice` etc., undefined method `startEditingItem`)

- [ ] **Step 4: Add `Format::itemType()`**

In `app/Support/Format.php`, add this method after `partnerType()` (before the closing `}` of the class):

```php
    public static function itemType(string $type): string
    {
        return match ($type) {
            'product' => 'Производ',
            'service' => 'Услуга',
            default => ucfirst($type),
        };
    }
```

- [ ] **Step 5: Rewrite `ItemIndex`**

Replace the contents of `app/Livewire/Inventory/ItemIndex.php`:

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

    public string $newSellingPrice = '';

    public string $newType = 'product';

    public bool $newIsMadeInMk = false;

    public string $newBarcode = '';

    public ?int $editingItemId = null;

    public string $editCode = '';

    public string $editName = '';

    public string $editUnitOfMeasure = '';

    public string $editCategory = '';

    public string $editVatRate = '';

    public string $editPreferredPartnerId = '';

    public string $editSellingPrice = '';

    public string $editType = 'product';

    public bool $editIsMadeInMk = false;

    public string $editBarcode = '';

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
            'newSellingPrice' => 'nullable|numeric|min:0',
            'newType' => ['required', Rule::in(Item::TYPES)],
            'newIsMadeInMk' => 'boolean',
            'newBarcode' => ['nullable', 'string', 'max:50', Rule::unique('items', 'barcode')->where('company_id', $this->company->id)],
        ]);

        Item::create([
            'company_id' => $this->company->id,
            'code' => $validated['newCode'],
            'name' => $validated['newName'],
            'unit_of_measure' => $validated['newUnitOfMeasure'],
            'category' => $validated['newCategory'] ?: null,
            'vat_rate' => $validated['newVatRate'],
            'preferred_partner_id' => $validated['newPreferredPartnerId'] ?: null,
            'selling_price' => $validated['newSellingPrice'] ?: null,
            'type' => $validated['newType'],
            'is_made_in_mk' => $validated['newIsMadeInMk'],
            'barcode' => $validated['newBarcode'] ?: null,
            'is_active' => true,
        ]);

        $this->reset(['newCode', 'newName', 'newCategory', 'newPreferredPartnerId', 'newSellingPrice', 'newIsMadeInMk', 'newBarcode']);
        $this->newUnitOfMeasure = 'piece';
        $this->newVatRate = '18.00';
        $this->newType = 'product';
    }

    public function toggleActive(int $itemId): void
    {
        $item = Item::where('company_id', $this->company->id)->findOrFail($itemId);
        Gate::authorize('update', $item);

        $item->update(['is_active' => ! $item->is_active]);
    }

    public function startEditingItem(int $itemId): void
    {
        $item = Item::where('company_id', $this->company->id)->findOrFail($itemId);
        Gate::authorize('update', $item);

        $this->editingItemId = $itemId;
        $this->editCode = $item->code;
        $this->editName = $item->name;
        $this->editUnitOfMeasure = $item->unit_of_measure;
        $this->editCategory = (string) $item->category;
        $this->editVatRate = (string) $item->vat_rate;
        $this->editPreferredPartnerId = (string) $item->preferred_partner_id;
        $this->editSellingPrice = (string) $item->selling_price;
        $this->editType = $item->type;
        $this->editIsMadeInMk = $item->is_made_in_mk;
        $this->editBarcode = (string) $item->barcode;
    }

    public function cancelEditingItem(): void
    {
        $this->editingItemId = null;
    }

    public function updateItem(int $itemId): void
    {
        $item = Item::where('company_id', $this->company->id)->findOrFail($itemId);
        Gate::authorize('update', $item);

        $validated = $this->validate([
            'editCode' => ['required', 'string', 'max:50', Rule::unique('items', 'code')->where('company_id', $this->company->id)->ignore($item->id)],
            'editName' => 'required|string|max:255',
            'editUnitOfMeasure' => 'required|string|max:20',
            'editCategory' => 'nullable|string|max:255',
            'editVatRate' => 'required|numeric|min:0|max:100',
            'editPreferredPartnerId' => ['nullable', Rule::exists('partners', 'id')->where('company_id', $this->company->id)],
            'editSellingPrice' => 'nullable|numeric|min:0',
            'editType' => ['required', Rule::in(Item::TYPES)],
            'editIsMadeInMk' => 'boolean',
            'editBarcode' => ['nullable', 'string', 'max:50', Rule::unique('items', 'barcode')->where('company_id', $this->company->id)->ignore($item->id)],
        ]);

        $item->update([
            'code' => $validated['editCode'],
            'name' => $validated['editName'],
            'unit_of_measure' => $validated['editUnitOfMeasure'],
            'category' => $validated['editCategory'] ?: null,
            'vat_rate' => $validated['editVatRate'],
            'preferred_partner_id' => $validated['editPreferredPartnerId'] ?: null,
            'selling_price' => $validated['editSellingPrice'] ?: null,
            'type' => $validated['editType'],
            'is_made_in_mk' => $validated['editIsMadeInMk'],
            'barcode' => $validated['editBarcode'] ?: null,
        ]);

        $this->editingItemId = null;
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

- [ ] **Step 6: Rewrite the Item index view**

Replace the contents of `resources/views/livewire/inventory/item-index.blade.php`:

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Артикли — {{ $company->name }}</h1>

    <div class="mb-4">
        <a href="{{ route('inventory.items.bulk-import', $company) }}" wire:navigate class="text-brand text-sm hover:underline">
            Масовен внес преку табела
        </a>
    </div>

    @can('create', \App\Models\Item::class)
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Додади артикл</h2>
            <form wire:submit="addItem" class="flex flex-wrap gap-3 items-end">
                <div>
                    <x-input-label for="newCode" value="Шифра" />
                    <x-text-input id="newCode" wire:model="newCode" class="w-40" />
                    @error('newCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="flex-1 min-w-[12rem]">
                    <x-input-label for="newName" value="Назив" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newUnitOfMeasure" value="Мерна единица" />
                    <x-text-input id="newUnitOfMeasure" wire:model="newUnitOfMeasure" class="w-24" />
                </div>
                <div>
                    <x-input-label for="newCategory" value="Категорија" />
                    <x-text-input id="newCategory" wire:model="newCategory" class="w-32" />
                </div>
                <div>
                    <x-input-label for="newVatRate" value="Стапка на ДДВ" />
                    <x-text-input id="newVatRate" wire:model="newVatRate" class="w-20" />
                </div>
                <div>
                    <x-input-label for="newSellingPrice" value="Продажна цена" />
                    <x-text-input id="newSellingPrice" wire:model="newSellingPrice" class="w-24" />
                    @error('newSellingPrice') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newType" value="Тип" />
                    <select id="newType" wire:model="newType" class="border-gray-300 rounded-md text-sm">
                        <option value="product">Производ</option>
                        <option value="service">Услуга</option>
                    </select>
                </div>
                <div class="flex items-center gap-2 pb-2">
                    <input type="checkbox" id="newIsMadeInMk" wire:model="newIsMadeInMk">
                    <label for="newIsMadeInMk" class="text-sm">МК-производство</label>
                </div>
                <div>
                    <x-input-label for="newBarcode" value="Баркод" />
                    <x-text-input id="newBarcode" wire:model="newBarcode" class="w-32" />
                    @error('newBarcode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newPreferredPartnerId" value="Основен добавувач" />
                    <select id="newPreferredPartnerId" wire:model="newPreferredPartnerId" class="border-gray-300 rounded-md text-sm">
                        <option value="">—</option>
                        @foreach ($partners as $partner)
                            <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                        @endforeach
                    </select>
                </div>
                <x-primary-button type="submit">Додади</x-primary-button>
            </form>
        </x-card>
    @endcan

    <div class="mb-4">
        <x-text-input wire:model.live="search" placeholder="Пребарувај по назив или шифра" class="w-full max-w-sm" />
    </div>

    <x-card padding="p-0" class="overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
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
                    <td class="py-2 px-4 font-mono">{{ $item->code }}</td>
                    <td class="py-2 px-4">{{ $item->name }}</td>
                    <td class="py-2 px-4">{{ $item->unit_of_measure }}</td>
                    <td class="py-2 px-4">{{ $item->category }}</td>
                    <td class="py-2 px-4">{{ $item->vat_rate }}</td>
                    <td class="py-2 px-4">{{ $item->selling_price !== null ? \App\Support\Format::money($item->selling_price) : '—' }}</td>
                    <td class="py-2 px-4">{{ \App\Support\Format::itemType($item->type) }}</td>
                    <td class="py-2 px-4">{{ $item->is_made_in_mk ? 'Да' : 'Не' }}</td>
                    <td class="py-2 px-4 font-mono">{{ $item->barcode }}</td>
                    <td class="py-2 px-4">{{ $item->is_active ? 'Да' : 'Не' }}</td>
                    <td class="py-2 px-4 whitespace-nowrap">
                        @can('update', $item)
                            <button type="button" wire:click="startEditingItem({{ $item->id }})" class="text-brand hover:underline text-sm mr-3">Уреди</button>
                            <button type="button" wire:click="toggleActive({{ $item->id }})" class="text-brand hover:underline text-sm">
                                {{ $item->is_active ? 'Деактивирај' : 'Активирај' }}
                            </button>
                        @endcan
                    </td>
                </tr>
                @if ($editingItemId === $item->id)
                    <tr wire:key="item-edit-{{ $item->id }}">
                        <td colspan="11" class="p-4 bg-gray-50">
                            <form wire:submit="updateItem({{ $item->id }})" class="flex flex-wrap gap-3 items-end">
                                <div>
                                    <x-input-label for="editCode" value="Шифра" />
                                    <x-text-input id="editCode" wire:model="editCode" class="w-40" />
                                    @error('editCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                                </div>
                                <div class="flex-1 min-w-[12rem]">
                                    <x-input-label for="editName" value="Назив" />
                                    <x-text-input id="editName" wire:model="editName" class="w-full" />
                                    @error('editName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <x-input-label for="editUnitOfMeasure" value="Мерна единица" />
                                    <x-text-input id="editUnitOfMeasure" wire:model="editUnitOfMeasure" class="w-24" />
                                </div>
                                <div>
                                    <x-input-label for="editCategory" value="Категорија" />
                                    <x-text-input id="editCategory" wire:model="editCategory" class="w-32" />
                                </div>
                                <div>
                                    <x-input-label for="editVatRate" value="Стапка на ДДВ" />
                                    <x-text-input id="editVatRate" wire:model="editVatRate" class="w-20" />
                                </div>
                                <div>
                                    <x-input-label for="editSellingPrice" value="Продажна цена" />
                                    <x-text-input id="editSellingPrice" wire:model="editSellingPrice" class="w-24" />
                                    @error('editSellingPrice') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <x-input-label for="editType" value="Тип" />
                                    <select id="editType" wire:model="editType" class="border-gray-300 rounded-md text-sm">
                                        <option value="product">Производ</option>
                                        <option value="service">Услуга</option>
                                    </select>
                                </div>
                                <div class="flex items-center gap-2 pb-2">
                                    <input type="checkbox" id="editIsMadeInMk" wire:model="editIsMadeInMk">
                                    <label for="editIsMadeInMk" class="text-sm">МК-производство</label>
                                </div>
                                <div>
                                    <x-input-label for="editBarcode" value="Баркод" />
                                    <x-text-input id="editBarcode" wire:model="editBarcode" class="w-32" />
                                    @error('editBarcode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <x-input-label for="editPreferredPartnerId" value="Основен добавувач" />
                                    <select id="editPreferredPartnerId" wire:model="editPreferredPartnerId" class="border-gray-300 rounded-md text-sm">
                                        <option value="">—</option>
                                        @foreach ($partners as $partner)
                                            <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <x-primary-button type="submit">Зачувај</x-primary-button>
                                <button type="button" wire:click="cancelEditingItem" class="text-gray-500 text-sm hover:underline">Откажи</button>
                            </form>
                        </td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="11" class="py-4 px-4 text-gray-500">Нема додадено артикли.</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>
</div>
```

- [ ] **Step 7: Add Macedonian attribute labels**

In `lang/mk/validation.php`, inside the `'attributes' => [ ... ]` array, add these lines right after the existing `'newCode' => 'шифра',` line:

```php
        'newSellingPrice' => 'продажна цена',
        'newType' => 'тип',
        'newBarcode' => 'баркод',
        'editCode' => 'шифра',
        'editName' => 'назив',
        'editUnitOfMeasure' => 'мерна единица',
        'editCategory' => 'категорија',
        'editVatRate' => 'стапка на ДДВ',
        'editPreferredPartnerId' => 'основен добавувач',
        'editSellingPrice' => 'продажна цена',
        'editType' => 'тип',
        'editBarcode' => 'баркод',
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --filter=FormatTest`
Expected: PASS

Run: `php artisan test --filter=ItemIndexTest`
Expected: PASS (all tests, including the 5 new ones — note the link to `inventory.items.bulk-import` in the view will 404 until Task 6 registers that route; the test suite never navigates through it, so this is safe to leave for now)

- [ ] **Step 9: Run the full suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add app/Support/Format.php app/Livewire/Inventory/ItemIndex.php resources/views/livewire/inventory/item-index.blade.php lang/mk/validation.php tests/Unit/FormatTest.php tests/Feature/ItemIndexTest.php
git commit -m "feat: add item edit form and new list columns for type/MK-made/barcode/price"
```

---

### Task 3: Exclude service-type items from stock-receiving contexts

**Files:**
- Modify: `app/Services/Inventory/StockMovementService.php`
- Modify: `app/Livewire/Inventory/StockMovementForm.php`
- Modify: `app/Livewire/Invoicing/PurchaseInvoiceForm.php`
- Test: `tests/Unit/StockMovementServiceTest.php`
- Test: `tests/Feature/StockMovementFormTest.php`
- Test: `tests/Feature/PurchaseInvoiceFormTest.php`

**Interfaces:**
- Consumes: `Item::isService()` (Task 1).
- Produces: `StockMovementService::receipt/issue/transfer/adjustment()` all throw `\InvalidArgumentException` (uncaught, same convention as the existing `assertSameCompany()` guard) when given a service-type item. `StockMovementForm`'s and `PurchaseInvoiceForm`'s item dropdowns and `itemId`/`item_id` validation rules exclude service-type items.

- [ ] **Step 1: Write the failing unit test for the service guard**

Append to `tests/Unit/StockMovementServiceTest.php` (inside the existing class):

```php
    public function test_receipt_rejects_a_service_type_item(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->service()->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->receipt($item, $warehouse, '10', '100.00', '2026-01-10', $user->id);
    }

    public function test_issue_rejects_a_service_type_item(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->service()->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->issue($item, $warehouse, '1', '2026-01-10', $user->id);
    }
```

- [ ] **Step 2: Write the failing feature tests for the two forms**

Append to `tests/Feature/StockMovementFormTest.php` (inside the existing class — check its `setUp()`/imports first and match the existing style):

```php
    public function test_a_service_type_item_does_not_appear_in_the_item_picker(): void
    {
        $company = Company::factory()->create();
        $product = Item::factory()->for($company)->create(['name' => 'Physical Widget']);
        Item::factory()->for($company)->service()->create(['name' => 'Consulting Hour']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'receipt'])
            ->assertSee('Physical Widget')
            ->assertDontSee('Consulting Hour');
    }

    public function test_submitting_a_service_type_item_id_directly_is_rejected(): void
    {
        $company = Company::factory()->create();
        $service = Item::factory()->for($company)->service()->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'receipt'])
            ->set('itemId', (string) $service->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('quantity', '1')
            ->set('unitCost', '10.00')
            ->set('movementDate', '2026-01-10')
            ->call('save')
            ->assertHasErrors(['itemId']);
    }
```

Append to `tests/Feature/PurchaseInvoiceFormTest.php` (inside the existing class):

```php
    public function test_a_service_type_item_does_not_appear_in_the_item_picker(): void
    {
        $company = Company::factory()->create();
        $product = Item::factory()->for($company)->create(['name' => 'Physical Widget']);
        Item::factory()->for($company)->service()->create(['name' => 'Consulting Hour']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->assertSee('Physical Widget')
            ->assertDontSee('Consulting Hour');
    }

    public function test_a_service_type_item_id_is_rejected_on_a_purchase_invoice_line(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $service = Item::factory()->for($company)->service()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', 'SUP-2026-050')
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.item_id', (string) $service->id)
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '10.00')
            ->set('lines.0.vat_rate', '18.00')
            ->call('save')
            ->assertHasErrors(['lines.0.item_id']);
    }
```

- [ ] **Step 3: Run the new tests to verify they fail**

Run: `php artisan test --filter=StockMovementServiceTest`
Expected: FAIL (no exception thrown for service-type items yet)

Run: `php artisan test --filter=StockMovementFormTest`
Expected: FAIL (service item still appears in the picker / no validation error)

Run: `php artisan test --filter=PurchaseInvoiceFormTest`
Expected: FAIL (same reasons)

- [ ] **Step 4: Add the guard to `StockMovementService`**

In `app/Services/Inventory/StockMovementService.php`, add this private method right after `assertSameCompany()`:

```php
    private function assertNotService(Item $item): void
    {
        if ($item->isService()) {
            throw new \InvalidArgumentException("Артикл #{$item->id} е услуга и не учествува во движења на залиха.");
        }
    }
```

Then add a call to `$this->assertNotService($item);` as the first line inside each of the four public methods (`receipt`, `issue`, `transfer`, `adjustment`), immediately before their existing `$this->assertSameCompany(...)` call(s). For example, `receipt()` becomes:

```php
    public function receipt(Item $item, Warehouse $warehouse, string $quantity, string $unitCost, string $movementDate, int $createdBy): StockMovement
    {
        $this->assertNotService($item);
        $this->assertSameCompany($item, $warehouse);
```

Apply the same one-line addition to `issue()`, `transfer()` (once, before its two `assertSameCompany()` calls), and `adjustment()`.

- [ ] **Step 5: Filter the item dropdown and validation in `StockMovementForm`**

In `app/Livewire/Inventory/StockMovementForm.php`, update the `save()` method's rules array:

```php
            'itemId' => ['required', Rule::exists('items', 'id')->where('company_id', $this->company->id)->where('type', 'product')],
```

And update `render()`'s items query:

```php
            'items' => Item::where('company_id', $this->company->id)->where('is_active', true)->where('type', 'product')->orderBy('name')->get(),
```

- [ ] **Step 6: Filter the item dropdown and validation in `PurchaseInvoiceForm`**

In `app/Livewire/Invoicing/PurchaseInvoiceForm.php`, update the validation rule:

```php
            'lines.*.item_id' => ['nullable', Rule::exists('items', 'id')->where('company_id', $this->company->id)->where('type', 'product')],
```

And update `render()`'s items query:

```php
            'items' => Item::where('company_id', $this->company->id)->where('is_active', true)->where('type', 'product')->orderBy('name')->get(),
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --filter=StockMovementServiceTest`
Expected: PASS

Run: `php artisan test --filter=StockMovementFormTest`
Expected: PASS

Run: `php artisan test --filter=PurchaseInvoiceFormTest`
Expected: PASS

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add app/Services/Inventory/StockMovementService.php app/Livewire/Inventory/StockMovementForm.php app/Livewire/Invoicing/PurchaseInvoiceForm.php tests/Unit/StockMovementServiceTest.php tests/Feature/StockMovementFormTest.php tests/Feature/PurchaseInvoiceFormTest.php
git commit -m "feat: exclude service-type items from stock movements and purchase invoice lines"
```

---

### Task 4: Sales invoice — skip stock issuance for service-type items + auto-fill selling price

**Files:**
- Modify: `app/Services/Invoicing/SalesInvoiceService.php`
- Modify: `app/Livewire/Invoicing/SalesInvoiceForm.php`
- Test: `tests/Unit/SalesInvoiceServiceTest.php`
- Test: `tests/Feature/SalesInvoiceFormTest.php`

**Interfaces:**
- Consumes: `Item::isService()`, `Item::selling_price` (Task 1).
- Produces: `SalesInvoiceService::confirm()` no longer requires a warehouse for service-type item lines and never calls `StockMovementService::issue()` for them; `cancel()` reverses stock only for lines that actually got a `stock_movement_id`. `SalesInvoiceForm::selectItem()` also sets `lines.*.unit_price` from the item's `selling_price` when present.

- [ ] **Step 1: Write the failing unit tests**

Append to `tests/Unit/SalesInvoiceServiceTest.php` (inside the existing class):

```php
    public function test_confirming_a_service_type_item_line_does_not_require_a_warehouse_or_issue_stock(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $serviceItem = Item::factory()->for($company)->service()->create(['vat_rate' => '18.00']);
        $user = User::factory()->create();

        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'warehouse_id' => null,
            'invoice_date' => '2026-03-01',
        ]);
        $invoice->lines()->create([
            'item_id' => $serviceItem->id,
            'description' => $serviceItem->name,
            'quantity' => '2',
            'unit_price' => '500.00',
            'vat_rate' => '18.00',
        ]);

        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $this->assertSame('confirmed', $confirmed->status);
        $entry = $confirmed->journalEntry()->with('lines.account')->first();
        $this->assertCount(3, $entry->lines); // AR + revenue + VAT, no COGS/inventory lines
        $this->assertNull($entry->lines->firstWhere('account.code', '701'));
        $this->assertNull($entry->lines->firstWhere('account.code', '660'));
        $this->assertSame(0, \App\Models\StockMovement::where('item_id', $serviceItem->id)->count());
    }

    public function test_cancelling_an_invoice_with_a_service_type_item_line_does_not_error(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $serviceItem = Item::factory()->for($company)->service()->create();
        $user = User::factory()->create();

        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'warehouse_id' => null, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['item_id' => $serviceItem->id, 'description' => $serviceItem->name, 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);
        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $cancelled = $this->service->cancel($confirmed->fresh(), $user->id);

        $this->assertSame('cancelled', $cancelled->status);
    }
```

- [ ] **Step 2: Write the failing feature test for price auto-fill**

Append to `tests/Feature/SalesInvoiceFormTest.php` (inside the existing class, right after `test_selecting_an_item_prefills_description_and_vat_rate`):

```php
    public function test_selecting_an_item_prefills_the_unit_price_from_selling_price(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Widget', 'selling_price' => '249.99']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->call('selectItem', 0, (string) $item->id)
            ->assertSet('lines.0.unit_price', '249.99');
    }

    public function test_selecting_an_item_without_a_selling_price_leaves_unit_price_unchanged(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Widget', 'selling_price' => null]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('lines.0.unit_price', '5.00')
            ->call('selectItem', 0, (string) $item->id)
            ->assertSet('lines.0.unit_price', '5.00');
    }
```

- [ ] **Step 3: Run the new tests to verify they fail**

Run: `php artisan test --filter=SalesInvoiceServiceTest`
Expected: FAIL (warehouse required exception / COGS+inventory lines present for the service line)

Run: `php artisan test --filter=SalesInvoiceFormTest`
Expected: FAIL (`unit_price` stays at its default `'0'`, not `'249.99'`)

- [ ] **Step 4: Update `SalesInvoiceService::confirm()` and `cancel()`**

In `app/Services/Invoicing/SalesInvoiceService.php`, replace this line:

```php
        $invoice->loadMissing(['lines', 'company']);
```

with:

```php
        $invoice->loadMissing(['lines.item', 'company']);
```

Replace this line:

```php
        $hasItemLines = $invoice->lines->contains(fn ($line) => $line->item_id !== null);
```

with:

```php
        $hasStockTrackedLines = $invoice->lines->contains(fn ($line) => $line->item_id !== null && ! $line->item->isService());
```

Replace this line:

```php
        if ($hasItemLines && $invoice->warehouse_id === null) {
```

with:

```php
        if ($hasStockTrackedLines && $invoice->warehouse_id === null) {
```

Replace this block inside the `DB::transaction()` closure:

```php
            foreach ($invoice->lines as $line) {
                if ($line->item_id === null) {
                    continue;
                }

                $movement = $this->stockMovementService->issue(
```

with:

```php
            foreach ($invoice->lines as $line) {
                if ($line->item_id === null || $line->item->isService()) {
                    continue;
                }

                $movement = $this->stockMovementService->issue(
```

In the same file's `cancel()` method, replace this block:

```php
            foreach ($invoice->lines as $line) {
                if ($line->item_id === null) {
                    continue;
                }

                $this->stockMovementService->receipt(
```

with:

```php
            foreach ($invoice->lines as $line) {
                if ($line->item_id === null || $line->stockMovement === null) {
                    continue;
                }

                $this->stockMovementService->receipt(
```

(This checks `stockMovement === null` rather than re-deriving the item's type — it directly reflects whether `confirm()` actually recorded a movement for this line, which is the true invariant `cancel()` needs, and stays correct even if an item's type changes between confirm and cancel.)

- [ ] **Step 5: Auto-fill `unit_price` in `SalesInvoiceForm::selectItem()`**

In `app/Livewire/Invoicing/SalesInvoiceForm.php`, replace:

```php
        if ($item) {
            $this->lines[$index]['description'] = $item->name;
            $this->lines[$index]['vat_rate'] = $this->company->is_vat_registered ? (string) $item->vat_rate : '0.00';
        }
```

with:

```php
        if ($item) {
            $this->lines[$index]['description'] = $item->name;
            $this->lines[$index]['vat_rate'] = $this->company->is_vat_registered ? (string) $item->vat_rate : '0.00';

            if ($item->selling_price !== null) {
                $this->lines[$index]['unit_price'] = (string) $item->selling_price;
            }
        }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=SalesInvoiceServiceTest`
Expected: PASS

Run: `php artisan test --filter=SalesInvoiceFormTest`
Expected: PASS

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Services/Invoicing/SalesInvoiceService.php app/Livewire/Invoicing/SalesInvoiceForm.php tests/Unit/SalesInvoiceServiceTest.php tests/Feature/SalesInvoiceFormTest.php
git commit -m "feat: skip stock issuance for service-type invoice lines, auto-fill price from item"
```

---

### Task 5: Barcode scanner falls back from шифра to баркод

**Files:**
- Modify: `app/Livewire/Inventory/StockMovementForm.php`
- Test: `tests/Feature/StockMovementFormTest.php`

**Interfaces:**
- Produces: `StockMovementForm::lookupByCode(string $code)` matches `code` first, then `barcode`, within the same company.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/StockMovementFormTest.php` (inside the existing class):

```php
    public function test_lookup_by_code_falls_back_to_barcode(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['code' => 'SKU-9', 'barcode' => '3800000000048']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'receipt'])
            ->call('lookupByCode', '3800000000048')
            ->assertSet('itemId', (string) $item->id)
            ->assertHasNoErrors('scannedCode');
    }

    public function test_lookup_by_code_still_errors_when_neither_code_nor_barcode_matches(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(StockMovementForm::class, ['company' => $company, 'type' => 'receipt'])
            ->call('lookupByCode', 'NOTHING-HERE')
            ->assertHasErrors('scannedCode');
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=StockMovementFormTest`
Expected: FAIL on `test_lookup_by_code_falls_back_to_barcode` (barcode lookup not implemented yet)

- [ ] **Step 3: Update `lookupByCode()`**

In `app/Livewire/Inventory/StockMovementForm.php`, replace:

```php
    public function lookupByCode(string $code): void
    {
        $item = Item::where('company_id', $this->company->id)->where('code', $code)->first();

        if (! $item) {
            $this->addError('scannedCode', "Не е пронајден артикл со шифра \"{$code}\".");

            return;
        }
```

with:

```php
    public function lookupByCode(string $code): void
    {
        $item = Item::where('company_id', $this->company->id)->where('code', $code)->first()
            ?? Item::where('company_id', $this->company->id)->where('barcode', $code)->first();

        if (! $item) {
            $this->addError('scannedCode', "Не е пронајден артикл со шифра или баркод \"{$code}\".");

            return;
        }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=StockMovementFormTest`
Expected: PASS

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Inventory/StockMovementForm.php tests/Feature/StockMovementFormTest.php
git commit -m "feat: barcode scanner falls back from code to barcode lookup"
```

---

### Task 6: Bulk-import scaffold — package, template download, route, Sidebar link

**Files:**
- Modify: `composer.json` / `composer.lock` (via `composer require`)
- Create: `app/Exports/ItemImportTemplateExport.php`
- Create: `app/Http/Controllers/ItemImportTemplateController.php`
- Create: `app/Livewire/Inventory/ItemBulkImport.php`
- Create: `resources/views/livewire/inventory/item-bulk-import.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/livewire/layout/sidebar.blade.php`
- Test: `tests/Feature/ItemBulkImportTest.php`

**Interfaces:**
- Produces: route `inventory.items.bulk-import` (`GET /companies/{company}/items/bulk-import`) rendering `ItemBulkImport`. Route `inventory.items.bulk-import.template` (`GET /companies/{company}/items/bulk-import/template`) downloads the .xlsx template via `ItemImportTemplateController` — a dedicated controller, not a Livewire action, matching this codebase's existing download pattern (`PartnerListPdfController`, `JournalEntryPdfController`, `SalesInvoicePdfController` are all plain invokable controllers, never Livewire methods). This task's `ItemBulkImport` is intentionally minimal (mount + render only) — Tasks 7-8 add the parser and the upload/preview/confirm flow.

- [ ] **Step 1: Install the package**

Run:

```bash
composer require maatwebsite/excel
```

Expected: installs `maatwebsite/excel` ^3.1 (pulls in `phpoffice/phpspreadsheet` and its own small dependency set) with no conflicts — this exact install was already dry-run-verified against this app's Laravel 13.20 during planning.

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/ItemBulkImportTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ItemBulkImportTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_the_bulk_import_page_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('inventory.items.bulk-import', $company))
            ->assertOk();
    }

    public function test_downloading_the_template_returns_an_xlsx_file(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)
            ->get(route('inventory.items.bulk-import.template', $company));

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --filter=ItemBulkImportTest`
Expected: FAIL (route `inventory.items.bulk-import` and `inventory.items.bulk-import.template` do not exist)

- [ ] **Step 4: Create the template export class**

Create `app/Exports/ItemImportTemplateExport.php`:

```php
<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ItemImportTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return [
            'Шифра', 'Назив', 'Мерна единица', 'Категорија', 'ДДВ стапка',
            'Продажна цена', 'Тип', 'МК-производство', 'Баркод',
        ];
    }

    public function array(): array
    {
        return [
            ['SKU-001', 'Пример артикл', 'парче', 'Пример категорија', '18', '250.00', 'производ', 'Да', '3800000000017'],
        ];
    }
}
```

- [ ] **Step 5: Create the template download controller**

Create `app/Http/Controllers/ItemImportTemplateController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Exports\ItemImportTemplateExport;
use App\Models\Company;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;

class ItemImportTemplateController extends Controller
{
    public function __invoke(Company $company)
    {
        Gate::authorize('view', $company);

        return Excel::download(new ItemImportTemplateExport(), 'artikli-obrazec.xlsx');
    }
}
```

- [ ] **Step 6: Create the `ItemBulkImport` component**

Create `app/Livewire/Inventory/ItemBulkImport.php`:

```php
<?php

namespace App\Livewire\Inventory;

use App\Models\Company;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class ItemBulkImport extends Component
{
    use WithFileUploads;

    public Company $company;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        return view('livewire.inventory.item-bulk-import');
    }
}
```

- [ ] **Step 7: Create the view**

Create `resources/views/livewire/inventory/item-bulk-import.blade.php`:

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Масовен внес артикли — {{ $company->name }}</h1>

    <x-card class="mb-6">
        <p class="text-sm text-gray-600 mb-3">
            Прво преземете го образецот, пополнете го во Excel и прикачете го овде.
        </p>
        <a href="{{ route('inventory.items.bulk-import.template', $company) }}" class="text-brand text-sm hover:underline">
            Преземи образец
        </a>
    </x-card>
</div>
```

- [ ] **Step 8: Register the routes**

In `routes/web.php`, add these imports at the top with the other controller/Livewire imports:

```php
use App\Http\Controllers\ItemImportTemplateController;
use App\Livewire\Inventory\ItemBulkImport;
```

Then add these two lines to the `inventory.` route group, right after the `items.index` route:

```php
    Route::get('/items/bulk-import', [ItemBulkImport::class, '__invoke'])->name('items.bulk-import');
    Route::get('/items/bulk-import/template', [ItemImportTemplateController::class, '__invoke'])->name('items.bulk-import.template');
```

- [ ] **Step 9: Add the Sidebar link**

In `resources/views/livewire/layout/sidebar.blade.php`, right after the existing "Артикли" link, add:

```blade
                        <a href="{{ route('inventory.items.bulk-import', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.items.bulk-import') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Масовен внес артикли</a>
```

- [ ] **Step 10: Run the tests to verify they pass**

Run: `php artisan test --filter=ItemBulkImportTest`
Expected: PASS

- [ ] **Step 11: Run the full suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 12: Commit**

```bash
git add composer.json composer.lock app/Exports/ItemImportTemplateExport.php app/Http/Controllers/ItemImportTemplateController.php app/Livewire/Inventory/ItemBulkImport.php resources/views/livewire/inventory/item-bulk-import.blade.php routes/web.php resources/views/livewire/layout/sidebar.blade.php tests/Feature/ItemBulkImportTest.php
git commit -m "feat: add bulk-import page scaffold with downloadable template"
```

---

### Task 7: `ItemImportParser` — plain PHP row parsing and validation

**Files:**
- Create: `app/Services/Inventory/ItemImportParser.php`
- Test: `tests/Unit/ItemImportParserTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks except `Item` (Task 1).
- Produces: `ItemImportParser::parse(array $rows, int $companyId): array` — `$rows` is a 0-indexed array of 0-indexed cell arrays exactly as `Excel::toArray()` returns them (row 0 is the heading row and is skipped). Returns an array of associative arrays, one per non-blank data row, each shaped:
  ```php
  [
      'row_number' => int,          // 1-based spreadsheet row number
      'action' => 'new'|'update'|'error',
      'errors' => string[],          // empty unless action === 'error'
      'code' => string,
      'name' => ?string,
      'unit_of_measure' => ?string,  // null means "keep existing" on an update row
      'category' => ?string,
      'category_provided' => bool,
      'vat_rate' => ?string,         // null means "keep existing" on an update row
      'selling_price' => ?string,
      'selling_price_provided' => bool,
      'type' => ?string,             // null means "keep existing" on an update row
      'is_made_in_mk' => ?bool,       // null means "keep existing" on an update row
      'barcode' => ?string,
      'barcode_provided' => bool,
      'existing_item_id' => ?int,
  ]
  ```
  This return shape is what Task 8's `ItemBulkImport::confirmImport()` consumes directly.

- [ ] **Step 1: Write the failing unit tests**

Create `tests/Unit/ItemImportParserTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Item;
use App\Services\Inventory\ItemImportParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemImportParserTest extends TestCase
{
    use RefreshDatabase;

    private ItemImportParser $parser;

    public function setUp(): void
    {
        parent::setUp();
        $this->parser = new ItemImportParser();
    }

    private const HEADER = ['Шифра', 'Назив', 'Мерна единица', 'Категорија', 'ДДВ стапка', 'Продажна цена', 'Тип', 'МК-производство', 'Баркод'];

    public function test_a_complete_new_row_parses_as_new_with_all_fields(): void
    {
        $company = Company::factory()->create();
        $rows = [
            self::HEADER,
            ['SKU-1', 'Widget', 'парче', 'Алат', '18', '99.90', 'производ', 'Да', '3800000000017'],
        ];

        $result = $this->parser->parse($rows, $company->id);

        $this->assertCount(1, $result);
        $row = $result[0];
        $this->assertSame('new', $row['action']);
        $this->assertSame([], $row['errors']);
        $this->assertSame('SKU-1', $row['code']);
        $this->assertSame('Widget', $row['name']);
        $this->assertSame('парче', $row['unit_of_measure']);
        $this->assertSame('Алат', $row['category']);
        $this->assertSame('18.00', $row['vat_rate']);
        $this->assertSame('99.90', $row['selling_price']);
        $this->assertSame('product', $row['type']);
        $this->assertTrue($row['is_made_in_mk']);
        $this->assertSame('3800000000017', $row['barcode']);
        $this->assertNull($row['existing_item_id']);
    }

    public function test_a_minimal_new_row_gets_sensible_defaults(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['SKU-2', 'Widget 2', 'парче', '', '', '', '', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('new', $row['action']);
        $this->assertSame('18.00', $row['vat_rate']);
        $this->assertSame('product', $row['type']);
        $this->assertFalse($row['is_made_in_mk']);
        $this->assertNull($row['category']);
        $this->assertNull($row['selling_price']);
        $this->assertNull($row['barcode']);
    }

    public function test_a_row_matching_an_existing_code_is_marked_as_update(): void
    {
        $company = Company::factory()->create();
        $existing = Item::factory()->for($company)->create(['code' => 'SKU-3']);
        $rows = [self::HEADER, ['SKU-3', 'New Name', 'парче', '', '', '', '', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('update', $row['action']);
        $this->assertSame($existing->id, $row['existing_item_id']);
    }

    public function test_blank_optional_cells_on_an_update_row_mean_keep_existing(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['code' => 'SKU-4', 'vat_rate' => '5.00', 'type' => 'service', 'is_made_in_mk' => true]);
        $rows = [self::HEADER, ['SKU-4', 'Renamed', 'парче', '', '', '', '', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('update', $row['action']);
        $this->assertNull($row['vat_rate']);
        $this->assertNull($row['type']);
        $this->assertNull($row['is_made_in_mk']);
        $this->assertFalse($row['category_provided']);
        $this->assertFalse($row['selling_price_provided']);
        $this->assertFalse($row['barcode_provided']);
    }

    public function test_a_provided_value_on_an_update_row_overwrites(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['code' => 'SKU-5', 'vat_rate' => '5.00']);
        $rows = [self::HEADER, ['SKU-5', 'Renamed', 'парче', '', '18', '', '', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('18.00', $row['vat_rate']);
    }

    public function test_blank_code_is_an_error(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['', 'Widget', 'парче', '', '', '', '', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('error', $row['action']);
        $this->assertContains('Шифрата е задолжителна.', $row['errors']);
    }

    public function test_duplicate_code_within_the_file_is_an_error_on_the_second_occurrence(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['SKU-6', 'A', 'парче', '', '', '', '', '', ''], ['SKU-6', 'B', 'парче', '', '', '', '', '', '']];

        $result = $this->parser->parse($rows, $company->id);

        $this->assertSame('new', $result[0]['action']);
        $this->assertSame('error', $result[1]['action']);
    }

    public function test_blank_name_is_an_error(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['SKU-7', '', 'парче', '', '', '', '', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('error', $row['action']);
        $this->assertContains('Називот е задолжителен.', $row['errors']);
    }

    public function test_blank_unit_of_measure_is_an_error_only_for_new_rows(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['code' => 'SKU-8', 'unit_of_measure' => 'kg']);
        $rows = [
            self::HEADER,
            ['SKU-8', 'Existing', '', '', '', '', '', '', ''],
            ['SKU-9', 'New', '', '', '', '', '', '', ''],
        ];

        $result = $this->parser->parse($rows, $company->id);

        $this->assertSame('update', $result[0]['action']);
        $this->assertSame('error', $result[1]['action']);
        $this->assertContains('Мерната единица е задолжителна за нов артикл.', $result[1]['errors']);
    }

    public function test_invalid_vat_rate_is_an_error(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['SKU-10', 'Widget', 'парче', '', 'not-a-number', '', '', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('error', $row['action']);
        $this->assertContains('Стапката на ДДВ мора да биде број од 0 до 100.', $row['errors']);
    }

    public function test_vat_rate_out_of_range_is_an_error(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['SKU-11', 'Widget', 'парче', '', '150', '', '', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('error', $row['action']);
    }

    public function test_negative_selling_price_is_an_error(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['SKU-12', 'Widget', 'парче', '', '', '-5', '', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('error', $row['action']);
        $this->assertContains('Продажната цена мора да биде позитивен број.', $row['errors']);
    }

    public function test_invalid_type_value_is_an_error(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['SKU-13', 'Widget', 'парче', '', '', '', 'нешто-друго', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('error', $row['action']);
        $this->assertStringContainsString('тип', $row['errors'][0]);
    }

    public function test_type_value_is_case_insensitive(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['SKU-14', 'Widget', 'парче', '', '', '', 'УСЛУГА', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('service', $row['type']);
    }

    public function test_invalid_made_in_mk_value_is_an_error(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['SKU-15', 'Widget', 'парче', '', '', '', '', 'можеби', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('error', $row['action']);
    }

    public function test_duplicate_barcode_within_the_file_is_an_error_on_the_second_occurrence(): void
    {
        $company = Company::factory()->create();
        $rows = [
            self::HEADER,
            ['SKU-16', 'A', 'парче', '', '', '', '', '', '3800000000017'],
            ['SKU-17', 'B', 'парче', '', '', '', '', '', '3800000000017'],
        ];

        $result = $this->parser->parse($rows, $company->id);

        $this->assertSame('new', $result[0]['action']);
        $this->assertSame('error', $result[1]['action']);
    }

    public function test_barcode_already_used_by_another_item_is_an_error(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['code' => 'SKU-18', 'barcode' => '3800000000017']);
        $rows = [self::HEADER, ['SKU-19', 'New Item', 'парче', '', '', '', '', '', '3800000000017']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('error', $row['action']);
    }

    public function test_updating_an_item_with_its_own_unchanged_barcode_is_not_an_error(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['code' => 'SKU-20', 'barcode' => '3800000000017']);
        $rows = [self::HEADER, ['SKU-20', 'Renamed', 'парче', '', '', '', '', '', '3800000000017']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('update', $row['action']);
    }

    public function test_blank_rows_are_skipped(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['', '', '', '', '', '', '', '', ''], ['SKU-21', 'Widget', 'парче', '', '', '', '', '', '']];

        $result = $this->parser->parse($rows, $company->id);

        $this->assertCount(1, $result);
        $this->assertSame('SKU-21', $result[0]['code']);
    }

    public function test_row_numbers_match_the_spreadsheets_own_1_based_row_number(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['SKU-22', 'Widget', 'парче', '', '', '', '', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame(2, $row['row_number']);
    }

    public function test_a_row_cannot_conflict_with_another_companys_item_code(): void
    {
        $otherCompany = Company::factory()->create();
        $thisCompany = Company::factory()->create();
        Item::factory()->for($otherCompany)->create(['code' => 'SKU-23']);
        $rows = [self::HEADER, ['SKU-23', 'Widget', 'парче', '', '', '', '', '', '']];

        $row = $this->parser->parse($rows, $thisCompany->id)[0];

        $this->assertSame('new', $row['action']);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=ItemImportParserTest`
Expected: FAIL (class `App\Services\Inventory\ItemImportParser` does not exist)

- [ ] **Step 3: Implement `ItemImportParser`**

Create `app/Services/Inventory/ItemImportParser.php`:

```php
<?php

namespace App\Services\Inventory;

use App\Models\Item;

class ItemImportParser
{
    public function parse(array $rows, int $companyId): array
    {
        $parsed = [];
        $codesSeenInFile = [];
        $barcodesSeenInFile = [];

        foreach ($rows as $index => $row) {
            if ($index === 0) {
                continue; // heading row
            }

            if ($this->isBlankRow($row)) {
                continue;
            }

            $rowNumber = $index + 1;
            $errors = [];

            $code = trim((string) ($row[0] ?? ''));
            $name = trim((string) ($row[1] ?? ''));
            $unitOfMeasureRaw = trim((string) ($row[2] ?? ''));
            $categoryRaw = trim((string) ($row[3] ?? ''));
            $vatRateRaw = trim((string) ($row[4] ?? ''));
            $sellingPriceRaw = trim((string) ($row[5] ?? ''));
            $typeRaw = trim((string) ($row[6] ?? ''));
            $madeInMkRaw = trim((string) ($row[7] ?? ''));
            $barcodeRaw = trim((string) ($row[8] ?? ''));

            if ($code === '') {
                $errors[] = 'Шифрата е задолжителна.';
            } elseif (isset($codesSeenInFile[$code])) {
                $errors[] = "Шифрата „{$code}“ се појавува повеќе пати во табелата.";
            }

            if ($name === '') {
                $errors[] = 'Називот е задолжителен.';
            }

            $existingItem = $code !== ''
                ? Item::where('company_id', $companyId)->where('code', $code)->first()
                : null;
            $action = $existingItem ? 'update' : 'new';

            $unitOfMeasure = $unitOfMeasureRaw !== '' ? $unitOfMeasureRaw : null;
            if ($unitOfMeasure === null && $action === 'new') {
                $errors[] = 'Мерната единица е задолжителна за нов артикл.';
            }

            $vatRate = null;
            if ($vatRateRaw !== '') {
                if (! is_numeric($vatRateRaw) || (float) $vatRateRaw < 0 || (float) $vatRateRaw > 100) {
                    $errors[] = 'Стапката на ДДВ мора да биде број од 0 до 100.';
                } else {
                    $vatRate = number_format((float) $vatRateRaw, 2, '.', '');
                }
            } elseif ($action === 'new') {
                $vatRate = '18.00';
            }

            $sellingPriceProvided = $sellingPriceRaw !== '';
            $sellingPrice = null;
            if ($sellingPriceProvided) {
                if (! is_numeric($sellingPriceRaw) || (float) $sellingPriceRaw < 0) {
                    $errors[] = 'Продажната цена мора да биде позитивен број.';
                } else {
                    $sellingPrice = number_format((float) $sellingPriceRaw, 2, '.', '');
                }
            }

            $type = null;
            if ($typeRaw !== '') {
                $normalized = mb_strtolower($typeRaw);
                if ($normalized === 'производ') {
                    $type = 'product';
                } elseif ($normalized === 'услуга') {
                    $type = 'service';
                } else {
                    $errors[] = "Невалидна вредност за тип: „{$typeRaw}“ (дозволено: производ, услуга, или празно).";
                }
            } elseif ($action === 'new') {
                $type = 'product';
            }

            $isMadeInMk = null;
            if ($madeInMkRaw !== '') {
                $normalized = mb_strtolower($madeInMkRaw);
                if ($normalized === 'да') {
                    $isMadeInMk = true;
                } elseif ($normalized === 'не') {
                    $isMadeInMk = false;
                } else {
                    $errors[] = "Невалидна вредност за МК-производство: „{$madeInMkRaw}“ (дозволено: Да, Не, или празно).";
                }
            } elseif ($action === 'new') {
                $isMadeInMk = false;
            }

            $barcodeProvided = $barcodeRaw !== '';
            if ($barcodeProvided) {
                if (isset($barcodesSeenInFile[$barcodeRaw])) {
                    $errors[] = "Баркодот „{$barcodeRaw}“ се појавува повеќе пати во табелата.";
                } else {
                    $conflict = Item::where('company_id', $companyId)
                        ->where('barcode', $barcodeRaw)
                        ->when($existingItem, fn ($q) => $q->whereKeyNot($existingItem->id))
                        ->first();

                    if ($conflict) {
                        $errors[] = "Баркодот „{$barcodeRaw}“ веќе е искористен кај артикл „{$conflict->code}“.";
                    }
                }
            }

            if ($code !== '') {
                $codesSeenInFile[$code] = true;
            }
            if ($barcodeProvided) {
                $barcodesSeenInFile[$barcodeRaw] = true;
            }

            $parsed[] = [
                'row_number' => $rowNumber,
                'action' => $errors === [] ? $action : 'error',
                'errors' => $errors,
                'code' => $code,
                'name' => $name !== '' ? $name : null,
                'unit_of_measure' => $unitOfMeasure,
                'category' => $categoryRaw !== '' ? $categoryRaw : null,
                'category_provided' => $categoryRaw !== '',
                'vat_rate' => $vatRate,
                'selling_price' => $sellingPrice,
                'selling_price_provided' => $sellingPriceProvided,
                'type' => $type,
                'is_made_in_mk' => $isMadeInMk,
                'barcode' => $barcodeProvided ? $barcodeRaw : null,
                'barcode_provided' => $barcodeProvided,
                'existing_item_id' => $existingItem?->id,
            ];
        }

        return $parsed;
    }

    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=ItemImportParserTest`
Expected: PASS (all 20 tests)

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/Inventory/ItemImportParser.php tests/Unit/ItemImportParserTest.php
git commit -m "feat: add ItemImportParser for bulk item spreadsheet validation"
```

---

### Task 8: Upload → preview → confirm flow in `ItemBulkImport`

**Files:**
- Create: `app/Imports/ItemsImport.php`
- Modify: `app/Livewire/Inventory/ItemBulkImport.php`
- Modify: `resources/views/livewire/inventory/item-bulk-import.blade.php`
- Modify: `lang/mk/validation.php`
- Test: `tests/Feature/ItemBulkImportTest.php`

**Interfaces:**
- Consumes: `ItemImportParser::parse()` (Task 7).
- Produces: `ItemBulkImport` public properties `$importFile` (nullable upload), `$parsedRows` (array, the parser's output), `$summary` (nullable string). Methods `preview(): void` (validates + parses the uploaded file into `$parsedRows`) and `confirmImport(): void` (commits `new`/`update` rows in one transaction, skips `error` rows, sets `$summary`, clears `$parsedRows`).

- [ ] **Step 1: Write the failing feature tests**

Append to `tests/Feature/ItemBulkImportTest.php` (inside the existing class). This test builds a real `.xlsx` file with `PhpOffice\PhpSpreadsheet` directly (Livewire's `UploadedFile::fake()->create()` only generates a file of a given *size*, not real parseable spreadsheet bytes — the actual package needs genuine spreadsheet content to parse):

```php
    private function makeXlsxUpload(array $rows): \Illuminate\Http\UploadedFile
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($rows, null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'items-import-').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        return new \Illuminate\Http\UploadedFile($path, 'items.xlsx', null, null, true);
    }

    public function test_uploading_a_file_shows_a_preview_with_new_and_update_rows(): void
    {
        $company = Company::factory()->create();
        \App\Models\Item::factory()->for($company)->create(['code' => 'SKU-EXISTING', 'name' => 'Old Name']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $rows = [
            ['Шифра', 'Назив', 'Мерна единица', 'Категорија', 'ДДВ стапка', 'Продажна цена', 'Тип', 'МК-производство', 'Баркод'],
            ['SKU-NEW', 'Brand New Item', 'парче', '', '18', '50.00', 'производ', 'Не', ''],
            ['SKU-EXISTING', 'Updated Name', 'парче', '', '', '', '', '', ''],
        ];

        Livewire::test(ItemBulkImport::class, ['company' => $company])
            ->set('importFile', $this->makeXlsxUpload($rows))
            ->call('preview')
            ->assertSet('parsedRows.0.action', 'new')
            ->assertSet('parsedRows.1.action', 'update')
            ->assertSee('Brand New Item')
            ->assertSee('Updated Name');
    }

    public function test_confirming_saves_valid_rows_and_skips_invalid_ones(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $rows = [
            ['Шифра', 'Назив', 'Мерна единица', 'Категорија', 'ДДВ стапка', 'Продажна цена', 'Тип', 'МК-производство', 'Баркод'],
            ['SKU-GOOD', 'Good Item', 'парче', '', '18', '50.00', 'производ', 'Не', ''],
            ['', 'No Code', 'парче', '', '', '', '', '', ''],
        ];

        Livewire::test(ItemBulkImport::class, ['company' => $company])
            ->set('importFile', $this->makeXlsxUpload($rows))
            ->call('preview')
            ->call('confirmImport')
            ->assertSet('summary', '1 додадени, 0 ажурирани, 1 прескокнати.');

        $this->assertDatabaseHas('items', ['company_id' => $company->id, 'code' => 'SKU-GOOD', 'name' => 'Good Item']);
        $this->assertDatabaseCount('items', 1);
    }

    public function test_confirming_an_update_row_with_a_blank_vat_rate_keeps_the_existing_rate(): void
    {
        $company = Company::factory()->create();
        \App\Models\Item::factory()->for($company)->create(['code' => 'SKU-KEEP', 'vat_rate' => '5.00']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $rows = [
            ['Шифра', 'Назив', 'Мерна единица', 'Категорија', 'ДДВ стапка', 'Продажна цена', 'Тип', 'МК-производство', 'Баркод'],
            ['SKU-KEEP', 'Renamed', 'парче', '', '', '', '', '', ''],
        ];

        Livewire::test(ItemBulkImport::class, ['company' => $company])
            ->set('importFile', $this->makeXlsxUpload($rows))
            ->call('preview')
            ->call('confirmImport');

        $item = \App\Models\Item::where('company_id', $company->id)->where('code', 'SKU-KEEP')->first();
        $this->assertSame('Renamed', $item->name);
        $this->assertSame('5.00', (string) $item->vat_rate);
    }

    public function test_uploading_a_non_spreadsheet_file_shows_a_friendly_error(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $path = tempnam(sys_get_temp_dir(), 'not-a-spreadsheet-').'.xlsx';
        file_put_contents($path, 'this is plain text, not a real xlsx file');
        $badFile = new \Illuminate\Http\UploadedFile($path, 'items.xlsx', null, null, true);

        Livewire::test(ItemBulkImport::class, ['company' => $company])
            ->set('importFile', $badFile)
            ->call('preview')
            ->assertHasErrors(['importFile']);
    }

    public function test_a_client_can_upload_and_confirm_for_their_own_company(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        $rows = [
            ['Шифра', 'Назив', 'Мерна единица', 'Категорија', 'ДДВ стапка', 'Продажна цена', 'Тип', 'МК-производство', 'Баркод'],
            ['SKU-CLIENT', 'Client Item', 'парче', '', '', '', '', '', ''],
        ];

        Livewire::test(ItemBulkImport::class, ['company' => $company])
            ->set('importFile', $this->makeXlsxUpload($rows))
            ->call('preview')
            ->call('confirmImport')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('items', ['company_id' => $company->id, 'code' => 'SKU-CLIENT']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=ItemBulkImportTest`
Expected: FAIL (`preview`/`confirmImport` methods and `parsedRows`/`summary` properties don't exist yet)

- [ ] **Step 3: Create the empty import marker class**

Create `app/Imports/ItemsImport.php`:

```php
<?php

namespace App\Imports;

class ItemsImport
{
}
```

(An intentionally empty class — `Excel::toArray()` only needs *an* object to hand to `Maatwebsite\Excel\Excel`, and giving it its own named class rather than an anonymous one leaves room to add import-specific concerns like `WithStartRow` later without touching call sites.)

- [ ] **Step 4: Rewrite `ItemBulkImport`**

Replace the contents of `app/Livewire/Inventory/ItemBulkImport.php`:

```php
<?php

namespace App\Livewire\Inventory;

use App\Imports\ItemsImport;
use App\Models\Company;
use App\Models\Item;
use App\Services\Inventory\ItemImportParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;

#[Layout('layouts.app')]
class ItemBulkImport extends Component
{
    use WithFileUploads;

    public Company $company;

    public $importFile = null;

    public array $parsedRows = [];

    public ?string $summary = null;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function preview(): void
    {
        Gate::authorize('create', Item::class);

        $this->validate(['importFile' => 'required|file|max:5120']);

        try {
            $sheets = Excel::toArray(new ItemsImport(), $this->importFile);
        } catch (\Throwable $e) {
            $this->addError('importFile', 'Фајлот не можеше да се прочита како табела (.xlsx или .csv). Проверете дека е точниот формат.');
            $this->importFile = null;

            return;
        }

        $rows = $sheets[0] ?? [];

        $this->parsedRows = app(ItemImportParser::class)->parse($rows, $this->company->id);
        $this->summary = null;
        $this->importFile = null;
    }

    public function confirmImport(): void
    {
        Gate::authorize('create', Item::class);

        $created = 0;
        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use (&$created, &$updated, &$skipped): void {
            foreach ($this->parsedRows as $row) {
                if ($row['action'] === 'error') {
                    $skipped++;

                    continue;
                }

                if ($row['action'] === 'new') {
                    Item::create([
                        'company_id' => $this->company->id,
                        'code' => $row['code'],
                        'name' => $row['name'],
                        'unit_of_measure' => $row['unit_of_measure'],
                        'category' => $row['category'],
                        'vat_rate' => $row['vat_rate'],
                        'selling_price' => $row['selling_price'],
                        'type' => $row['type'],
                        'is_made_in_mk' => $row['is_made_in_mk'],
                        'barcode' => $row['barcode'],
                        'is_active' => true,
                    ]);
                    $created++;

                    continue;
                }

                $item = Item::findOrFail($row['existing_item_id']);
                Gate::authorize('update', $item);

                $updateData = ['name' => $row['name']];

                if ($row['unit_of_measure'] !== null) {
                    $updateData['unit_of_measure'] = $row['unit_of_measure'];
                }
                if ($row['category_provided']) {
                    $updateData['category'] = $row['category'];
                }
                if ($row['vat_rate'] !== null) {
                    $updateData['vat_rate'] = $row['vat_rate'];
                }
                if ($row['selling_price_provided']) {
                    $updateData['selling_price'] = $row['selling_price'];
                }
                if ($row['type'] !== null) {
                    $updateData['type'] = $row['type'];
                }
                if ($row['is_made_in_mk'] !== null) {
                    $updateData['is_made_in_mk'] = $row['is_made_in_mk'];
                }
                if ($row['barcode_provided']) {
                    $updateData['barcode'] = $row['barcode'];
                }

                $item->update($updateData);
                $updated++;
            }
        });

        $this->summary = "{$created} додадени, {$updated} ажурирани, {$skipped} прескокнати.";
        $this->parsedRows = [];
    }

    public function render()
    {
        return view('livewire.inventory.item-bulk-import');
    }
}
```

- [ ] **Step 5: Rewrite the view**

Replace the contents of `resources/views/livewire/inventory/item-bulk-import.blade.php`:

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Масовен внес артикли — {{ $company->name }}</h1>

    @if ($summary)
        <x-card class="mb-6 bg-green-50">
            <p class="text-sm text-green-700">{{ $summary }}</p>
        </x-card>
    @endif

    <x-card class="mb-6">
        <p class="text-sm text-gray-600 mb-3">
            Прво преземете го образецот, пополнете го во Excel и прикачете го овде.
        </p>
        <a href="{{ route('inventory.items.bulk-import.template', $company) }}" class="text-brand text-sm hover:underline mb-4 block">
            Преземи образец
        </a>

        <form wire:submit="preview" class="flex items-end gap-3">
            <div>
                <x-input-label for="importFile" value="Фајл (.xlsx или .csv)" />
                <input type="file" id="importFile" wire:model="importFile" accept=".xlsx,.csv" class="text-sm">
                @error('importFile') <span class="text-red-600 text-sm block">{{ $message }}</span> @enderror
            </div>
            <x-primary-button type="submit">Прикажи преглед</x-primary-button>
        </form>
    </x-card>

    @if (! empty($parsedRows))
        <x-card padding="p-0" class="overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead>
                    <tr class="text-left text-sm text-gray-500">
                        <th class="py-2 px-4">Ред</th>
                        <th class="py-2 px-4">Статус</th>
                        <th class="py-2 px-4">Шифра</th>
                        <th class="py-2 px-4">Назив</th>
                        <th class="py-2 px-4">Забелешка</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($parsedRows as $row)
                        <tr class="text-sm" wire:key="preview-row-{{ $row['row_number'] }}">
                            <td class="py-2 px-4">{{ $row['row_number'] }}</td>
                            <td class="py-2 px-4">
                                @if ($row['action'] === 'new')
                                    <span class="text-green-700">Ново</span>
                                @elseif ($row['action'] === 'update')
                                    <span class="text-blue-700">Ажурирање</span>
                                @else
                                    <span class="text-red-600">Грешка</span>
                                @endif
                            </td>
                            <td class="py-2 px-4 font-mono">{{ $row['code'] }}</td>
                            <td class="py-2 px-4">{{ $row['name'] }}</td>
                            <td class="py-2 px-4 text-red-600">{{ implode(' ', $row['errors']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>

        <div class="mt-4">
            <x-primary-button type="button" wire:click="confirmImport">Потврди и зачувај</x-primary-button>
        </div>
    @endif
</div>
```

- [ ] **Step 6: Add the Macedonian attribute label**

In `lang/mk/validation.php`'s `'attributes'` array, add:

```php
        'importFile' => 'фајл',
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --filter=ItemBulkImportTest`
Expected: PASS (all tests, including the 5 new ones)

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 9: Manual browser verification**

Since this task delivers the last piece of user-facing functionality in this plan, verify by hand in a real browser before considering the plan done:

1. Log in, open a company, go to Магацин → Артикли → „Масовен внес преку табела“.
2. Click „Преземи образец“, confirm an `.xlsx` downloads with the 9 Macedonian column headers and one example row.
3. Fill in a few rows (mix of new codes and one code that already exists), save, upload it back.
4. Confirm the preview table shows correct Ново/Ажурирање/Грешка per row, with readable error text on any deliberately-broken row (e.g. blank code).
5. Click „Потврди и зачувај“, confirm the summary line and that the items list now reflects the changes.
6. On Артикли, click „Уреди“ on an item, change a few fields including Тип to „Услуга“, save, confirm the row updates in place.
7. Open Магацин → Движење на залиха → Прием, confirm the service-type item is absent from the item dropdown.
8. Open a draft sales invoice, select an item that has a Продажна цена set, confirm the line's unit price auto-fills.
9. Scan (or manually call) a barcode on the movement-entry screen for an item whose баркод differs from its шифра, confirm it resolves correctly.

- [ ] **Step 10: Commit**

```bash
git add app/Imports/ItemsImport.php app/Livewire/Inventory/ItemBulkImport.php resources/views/livewire/inventory/item-bulk-import.blade.php lang/mk/validation.php tests/Feature/ItemBulkImportTest.php
git commit -m "feat: implement bulk item import preview and transactional commit flow"
```
