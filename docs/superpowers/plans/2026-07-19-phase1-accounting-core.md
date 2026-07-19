# Phase 1: Accounting Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the double-entry bookkeeping core for tami-web-app: a per-company chart of accounts seeded from the official Macedonian regulation, manual journal entries with multi-currency support, the resulting ledger, and the analytical-card / trial-balance reports.

**Architecture:** Standard Laravel 13 + Livewire 3 feature module, following the exact conventions already established in Phase 0 (`app/Models/`, `app/Policies/`, `app/Livewire/`, manual `company_id` tenancy scoping via `User::visibleCompanies()`, PHPUnit + `RefreshDatabase` tests, Blade `@props`/`$attributes->merge()` components). New Livewire components live under `app/Livewire/Accounting/` (a subfolder, since this is the first multi-component subsystem — mirrors the existing `app/Livewire/Actions/` and `app/Livewire/Forms/` subfolder pattern from Breeze). Two plain PHP query-service classes (`LedgerCardQuery`, `TrialBalanceQuery`) implement the "two report engines with a grouping parameter" design from the spec, kept independent of Livewire so they're unit-testable on their own.

**Tech Stack:** Laravel 13.8, Livewire 3.6.4, PHP 8.3, MySQL (SQLite in tests), PHPUnit 12, spatie/laravel-permission 8.3, Laravel's built-in `Http` client (new usage — first HTTP-client integration in this codebase, for the NBRM exchange rate API).

## Global Constraints

- PHP `^8.3`, Laravel `^13.8`, Livewire `^3.6.4` — match versions already in `composer.json`, do not add new packages beyond what's listed per-task.
- Role names are the plain strings `'admin'`, `'accountant'`, `'client'` (spatie/laravel-permission) — no enum/constants wrapper.
- Tenancy is scoped manually per-query via `$user->visibleCompanies()` (no Eloquent global scopes exist anywhere in this codebase — do not introduce one).
- Tests are PHPUnit (not Pest), class-based, `use RefreshDatabase;`, snake_case `test_*` methods, roles re-seeded per test class via `Role::findOrCreate(...)` in `setUp()`.
- Policies delegate to `$user->visibleCompanies()->whereKey($company_id)->exists()` for read access, matching `CompanyPolicy`'s existing pattern exactly.
- Per the approved spec (`docs/superpowers/specs/2026-07-18-phase1-accounting-core-design.md`): Admin + Accountant can create/edit everything in this module; Client is read-only.
- Journal entries: save = posted immediately (no draft/review workflow), freely editable/deletable after posting (no reversing-entry requirement).
- MKD is always the ledger's base currency; foreign-currency lines are additive fields on top of the MKD debit/credit columns, never a replacement for them.
- The official chart of accounts source data lives at `docs/reference/official-chart-of-accounts.json` (428 accounts, already extracted and committed) — read it via `base_path()`, do not re-parse the PDF.

**Scope note on reports:** the spec's "two report engines" cover all seven sample report *groupings* (by account / by account+partner / by partner / by synthetic account, etc.) at the data level. This plan implements per-group opening/movement/closing figures and running balances correctly, but does not attempt to reproduce the legacy PDFs' exact multi-level nested subtotal *page layout* (e.g. class-level "Вкупно за група" rows nested above group-level rows nested above account rows all on one page) — that's a page-layout refinement, not a report-correctness requirement, and can be revisited after this phase if needed.

---

### Task 1: Chart of Accounts Schema

**Files:**
- Create: `database/migrations/2026_07_19_090000_create_accounts_table.php`
- Create: `app/Models/Account.php`
- Create: `database/factories/AccountFactory.php`
- Test: `tests/Unit/AccountTest.php`

**Interfaces:**
- Produces: `Account` model with fillable `['company_id', 'code', 'name', 'parent_code', 'is_analytical', 'is_active']`; `class` and `group` columns are auto-derived from `code` on save; relation `company(): BelongsTo`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_class_and_group_are_derived_from_code_on_save(): void
    {
        $company = Company::factory()->create();

        $account = Account::create([
            'company_id' => $company->id,
            'code' => '1200',
            'name' => 'Побарувања од купувачи во земјата',
            'parent_code' => '120',
            'is_analytical' => true,
            'is_active' => true,
        ]);

        $this->assertSame('1', $account->class);
        $this->assertSame('12', $account->group);
    }

    public function test_account_belongs_to_a_company(): void
    {
        $company = Company::factory()->create();
        $account = Account::factory()->for($company)->create();

        $this->assertTrue($account->company->is($company));
    }

    public function test_code_is_unique_per_company(): void
    {
        $company = Company::factory()->create();
        Account::factory()->for($company)->create(['code' => '100']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Account::factory()->for($company)->create(['code' => '100']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AccountTest`
Expected: FAIL — `Class "App\Models\Account" not found` (or migration table missing).

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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10);
            $table->string('name');
            $table->char('class', 1);
            $table->char('group', 2);
            $table->string('parent_code', 10)->nullable();
            $table->boolean('is_analytical')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
```

- [ ] **Step 4: Write the `Account` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Account extends Model
{
    use HasFactory;

    protected $fillable = ['company_id', 'code', 'name', 'parent_code', 'is_analytical', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_analytical' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Account $account) {
            $account->class = substr($account->code, 0, 1);
            $account->group = substr($account->code, 0, 2);
        });
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

class AccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => $this->faker->unique()->numerify('###'),
            'name' => $this->faker->words(3, true),
            'parent_code' => null,
            'is_analytical' => false,
            'is_active' => true,
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=AccountTest`
Expected: PASS (3 tests)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_19_090000_create_accounts_table.php app/Models/Account.php database/factories/AccountFactory.php tests/Unit/AccountTest.php
git commit -m "feat: add accounts table and Account model"
```

---

### Task 2: Official Chart of Accounts Auto-Seeding

**Files:**
- Create: `app/Services/OfficialChartOfAccounts.php`
- Create: `app/Observers/CompanyObserver.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/OfficialChartOfAccountsSeedingTest.php`

**Interfaces:**
- Consumes: `Account` model (Task 1), `docs/reference/official-chart-of-accounts.json` (428 rows, each with `code`, `name` keys, already committed to the repo).
- Produces: `OfficialChartOfAccounts::seedForCompany(Company $company): void` — creates one `Account` row per official entry. Fired automatically whenever a `Company` row is created, via `CompanyObserver`, so it works regardless of whether the company was created by a seeder, `tinker`, or a future UI.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficialChartOfAccountsSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_company_seeds_the_full_official_chart_of_accounts(): void
    {
        $company = Company::factory()->create();

        $this->assertSame(428, Account::where('company_id', $company->id)->count());
    }

    public function test_seeded_accounts_are_synthetic_and_active_by_default(): void
    {
        $company = Company::factory()->create();

        $account = Account::where('company_id', $company->id)->where('code', '120')->first();

        $this->assertNotNull($account);
        $this->assertSame('Побарувања од купувачи во земјата', $account->name);
        $this->assertFalse($account->is_analytical);
        $this->assertTrue($account->is_active);
        $this->assertSame('1', $account->class);
        $this->assertSame('12', $account->group);
    }

    public function test_two_companies_each_get_their_own_independent_copy(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        Account::where('company_id', $companyA->id)->where('code', '120')->first()
            ->update(['is_active' => false]);

        $this->assertFalse(Account::where('company_id', $companyA->id)->where('code', '120')->first()->is_active);
        $this->assertTrue(Account::where('company_id', $companyB->id)->where('code', '120')->first()->is_active);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OfficialChartOfAccountsSeedingTest`
Expected: FAIL — accounts count is 0 (no seeding wired up yet).

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Support\Facades\File;

class OfficialChartOfAccounts
{
    public static function seedForCompany(Company $company): void
    {
        $path = base_path('docs/reference/official-chart-of-accounts.json');

        $accounts = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        foreach ($accounts as $account) {
            Account::create([
                'company_id' => $company->id,
                'code' => $account['code'],
                'name' => $account['name'],
                'parent_code' => null,
                'is_analytical' => false,
                'is_active' => true,
            ]);
        }
    }
}
```

- [ ] **Step 4: Write the observer**

```php
<?php

namespace App\Observers;

use App\Models\Company;
use App\Services\OfficialChartOfAccounts;

class CompanyObserver
{
    public function created(Company $company): void
    {
        OfficialChartOfAccounts::seedForCompany($company);
    }
}
```

- [ ] **Step 5: Register the observer**

In `app/Providers/AppServiceProvider.php`, add to the `boot()` method:

```php
use App\Models\Company;
use App\Observers\CompanyObserver;

public function boot(): void
{
    Company::observe(CompanyObserver::class);
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=OfficialChartOfAccountsSeedingTest`
Expected: PASS (3 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Services/OfficialChartOfAccounts.php app/Observers/CompanyObserver.php app/Providers/AppServiceProvider.php tests/Feature/OfficialChartOfAccountsSeedingTest.php
git commit -m "feat: auto-seed the official chart of accounts when a company is created"
```

---

### Task 3: Partners (Business Partner / Counterparty) Schema

**Files:**
- Create: `database/migrations/2026_07_19_090100_create_partners_table.php`
- Create: `app/Models/Partner.php`
- Create: `database/factories/PartnerFactory.php`
- Test: `tests/Unit/PartnerTest.php`

**Interfaces:**
- Produces: `Partner` model with fillable `['company_id', 'name', 'tax_id', 'email', 'phone', 'address']`; relation `company(): BelongsTo`. Mirrors the `Company` model's own field set exactly, since Invoicing/е-Фактура will need the same fields later.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_belongs_to_a_company(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();

        $this->assertTrue($partner->company->is($company));
    }

    public function test_partner_stores_full_contact_details(): void
    {
        $partner = Partner::factory()->create([
            'name' => 'АКАУНТ СОЛУШН ДООЕЛ',
            'tax_id' => '4030012345678',
            'email' => 'contact@akaunt.mk',
            'phone' => '+389 70 123 456',
            'address' => 'ул. Партизанска бр. 1, Скопје',
        ]);

        $this->assertSame('4030012345678', $partner->tax_id);
        $this->assertSame('contact@akaunt.mk', $partner->email);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PartnerTest`
Expected: FAIL — `Class "App\Models\Partner" not found`.

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
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('tax_id')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};
```

- [ ] **Step 4: Write the `Partner` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Partner extends Model
{
    use HasFactory;

    protected $fillable = ['company_id', 'name', 'tax_id', 'email', 'phone', 'address'];

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

class PartnerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => $this->faker->company(),
            'tax_id' => $this->faker->numerify('#############'),
            'email' => $this->faker->companyEmail(),
            'phone' => $this->faker->phoneNumber(),
            'address' => $this->faker->address(),
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=PartnerTest`
Expected: PASS (2 tests)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_19_090100_create_partners_table.php app/Models/Partner.php database/factories/PartnerFactory.php tests/Unit/PartnerTest.php
git commit -m "feat: add partners table and Partner model"
```

---

### Task 4: Journal Entries & Lines Schema

**Files:**
- Create: `database/migrations/2026_07_19_090200_create_journal_entries_table.php`
- Create: `database/migrations/2026_07_19_090300_create_journal_entry_lines_table.php`
- Create: `app/Models/JournalEntry.php`
- Create: `app/Models/JournalEntryLine.php`
- Create: `database/factories/JournalEntryFactory.php`
- Create: `database/factories/JournalEntryLineFactory.php`
- Test: `tests/Unit/JournalEntryTest.php`

**Interfaces:**
- Consumes: `Account` (Task 1), `Partner` (Task 3), `User` (existing).
- Produces: `JournalEntry` with fillable `['company_id', 'entry_date', 'description', 'created_by']` (`fiscal_year`/`entry_number` are auto-assigned on create), relations `company()`, `lines(): HasMany`, `creator(): BelongsTo`; method `isBalanced(): bool`. `JournalEntryLine` with fillable `['journal_entry_id', 'account_id', 'partner_id', 'description', 'debit', 'credit', 'currency_code', 'exchange_rate', 'foreign_amount']`, relations `journalEntry()`, `account()`, `partner()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_entry_number_and_fiscal_year_are_assigned_automatically(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();

        $first = JournalEntry::create([
            'company_id' => $company->id,
            'entry_date' => '2026-03-15',
            'description' => 'First entry',
            'created_by' => $user->id,
        ]);

        $second = JournalEntry::create([
            'company_id' => $company->id,
            'entry_date' => '2026-06-01',
            'description' => 'Second entry',
            'created_by' => $user->id,
        ]);

        $this->assertSame(2026, $first->fiscal_year);
        $this->assertSame(1, $first->entry_number);
        $this->assertSame(2, $second->entry_number);
    }

    public function test_entry_numbering_resets_per_fiscal_year(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();

        JournalEntry::create(['company_id' => $company->id, 'entry_date' => '2025-12-31', 'description' => 'Old year', 'created_by' => $user->id]);
        $newYearEntry = JournalEntry::create(['company_id' => $company->id, 'entry_date' => '2026-01-01', 'description' => 'New year', 'created_by' => $user->id]);

        $this->assertSame(1, $newYearEntry->entry_number);
        $this->assertSame(2026, $newYearEntry->fiscal_year);
    }

    public function test_entry_numbering_is_independent_per_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $user = User::factory()->create();

        JournalEntry::create(['company_id' => $companyA->id, 'entry_date' => '2026-01-01', 'description' => 'A1', 'created_by' => $user->id]);
        $bEntry = JournalEntry::create(['company_id' => $companyB->id, 'entry_date' => '2026-01-01', 'description' => 'B1', 'created_by' => $user->id]);

        $this->assertSame(1, $bEntry->entry_number);
    }

    public function test_is_balanced_returns_true_when_debits_equal_credits(): void
    {
        $company = Company::factory()->create();
        $cash = Account::factory()->for($company)->create(['code' => '1001']);
        $revenue = Account::factory()->for($company)->create(['code' => '7401']);
        $entry = JournalEntry::factory()->for($company)->create();

        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $revenue->id, 'debit' => 0, 'credit' => 1000]);

        $this->assertTrue($entry->isBalanced());
    }

    public function test_is_balanced_returns_false_when_debits_do_not_equal_credits(): void
    {
        $company = Company::factory()->create();
        $cash = Account::factory()->for($company)->create(['code' => '1001']);
        $revenue = Account::factory()->for($company)->create(['code' => '7401']);
        $entry = JournalEntry::factory()->for($company)->create();

        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $revenue->id, 'debit' => 0, 'credit' => 900]);

        $this->assertFalse($entry->isBalanced());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=JournalEntryTest`
Expected: FAIL — `Class "App\Models\JournalEntry" not found`.

- [ ] **Step 3: Write the migrations**

```php
<?php
// 2026_07_19_090200_create_journal_entries_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedInteger('entry_number');
            $table->date('entry_date');
            $table->string('description')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'fiscal_year', 'entry_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
```

```php
<?php
// 2026_07_19_090300_create_journal_entry_lines_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained();
            $table->foreignId('partner_id')->nullable()->constrained();
            $table->string('description')->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('currency_code', 3)->default('MKD');
            $table->decimal('exchange_rate', 12, 6)->default(1);
            $table->decimal('foreign_amount', 15, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
    }
};
```

- [ ] **Step 4: Write the `JournalEntry` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class JournalEntry extends Model
{
    use HasFactory;

    protected $fillable = ['company_id', 'entry_date', 'description', 'created_by'];

    protected function casts(): array
    {
        return ['entry_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::creating(function (JournalEntry $entry) {
            $entry->fiscal_year = Carbon::parse($entry->entry_date)->year;

            $max = static::where('company_id', $entry->company_id)
                ->where('fiscal_year', $entry->fiscal_year)
                ->lockForUpdate()
                ->max('entry_number');

            $entry->entry_number = ($max ?? 0) + 1;
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isBalanced(): bool
    {
        $totals = $this->lines()->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')->first();

        return round((float) $totals->total_debit, 2) === round((float) $totals->total_credit, 2);
    }
}
```

**Important:** the `lockForUpdate()` call inside the `creating` event only actually prevents a race condition when the surrounding code wraps entry creation in `DB::transaction(...)`. This is enforced in Task 9 (`JournalEntryForm`), where every save is wrapped in a transaction. Document this dependency with a comment above `booted()`:

```php
    // NOTE: lockForUpdate() here only holds a real lock when the caller wraps
    // JournalEntry::create(...) in DB::transaction(...) — see JournalEntryForm::save().
    protected static function booted(): void
```

- [ ] **Step 5: Write the `JournalEntryLine` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'journal_entry_id', 'account_id', 'partner_id', 'description',
        'debit', 'credit', 'currency_code', 'exchange_rate', 'foreign_amount',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'foreign_amount' => 'decimal:2',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
```

- [ ] **Step 6: Write the factories**

```php
<?php
// database/factories/JournalEntryFactory.php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class JournalEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'entry_date' => $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'description' => $this->faker->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
```

```php
<?php
// database/factories/JournalEntryLineFactory.php

namespace Database\Factories;

use App\Models\Account;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

class JournalEntryLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'journal_entry_id' => JournalEntry::factory(),
            'account_id' => Account::factory(),
            'partner_id' => null,
            'description' => $this->faker->words(4, true),
            'debit' => 0,
            'credit' => 0,
            'currency_code' => 'MKD',
            'exchange_rate' => 1,
            'foreign_amount' => null,
        ];
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=JournalEntryTest`
Expected: PASS (5 tests)

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_19_090200_create_journal_entries_table.php database/migrations/2026_07_19_090300_create_journal_entry_lines_table.php app/Models/JournalEntry.php app/Models/JournalEntryLine.php database/factories/JournalEntryFactory.php database/factories/JournalEntryLineFactory.php tests/Unit/JournalEntryTest.php
git commit -m "feat: add journal entries and lines schema with auto-numbering and balance check"
```

---

### Task 5: Exchange Rates & NBRM Integration

**Files:**
- Create: `database/migrations/2026_07_19_090400_create_exchange_rates_table.php`
- Create: `app/Models/ExchangeRate.php`
- Create: `app/Services/ExchangeRateService.php`
- Test: `tests/Unit/ExchangeRateServiceTest.php`

**Interfaces:**
- Produces: `ExchangeRate` model (`rate_date`, `currency_code`, `rate`). `ExchangeRateService::getRate(string $currencyCode, \Illuminate\Support\Carbon $date): float` — returns `1.0` for `MKD`, returns the cached rate if one exists for that date+currency, otherwise fetches from NBRM's public JSON feed, caches it, and returns it.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExchangeRateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mkd_always_returns_a_rate_of_one(): void
    {
        $service = new ExchangeRateService();

        $this->assertSame(1.0, $service->getRate('MKD', Carbon::parse('2026-07-01')));
    }

    public function test_fetches_and_caches_a_rate_from_nbrm(): void
    {
        Http::fake([
            'nbrm.mk/*' => Http::response([
                ['oznaka' => 'EUR', 'sreden' => 61.6917, 'nomin' => 1, 'datum' => '2026-07-01T00:00:00'],
                ['oznaka' => 'USD', 'sreden' => 54.144, 'nomin' => 1, 'datum' => '2026-07-01T00:00:00'],
            ], 200),
        ]);

        $service = new ExchangeRateService();
        $rate = $service->getRate('EUR', Carbon::parse('2026-07-01'));

        $this->assertSame(61.6917, $rate);
        $this->assertDatabaseHas('exchange_rates', [
            'rate_date' => '2026-07-01',
            'currency_code' => 'EUR',
            'rate' => 61.6917,
        ]);
    }

    public function test_uses_the_cached_rate_without_calling_nbrm_again(): void
    {
        ExchangeRate::create(['rate_date' => '2026-07-01', 'currency_code' => 'EUR', 'rate' => 61.5]);

        Http::fake([
            'nbrm.mk/*' => Http::response('should not be called', 500),
        ]);

        $service = new ExchangeRateService();
        $rate = $service->getRate('eur', Carbon::parse('2026-07-01'));

        $this->assertSame(61.5, $rate);
        Http::assertNothingSent();
    }

    public function test_throws_when_nbrm_has_no_rate_for_the_requested_currency(): void
    {
        Http::fake([
            'nbrm.mk/*' => Http::response([
                ['oznaka' => 'USD', 'sreden' => 54.144, 'nomin' => 1, 'datum' => '2026-07-01T00:00:00'],
            ], 200),
        ]);

        $service = new ExchangeRateService();

        $this->expectException(\RuntimeException::class);

        $service->getRate('EUR', Carbon::parse('2026-07-01'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ExchangeRateServiceTest`
Expected: FAIL — `Class "App\Services\ExchangeRateService" not found`.

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
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->date('rate_date');
            $table->string('currency_code', 3);
            $table->decimal('rate', 12, 6);
            $table->timestamps();

            $table->unique(['rate_date', 'currency_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
```

- [ ] **Step 4: Write the `ExchangeRate` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use HasFactory;

    protected $fillable = ['rate_date', 'currency_code', 'rate'];

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'rate' => 'decimal:6',
        ];
    }
}
```

- [ ] **Step 5: Write the `ExchangeRateService`**

```php
<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class ExchangeRateService
{
    public function getRate(string $currencyCode, Carbon $date): float
    {
        $currencyCode = strtoupper($currencyCode);

        if ($currencyCode === 'MKD') {
            return 1.0;
        }

        $cached = ExchangeRate::where('rate_date', $date->toDateString())
            ->where('currency_code', $currencyCode)
            ->first();

        if ($cached) {
            return (float) $cached->rate;
        }

        $formatted = $date->format('d.m.Y');

        $response = Http::get('https://www.nbrm.mk/KLServiceNOV/GetExchangeRate', [
            'StartDate' => $formatted,
            'EndDate' => $formatted,
            'format' => 'json',
        ])->throw();

        $entry = collect($response->json())->first(fn (array $row) => $row['oznaka'] === $currencyCode);

        if (! $entry) {
            throw new \RuntimeException("No NBRM exchange rate found for {$currencyCode} on {$formatted}.");
        }

        $rate = (float) $entry['sreden'] / (float) $entry['nomin'];

        ExchangeRate::create([
            'rate_date' => $date->toDateString(),
            'currency_code' => $currencyCode,
            'rate' => $rate,
        ]);

        return $rate;
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=ExchangeRateServiceTest`
Expected: PASS (4 tests)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_19_090400_create_exchange_rates_table.php app/Models/ExchangeRate.php app/Services/ExchangeRateService.php tests/Unit/ExchangeRateServiceTest.php
git commit -m "feat: add exchange rate caching backed by NBRM's public rate feed"
```

---

### Task 6: Policies (Role-Based Access)

**Files:**
- Create: `app/Policies/AccountPolicy.php`
- Create: `app/Policies/PartnerPolicy.php`
- Create: `app/Policies/JournalEntryPolicy.php`
- Test: `tests/Feature/AccountingPoliciesTest.php`

**Interfaces:**
- Consumes: `User::visibleCompanies()` (existing), `User::hasAnyRole()` (spatie/laravel-permission, already used via `HasRoles` trait on `User`).
- Produces: `AccountPolicy` (`viewAny`, `view`, `create`, `update` — no `delete`, accounts are deactivated not deleted since journal entries may reference them historically), `PartnerPolicy` (`viewAny`, `view`, `create`, `update`), `JournalEntryPolicy` (`viewAny`, `view`, `create`, `update`, `delete`). All `view*` methods are open to any role (including `client`, read-only); `create`/`update`/`delete` require `admin` or `accountant`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountingPoliciesTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_client_can_view_but_not_edit_their_own_companys_accounts(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $account = Account::factory()->for($company)->create();

        $this->assertTrue($client->can('view', $account));
        $this->assertFalse($client->can('update', $account));
    }

    public function test_client_cannot_view_another_companys_accounts(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $account = Account::factory()->for($otherCompany)->create();

        $this->assertFalse($client->can('view', $account));
    }

    public function test_accountant_assigned_to_a_company_can_manage_its_journal_entries(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($company);
        $entry = JournalEntry::factory()->for($company)->create(['created_by' => $accountant->id]);

        $this->assertTrue($accountant->can('create', JournalEntry::class));
        $this->assertTrue($accountant->can('update', $entry));
        $this->assertTrue($accountant->can('delete', $entry));
    }

    public function test_client_cannot_create_or_edit_journal_entries(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $entry = JournalEntry::factory()->for($company)->create();

        $this->assertFalse($client->can('create', JournalEntry::class));
        $this->assertFalse($client->can('update', $entry));
    }

    public function test_admin_can_manage_partners_for_any_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $partner = Partner::factory()->for($company)->create();

        $this->assertTrue($admin->can('update', $partner));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AccountingPoliciesTest`
Expected: FAIL — policy classes don't exist, so `can()` falls through to `false` for everything, failing the positive assertions.

- [ ] **Step 3: Write `AccountPolicy`**

```php
<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Account $account): bool
    {
        return $user->visibleCompanies()->whereKey($account->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant']);
    }

    public function update(User $user, Account $account): bool
    {
        return $user->hasAnyRole(['admin', 'accountant'])
            && $user->visibleCompanies()->whereKey($account->company_id)->exists();
    }
}
```

- [ ] **Step 4: Write `PartnerPolicy`**

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
        return $user->hasAnyRole(['admin', 'accountant']);
    }

    public function update(User $user, Partner $partner): bool
    {
        return $user->hasAnyRole(['admin', 'accountant'])
            && $user->visibleCompanies()->whereKey($partner->company_id)->exists();
    }
}
```

- [ ] **Step 5: Write `JournalEntryPolicy`**

```php
<?php

namespace App\Policies;

use App\Models\JournalEntry;
use App\Models\User;

class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JournalEntry $journalEntry): bool
    {
        return $user->visibleCompanies()->whereKey($journalEntry->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant']);
    }

    public function update(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasAnyRole(['admin', 'accountant'])
            && $user->visibleCompanies()->whereKey($journalEntry->company_id)->exists();
    }

    public function delete(User $user, JournalEntry $journalEntry): bool
    {
        return $this->update($user, $journalEntry);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=AccountingPoliciesTest`
Expected: PASS (5 tests) — Laravel's policy auto-discovery (already relied on by `CompanyPolicy`) picks these up automatically by naming convention, no manual registration needed.

- [ ] **Step 7: Commit**

```bash
git add app/Policies/AccountPolicy.php app/Policies/PartnerPolicy.php app/Policies/JournalEntryPolicy.php tests/Feature/AccountingPoliciesTest.php
git commit -m "feat: add read/write policies for accounts, partners, and journal entries"
```

---

### Task 7: Chart of Accounts Screen

**Files:**
- Create: `app/Livewire/Accounting/AccountIndex.php`
- Create: `resources/views/livewire/accounting/account-index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/AccountIndexTest.php`

**Interfaces:**
- Consumes: `Account` (Task 1), `AccountPolicy` (Task 6).
- Produces: `App\Livewire\Accounting\AccountIndex`, `mount(Company $company)`, public methods `toggleActive(int $accountId)` and `addAnalyticalAccount()` (validates `newCode`/`newName`/`newParentCode`, creates the account, resets the form). Also registers all six named routes for the whole Accounting Core module up front (Task 8's and Task 9's Blade views/redirects reference route names — e.g. `accounting.journal-entries.create` — before those tasks' Livewire classes exist; this is safe because `Route::get('/path', SomeClass::class)` only needs the class name as a string at registration time, it doesn't autoload or instantiate the class until that specific route is actually dispatched, which happens once the relevant task creates it).

- [ ] **Step 0: Register the module's routes now, so later tasks' `route()` calls resolve**

In `routes/web.php`, add:

```php
use App\Livewire\Accounting\AccountIndex;
use App\Livewire\Accounting\JournalEntryForm;
use App\Livewire\Accounting\JournalEntryIndex;
use App\Livewire\Accounting\LedgerCardReport;
use App\Livewire\Accounting\TrialBalanceReport;

Route::middleware(['auth'])->prefix('companies/{company}')->name('accounting.')->group(function () {
    Route::get('/accounts', AccountIndex::class)->name('accounts.index');
    Route::get('/journal-entries', JournalEntryIndex::class)->name('journal-entries.index');
    Route::get('/journal-entries/create', JournalEntryForm::class)->name('journal-entries.create');
    Route::get('/journal-entries/{journalEntry}/edit', JournalEntryForm::class)->name('journal-entries.edit');
    Route::get('/reports/ledger-card', LedgerCardReport::class)->name('reports.ledger-card');
    Route::get('/reports/trial-balance', TrialBalanceReport::class)->name('reports.trial-balance');
});
```

`JournalEntryIndex`, `JournalEntryForm`, `LedgerCardReport`, and `TrialBalanceReport` don't exist yet at this point in the plan — that's fine, this step only registers route *names*, and each class is created by its own task before its route is ever dispatched in a test.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Accounting\AccountIndex;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_it_lists_the_companys_accounts(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(AccountIndex::class, ['company' => $company])
            ->assertSee('Побарувања од купувачи во земјата')
            ->assertSee('120');
    }

    public function test_accountant_can_deactivate_an_account(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($company);
        $account = Account::where('company_id', $company->id)->where('code', '120')->first();

        $this->actingAs($accountant);

        Livewire::test(AccountIndex::class, ['company' => $company])
            ->call('toggleActive', $account->id);

        $this->assertFalse($account->fresh()->is_active);
    }

    public function test_accountant_can_add_an_analytical_account(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($company);

        $this->actingAs($accountant);

        Livewire::test(AccountIndex::class, ['company' => $company])
            ->set('newParentCode', '234')
            ->set('newCode', '2341')
            ->set('newName', 'Обврски за придонес за задолжително пензиско и инвалидско осигурување')
            ->call('addAnalyticalAccount')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('accounts', [
            'company_id' => $company->id,
            'code' => '2341',
            'parent_code' => '234',
            'is_analytical' => true,
        ]);
    }

    public function test_client_cannot_add_an_analytical_account(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        Livewire::test(AccountIndex::class, ['company' => $company])
            ->set('newParentCode', '234')
            ->set('newCode', '2341')
            ->set('newName', 'Test')
            ->call('addAnalyticalAccount')
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AccountIndexTest`
Expected: FAIL — `Class "App\Livewire\Accounting\AccountIndex" not found`.

- [ ] **Step 3: Write the Livewire component**

```php
<?php

namespace App\Livewire\Accounting;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class AccountIndex extends Component
{
    public Company $company;

    public string $newCode = '';

    public string $newName = '';

    public string $newParentCode = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function toggleActive(int $accountId): void
    {
        $account = Account::where('company_id', $this->company->id)->findOrFail($accountId);
        Gate::authorize('update', $account);

        $account->update(['is_active' => ! $account->is_active]);
    }

    public function addAnalyticalAccount(): void
    {
        Gate::authorize('create', Account::class);

        $validated = $this->validate([
            'newCode' => 'required|string|max:10|regex:/^[0-9]{4,}$/',
            'newName' => 'required|string|max:255',
            'newParentCode' => 'required|string|size:3',
        ]);

        Account::create([
            'company_id' => $this->company->id,
            'code' => $validated['newCode'],
            'name' => $validated['newName'],
            'parent_code' => $validated['newParentCode'],
            'is_analytical' => true,
            'is_active' => true,
        ]);

        $this->reset(['newCode', 'newName', 'newParentCode']);
    }

    public function render()
    {
        $accountsByClass = Account::where('company_id', $this->company->id)
            ->orderBy('code')
            ->get()
            ->groupBy('class');

        return view('livewire.accounting.account-index', ['accountsByClass' => $accountsByClass]);
    }
}
```

- [ ] **Step 4: Write the Blade view**

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Chart of Accounts — {{ $company->name }}</h1>

    @can('create', \App\Models\Account::class)
        <div class="bg-white shadow rounded-md p-4 mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Add analytical account</h2>
            <form wire:submit="addAnalyticalAccount" class="flex flex-wrap gap-3 items-end">
                <div>
                    <x-input-label for="newParentCode" value="Parent synthetic code (3 digits)" />
                    <x-text-input id="newParentCode" wire:model="newParentCode" class="w-32" />
                    @error('newParentCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="newCode" value="New code (4+ digits)" />
                    <x-text-input id="newCode" wire:model="newCode" class="w-32" />
                    @error('newCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="flex-1 min-w-[16rem]">
                    <x-input-label for="newName" value="Name" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <x-primary-button type="submit">Add</x-primary-button>
            </form>
        </div>
    @endcan

    @foreach ($accountsByClass as $class => $accounts)
        <div class="mb-6">
            <h3 class="text-lg font-semibold text-gray-700 border-b pb-1 mb-2">Класа {{ $class }}</h3>
            <table class="min-w-full divide-y divide-gray-200">
                <thead>
                    <tr class="text-left text-sm text-gray-500">
                        <th class="py-1 pr-4">Code</th>
                        <th class="py-1 pr-4">Name</th>
                        <th class="py-1 pr-4">Active</th>
                        <th class="py-1"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($accounts as $account)
                        <tr class="text-sm {{ $account->is_active ? '' : 'text-gray-400' }}">
                            <td class="py-1 pr-4 font-mono">{{ $account->code }}</td>
                            <td class="py-1 pr-4">{{ $account->name }}</td>
                            <td class="py-1 pr-4">{{ $account->is_active ? 'Yes' : 'No' }}</td>
                            <td class="py-1">
                                @can('update', $account)
                                    <button type="button" wire:click="toggleActive({{ $account->id }})" class="text-indigo-600 hover:underline text-sm">
                                        {{ $account->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
</div>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AccountIndexTest`
Expected: PASS (4 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Accounting/AccountIndex.php resources/views/livewire/accounting/account-index.blade.php routes/web.php tests/Feature/AccountIndexTest.php
git commit -m "feat: add chart of accounts management screen and register accounting module routes"
```

---

### Task 8: Journal Entry List Screen

**Files:**
- Create: `app/Livewire/Accounting/JournalEntryIndex.php`
- Create: `resources/views/livewire/accounting/journal-entry-index.blade.php`
- Test: `tests/Feature/JournalEntryIndexTest.php`

**Interfaces:**
- Consumes: `JournalEntry` (Task 4), `JournalEntryPolicy` (Task 6).
- Produces: `App\Livewire\Accounting\JournalEntryIndex`, `mount(Company $company)`, paginated listing ordered by `entry_date` descending then `entry_number` descending.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Accounting\JournalEntryIndex;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JournalEntryIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_lists_the_companys_journal_entries(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $entry = JournalEntry::factory()->for($company)->create(['description' => 'Opening balances']);

        $this->actingAs($admin);

        Livewire::test(JournalEntryIndex::class, ['company' => $company])
            ->assertSee('Opening balances')
            ->assertSee((string) $entry->entry_number);
    }

    public function test_client_can_view_the_list_but_sees_no_new_entry_link(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        JournalEntry::factory()->for($company)->create(['description' => 'Opening balances']);

        $this->actingAs($client);

        Livewire::test(JournalEntryIndex::class, ['company' => $company])
            ->assertSee('Opening balances')
            ->assertDontSee('New Entry');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=JournalEntryIndexTest`
Expected: FAIL — `Class "App\Livewire\Accounting\JournalEntryIndex" not found`.

- [ ] **Step 3: Write the Livewire component**

```php
<?php

namespace App\Livewire\Accounting;

use App\Models\Company;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class JournalEntryIndex extends Component
{
    use WithPagination;

    public Company $company;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        $entries = JournalEntry::where('company_id', $this->company->id)
            ->with('creator')
            ->orderByDesc('entry_date')
            ->orderByDesc('entry_number')
            ->paginate(25);

        return view('livewire.accounting.journal-entry-index', ['entries' => $entries]);
    }
}
```

- [ ] **Step 4: Write the Blade view**

```blade
<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Journal Entries — {{ $company->name }}</h1>
        @can('create', \App\Models\JournalEntry::class)
            <a href="{{ route('accounting.journal-entries.create', $company) }}">
                <x-primary-button type="button">New Entry</x-primary-button>
            </a>
        @endcan
    </div>

    <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">#</th>
                <th class="py-2 px-4">Date</th>
                <th class="py-2 px-4">Description</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($entries as $entry)
                <tr class="text-sm">
                    <td class="py-2 px-4 font-mono">{{ $entry->entry_number }}</td>
                    <td class="py-2 px-4">{{ $entry->entry_date->format('d.m.Y') }}</td>
                    <td class="py-2 px-4">{{ $entry->description }}</td>
                    <td class="py-2 px-4">
                        <a href="{{ route('accounting.journal-entries.edit', [$company, $entry]) }}" class="text-indigo-600 hover:underline">
                            @can('update', $entry) Edit @else View @endcan
                        </a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-4 px-4 text-gray-500">No journal entries yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="mt-4">{{ $entries->links() }}</div>
</div>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=JournalEntryIndexTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Accounting/JournalEntryIndex.php resources/views/livewire/accounting/journal-entry-index.blade.php tests/Feature/JournalEntryIndexTest.php
git commit -m "feat: add journal entry list screen"
```

---

### Task 9: Journal Entry Create/Edit Form

**Files:**
- Create: `app/Livewire/Accounting/JournalEntryForm.php`
- Create: `resources/views/livewire/accounting/journal-entry-form.blade.php`
- Test: `tests/Feature/JournalEntryFormTest.php`

**Interfaces:**
- Consumes: `JournalEntry`, `JournalEntryLine` (Task 4), `Account` (Task 1), `Partner` (Task 3), `ExchangeRateService::getRate()` (Task 5), `JournalEntryPolicy` (Task 6).
- Produces: `App\Livewire\Accounting\JournalEntryForm`, `mount(Company $company, ?JournalEntry $journalEntry = null)`, public array `$lines` (each: `account_id`, `partner_id`, `description`, `debit`, `credit`, `currency_code`, `exchange_rate`, `foreign_amount`), methods `addLine()`, `removeLine(int $index)`, `fetchRate(int $index)`, `save()`. Rejects unbalanced entries with a validation error rather than silently saving.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Accounting\JournalEntryForm;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JournalEntryFormTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_a_balanced_entry_saves_and_posts_immediately(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
            ->set('description', 'Cash sale')
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1000')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '1000')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('accounting.journal-entries.index', $company));

        $entry = JournalEntry::where('company_id', $company->id)->where('description', 'Cash sale')->firstOrFail();
        $this->assertTrue($entry->isBalanced());
        $this->assertCount(2, $entry->lines);
    }

    public function test_an_unbalanced_entry_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-03-15')
            ->set('lines.0.account_id', $cash->id)
            ->set('lines.0.debit', '1000')
            ->set('lines.1.account_id', $revenue->id)
            ->set('lines.1.credit', '900')
            ->call('save')
            ->assertHasErrors('lines');

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_editing_an_existing_entry_replaces_its_lines(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();
        $entry = JournalEntry::factory()->for($company)->create(['created_by' => $admin->id]);
        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 500, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $revenue->id, 'debit' => 0, 'credit' => 500]);

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company, 'journalEntry' => $entry])
            ->set('lines.0.debit', '750')
            ->set('lines.1.credit', '750')
            ->call('save')
            ->assertHasNoErrors();

        $entry->refresh();
        $this->assertCount(2, $entry->lines);
        $this->assertSame('750.00', $entry->lines->first()->debit);
    }

    public function test_client_cannot_access_the_form(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->assertForbidden();
    }

    public function test_fetch_rate_pulls_from_nbrm_and_fills_the_line(): void
    {
        Http::fake([
            'nbrm.mk/*' => Http::response([
                ['oznaka' => 'EUR', 'sreden' => 61.6917, 'nomin' => 1, 'datum' => '2026-07-01T00:00:00'],
            ], 200),
        ]);

        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(JournalEntryForm::class, ['company' => $company])
            ->set('entryDate', '2026-07-01')
            ->set('lines.0.currency_code', 'EUR')
            ->set('lines.0.foreign_amount', '100')
            ->call('fetchRate', 0)
            ->assertSet('lines.0.exchange_rate', 61.6917)
            ->assertSet('lines.0.debit', '6169.17');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=JournalEntryFormTest`
Expected: FAIL — `Class "App\Livewire\Accounting\JournalEntryForm" not found`.

- [ ] **Step 3: Write the Livewire component**

```php
<?php

namespace App\Livewire\Accounting;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Services\ExchangeRateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class JournalEntryForm extends Component
{
    public Company $company;

    public ?JournalEntry $journalEntry = null;

    public string $entryDate = '';

    public string $description = '';

    public array $lines = [];

    public function mount(Company $company, ?JournalEntry $journalEntry = null): void
    {
        $this->company = $company;
        $this->journalEntry = $journalEntry;

        Gate::authorize($journalEntry ? 'update' : 'create', $journalEntry ?? JournalEntry::class);

        if ($journalEntry) {
            $this->entryDate = $journalEntry->entry_date->toDateString();
            $this->description = (string) $journalEntry->description;
            $this->lines = $journalEntry->lines->map(fn ($line) => [
                'account_id' => $line->account_id,
                'partner_id' => $line->partner_id,
                'description' => $line->description,
                'debit' => (string) $line->debit,
                'credit' => (string) $line->credit,
                'currency_code' => $line->currency_code,
                'exchange_rate' => (string) $line->exchange_rate,
                'foreign_amount' => $line->foreign_amount === null ? null : (string) $line->foreign_amount,
            ])->toArray();
        } else {
            $this->entryDate = now()->toDateString();
            $this->lines = [$this->emptyLine(), $this->emptyLine()];
        }
    }

    protected function emptyLine(): array
    {
        return [
            'account_id' => '',
            'partner_id' => '',
            'description' => '',
            'debit' => '0',
            'credit' => '0',
            'currency_code' => 'MKD',
            'exchange_rate' => '1',
            'foreign_amount' => null,
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

    public function fetchRate(int $index): void
    {
        $currency = $this->lines[$index]['currency_code'];
        $foreignAmount = (float) $this->lines[$index]['foreign_amount'];

        $rate = app(ExchangeRateService::class)->getRate($currency, Carbon::parse($this->entryDate));

        $this->lines[$index]['exchange_rate'] = (string) $rate;

        $mkdAmount = number_format($foreignAmount * $rate, 2, '.', '');

        // Defaults to filling debit — the common case — unless the user has
        // already put a value in credit (e.g. for a payment/liability line).
        if ((float) $this->lines[$index]['credit'] > 0) {
            $this->lines[$index]['credit'] = $mkdAmount;
        } else {
            $this->lines[$index]['debit'] = $mkdAmount;
        }
    }

    public function save(): void
    {
        Gate::authorize($this->journalEntry ? 'update' : 'create', $this->journalEntry ?? JournalEntry::class);

        $this->validate([
            'entryDate' => 'required|date',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'required|exists:accounts,id',
        ]);

        $totalDebit = collect($this->lines)->sum(fn ($line) => (float) $line['debit']);
        $totalCredit = collect($this->lines)->sum(fn ($line) => (float) $line['credit']);

        if (round($totalDebit, 2) !== round($totalCredit, 2)) {
            $this->addError('lines', 'The entry does not balance — total debit must equal total credit.');

            return;
        }

        DB::transaction(function () {
            $entry = $this->journalEntry ?? new JournalEntry([
                'company_id' => $this->company->id,
                'created_by' => auth()->id(),
            ]);
            $entry->entry_date = $this->entryDate;
            $entry->description = $this->description;
            $entry->company_id = $this->company->id;

            if (! $entry->exists) {
                $entry->created_by = auth()->id();
            }

            $entry->save();
            $entry->lines()->delete();

            foreach ($this->lines as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'partner_id' => $line['partner_id'] ?: null,
                    'description' => $line['description'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'currency_code' => $line['currency_code'],
                    'exchange_rate' => $line['exchange_rate'],
                    'foreign_amount' => $line['foreign_amount'] ?: null,
                ]);
            }

            $this->journalEntry = $entry;
        });

        $this->redirect(route('accounting.journal-entries.index', $this->company));
    }

    public function render()
    {
        return view('livewire.accounting.journal-entry-form', [
            'accounts' => Account::where('company_id', $this->company->id)->where('is_active', true)->orderBy('code')->get(),
            'partners' => Partner::where('company_id', $this->company->id)->orderBy('name')->get(),
        ]);
    }
}
```

**Note on `test_editing_an_existing_entry_replaces_its_lines`:** the `save()` method deletes and recreates lines on every save rather than diffing — this is the simplest correct implementation of "freely editable" from the spec, and is safe because journal entry lines carry no independent identity outside their parent entry.

- [ ] **Step 4: Write the Blade view**

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">
        {{ $journalEntry ? 'Edit Journal Entry #'.$journalEntry->entry_number : 'New Journal Entry' }} — {{ $company->name }}
    </h1>

    <form wire:submit="save" class="bg-white shadow rounded-md p-4">
        <div class="grid grid-cols-2 gap-4 mb-4">
            <div>
                <x-input-label for="entryDate" value="Date" />
                <input type="date" id="entryDate" wire:model="entryDate" class="border-gray-300 rounded-md shadow-sm w-full" />
                @error('entryDate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="description" value="Description" />
                <x-text-input id="description" wire:model="description" class="w-full" />
            </div>
        </div>

        @error('lines') <p class="text-red-600 text-sm mb-2">{{ $message }}</p> @enderror

        <table class="min-w-full divide-y divide-gray-200 mb-4">
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-1 pr-2">Account</th>
                    <th class="py-1 pr-2">Partner</th>
                    <th class="py-1 pr-2">Description</th>
                    <th class="py-1 pr-2">Debit</th>
                    <th class="py-1 pr-2">Credit</th>
                    <th class="py-1 pr-2">Currency</th>
                    <th class="py-1 pr-2">Foreign amt.</th>
                    <th class="py-1 pr-2">Rate</th>
                    <th class="py-1"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $index => $line)
                    <tr>
                        <td class="py-1 pr-2">
                            <select wire:model="lines.{{ $index }}.account_id" class="border-gray-300 rounded-md text-sm">
                                <option value="">—</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="py-1 pr-2">
                            <select wire:model="lines.{{ $index }}.partner_id" class="border-gray-300 rounded-md text-sm">
                                <option value="">—</option>
                                @foreach ($partners as $partner)
                                    <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="py-1 pr-2"><input type="text" wire:model="lines.{{ $index }}.description" class="border-gray-300 rounded-md text-sm w-32" /></td>
                        <td class="py-1 pr-2"><input type="number" step="0.01" wire:model="lines.{{ $index }}.debit" class="border-gray-300 rounded-md text-sm w-24" /></td>
                        <td class="py-1 pr-2"><input type="number" step="0.01" wire:model="lines.{{ $index }}.credit" class="border-gray-300 rounded-md text-sm w-24" /></td>
                        <td class="py-1 pr-2">
                            <select wire:model="lines.{{ $index }}.currency_code" class="border-gray-300 rounded-md text-sm">
                                <option value="MKD">MKD</option>
                                <option value="EUR">EUR</option>
                                <option value="USD">USD</option>
                                <option value="GBP">GBP</option>
                                <option value="CHF">CHF</option>
                            </select>
                        </td>
                        <td class="py-1 pr-2"><input type="number" step="0.01" wire:model="lines.{{ $index }}.foreign_amount" class="border-gray-300 rounded-md text-sm w-20" /></td>
                        <td class="py-1 pr-2 flex items-center gap-1">
                            <input type="number" step="0.000001" wire:model="lines.{{ $index }}.exchange_rate" class="border-gray-300 rounded-md text-sm w-20" />
                            @if ($line['currency_code'] !== 'MKD')
                                <button type="button" wire:click="fetchRate({{ $index }})" class="text-xs text-indigo-600 hover:underline">NBRM</button>
                            @endif
                        </td>
                        <td class="py-1">
                            <button type="button" wire:click="removeLine({{ $index }})" class="text-red-600 text-sm">✕</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <button type="button" wire:click="addLine" class="text-sm text-indigo-600 hover:underline mb-4">+ Add line</button>

        <div>
            <x-primary-button type="submit">Save</x-primary-button>
        </div>
    </form>
</div>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=JournalEntryFormTest`
Expected: PASS (5 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Accounting/JournalEntryForm.php resources/views/livewire/accounting/journal-entry-form.blade.php tests/Feature/JournalEntryFormTest.php
git commit -m "feat: add journal entry create/edit form with multi-currency line support"
```

---

### Task 10: Ledger Card Report

**Files:**
- Create: `app/Services/Accounting/LedgerCardQuery.php`
- Create: `app/Livewire/Accounting/LedgerCardReport.php`
- Create: `resources/views/livewire/accounting/ledger-card-report.blade.php`
- Test: `tests/Unit/LedgerCardQueryTest.php`
- Test: `tests/Feature/LedgerCardReportTest.php`

**Interfaces:**
- Consumes: `JournalEntryLine` (Task 4).
- Produces: `LedgerCardQuery::run(Company $company, array $filters): \Illuminate\Support\Collection` — `$filters` keys: `account_id` (nullable int), `partner_id` (nullable int), `from` (Carbon), `to` (Carbon). Returns an ordered collection of line rows (date, description, partner name, debit, credit, running balance), at least one of `account_id`/`partner_id` required by the caller (enforced in the Livewire component, not the query class, so the query class stays a simple reusable primitive). `App\Livewire\Accounting\LedgerCardReport`, `mount(Company $company)`.

- [ ] **Step 1: Write the failing unit test for the query class**

```php
<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Services\Accounting\LedgerCardQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LedgerCardQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_lines_for_an_account_with_a_running_balance(): void
    {
        $company = Company::factory()->create();
        $account = Account::where('company_id', $company->id)->where('code', '120')->first();
        $partner = Partner::factory()->for($company)->create(['name' => 'АКАУНТ СОЛУШН ДООЕЛ']);

        $entry1 = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-10']);
        $entry1->lines()->create(['account_id' => $account->id, 'partner_id' => $partner->id, 'debit' => 6000, 'credit' => 0, 'description' => 'Фактура 01/26']);

        $entry2 = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-20']);
        $entry2->lines()->create(['account_id' => $account->id, 'partner_id' => $partner->id, 'debit' => 0, 'credit' => 2000, 'description' => 'Извод 5']);

        $rows = LedgerCardQuery::run($company, [
            'account_id' => $account->id,
            'partner_id' => null,
            'from' => Carbon::parse('2026-01-01'),
            'to' => Carbon::parse('2026-01-31'),
        ]);

        $this->assertCount(2, $rows);
        $this->assertSame(6000.0, (float) $rows[0]['debit']);
        $this->assertSame(6000.0, (float) $rows[0]['balance']);
        $this->assertSame(2000.0, (float) $rows[1]['credit']);
        $this->assertSame(4000.0, (float) $rows[1]['balance']);
    }

    public function test_opening_balance_before_the_date_range_carries_into_the_running_balance(): void
    {
        $company = Company::factory()->create();
        $account = Account::where('company_id', $company->id)->where('code', '120')->first();

        $before = JournalEntry::factory()->for($company)->create(['entry_date' => '2025-12-15']);
        $before->lines()->create(['account_id' => $account->id, 'debit' => 1000, 'credit' => 0]);

        $inRange = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-10']);
        $inRange->lines()->create(['account_id' => $account->id, 'debit' => 500, 'credit' => 0]);

        $rows = LedgerCardQuery::run($company, [
            'account_id' => $account->id,
            'partner_id' => null,
            'from' => Carbon::parse('2026-01-01'),
            'to' => Carbon::parse('2026-01-31'),
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(1500.0, (float) $rows[0]['balance']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LedgerCardQueryTest`
Expected: FAIL — `Class "App\Services\Accounting\LedgerCardQuery" not found`.

- [ ] **Step 3: Write the query class**

```php
<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\JournalEntryLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class LedgerCardQuery
{
    public static function run(Company $company, array $filters): Collection
    {
        $accountId = $filters['account_id'] ?? null;
        $partnerId = $filters['partner_id'] ?? null;
        /** @var Carbon $from */
        $from = $filters['from'];
        /** @var Carbon $to */
        $to = $filters['to'];

        $baseQuery = fn () => JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)
            ->when($accountId, fn ($q) => $q->where('journal_entry_lines.account_id', $accountId))
            ->when($partnerId, fn ($q) => $q->where('journal_entry_lines.partner_id', $partnerId));

        $openingBalance = (clone $baseQuery())
            ->where('journal_entries.entry_date', '<', $from->toDateString())
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as balance')
            ->value('balance');

        $lines = (clone $baseQuery())
            ->whereBetween('journal_entries.entry_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entry_lines.id')
            ->with(['journalEntry', 'partner'])
            ->get(['journal_entry_lines.*']);

        $runningBalance = (float) $openingBalance;

        return $lines->map(function (JournalEntryLine $line) use (&$runningBalance) {
            $runningBalance += (float) $line->debit - (float) $line->credit;

            return [
                'date' => $line->journalEntry->entry_date,
                'description' => $line->description,
                'partner' => $line->partner?->name,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'balance' => $runningBalance,
            ];
        })->values();
    }
}
```

- [ ] **Step 4: Run the unit test to verify it passes**

Run: `php artisan test --filter=LedgerCardQueryTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Write the failing Feature test for the Livewire wrapper**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Accounting\LedgerCardReport;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LedgerCardReportTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_it_shows_lines_once_an_account_is_selected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $account = Account::where('company_id', $company->id)->where('code', '120')->first();
        $entry = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-10']);
        $entry->lines()->create(['account_id' => $account->id, 'debit' => 6000, 'credit' => 0, 'description' => 'Фактура 01/26']);

        $this->actingAs($admin);

        Livewire::test(LedgerCardReport::class, ['company' => $company])
            ->set('accountId', $account->id)
            ->set('from', '2026-01-01')
            ->set('to', '2026-01-31')
            ->assertSee('Фактура 01/26')
            ->assertSee('6000');
    }
}
```

- [ ] **Step 6: Run test to verify it fails**

Run: `php artisan test --filter=LedgerCardReportTest`
Expected: FAIL — `Class "App\Livewire\Accounting\LedgerCardReport" not found`.

- [ ] **Step 7: Write the Livewire component**

```php
<?php

namespace App\Livewire\Accounting;

use App\Models\Account;
use App\Models\Company;
use App\Models\Partner;
use App\Services\Accounting\LedgerCardQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class LedgerCardReport extends Component
{
    public Company $company;

    public ?int $accountId = null;

    public ?int $partnerId = null;

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

        if ($this->accountId || $this->partnerId) {
            $rows = LedgerCardQuery::run($this->company, [
                'account_id' => $this->accountId,
                'partner_id' => $this->partnerId,
                'from' => Carbon::parse($this->from),
                'to' => Carbon::parse($this->to),
            ]);
        }

        return view('livewire.accounting.ledger-card-report', [
            'rows' => $rows,
            'accounts' => Account::where('company_id', $this->company->id)->orderBy('code')->get(),
            'partners' => Partner::where('company_id', $this->company->id)->orderBy('name')->get(),
        ]);
    }
}
```

- [ ] **Step 8: Write the Blade view**

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Аналитичка картица — {{ $company->name }}</h1>

    <div class="bg-white shadow rounded-md p-4 mb-4 flex flex-wrap gap-4 items-end">
        <div>
            <x-input-label for="accountId" value="Account" />
            <select id="accountId" wire:model.live="accountId" class="border-gray-300 rounded-md text-sm">
                <option value="">—</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="partnerId" value="Partner" />
            <select id="partnerId" wire:model.live="partnerId" class="border-gray-300 rounded-md text-sm">
                <option value="">—</option>
                @foreach ($partners as $partner)
                    <option value="{{ $partner->id }}">{{ $partner->name }}</option>
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

    @if ($accountId || $partnerId)
        <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-2 px-4">Date</th>
                    <th class="py-2 px-4">Description</th>
                    <th class="py-2 px-4">Partner</th>
                    <th class="py-2 px-4 text-right">Debit</th>
                    <th class="py-2 px-4 text-right">Credit</th>
                    <th class="py-2 px-4 text-right">Balance</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    <tr class="text-sm">
                        <td class="py-2 px-4">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d.m.y') }}</td>
                        <td class="py-2 px-4">{{ $row['description'] }}</td>
                        <td class="py-2 px-4">{{ $row['partner'] }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['debit'], 2) }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['credit'], 2) }}</td>
                        <td class="py-2 px-4 text-right">{{ number_format($row['balance'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-4 px-4 text-gray-500">No transactions in this range.</td></tr>
                @endforelse
            </tbody>
        </table>
    @else
        <p class="text-gray-500">Select an account and/or a partner to see the ledger card.</p>
    @endif
</div>
```

- [ ] **Step 9: Run test to verify it passes**

Run: `php artisan test --filter=LedgerCardReportTest`
Expected: PASS (1 test)

- [ ] **Step 10: Commit**

```bash
git add app/Services/Accounting/LedgerCardQuery.php app/Livewire/Accounting/LedgerCardReport.php resources/views/livewire/accounting/ledger-card-report.blade.php tests/Unit/LedgerCardQueryTest.php tests/Feature/LedgerCardReportTest.php
git commit -m "feat: add the analytical ledger card report"
```

---

### Task 11: Trial Balance Report

**Files:**
- Create: `app/Services/Accounting/TrialBalanceQuery.php`
- Create: `app/Livewire/Accounting/TrialBalanceReport.php`
- Create: `resources/views/livewire/accounting/trial-balance-report.blade.php`
- Test: `tests/Unit/TrialBalanceQueryTest.php`
- Test: `tests/Feature/TrialBalanceReportTest.php`

**Interfaces:**
- Consumes: `JournalEntryLine`, `Account` (Task 1/4).
- Produces: `TrialBalanceQuery::run(Company $company, string $groupBy, Carbon $from, Carbon $to): \Illuminate\Support\Collection` — `$groupBy` is one of `'account'` (full code, the detailed default), `'synthetic'` (first 3 digits of the code), `'partner'`, or `'account_partner'` (combined — this is the "Кумулатив по аналитички конта и фирми" sample: same figures, just grouped by both dimensions together, lines with no partner excluded). Each row: `key` (grouping value), `label`, opening/movement/closing `balance` figures. `App\Livewire\Accounting\TrialBalanceReport`, `mount(Company $company)`.

- [ ] **Step 1: Write the failing unit test for the query class**

```php
<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Services\Accounting\TrialBalanceQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TrialBalanceQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_computes_opening_movement_and_closing_by_account(): void
    {
        $company = Company::factory()->create();
        $account = Account::where('company_id', $company->id)->where('code', '120')->first();

        $before = JournalEntry::factory()->for($company)->create(['entry_date' => '2025-12-15']);
        $before->lines()->create(['account_id' => $account->id, 'debit' => 1000, 'credit' => 0]);

        $inRange = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-10']);
        $inRange->lines()->create(['account_id' => $account->id, 'debit' => 500, 'credit' => 200]);

        $rows = TrialBalanceQuery::run($company, 'account', Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $row = $rows->firstWhere('key', '120');

        $this->assertSame(1000.0, $row['opening_balance']);
        $this->assertSame(500.0, $row['movement_debit']);
        $this->assertSame(200.0, $row['movement_credit']);
        $this->assertSame(1300.0, $row['closing_balance']);
    }

    public function test_synthetic_grouping_collapses_analytical_accounts(): void
    {
        $company = Company::factory()->create();
        $synthetic = Account::where('company_id', $company->id)->where('code', '234')->first();
        $analytical1 = Account::create(['company_id' => $company->id, 'code' => '2341', 'name' => 'Pension', 'parent_code' => '234', 'is_analytical' => true, 'is_active' => true]);
        $analytical2 = Account::create(['company_id' => $company->id, 'code' => '2342', 'name' => 'Health', 'parent_code' => '234', 'is_analytical' => true, 'is_active' => true]);

        $entry = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-10']);
        $entry->lines()->create(['account_id' => $analytical1->id, 'debit' => 0, 'credit' => 300]);
        $entry->lines()->create(['account_id' => $analytical2->id, 'debit' => 0, 'credit' => 150]);

        $rows = TrialBalanceQuery::run($company, 'synthetic', Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $row = $rows->firstWhere('key', '234');
        $this->assertSame(450.0, $row['movement_credit']);
        $this->assertSame($synthetic->name, $row['label']);
    }

    public function test_account_partner_grouping_combines_both_dimensions(): void
    {
        $company = Company::factory()->create();
        $account = Account::where('company_id', $company->id)->where('code', '120')->first();
        $partnerA = Partner::factory()->for($company)->create(['name' => 'АКАУНТ СОЛУШН ДООЕЛ']);
        $partnerB = Partner::factory()->for($company)->create(['name' => 'ХЕТА ЛИЗИНГ ДОО']);

        $entry = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-10']);
        $entry->lines()->create(['account_id' => $account->id, 'partner_id' => $partnerA->id, 'debit' => 6000, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $account->id, 'partner_id' => $partnerB->id, 'debit' => 10000, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $account->id, 'partner_id' => null, 'debit' => 500, 'credit' => 0]);

        $rows = TrialBalanceQuery::run($company, 'account_partner', Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertCount(2, $rows);
        $rowA = $rows->firstWhere('label', 'АКАУНТ СОЛУШН ДООЕЛ');
        $this->assertSame(6000.0, $rowA['movement_debit']);
        $rowB = $rows->firstWhere('label', 'ХЕТА ЛИЗИНГ ДОО');
        $this->assertSame(10000.0, $rowB['movement_debit']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TrialBalanceQueryTest`
Expected: FAIL — `Class "App\Services\Accounting\TrialBalanceQuery" not found`.

- [ ] **Step 3: Write the query class**

```php
<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntryLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TrialBalanceQuery
{
    public static function run(Company $company, string $groupBy, Carbon $from, Carbon $to): Collection
    {
        // NOTE: grouping is done in PHP rather than SQL GROUP BY so that the
        // 'account_partner' composite key doesn't need a cross-database string
        // concatenation expression (MySQL's CONCAT() vs SQLite's || operator).
        // Data volumes here are small (one accounting firm's clients), so this
        // is not a performance concern.
        $keyFor = match ($groupBy) {
            'account' => fn (JournalEntryLine $line) => $line->account->code,
            'synthetic' => fn (JournalEntryLine $line) => substr($line->account->code, 0, 3),
            'partner' => fn (JournalEntryLine $line) => $line->partner_id ? (string) $line->partner_id : null,
            'account_partner' => fn (JournalEntryLine $line) => $line->partner_id
                ? $line->account->code.'::'.$line->partner_id
                : null,
            default => throw new \InvalidArgumentException("Unknown grouping [{$groupBy}]."),
        };

        $labelFor = match ($groupBy) {
            'account', 'synthetic' => fn (JournalEntryLine $line) => $line->account->name,
            'partner', 'account_partner' => fn (JournalEntryLine $line) => $line->partner?->name,
        };

        $baseQuery = fn () => JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)
            ->with(['account', 'partner']);

        $openingLines = (clone $baseQuery())
            ->where('journal_entries.entry_date', '<', $from->toDateString())
            ->get(['journal_entry_lines.*']);

        $movementLines = (clone $baseQuery())
            ->whereBetween('journal_entries.entry_date', [$from->toDateString(), $to->toDateString()])
            ->get(['journal_entry_lines.*']);

        $openingTotals = self::totalsByKey($openingLines, $keyFor);
        $movementTotals = self::totalsByKey($movementLines, $keyFor);

        $keys = $openingTotals->keys()->merge($movementTotals->keys())->unique()->filter();

        $labels = $groupBy === 'synthetic'
            ? Account::where('company_id', $company->id)->where('is_analytical', false)->pluck('name', 'code')
            : self::labelsByKey($movementLines->isNotEmpty() ? $movementLines : $openingLines, $keyFor, $labelFor);

        return $keys->map(function ($key) use ($openingTotals, $movementTotals, $labels) {
            $opening = $openingTotals->get($key, ['debit' => 0.0, 'credit' => 0.0]);
            $movement = $movementTotals->get($key, ['debit' => 0.0, 'credit' => 0.0]);
            $openingBalance = $opening['debit'] - $opening['credit'];
            $movementDebit = $movement['debit'];
            $movementCredit = $movement['credit'];

            return [
                'key' => $key,
                'label' => $labels->get($key, $key),
                'opening_balance' => $openingBalance,
                'movement_debit' => $movementDebit,
                'movement_credit' => $movementCredit,
                'closing_balance' => $openingBalance + $movementDebit - $movementCredit,
            ];
        })->sortBy('key')->values();
    }

    private static function totalsByKey(Collection $lines, \Closure $keyFor): Collection
    {
        return $lines
            ->filter(fn (JournalEntryLine $line) => $keyFor($line) !== null)
            ->groupBy($keyFor)
            ->map(fn (Collection $group) => [
                'debit' => (float) $group->sum('debit'),
                'credit' => (float) $group->sum('credit'),
            ]);
    }

    private static function labelsByKey(Collection $lines, \Closure $keyFor, \Closure $labelFor): Collection
    {
        return $lines
            ->filter(fn (JournalEntryLine $line) => $keyFor($line) !== null)
            ->groupBy($keyFor)
            ->map(fn (Collection $group) => $labelFor($group->first()));
    }
}
```

- [ ] **Step 4: Run the unit test to verify it passes**

Run: `php artisan test --filter=TrialBalanceQueryTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Write the failing Feature test for the Livewire wrapper**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Accounting\TrialBalanceReport;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TrialBalanceReportTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_it_shows_the_trial_balance_grouped_by_account(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $account = Account::where('company_id', $company->id)->where('code', '120')->first();
        $entry = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-10']);
        $entry->lines()->create(['account_id' => $account->id, 'debit' => 1000, 'credit' => 0]);

        $this->actingAs($admin);

        Livewire::test(TrialBalanceReport::class, ['company' => $company])
            ->set('from', '2026-01-01')
            ->set('to', '2026-01-31')
            ->assertSee('120')
            ->assertSee('1,000.00');
    }
}
```

- [ ] **Step 6: Run test to verify it fails**

Run: `php artisan test --filter=TrialBalanceReportTest`
Expected: FAIL — `Class "App\Livewire\Accounting\TrialBalanceReport" not found`.

- [ ] **Step 7: Write the Livewire component**

```php
<?php

namespace App\Livewire\Accounting;

use App\Models\Company;
use App\Services\Accounting\TrialBalanceQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class TrialBalanceReport extends Component
{
    public Company $company;

    public string $groupBy = 'account';

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
        $rows = TrialBalanceQuery::run($this->company, $this->groupBy, Carbon::parse($this->from), Carbon::parse($this->to));

        return view('livewire.accounting.trial-balance-report', ['rows' => $rows]);
    }
}
```

- [ ] **Step 8: Write the Blade view**

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Бруто Биланс — {{ $company->name }}</h1>

    <div class="bg-white shadow rounded-md p-4 mb-4 flex flex-wrap gap-4 items-end">
        <div>
            <x-input-label for="groupBy" value="Group by" />
            <select id="groupBy" wire:model.live="groupBy" class="border-gray-300 rounded-md text-sm">
                <option value="account">Full account (по конта)</option>
                <option value="synthetic">Synthetic account only (по синтетики)</option>
                <option value="partner">Partner (по фирми)</option>
                <option value="account_partner">Account + partner (Кумулатив по аналитички конта и фирми)</option>
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

    <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Code</th>
                <th class="py-2 px-4">Name</th>
                <th class="py-2 px-4 text-right">Opening balance</th>
                <th class="py-2 px-4 text-right">Movement debit</th>
                <th class="py-2 px-4 text-right">Movement credit</th>
                <th class="py-2 px-4 text-right">Closing balance</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($rows as $row)
                <tr class="text-sm">
                    <td class="py-2 px-4 font-mono">{{ $row['key'] }}</td>
                    <td class="py-2 px-4">{{ $row['label'] }}</td>
                    <td class="py-2 px-4 text-right">{{ number_format($row['opening_balance'], 2) }}</td>
                    <td class="py-2 px-4 text-right">{{ number_format($row['movement_debit'], 2) }}</td>
                    <td class="py-2 px-4 text-right">{{ number_format($row['movement_credit'], 2) }}</td>
                    <td class="py-2 px-4 text-right">{{ number_format($row['closing_balance'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-4 px-4 text-gray-500">No activity in this range.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
```

- [ ] **Step 9: Run test to verify it passes**

Run: `php artisan test --filter=TrialBalanceReportTest`
Expected: PASS (1 test)

- [ ] **Step 10: Commit**

```bash
git add app/Services/Accounting/TrialBalanceQuery.php app/Livewire/Accounting/TrialBalanceReport.php resources/views/livewire/accounting/trial-balance-report.blade.php tests/Unit/TrialBalanceQueryTest.php tests/Feature/TrialBalanceReportTest.php
git commit -m "feat: add the trial balance report with account/synthetic/partner grouping"
```

---

### Task 12: Navigation & Final Route Smoke Test

**Files:**
- Modify: `resources/views/livewire/layout/navigation.blade.php`
- Modify: `resources/views/livewire/company-index.blade.php`
- Test: `tests/Feature/AccountingRoutesTest.php`

**Interfaces:**
- Consumes: all five Accounting Livewire components from Tasks 7–11, and the six named routes already registered in Task 7 (`accounting.accounts.index`, `accounting.journal-entries.index`, `accounting.journal-entries.create`, `accounting.journal-entries.edit`, `accounting.reports.ledger-card`, `accounting.reports.trial-balance`).
- Produces: navigation links from the Companies list into each company's accounting screens, and a comprehensive smoke test confirming every route actually renders now that every component behind it exists (this test could not run any earlier — it needs all five components built).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountingRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_all_accounting_routes_render_successfully_for_an_admin(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('accounting.accounts.index', $company))->assertOk();
        $this->get(route('accounting.journal-entries.index', $company))->assertOk();
        $this->get(route('accounting.journal-entries.create', $company))->assertOk();
        $this->get(route('accounting.reports.ledger-card', $company))->assertOk();
        $this->get(route('accounting.reports.trial-balance', $company))->assertOk();
    }

    public function test_accounting_routes_require_authentication(): void
    {
        $company = Company::factory()->create();

        $this->get(route('accounting.accounts.index', $company))->assertRedirect(route('login'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AccountingRoutesTest`
Expected: FAIL — the routes were registered back in Task 7, but there are no navigation links to them yet (`assertSee` calls for links, if any, would fail; more importantly this is the first point every route's target component actually exists simultaneously, so run it first to confirm the baseline before adding navigation).

- [ ] **Step 3: Add navigation links**

In `resources/views/livewire/layout/navigation.blade.php`, alongside the existing `<x-nav-link>` for Companies, add (this app is single-company-context free at the nav level today — link to the companies list, since picking a specific company's accounting screens happens from that list; do not hardcode a single company here):

```blade
<x-nav-link :href="route('companies.index')" :active="request()->routeIs('companies.*') || request()->routeIs('accounting.*')">
    {{ __('Companies') }}
</x-nav-link>
```

(If a "Companies" nav link already exists, only update its `:active` condition to also match `accounting.*`, matching the pattern above, rather than duplicating the link.) Then, on the `company-index.blade.php` view (`resources/views/livewire/company-index.blade.php`), add links from each listed company into its accounting screens:

```blade
<li class="py-2 flex items-center justify-between">
    <span>{{ $company->name }}</span>
    <span class="space-x-3 text-sm">
        <a href="{{ route('accounting.accounts.index', $company) }}" class="text-indigo-600 hover:underline">Accounts</a>
        <a href="{{ route('accounting.journal-entries.index', $company) }}" class="text-indigo-600 hover:underline">Journal</a>
        <a href="{{ route('accounting.reports.ledger-card', $company) }}" class="text-indigo-600 hover:underline">Ledger Card</a>
        <a href="{{ route('accounting.reports.trial-balance', $company) }}" class="text-indigo-600 hover:underline">Trial Balance</a>
    </span>
</li>
```

Replace the existing `<li class="py-2">{{ $company->name }}</li>` in that file with the block above.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AccountingRoutesTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Run the full test suite**

Run: `php artisan test`
Expected: PASS — every test from Tasks 1–12 plus the existing Phase 0 suite green.

- [ ] **Step 6: Commit**

```bash
git add resources/views/livewire/layout/navigation.blade.php resources/views/livewire/company-index.blade.php tests/Feature/AccountingRoutesTest.php
git commit -m "feat: add navigation links into the accounting core module"
```

---

## Post-Plan Manual Verification

After all tasks are complete, per this project's practice of testing real features in a browser before calling work done:

1. Log in as `admin@tami.test`, open a company's **Accounts** screen, confirm all 428 accounts appear grouped by class, deactivate one, add an analytical account under `234`.
2. Create a journal entry with a MKD-only balanced transaction; confirm it appears immediately in the journal list and the trial balance.
3. Create a journal entry with a EUR line, click the NBRM rate button, confirm the fetched rate and computed MKD amount look sane against `nbrm.mk/kursna_lista-en.nspx`.
4. Pull up the Ledger Card report filtered to that account, confirm the running balance is correct.
5. Log in as `client@tami.test`, confirm the accounting screens are visible but read-only (no "New Entry" button, no deactivate/add-account controls).
