# Phase 0a: App Skeleton, Auth & Multi-Tenancy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up a working Laravel app — installable and testable entirely on the local Windows dev machine — with the three-role permission system and shared-schema multi-tenancy (companies scoped by `company_id`) proven end-to-end by passing tests.

**Architecture:** Laravel + Livewire + Tailwind (via Breeze's Livewire stack) for the UI shell. Spatie Laravel-Permission for roles. A `companies` table is the tenancy anchor: `users.company_id` scopes Client-role users to one company; a `company_user` pivot scopes Accountant-role users to many companies; Admin-role users see everything. A `CompanyPolicy` centralizes the authorization rule, proven by a Livewire "Companies" list page that only shows what each role is allowed to see.

**Tech Stack:** PHP 8.3 (NTS, matching the production droplet), Laravel (latest stable via `composer create-project`), Livewire 3, Tailwind CSS, Laravel Breeze (Livewire stack), spatie/laravel-permission, SQLite for both local dev and automated tests (production will use MySQL — that swap is configuration only, handled in Phase 0b, not this plan).

## Global Constraints

- PHP version must be 8.3.x to match the droplet (`PHP 8.3.31 (cli) (NTS)`) — confirmed via SSH on 2026-07-17.
- No MySQL install required locally — SQLite is used for local dev and testing per the design's `docs/superpowers/specs/2026-07-17-phase0-foundation-design.md`. Production MySQL config is out of scope for this plan.
- Brand colors from the design: `#ff6600` primary, white / light gray — apply as Tailwind theme extensions, not used heavily yet (no real UI screens in this phase beyond the companies list).
- Three roles are exactly: `admin`, `accountant`, `client` (lowercase, matching Spatie's role-name convention) — per `docs/superpowers/specs/2026-07-17-phase0-foundation-design.md`.
- This plan does NOT cover: Google Drive integration, nightly backups, GitHub Actions deployment, or production MySQL — those are Phase 0b, a separate plan.
- Every task must end with a commit. Do not batch multiple tasks into one commit.

---

## File Structure

| File | Responsibility |
|---|---|
| `composer.json` | PHP dependencies (Laravel, Breeze, Livewire, spatie/laravel-permission) |
| `database/migrations/*_create_companies_table.php` | `companies` table schema |
| `database/migrations/*_add_company_id_to_users_table.php` | Tenancy FK on `users` |
| `database/migrations/*_create_company_user_table.php` | Accountant↔Company many-to-many pivot |
| `app/Models/Company.php` | Company model + `clients()`/`accountants()` relations |
| `app/Models/User.php` (modified) | `company()`/`assignedCompanies()` relations |
| `database/factories/CompanyFactory.php` | Test data factory for companies |
| `database/seeders/RoleSeeder.php` | Creates the 3 core roles |
| `database/seeders/DemoDataSeeder.php` | Demo admin/accountant/client + companies for manual local verification |
| `app/Policies/CompanyPolicy.php` | Central authorization rule for who can view which company |
| `app/Livewire/CompanyIndex.php` | Livewire component listing companies scoped to the logged-in user's role |
| `resources/views/livewire/company-index.blade.php` | View for the above |
| `routes/web.php` (modified) | Adds the `/companies` route |
| `tests/Feature/RoleSeederTest.php` | Verifies the 3 roles get created |
| `tests/Feature/CompanyTest.php` | Verifies Company model/factory |
| `tests/Feature/UserCompanyRelationsTest.php` | Verifies tenancy relations on User |
| `tests/Feature/CompanyPolicyTest.php` | Verifies the authorization rule directly |
| `tests/Feature/CompanyIndexTest.php` | Verifies the Livewire page shows the right companies per role |

---

### Task 1: Local PHP 8.3 + Composer setup

**Files:** None (machine setup only, no repo files touched).

**Interfaces:**
- Produces: a working `php` (8.3.x NTS) and `composer` command on the local machine, used by every later task.

- [ ] **Step 1: Install PHP 8.3 (NTS) via winget**

Run:
```bash
winget install --id PHP.PHP.NTS.8.3 -e --accept-source-agreements --accept-package-agreements
```
Expected: winget reports successful installation (exit code 0).

- [ ] **Step 2: Verify PHP is on PATH and is the right version**

Run:
```bash
php --version
```
Expected: `PHP 8.3.x (cli) ... (NTS)`. If the command is not found, PATH hasn't refreshed in this shell — locate it and register it for this session:
```bash
PHP_DIR=$(find "/c/Users/$USERNAME/AppData/Local/Microsoft/WinGet/Packages" -maxdepth 1 -iname "PHP.PHP.NTS.8.3*" 2>/dev/null | head -1)
export PATH="$PHP_DIR:$PATH"
php --version
```

- [ ] **Step 3: Confirm the required PHP extensions are enabled**

Run:
```bash
php -m | grep -Ei 'mysqli|pdo_mysql|pdo_sqlite|mbstring|xml|curl|zip|gd|bcmath'
```
Expected: all of `pdo_sqlite`, `pdo_mysql`, `mbstring`, `xml`, `curl`, `zip`, `gd`, `bcmath` are listed (matches the droplet's module list from 2026-07-17).

- [ ] **Step 4: Install Composer**

Run:
```bash
curl -o "$TEMP/Composer-Setup.exe" https://getcomposer.org/Composer-Setup.exe
"$TEMP/Composer-Setup.exe" /VERYSILENT /SUPPRESSMSGBOXES /NORESTART
```
Expected: silent install completes with no error output.

- [ ] **Step 5: Verify Composer**

Run:
```bash
composer --version
```
Expected: `Composer version 2.x.x ...`. If not found, open a new terminal (Composer's installer updates PATH at the system level, which an already-open shell won't see) and re-run.

No commit for this task — nothing in the repo changed yet.

---

### Task 2: Create the Laravel project and verify it boots

**Files:**
- Create: entire Laravel skeleton (composer.json, artisan, app/, bootstrap/, config/, database/, public/, resources/, routes/, tests/, .env.example, .gitignore) merged into the existing `tami-web-app` repo root (which currently only has `.git/` and `docs/`).

**Interfaces:**
- Produces: a bootable Laravel app at the repo root, with the default `tests/Feature/ExampleTest.php` passing.

- [ ] **Step 1: Scaffold Laravel into a temp folder (avoids Composer conflicting with the existing non-empty repo directory)**

Run:
```bash
cd "C:/Users/FinanceBuddy.mk/Documents"
composer create-project laravel/laravel tami-web-app-scaffold
```
Expected: Composer downloads Laravel and its dependencies, ending with "Application ready! Build something amazing."

- [ ] **Step 2: Merge the scaffold into the existing repo, keeping `docs/` and `.git/` intact**

Run:
```bash
cp -a "C:/Users/FinanceBuddy.mk/Documents/tami-web-app-scaffold/." "C:/Users/FinanceBuddy.mk/Documents/tami-web-app/"
rm -rf "C:/Users/FinanceBuddy.mk/Documents/tami-web-app-scaffold"
cd "C:/Users/FinanceBuddy.mk/Documents/tami-web-app"
ls
```
Expected: repo root now shows both `docs/` and the full Laravel structure (`app/`, `artisan`, `composer.json`, etc.).

- [ ] **Step 3: Configure the local `.env` for SQLite**

Run:
```bash
cp .env.example .env
touch database/database.sqlite
php artisan key:generate
```
Then edit `.env`, replacing the `DB_CONNECTION`/`DB_*` block with:
```
DB_CONNECTION=sqlite
DB_DATABASE=C:/Users/FinanceBuddy.mk/Documents/tami-web-app/database/database.sqlite
```
(Delete the other `DB_HOST`/`DB_PORT`/`DB_USERNAME`/`DB_PASSWORD` lines — SQLite doesn't use them.)

- [ ] **Step 4: Run the default test suite to confirm the app boots correctly**

Run:
```bash
php artisan test
```
Expected: `Tests:  X passed` including the default `ExampleTest` (homepage returns HTTP 200), no failures.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
Scaffold Laravel application

Fresh Laravel install configured for local SQLite dev/testing.
Production will use MySQL (Phase 0b), not committed here.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Install Tailwind, Livewire, and Breeze (Livewire stack)

**Files:**
- Modify: `composer.json`, `package.json`
- Create: `routes/auth.php`, `resources/views/livewire/**` (Breeze-generated auth views), `resources/views/components/**` (Breeze layout components)

**Interfaces:**
- Consumes: bootable Laravel app from Task 2.
- Produces: working `/login` and `/register` pages using Livewire components, Tailwind configured and building successfully. Later tasks' Livewire components follow the same `app/Livewire/*.php` + `resources/views/livewire/*.blade.php` convention Breeze establishes here.

- [ ] **Step 1: Require Breeze and scaffold the Livewire stack**

Run:
```bash
composer require laravel/breeze --dev
php artisan breeze:install livewire
```
Expected: Breeze reports it published routes, views, and Livewire components; prompts (if any) for dark mode — answer `no`.

- [ ] **Step 2: Install and build front-end assets**

Run:
```bash
npm install
npm run build
```
Expected: both commands complete with exit code 0; `public/build/manifest.json` exists afterward.

- [ ] **Step 3: Add brand colors to the Tailwind theme**

In `tailwind.config.js`, inside the `theme.extend` object, add:
```js
colors: {
    brand: {
        DEFAULT: '#ff6600',
        light: '#ff8533',
        dark: '#cc5200',
    },
},
```

- [ ] **Step 4: Rebuild assets and verify the app boots with auth routes present**

Run:
```bash
npm run build
php artisan route:list --name=login
```
Expected: a `GET|HEAD login` route is listed.

- [ ] **Step 5: Run the test suite to confirm Breeze's own tests pass**

Run:
```bash
php artisan test
```
Expected: all tests pass, including Breeze's generated `tests/Feature/Auth/*` tests.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
Add Livewire, Tailwind, and Breeze auth scaffold

Installs the Livewire-stack Breeze auth (login/register/profile) and
adds brand colors (#ff6600) to the Tailwind theme.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Install Spatie roles and seed the 3 core roles

**Files:**
- Modify: `composer.json`, `config/permission.php` (published)
- Create: `database/migrations/*_create_permission_tables.php` (published by the package), `database/seeders/RoleSeeder.php`
- Test: `tests/Feature/RoleSeederTest.php`

**Interfaces:**
- Produces: `Spatie\Permission\Models\Role` rows for `admin`, `accountant`, `client`, usable via `$user->assignRole('admin')` / `$user->hasRole('admin')` in every later task.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/RoleSeederTest.php`:
```php
<?php

namespace Tests\Feature;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_the_three_core_roles(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertTrue(Role::where('name', 'admin')->exists());
        $this->assertTrue(Role::where('name', 'accountant')->exists());
        $this->assertTrue(Role::where('name', 'client')->exists());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run:
```bash
php artisan test --filter=RoleSeederTest
```
Expected: FAIL — `Class "Database\Seeders\RoleSeeder" not found` (or similar), since the package and seeder don't exist yet.

- [ ] **Step 3: Install the package and publish its migration**

Run:
```bash
composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```
Expected: a `create_permission_tables` migration runs successfully.

- [ ] **Step 4: Create the seeder**

Create `database/seeders/RoleSeeder.php`:
```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['admin', 'accountant', 'client'] as $role) {
            Role::findOrCreate($role);
        }
    }
}
```

- [ ] **Step 5: Add the `HasRoles` trait to the User model**

In `app/Models/User.php`, add the import and trait:
```php
use Spatie\Permission\Traits\HasRoles;
```
Add `HasRoles` to the `use` statement inside the class (alongside `HasFactory, Notifiable`).

- [ ] **Step 6: Run test to verify it passes**

Run:
```bash
php artisan test --filter=RoleSeederTest
```
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
Add roles via spatie/laravel-permission

Seeds the three core roles (admin, accountant, client) that every
later authorization check builds on.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Companies table, model, and factory

**Files:**
- Create: `database/migrations/*_create_companies_table.php`, `app/Models/Company.php`, `database/factories/CompanyFactory.php`
- Test: `tests/Feature/CompanyTest.php`

**Interfaces:**
- Produces: `App\Models\Company` with fillable `name`, `tax_id`, `email`, `phone`, `address`, `logo_path`, and a `CompanyFactory` usable by all later tests as `Company::factory()->create([...])`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CompanyTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_company_can_be_created_with_expected_fields(): void
    {
        $company = Company::factory()->create([
            'name' => 'Test Firm DOO',
            'tax_id' => '4030012345678',
        ]);

        $this->assertDatabaseHas('companies', [
            'name' => 'Test Firm DOO',
            'tax_id' => '4030012345678',
        ]);
        $this->assertEquals('Test Firm DOO', $company->fresh()->name);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run:
```bash
php artisan test --filter=CompanyTest
```
Expected: FAIL — `Class "App\Models\Company" not found`.

- [ ] **Step 3: Create the migration**

Run:
```bash
php artisan make:migration create_companies_table
```
Edit the generated file's `up()` method:
```php
public function up(): void
{
    Schema::create('companies', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('tax_id')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('address')->nullable();
        $table->string('logo_path')->nullable();
        $table->timestamps();
    });
}
```

- [ ] **Step 4: Create the model**

Create `app/Models/Company.php`:
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

    protected $fillable = ['name', 'tax_id', 'email', 'phone', 'address', 'logo_path'];

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

- [ ] **Step 5: Create the factory**

Create `database/factories/CompanyFactory.php`:
```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'tax_id' => $this->faker->numerify('#############'),
            'email' => $this->faker->companyEmail(),
            'phone' => $this->faker->phoneNumber(),
            'address' => $this->faker->address(),
        ];
    }
}
```

- [ ] **Step 6: Run the migration and re-run the test**

Run:
```bash
php artisan migrate
php artisan test --filter=CompanyTest
```
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
Add Company model, migration, and factory

The tenancy anchor every client company's data will be scoped to.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Tenancy relations on User (company_id + accountant pivot)

**Files:**
- Create: `database/migrations/*_add_company_id_to_users_table.php`, `database/migrations/*_create_company_user_table.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/UserCompanyRelationsTest.php`

**Interfaces:**
- Consumes: `Company` model + factory from Task 5.
- Produces: `$user->company` (belongsTo, for Client-role users), `$user->assignedCompanies()` (belongsToMany, for Accountant-role users) — both used directly by `CompanyPolicy` in Task 7.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/UserCompanyRelationsTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserCompanyRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_user_belongs_to_one_company(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);

        $this->assertTrue($client->company->is($company));
    }

    public function test_an_accountant_can_be_assigned_to_multiple_companies(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $accountant = User::factory()->create();

        $accountant->assignedCompanies()->attach([$companyA->id, $companyB->id]);

        $this->assertCount(2, $accountant->assignedCompanies()->get());
        $this->assertTrue($accountant->assignedCompanies->contains($companyA));
        $this->assertTrue($accountant->assignedCompanies->contains($companyB));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run:
```bash
php artisan test --filter=UserCompanyRelationsTest
```
Expected: FAIL — `company_id` column doesn't exist / `assignedCompanies` method doesn't exist.

- [ ] **Step 3: Create the `company_id` migration**

Run:
```bash
php artisan make:migration add_company_id_to_users_table --table=users
```
Edit `up()`:
```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
    });
}
```
And `down()`:
```php
public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropConstrainedForeignId('company_id');
    });
}
```

- [ ] **Step 4: Create the `company_user` pivot migration**

Run:
```bash
php artisan make:migration create_company_user_table
```
Edit `up()`:
```php
public function up(): void
{
    Schema::create('company_user', function (Blueprint $table) {
        $table->id();
        $table->foreignId('company_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->timestamps();
        $table->unique(['company_id', 'user_id']);
    });
}
```

- [ ] **Step 5: Add relations to the User model**

In `app/Models/User.php`, add imports:
```php
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
```
Add methods inside the class:
```php
public function company(): BelongsTo
{
    return $this->belongsTo(Company::class);
}

public function assignedCompanies(): BelongsToMany
{
    return $this->belongsToMany(Company::class);
}
```

- [ ] **Step 6: Run the migrations and re-run the test**

Run:
```bash
php artisan migrate
php artisan test --filter=UserCompanyRelationsTest
```
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
Add tenancy relations between User and Company

users.company_id scopes Client-role users to one company;
company_user pivot scopes Accountant-role users to many.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: CompanyPolicy — the central authorization rule

**Files:**
- Create: `app/Policies/CompanyPolicy.php`
- Test: `tests/Feature/CompanyPolicyTest.php`

**Interfaces:**
- Consumes: `hasRole()` from Task 4, `company`/`assignedCompanies` relations from Task 6.
- Produces: `Gate::authorize('view', $company)` / `$user->can('view', $company)`, used directly by the Livewire component in Task 8.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CompanyPolicyTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_any_company(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create();

        $this->assertTrue($admin->can('view', $company));
    }

    public function test_client_can_view_only_their_own_company(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');

        $this->assertTrue($client->can('view', $ownCompany));
        $this->assertFalse($client->can('view', $otherCompany));
    }

    public function test_accountant_can_view_only_assigned_companies(): void
    {
        $assigned = Company::factory()->create();
        $notAssigned = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($assigned->id);

        $this->assertTrue($accountant->can('view', $assigned));
        $this->assertFalse($accountant->can('view', $notAssigned));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run:
```bash
php artisan test --filter=CompanyPolicyTest
```
Expected: FAIL — with no policy registered, `$user->can('view', $company)` returns `false` for the admin case too, so `test_admin_can_view_any_company` fails.

- [ ] **Step 3: Create the policy**

Run:
```bash
php artisan make:policy CompanyPolicy --model=Company
```
Replace its contents with:
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
        if ($user->hasRole('admin')) {
            return true;
        }

        if ($user->hasRole('client')) {
            return $user->company_id === $company->id;
        }

        if ($user->hasRole('accountant')) {
            return $user->assignedCompanies()->where('companies.id', $company->id)->exists();
        }

        return false;
    }
}
```

Laravel auto-discovers this policy for the `Company` model because it's named `CompanyPolicy` and lives in `app/Policies` — no manual registration needed.

- [ ] **Step 4: Run test to verify it passes**

Run:
```bash
php artisan test --filter=CompanyPolicyTest
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
Add CompanyPolicy for role-based company visibility

Admin sees all, Client sees only their own company, Accountant sees
only explicitly assigned companies.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Companies Livewire page (proves tenancy end-to-end)

**Files:**
- Create: `app/Livewire/CompanyIndex.php`, `resources/views/livewire/company-index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/CompanyIndexTest.php`

**Interfaces:**
- Consumes: `CompanyPolicy` from Task 7 (indirectly, by filtering the same way in the query), `Company` model from Task 5.
- Produces: `GET /companies` (route name `companies.index`), the first real screen in the app — later modules (Inventory, Invoicing) will link into per-company pages from here.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CompanyIndexTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Livewire\CompanyIndex;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_all_companies(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Company::factory()->create(['name' => 'Alpha Ltd']);
        Company::factory()->create(['name' => 'Beta Ltd']);

        $this->actingAs($admin);

        Livewire::test(CompanyIndex::class)
            ->assertSee('Alpha Ltd')
            ->assertSee('Beta Ltd');
    }

    public function test_client_sees_only_their_own_company(): void
    {
        $companyA = Company::factory()->create(['name' => 'Alpha Ltd']);
        $companyB = Company::factory()->create(['name' => 'Beta Ltd']);
        $client = User::factory()->create(['company_id' => $companyA->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        Livewire::test(CompanyIndex::class)
            ->assertSee('Alpha Ltd')
            ->assertDontSee('Beta Ltd');
    }

    public function test_accountant_sees_only_assigned_companies(): void
    {
        $companyA = Company::factory()->create(['name' => 'Alpha Ltd']);
        $companyB = Company::factory()->create(['name' => 'Beta Ltd']);
        $companyC = Company::factory()->create(['name' => 'Gamma Ltd']);
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach([$companyA->id, $companyB->id]);

        $this->actingAs($accountant);

        Livewire::test(CompanyIndex::class)
            ->assertSee('Alpha Ltd')
            ->assertSee('Beta Ltd')
            ->assertDontSee('Gamma Ltd');
    }

    public function test_the_route_requires_authentication(): void
    {
        $this->get('/companies')->assertRedirect('/login');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run:
```bash
php artisan test --filter=CompanyIndexTest
```
Expected: FAIL — `Class "App\Livewire\CompanyIndex" not found`.

- [ ] **Step 3: Create the Livewire component**

Run:
```bash
php artisan make:livewire CompanyIndex
```
Replace `app/Livewire/CompanyIndex.php` contents with:
```php
<?php

namespace App\Livewire;

use App\Models\Company;
use Livewire\Component;

class CompanyIndex extends Component
{
    public function render()
    {
        $user = auth()->user();

        $companies = match (true) {
            $user->hasRole('admin') => Company::orderBy('name')->get(),
            $user->hasRole('client') => Company::where('id', $user->company_id)->get(),
            $user->hasRole('accountant') => $user->assignedCompanies()->orderBy('name')->get(),
            default => collect(),
        };

        return view('livewire.company-index', ['companies' => $companies]);
    }
}
```

- [ ] **Step 4: Create the view**

Replace `resources/views/livewire/company-index.blade.php` contents with:
```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Companies</h1>

    @if ($companies->isEmpty())
        <p class="text-gray-500">No companies to show.</p>
    @else
        <ul class="divide-y divide-gray-200">
            @foreach ($companies as $company)
                <li class="py-2">{{ $company->name }}</li>
            @endforeach
        </ul>
    @endif
</div>
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, add the import:
```php
use App\Livewire\CompanyIndex;
```
Add inside the existing `Route::middleware(['auth'])->group(function () { ... })` block (Breeze already created this group):
```php
Route::get('/companies', CompanyIndex::class)->name('companies.index');
```

- [ ] **Step 6: Run test to verify it passes**

Run:
```bash
php artisan test --filter=CompanyIndexTest
```
Expected: PASS, all 4 tests.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
Add Companies Livewire page

First real screen in the app. Proves the tenancy model end-to-end:
each role sees exactly the companies the CompanyPolicy allows.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Demo data seeder for manual local verification

**Files:**
- Create: `database/seeders/DemoDataSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/DemoDataSeederTest.php`

**Interfaces:**
- Consumes: `RoleSeeder` (Task 4), `Company`/`User` factories (Tasks 5, 6).
- Produces: one seeded admin, one accountant assigned to 2 companies, one client tied to 1 company, all with a fixed password for manual login testing — not for production use.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DemoDataSeederTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_one_user_per_role_with_correct_scoping(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(DemoDataSeeder::class);

        $admin = User::where('email', 'admin@tami.test')->first();
        $accountant = User::where('email', 'accountant@tami.test')->first();
        $client = User::where('email', 'client@tami.test')->first();

        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasRole('admin'));

        $this->assertNotNull($accountant);
        $this->assertTrue($accountant->hasRole('accountant'));
        $this->assertCount(2, $accountant->assignedCompanies);

        $this->assertNotNull($client);
        $this->assertTrue($client->hasRole('client'));
        $this->assertNotNull($client->company_id);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run:
```bash
php artisan test --filter=DemoDataSeederTest
```
Expected: FAIL — `Class "Database\Seeders\DemoDataSeeder" not found`.

- [ ] **Step 3: Create the seeder**

Create `database/seeders/DemoDataSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $companyA = Company::factory()->create(['name' => 'Demo Firm Alpha DOO']);
        $companyB = Company::factory()->create(['name' => 'Demo Firm Beta DOO']);

        $admin = User::factory()->create([
            'name' => 'Demo Admin',
            'email' => 'admin@tami.test',
            'password' => bcrypt('password'),
        ]);
        $admin->assignRole('admin');

        $accountant = User::factory()->create([
            'name' => 'Demo Accountant',
            'email' => 'accountant@tami.test',
            'password' => bcrypt('password'),
        ]);
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach([$companyA->id, $companyB->id]);

        $client = User::factory()->create([
            'name' => 'Demo Client',
            'email' => 'client@tami.test',
            'password' => bcrypt('password'),
            'company_id' => $companyA->id,
        ]);
        $client->assignRole('client');
    }
}
```

- [ ] **Step 4: Wire it into the main DatabaseSeeder**

In `database/seeders/DatabaseSeeder.php`, replace the `run()` method with:
```php
public function run(): void
{
    $this->call([
        RoleSeeder::class,
        DemoDataSeeder::class,
    ]);
}
```

- [ ] **Step 5: Run test to verify it passes**

Run:
```bash
php artisan test --filter=DemoDataSeederTest
```
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
Add demo data seeder for local manual verification

One admin, one accountant (2 assigned companies), one client
(1 own company) — for logging in locally and eyeballing the
Companies page per role.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: Full suite verification and manual walkthrough

**Files:** None (verification only).

**Interfaces:** None — this task confirms everything from Tasks 1-9 works together.

- [ ] **Step 1: Run the complete automated test suite**

Run:
```bash
php artisan test
```
Expected: all tests pass (no failures), covering roles, companies, tenancy relations, the policy, and the Companies page.

- [ ] **Step 1b: Confirm error logging satisfies the design's requirement (no code needed)**

Run:
```bash
grep -A3 "'default' =>" config/logging.php
tail -5 storage/logs/laravel.log 2>/dev/null || echo "no log file yet — that's fine, none have occurred"
```
Expected: `'default' => env('LOG_CHANNEL', 'stack')`, confirming Laravel's built-in log-file channel is active out of the box — this alone satisfies the design's "Error Logging: standard Laravel log files, no external service" requirement. No task/code was needed for this.

- [ ] **Step 2: Seed the database with demo data**

Run:
```bash
php artisan migrate:fresh --seed
```
Expected: migrations run clean, seeders complete with no errors.

- [ ] **Step 3: Start the local dev server**

Run:
```bash
php artisan serve
```
Expected: `Server running on [http://127.0.0.1:8000]`.

- [ ] **Step 4: Manual browser verification (for the human reviewer, not an agentic worker)**

Open `http://127.0.0.1:8000/login` in a browser and log in as each demo user (password `password` for all three):
- `admin@tami.test` → visiting `/companies` should show **both** "Demo Firm Alpha DOO" and "Demo Firm Beta DOO".
- `accountant@tami.test` → same page should show **both** companies (assigned to both).
- `client@tami.test` → same page should show **only** "Demo Firm Alpha DOO".

- [ ] **Step 5: Stop the dev server and commit a final marker**

Press `Ctrl+C` to stop `php artisan serve`, then:
```bash
git log --oneline
```
Expected: a clean, readable commit history from Task 2 through Task 9. No further commit needed for this task — it's verification-only.

---

## What's Next

Phase 0b (separate plan) covers: Google Drive service-account integration, the nightly database backup job, switching production config to MySQL, and setting up GitHub Actions to auto-deploy to the droplet. That plan should only be written after this one is executed and verified.
