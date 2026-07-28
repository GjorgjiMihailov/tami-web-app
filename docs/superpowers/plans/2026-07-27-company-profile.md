# Company Profile (Почетна) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every company a full profile (identity fields, up to 5 bank accounts, logo + position, invoice footer note), editable from an inline "Уреди" form on Почетна (`CompanyDashboard`), and retire the old minimal inline edit on the Companies list (`CompanyIndex`).

**Architecture:** Three migrations extend `companies` and add a new `company_bank_accounts` child table, replacing the old single `bank_account` string column. `CompanyIndex` loses its inline edit entirely. `CompanyDashboard` gains an inline (non-modal) edit form gated on `Gate::authorize('update', $company)`, built incrementally: scalar fields first, then a repeatable bank-account block (plain Livewire array property, auto-reveals a new blank row as the last one is filled, capped at 5, delete-and-reinsert on save), then logo upload (`WithFileUploads`, `public` disk) + position radio + footer note textarea.

**Tech Stack:** Laravel 13 + Livewire 3, SQLite (tests) / MySQL (prod), Tailwind, existing `<x-card>`/`<x-input-label>`/`<x-text-input>`/`<x-primary-button>` Blade components.

## Global Constraints

- All new `companies` columns are nullable/optional except the already-required `name`.
- Bank accounts: max 5 rows per company, stored in a separate `company_bank_accounts` table (FK `company_id`, cascade delete), synced by delete-and-reinsert on every save.
- Logo storage is the local `public` disk (`storage/app/public`), not Google Drive — uploaded via Livewire's `WithFileUploads`, subject to the existing 25MB upload limit (`config/livewire.php`, already raised in Phase 4a).
- `CompanyDashboard`'s edit form is gated on `Gate::authorize('update', $company)` — admin-only, unchanged policy.
- `CompanyIndex`'s inline edit (`editingCompanyId`/`editBankAccount`/`editIsVatRegistered` + `startEdit`/`saveEdit`) is removed entirely — full profile editing happens exclusively on `CompanyDashboard` (Почетна) from this point on.
- Test command for this project: `php artisan test --filter=<ClassName>`.

---

## File Structure

- `database/migrations/2026_07_27_090000_add_profile_fields_to_companies_table.php` — new scalar columns on `companies`.
- `database/migrations/2026_07_27_090100_create_company_bank_accounts_table.php` — new child table.
- `database/migrations/2026_07_27_090200_migrate_company_bank_account_to_company_bank_accounts.php` — copies existing `bank_account` values into rows (data-only, no schema change).
- `database/migrations/2026_07_27_090300_drop_bank_account_from_companies_table.php` — drops the now-migrated column.
- `app/Models/Company.php` — modify: new fillable fields, `bankAccounts()` relation, `bank_account` removed from fillable.
- `app/Models/CompanyBankAccount.php` — new model.
- `app/Livewire/CompanyIndex.php` — modify: remove inline edit.
- `resources/views/livewire/company-index.blade.php` — modify: remove inline edit markup.
- `resources/views/pdf/sales-invoice.blade.php` — modify: switch the bank-account line from the dropped `bank_account` field to the new `bankAccounts` relation (real consumer the spec's grep missed — see Task 4).
- `tests/Feature/SalesInvoicePdfTest.php` — modify: fixture no longer sets `bank_account` via factory.
- `app/Livewire/CompanyDashboard.php` — modify: add edit form (scalar fields → bank accounts → logo/position/footer note, built up across Tasks 5-7).
- `resources/views/livewire/company-dashboard.blade.php` — modify: same, in step with the component.
- `tests/Feature/CompanyBankAccountMigrationTest.php` — new.
- `tests/Feature/CompanyTest.php` — modify: new fields + relation coverage.
- `tests/Feature/CompanyIndexTest.php` — modify: remove obsolete edit tests.
- `tests/Feature/CompanyDashboardTest.php` — modify: add edit-form, bank-account, and logo coverage.

---

### Task 1: Company profile scalar fields (migration + model)

**Files:**
- Create: `database/migrations/2026_07_27_090000_add_profile_fields_to_companies_table.php`
- Modify: `app/Models/Company.php`
- Test: `tests/Feature/CompanyTest.php`

**Interfaces:**
- Produces: `companies` columns `short_name`, `registration_number`, `nkd_code`, `nkd_name`, `website`, `director_name`, `director_phone`, `director_email`, `logo_position` (string, default `'left'`), `invoice_footer_note` (text, nullable), all readable/writable via `Company::$fillable`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/CompanyTest.php`:

```php
    public function test_a_company_can_be_created_with_profile_fields(): void
    {
        $company = Company::factory()->create([
            'short_name' => 'ТФ',
            'registration_number' => '1234567',
            'nkd_code' => '62.01',
            'nkd_name' => 'Компјутерско програмирање',
            'website' => 'https://example.mk',
            'director_name' => 'Марко Марковски',
            'director_phone' => '070123456',
            'director_email' => 'marko@example.mk',
            'logo_position' => 'center',
            'invoice_footer_note' => 'Ви благодариме за соработката.',
        ]);

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'short_name' => 'ТФ',
            'registration_number' => '1234567',
            'nkd_code' => '62.01',
            'nkd_name' => 'Компјутерско програмирање',
            'website' => 'https://example.mk',
            'director_name' => 'Марко Марковски',
            'director_phone' => '070123456',
            'director_email' => 'marko@example.mk',
            'logo_position' => 'center',
            'invoice_footer_note' => 'Ви благодариме за соработката.',
        ]);
    }

    public function test_logo_position_defaults_to_left(): void
    {
        $company = Company::factory()->create();

        $this->assertEquals('left', $company->fresh()->logo_position);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CompanyTest`
Expected: FAIL — `short_name` (and siblings) are not fillable / columns don't exist (`SQLSTATE... no such column`).

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_27_090000_add_profile_fields_to_companies_table.php`:

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
            $table->string('short_name')->nullable()->after('name');
            $table->string('registration_number')->nullable()->after('tax_id');
            $table->string('nkd_code')->nullable()->after('registration_number');
            $table->string('nkd_name')->nullable()->after('nkd_code');
            $table->string('website')->nullable()->after('address');
            $table->string('director_name')->nullable()->after('website');
            $table->string('director_phone')->nullable()->after('director_name');
            $table->string('director_email')->nullable()->after('director_phone');
            $table->string('logo_position')->default('left')->after('logo_path');
            $table->text('invoice_footer_note')->nullable()->after('is_vat_registered');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'short_name',
                'registration_number',
                'nkd_code',
                'nkd_name',
                'website',
                'director_name',
                'director_phone',
                'director_email',
                'logo_position',
                'invoice_footer_note',
            ]);
        });
    }
};
```

- [ ] **Step 4: Update the model**

In `app/Models/Company.php`, replace the `$fillable` line:

```php
    protected $fillable = [
        'name', 'short_name', 'tax_id', 'registration_number', 'nkd_code', 'nkd_name',
        'email', 'phone', 'address', 'website', 'director_name', 'director_phone', 'director_email',
        'logo_path', 'logo_position', 'bank_account', 'is_vat_registered', 'invoice_footer_note',
    ];
```

(`bank_account` stays in `$fillable` for now — it's removed in Task 4, after `CompanyIndex`'s inline edit that writes it is removed in Task 3.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=CompanyTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_27_090000_add_profile_fields_to_companies_table.php app/Models/Company.php tests/Feature/CompanyTest.php
git commit -m "feat: add company profile fields (short name, ЕМБС, НКД, website, director contact, logo position, invoice footer note)"
```

---

### Task 2: `company_bank_accounts` table + model + relation

**Files:**
- Create: `database/migrations/2026_07_27_090100_create_company_bank_accounts_table.php`
- Create: `app/Models/CompanyBankAccount.php`
- Modify: `app/Models/Company.php`
- Test: `tests/Feature/CompanyTest.php`

**Interfaces:**
- Consumes: `Company` model (Task 1).
- Produces: `App\Models\CompanyBankAccount` (fillable `company_id`, `bank_name`, `account_number`, `position`) and `Company::bankAccounts(): HasMany`, ordered by `position` ascending — later tasks (5-7) rely on both.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/CompanyTest.php`:

```php
    public function test_a_company_can_have_up_to_five_bank_accounts_ordered_by_position(): void
    {
        $company = Company::factory()->create();
        $company->bankAccounts()->create(['bank_name' => 'НЛБ Банка', 'account_number' => 'MK07200002785123453', 'position' => 1]);
        $company->bankAccounts()->create(['bank_name' => 'Комерцијална банка', 'account_number' => 'MK07300701104789126', 'position' => 0]);

        $this->assertEquals(
            ['Комерцијална банка', 'НЛБ Банка'],
            $company->bankAccounts()->pluck('bank_name')->all()
        );
    }

    public function test_deleting_a_company_deletes_its_bank_accounts(): void
    {
        $company = Company::factory()->create();
        $company->bankAccounts()->create(['bank_name' => 'НЛБ Банка', 'account_number' => 'MK07200002785123453', 'position' => 0]);

        $company->delete();

        $this->assertDatabaseCount('company_bank_accounts', 0);
    }
```

Add `use App\Models\CompanyBankAccount;` is not required for these two tests (they go through the relation only).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CompanyTest`
Expected: FAIL — `Call to undefined method App\Models\Company::bankAccounts()`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_27_090100_create_company_bank_accounts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_bank_accounts');
    }
};
```

- [ ] **Step 4: Create the model**

Create `app/Models/CompanyBankAccount.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyBankAccount extends Model
{
    protected $fillable = ['company_id', 'bank_name', 'account_number', 'position'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
```

- [ ] **Step 5: Add the relation to `Company`**

In `app/Models/Company.php`, add the import and method:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;
```

(already imported for `clients()`/`accountants()` — check before adding a duplicate `use`.)

```php
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CompanyBankAccount::class)->orderBy('position');
    }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=CompanyTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_27_090100_create_company_bank_accounts_table.php app/Models/CompanyBankAccount.php app/Models/Company.php tests/Feature/CompanyTest.php
git commit -m "feat: add company_bank_accounts table and Company::bankAccounts() relation"
```

---

### Task 3: Remove `CompanyIndex`'s inline edit form

**Files:**
- Modify: `app/Livewire/CompanyIndex.php`
- Modify: `resources/views/livewire/company-index.blade.php`
- Test: `tests/Feature/CompanyIndexTest.php`

**Interfaces:**
- Produces: `CompanyIndex` with only `newName`/`newTaxId`/`newEmail`/`newPhone`/`newAddress` + `addCompany()` — no edit capability. (Task 5 rebuilds full profile editing on `CompanyDashboard`; `bank_account`/`is_vat_registered` editing is intentionally unavailable between this task and Task 5 — acceptable since this plan lands as one deploy, not incrementally in production.)

This task removes code+tests; it isn't new-feature TDD, so the flow below proves the removal is real (existing tests fail against the still-editable component) before deleting the tests.

- [ ] **Step 1: Remove the inline edit form from the component**

Replace the full contents of `app/Livewire/CompanyIndex.php`:

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

    public function render()
    {
        $companies = auth()->user()->visibleCompanies()->orderBy('name')->get();

        return view('livewire.company-index', ['companies' => $companies]);
    }
}
```

- [ ] **Step 2: Remove the inline edit markup from the view**

Replace the full contents of `resources/views/livewire/company-index.blade.php`:

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Фирми</h1>

    @can('create', \App\Models\Company::class)
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Додади фирма</h2>
            <form wire:submit="addCompany" class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newName" value="Назив" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newTaxId" value="ЕДБ" />
                    <x-text-input id="newTaxId" wire:model="newTaxId" class="w-40" />
                    @error('newTaxId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newEmail" value="Е-пошта" />
                    <x-text-input id="newEmail" wire:model="newEmail" class="w-48" />
                    @error('newEmail') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newPhone" value="Телефон" />
                    <x-text-input id="newPhone" wire:model="newPhone" class="w-32" />
                    @error('newPhone') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newAddress" value="Адреса" />
                    <x-text-input id="newAddress" wire:model="newAddress" class="w-full" />
                    @error('newAddress') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <x-primary-button type="submit">Додади фирма</x-primary-button>
            </form>
        </x-card>
    @endcan

    @if ($companies->isEmpty())
        <p class="text-gray-500">Нема додадено фирми.</p>
    @else
        <ul class="divide-y divide-gray-200">
            @foreach ($companies as $company)
                <li class="py-3">
                    <span class="font-medium">{{ $company->name }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
```

- [ ] **Step 3: Run the existing test suite to confirm the obsolete tests now fail**

Run: `php artisan test --filter=CompanyIndexTest`
Expected: FAIL — `test_admin_can_update_a_companys_bank_account_and_vat_registration` and `test_client_cannot_update_company_settings` fail with `Method Livewire\Livewire...->call('startEdit')` / `BadMethodCallException` (the method no longer exists).

- [ ] **Step 4: Remove the obsolete tests**

In `tests/Feature/CompanyIndexTest.php`, delete the two test methods `test_admin_can_update_a_companys_bank_account_and_vat_registration` and `test_client_cannot_update_company_settings` (the last two methods in the file). Every other test in the file is unaffected.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=CompanyIndexTest`
Expected: PASS (all remaining tests green).

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/CompanyIndex.php resources/views/livewire/company-index.blade.php tests/Feature/CompanyIndexTest.php
git commit -m "refactor: remove CompanyIndex inline edit — full profile editing moves to Почетна"
```

---

### Task 4: Migrate `bank_account` data and drop the column

**Note on the spec:** the design spec states `bank_account` is "only read/written in `CompanyIndex`... and `Company::$fillable`" per an earlier grep. That grep missed a real consumer: `resources/views/pdf/sales-invoice.blade.php` reads `$invoice->company->bank_account` directly (and `tests/Feature/SalesInvoicePdfTest.php` sets it via `Company::factory()->create(['bank_account' => ...])`). This task fixes both as part of the cutover — dropping the column without this fix would break the live sales-invoice PDF's bank-account line.

**Files:**
- Create: `database/migrations/2026_07_27_090200_migrate_company_bank_account_to_company_bank_accounts.php`
- Create: `database/migrations/2026_07_27_090300_drop_bank_account_from_companies_table.php`
- Modify: `app/Models/Company.php`
- Modify: `resources/views/pdf/sales-invoice.blade.php`
- Modify: `tests/Feature/SalesInvoicePdfTest.php`
- Test: `tests/Feature/CompanyBankAccountMigrationTest.php`

**Interfaces:**
- Consumes: `CompanyBankAccount` (Task 2), `Company::bankAccounts()` (Task 2).
- Produces: no more `bank_account` column/fillable entry — `company_bank_accounts` is the only source of truth from here on. The sales-invoice PDF now lists every row from `$invoice->company->bankAccounts` instead of a single field (a minimal compatibility fix, not the full invoice redesign — that's sub-project 3, already scoped separately).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CompanyBankAccountMigrationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompanyBankAccountMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_copies_an_existing_bank_account_value_into_a_bank_account_row(): void
    {
        $company = Company::factory()->create();

        Schema::table('companies', function (Blueprint $table) {
            $table->string('bank_account')->nullable();
        });

        DB::table('companies')->where('id', $company->id)->update([
            'bank_account' => 'MK07300701104789126',
        ]);

        (require database_path('migrations/2026_07_27_090200_migrate_company_bank_account_to_company_bank_accounts.php'))->up();

        $this->assertDatabaseHas('company_bank_accounts', [
            'company_id' => $company->id,
            'bank_name' => null,
            'account_number' => 'MK07300701104789126',
            'position' => 0,
        ]);

        // Restore final schema state (column-less) for the rest of the suite.
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('bank_account');
        });
    }

    public function test_a_company_with_no_bank_account_value_gets_no_row(): void
    {
        $company = Company::factory()->create();

        Schema::table('companies', function (Blueprint $table) {
            $table->string('bank_account')->nullable();
        });

        (require database_path('migrations/2026_07_27_090200_migrate_company_bank_account_to_company_bank_accounts.php'))->up();

        $this->assertDatabaseCount('company_bank_accounts', 0);

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('bank_account');
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CompanyBankAccountMigrationTest`
Expected: FAIL — `require(...2026_07_27_090200_migrate_company_bank_account_to_company_bank_accounts.php): Failed to open stream`.

- [ ] **Step 3: Write the data migration**

Create `database/migrations/2026_07_27_090200_migrate_company_bank_account_to_company_bank_accounts.php`:

```php
<?php

use App\Models\Company;
use App\Models\CompanyBankAccount;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Company::whereNotNull('bank_account')
            ->where('bank_account', '!=', '')
            ->each(function (Company $company) {
                CompanyBankAccount::create([
                    'company_id' => $company->id,
                    'bank_name' => null,
                    'account_number' => $company->bank_account,
                    'position' => 0,
                ]);
            });
    }

    public function down(): void
    {
        // Intentional no-op: reversing would mean picking one of up to 5
        // company_bank_accounts rows to collapse back into a single string
        // column, which is lossy and not a safe automatic operation. The
        // paired schema migration that drops `bank_account` is likewise a
        // documented no-op on down() — same precedent as the Phase 4a
        // purchase-invoice source-document migration.
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CompanyBankAccountMigrationTest`
Expected: PASS

- [ ] **Step 5: Write the drop-column migration**

Create `database/migrations/2026_07_27_090300_drop_bank_account_from_companies_table.php`:

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
            $table->dropColumn('bank_account');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('bank_account')->nullable()->after('logo_path');
        });
    }
};
```

- [ ] **Step 6: Remove `bank_account` from the model's fillable**

In `app/Models/Company.php`, update `$fillable`:

```php
    protected $fillable = [
        'name', 'short_name', 'tax_id', 'registration_number', 'nkd_code', 'nkd_name',
        'email', 'phone', 'address', 'website', 'director_name', 'director_phone', 'director_email',
        'logo_path', 'logo_position', 'is_vat_registered', 'invoice_footer_note',
    ];
```

- [ ] **Step 7: Run the full suite to check for other consumers**

Run: `php artisan test`
Expected: FAIL — `SalesInvoicePdfTest::test_it_downloads_a_pdf_for_a_confirmed_invoice` fails with a `QueryException` (`bank_account` is not a fillable/existing column any more). This confirms the spec's grep missed a real consumer.

- [ ] **Step 8: Fix the sales-invoice PDF template**

In `resources/views/pdf/sales-invoice.blade.php`, replace:

```blade
            @if ($invoice->company->bank_account)
                Трансакциска сметка: {{ $invoice->company->bank_account }}<br>
            @endif
```

with:

```blade
            @foreach ($invoice->company->bankAccounts as $bankAccount)
                Трансакциска сметка{{ $bankAccount->bank_name ? " ({$bankAccount->bank_name})" : '' }}: {{ $bankAccount->account_number }}<br>
            @endforeach
```

- [ ] **Step 9: Fix the PDF test's fixture**

In `tests/Feature/SalesInvoicePdfTest.php`, replace:

```php
        $company = Company::factory()->create(['name' => 'Fajnens Badi DOOEL', 'bank_account' => 'MK07300701104789126']);
```

with:

```php
        $company = Company::factory()->create(['name' => 'Fajnens Badi DOOEL']);
        $company->bankAccounts()->create(['bank_name' => 'Комерцијална банка', 'account_number' => 'MK07300701104789126', 'position' => 0]);
```

- [ ] **Step 10: Run the full suite again to verify it passes**

Run: `php artisan test`
Expected: PASS (this also proves Task 3's removal of `CompanyIndex`'s edit form was complete, and that the PDF template is the only other consumer — no other leftover reference to the dropped column).

- [ ] **Step 11: Commit**

```bash
git add database/migrations/2026_07_27_090200_migrate_company_bank_account_to_company_bank_accounts.php database/migrations/2026_07_27_090300_drop_bank_account_from_companies_table.php app/Models/Company.php resources/views/pdf/sales-invoice.blade.php tests/Feature/SalesInvoicePdfTest.php tests/Feature/CompanyBankAccountMigrationTest.php
git commit -m "feat: migrate bank_account data into company_bank_accounts and drop the old column"
```

---

### Task 5: `CompanyDashboard` — "Уреди" button and scalar profile fields

**Files:**
- Modify: `app/Livewire/CompanyDashboard.php`
- Modify: `resources/views/livewire/company-dashboard.blade.php`
- Test: `tests/Feature/CompanyDashboardTest.php`

**Interfaces:**
- Consumes: `Company` fillable fields (Task 1), `CompanyPolicy::update` (unchanged).
- Produces: `CompanyDashboard::startEdit()`, `::cancelEdit()`, `::save()` and public properties `$editing`, `$editName`, `$editShortName`, `$editTaxId`, `$editRegistrationNumber`, `$editNkdCode`, `$editNkdName`, `$editEmail`, `$editPhone`, `$editWebsite`, `$editAddress`, `$editDirectorName`, `$editDirectorPhone`, `$editDirectorEmail`, `$editIsVatRegistered` — Tasks 6-7 add `$bankAccounts`, `$newLogo`, `$editLogoPosition`, `$editInvoiceFooterNote` and extend `save()`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/CompanyDashboardTest.php` (add `use Illuminate\Support\Facades\Gate;` is not needed; keep existing imports):

```php
    public function test_admin_sees_the_edit_button(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->assertSee('Уреди');
    }

    public function test_non_admin_does_not_see_the_edit_button(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->assertDontSee('Уреди');
    }

    public function test_non_admin_cannot_start_editing(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->assertForbidden();
    }

    public function test_admin_can_edit_the_companys_profile_fields(): void
    {
        $company = Company::factory()->create(['name' => 'Стара фирма ДОО']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('editName', 'Ажурирана фирма ДОО')
            ->set('editShortName', 'АФ')
            ->set('editRegistrationNumber', '1234567')
            ->set('editNkdCode', '62.01')
            ->set('editNkdName', 'Компјутерско програмирање')
            ->set('editWebsite', 'https://example.mk')
            ->set('editDirectorName', 'Марко Марковски')
            ->set('editDirectorPhone', '070123456')
            ->set('editDirectorEmail', 'marko@example.mk')
            ->set('editIsVatRegistered', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => 'Ажурирана фирма ДОО',
            'short_name' => 'АФ',
            'registration_number' => '1234567',
            'nkd_code' => '62.01',
            'nkd_name' => 'Компјутерско програмирање',
            'website' => 'https://example.mk',
            'director_name' => 'Марко Марковски',
            'director_phone' => '070123456',
            'director_email' => 'marko@example.mk',
            'is_vat_registered' => false,
        ]);
    }

    public function test_editing_the_profile_requires_a_name(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('editName', '')
            ->call('save')
            ->assertHasErrors(['editName' => 'required']);
    }

    public function test_non_admin_cannot_call_save(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->call('save')
            ->assertForbidden();
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CompanyDashboardTest`
Expected: FAIL — `Uncaught Error: Call to undefined method App\Livewire\CompanyDashboard::startEdit()` and similar for `save`.

- [ ] **Step 3: Implement the component**

Replace the full contents of `app/Livewire/CompanyDashboard.php`:

```php
<?php

namespace App\Livewire;

use App\Models\Company;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CompanyDashboard extends Component
{
    public Company $company;

    public bool $editing = false;

    public string $editName = '';

    public string $editShortName = '';

    public string $editTaxId = '';

    public string $editRegistrationNumber = '';

    public string $editNkdCode = '';

    public string $editNkdName = '';

    public string $editEmail = '';

    public string $editPhone = '';

    public string $editWebsite = '';

    public string $editAddress = '';

    public string $editDirectorName = '';

    public string $editDirectorPhone = '';

    public string $editDirectorEmail = '';

    public bool $editIsVatRegistered = true;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function startEdit(): void
    {
        Gate::authorize('update', $this->company);

        $this->editName = $this->company->name;
        $this->editShortName = (string) $this->company->short_name;
        $this->editTaxId = (string) $this->company->tax_id;
        $this->editRegistrationNumber = (string) $this->company->registration_number;
        $this->editNkdCode = (string) $this->company->nkd_code;
        $this->editNkdName = (string) $this->company->nkd_name;
        $this->editEmail = (string) $this->company->email;
        $this->editPhone = (string) $this->company->phone;
        $this->editWebsite = (string) $this->company->website;
        $this->editAddress = (string) $this->company->address;
        $this->editDirectorName = (string) $this->company->director_name;
        $this->editDirectorPhone = (string) $this->company->director_phone;
        $this->editDirectorEmail = (string) $this->company->director_email;
        $this->editIsVatRegistered = $this->company->is_vat_registered;

        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
    }

    public function save(): void
    {
        Gate::authorize('update', $this->company);

        $validated = $this->validate([
            'editName' => 'required|string|max:255',
            'editShortName' => 'nullable|string|max:255',
            'editTaxId' => 'nullable|string|max:255',
            'editRegistrationNumber' => 'nullable|string|max:255',
            'editNkdCode' => 'nullable|string|max:255',
            'editNkdName' => 'nullable|string|max:255',
            'editEmail' => 'nullable|email|max:255',
            'editPhone' => 'nullable|string|max:255',
            'editWebsite' => 'nullable|string|max:255',
            'editAddress' => 'nullable|string|max:255',
            'editDirectorName' => 'nullable|string|max:255',
            'editDirectorPhone' => 'nullable|string|max:255',
            'editDirectorEmail' => 'nullable|email|max:255',
            'editIsVatRegistered' => 'boolean',
        ]);

        $this->company->update([
            'name' => $validated['editName'],
            'short_name' => $validated['editShortName'] ?: null,
            'tax_id' => $validated['editTaxId'] ?: null,
            'registration_number' => $validated['editRegistrationNumber'] ?: null,
            'nkd_code' => $validated['editNkdCode'] ?: null,
            'nkd_name' => $validated['editNkdName'] ?: null,
            'email' => $validated['editEmail'] ?: null,
            'phone' => $validated['editPhone'] ?: null,
            'website' => $validated['editWebsite'] ?: null,
            'address' => $validated['editAddress'] ?: null,
            'director_name' => $validated['editDirectorName'] ?: null,
            'director_phone' => $validated['editDirectorPhone'] ?: null,
            'director_email' => $validated['editDirectorEmail'] ?: null,
            'is_vat_registered' => $validated['editIsVatRegistered'],
        ]);

        $this->editing = false;
    }

    public function render()
    {
        return view('livewire.company-dashboard');
    }
}
```

- [ ] **Step 4: Implement the view**

Replace the full contents of `resources/views/livewire/company-dashboard.blade.php`:

```blade
<div>
    <div class="flex items-center justify-between mb-1">
        <h1 class="text-2xl font-bold text-gray-800">Работите на: {{ $company->name }}</h1>
        @can('update', $company)
            @if (! $editing)
                <button type="button" wire:click="startEdit" class="text-brand hover:underline text-sm">Уреди</button>
            @endif
        @endcan
    </div>
    <p class="text-sm text-gray-500 mb-6">Изберете модул подолу за да започнете.</p>

    @if ($editing)
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-3">Профил на фирма</h2>
            <form wire:submit="save" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <x-input-label for="editName" value="Назив" />
                        <x-text-input id="editName" wire:model="editName" class="w-full" />
                        @error('editName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-input-label for="editShortName" value="Кратко име" />
                        <x-text-input id="editShortName" wire:model="editShortName" class="w-full" />
                    </div>
                    <div>
                        <x-input-label for="editTaxId" value="ЕДБ" />
                        <x-text-input id="editTaxId" wire:model="editTaxId" class="w-full" />
                    </div>
                    <div>
                        <x-input-label for="editRegistrationNumber" value="ЕМБС" />
                        <x-text-input id="editRegistrationNumber" wire:model="editRegistrationNumber" class="w-full" />
                    </div>
                    <div>
                        <x-input-label for="editNkdCode" value="Шифра на дејност (НКД)" />
                        <x-text-input id="editNkdCode" wire:model="editNkdCode" class="w-full" />
                    </div>
                    <div>
                        <x-input-label for="editNkdName" value="Назив на дејност (НКД)" />
                        <x-text-input id="editNkdName" wire:model="editNkdName" class="w-full" />
                    </div>
                    <div>
                        <x-input-label for="editEmail" value="Е-пошта" />
                        <x-text-input id="editEmail" wire:model="editEmail" class="w-full" />
                        @error('editEmail') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-input-label for="editPhone" value="Телефон" />
                        <x-text-input id="editPhone" wire:model="editPhone" class="w-full" />
                    </div>
                    <div>
                        <x-input-label for="editWebsite" value="Веб-страница" />
                        <x-text-input id="editWebsite" wire:model="editWebsite" class="w-full" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="editAddress" value="Адреса" />
                        <x-text-input id="editAddress" wire:model="editAddress" class="w-full" />
                    </div>
                    <div>
                        <x-input-label for="editDirectorName" value="Управител - име" />
                        <x-text-input id="editDirectorName" wire:model="editDirectorName" class="w-full" />
                    </div>
                    <div>
                        <x-input-label for="editDirectorPhone" value="Управител - телефон" />
                        <x-text-input id="editDirectorPhone" wire:model="editDirectorPhone" class="w-full" />
                    </div>
                    <div>
                        <x-input-label for="editDirectorEmail" value="Управител - е-пошта" />
                        <x-text-input id="editDirectorEmail" wire:model="editDirectorEmail" class="w-full" />
                        @error('editDirectorEmail') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div class="flex items-center gap-2 pb-2">
                        <input type="checkbox" id="editIsVatRegistered" wire:model="editIsVatRegistered">
                        <label for="editIsVatRegistered" class="text-sm">Во ДДВ систем</label>
                    </div>
                </div>

                <div class="flex gap-3">
                    <x-primary-button type="submit">Зачувај</x-primary-button>
                    <button type="button" wire:click="cancelEdit" class="text-sm text-gray-500 hover:underline">Откажи</button>
                </div>
            </form>
        </x-card>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <a href="{{ route('accounting.accounts.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Сметководство</h2>
                <p class="text-sm text-gray-500 mt-1">Контен план, налози, картици, биланс</p>
            </x-card>
        </a>
        <a href="{{ route('inventory.warehouses.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Магацин</h2>
                <p class="text-sm text-gray-500 mt-1">Магацини, артикли, извештаи за залихи</p>
            </x-card>
        </a>
        <a href="{{ route('sales-invoices.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Фактури</h2>
                <p class="text-sm text-gray-500 mt-1">Партнери, излезни и влезни фактури</p>
            </x-card>
        </a>
        <a href="{{ route('documents.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Документи</h2>
                <p class="text-sm text-gray-500 mt-1">Прикачени и генерирани документи</p>
            </x-card>
        </a>
        <a href="{{ route('reports.ddv04', $company) }}" wire:navigate>
            <x-card class="hover:shadow-md transition-shadow">
                <h2 class="font-semibold text-gray-700">Извештаи</h2>
                <p class="text-sm text-gray-500 mt-1">Законски извештаи</p>
            </x-card>
        </a>
    </div>
</div>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=CompanyDashboardTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/CompanyDashboard.php resources/views/livewire/company-dashboard.blade.php tests/Feature/CompanyDashboardTest.php
git commit -m "feat: add inline company profile edit form to Почетна"
```

---

### Task 6: `CompanyDashboard` — repeatable bank-account block

**Files:**
- Modify: `app/Livewire/CompanyDashboard.php`
- Modify: `resources/views/livewire/company-dashboard.blade.php`
- Test: `tests/Feature/CompanyDashboardTest.php`

**Interfaces:**
- Consumes: `Company::bankAccounts()` (Task 2), `CompanyDashboard::startEdit()`/`save()` (Task 5).
- Produces: public `array $bankAccounts` (each row `['bank_name' => string, 'account_number' => string]`), auto-appending a blank row when the last row's `account_number` is filled, capped at 5 — `save()` now also syncs `company_bank_accounts` (delete-and-reinsert).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/CompanyDashboardTest.php`:

```php
    public function test_starting_edit_seeds_one_blank_bank_account_row_when_none_exist(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->assertSet('bankAccounts', [['bank_name' => '', 'account_number' => '']]);
    }

    public function test_filling_the_last_bank_account_row_reveals_a_new_blank_row(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $component = Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('bankAccounts.0.account_number', 'MK07300701104789126');

        $this->assertCount(2, $component->get('bankAccounts'));
    }

    public function test_bank_account_rows_are_capped_at_five(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $component = Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit');

        foreach (range(0, 4) as $index) {
            $component->set("bankAccounts.$index.account_number", "MK0{$index}00000000000000");
        }

        $this->assertCount(5, $component->get('bankAccounts'));
    }

    public function test_admin_can_save_multiple_bank_accounts(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('bankAccounts.0.bank_name', 'Комерцијална банка')
            ->set('bankAccounts.0.account_number', 'MK07300701104789126')
            ->set('bankAccounts.1.bank_name', 'НЛБ Банка')
            ->set('bankAccounts.1.account_number', 'MK07200002785123453')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('company_bank_accounts', [
            'company_id' => $company->id,
            'bank_name' => 'Комерцијална банка',
            'account_number' => 'MK07300701104789126',
            'position' => 0,
        ]);
        $this->assertDatabaseHas('company_bank_accounts', [
            'company_id' => $company->id,
            'bank_name' => 'НЛБ Банка',
            'account_number' => 'MK07200002785123453',
            'position' => 1,
        ]);
        $this->assertDatabaseCount('company_bank_accounts', 2);
    }

    public function test_saving_replaces_the_previous_set_of_bank_accounts(): void
    {
        $company = Company::factory()->create();
        $company->bankAccounts()->create(['bank_name' => 'Стара банка', 'account_number' => 'MK00OLD00000000000', 'position' => 0]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->assertSet('bankAccounts.0.account_number', 'MK00OLD00000000000')
            ->set('bankAccounts.0.bank_name', 'Нова банка')
            ->set('bankAccounts.0.account_number', 'MK00NEW00000000000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('company_bank_accounts', ['account_number' => 'MK00OLD00000000000']);
        $this->assertDatabaseHas('company_bank_accounts', [
            'company_id' => $company->id,
            'bank_name' => 'Нова банка',
            'account_number' => 'MK00NEW00000000000',
        ]);
    }

    public function test_a_blank_trailing_row_is_not_saved(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('bankAccounts.0.bank_name', 'Комерцијална банка')
            ->set('bankAccounts.0.account_number', 'MK07300701104789126')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('company_bank_accounts', 1);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CompanyDashboardTest`
Expected: FAIL — `bankAccounts` property does not exist on `CompanyDashboard`.

- [ ] **Step 3: Extend the component**

In `app/Livewire/CompanyDashboard.php`, add a public property after `$editIsVatRegistered`:

```php
    public array $bankAccounts = [];
```

In `startEdit()`, add before the closing `$this->editing = true;` line:

```php
        $existing = $this->company->bankAccounts()->get();
        $this->bankAccounts = $existing->isEmpty()
            ? [['bank_name' => '', 'account_number' => '']]
            : $existing->map(fn ($row) => [
                'bank_name' => (string) $row->bank_name,
                'account_number' => (string) $row->account_number,
            ])->all();
```

So the full `startEdit()` method becomes:

```php
    public function startEdit(): void
    {
        Gate::authorize('update', $this->company);

        $this->editName = $this->company->name;
        $this->editShortName = (string) $this->company->short_name;
        $this->editTaxId = (string) $this->company->tax_id;
        $this->editRegistrationNumber = (string) $this->company->registration_number;
        $this->editNkdCode = (string) $this->company->nkd_code;
        $this->editNkdName = (string) $this->company->nkd_name;
        $this->editEmail = (string) $this->company->email;
        $this->editPhone = (string) $this->company->phone;
        $this->editWebsite = (string) $this->company->website;
        $this->editAddress = (string) $this->company->address;
        $this->editDirectorName = (string) $this->company->director_name;
        $this->editDirectorPhone = (string) $this->company->director_phone;
        $this->editDirectorEmail = (string) $this->company->director_email;
        $this->editIsVatRegistered = $this->company->is_vat_registered;

        $existing = $this->company->bankAccounts()->get();
        $this->bankAccounts = $existing->isEmpty()
            ? [['bank_name' => '', 'account_number' => '']]
            : $existing->map(fn ($row) => [
                'bank_name' => (string) $row->bank_name,
                'account_number' => (string) $row->account_number,
            ])->all();

        $this->editing = true;
    }
```

Add the auto-reveal hook (Livewire's generic `updated($fullPropertyPath, $newValue)` lifecycle hook — confirmed in `vendor/livewire/livewire/src/Features/SupportLifecycleHooks/SupportLifecycleHooks.php`, `update()` calls `callHook('updated', [$fullPath, $newValue])`), placed after `cancelEdit()`:

```php
    public function updated(string $name, $value): void
    {
        if (! str_ends_with($name, '.account_number')) {
            return;
        }

        $lastIndex = array_key_last($this->bankAccounts);
        $currentIndex = (int) explode('.', $name)[1];

        if ($currentIndex === $lastIndex && trim((string) $value) !== '' && count($this->bankAccounts) < 5) {
            $this->bankAccounts[] = ['bank_name' => '', 'account_number' => ''];
        }
    }
```

Extend `save()`'s validation array (add after `'editIsVatRegistered' => 'boolean',`):

```php
            'bankAccounts' => 'array|max:5',
            'bankAccounts.*.bank_name' => 'nullable|string|max:255',
            'bankAccounts.*.account_number' => 'nullable|string|max:255',
```

Extend `save()`'s body — add after the `$this->company->update([...]);` call and before `$this->editing = false;`:

```php
        $keptRows = collect($validated['bankAccounts'])
            ->filter(fn ($row) => trim((string) ($row['bank_name'] ?? '')) !== '' || trim((string) ($row['account_number'] ?? '')) !== '')
            ->values()
            ->take(5);

        $this->company->bankAccounts()->delete();
        foreach ($keptRows as $index => $row) {
            $this->company->bankAccounts()->create([
                'bank_name' => $row['bank_name'] ?: null,
                'account_number' => $row['account_number'] ?: null,
                'position' => $index,
            ]);
        }
```

- [ ] **Step 4: Extend the view**

In `resources/views/livewire/company-dashboard.blade.php`, add this block inside the `<form wire:submit="save" ...>`, right after the closing `</div>` of the scalar-fields grid (i.e. between the fields grid and the `<div class="flex gap-3">` save/cancel buttons):

```blade
                <div>
                    <h3 class="text-sm font-semibold text-gray-700 mb-2">Трансакциски сметки (до 5)</h3>
                    <div class="space-y-2">
                        @foreach ($bankAccounts as $index => $row)
                            <div class="flex flex-wrap gap-3 items-end">
                                <div>
                                    <x-input-label for="bank_name_{{ $index }}" value="Банка" />
                                    <x-text-input id="bank_name_{{ $index }}" wire:model="bankAccounts.{{ $index }}.bank_name" class="w-48" />
                                </div>
                                <div>
                                    <x-input-label for="account_number_{{ $index }}" value="Сметка (IBAN)" />
                                    <x-text-input id="account_number_{{ $index }}" wire:model.live.blur="bankAccounts.{{ $index }}.account_number" class="w-64" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=CompanyDashboardTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/CompanyDashboard.php resources/views/livewire/company-dashboard.blade.php tests/Feature/CompanyDashboardTest.php
git commit -m "feat: add repeatable bank-account block to the company profile form"
```

---

### Task 7: `CompanyDashboard` — logo upload, position picker, invoice footer note

**Files:**
- Modify: `app/Livewire/CompanyDashboard.php`
- Modify: `resources/views/livewire/company-dashboard.blade.php`
- Test: `tests/Feature/CompanyDashboardTest.php`

**Interfaces:**
- Consumes: `logo_path`/`logo_position`/`invoice_footer_note` columns (Task 1), `config/livewire.php`'s 25MB `temporary_file_upload` limit (already raised in Phase 4a).
- Produces: `CompanyDashboard` now `use WithFileUploads`, with public `$newLogo`, `$editLogoPosition`, `$editInvoiceFooterNote` — `save()` stores the uploaded file to the `public` disk and updates `logo_path`.

- [ ] **Step 1: Write the failing tests**

Add `use Illuminate\Http\UploadedFile;` and `use Illuminate\Support\Facades\Storage;` to the top of `tests/Feature/CompanyDashboardTest.php` (alongside the existing `use` statements), then add:

```php
    public function test_admin_can_upload_a_logo_and_set_its_position(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $file = UploadedFile::fake()->image('logo.png');

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('newLogo', $file)
            ->set('editLogoPosition', 'center')
            ->call('save')
            ->assertHasNoErrors();

        $company->refresh();
        $this->assertNotNull($company->logo_path);
        Storage::disk('public')->assertExists($company->logo_path);
        $this->assertEquals('center', $company->logo_position);
    }

    public function test_admin_can_save_the_invoice_footer_note(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('editInvoiceFooterNote', 'Ви благодариме за соработката.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'invoice_footer_note' => 'Ви благодариме за соработката.',
        ]);
    }

    public function test_logo_position_defaults_to_left_when_editing_an_untouched_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->assertSet('editLogoPosition', 'left');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CompanyDashboardTest`
Expected: FAIL — `newLogo`/`editLogoPosition`/`editInvoiceFooterNote` properties do not exist on `CompanyDashboard`.

- [ ] **Step 3: Extend the component**

In `app/Livewire/CompanyDashboard.php`:

Add the trait import and usage:

```php
use Livewire\WithFileUploads;
```

```php
class CompanyDashboard extends Component
{
    use WithFileUploads;

    public Company $company;
```

Add public properties after `public array $bankAccounts = [];`:

```php
    public string $editLogoPosition = 'left';

    public string $editInvoiceFooterNote = '';

    public $newLogo = null;
```

In `startEdit()`, add after the `$existing = ...` / `$this->bankAccounts = ...` block and before `$this->editing = true;`:

```php
        $this->editLogoPosition = $this->company->logo_position ?: 'left';
        $this->editInvoiceFooterNote = (string) $this->company->invoice_footer_note;
        $this->newLogo = null;
```

Extend `save()`'s validation array (add after the `bankAccounts.*.account_number` rule):

```php
            'editLogoPosition' => ['required', Rule::in(['left', 'center', 'right'])],
            'editInvoiceFooterNote' => 'nullable|string|max:2000',
            'newLogo' => 'nullable|image|max:25600',
```

Add the import this rule needs, alongside the other `use` statements:

```php
use Illuminate\Validation\Rule;
```

In `save()`'s `$this->company->update([...])` call, add two keys:

```php
            'logo_position' => $validated['editLogoPosition'],
            'invoice_footer_note' => $validated['editInvoiceFooterNote'] ?: null,
```

After the bank-accounts sync block (`foreach ($keptRows as $index => $row) { ... }`) and before `$this->editing = false;`, add:

```php
        if ($this->newLogo) {
            $path = $this->newLogo->store('logos/'.$this->company->id, 'public');
            $this->company->update(['logo_path' => $path]);
            $this->newLogo = null;
        }
```

- [ ] **Step 4: Extend the view**

In `resources/views/livewire/company-dashboard.blade.php`, add this block right after the bank-accounts block (Task 6) and before the save/cancel `<div class="flex gap-3">`:

```blade
                <div>
                    <h3 class="text-sm font-semibold text-gray-700 mb-2">Лого</h3>
                    <div class="flex flex-wrap gap-4 items-start">
                        <div>
                            @if ($company->logo_path)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($company->logo_path) }}" alt="Лого" class="h-16 mb-2">
                            @endif
                            <input type="file" wire:model="newLogo" accept="image/*" class="text-sm">
                            @error('newLogo') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <x-input-label value="Позиција на логото на фактура" />
                            <div class="flex gap-4 text-sm mt-1">
                                <label class="flex items-center gap-1">
                                    <input type="radio" wire:model="editLogoPosition" value="left"> Лево
                                </label>
                                <label class="flex items-center gap-1">
                                    <input type="radio" wire:model="editLogoPosition" value="center"> Средина
                                </label>
                                <label class="flex items-center gap-1">
                                    <input type="radio" wire:model="editLogoPosition" value="right"> Десно
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <x-input-label for="editInvoiceFooterNote" value="Забелешка за фуснота на фактура" />
                    <textarea id="editInvoiceFooterNote" wire:model="editInvoiceFooterNote" rows="3" class="border-gray-300 rounded-md w-full text-sm"></textarea>
                </div>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=CompanyDashboardTest`
Expected: PASS

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS (all tests across the app, confirming no regression from the `bank_account` column removal or the `CompanyIndex` simplification).

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/CompanyDashboard.php resources/views/livewire/company-dashboard.blade.php tests/Feature/CompanyDashboardTest.php
git commit -m "feat: add logo upload, position picker, and invoice footer note to the company profile form"
```

---

## Deploy note (not a task — record for the deploy step)

Production needs `php artisan storage:link` run once on the droplet (`/var/www/portal`) after this lands, so `storage/app/public` is reachable at `/storage` — same one-time-setup category as the Phase 0b Google OAuth setup. Without it, uploaded logos will save successfully but render as broken images.
