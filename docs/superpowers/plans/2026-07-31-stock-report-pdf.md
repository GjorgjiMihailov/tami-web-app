# Стоков извештај — PDF извоз со набавна и продажна вредност (сп#7) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "продажна вредност" (selling value) column next to the existing "набавна вредност" (cost value) column on the Залиха (stock on hand) report, both on screen and in a new PDF export.

**Architecture:** Extend `StockLevelQuery`'s two existing query methods with a selling-value computation (null selling price → 0). Add a new `StockOnHandPdfController` (mirrors the existing `PartnerListPdfController` pattern) + table-based Blade view + route. Update the existing on-screen report view to show the new column and link to the PDF.

**Tech Stack:** Laravel 13, Livewire 3, `barryvdh/laravel-dompdf` 3.1.6, SQLite (tests) / MySQL (production), PHPUnit.

## Global Constraints

- Money is formatted via `\App\Support\Format::money($amount, currency: '')` (no currency suffix) — the existing convention for this report's cost-value column.
- dompdf 3.1.6 in this codebase has **zero support for `display:flex`/`grid`** — any new PDF view must use `<table>`/`<td>` layout only.
- Selling value is VAT-exclusive, matching `items.selling_price`'s existing convention (same as the already-VAT-exclusive cost value).
- An item with no `selling_price` (`NULL`) contributes `0` to selling value, never `NULL` and never excluded from totals.
- All user-facing text is Macedonian (Cyrillic), no language switching.
- Text `Route::get($uri, [ClassName::class, '__invoke'])` (array-callable form), not a bare class-string — established codebase convention for these route groups.

---

### Task 1: `StockLevelQuery` selling-value calculations

**Files:**
- Modify: `app/Services/Inventory/StockLevelQuery.php`
- Test: `tests/Unit/StockLevelQueryTest.php`

**Interfaces:**
- Consumes: nothing new (uses existing `Item`, `StockLevel`, `Warehouse` models and `items.selling_price` column, already present from sub-project #6).
- Produces: `StockLevelQuery::stockOnHand()` rows gain a `'selling_value' => float` key. `StockLevelQuery::stockOnHandTotals()` rows gain a `'total_selling_value' => float` key. Both later tasks (the PDF controller and the on-screen view) consume these two exact key names.

- [ ] **Step 1: Write the failing tests**

Add these four test methods to `tests/Unit/StockLevelQueryTest.php` (insert after `test_stock_on_hand_totals_sums_across_warehouses`, before `test_stock_on_hand_and_totals_exclude_rows_whose_item_and_warehouse_belong_to_different_companies`):

```php
    public function test_stock_on_hand_includes_selling_value(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['selling_price' => '80.00']);
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', '2026-01-01', $user->id);

        $rows = StockLevelQuery::stockOnHand($company);

        $this->assertSame(800.0, $rows[0]['selling_value']);
    }

    public function test_stock_on_hand_selling_value_is_zero_when_item_has_no_selling_price(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['selling_price' => null]);
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', '2026-01-01', $user->id);

        $rows = StockLevelQuery::stockOnHand($company);

        $this->assertSame(0.0, $rows[0]['selling_value']);
    }

    public function test_stock_on_hand_totals_includes_selling_value(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['selling_price' => '80.00']);
        $warehouseA = Warehouse::factory()->for($company)->create();
        $warehouseB = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();
        app(StockMovementService::class)->receipt($item, $warehouseA, '10', '50.00', '2026-01-01', $user->id);
        app(StockMovementService::class)->receipt($item, $warehouseB, '20', '50.00', '2026-01-01', $user->id);

        $rows = StockLevelQuery::stockOnHandTotals($company);

        $this->assertSame(2400.0, $rows[0]['total_selling_value']);
    }

    public function test_stock_on_hand_totals_selling_value_is_zero_when_item_has_no_selling_price(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['selling_price' => null]);
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = User::factory()->create();
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', '2026-01-01', $user->id);

        $rows = StockLevelQuery::stockOnHandTotals($company);

        $this->assertSame(0.0, $rows[0]['total_selling_value']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=StockLevelQueryTest`
Expected: FAIL — `Undefined array key "selling_value"` (and `"total_selling_value"`) on the four new tests. The pre-existing tests in this file still pass.

- [ ] **Step 3: Implement the query changes**

Replace the full contents of `app/Services/Inventory/StockLevelQuery.php` with:

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
            ->where('warehouses.company_id', $company->id)
            ->when($warehouseId, fn ($q) => $q->where('stock_levels.warehouse_id', $warehouseId))
            ->orderBy('items.name')
            ->get([
                'items.id as item_id',
                'items.code as item_code',
                'items.name as item_name',
                'items.selling_price',
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
                'selling_value' => round((float) $row->quantity_on_hand * (float) ($row->selling_price ?? 0), 2),
            ])
            ->values();
    }

    public static function stockOnHandTotals(Company $company): Collection
    {
        return StockLevel::query()
            ->join('items', 'items.id', '=', 'stock_levels.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_levels.warehouse_id')
            ->where('items.company_id', $company->id)
            ->where('warehouses.company_id', $company->id)
            ->selectRaw('items.id as item_id, items.code as item_code, items.name as item_name, SUM(stock_levels.quantity_on_hand) as total_quantity, SUM(stock_levels.quantity_on_hand * stock_levels.average_cost) as total_value, SUM(stock_levels.quantity_on_hand * COALESCE(items.selling_price, 0)) as total_selling_value')
            ->groupBy('items.id', 'items.code', 'items.name')
            ->orderBy('items.name')
            ->get()
            ->map(fn ($row) => [
                'item_id' => (int) $row->item_id,
                'item_code' => $row->item_code,
                'item_name' => $row->item_name,
                'total_quantity' => (float) $row->total_quantity,
                'total_value' => round((float) $row->total_value, 2),
                'total_selling_value' => round((float) $row->total_selling_value, 2),
            ])
            ->values();
    }

    public static function valuationSummary(Company $company, ?string $groupBy = null): Collection
    {
        $query = StockLevel::query()
            ->join('items', 'items.id', '=', 'stock_levels.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_levels.warehouse_id')
            ->where('items.company_id', $company->id)
            ->where('warehouses.company_id', $company->id);

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
                ->selectRaw("COALESCE(items.category, 'Без категорија') as label, SUM(stock_levels.quantity_on_hand * stock_levels.average_cost) as total_value")
                ->groupBy('label')
                ->orderBy('label')
                ->get()
                ->map(fn ($row) => ['label' => $row->label, 'total_value' => round((float) $row->total_value, 2)])
                ->values();
        }

        $total = (clone $query)->selectRaw('SUM(stock_levels.quantity_on_hand * stock_levels.average_cost) as total_value')->value('total_value');

        return collect([['label' => 'Вкупно', 'total_value' => round((float) $total, 2)]]);
    }
}
```

(Only `stockOnHand()` and `stockOnHandTotals()` changed — `valuationSummary()` is untouched, out of scope per the design.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=StockLevelQueryTest`
Expected: PASS — all tests in the file, including the 4 new ones.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Inventory/StockLevelQuery.php tests/Unit/StockLevelQueryTest.php
git commit -m "feat: add selling value to stock-on-hand query"
```

---

### Task 2: PDF export (controller + route + view)

**Files:**
- Create: `app/Http/Controllers/StockOnHandPdfController.php`
- Create: `resources/views/pdf/stock-on-hand.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/StockOnHandPdfTest.php`

**Interfaces:**
- Consumes: `StockLevelQuery::stockOnHand(Company $company, ?int $warehouseId)` and `StockLevelQuery::stockOnHandTotals(Company $company)` from Task 1 (rows carry `item_code`, `item_name`, `quantity_on_hand`/`total_quantity`, `value`/`total_value`, `selling_value`/`total_selling_value`).
- Produces: route `inventory.reports.stock-on-hand.pdf` (accepts optional `?warehouseId=`), used by Task 3's "Преземи PDF" link. The `pdf.stock-on-hand` view expects `company` (Company), `rows` (Collection of arrays each with `item_code`, `item_name`, `quantity`, `cost_value`, `selling_value`), `warehouseName` (`?string`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/StockOnHandPdfTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockOnHandPdfTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_downloads_a_pdf_of_totals_across_all_warehouses(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Widget', 'selling_price' => '80.00']);
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', '2026-01-01', $admin->id);

        $response = $this->actingAs($admin)->get(route('inventory.reports.stock-on-hand.pdf', $company));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_it_downloads_a_pdf_scoped_to_one_warehouse(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['selling_price' => '80.00']);
        $warehouseA = Warehouse::factory()->for($company)->create(['name' => 'Main']);
        $warehouseB = Warehouse::factory()->for($company)->create(['name' => 'Annex']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(StockMovementService::class)->receipt($item, $warehouseA, '10', '50.00', '2026-01-01', $admin->id);
        app(StockMovementService::class)->receipt($item, $warehouseB, '5', '50.00', '2026-01-01', $admin->id);

        $response = $this->actingAs($admin)
            ->get(route('inventory.reports.stock-on-hand.pdf', $company).'?warehouseId='.$warehouseA->id);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_an_unknown_or_foreign_warehouse_id_is_rejected(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $foreignWarehouse = Warehouse::factory()->for($otherCompany)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('inventory.reports.stock-on-hand.pdf', $company).'?warehouseId='.$foreignWarehouse->id)
            ->assertNotFound();
    }

    public function test_a_client_of_another_company_cannot_download_the_pdf(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $otherCompany->id]);
        $client->assignRole('client');

        $this->actingAs($client)
            ->get(route('inventory.reports.stock-on-hand.pdf', $company))
            ->assertForbidden();
    }

    public function test_it_shows_cost_and_selling_value_columns(): void
    {
        $company = Company::factory()->create();

        $html = view('pdf.stock-on-hand', [
            'company' => $company,
            'rows' => collect([
                ['item_code' => 'SKU-1', 'item_name' => 'Widget', 'quantity' => 10.0, 'cost_value' => 500.0, 'selling_value' => 800.0],
            ]),
            'warehouseName' => null,
        ])->render();

        $this->assertStringContainsString('Widget', $html);
        $this->assertStringContainsString('Набавна вредност', $html);
        $this->assertStringContainsString('Продажна вредност', $html);
        $this->assertStringContainsString(\App\Support\Format::money(500.0, currency: ''), $html);
        $this->assertStringContainsString(\App\Support\Format::money(800.0, currency: ''), $html);
    }

    public function test_it_shows_the_warehouse_name_in_the_title_when_scoped(): void
    {
        $company = Company::factory()->create();

        $html = view('pdf.stock-on-hand', [
            'company' => $company,
            'rows' => collect(),
            'warehouseName' => 'Главен магацин',
        ])->render();

        $this->assertStringContainsString('Главен магацин', $html);
    }

    public function test_the_downloaded_response_is_an_actual_rendered_pdf(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('inventory.reports.stock-on-hand.pdf', $company));

        $response->assertOk();
        $bytes = $response->getContent();

        $this->assertNotFalse($bytes, 'Expected the response to expose raw PDF bytes.');
        $this->assertStringStartsWith('%PDF-', $bytes, 'Response body does not look like a real PDF document.');
        $this->assertGreaterThan(1000, strlen($bytes), 'Rendered PDF is suspiciously small to be a real document.');
        $this->assertStringContainsString('%%EOF', $bytes, 'Rendered PDF is missing its end-of-file marker.');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=StockOnHandPdfTest`
Expected: FAIL — `Route [inventory.reports.stock-on-hand.pdf] not defined` (route/controller/view don't exist yet).

- [ ] **Step 3: Implement the route, controller, and view**

In `routes/web.php`, add the import near the top (next to the existing `use App\Http\Controllers\PartnerListPdfController;` line):

```php
use App\Http\Controllers\StockOnHandPdfController;
```

Then add the new route inside the existing `inventory.` group, directly after the `reports.stock-on-hand` line:

```php
    Route::get('/reports/stock-on-hand', [StockOnHandReport::class, '__invoke'])->name('reports.stock-on-hand');
    Route::get('/reports/stock-on-hand/pdf', [StockOnHandPdfController::class, '__invoke'])->name('reports.stock-on-hand.pdf');
```

Create `app/Http/Controllers/StockOnHandPdfController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Warehouse;
use App\Services\Inventory\StockLevelQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StockOnHandPdfController extends Controller
{
    public function __invoke(Company $company, Request $request)
    {
        Gate::authorize('view', $company);

        $warehouseId = $request->integer('warehouseId') ?: null;

        if ($warehouseId) {
            $warehouseName = Warehouse::where('company_id', $company->id)->findOrFail($warehouseId)->name;
            $rows = StockLevelQuery::stockOnHand($company, $warehouseId)->map(fn ($row) => [
                'item_code' => $row['item_code'],
                'item_name' => $row['item_name'],
                'quantity' => $row['quantity_on_hand'],
                'cost_value' => $row['value'],
                'selling_value' => $row['selling_value'],
            ]);
        } else {
            $warehouseName = null;
            $rows = StockLevelQuery::stockOnHandTotals($company)->map(fn ($row) => [
                'item_code' => $row['item_code'],
                'item_name' => $row['item_name'],
                'quantity' => $row['total_quantity'],
                'cost_value' => $row['total_value'],
                'selling_value' => $row['total_selling_value'],
            ]);
        }

        $pdf = Pdf::loadView('pdf.stock-on-hand', [
            'company' => $company,
            'rows' => $rows,
            'warehouseName' => $warehouseName,
        ]);

        return $pdf->download('zaliha.pdf');
    }
}
```

Create `resources/views/pdf/stock-on-hand.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans'; font-size: 11px; color: #1f2937; margin: 0; padding: 0; }
        .accent-bar { height: 6px; background-color: #ff6600; }
        .content { padding: 18px 24px; }
        .muted { color: #6b7280; }
        table.header-table { width: 100%; margin-bottom: 10px; }
        table.header-table td { vertical-align: top; }
        table.stock { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.stock th { text-align: left; font-size: 10px; color: #6b7280; background-color: #f9fafb; padding: 6px; }
        table.stock th.text-right, table.stock td.text-right { text-align: right; }
        table.stock td { padding: 6px; border-bottom: 1px solid #f3f4f6; }
    </style>
</head>
<body>
    <div class="accent-bar"></div>
    <div class="content">
        <table class="header-table">
            <tr>
                <td><strong>{{ $company->name }}</strong></td>
                <td class="muted" style="text-align: right;">
                    Залиха @if($warehouseName) — {{ $warehouseName }} @else — сите магацини @endif
                </td>
            </tr>
        </table>

        <table class="stock">
            <thead>
                <tr>
                    <th>Шифра</th>
                    <th>Артикл</th>
                    <th class="text-right">Количина</th>
                    <th class="text-right">Набавна вредност</th>
                    <th class="text-right">Продажна вредност</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>{{ $row['item_code'] }}</td>
                        <td>{{ $row['item_name'] }}</td>
                        <td class="text-right">{{ number_format($row['quantity'], 3, ',', '.') }}</td>
                        <td class="text-right">{{ \App\Support\Format::money($row['cost_value'], currency: '') }}</td>
                        <td class="text-right">{{ \App\Support\Format::money($row['selling_value'], currency: '') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">Нема евидентирана залиха.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=StockOnHandPdfTest`
Expected: PASS — all 7 tests.

- [ ] **Step 5: Commit**

```bash
git add routes/web.php app/Http/Controllers/StockOnHandPdfController.php resources/views/pdf/stock-on-hand.blade.php tests/Feature/StockOnHandPdfTest.php
git commit -m "feat: add stock-on-hand PDF export"
```

---

### Task 3: On-screen report — new column, renamed column, PDF link

**Files:**
- Modify: `resources/views/livewire/inventory/stock-on-hand-report.blade.php`
- Modify: `tests/Feature/StockOnHandReportTest.php`
- Modify: `tests/Feature/InventoryRoutesTest.php`

**Interfaces:**
- Consumes: `StockLevelQuery::stockOnHand()`/`stockOnHandTotals()` `selling_value`/`total_selling_value` keys from Task 1; route `inventory.reports.stock-on-hand.pdf` from Task 2.
- Produces: nothing consumed by later tasks (this is the final task).

- [ ] **Step 1: Write the failing tests**

Add these test methods to `tests/Feature/StockOnHandReportTest.php` (insert after `test_it_shows_totals_across_warehouses_by_default`, before `test_the_stock_on_hand_page_renders_successfully_over_http`):

```php
    public function test_it_shows_cost_and_selling_value_for_a_specific_warehouse(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Widget', 'selling_price' => '80.00']);
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', '2026-01-01', $admin->id);

        $this->actingAs($admin);

        Livewire::test(StockOnHandReport::class, ['company' => $company])
            ->set('warehouseId', $warehouse->id)
            ->assertSee('Набавна вредност')
            ->assertSee('Продажна вредност')
            ->assertSee('500,00')
            ->assertSee('800,00');
    }

    public function test_it_shows_selling_value_in_the_totals_across_warehouses_view(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Widget', 'selling_price' => '80.00']);
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', '2026-01-01', $admin->id);

        $this->actingAs($admin);

        Livewire::test(StockOnHandReport::class, ['company' => $company])
            ->assertSee('Вкупна набавна вредност')
            ->assertSee('Вкупна продажна вредност')
            ->assertSee('800,00');
    }

    public function test_it_links_to_the_pdf_download(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('inventory.reports.stock-on-hand', $company))
            ->assertOk()
            ->assertSee(route('inventory.reports.stock-on-hand.pdf', $company), false);
    }
```

Add the required imports to the top of `tests/Feature/StockOnHandReportTest.php` if not already present — check the existing `use` block; `App\Services\Inventory\StockMovementService` is already imported (used by the existing test in this file), so no new imports are needed.

Also add this line to `tests/Feature/InventoryRoutesTest.php`'s `test_all_inventory_routes_render_successfully_for_an_admin`, directly after the existing `inventory.reports.stock-on-hand` assertion:

```php
        $this->get(route('inventory.reports.stock-on-hand', $company))->assertOk();
        $this->get(route('inventory.reports.stock-on-hand.pdf', $company))->assertOk();
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=StockOnHandReportTest`
Run: `php artisan test --filter=InventoryRoutesTest`
Expected: FAIL — the new `assertSee()` calls don't find "Набавна вредност"/"Продажна вредност"/"Вкупна набавна вредност"/"Вкупна продажна вредност" or the PDF link (the view still says "Вредност"/"Вкупна вредност" and has no PDF link).

- [ ] **Step 3: Implement the view changes**

Replace the full contents of `resources/views/livewire/inventory/stock-on-hand-report.blade.php` with:

```blade
<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Залиха — {{ $company->name }}</h1>
        <a href="{{ route('inventory.reports.stock-on-hand.pdf', $company) }}{{ $warehouseId ? '?warehouseId='.$warehouseId : '' }}" class="text-brand hover:underline text-sm">Преземи PDF</a>
    </div>

    <x-card class="mb-4 flex flex-wrap gap-4 items-end">
        <div>
            <x-input-label for="warehouseId" value="Магацин" />
            <select id="warehouseId" wire:model.live="warehouseId" class="border-gray-300 rounded-md text-sm">
                <option value="">Сите магацини (вкупно)</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                @endforeach
            </select>
        </div>
    </x-card>

    @if ($warehouseId)
        <x-card padding="p-0" class="overflow-hidden">
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
                        <td class="py-2 px-4">{{ $row['item_name'] }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['quantity_on_hand'], 3) }}</td>
                        <td class="py-2 px-4 text-right">{{ \App\Support\Format::money($row['average_cost'], currency: '', decimals: 4) }}</td>
                        <td class="py-2 px-4 text-right">{{ \App\Support\Format::money($row['value'], currency: '') }}</td>
                        <td class="py-2 px-4 text-right">{{ \App\Support\Format::money($row['selling_value'], currency: '') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-4 px-4 text-gray-500">Нема залиха во овој магацин.</td></tr>
                @endforelse
            </tbody>
        </table>
        </x-card>
    @else
        <x-card padding="p-0" class="overflow-hidden">
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
                        <td class="py-2 px-4">{{ $row['item_name'] }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['total_quantity'], 3) }}</td>
                        <td class="py-2 px-4 text-right">{{ \App\Support\Format::money($row['total_value'], currency: '') }}</td>
                        <td class="py-2 px-4 text-right">{{ \App\Support\Format::money($row['total_selling_value'], currency: '') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-4 px-4 text-gray-500">Нема евидентирана залиха.</td></tr>
                @endforelse
            </tbody>
        </table>
        </x-card>
    @endif
</div>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=StockOnHandReportTest`
Run: `php artisan test --filter=InventoryRoutesTest`
Expected: PASS — all tests in both files.

- [ ] **Step 5: Run the full test suite**

Run: `php artisan test`
Expected: PASS — full suite green (no regressions in other reports/screens that might share the Inventory blade components).

- [ ] **Step 6: Commit**

```bash
git add resources/views/livewire/inventory/stock-on-hand-report.blade.php tests/Feature/StockOnHandReportTest.php tests/Feature/InventoryRoutesTest.php
git commit -m "feat: show selling value and PDF link on the stock-on-hand screen"
```

---

## Deployment note (not a task — do after all 3 tasks are committed and reviewed)

Push to `main` only after explicit user go-ahead (production accounting app, matching every prior sub-project's convention). Per this project's now well-established pattern, budget for a possible CI-only MySQL failure cycle even though this plan's migrations are none (no schema change in this sub-project — `selling_price` already exists from sub-project #6) — the risk here is low but not zero (e.g. `COALESCE` and `SUM` are both standard ANSI SQL, supported identically by SQLite and MySQL, so no new migration/identifier-length/FK-index risk class applies this time).
