# Phase 3a: Sales Invoicing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build core outgoing (sales) invoicing for tami-web-app: draft/confirm/cancel invoices linked to Partners and Items, auto-posting a balanced Journal Entry and auto-issuing stock on confirm, payment recording with its own GL postings, and a downloadable PDF.

**Architecture:** Standard Laravel 13 + Livewire 3 feature module under `app/Livewire/Invoicing/`, following the exact conventions established in Phases 1–2 (`app/Models/`, `app/Policies/` auto-discovered by Laravel's naming convention, manual `company_id` tenancy scoping via `User::visibleCompanies()`, PHPUnit + `RefreshDatabase` tests). All GL-posting and stock-integration logic lives in one plain PHP service class (`SalesInvoiceService`, Livewire-independent, unit-testable on its own) that composes the existing Phase 1 `JournalEntry` model and Phase 2 `StockMovementService` — no new ledger or stock machinery, this phase only orchestrates the two systems that already exist. A small new `App\Support\Bcmath` helper provides the same round-half-up rounding Phase 2 uses, for invoice line/VAT math.

**Tech Stack:** Laravel 13.8, Livewire 3.6.4, PHP 8.3, MySQL (SQLite in tests), PHPUnit 12, `barryvdh/laravel-dompdf` (new — first PDF dependency in this codebase).

## Global Constraints

- PHP `^8.3`, Laravel `^13.8`, Livewire `^3.6.4` — match versions already in `composer.json`. The only new dependency this phase adds is `barryvdh/laravel-dompdf`, added via plain `composer require barryvdh/laravel-dompdf` (no version pinned in this plan — let Composer resolve the version compatible with the installed Laravel 13).
- Role names are the plain strings `'admin'`, `'accountant'`, `'client'` — no enum wrapper, consistent with Phases 1–2.
- Tenancy is scoped manually per-query via `$user->visibleCompanies()` — no Eloquent global scopes; do not introduce one.
- Tests are PHPUnit (not Pest), class-based, `use RefreshDatabase;`, snake_case `test_*` methods, roles re-seeded per test class via `Role::findOrCreate(...)` in `setUp()`.
- `Route::get($uri, [ClassName::class, '__invoke'])` (array-callable form, not bare class-string) is required for every new route, for the same reason documented in the existing `routes/web.php` comment: bare class-strings resolve `method_exists()` eagerly at route *registration* time and crash app boot if the target class doesn't exist yet. Task 1 registers the full `partners.` and `sales-invoices.` route groups up front (mirroring exactly how Phase 2's Task 1 registered all six `inventory.*` routes before five of their target classes existed), so every later task's "renders over HTTP" test can pass as soon as that task's own class exists.
- **Invoice numbering is assigned at confirm-time, not at draft-creation time.** The approved spec described numbering as following "the same pattern as `JournalEntry`... assigned on creation" — but `JournalEntry` has no draft state, so assigning its number at INSERT time is safe (nothing is ever discarded after that). `SalesInvoice` deliberately has a draft state that can be edited repeatedly or abandoned; assigning a legally-required sequential number at draft-creation time would burn numbers on invoices that are never actually issued, creating gaps. `sales_invoices.fiscal_year`/`invoice_number` are therefore nullable columns, populated only inside `SalesInvoiceService::confirm()`, using the identical lock-and-increment technique (`lockForUpdate()` + `DB::transaction()`) as `JournalEntry`.
- **Every invoice line requires a quantity**, whether it references an Item or is free-text — resolves an imprecise phrase in the approved spec ("quantity required when item_id is set"). A line's total is always `quantity × unit_price`; a free-text one-off charge just defaults to quantity `1` in the form rather than being exempt from having one.
- **Rounding:** a new `App\Support\Bcmath::roundHalfUp(string $value, int $scale): string` helper implements the identical round-half-up algorithm as Phase 2's `StockMovementService::bcDivRoundHalfUp()` (guard-scale division, nudge by half a unit, truncate to target scale). It's a separate small class rather than a modification to Phase 2's already-shipped, tested private method — same rounding behavior, without touching stable code for a cosmetic DRY win. Used for every invoice line/VAT amount calculation.
- **GL account codes are resolved by fixed convention**, looked up by `code` within the company's already-seeded 428-account chart (Phase 1's `OfficialChartOfAccounts`) — confirmed present in `docs/reference/official-chart-of-accounts.json`: `120` (Побарувања од купувачи — AR), `740` (Приходи од продажба... во земјата — domestic revenue), `230` (Обврски за ДДВ — VAT payable), `660` (Стоки на залиха — inventory asset), `701` (Набавна вредност на продадени добра — COGS), `100` (bank current account), `102` (cash till). No per-category account mapping table this phase.
- **`SalesInvoicePaymentPolicy` from the approved spec is consolidated into `SalesInvoicePolicy`.** Payments have no standalone screen or route — they're recorded inline from `SalesInvoiceShow`, gated by the invoice's own `update` policy check — so a distinct payment-policy class would carry no unique authorization logic.
- **`PartnerPolicy` is broadened to allow `'client'` in `create`/`update`** (Task 1) — it was previously `admin`/`accountant` only. This is a direct, low-risk consequence of the already-approved decision that Clients get full read-write on invoicing: a client needs to be able to add their own customers. `JournalEntryPolicy` (which also references partners) is untouched — clients still cannot create/edit journal entries, so no new capability leaks into accounting.
- **Each item-linked invoice line stores the exact `stock_movement_id` it produced at confirm-time** (nullable FK on `sales_invoice_lines`, set inside `SalesInvoiceService::confirm()`). This lets `cancel()` reverse stock at the *precise* cost that was issued, rather than re-deriving a cost that may have drifted if the warehouse's average cost changed since.
- **Cancellation is blocked entirely once any payment exists** against the invoice — no partial/credit-note workflow this phase, per the approved spec's explicit out-of-scope list.
- Per the approved spec: **sales invoices only** (purchase invoicing is Phase 3b); **MKD only**, no foreign currency; **PDF download only**, no real email sending (mail stays the `log` driver); **Clients get full read-write** on invoices/payments for their own company (matches `WarehousePolicy`/`ItemPolicy`, not `JournalEntryPolicy`).

---

### Task 1: Partner Management Screen + Route Registration

**Files:**
- Modify: `app/Policies/PartnerPolicy.php`
- Create: `app/Livewire/PartnerIndex.php`
- Create: `resources/views/livewire/partner-index.blade.php`
- Modify: `routes/web.php` (add `partners.` group, and register the full `sales-invoices.` group up front)
- Test: `tests/Feature/PartnerIndexTest.php`
- Test: `tests/Feature/AccountingPoliciesTest.php` (extend — assert clients can now manage partners)

**Interfaces:**
- Produces: route names `partners.index`, `sales-invoices.index`, `sales-invoices.create`, `sales-invoices.edit`, `sales-invoices.show`, `sales-invoices.pdf` — all registered now (five of six targets don't exist until later tasks).
- Produces: `PartnerPolicy::create()`/`update()` now return true for `'client'` too (previously `admin`/`accountant` only).

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\PartnerIndex;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PartnerIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_lists_the_companys_partners(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->for($company)->create(['name' => 'Acme DOOEL']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(PartnerIndex::class, ['company' => $company])
            ->assertSee('Acme DOOEL');
    }

    public function test_client_can_add_a_partner_for_their_own_company(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        Livewire::test(PartnerIndex::class, ['company' => $company])
            ->set('newName', 'Beta Customer DOO')
            ->set('newTaxId', '4001234567890')
            ->set('newEmail', 'billing@betacustomer.mk')
            ->call('addPartner')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('partners', [
            'company_id' => $company->id,
            'name' => 'Beta Customer DOO',
            'tax_id' => '4001234567890',
        ]);
    }

    public function test_adding_a_partner_requires_a_name(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(PartnerIndex::class, ['company' => $company])
            ->set('newName', '')
            ->call('addPartner')
            ->assertHasErrors(['newName' => 'required']);
    }

    public function test_the_partners_page_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('partners.index', $company))
            ->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PartnerIndexTest`
Expected: FAIL — `Class "App\Livewire\PartnerIndex" not found`.

- [ ] **Step 3: Broaden `PartnerPolicy`**

```php
<?php

namespace App\Policies;

use App\Models\Partner;
use App\Models\User;

class PartnerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Partner $partner): bool
    {
        return $user->visibleCompanies()->whereKey($partner->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'client']);
    }

    public function update(User $user, Partner $partner): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'client'])
            && $user->visibleCompanies()->whereKey($partner->company_id)->exists();
    }
}
```

- [ ] **Step 4: Write the `PartnerIndex` Livewire component**

```php
<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\Partner;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PartnerIndex extends Component
{
    public Company $company;

    public string $newName = '';

    public string $newTaxId = '';

    public string $newEmail = '';

    public string $newPhone = '';

    public string $newAddress = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function addPartner(): void
    {
        Gate::authorize('create', Partner::class);

        $validated = $this->validate([
            'newName' => 'required|string|max:255',
            'newTaxId' => 'nullable|string|max:255',
            'newEmail' => 'nullable|email|max:255',
            'newPhone' => 'nullable|string|max:255',
            'newAddress' => 'nullable|string|max:255',
        ]);

        Partner::create([
            'company_id' => $this->company->id,
            'name' => $validated['newName'],
            'tax_id' => $validated['newTaxId'] ?: null,
            'email' => $validated['newEmail'] ?: null,
            'phone' => $validated['newPhone'] ?: null,
            'address' => $validated['newAddress'] ?: null,
        ]);

        $this->reset(['newName', 'newTaxId', 'newEmail', 'newPhone', 'newAddress']);
    }

    public function render()
    {
        return view('livewire.partner-index', [
            'partners' => Partner::where('company_id', $this->company->id)->orderBy('name')->get(),
        ]);
    }
}
```

- [ ] **Step 5: Write the view**

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Partners — {{ $company->name }}</h1>

    @can('create', \App\Models\Partner::class)
        <div class="bg-white shadow rounded-md p-4 mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Add partner</h2>
            <form wire:submit="addPartner" class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newName" value="Name" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newTaxId" value="Tax ID" />
                    <x-text-input id="newTaxId" wire:model="newTaxId" class="w-40" />
                </div>
                <div>
                    <x-input-label for="newEmail" value="Email" />
                    <x-text-input id="newEmail" wire:model="newEmail" class="w-48" />
                    @error('newEmail') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newPhone" value="Phone" />
                    <x-text-input id="newPhone" wire:model="newPhone" class="w-32" />
                </div>
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newAddress" value="Address" />
                    <x-text-input id="newAddress" wire:model="newAddress" class="w-full" />
                </div>
                <x-primary-button type="submit">Add</x-primary-button>
            </form>
        </div>
    @endcan

    <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Name</th>
                <th class="py-2 px-4">Tax ID</th>
                <th class="py-2 px-4">Email</th>
                <th class="py-2 px-4">Phone</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($partners as $partner)
                <tr class="text-sm">
                    <td class="py-2 px-4">{{ $partner->name }}</td>
                    <td class="py-2 px-4">{{ $partner->tax_id }}</td>
                    <td class="py-2 px-4">{{ $partner->email }}</td>
                    <td class="py-2 px-4">{{ $partner->phone }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-4 px-4 text-gray-500">No partners yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
```

- [ ] **Step 6: Register routes**

Modify `routes/web.php` — add these imports alongside the existing ones:

```php
use App\Http\Controllers\SalesInvoicePdfController;
use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Livewire\Invoicing\SalesInvoiceIndex;
use App\Livewire\Invoicing\SalesInvoiceShow;
use App\Livewire\PartnerIndex;
```

Add these route groups after the existing `inventory.` group (before `require __DIR__.'/auth.php';`):

```php
Route::middleware(['auth'])->prefix('companies/{company}')->name('partners.')->group(function () {
    Route::get('/partners', [PartnerIndex::class, '__invoke'])->name('index');
});

// Array-callable form (not bare class-string) for the same reason as the
// accounting.* and inventory.* groups above: four of these five target
// classes don't exist until later Invoicing tasks, and a bare class-string
// would crash route registration immediately.
Route::middleware(['auth'])->prefix('companies/{company}')->name('sales-invoices.')->group(function () {
    Route::get('/sales-invoices', [SalesInvoiceIndex::class, '__invoke'])->name('index');
    Route::get('/sales-invoices/create', [SalesInvoiceForm::class, '__invoke'])->name('create');
    Route::get('/sales-invoices/{salesInvoice}/edit', [SalesInvoiceForm::class, '__invoke'])->name('edit');
    Route::get('/sales-invoices/{salesInvoice}', [SalesInvoiceShow::class, '__invoke'])->name('show');
    Route::get('/sales-invoices/{salesInvoice}/pdf', [SalesInvoicePdfController::class, '__invoke'])->name('pdf');
});
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=PartnerIndexTest`
Expected: PASS

- [ ] **Step 8: Extend `AccountingPoliciesTest` for the broadened `PartnerPolicy`**

Add this test method to `tests/Feature/AccountingPoliciesTest.php`:

```php
    public function test_client_can_manage_their_own_companys_partners(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $partner = Partner::factory()->for($company)->create();

        $this->assertTrue($client->can('create', Partner::class));
        $this->assertTrue($client->can('update', $partner));
    }
```

- [ ] **Step 9: Run the full policy test file to verify it passes**

Run: `php artisan test --filter=AccountingPoliciesTest`
Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add app/Policies/PartnerPolicy.php app/Livewire/PartnerIndex.php resources/views/livewire/partner-index.blade.php routes/web.php tests/Feature/PartnerIndexTest.php tests/Feature/AccountingPoliciesTest.php
git commit -m "feat: add partner management screen, broaden PartnerPolicy for clients, register invoicing routes"
```

---

### Task 2: Company Invoicing Fields

**Files:**
- Create: `database/migrations/2026_07_20_100000_add_invoicing_fields_to_companies_table.php`
- Modify: `app/Models/Company.php`
- Modify: `app/Policies/CompanyPolicy.php`
- Modify: `app/Livewire/CompanyIndex.php`
- Modify: `resources/views/livewire/company-index.blade.php`
- Test: `tests/Feature/CompanyIndexTest.php` (extend)
- Test: `tests/Feature/CompanyPolicyTest.php` (extend)

**Interfaces:**
- Produces: `Company` fillable gains `bank_account`, `is_vat_registered` (cast boolean, default true).
- Produces: `CompanyPolicy::update()` — admin-only.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/CompanyPolicyTest.php` (read the existing file first to match its exact structure and `setUp()`):

```php
    public function test_only_admin_can_update_a_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->assertTrue($admin->can('update', $company));
        $this->assertFalse($client->can('update', $company));
    }
```

Add to `tests/Feature/CompanyIndexTest.php`:

```php
    public function test_admin_can_update_a_companys_bank_account_and_vat_registration(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(CompanyIndex::class)
            ->call('startEdit', $company->id)
            ->set('editBankAccount', 'MK07300701104789126')
            ->set('editIsVatRegistered', false)
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'bank_account' => 'MK07300701104789126',
            'is_vat_registered' => false,
        ]);
    }

    public function test_client_cannot_update_company_settings(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        Livewire::test(CompanyIndex::class)
            ->call('startEdit', $company->id)
            ->assertForbidden();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=CompanyPolicyTest`
Run: `php artisan test --filter=CompanyIndexTest`
Expected: Both FAIL — `startEdit` method / `bank_account` column don't exist.

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
        Schema::table('companies', function (Blueprint $table) {
            $table->string('bank_account')->nullable()->after('logo_path');
            $table->boolean('is_vat_registered')->default(true)->after('bank_account');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['bank_account', 'is_vat_registered']);
        });
    }
};
```

- [ ] **Step 4: Update the `Company` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'tax_id', 'email', 'phone', 'address', 'logo_path', 'bank_account', 'is_vat_registered'];

    protected function casts(): array
    {
        return ['is_vat_registered' => 'boolean'];
    }

    public function clients(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function accountants(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
```

- [ ] **Step 5: Update `CompanyPolicy`**

```php
<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Company $company): bool
    {
        return $user->visibleCompanies()->whereKey($company->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, Company $company): bool
    {
        return $user->hasRole('admin');
    }
}
```

- [ ] **Step 6: Update the `CompanyIndex` Livewire component**

```php
<?php

namespace App\Livewire;

use App\Models\Company;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CompanyIndex extends Component
{
    public string $newName = '';

    public string $newTaxId = '';

    public string $newEmail = '';

    public string $newPhone = '';

    public string $newAddress = '';

    public ?int $editingCompanyId = null;

    public string $editBankAccount = '';

    public bool $editIsVatRegistered = true;

    public function addCompany(): void
    {
        Gate::authorize('create', Company::class);

        $validated = $this->validate([
            'newName' => 'required|string|max:255',
            'newTaxId' => 'nullable|string|max:255',
            'newEmail' => 'nullable|email|max:255',
            'newPhone' => 'nullable|string|max:255',
            'newAddress' => 'nullable|string|max:255',
        ]);

        Company::create([
            'name' => $validated['newName'],
            'tax_id' => $validated['newTaxId'] ?: null,
            'email' => $validated['newEmail'] ?: null,
            'phone' => $validated['newPhone'] ?: null,
            'address' => $validated['newAddress'] ?: null,
        ]);

        $this->reset(['newName', 'newTaxId', 'newEmail', 'newPhone', 'newAddress']);
    }

    public function startEdit(int $companyId): void
    {
        $company = Company::findOrFail($companyId);
        Gate::authorize('update', $company);

        $this->editingCompanyId = $company->id;
        $this->editBankAccount = (string) $company->bank_account;
        $this->editIsVatRegistered = $company->is_vat_registered;
    }

    public function saveEdit(): void
    {
        $company = Company::findOrFail($this->editingCompanyId);
        Gate::authorize('update', $company);

        $validated = $this->validate([
            'editBankAccount' => 'nullable|string|max:255',
            'editIsVatRegistered' => 'boolean',
        ]);

        $company->update([
            'bank_account' => $validated['editBankAccount'] ?: null,
            'is_vat_registered' => $validated['editIsVatRegistered'],
        ]);

        $this->editingCompanyId = null;
    }

    public function render()
    {
        $companies = auth()->user()->visibleCompanies()->orderBy('name')->get();

        return view('livewire.company-index', ['companies' => $companies]);
    }
}
```

- [ ] **Step 7: Add edit UI to the view**

Modify `resources/views/livewire/company-index.blade.php` — replace the `<span class="font-medium">{{ $company->name }}</span>` line and the block right after it with:

```blade
                    <div class="flex items-center justify-between">
                        <span class="font-medium">{{ $company->name }}</span>
                        @can('update', $company)
                            @if ($editingCompanyId !== $company->id)
                                <button type="button" wire:click="startEdit({{ $company->id }})" class="text-indigo-600 hover:underline text-sm">Edit settings</button>
                            @endif
                        @endcan
                    </div>

                    @if ($editingCompanyId === $company->id)
                        <div class="mt-2 mb-3 p-3 bg-gray-50 rounded-md">
                            <form wire:submit="saveEdit" class="flex flex-wrap gap-3 items-end">
                                <div>
                                    <x-input-label for="editBankAccount" value="Bank account (IBAN)" />
                                    <x-text-input id="editBankAccount" wire:model="editBankAccount" class="w-64" />
                                </div>
                                <div class="flex items-center gap-2 pb-2">
                                    <input type="checkbox" id="editIsVatRegistered" wire:model="editIsVatRegistered">
                                    <label for="editIsVatRegistered" class="text-sm">VAT registered</label>
                                </div>
                                <x-primary-button type="submit">Save</x-primary-button>
                            </form>
                        </div>
                    @endif
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test --filter=CompanyPolicyTest`
Run: `php artisan test --filter=CompanyIndexTest`
Expected: Both PASS

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_07_20_100000_add_invoicing_fields_to_companies_table.php app/Models/Company.php app/Policies/CompanyPolicy.php app/Livewire/CompanyIndex.php resources/views/livewire/company-index.blade.php tests/Feature/CompanyIndexTest.php tests/Feature/CompanyPolicyTest.php
git commit -m "feat: add company bank account and VAT-registration fields with admin-only edit"
```

---

### Task 3: Sales Invoice & Line Schema

**Files:**
- Create: `database/migrations/2026_07_20_100100_create_sales_invoices_table.php`
- Create: `database/migrations/2026_07_20_100200_create_sales_invoice_lines_table.php`
- Create: `app/Support/Bcmath.php`
- Create: `app/Models/SalesInvoice.php`
- Create: `app/Models/SalesInvoiceLine.php`
- Create: `database/factories/SalesInvoiceFactory.php`
- Create: `database/factories/SalesInvoiceLineFactory.php`
- Test: `tests/Unit/BcmathTest.php`
- Test: `tests/Unit/SalesInvoiceTest.php`
- Test: `tests/Unit/SalesInvoiceLineTest.php`

**Interfaces:**
- Produces: `App\Support\Bcmath::roundHalfUp(string $value, int $scale): string`.
- Produces: `SalesInvoice` model — fillable `['company_id', 'partner_id', 'warehouse_id', 'journal_entry_id', 'fiscal_year', 'invoice_number', 'invoice_date', 'due_date', 'status', 'sent_at', 'notes', 'created_by']`; relations `company()`, `partner()`, `warehouse()`, `journalEntry()`, `creator()`, `lines()`, `payments()` (this last relation references `SalesInvoicePayment`, whose class file is only created in Task 4 — harmless, since PHP only resolves a relation method's class reference when the method is actually called, not when `SalesInvoice` itself loads); methods `subtotal()`, `vatTotal()`, `grandTotal()`, `paidTotal()`, `balanceDue()`, `paymentStatus()`, `isOverdue()` — all return bcmath strings except the last two.
- Produces: `SalesInvoiceLine` model — fillable `['sales_invoice_id', 'item_id', 'stock_movement_id', 'description', 'quantity', 'unit_price', 'vat_rate']`; relations `salesInvoice()`, `item()`, `stockMovement()`; methods `lineTotal(): string`, `vatAmount(): string`.

- [ ] **Step 1: Write the failing unit tests**

```php
<?php

namespace Tests\Unit;

use App\Support\Bcmath;
use Tests\TestCase;

class BcmathTest extends TestCase
{
    public function test_it_rounds_half_up_at_the_given_scale(): void
    {
        $this->assertSame('1.235', Bcmath::roundHalfUp('1.2345', 3));
        $this->assertSame('1.234', Bcmath::roundHalfUp('1.2344', 3));
        $this->assertSame('10.00', Bcmath::roundHalfUp('9.995', 2));
    }

    public function test_it_rounds_negative_values_correctly(): void
    {
        $this->assertSame('-1.235', Bcmath::roundHalfUp('-1.2345', 3));
    }
}
```

```php
<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_a_company_and_partner(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);

        $this->assertTrue($invoice->company->is($company));
        $this->assertTrue($invoice->partner->is($partner));
    }

    public function test_totals_sum_across_lines_correctly(): void
    {
        $invoice = SalesInvoice::factory()->create();
        $invoice->lines()->create(['description' => 'Line A', 'quantity' => '2', 'unit_price' => '100.00', 'vat_rate' => '18.00']);
        $invoice->lines()->create(['description' => 'Line B', 'quantity' => '1', 'unit_price' => '50.00', 'vat_rate' => '18.00']);

        // Line A: 2 * 100 = 200.00 net, VAT 36.00
        // Line B: 1 * 50  = 50.00 net,  VAT 9.00
        $this->assertSame('250.00', $invoice->fresh(['lines'])->subtotal());
        $this->assertSame('45.00', $invoice->fresh(['lines'])->vatTotal());
        $this->assertSame('295.00', $invoice->fresh(['lines'])->grandTotal());
    }

    public function test_payment_status_reflects_recorded_payments(): void
    {
        $invoice = SalesInvoice::factory()->create(['status' => 'confirmed']);
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

    public function test_draft_invoices_report_payment_status_as_not_applicable(): void
    {
        $invoice = SalesInvoice::factory()->create(['status' => 'draft']);

        $this->assertSame('n/a', $invoice->paymentStatus());
    }

    public function test_is_overdue_when_unpaid_past_due_date(): void
    {
        $invoice = SalesInvoice::factory()->create(['status' => 'confirmed', 'due_date' => now()->subDay()]);
        $invoice->lines()->create(['description' => 'Line A', 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '0']);

        $this->assertTrue($invoice->fresh(['lines', 'payments'])->isOverdue());
    }
}
```

```php
<?php

namespace Tests\Unit;

use App\Models\SalesInvoiceLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoiceLineTest extends TestCase
{
    use RefreshDatabase;

    public function test_line_total_is_quantity_times_unit_price(): void
    {
        $line = SalesInvoiceLine::factory()->create(['quantity' => '3', 'unit_price' => '19.99']);

        $this->assertSame('59.97', $line->lineTotal());
    }

    public function test_vat_amount_is_line_total_times_vat_rate(): void
    {
        $line = SalesInvoiceLine::factory()->create(['quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $this->assertSame('18.00', $line->vatAmount());
    }

    public function test_zero_vat_rate_produces_zero_vat_amount(): void
    {
        $line = SalesInvoiceLine::factory()->create(['quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0.00']);

        $this->assertSame('0.00', $line->vatAmount());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=BcmathTest`
Run: `php artisan test --filter=SalesInvoiceTest`
Run: `php artisan test --filter=SalesInvoiceLineTest`
Expected: All FAIL — classes/tables not found.

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
        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $table->unsignedSmallInteger('fiscal_year')->nullable();
            $table->unsignedInteger('invoice_number')->nullable();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->string('status', 20)->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'fiscal_year', 'invoice_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoices');
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
        Schema::create('sales_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items');
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements');
            $table->string('description')->nullable();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('vat_rate', 5, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_lines');
    }
};
```

- [ ] **Step 4: Write the `Bcmath` support class**

```php
<?php

namespace App\Support;

class Bcmath
{
    /**
     * Round a bcmath string to $scale decimal places using round-half-up,
     * instead of bcmath's native truncation. Same algorithm as Phase 2's
     * StockMovementService::bcDivRoundHalfUp() — kept separate rather than
     * modifying that already-shipped, tested private method, but the
     * rounding behavior is intentionally identical.
     */
    public static function roundHalfUp(string $value, int $scale): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        if (bccomp($value, '0', $scale + 10) < 0) {
            return bcsub($value, $half, $scale);
        }

        return bcadd($value, $half, $scale);
    }
}
```

- [ ] **Step 5: Write the `SalesInvoice` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesInvoice extends Model
{
    use HasFactory;

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

Note: `SalesInvoicePayment` (used by `payments()` above) doesn't exist until Task 4. That's fine — PHP only resolves the class name when the relation method is actually called, not at class-load time (same reason the array-callable routing trick from Task 1 works).

- [ ] **Step 6: Write the `SalesInvoiceLine` model**

```php
<?php

namespace App\Models;

use App\Support\Bcmath;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesInvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = ['sales_invoice_id', 'item_id', 'stock_movement_id', 'description', 'quantity', 'unit_price', 'vat_rate'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'vat_rate' => 'decimal:2',
        ];
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
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

- [ ] **Step 7: Write the factories**

```php
<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

class SalesInvoiceFactory extends Factory
{
    public function definition(): array
    {
        $company = Company::factory();

        return [
            'company_id' => $company,
            'partner_id' => Partner::factory()->for($company),
            'warehouse_id' => null,
            'journal_entry_id' => null,
            'fiscal_year' => null,
            'invoice_number' => null,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(15)->toDateString(),
            'status' => 'draft',
            'sent_at' => null,
            'notes' => null,
            'created_by' => \App\Models\User::factory(),
        ];
    }
}
```

```php
<?php

namespace Database\Factories;

use App\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class SalesInvoiceLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sales_invoice_id' => SalesInvoice::factory(),
            'item_id' => null,
            'stock_movement_id' => null,
            'description' => $this->faker->words(3, true),
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'vat_rate' => '18.00',
        ];
    }
}
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test --filter=BcmathTest`
Run: `php artisan test --filter=SalesInvoiceTest`
Run: `php artisan test --filter=SalesInvoiceLineTest`
Expected: All PASS

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_07_20_100100_create_sales_invoices_table.php database/migrations/2026_07_20_100200_create_sales_invoice_lines_table.php app/Support/Bcmath.php app/Models/SalesInvoice.php app/Models/SalesInvoiceLine.php database/factories/SalesInvoiceFactory.php database/factories/SalesInvoiceLineFactory.php tests/Unit/BcmathTest.php tests/Unit/SalesInvoiceTest.php tests/Unit/SalesInvoiceLineTest.php
git commit -m "feat: add sales_invoices/sales_invoice_lines schema, models, and Bcmath rounding helper"
```

---

### Task 4: Sales Invoice Payment Schema & Policy

**Files:**
- Create: `database/migrations/2026_07_20_100300_create_sales_invoice_payments_table.php`
- Create: `app/Models/SalesInvoicePayment.php`
- Create: `database/factories/SalesInvoicePaymentFactory.php`
- Create: `app/Exceptions/InvalidInvoiceStateException.php`
- Create: `app/Policies/SalesInvoicePolicy.php`
- Test: `tests/Unit/SalesInvoicePaymentTest.php`

**Interfaces:**
- Produces: `SalesInvoicePayment` model — fillable `['sales_invoice_id', 'amount', 'payment_date', 'payment_method', 'created_by']`, casts `amount` to `decimal:2`, `payment_date` to `date`; relations `salesInvoice()`, `creator()`.
- Produces: `App\Exceptions\InvalidInvoiceStateException extends \RuntimeException` — used by `SalesInvoiceService` (Tasks 5–7) for every guard failure.
- Produces: `SalesInvoicePolicy` — `viewAny`/`view`/`create`/`update`, client-inclusive (matches `WarehousePolicy`/`ItemPolicy`).

- [ ] **Step 1: Write the failing unit test**

```php
<?php

namespace Tests\Unit;

use App\Models\SalesInvoice;
use App\Models\SalesInvoicePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoicePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_an_invoice_and_creator(): void
    {
        $invoice = SalesInvoice::factory()->create();
        $user = User::factory()->create();
        $payment = SalesInvoicePayment::factory()->for($invoice, 'salesInvoice')->create(['created_by' => $user->id]);

        $this->assertTrue($payment->salesInvoice->is($invoice));
        $this->assertTrue($payment->creator->is($user));
    }

    public function test_amount_is_cast_to_decimal(): void
    {
        $payment = SalesInvoicePayment::factory()->create(['amount' => '150.50']);

        $this->assertSame('150.50', (string) $payment->fresh()->amount);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SalesInvoicePaymentTest`
Expected: FAIL — `Class "App\Models\SalesInvoicePayment" not found`.

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
        Schema::create('sales_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('payment_date');
            $table->string('payment_method', 20);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_payments');
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

class SalesInvoicePayment extends Model
{
    use HasFactory;

    protected $fillable = ['sales_invoice_id', 'amount', 'payment_date', 'payment_method', 'created_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
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

use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SalesInvoicePaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sales_invoice_id' => SalesInvoice::factory(),
            'amount' => '100.00',
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank',
            'created_by' => User::factory(),
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=SalesInvoicePaymentTest`
Expected: PASS

- [ ] **Step 7: Write the exception class**

```php
<?php

namespace App\Exceptions;

class InvalidInvoiceStateException extends \RuntimeException
{
}
```

- [ ] **Step 8: Write the policy**

```php
<?php

namespace App\Policies;

use App\Models\SalesInvoice;
use App\Models\User;

class SalesInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SalesInvoice $salesInvoice): bool
    {
        return $user->visibleCompanies()->whereKey($salesInvoice->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'client']);
    }

    public function update(User $user, SalesInvoice $salesInvoice): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'client'])
            && $user->visibleCompanies()->whereKey($salesInvoice->company_id)->exists();
    }
}
```

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_07_20_100300_create_sales_invoice_payments_table.php app/Models/SalesInvoicePayment.php database/factories/SalesInvoicePaymentFactory.php app/Exceptions/InvalidInvoiceStateException.php app/Policies/SalesInvoicePolicy.php tests/Unit/SalesInvoicePaymentTest.php
git commit -m "feat: add sales_invoice_payments schema, SalesInvoicePolicy, and InvalidInvoiceStateException"
```

---

### Task 5: Sales Invoice Service — Confirm

**Files:**
- Create: `app/Services/Invoicing/SalesInvoiceService.php`
- Test: `tests/Unit/SalesInvoiceServiceTest.php`

**Interfaces:**
- Consumes: `SalesInvoice`/`SalesInvoiceLine` (Task 3), `SalesInvoicePayment`/`InvalidInvoiceStateException` (Task 4), `App\Services\Inventory\StockMovementService::issue()` (existing, Phase 2), `App\Models\JournalEntry`/`JournalEntryLine` (existing, Phase 1), `App\Models\Account` (existing, Phase 1).
- Produces: `SalesInvoiceService::confirm(SalesInvoice $invoice, int $userId): SalesInvoice`. Private helper `account(Company $company, string $code): Account` (reused by Tasks 6–7 — do not duplicate, extend this same class).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private SalesInvoiceService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = app(SalesInvoiceService::class);
    }

    private function seedAccounts(Company $company): void
    {
        foreach ([
            ['code' => '120', 'name' => 'AR'],
            ['code' => '740', 'name' => 'Revenue'],
            ['code' => '230', 'name' => 'VAT Payable'],
            ['code' => '660', 'name' => 'Inventory Asset'],
            ['code' => '701', 'name' => 'COGS'],
            ['code' => '100', 'name' => 'Bank'],
            ['code' => '102', 'name' => 'Cash'],
        ] as $account) {
            Account::factory()->for($company)->create($account);
        }
    }

    public function test_confirming_a_service_only_invoice_posts_ar_revenue_and_vat(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame(2026, $confirmed->fiscal_year);
        $this->assertSame(1, $confirmed->invoice_number);
        $this->assertNotNull($confirmed->journal_entry_id);

        $entry = $confirmed->journalEntry()->with('lines.account')->first();
        $this->assertCount(3, $entry->lines);

        $ar = $entry->lines->firstWhere('account.code', '120');
        $revenue = $entry->lines->firstWhere('account.code', '740');
        $vat = $entry->lines->firstWhere('account.code', '230');

        $this->assertSame('1180.00', (string) $ar->debit);
        $this->assertSame('1000.00', (string) $revenue->credit);
        $this->assertSame('180.00', (string) $vat->credit);
    }

    public function test_confirming_an_item_line_issues_stock_and_posts_cogs(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create(['vat_rate' => '18.00']);
        $user = User::factory()->create();

        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', '2026-01-01', $user->id);

        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'warehouse_id' => $warehouse->id,
            'invoice_date' => '2026-03-01',
        ]);
        $line = $invoice->lines()->create(['item_id' => $item->id, 'description' => $item->name, 'quantity' => '4', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $this->assertSame('6.000', (string) \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first()->quantity_on_hand);

        $entry = $confirmed->journalEntry()->with('lines.account')->first();
        $this->assertCount(5, $entry->lines);

        $cogs = $entry->lines->firstWhere('account.code', '701');
        $inventoryAsset = $entry->lines->firstWhere('account.code', '660');

        // 4 units issued at the receipted cost of 50.00 each = 200.00 COGS
        $this->assertSame('200.00', (string) $cogs->debit);
        $this->assertSame('200.00', (string) $inventoryAsset->credit);

        $this->assertSame($line->fresh()->stock_movement_id, \App\Models\StockMovement::where('item_id', $item->id)->where('type', 'issue')->first()->id);
    }

    public function test_confirming_skips_vat_when_company_is_not_vat_registered(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => false]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $entry = $confirmed->journalEntry()->with('lines.account')->first();
        $this->assertCount(2, $entry->lines);
        $this->assertNull($entry->lines->firstWhere('account.code', '230'));
    }

    public function test_invoice_numbers_are_sequential_per_company_per_fiscal_year(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();

        foreach ([1, 2] as $n) {
            $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-0'.$n]);
            $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '0']);
            $confirmed = $this->service->confirm($invoice->fresh(), $user->id);
            $this->assertSame($n, $confirmed->invoice_number);
        }
    }

    public function test_confirming_requires_at_least_one_line(): void
    {
        $invoice = SalesInvoice::factory()->create();
        $user = User::factory()->create();

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->confirm($invoice, $user->id);
    }

    public function test_confirming_an_already_confirmed_invoice_throws(): void
    {
        $invoice = SalesInvoice::factory()->create(['status' => 'confirmed']);
        $user = User::factory()->create();

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->confirm($invoice, $user->id);
    }

    public function test_confirming_an_item_line_without_a_warehouse_throws(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['warehouse_id' => null]);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => $item->name, 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '0']);
        $user = User::factory()->create();

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->confirm($invoice->fresh(), $user->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SalesInvoiceServiceTest`
Expected: FAIL — `Class "App\Services\Invoicing\SalesInvoiceService" not found`.

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services\Invoicing;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\SalesInvoice;
use App\Services\Inventory\StockMovementService;
use Illuminate\Support\Facades\DB;

class SalesInvoiceService
{
    public function __construct(private StockMovementService $stockMovementService)
    {
    }

    public function confirm(SalesInvoice $invoice, int $userId): SalesInvoice
    {
        if ($invoice->status !== 'draft') {
            throw new InvalidInvoiceStateException("Invoice #{$invoice->id} is not a draft and cannot be confirmed.");
        }

        $invoice->loadMissing(['lines', 'company']);

        if ($invoice->lines->isEmpty()) {
            throw new InvalidInvoiceStateException('An invoice needs at least one line before it can be confirmed.');
        }

        $hasItemLines = $invoice->lines->contains(fn ($line) => $line->item_id !== null);

        if ($hasItemLines && $invoice->warehouse_id === null) {
            throw new InvalidInvoiceStateException('A warehouse is required to confirm an invoice with item lines.');
        }

        return DB::transaction(function () use ($invoice, $userId) {
            $fiscalYear = $invoice->invoice_date->year;
            $maxNumber = SalesInvoice::where('company_id', $invoice->company_id)
                ->where('fiscal_year', $fiscalYear)
                ->lockForUpdate()
                ->max('invoice_number');
            $invoiceNumber = ($maxNumber ?? 0) + 1;

            $cogsTotal = '0.00';

            foreach ($invoice->lines as $line) {
                if ($line->item_id === null) {
                    continue;
                }

                $movement = $this->stockMovementService->issue(
                    $line->item,
                    $invoice->warehouse,
                    (string) $line->quantity,
                    $invoice->invoice_date->toDateString(),
                    $userId
                );

                $line->update(['stock_movement_id' => $movement->id]);
                $cogsTotal = bcadd($cogsTotal, bcmul((string) $line->quantity, (string) $movement->unit_cost, 2), 2);
            }

            $vatRegistered = $invoice->company->is_vat_registered;
            $net = $invoice->subtotal();
            $vat = $vatRegistered ? $invoice->vatTotal() : '0.00';
            $gross = bcadd($net, $vat, 2);
            $label = "Invoice {$fiscalYear}/{$invoiceNumber}";

            $entry = JournalEntry::create([
                'company_id' => $invoice->company_id,
                'entry_date' => $invoice->invoice_date,
                'description' => "Sales {$label}",
                'created_by' => $userId,
            ]);

            $entry->lines()->create([
                'account_id' => $this->account($invoice->company, '120')->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'debit' => $gross,
                'credit' => '0',
            ]);

            $entry->lines()->create([
                'account_id' => $this->account($invoice->company, '740')->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'debit' => '0',
                'credit' => $net,
            ]);

            if (bccomp($vat, '0', 2) > 0) {
                $entry->lines()->create([
                    'account_id' => $this->account($invoice->company, '230')->id,
                    'partner_id' => $invoice->partner_id,
                    'description' => "VAT on {$label}",
                    'debit' => '0',
                    'credit' => $vat,
                ]);
            }

            if (bccomp($cogsTotal, '0', 2) > 0) {
                $entry->lines()->create([
                    'account_id' => $this->account($invoice->company, '701')->id,
                    'description' => "COGS for {$label}",
                    'debit' => $cogsTotal,
                    'credit' => '0',
                ]);

                $entry->lines()->create([
                    'account_id' => $this->account($invoice->company, '660')->id,
                    'description' => "COGS for {$label}",
                    'debit' => '0',
                    'credit' => $cogsTotal,
                ]);
            }

            $invoice->update([
                'fiscal_year' => $fiscalYear,
                'invoice_number' => $invoiceNumber,
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

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SalesInvoiceServiceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Invoicing/SalesInvoiceService.php tests/Unit/SalesInvoiceServiceTest.php
git commit -m "feat: add SalesInvoiceService::confirm() with GL posting and stock issue"
```

---

### Task 6: Sales Invoice Service — Cancel

**Files:**
- Modify: `app/Services/Invoicing/SalesInvoiceService.php`
- Modify: `tests/Unit/SalesInvoiceServiceTest.php`

**Interfaces:**
- Consumes: `SalesInvoiceService::account()` (Task 5, unchanged), `App\Services\Inventory\StockMovementService::receipt()` (existing, Phase 2).
- Produces: `SalesInvoiceService::cancel(SalesInvoice $invoice, int $userId): SalesInvoice`.

- [ ] **Step 1: Add the failing tests**

Add to `tests/Unit/SalesInvoiceServiceTest.php`:

```php
    public function test_cancelling_a_confirmed_invoice_reverses_gl_and_stock(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();
        $user = User::factory()->create();

        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', '2026-01-01', $user->id);

        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'warehouse_id' => $warehouse->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => $item->name, 'quantity' => '4', 'unit_price' => '100.00', 'vat_rate' => '18.00']);
        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $cancelled = $this->service->cancel($confirmed, $user->id);

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('10.000', (string) \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first()->quantity_on_hand);

        $reversal = \App\Models\JournalEntry::where('company_id', $company->id)->where('id', '!=', $confirmed->journal_entry_id)->with('lines')->first();
        $this->assertNotNull($reversal);

        $originalTotalDebit = $confirmed->journalEntry->lines->sum('debit');
        $reversalTotalCredit = $reversal->lines->sum('credit');
        $this->assertSame((string) $originalTotalDebit, (string) $reversalTotalCredit);
    }

    public function test_cancelling_a_draft_invoice_throws(): void
    {
        $invoice = SalesInvoice::factory()->create(['status' => 'draft']);
        $user = User::factory()->create();

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->cancel($invoice, $user->id);
    }

    public function test_cancelling_an_invoice_with_a_payment_throws(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);
        $this->service->recordPayment($confirmed, '50.00', '2026-03-05', 'bank', $user->id);

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->cancel($confirmed->fresh(), $user->id);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SalesInvoiceServiceTest`
Expected: FAIL — `cancel()`/`recordPayment()` methods don't exist yet (the payment test references `recordPayment`, built in Task 7 — it's written now because `cancel()`'s payment-block guard needs it to test properly; it will start passing once Task 7 lands, which is the very next task).

- [ ] **Step 3: Add `cancel()` to the service**

Modify `app/Services/Invoicing/SalesInvoiceService.php` — add this method to the class, after `confirm()`:

```php
    public function cancel(SalesInvoice $invoice, int $userId): SalesInvoice
    {
        if ($invoice->status !== 'confirmed') {
            throw new InvalidInvoiceStateException("Invoice #{$invoice->id} is not confirmed and cannot be cancelled.");
        }

        if ($invoice->payments()->exists()) {
            throw new InvalidInvoiceStateException('An invoice with recorded payments cannot be cancelled.');
        }

        $invoice->loadMissing(['lines.item', 'lines.stockMovement', 'journalEntry.lines', 'warehouse', 'company']);

        return DB::transaction(function () use ($invoice, $userId) {
            foreach ($invoice->lines as $line) {
                if ($line->item_id === null) {
                    continue;
                }

                $this->stockMovementService->receipt(
                    $line->item,
                    $invoice->warehouse,
                    (string) $line->quantity,
                    (string) $line->stockMovement->unit_cost,
                    now()->toDateString(),
                    $userId
                );
            }

            $reversal = JournalEntry::create([
                'company_id' => $invoice->company_id,
                'entry_date' => now()->toDateString(),
                'description' => "Reversal of invoice {$invoice->fiscal_year}/{$invoice->invoice_number}",
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

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=SalesInvoiceServiceTest`
Expected: `test_cancelling_a_confirmed_invoice_reverses_gl_and_stock` and `test_cancelling_a_draft_invoice_throws` PASS. `test_cancelling_an_invoice_with_a_payment_throws` still FAILS (`recordPayment()` doesn't exist) — expected at this point, resolved by Task 7.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Invoicing/SalesInvoiceService.php tests/Unit/SalesInvoiceServiceTest.php
git commit -m "feat: add SalesInvoiceService::cancel() with GL reversal and stock receipt"
```

---

### Task 7: Sales Invoice Service — Record Payment

**Files:**
- Modify: `app/Services/Invoicing/SalesInvoiceService.php`
- Modify: `tests/Unit/SalesInvoiceServiceTest.php`

**Interfaces:**
- Produces: `SalesInvoiceService::recordPayment(SalesInvoice $invoice, string $amount, string $paymentDate, string $paymentMethod, int $userId): SalesInvoicePayment`.

- [ ] **Step 1: Add the failing tests**

Add to `tests/Unit/SalesInvoiceServiceTest.php`:

```php
    public function test_recording_a_payment_posts_bank_debit_and_ar_credit(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $payment = $this->service->recordPayment($confirmed, '60.00', '2026-03-10', 'bank', $user->id);

        $this->assertSame('60.00', (string) $payment->amount);
        $this->assertSame('partially_paid', $confirmed->fresh(['lines', 'payments'])->paymentStatus());

        $entry = \App\Models\JournalEntry::where('company_id', $company->id)->where('id', '!=', $confirmed->journal_entry_id)->with('lines.account')->first();
        $bank = $entry->lines->firstWhere('account.code', '100');
        $ar = $entry->lines->firstWhere('account.code', '120');

        $this->assertSame('60.00', (string) $bank->debit);
        $this->assertSame('60.00', (string) $ar->credit);
    }

    public function test_recording_a_cash_payment_debits_the_cash_account(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $this->service->recordPayment($confirmed, '100.00', '2026-03-10', 'cash', $user->id);

        $entry = \App\Models\JournalEntry::where('company_id', $company->id)->where('id', '!=', $confirmed->journal_entry_id)->with('lines.account')->first();
        $cash = $entry->lines->firstWhere('account.code', '102');
        $this->assertSame('100.00', (string) $cash->debit);
    }

    public function test_payment_cannot_exceed_the_remaining_balance(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->recordPayment($confirmed, '150.00', '2026-03-10', 'bank', $user->id);
    }

    public function test_payment_on_a_draft_invoice_throws(): void
    {
        $invoice = SalesInvoice::factory()->create(['status' => 'draft']);
        $user = User::factory()->create();

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->recordPayment($invoice, '10.00', '2026-03-10', 'bank', $user->id);
    }
```

- [ ] **Step 2: Run tests to verify the new ones fail**

Run: `php artisan test --filter=SalesInvoiceServiceTest`
Expected: The four new tests FAIL — `recordPayment()` doesn't exist. `test_cancelling_an_invoice_with_a_payment_throws` (from Task 6) now also becomes runnable — it should still FAIL for the same reason until Step 3 lands.

- [ ] **Step 3: Add `recordPayment()` to the service**

Modify `app/Services/Invoicing/SalesInvoiceService.php`:
1. Add `use App\Models\SalesInvoicePayment;` to the `use` imports at the top.
2. Add this method to the class, after `cancel()`:

```php
    public function recordPayment(SalesInvoice $invoice, string $amount, string $paymentDate, string $paymentMethod, int $userId): SalesInvoicePayment
    {
        if ($invoice->status !== 'confirmed') {
            throw new InvalidInvoiceStateException("Invoice #{$invoice->id} is not confirmed; payments can only be recorded against confirmed invoices.");
        }

        $invoice->loadMissing(['lines', 'payments', 'company']);

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
            $label = "Payment for invoice {$invoice->fiscal_year}/{$invoice->invoice_number}";

            $entry = JournalEntry::create([
                'company_id' => $invoice->company_id,
                'entry_date' => $paymentDate,
                'description' => $label,
                'created_by' => $userId,
            ]);

            $entry->lines()->create([
                'account_id' => $this->account($invoice->company, $cashOrBankCode)->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'debit' => $amount,
                'credit' => '0',
            ]);

            $entry->lines()->create([
                'account_id' => $this->account($invoice->company, '120')->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'debit' => '0',
                'credit' => $amount,
            ]);

            return $payment;
        });
    }
```

- [ ] **Step 4: Run the full service test file**

Run: `php artisan test --filter=SalesInvoiceServiceTest`
Expected: All PASS (including `test_cancelling_an_invoice_with_a_payment_throws` from Task 6).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Invoicing/SalesInvoiceService.php tests/Unit/SalesInvoiceServiceTest.php
git commit -m "feat: add SalesInvoiceService::recordPayment() with bank/cash GL posting"
```

---

### Task 8: Sales Invoice Form (Draft Create/Edit)

**Files:**
- Create: `app/Livewire/Invoicing/SalesInvoiceForm.php`
- Create: `resources/views/livewire/invoicing/sales-invoice-form.blade.php`
- Test: `tests/Feature/SalesInvoiceFormTest.php`

**Interfaces:**
- Consumes: `SalesInvoice`/`SalesInvoiceLine` (Task 3), `SalesInvoicePolicy` (Task 4), route `sales-invoices.show` (Task 1, target built in Task 9 — the redirect target won't resolve to a working page until then, which is fine, the same staged-dependency pattern as every other multi-task route group in this codebase).
- Produces: draft creation/editing only. Does not call `SalesInvoiceService` — confirming happens from `SalesInvoiceShow` (Task 9).

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceFormTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_creates_a_draft_invoice_with_a_free_text_line(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.description', 'Consulting services')
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_price', '500.00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sales_invoices', ['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'draft']);
        $this->assertDatabaseHas('sales_invoice_lines', ['description' => 'Consulting services', 'quantity' => '2.000', 'unit_price' => '500.00']);
    }

    public function test_selecting_an_item_prefills_description_and_vat_rate(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Widget', 'vat_rate' => '18.00']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->call('selectItem', 0, (string) $item->id)
            ->assertSet('lines.0.description', 'Widget')
            ->assertSet('lines.0.vat_rate', '18.00');
    }

    public function test_an_item_line_without_a_warehouse_is_rejected(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.item_id', (string) $item->id)
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '10.00')
            ->call('save')
            ->assertHasErrors(['warehouseId']);
    }

    public function test_a_confirmed_invoice_cannot_be_edited(): void
    {
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['status' => 'confirmed']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertForbidden();
    }

    public function test_client_can_create_a_draft_invoice_for_their_own_company(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines.0.description', 'Service')
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '10.00')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_the_create_page_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('sales-invoices.create', $company))
            ->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SalesInvoiceFormTest`
Expected: FAIL — `Class "App\Livewire\Invoicing\SalesInvoiceForm" not found`.

- [ ] **Step 3: Write the Livewire component**

```php
<?php

namespace App\Livewire\Invoicing;

use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SalesInvoiceForm extends Component
{
    public Company $company;

    public ?SalesInvoice $salesInvoice = null;

    public string $partnerId = '';

    public string $warehouseId = '';

    public string $invoiceDate = '';

    public string $dueDate = '';

    public string $notes = '';

    public array $lines = [];

    public function mount(Company $company, ?SalesInvoice $salesInvoice = null): void
    {
        $this->company = $company;

        Gate::authorize($salesInvoice ? 'update' : 'create', $salesInvoice ?? SalesInvoice::class);

        if ($salesInvoice) {
            if ($salesInvoice->company_id !== $company->id) {
                abort(404);
            }

            if ($salesInvoice->status !== 'draft') {
                abort(403, 'Only draft invoices can be edited.');
            }
        }

        $this->salesInvoice = $salesInvoice;

        if ($salesInvoice) {
            $this->partnerId = (string) $salesInvoice->partner_id;
            $this->warehouseId = $salesInvoice->warehouse_id === null ? '' : (string) $salesInvoice->warehouse_id;
            $this->invoiceDate = $salesInvoice->invoice_date->toDateString();
            $this->dueDate = $salesInvoice->due_date->toDateString();
            $this->notes = (string) $salesInvoice->notes;
            $this->lines = $salesInvoice->lines->map(fn ($line) => [
                'item_id' => $line->item_id === null ? '' : (string) $line->item_id,
                'description' => (string) $line->description,
                'quantity' => (string) $line->quantity,
                'unit_price' => (string) $line->unit_price,
                'vat_rate' => (string) $line->vat_rate,
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
            'description' => '',
            'quantity' => '1',
            'unit_price' => '0',
            'vat_rate' => $this->company->is_vat_registered ? '18.00' : '0.00',
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

        $item = Item::where('company_id', $this->company->id)->find($itemId);

        if ($item) {
            $this->lines[$index]['description'] = $item->name;
            $this->lines[$index]['vat_rate'] = $this->company->is_vat_registered ? (string) $item->vat_rate : '0.00';
        }
    }

    public function save(): void
    {
        Gate::authorize($this->salesInvoice ? 'update' : 'create', $this->salesInvoice ?? SalesInvoice::class);

        $this->validate([
            'partnerId' => ['required', Rule::exists('partners', 'id')->where('company_id', $this->company->id)],
            'warehouseId' => ['nullable', Rule::exists('warehouses', 'id')->where('company_id', $this->company->id)],
            'invoiceDate' => 'required|date',
            'dueDate' => 'required|date|after_or_equal:invoiceDate',
            'lines' => 'required|array|min:1',
            'lines.*.item_id' => ['nullable', Rule::exists('items', 'id')->where('company_id', $this->company->id)],
            'lines.*.description' => 'nullable|string|max:255',
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.vat_rate' => 'required|numeric|min:0|max:100',
        ]);

        foreach ($this->lines as $index => $line) {
            if (($line['item_id'] ?? '') === '' && trim((string) ($line['description'] ?? '')) === '') {
                $this->addError("lines.{$index}.description", 'Each line needs an item or a description.');

                return;
            }
        }

        $hasItemLines = collect($this->lines)->contains(fn ($line) => ($line['item_id'] ?? '') !== '');

        if ($hasItemLines && $this->warehouseId === '') {
            $this->addError('warehouseId', 'A warehouse is required when any line references an item.');

            return;
        }

        DB::transaction(function () {
            $invoice = $this->salesInvoice ?? new SalesInvoice([
                'company_id' => $this->company->id,
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);
            $invoice->company_id = $this->company->id;
            $invoice->partner_id = $this->partnerId;
            $invoice->warehouse_id = $this->warehouseId ?: null;
            $invoice->invoice_date = $this->invoiceDate;
            $invoice->due_date = $this->dueDate;
            $invoice->notes = $this->notes ?: null;

            if (! $invoice->exists) {
                $invoice->status = 'draft';
                $invoice->created_by = auth()->id();
            }

            $invoice->save();
            $invoice->lines()->delete();

            foreach ($this->lines as $line) {
                $invoice->lines()->create([
                    'item_id' => $line['item_id'] ?: null,
                    'description' => $line['description'] ?: null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'vat_rate' => $line['vat_rate'],
                ]);
            }

            $this->salesInvoice = $invoice;
        });

        $this->redirect(route('sales-invoices.show', [$this->company, $this->salesInvoice]));
    }

    public function render()
    {
        return view('livewire.invoicing.sales-invoice-form', [
            'partners' => Partner::where('company_id', $this->company->id)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('company_id', $this->company->id)->where('is_active', true)->orderBy('name')->get(),
            'items' => Item::where('company_id', $this->company->id)->where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}
```

- [ ] **Step 4: Write the view**

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">
        {{ $salesInvoice ? 'Edit draft invoice' : 'New sales invoice' }} — {{ $company->name }}
    </h1>

    <form wire:submit="save" class="space-y-6">
        <div class="bg-white shadow rounded-md p-4 grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div>
                <x-input-label for="partnerId" value="Customer" />
                <select id="partnerId" wire:model="partnerId" class="w-full border-gray-300 rounded-md text-sm">
                    <option value="">Select a customer</option>
                    @foreach ($partners as $partner)
                        <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                    @endforeach
                </select>
                @error('partnerId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
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
                <x-input-label for="invoiceDate" value="Invoice date" />
                <x-text-input id="invoiceDate" type="date" wire:model="invoiceDate" class="w-full" />
                @error('invoiceDate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="dueDate" value="Due date" />
                <x-text-input id="dueDate" type="date" wire:model="dueDate" class="w-full" />
                @error('dueDate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="bg-white shadow rounded-md p-4">
            <h2 class="font-semibold text-gray-700 mb-3">Lines</h2>
            @foreach ($lines as $index => $line)
                <div class="flex flex-wrap gap-3 items-end mb-3 pb-3 border-b border-gray-100">
                    <div class="w-48">
                        <x-input-label value="Item (optional)" />
                        <select wire:change="selectItem({{ $index }}, $event.target.value)" class="w-full border-gray-300 rounded-md text-sm">
                            <option value="">— free text —</option>
                            @foreach ($items as $item)
                                <option value="{{ $item->id }}" @selected($line['item_id'] === (string) $item->id)>{{ $item->code }} — {{ $item->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex-1 min-w-[12rem]">
                        <x-input-label value="Description" />
                        <x-text-input wire:model="lines.{{ $index }}.description" class="w-full" />
                        @error("lines.{$index}.description") <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
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

Run: `php artisan test --filter=SalesInvoiceFormTest`
Expected: All pass except `test_the_create_page_renders_successfully_over_http`, which will FAIL until `sales-invoices.show`'s target exists — actually since this route only requires the `SalesInvoiceForm` class itself (the `create` route target), it should PASS now. If it fails, check that route registration from Task 1 points `sales-invoices.create` at `[SalesInvoiceForm::class, '__invoke']`.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Invoicing/SalesInvoiceForm.php resources/views/livewire/invoicing/sales-invoice-form.blade.php tests/Feature/SalesInvoiceFormTest.php
git commit -m "feat: add sales invoice draft create/edit form"
```

---

### Task 9: Sales Invoice Show (Confirm, Cancel, Payments)

**Files:**
- Create: `app/Livewire/Invoicing/SalesInvoiceShow.php`
- Create: `resources/views/livewire/invoicing/sales-invoice-show.blade.php`
- Test: `tests/Feature/SalesInvoiceShowTest.php`

**Interfaces:**
- Consumes: `SalesInvoiceService` (Tasks 5–7), `InsufficientStockException` (existing, Phase 2), `InvalidInvoiceStateException` (Task 4).

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\SalesInvoiceShow;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceShowTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    private function seedAccounts(Company $company): void
    {
        foreach (['120', '740', '230', '660', '701', '100', '102'] as $code) {
            \App\Models\Account::factory()->for($company)->create(['code' => $code, 'name' => $code]);
        }
    }

    public function test_confirming_a_draft_invoice_from_the_show_screen(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->call('confirm')
            ->assertHasNoErrors();

        $this->assertSame('confirmed', $invoice->fresh()->status);
    }

    public function test_confirming_with_insufficient_stock_shows_an_error(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'warehouse_id' => $warehouse->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => $item->name, 'quantity' => '5', 'unit_price' => '10.00', 'vat_rate' => '0']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->call('confirm')
            ->assertHasErrors(['confirm']);

        $this->assertSame('draft', $invoice->fresh()->status);
    }

    public function test_recording_a_payment_and_cancel_button_is_hidden_once_paid(): void
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
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->set('paymentAmount', '100.00')
            ->set('paymentDate', '2026-03-10')
            ->set('paymentMethod', 'bank')
            ->call('recordPayment')
            ->assertHasNoErrors()
            ->assertDontSee('Cancel invoice');

        $this->assertDatabaseHas('sales_invoice_payments', ['sales_invoice_id' => $invoice->id, 'amount' => '100.00']);
    }

    public function test_mark_sent_sets_sent_at(): void
    {
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['status' => 'confirmed']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->call('markSent');

        $this->assertNotNull($invoice->fresh()->sent_at);
    }

    public function test_client_cannot_view_another_companys_invoice(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($otherCompany)->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(SalesInvoiceShow::class, ['company' => $otherCompany, 'salesInvoice' => $invoice])
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SalesInvoiceShowTest`
Expected: FAIL — `Class "App\Livewire\Invoicing\SalesInvoiceShow" not found`.

- [ ] **Step 3: Write the Livewire component**

```php
<?php

namespace App\Livewire\Invoicing;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SalesInvoiceShow extends Component
{
    public Company $company;

    public SalesInvoice $salesInvoice;

    public string $paymentAmount = '';

    public string $paymentDate = '';

    public string $paymentMethod = 'bank';

    public function mount(Company $company, SalesInvoice $salesInvoice): void
    {
        Gate::authorize('view', $salesInvoice);

        if ($salesInvoice->company_id !== $company->id) {
            abort(404);
        }

        $this->company = $company;
        $this->salesInvoice = $salesInvoice;
        $this->paymentDate = now()->toDateString();
    }

    public function confirm(SalesInvoiceService $service): void
    {
        Gate::authorize('update', $this->salesInvoice);

        try {
            $service->confirm($this->salesInvoice, auth()->id());
        } catch (InsufficientStockException|InvalidInvoiceStateException $e) {
            $this->addError('confirm', $e->getMessage());

            return;
        }

        $this->salesInvoice->refresh();
    }

    public function cancel(SalesInvoiceService $service): void
    {
        Gate::authorize('update', $this->salesInvoice);

        try {
            $service->cancel($this->salesInvoice, auth()->id());
        } catch (InvalidInvoiceStateException $e) {
            $this->addError('cancel', $e->getMessage());

            return;
        }

        $this->salesInvoice->refresh();
    }

    public function recordPayment(SalesInvoiceService $service): void
    {
        Gate::authorize('update', $this->salesInvoice);

        $this->validate([
            'paymentAmount' => 'required|numeric|min:0.01',
            'paymentDate' => 'required|date',
            'paymentMethod' => 'required|in:bank,cash',
        ]);

        try {
            $service->recordPayment($this->salesInvoice, $this->paymentAmount, $this->paymentDate, $this->paymentMethod, auth()->id());
        } catch (InvalidInvoiceStateException $e) {
            $this->addError('paymentAmount', $e->getMessage());

            return;
        }

        $this->reset(['paymentAmount']);
        $this->salesInvoice->refresh();
    }

    public function markSent(): void
    {
        Gate::authorize('update', $this->salesInvoice);

        $this->salesInvoice->update(['sent_at' => now()]);
    }

    public function render()
    {
        $invoice = $this->salesInvoice->fresh(['lines.item', 'payments', 'partner']);

        return view('livewire.invoicing.sales-invoice-show', [
            'invoice' => $invoice,
        ]);
    }
}
```

- [ ] **Step 4: Write the view**

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">
        {{ $invoice->status === 'confirmed' ? "Invoice {$invoice->fiscal_year}/{$invoice->invoice_number}" : 'Draft invoice' }}
    </h1>
    <p class="text-sm text-gray-500 mb-4">{{ $invoice->partner->name }} — status: {{ $invoice->status }}
        @if ($invoice->status === 'confirmed') ({{ $invoice->paymentStatus() }}@if($invoice->isOverdue()), overdue @endif) @endif
    </p>

    @error('confirm') <p class="text-red-600 text-sm mb-3">{{ $message }}</p> @enderror
    @error('cancel') <p class="text-red-600 text-sm mb-3">{{ $message }}</p> @enderror

    <div class="bg-white shadow rounded-md p-4 mb-4">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-1">Description</th>
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
                        <td class="py-1">{{ $line->quantity }}</td>
                        <td class="py-1">{{ $line->unit_price }}</td>
                        <td class="py-1">{{ $line->vat_rate }}</td>
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
            <a href="{{ route('sales-invoices.edit', [$company, $invoice]) }}" class="text-indigo-600 hover:underline text-sm">Edit</a>
            <button type="button" wire:click="confirm" class="bg-indigo-600 text-white px-3 py-1.5 rounded-md text-sm">Confirm</button>
        @endif
        @if ($invoice->status === 'confirmed')
            <a href="{{ route('sales-invoices.pdf', [$company, $invoice]) }}" class="text-indigo-600 hover:underline text-sm">Download PDF</a>
            @if (! $invoice->sent_at)
                <button type="button" wire:click="markSent" class="text-indigo-600 hover:underline text-sm">Mark as sent</button>
            @endif
            @if ($invoice->payments->isEmpty())
                <button type="button" wire:click="cancel" class="text-red-600 hover:underline text-sm">Cancel invoice</button>
            @endif
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

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=SalesInvoiceShowTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Invoicing/SalesInvoiceShow.php resources/views/livewire/invoicing/sales-invoice-show.blade.php tests/Feature/SalesInvoiceShowTest.php
git commit -m "feat: add sales invoice show screen with confirm, cancel, payment, and mark-sent actions"
```

---

### Task 10: Sales Invoice Index

**Files:**
- Create: `app/Livewire/Invoicing/SalesInvoiceIndex.php`
- Create: `resources/views/livewire/invoicing/sales-invoice-index.blade.php`
- Test: `tests/Feature/SalesInvoiceIndexTest.php`

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\SalesInvoiceIndex;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_it_lists_the_companys_invoices(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Acme']);
        SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'draft']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceIndex::class, ['company' => $company])
            ->assertSee('Acme');
    }

    public function test_status_filter_narrows_the_list(): void
    {
        $company = Company::factory()->create();
        $draftPartner = Partner::factory()->for($company)->create(['name' => 'Draft Customer']);
        $confirmedPartner = Partner::factory()->for($company)->create(['name' => 'Confirmed Customer']);
        SalesInvoice::factory()->for($company)->create(['partner_id' => $draftPartner->id, 'status' => 'draft']);
        SalesInvoice::factory()->for($company)->create(['partner_id' => $confirmedPartner->id, 'status' => 'confirmed', 'fiscal_year' => 2026, 'invoice_number' => 1]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceIndex::class, ['company' => $company])
            ->set('statusFilter', 'confirmed')
            ->assertSee('Confirmed Customer')
            ->assertDontSee('Draft Customer');
    }

    public function test_the_index_page_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('sales-invoices.index', $company))
            ->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SalesInvoiceIndexTest`
Expected: FAIL — `Class "App\Livewire\Invoicing\SalesInvoiceIndex" not found`.

- [ ] **Step 3: Write the Livewire component**

```php
<?php

namespace App\Livewire\Invoicing;

use App\Models\Company;
use App\Models\SalesInvoice;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SalesInvoiceIndex extends Component
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
        $invoices = SalesInvoice::where('company_id', $this->company->id)
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->with(['partner', 'lines', 'payments'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->get();

        return view('livewire.invoicing.sales-invoice-index', ['invoices' => $invoices]);
    }
}
```

- [ ] **Step 4: Write the view**

```blade
<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Sales Invoices — {{ $company->name }}</h1>
        <a href="{{ route('sales-invoices.create', $company) }}" class="bg-indigo-600 text-white px-3 py-1.5 rounded-md text-sm">New invoice</a>
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
                <th class="py-2 px-4">Number</th>
                <th class="py-2 px-4">Customer</th>
                <th class="py-2 px-4">Date</th>
                <th class="py-2 px-4">Status</th>
                <th class="py-2 px-4">Total</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($invoices as $invoice)
                <tr class="text-sm">
                    <td class="py-2 px-4">{{ $invoice->invoice_number ? "{$invoice->fiscal_year}/{$invoice->invoice_number}" : '—' }}</td>
                    <td class="py-2 px-4">{{ $invoice->partner->name }}</td>
                    <td class="py-2 px-4">{{ $invoice->invoice_date->toDateString() }}</td>
                    <td class="py-2 px-4">{{ $invoice->status }}</td>
                    <td class="py-2 px-4">{{ $invoice->grandTotal() }}</td>
                    <td class="py-2 px-4">
                        <a href="{{ route('sales-invoices.show', [$company, $invoice]) }}" class="text-indigo-600 hover:underline">View</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-4 px-4 text-gray-500">No invoices yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=SalesInvoiceIndexTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Invoicing/SalesInvoiceIndex.php resources/views/livewire/invoicing/sales-invoice-index.blade.php tests/Feature/SalesInvoiceIndexTest.php
git commit -m "feat: add sales invoice list screen with status filter"
```

---

### Task 11: PDF Generation

**Files:**
- Modify: `composer.json` (via `composer require`)
- Create: `app/Http/Controllers/SalesInvoicePdfController.php`
- Create: `resources/views/pdf/sales-invoice.blade.php`
- Test: `tests/Feature/SalesInvoicePdfTest.php`

- [ ] **Step 1: Install the PDF package**

Run: `composer require barryvdh/laravel-dompdf`
Expected: Package installs cleanly against the existing Laravel 13/PHP 8.3 constraints; `composer.json`/`composer.lock` are updated.

- [ ] **Step 2: Write the failing feature test**

```php
<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_it_downloads_a_pdf_for_a_confirmed_invoice(): void
    {
        $company = Company::factory()->create(['name' => 'Fajnens Badi DOOEL', 'bank_account' => 'MK07300701104789126']);
        $partner = Partner::factory()->for($company)->create(['name' => 'Customer DOO']);
        $entry = JournalEntry::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'confirmed',
            'fiscal_year' => 2026,
            'invoice_number' => 1,
            'journal_entry_id' => $entry->id,
        ]);
        $invoice->lines()->create(['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '500.00', 'vat_rate' => '18.00']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('sales-invoices.pdf', [$company, $invoice]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_a_draft_invoice_cannot_be_downloaded_as_pdf(): void
    {
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['status' => 'draft']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('sales-invoices.pdf', [$company, $invoice]))
            ->assertStatus(403);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=SalesInvoicePdfTest`
Expected: FAIL — `Class "App\Http\Controllers\SalesInvoicePdfController" not found`.

- [ ] **Step 4: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\SalesInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;

class SalesInvoicePdfController extends Controller
{
    public function __invoke(Company $company, SalesInvoice $salesInvoice)
    {
        Gate::authorize('view', $salesInvoice);

        abort_if($salesInvoice->company_id !== $company->id, 404);
        abort_if($salesInvoice->status !== 'confirmed', 403, 'Only confirmed invoices can be downloaded as PDF.');

        $salesInvoice->load(['lines', 'partner', 'company']);

        $pdf = Pdf::loadView('pdf.sales-invoice', ['invoice' => $salesInvoice]);

        return $pdf->download("invoice-{$salesInvoice->fiscal_year}-{$salesInvoice->invoice_number}.pdf");
    }
}
```

- [ ] **Step 5: Write the PDF view**

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        .totals { text-align: right; margin-top: 12px; }
        .header { display: flex; justify-content: space-between; margin-bottom: 16px; }
    </style>
</head>
<body>
    <h1>Invoice {{ $invoice->fiscal_year }}/{{ $invoice->invoice_number }}</h1>

    <div class="header">
        <div>
            <strong>{{ $invoice->company->name }}</strong><br>
            {{ $invoice->company->address }}<br>
            Tax ID: {{ $invoice->company->tax_id }}<br>
            @if ($invoice->company->bank_account)
                Bank account: {{ $invoice->company->bank_account }}<br>
            @endif
        </div>
        <div>
            <strong>Bill to:</strong><br>
            {{ $invoice->partner->name }}<br>
            {{ $invoice->partner->address }}<br>
            @if ($invoice->partner->tax_id)
                Tax ID: {{ $invoice->partner->tax_id }}<br>
            @endif
        </div>
        <div>
            Invoice date: {{ $invoice->invoice_date->toDateString() }}<br>
            Due date: {{ $invoice->due_date->toDateString() }}
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th>Qty</th>
                <th>Unit price</th>
                <th>VAT %</th>
                <th>Line total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td>{{ $line->quantity }}</td>
                    <td>{{ $line->unit_price }}</td>
                    <td>{{ $line->vat_rate }}</td>
                    <td>{{ $line->lineTotal() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div>Subtotal: {{ $invoice->subtotal() }}</div>
        <div>VAT: {{ $invoice->vatTotal() }}</div>
        <div><strong>Total: {{ $invoice->grandTotal() }}</strong></div>
    </div>
</body>
</html>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=SalesInvoicePdfTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock app/Http/Controllers/SalesInvoicePdfController.php resources/views/pdf/sales-invoice.blade.php tests/Feature/SalesInvoicePdfTest.php
git commit -m "feat: add sales invoice PDF download via barryvdh/laravel-dompdf"
```

---

### Task 12: Companies-List Links, Navigation, and Cross-Cutting Tests

**Files:**
- Modify: `resources/views/livewire/company-index.blade.php`
- Modify: `resources/views/livewire/layout/navigation.blade.php`
- Test: `tests/Feature/InvoicingRoutesTest.php`
- Test: `tests/Feature/SalesInvoicePoliciesTest.php`
- Test: `tests/Feature/CompanyIndexTest.php` (extend)

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoicingRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_all_invoicing_routes_render_successfully_for_an_admin(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->for($company)->create();
        Warehouse::factory()->for($company)->create();
        Item::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('partners.index', $company))->assertOk();
        $this->get(route('sales-invoices.index', $company))->assertOk();
        $this->get(route('sales-invoices.create', $company))->assertOk();
    }

    public function test_invoicing_routes_require_authentication(): void
    {
        $company = Company::factory()->create();

        $this->get(route('partners.index', $company))->assertRedirect(route('login'));
        $this->get(route('sales-invoices.index', $company))->assertRedirect(route('login'));
    }
}
```

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoicePoliciesTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_client_can_manage_their_own_companys_invoices(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $invoice = SalesInvoice::factory()->for($company)->create();

        $this->assertTrue($client->can('create', SalesInvoice::class));
        $this->assertTrue($client->can('update', $invoice));
        $this->assertTrue($client->can('view', $invoice));
    }

    public function test_client_cannot_manage_another_companys_invoices(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $invoice = SalesInvoice::factory()->for($otherCompany)->create();

        $this->assertFalse($client->can('view', $invoice));
        $this->assertFalse($client->can('update', $invoice));
    }

    public function test_accountant_not_assigned_to_a_company_cannot_view_its_invoices(): void
    {
        $companyTheyManage = Company::factory()->create();
        $companyTheyDoNotManage = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($companyTheyManage);
        $invoice = SalesInvoice::factory()->for($companyTheyDoNotManage)->create();

        $this->assertFalse($accountant->can('view', $invoice));
    }

    public function test_admin_can_manage_invoices_for_any_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $invoice = SalesInvoice::factory()->for($company)->create();

        $this->assertTrue($admin->can('update', $invoice));
    }
}
```

Add to `tests/Feature/CompanyIndexTest.php`:

```php
    public function test_the_companies_list_links_to_invoicing_screens_for_a_visible_company(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['name' => 'Alpha Ltd']);

        $this->actingAs($admin);

        Livewire::test(CompanyIndex::class)
            ->assertSeeHtml(route('partners.index', $company))
            ->assertSeeHtml(route('sales-invoices.index', $company))
            ->assertSeeHtml(route('sales-invoices.create', $company));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=InvoicingRoutesTest`
Run: `php artisan test --filter=SalesInvoicePoliciesTest`
Run: `php artisan test --filter=CompanyIndexTest`
Expected: `InvoicingRoutesTest`/`SalesInvoicePoliciesTest` PASS already (all pieces exist from Tasks 1–10) — the new `CompanyIndexTest` method FAILS (no links in the view yet).

- [ ] **Step 3: Add companies-list links**

Modify `resources/views/livewire/company-index.blade.php` — add this block right after the existing "Record movement:" block (still inside the `@foreach ($companies as $company)` loop):

```blade
                    <div class="mt-1 text-sm text-gray-500">Invoicing:</div>
                    <div class="space-x-3 text-sm">
                        <a href="{{ route('partners.index', $company) }}" class="text-indigo-600 hover:underline">Partners</a>
                        <a href="{{ route('sales-invoices.index', $company) }}" class="text-indigo-600 hover:underline">Sales Invoices</a>
                        <a href="{{ route('sales-invoices.create', $company) }}" class="text-indigo-600 hover:underline">New Invoice</a>
                    </div>
```

- [ ] **Step 4: Extend nav active-state highlighting**

Modify `resources/views/livewire/layout/navigation.blade.php` — update the `:active` condition on the "Companies" nav link:

```blade
                    <x-nav-link :href="route('companies.index')" :active="request()->routeIs('companies.*') || request()->routeIs('accounting.*') || request()->routeIs('inventory.*') || request()->routeIs('partners.*') || request()->routeIs('sales-invoices.*')">
                        {{ __('Companies') }}
                    </x-nav-link>
```

- [ ] **Step 5: Run all new/modified tests**

Run: `php artisan test --filter=InvoicingRoutesTest`
Run: `php artisan test --filter=SalesInvoicePoliciesTest`
Run: `php artisan test --filter=CompanyIndexTest`
Expected: All PASS

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test`
Expected: All tests across the whole application PASS (no regressions in Phases 0–2).

- [ ] **Step 7: Commit**

```bash
git add resources/views/livewire/company-index.blade.php resources/views/livewire/layout/navigation.blade.php tests/Feature/InvoicingRoutesTest.php tests/Feature/SalesInvoicePoliciesTest.php tests/Feature/CompanyIndexTest.php
git commit -m "feat: link invoicing screens from the companies list and extend nav active-state highlighting"
```

---

## Out of Scope (this plan)

- Purchase (incoming) invoicing — Phase 3b, a separate follow-on plan.
- е-Фактура integration — Phase 8.
- Real email sending — SMTP setup is a separate follow-up once an email account/service is chosen.
- Foreign-currency invoices, credit notes, per-category GL account overrides, recurring invoices — all per the approved design spec's out-of-scope list.
