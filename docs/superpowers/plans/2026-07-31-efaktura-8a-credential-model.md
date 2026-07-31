# Phase 8a: е-Фактура Credential Model Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a per-company е-Фактура credential model to the Company Profile screen — each company either supplies its own е-Фактура credentials (X-EUJP-ID + a `.p12`/`.pfx` certificate + password) or requests to use the accounting firm's own certificate, subject to admin approval — with no live е-Фактура API calls yet (that's Phase 8b/8c).

**Architecture:** Flat new columns on the existing `companies` table (mode toggle + own-mode secrets, encrypted at rest via Laravel's `encrypted` cast; certificate file stored on the existing private `local` disk, never `google` or `public`). The existing `CompanyDashboard` Livewire component (admin-only edit form) gains the mode toggle and own-mode fields; a new, separately-authorized action lets *any* user who can view the company request firm-fallback access; a new admin-only Livewire screen lists pending requests with approve/reject actions.

**Tech Stack:** Laravel 13, Livewire 3 (`WithFileUploads`), Tailwind, spatie/laravel-permission, PHPUnit (class-based, not Pest — this codebase has no Pest test files despite the plugin being installed).

## Global Constraints

- No `'encrypted'` Eloquent cast exists anywhere in this codebase yet — this plan introduces the pattern for the first time; don't assume any other code already does this.
- The certificate file and its password must NEVER be stored on the `google` disk (used for regular Documents) or the `public` disk (used for the company logo) — only the existing private `local` disk (`storage/app/private`, already defined in `config/filesystems.php`, currently unused by any model).
- This codebase's route registration for Livewire full-page components always uses the array-callable form `[ComponentClass::class, '__invoke']`, never a bare class-string — bare class-string crashes app boot via an eager `method_exists()` check if the target class doesn't exist yet at registration time.
- Livewire authorization convention in this codebase: call `Gate::authorize(...)` (or `abort_unless(...)` for non-policy checks) directly inside `mount()`/action methods — not middleware, not `@can` alone (though `@can` is used in blade to gate visibility of buttons/forms).
- Test suite runs on SQLite locally; this plan's migration only adds nullable/defaulted columns (no new indexes, no MySQL-identifier-length risk), so no CI-only MySQL failure is expected here — still worth remembering as a recurring project gotcha for later е-Фактура tasks (8b/8c) that add tables/indexes.
- Local dev DB needs a manual `php artisan migrate` before any browser verification session (recurring project gotcha).

---

### Task 1: Migration + `Company` model — е-Фактура credential fields

**Files:**
- Create: `database/migrations/2026_07_31_100000_add_efaktura_fields_to_companies_table.php`
- Modify: `app/Models/Company.php`
- Test: `tests/Unit/CompanyEfakturaAccessTest.php`

**Interfaces:**
- Produces: `Company::EFAKTURA_MODE_OWN` / `Company::EFAKTURA_MODE_FIRM` string constants; `Company::EFAKTURA_STATUS_NONE` / `_REQUESTED` / `_APPROVED` / `_REJECTED` string constants; `Company::hasEfakturaAccess(): bool`; fillable/cast attributes `efaktura_credential_mode`, `efaktura_eujp_id`, `efaktura_certificate_path` (encrypted), `efaktura_certificate_password` (encrypted), `efaktura_firm_access_status` — these exact names are consumed by Tasks 2-5.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyEfakturaAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_to_firm_mode_with_no_access(): void
    {
        $company = Company::factory()->create();

        $this->assertSame(Company::EFAKTURA_MODE_FIRM, $company->efaktura_credential_mode);
        $this->assertSame(Company::EFAKTURA_STATUS_NONE, $company->efaktura_firm_access_status);
        $this->assertFalse($company->hasEfakturaAccess());
    }

    public function test_firm_mode_has_access_only_once_approved(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM,
            'efaktura_firm_access_status' => Company::EFAKTURA_STATUS_REQUESTED,
        ]);

        $this->assertFalse($company->hasEfakturaAccess());

        $company->update(['efaktura_firm_access_status' => Company::EFAKTURA_STATUS_APPROVED]);

        $this->assertTrue($company->fresh()->hasEfakturaAccess());
    }

    public function test_own_mode_has_access_only_with_eujp_id_and_certificate(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN]);

        $this->assertFalse($company->hasEfakturaAccess());

        $company->update([
            'efaktura_eujp_id' => 'EUJP-123',
            'efaktura_certificate_path' => 'efaktura-certs/1/cert.p12',
        ]);

        $this->assertTrue($company->fresh()->hasEfakturaAccess());
    }

    public function test_certificate_path_and_password_are_encrypted_at_rest(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_certificate_path' => 'efaktura-certs/1/cert.p12',
            'efaktura_certificate_password' => 'super-secret',
        ]);

        $rawPath = \DB::table('companies')->where('id', $company->id)->value('efaktura_certificate_path');
        $rawPassword = \DB::table('companies')->where('id', $company->id)->value('efaktura_certificate_password');

        $this->assertNotSame('efaktura-certs/1/cert.p12', $rawPath);
        $this->assertNotSame('super-secret', $rawPassword);
        $this->assertSame('efaktura-certs/1/cert.p12', $company->fresh()->efaktura_certificate_path);
        $this->assertSame('super-secret', $company->fresh()->efaktura_certificate_password);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/CompanyEfakturaAccessTest.php`
Expected: FAIL — `Column not found: efaktura_credential_mode` (migration doesn't exist yet) or `Undefined constant App\Models\Company::EFAKTURA_MODE_FIRM`.

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
            $table->string('efaktura_credential_mode')->default('firm')->after('invoice_footer_note');
            $table->string('efaktura_eujp_id')->nullable()->after('efaktura_credential_mode');
            $table->text('efaktura_certificate_path')->nullable()->after('efaktura_eujp_id');
            $table->text('efaktura_certificate_password')->nullable()->after('efaktura_certificate_path');
            $table->string('efaktura_firm_access_status')->default('none')->after('efaktura_certificate_password');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'efaktura_credential_mode', 'efaktura_eujp_id', 'efaktura_certificate_path',
                'efaktura_certificate_password', 'efaktura_firm_access_status',
            ]);
        });
    }
};
```

Run: `php artisan migrate`
Expected: `2026_07_31_100000_add_efaktura_fields_to_companies_table ... DONE`

- [ ] **Step 4: Update the `Company` model**

Modify `app/Models/Company.php` — add to `$fillable`, add constants, replace `casts()`, add `hasEfakturaAccess()`:

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

    public const EFAKTURA_MODE_OWN = 'own';
    public const EFAKTURA_MODE_FIRM = 'firm';

    public const EFAKTURA_STATUS_NONE = 'none';
    public const EFAKTURA_STATUS_REQUESTED = 'requested';
    public const EFAKTURA_STATUS_APPROVED = 'approved';
    public const EFAKTURA_STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'name', 'short_name', 'tax_id', 'registration_number', 'nkd_code', 'nkd_name',
        'email', 'phone', 'address', 'website', 'director_name', 'director_phone', 'director_email',
        'logo_path', 'logo_position', 'is_vat_registered', 'invoice_footer_note',
        'efaktura_credential_mode', 'efaktura_eujp_id', 'efaktura_certificate_path',
        'efaktura_certificate_password', 'efaktura_firm_access_status',
    ];

    protected function casts(): array
    {
        return [
            'is_vat_registered' => 'boolean',
            'efaktura_certificate_path' => 'encrypted',
            'efaktura_certificate_password' => 'encrypted',
        ];
    }

    public function clients(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function accountants(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CompanyBankAccount::class)->orderBy('position');
    }

    public function hasEfakturaAccess(): bool
    {
        if ($this->efaktura_credential_mode === self::EFAKTURA_MODE_OWN) {
            return filled($this->efaktura_eujp_id) && filled($this->efaktura_certificate_path);
        }

        return $this->efaktura_firm_access_status === self::EFAKTURA_STATUS_APPROVED;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Unit/CompanyEfakturaAccessTest.php`
Expected: PASS (4 tests)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_31_100000_add_efaktura_fields_to_companies_table.php app/Models/Company.php tests/Unit/CompanyEfakturaAccessTest.php
git commit -m "feat: add е-Фактура credential fields to Company model"
```

---

### Task 2: `CompanyDashboard` edit form — own-mode credential fields

**Files:**
- Modify: `app/Livewire/CompanyDashboard.php`
- Modify: `resources/views/livewire/company-dashboard.blade.php`
- Test: `tests/Feature/CompanyDashboardEfakturaCredentialsTest.php`

**Interfaces:**
- Consumes: `Company::EFAKTURA_MODE_OWN`/`EFAKTURA_MODE_FIRM` (Task 1).
- Produces: `CompanyDashboard` public properties `editEfakturaMode`, `editEfakturaEujpId`, `newEfakturaCertificate`, `editEfakturaCertificatePassword` — not consumed elsewhere, but keep these exact names since Task 3 adds a *different* method (`requestFirmEfakturaAccess`) to the same class and must not collide.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\CompanyDashboard;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyDashboardEfakturaCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_admin_can_switch_company_to_own_efaktura_mode_with_certificate(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $cert = UploadedFile::fake()->create('cert.p12', 10);

        Livewire::actingAs($admin)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('editEfakturaMode', Company::EFAKTURA_MODE_OWN)
            ->set('editEfakturaEujpId', 'EUJP-999')
            ->set('newEfakturaCertificate', $cert)
            ->set('editEfakturaCertificatePassword', 'pw-123')
            ->call('save')
            ->assertHasNoErrors();

        $company->refresh();
        $this->assertSame(Company::EFAKTURA_MODE_OWN, $company->efaktura_credential_mode);
        $this->assertSame('EUJP-999', $company->efaktura_eujp_id);
        $this->assertSame('pw-123', $company->efaktura_certificate_password);
        Storage::disk('local')->assertExists($company->efaktura_certificate_path);
    }

    public function test_switching_back_to_firm_mode_clears_own_mode_secrets(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-999',
            'efaktura_certificate_path' => 'efaktura-certs/1/cert.p12',
            'efaktura_certificate_password' => 'pw-123',
        ]);

        Livewire::actingAs($admin)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('editEfakturaMode', Company::EFAKTURA_MODE_FIRM)
            ->call('save')
            ->assertHasNoErrors();

        $company->refresh();
        $this->assertSame(Company::EFAKTURA_MODE_FIRM, $company->efaktura_credential_mode);
        $this->assertNull($company->efaktura_eujp_id);
        $this->assertNull($company->efaktura_certificate_path);
        $this->assertNull($company->efaktura_certificate_password);
    }

    public function test_client_cannot_edit_efaktura_credentials(): void
    {
        $client = User::factory()->create();
        $client->assignRole('client');
        $company = Company::factory()->create();
        $client->update(['company_id' => $company->id]);

        Livewire::actingAs($client)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/CompanyDashboardEfakturaCredentialsTest.php`
Expected: FAIL — `Unable to set property [editEfakturaMode] on component` (properties don't exist yet).

- [ ] **Step 3: Extend `CompanyDashboard`**

Modify `app/Livewire/CompanyDashboard.php`:
1. Add public properties alongside the existing `editLogoPosition`/`newLogo` declarations:

```php
    public string $editEfakturaMode = 'firm';
    public string $editEfakturaEujpId = '';
    public $newEfakturaCertificate = null;
    public string $editEfakturaCertificatePassword = '';
```

2. In `startEdit()`, alongside the other `edit*` seeding lines, add:

```php
        $this->editEfakturaMode = $this->company->efaktura_credential_mode;
        $this->editEfakturaEujpId = (string) $this->company->efaktura_eujp_id;
        $this->editEfakturaCertificatePassword = '';
        $this->newEfakturaCertificate = null;
```

3. In `save()`, add to the `$this->validate([...])` array:

```php
            'editEfakturaMode' => ['required', \Illuminate\Validation\Rule::in([
                \App\Models\Company::EFAKTURA_MODE_OWN, \App\Models\Company::EFAKTURA_MODE_FIRM,
            ])],
            'editEfakturaEujpId' => 'nullable|string|max:100',
            'newEfakturaCertificate' => 'nullable|file|max:5120|mimes:p12,pfx',
            'editEfakturaCertificatePassword' => 'nullable|string|max:255',
```

4. Still in `save()`, inside the existing `DB::transaction(function () use ($validated) { ... })`, replace the block that calls `$this->company->update([...])` for the simple fields so the array it builds also branches on the е-Фактура mode. Build the update array as a local variable first, then update once:

```php
            $companyData = [
                'name' => $validated['editName'],
                'short_name' => $validated['editShortName'],
                'tax_id' => $validated['editTaxId'],
                'registration_number' => $validated['editRegistrationNumber'],
                'nkd_code' => $validated['editNkdCode'],
                'nkd_name' => $validated['editNkdName'],
                'email' => $validated['editEmail'],
                'phone' => $validated['editPhone'],
                'website' => $validated['editWebsite'],
                'address' => $validated['editAddress'],
                'director_name' => $validated['editDirectorName'],
                'director_phone' => $validated['editDirectorPhone'],
                'director_email' => $validated['editDirectorEmail'],
                'is_vat_registered' => $validated['editIsVatRegistered'],
                'logo_position' => $validated['editLogoPosition'],
                'invoice_footer_note' => $validated['editInvoiceFooterNote'],
                'efaktura_credential_mode' => $validated['editEfakturaMode'],
            ];

            if ($validated['editEfakturaMode'] === \App\Models\Company::EFAKTURA_MODE_OWN) {
                if (filled($validated['editEfakturaEujpId'])) {
                    $companyData['efaktura_eujp_id'] = $validated['editEfakturaEujpId'];
                }
                if ($this->newEfakturaCertificate) {
                    $companyData['efaktura_certificate_path'] = $this->newEfakturaCertificate
                        ->store('efaktura-certs/'.$this->company->id, 'local');
                }
                if (filled($validated['editEfakturaCertificatePassword'])) {
                    $companyData['efaktura_certificate_password'] = $validated['editEfakturaCertificatePassword'];
                }
            } else {
                $companyData['efaktura_eujp_id'] = null;
                $companyData['efaktura_certificate_path'] = null;
                $companyData['efaktura_certificate_password'] = null;
            }

            $this->company->update($companyData);
            $this->newEfakturaCertificate = null;
```

   Keep the existing bank-account and logo-handling code that already lives inside the same transaction; only the plain-field `update()` call is being replaced by the block above (this codebase's existing `save()` already updates simple fields via one `update()` call, so this is a like-for-like replacement, not an addition of a second `update()` call).

- [ ] **Step 4: Extend the blade edit form**

Modify `resources/views/livewire/company-dashboard.blade.php` — inside the existing `@if ($editing)` edit-form block, after the logo section, add:

```blade
<div>
    <h3 class="text-sm font-semibold text-gray-700 mb-2">е-Фактура акредитиви</h3>
    <div class="flex gap-4 mb-3">
        <label class="inline-flex items-center gap-2">
            <input type="radio" wire:model="editEfakturaMode" value="firm">
            <span>Користи го фирменото</span>
        </label>
        <label class="inline-flex items-center gap-2">
            <input type="radio" wire:model="editEfakturaMode" value="own">
            <span>Сопствени акредитиви</span>
        </label>
    </div>

    @if ($editEfakturaMode === 'own')
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm text-gray-600 mb-1">X-EUJP-ID</label>
                <input type="text" wire:model="editEfakturaEujpId" class="w-full rounded-lg border-gray-300">
                @error('editEfakturaEujpId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">Лозинка на сертификат</label>
                <input type="password" wire:model="editEfakturaCertificatePassword" class="w-full rounded-lg border-gray-300" placeholder="Остави празно за да ја задржиш постојната">
                @error('editEfakturaCertificatePassword') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm text-gray-600 mb-1">Сертификат (.p12/.pfx)</label>
                <input type="file" wire:model="newEfakturaCertificate" accept=".p12,.pfx" class="text-sm">
                @if ($company->efaktura_certificate_path)
                    <p class="text-xs text-gray-500 mt-1">Веќе е поставен сертификат — избери нов фајл само ако сакаш да го замениш.</p>
                @endif
                @error('newEfakturaCertificate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
        </div>
    @endif
</div>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/CompanyDashboardEfakturaCredentialsTest.php`
Expected: PASS (3 tests)

- [ ] **Step 6: Run the full existing `CompanyDashboard` test file to check for regressions**

Run: `php artisan test tests/Feature/CompanyDashboardTest.php`
Expected: PASS (no regressions from restructuring the `update()` call into `$companyData`)

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/CompanyDashboard.php resources/views/livewire/company-dashboard.blade.php tests/Feature/CompanyDashboardEfakturaCredentialsTest.php
git commit -m "feat: add own-mode е-Фактура credential fields to Company edit form"
```

---

### Task 3: Request firm-fallback access (client-visible action)

**Files:**
- Modify: `app/Livewire/CompanyDashboard.php`
- Modify: `resources/views/livewire/company-dashboard.blade.php`
- Test: `tests/Feature/CompanyDashboardEfakturaRequestTest.php`

**Interfaces:**
- Consumes: `Company::EFAKTURA_MODE_FIRM`, `EFAKTURA_STATUS_NONE/_REQUESTED/_REJECTED` (Task 1).
- Produces: `CompanyDashboard::requestFirmEfakturaAccess(): void` — a new action method, authorized via the existing `view` ability (not `update`), so any role that can see the company (admin, assigned accountant, the company's own client) can trigger it.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\CompanyDashboard;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyDashboardEfakturaRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_client_can_request_firm_efaktura_access(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        Livewire::actingAs($client)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('requestFirmEfakturaAccess');

        $this->assertSame(Company::EFAKTURA_STATUS_REQUESTED, $company->fresh()->efaktura_firm_access_status);
    }

    public function test_request_is_a_no_op_when_company_is_in_own_mode(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN]);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        Livewire::actingAs($client)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('requestFirmEfakturaAccess');

        $this->assertSame(Company::EFAKTURA_STATUS_NONE, $company->fresh()->efaktura_firm_access_status);
    }

    public function test_unrelated_user_cannot_request_access_for_a_company_they_cannot_view(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $otherCompany = Company::factory()->create();
        $unrelatedClient = User::factory()->create(['company_id' => $otherCompany->id]);
        $unrelatedClient->assignRole('client');

        Livewire::actingAs($unrelatedClient)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/CompanyDashboardEfakturaRequestTest.php`
Expected: FAIL — `Method requestFirmEfakturaAccess does not exist` (first test), third test may already pass since `mount()` already authorizes `view`.

- [ ] **Step 3: Add the action method**

Modify `app/Livewire/CompanyDashboard.php` — add a new public method (near `cancelEdit()`):

```php
    public function requestFirmEfakturaAccess(): void
    {
        Gate::authorize('view', $this->company);

        if ($this->company->efaktura_credential_mode !== \App\Models\Company::EFAKTURA_MODE_FIRM) {
            return;
        }

        $this->company->update(['efaktura_firm_access_status' => \App\Models\Company::EFAKTURA_STATUS_REQUESTED]);
    }
```

- [ ] **Step 4: Add the blade section**

Modify `resources/views/livewire/company-dashboard.blade.php` — outside the `@if ($editing)` block (so it's visible to every role that can view the page, not just when the admin-only edit form is open), near the top of the page:

```blade
@if ($company->efaktura_credential_mode === \App\Models\Company::EFAKTURA_MODE_FIRM)
    <x-card>
        <h3 class="text-sm font-semibold text-gray-700 mb-2">е-Фактура пристап</h3>
        @if (in_array($company->efaktura_firm_access_status, [
                \App\Models\Company::EFAKTURA_STATUS_NONE,
                \App\Models\Company::EFAKTURA_STATUS_REJECTED,
            ]))
            <button wire:click="requestFirmEfakturaAccess" type="button" class="rounded-full bg-orange-600 text-white px-4 py-2 text-sm">
                Побарај користење на фирмениот сертификат
            </button>
        @elseif ($company->efaktura_firm_access_status === \App\Models\Company::EFAKTURA_STATUS_REQUESTED)
            <x-badge status="amber">Чека одобрување</x-badge>
        @elseif ($company->efaktura_firm_access_status === \App\Models\Company::EFAKTURA_STATUS_APPROVED)
            <x-badge status="green">Одобрено</x-badge>
        @endif
    </x-card>
@endif
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/CompanyDashboardEfakturaRequestTest.php`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/CompanyDashboard.php resources/views/livewire/company-dashboard.blade.php tests/Feature/CompanyDashboardEfakturaRequestTest.php
git commit -m "feat: let any company viewer request firm-fallback е-Фактура access"
```

---

### Task 4: Admin approval screen

**Files:**
- Create: `app/Livewire/EfakturaAccessRequests.php`
- Create: `resources/views/livewire/efaktura-access-requests.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/EfakturaAccessRequestsTest.php`

**Interfaces:**
- Consumes: `Company::EFAKTURA_STATUS_REQUESTED/_APPROVED/_REJECTED` (Task 1).
- Produces: route `efaktura.access-requests` (GET `/efaktura/access-requests`); `EfakturaAccessRequests::approve(Company $company)` / `::reject(Company $company)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\EfakturaAccessRequests;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaAccessRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_admin_sees_only_requested_companies(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $requested = Company::factory()->create([
            'name' => 'Побарана',
            'efaktura_firm_access_status' => Company::EFAKTURA_STATUS_REQUESTED,
        ]);
        Company::factory()->create(['efaktura_firm_access_status' => Company::EFAKTURA_STATUS_NONE]);

        Livewire::actingAs($admin)
            ->test(EfakturaAccessRequests::class)
            ->assertSee('Побарана')
            ->assertSeeHtml((string) $requested->id);
    }

    public function test_admin_can_approve_a_request(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['efaktura_firm_access_status' => Company::EFAKTURA_STATUS_REQUESTED]);

        Livewire::actingAs($admin)
            ->test(EfakturaAccessRequests::class)
            ->call('approve', $company);

        $this->assertSame(Company::EFAKTURA_STATUS_APPROVED, $company->fresh()->efaktura_firm_access_status);
    }

    public function test_admin_can_reject_a_request(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['efaktura_firm_access_status' => Company::EFAKTURA_STATUS_REQUESTED]);

        Livewire::actingAs($admin)
            ->test(EfakturaAccessRequests::class)
            ->call('reject', $company);

        $this->assertSame(Company::EFAKTURA_STATUS_REJECTED, $company->fresh()->efaktura_firm_access_status);
    }

    public function test_non_admin_cannot_view_the_screen(): void
    {
        $client = User::factory()->create();
        $client->assignRole('client');

        Livewire::actingAs($client)->test(EfakturaAccessRequests::class);
    }
}
```

Note: the fourth test expects a 403 abort during `mount()`; Livewire's test helper converts an aborted mount into a thrown HTTP exception, so this test will show as an error until Step 3 exists — that's expected and gets resolved once `abort_unless` is in place (Livewire testing surfaces the abort as a genuine HTTP exception, which PHPUnit reports as a normal test failure/exception you can assert on with `expectException` if stricter checking is wanted; for this codebase's existing convention — see `CompanyDashboardEfakturaCredentialsTest::test_client_cannot_edit_efaktura_credentials` above using `->assertForbidden()` — prefer that assertion style here too):

```php
    public function test_non_admin_cannot_view_the_screen(): void
    {
        $client = User::factory()->create();
        $client->assignRole('client');

        Livewire::actingAs($client)->test(EfakturaAccessRequests::class)->assertForbidden();
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/EfakturaAccessRequestsTest.php`
Expected: FAIL — `Class "App\Livewire\EfakturaAccessRequests" not found`.

- [ ] **Step 3: Create the Livewire component**

Create `app/Livewire/EfakturaAccessRequests.php`:

```php
<?php

namespace App\Livewire;

use App\Models\Company;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class EfakturaAccessRequests extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('admin'), 403);
    }

    public function approve(Company $company): void
    {
        abort_unless(auth()->user()->hasRole('admin'), 403);
        $company->update(['efaktura_firm_access_status' => Company::EFAKTURA_STATUS_APPROVED]);
    }

    public function reject(Company $company): void
    {
        abort_unless(auth()->user()->hasRole('admin'), 403);
        $company->update(['efaktura_firm_access_status' => Company::EFAKTURA_STATUS_REJECTED]);
    }

    public function render()
    {
        $pendingCompanies = Company::where('efaktura_firm_access_status', Company::EFAKTURA_STATUS_REQUESTED)
            ->orderBy('name')
            ->get();

        return view('livewire.efaktura-access-requests', ['pendingCompanies' => $pendingCompanies]);
    }
}
```

- [ ] **Step 4: Create the blade view**

Create `resources/views/livewire/efaktura-access-requests.blade.php`:

```blade
<div class="max-w-3xl mx-auto py-6">
    <h1 class="text-lg font-semibold mb-4">Чекаат одобрување — фирмени е-Фактура акредитиви</h1>

    @if ($pendingCompanies->isEmpty())
        <p class="text-gray-500">Нема барања што чекаат одобрување.</p>
    @else
        <div class="space-y-3">
            @foreach ($pendingCompanies as $company)
                <x-card>
                    <div class="flex items-center justify-between">
                        <span>{{ $company->name }}</span>
                        <span class="hidden" data-company-id="{{ $company->id }}"></span>
                        <div class="flex gap-2">
                            <button wire:click="approve({{ $company->id }})" type="button" class="rounded-full bg-green-600 text-white px-4 py-1.5 text-sm">Одобри</button>
                            <button wire:click="reject({{ $company->id }})" type="button" class="rounded-full bg-red-600 text-white px-4 py-1.5 text-sm">Одбиј</button>
                        </div>
                    </div>
                </x-card>
            @endforeach
        </div>
    @endif
</div>
```

- [ ] **Step 5: Register the route**

Modify `routes/web.php` — add near the other top-level (non company-scoped) authenticated routes:

```php
Route::middleware(['auth'])->get('/efaktura/access-requests', [\App\Livewire\EfakturaAccessRequests::class, '__invoke'])->name('efaktura.access-requests');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/EfakturaAccessRequestsTest.php`
Expected: PASS (4 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/EfakturaAccessRequests.php resources/views/livewire/efaktura-access-requests.blade.php routes/web.php tests/Feature/EfakturaAccessRequestsTest.php
git commit -m "feat: add admin screen for approving firm-fallback е-Фактура requests"
```

---

### Task 5: Navigation link + whole-feature regression pass

**Files:**
- Modify: `resources/views/layouts/navigation.blade.php`
- Test: `tests/Feature/EfakturaCredentialModelRegressionTest.php`

**Interfaces:**
- Consumes: route `efaktura.access-requests` (Task 4), all `Company::EFAKTURA_*` constants (Task 1).

- [ ] **Step 1: Add the navigation link**

Modify `resources/views/layouts/navigation.blade.php` — this is Laravel Breeze's standard scaffold from Phase 0a, which always includes a `<x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">{{ __('Dashboard') }}</x-nav-link>` entry in the desktop nav list. Add a sibling link immediately after it, gated to admin only:

```blade
@if (auth()->user()->hasRole('admin'))
    <x-nav-link :href="route('efaktura.access-requests')" :active="request()->routeIs('efaktura.access-requests')">
        {{ __('е-Фактура барања') }}
    </x-nav-link>
@endif
```

If the file's exact desktop-nav markup differs slightly from this assumption (e.g. a different Breeze layout variant), add this block as a sibling of whatever existing `<x-nav-link>` entries are already present in the same `<div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">`-style container, keeping the same `@if (auth()->user()->hasRole('admin'))` guard.

- [ ] **Step 2: Write and run the regression test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\CompanyDashboard;
use App\Livewire\EfakturaAccessRequests;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaCredentialModelRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_full_request_then_approve_flow_grants_access(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertFalse($company->hasEfakturaAccess());

        Livewire::actingAs($client)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('requestFirmEfakturaAccess');

        $this->assertFalse($company->fresh()->hasEfakturaAccess());

        Livewire::actingAs($admin)
            ->test(EfakturaAccessRequests::class)
            ->call('approve', $company->fresh());

        $this->assertTrue($company->fresh()->hasEfakturaAccess());
    }

    public function test_navigation_link_only_renders_for_admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $client = User::factory()->create();
        $client->assignRole('client');

        $this->actingAs($admin)->get(route('dashboard'))->assertSee('е-Фактура барања');
        $this->actingAs($client)->get(route('dashboard'))->assertDontSee('е-Фактура барања');
    }
}
```

Run: `php artisan test tests/Feature/EfakturaCredentialModelRegressionTest.php`
Expected: PASS (2 tests)

- [ ] **Step 3: Run the full test suite**

Run: `php artisan test`
Expected: All tests PASS (no regressions in any pre-existing `Company*`/`Dashboard*` test files).

- [ ] **Step 4: Commit**

```bash
git add resources/views/layouts/navigation.blade.php tests/Feature/EfakturaCredentialModelRegressionTest.php
git commit -m "feat: add nav link to е-Фактура access-requests screen and lock in end-to-end regression test"
```

---

## After this plan

Phase 8a delivers the full credential/permission model with zero live е-Фактура API calls — everything is testable today. Phase 8b (signing + sending sales invoices) and Phase 8c (discovering/accepting incoming purchase invoices) each get their own follow-on plan once this one is deployed and reviewed, per the design doc's split (`docs/superpowers/specs/2026-07-31-efaktura-integration-design.md`). Live signing/sending can only be verified once the user actually connects a real certificate — that remains a separate, short manual step after 8b ships.
