# Видови профили и овластувања — план за изведба

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Замени ја единствената улога `client` со `internal_client` (правно
лице) и `freelancer_client` (физичко лице), додади лимит на бројот фирми по
сметководител, и отвори читачки пристап за `internal_client` до платопис,
ДДВ-04, бруто биланс, аналитичка картица и изводи на сопствената фирма.

**Architecture:** Spatie-улогата `client` се преименува на ниво на база
(миграција, не само seeder), нов `App\Support\CompanyType::clientRole()`
станува единствениот извор за „која улога добива нов клиентски профил на овој
тип фирма", `User::isClient()` заменува секое место што денес прашува
`hasRole('client')`. Пристапот до платата/извештаите се отвора со отстранување
на `EnsureAccountingAccess` од читачките рути (Livewire-компонентите и
контролерите таму веќе сами прашуваат `Gate::authorize('view', $company)`) и
со нова експлицитна `CompanyPolicy::managePayroll()` заштита на секое дејство
што пишува во плата.

**Tech Stack:** Laravel 13, Livewire 3, Spatie/laravel-permission, PHPUnit,
SQLite (тестови/локално) / MySQL (CI/продукција).

## Global Constraints

- Секоја задача завршува со зелен `php -d memory_limit=1G vendor/bin/phpunit`
  на најмалку засегнатиот дел (конкретен фајл/директориум); целиот пакет се
  пушта еднаш, на крајот, пред спојување (~45 минути, `composer test` паѓа на
  сопствен лимит од 300s и враќа лажен exit 0 — никогаш не се користи за
  вистинска проверка).
- Секој нов/сменет PHP фајл останува UTF-8 БЕЗ BOM — BOM пред `<?php` руши сè.
  Механичките замени во Задача 2 одат преку `sed` (git bash), не преку
  PowerShell `Set-Content`.
- По секоја измена во Blade оди `npm run build` пред рачна проверка во
  прелистувач (не е потребно за тестовите — PHPUnit не чита компилиран CSS).
- Секој комит завршува со `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`.
- Ниту едно скенирано „наскоро" однесување не се менува во оваа фаза (е-ПДД
  функционалноста, е-Фактура за internal_client — вон опсег, види ја
  спецификацијата).

---

## Task 1: Улогите на ниво на база — преименување + нова улога

**Files:**
- Create: `database/migrations/2026_09_23_120000_rename_client_role_and_add_freelancer_client_role.php`
- Modify: `database/seeders/RoleSeeder.php`
- Test: `tests/Feature/RoleSeederTest.php`
- Test: `tests/Unit/Migrations/RenameClientRoleMigrationTest.php`

**Interfaces:**
- Produces: Spatie-улоги `admin`, `accountant`, `internal_client`,
  `freelancer_client` (улогата `client` веќе не постои по оваа задача).

- [ ] **Step 1: Напиши ја миграцијата**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // UPDATE, не delete+insert — секој веќе доделен корисник е поврзан
        // преку role_id во model_has_roles, не преку името. Ова е единствениот
        // начин преименувањето да не бара миграција на луѓе.
        $guard = DB::table('roles')->where('name', 'admin')->value('guard_name') ?? 'web';

        DB::table('roles')
            ->where('name', 'client')
            ->update(['name' => 'internal_client', 'updated_at' => now()]);

        if (! DB::table('roles')->where('name', 'freelancer_client')->exists()) {
            DB::table('roles')->insert([
                'name' => 'freelancer_client',
                'guard_name' => $guard,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('roles')->where('name', 'internal_client')->update(['name' => 'client', 'updated_at' => now()]);
        DB::table('roles')->where('name', 'freelancer_client')->delete();
    }
};
```

- [ ] **Step 2: Ажурирај го RoleSeeder**

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['admin', 'accountant', 'internal_client', 'freelancer_client'] as $role) {
            Role::findOrCreate($role);
        }
    }
}
```

- [ ] **Step 3: Ажурирај го постојниот RoleSeederTest**

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

    public function test_seeder_creates_the_four_core_roles(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertTrue(Role::where('name', 'admin')->exists());
        $this->assertTrue(Role::where('name', 'accountant')->exists());
        $this->assertTrue(Role::where('name', 'internal_client')->exists());
        $this->assertTrue(Role::where('name', 'freelancer_client')->exists());
        $this->assertFalse(Role::where('name', 'client')->exists());
    }
}
```

- [ ] **Step 4: Напиши тест за самата миграција (докажува дека постоечки корисник го преживува преименувањето)**

```php
<?php

namespace Tests\Unit\Migrations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RenameClientRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * RefreshDatabase веќе ги пушти сите миграции (вклучувајќи ја оваа) на
     * празна база, па 'client' не постои за да се преименува таму. За да се
     * докаже дека самата UPDATE-логика работи врз ВИСТИНСКИ ред (како на
     * продукција), тестот рачно го враќа редот во старата состојба и повторно
     * ја повикува migration-класата.
     */
    public function test_a_user_on_the_old_client_role_keeps_their_role_through_the_rename(): void
    {
        DB::table('roles')->where('name', 'internal_client')->update(['name' => 'client']);
        DB::table('roles')->where('name', 'freelancer_client')->delete();

        $user = User::factory()->create();
        $user->assignRole('client');
        $this->assertTrue($user->fresh()->hasRole('client'));

        $migration = require database_path('migrations/2026_09_23_120000_rename_client_role_and_add_freelancer_client_role.php');
        $migration->up();

        // Мора да прочита свежо: Spatie ги кешира улогите на моделот во меморија.
        $fresh = User::find($user->id);
        $this->assertTrue($fresh->hasRole('internal_client'));
        $this->assertFalse($fresh->hasRole('client'));
        $this->assertTrue(Role::where('name', 'freelancer_client')->exists());
    }
}
```

- [ ] **Step 5: Пушти го само овој дел**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/RoleSeederTest.php tests/Unit/Migrations/RenameClientRoleMigrationTest.php`
Expected: PASS (3 tests)

- [ ] **Step 6: Комит**

```bash
git add database/migrations/2026_09_23_120000_rename_client_role_and_add_freelancer_client_role.php database/seeders/RoleSeeder.php tests/Feature/RoleSeederTest.php tests/Unit/Migrations/RenameClientRoleMigrationTest.php
git commit -m "$(cat <<'EOF'
Преименувај ја улогата client во internal_client, додади freelancer_client

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Механичка замена низ кодот + двата вида политики

Сите места што денес пишуваат `'client'` (буквален Spatie-стринг) се менуваат
во еден чекор преку `sed` — 79 тест-фајлови плус 10 фајлови во `app/` содржат
буквално и единствено токенот `'client'` (потврдено претходно со grep -o:
нема `'client_id'` или слично, секој погодок е точно тие девет знаци).

**Files:**
- Modify: сите `.php` под `app/`, `database/`, `tests/` што содржат `'client'`
- Modify: `app/Policies/{Form743,Employee,Item,Partner,PurchaseInvoice,SalesInvoice,StockMovement,Warehouse}Policy.php` (дополнителна рачна поправка по sed-от)
- Modify: `tests/Feature/Bank/Form743UploadTest.php`, `tests/Feature/Bank/Form743WorklistTest.php` (дополнителна рачна поправка по sed-от)

**Interfaces:**
- Consumes: улогите од Задача 1 (`internal_client`, `freelancer_client`).

- [ ] **Step 1: Механичка замена**

```bash
grep -rl "'client'" app database tests --include="*.php" | xargs sed -i "s/'client'/'internal_client'/g"
```

- [ ] **Step 2: Провери дека ништо не остана**

Run: `grep -rn "'client'" app database tests --include="*.php"`
Expected: без резултат (празно)

- [ ] **Step 3: Прошири ги осумте политики што прашуваат „било кој клиент"**

Овие политики порано пропуштаа секаков клиент (правно и физичко лице
делеа една улога); по sed-от од Step 1 пропуштаат само `internal_client` —
freelancer_client треба да остане исто толку пропуштен колку што беше
`client` порано (самата рута/модул одлучува дали физичко лице воопшто стигнува
до таму, политиката намерно останува широка).

```bash
sed -i "s/\['admin', 'accountant', 'internal_client'\]/['admin', 'accountant', 'internal_client', 'freelancer_client']/g" \
  app/Policies/Form743Policy.php \
  app/Policies/EmployeePolicy.php \
  app/Policies/ItemPolicy.php \
  app/Policies/PartnerPolicy.php \
  app/Policies/PurchaseInvoicePolicy.php \
  app/Policies/SalesInvoicePolicy.php \
  app/Policies/StockMovementPolicy.php \
  app/Policies/WarehousePolicy.php
```

- [ ] **Step 4: Провери ги двете поправки со око**

Run: `grep -n "hasAnyRole" app/Policies/Form743Policy.php app/Policies/EmployeePolicy.php app/Policies/ItemPolicy.php app/Policies/PartnerPolicy.php app/Policies/PurchaseInvoicePolicy.php app/Policies/SalesInvoicePolicy.php app/Policies/StockMovementPolicy.php app/Policies/WarehousePolicy.php`
Expected: секој ред содржи `['admin', 'accountant', 'internal_client', 'freelancer_client']`

- [ ] **Step 5: Врати ги двата 743-тест-фајла на семантички точната улога**

Овие два фајла тестираат физичко лице (странство) — по sed-от од Step 1 добија
`internal_client`, а логички им треба `freelancer_client` (функционално веќе
работеше и со internal_client бидејќи Form743Policy е широка, но улогата на
тест-профилот треба да одговара на она што навистина го тестира).

`tests/Feature/Bank/Form743UploadTest.php`, во методот `clientOf()`:

```php
    private function clientOf(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('freelancer_client');

        return $user;
    }
```

`tests/Feature/Bank/Form743WorklistTest.php`, редот со `$client->assignRole(...)`:

```php
        $client->assignRole('freelancer_client');
```

- [ ] **Step 6: Пушти го целиот пакет**

Run: `php -d memory_limit=1G vendor/bin/phpunit`
Expected: PASS, ист број тестови како пред задачава (сите постоечки тврдења
проверуваат однесување, не име на улога, па преименувањето само по себе не
менува ниту едно очекување)

- [ ] **Step 7: Комит**

```bash
git add -A
git commit -m "$(cat <<'EOF'
Пренеси го целиот код на internal_client/freelancer_client

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Улога ⇄ тип на фирма (заклучена врска) + User::isClient()

**Files:**
- Modify: `app/Support/CompanyType.php`
- Modify: `app/Models/User.php`
- Modify: `app/Livewire/CompanyUsers.php`
- Test: `tests/Unit/CompanyTypeTest.php`
- Test: `tests/Feature/Users/CompanyUsersTest.php`
- Test: `tests/Feature/UserCompanyRelationsTest.php`

**Interfaces:**
- Produces: `CompanyType::clientRole(): string`, `User::isClient(): bool`.
- Consumes: улогите `internal_client`/`freelancer_client` (Задача 1/2).

- [ ] **Step 1: Failing test за CompanyType::clientRole()**

Додади во `tests/Unit/CompanyTypeTest.php`:

```php
    public function test_legal_maps_to_internal_client(): void
    {
        $this->assertSame('internal_client', CompanyType::LEGAL->clientRole());
    }

    public function test_individual_maps_to_freelancer_client(): void
    {
        $this->assertSame('freelancer_client', CompanyType::INDIVIDUAL->clientRole());
    }
```

(Провери го постојниот `use` блок на фајлот — веројатно веќе увезува
`App\Support\CompanyType`; ако тестот нема класа `CompanyTypeTest` со тие
методи, додади ги во истиот стил како постојните `test_*` методи таму.)

- [ ] **Step 2: Пушти го — очекувано FAIL**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Unit/CompanyTypeTest.php`
Expected: FAIL со „Call to undefined method clientRole()"

- [ ] **Step 3: Додади го методот во CompanyType**

```php
    /**
     * Улогата што ја добива секој нов клиентски профил на фирма од овој тип.
     * Заклучена врска — нема начин низ UI да се додели спротивното.
     */
    public function clientRole(): string
    {
        return match ($this) {
            self::LEGAL => 'internal_client',
            self::INDIVIDUAL => 'freelancer_client',
        };
    }
```

- [ ] **Step 4: Пушти — очекувано PASS**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Unit/CompanyTypeTest.php`
Expected: PASS

- [ ] **Step 5: Failing test за User::isClient()**

Додади во `tests/Feature/UserCompanyRelationsTest.php` (или соодветниот
Unit-тест за `User`, ако постои посебен):

```php
    public function test_is_client_is_true_for_both_client_roles(): void
    {
        $internal = User::factory()->create();
        $internal->assignRole('internal_client');
        $freelancer = User::factory()->create();
        $freelancer->assignRole('freelancer_client');

        $this->assertTrue($internal->isClient());
        $this->assertTrue($freelancer->isClient());
    }

    public function test_is_client_is_false_for_office_roles(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');

        $this->assertFalse($admin->isClient());
        $this->assertFalse($accountant->isClient());
    }
```

Провери дека `setUp()` на тој фајл создава ги четирите улоги (по Задача 1);
ако користи `Role::findOrCreate('client')`, sed-от од Задача 2 веќе го смени
тоа — додади `Role::findOrCreate('freelancer_client')` ако недостасува.

- [ ] **Step 6: Пушти — очекувано FAIL**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/UserCompanyRelationsTest.php`
Expected: FAIL со „Call to undefined method isClient()"

- [ ] **Step 7: Додади го методот во User и употреби го во visibleCompanies()**

```php
    public function isClient(): bool
    {
        return $this->hasAnyRole(['internal_client', 'freelancer_client']);
    }
```

Во `visibleCompanies()`, замени:

```php
        if ($this->hasRole('client')) {
```

со:

```php
        if ($this->isClient()) {
```

(По Задача 2 тој ред веќе гласи `hasRole('internal_client')` — ова е точката
каде реално треба да се спореди со `isClient()` за да не изгуби freelancer_client.)

- [ ] **Step 8: Пушти — очекувано PASS**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/UserCompanyRelationsTest.php tests/Feature/CompanyPolicyTest.php`
Expected: PASS

- [ ] **Step 9: Failing test за автоматско доделување улога по тип на фирма**

Во `tests/Feature/Users/CompanyUsersTest.php`, додади:

```php
    public function test_a_new_user_on_a_legal_company_gets_internal_client(): void
    {
        $company = Company::factory()->create(); // default type = LEGAL
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(CompanyUsers::class, ['company' => $company])
            ->set('newName', 'Марија Петровска')
            ->set('newEmail', 'marija@primer.mk')
            ->call('addUser')
            ->assertHasNoErrors();

        $created = User::where('email', 'marija@primer.mk')->firstOrFail();
        $this->assertTrue($created->hasRole('internal_client'));
        $this->assertFalse($created->hasRole('freelancer_client'));
    }

    public function test_a_new_user_on_an_individual_company_gets_freelancer_client(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(CompanyUsers::class, ['company' => $company])
            ->set('newName', 'Иван Стоилков')
            ->set('newEmail', 'ivan@primer.mk')
            ->call('addUser')
            ->assertHasNoErrors();

        $created = User::where('email', 'ivan@primer.mk')->firstOrFail();
        $this->assertTrue($created->hasRole('freelancer_client'));
        $this->assertFalse($created->hasRole('internal_client'));
    }
```

Додади `use App\Support\CompanyType;` на врвот ако недостасува.

- [ ] **Step 10: Пушти — очекувано FAIL (или лажен PASS — провери го второто тврдење)**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Users/CompanyUsersTest.php --filter test_a_new_user_on_an_individual_company_gets_freelancer_client`
Expected: FAIL — `assertTrue($created->hasRole('freelancer_client'))` пропаѓа, бидејќи `addUser()` сè уште тврдо доделува `internal_client`.

- [ ] **Step 11: Поправи го CompanyUsers::addUser()**

Во `app/Livewire/CompanyUsers.php`, замени:

```php
        $user->assignRole('internal_client');
```

со:

```php
        $user->assignRole($this->company->type->clientRole());
```

- [ ] **Step 12: Пушти — очекувано PASS**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Users/CompanyUsersTest.php`
Expected: PASS

- [ ] **Step 13: Поправи го MenuTest::userWithRole() — денес препознава само `'client'`**

`tests/Unit/Support/MenuTest.php` има хелпер:

```php
    private function userWithRole(string $role, ?Company $company = null): User
    {
        $user = User::factory()->create($company && $role === 'client' ? ['company_id' => $company->id] : []);
        $user->assignRole($role);

        return $user;
    }
```

По Задача 2 условот гласи `$role === 'internal_client'` (sed го преименувал
буквалниот стринг) — што значи повик со `'freelancer_client'` НЕ би го
поставил `company_id`, и секој иден freelancer_client-тест во овој фајл би
паднал на празна фирма. Поправи го условот да ги препознае двете:

```php
    private function userWithRole(string $role, ?Company $company = null): User
    {
        $isClientRole = in_array($role, ['internal_client', 'freelancer_client'], true);
        $user = User::factory()->create($company && $isClientRole ? ['company_id' => $company->id] : []);
        $user->assignRole($role);

        return $user;
    }
```

Додади и еден регресивен тест, доказ дека freelancer_client го гледа истото
дрво какво што го гледаше `client` порано на индивидуална фирма:

```php
    public function test_a_freelancer_client_sees_the_individual_tree_unchanged(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
        $freelancer = $this->userWithRole('freelancer_client', $company);

        $this->assertSame(['Излезни фактури', 'Кооперанти'], $this->itemLabels(Menu::for($freelancer, $company, PortalApp::PRODAZBA), 'sales'));
        $this->assertSame(['743 обрасци'], $this->itemLabels(Menu::for($freelancer, $company, PortalApp::FINANSII), 'bank'));
    }
```

- [ ] **Step 14: Пушти — очекувано PASS**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Unit/Support/MenuTest.php`
Expected: PASS

- [ ] **Step 15: Комит**

```bash
git add app/Support/CompanyType.php app/Models/User.php app/Livewire/CompanyUsers.php tests/Unit/CompanyTypeTest.php tests/Feature/UserCompanyRelationsTest.php tests/Feature/Users/CompanyUsersTest.php tests/Unit/Support/MenuTest.php
git commit -m "$(cat <<'EOF'
Заклучи ја улогата на нов клиентски профил на типот на фирмата

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Лимит на фирми по сметководител

**Files:**
- Create: `database/migrations/2026_09_23_130000_add_company_limit_to_users_table.php`
- Modify: `app/Policies/CompanyPolicy.php`
- Modify: `app/Livewire/OfficeUsers.php`
- Modify: `resources/views/livewire/office-users.blade.php`
- Test: `tests/Feature/CompanyPolicyTest.php`
- Test: `tests/Feature/Users/OfficeUsersTest.php`

**Interfaces:**
- Produces: `users.company_limit` (nullable int), `OfficeUsers::updateCompanyLimit(int $userId, string $limit): void`.

- [ ] **Step 1: Миграција**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // null = неограничено. Секој постоен сметководител останува
            // неограничен додека админ изречно не му постави број.
            $table->unsignedInteger('company_limit')->nullable()->after('disabled_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('company_limit');
        });
    }
};
```

- [ ] **Step 2: Failing test — лимитот го блокира create()**

Додади во `tests/Feature/CompanyPolicyTest.php`:

```php
    public function test_an_accountant_at_their_company_limit_may_not_create_another(): void
    {
        $accountant = User::factory()->create(['company_limit' => 1]);
        $accountant->assignRole('accountant');
        Company::factory()->create()->accountants()->attach($accountant);

        $this->assertFalse($accountant->can('create', Company::class));
    }

    public function test_an_accountant_under_their_company_limit_may_still_create(): void
    {
        $accountant = User::factory()->create(['company_limit' => 2]);
        $accountant->assignRole('accountant');
        Company::factory()->create()->accountants()->attach($accountant);

        $this->assertTrue($accountant->can('create', Company::class));
    }

    public function test_a_null_company_limit_means_unlimited(): void
    {
        $accountant = User::factory()->create(['company_limit' => null]);
        $accountant->assignRole('accountant');
        Company::factory()->count(5)->create()->each(
            fn (Company $c) => $c->accountants()->attach($accountant)
        );

        $this->assertTrue($accountant->can('create', Company::class));
    }
```

- [ ] **Step 3: Пушти — очекувано FAIL**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/CompanyPolicyTest.php --filter company_limit`
Expected: FAIL на првиот тест (лимитот сè уште не постои/не се проверува)

- [ ] **Step 4: Прошири го CompanyPolicy::create()**

```php
    public function create(User $user): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        if (! $user->hasRole('accountant')) {
            return false;
        }

        if ($user->company_limit === null) {
            return true;
        }

        return $user->assignedCompanies()->count() < $user->company_limit;
    }
```

- [ ] **Step 5: Пушти — очекувано PASS**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/CompanyPolicyTest.php`
Expected: PASS (сите, вклучувајќи ги постојните — лимитот null не смее да
скрши ниту едно постојно тврдење)

- [ ] **Step 6: Failing test — само админ го менува лимитот**

Додади во `tests/Feature/Users/OfficeUsersTest.php`:

```php
    public function test_an_admin_sets_a_companys_limit_on_an_accountant(): void
    {
        $accountant = $this->userWithRole('accountant');

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(OfficeUsers::class)
            ->call('updateCompanyLimit', $accountant->id, '3')
            ->assertHasNoErrors();

        $this->assertSame(3, $accountant->fresh()->company_limit);
    }

    public function test_an_empty_value_clears_the_limit_to_unlimited(): void
    {
        $accountant = $this->userWithRole('accountant');
        $accountant->forceFill(['company_limit' => 2])->save();

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(OfficeUsers::class)
            ->call('updateCompanyLimit', $accountant->id, '');

        $this->assertNull($accountant->fresh()->company_limit);
    }

    public function test_an_accountant_cannot_set_their_own_limit(): void
    {
        $accountant = $this->userWithRole('accountant');

        Livewire::actingAs($accountant)
            ->test(OfficeUsers::class)
            ->call('updateCompanyLimit', $accountant->id, '5')
            ->assertForbidden();
    }
```

- [ ] **Step 7: Пушти — очекувано FAIL**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Users/OfficeUsersTest.php --filter company_limit`
Expected: FAIL со „Method updateCompanyLimit does not exist"

- [ ] **Step 8: Додади ја методата во OfficeUsers**

```php
    public function updateCompanyLimit(int $userId, string $limit): void
    {
        abort_unless(auth()->user()->hasRole('admin'), 403);

        $user = $this->officeUser($userId);

        abort_unless($user->hasRole('accountant'), 403);

        $trimmed = trim($limit);
        $value = $trimmed === '' ? null : max(0, (int) $trimmed);

        $user->forceFill(['company_limit' => $value])->save();
    }
```

- [ ] **Step 9: Пушти — очекувано PASS**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Users/OfficeUsersTest.php`
Expected: PASS

- [ ] **Step 10: Додади ја колоната во Blade**

Во `resources/views/livewire/office-users.blade.php`, во `<thead>` по „Улога":

```blade
                    <th class="py-1">Лимит на фирми</th>
```

Во `<tbody>`, веднаш по ќелијата со `{{ \App\Livewire\OfficeUsers::ROLES[...] }}`:

```blade
                        <td class="py-1">
                            @if ($user->hasRole('accountant'))
                                <input type="number" min="0"
                                       wire:change="updateCompanyLimit({{ $user->id }}, $event.target.value)"
                                       value="{{ $user->company_limit }}"
                                       placeholder="неограничено"
                                       class="w-24 border-gray-300 rounded-md text-sm">
                            @endif
                        </td>
```

- [ ] **Step 11: npm run build**

Run: `npm run build`
Expected: успешно градење

- [ ] **Step 12: Целосен пакет**

Run: `php -d memory_limit=1G vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 13: Комит**

```bash
git add database/migrations/2026_09_23_130000_add_company_limit_to_users_table.php app/Policies/CompanyPolicy.php app/Livewire/OfficeUsers.php resources/views/livewire/office-users.blade.php tests/Feature/CompanyPolicyTest.php tests/Feature/Users/OfficeUsersTest.php
git commit -m "$(cat <<'EOF'
Додади лимит на бројот фирми по сметководител

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: internal_client — читачки пристап до платата

`PayrollRunIndex`/`PayrollRunShow` денес се затворени со рутна middleware
`EnsureAccountingAccess`, регистрирана и како Livewire persistent middleware
(`AppServiceProvider::boot()`) — таа автоматски се повторува на СЕКОЕ Livewire
дејство на компонента чија почетна рута ја носи. Отстранувањето од рутата
значи дека `createRun()`/`saveLine()`/`deleteLine()`/`confirm()`/
`returnToDraft()` веќе не се штитени НИКАКО (тие денес прашуваат само
`Gate::authorize('view', $company)`, кое по Задача 3 пропушта и
internal_client) — затоа секое од тие пет дејства бара НОВА, експлицитна
заштита однатре.

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Policies/CompanyPolicy.php`
- Modify: `app/Livewire/Payroll/PayrollRunIndex.php`
- Modify: `app/Livewire/Payroll/PayrollRunShow.php`
- Modify: `app/Support/Menu.php`
- Test: `tests/Feature/Payroll/PayrollAccessTest.php`
- Test: `tests/Unit/Support/MenuTest.php`

**Interfaces:**
- Produces: `CompanyPolicy::managePayroll(User $user, Company $company): bool`.
- Consumes: `User::isClient()` (Задача 3, преку `visibleCompanies()`).

- [ ] **Step 1: Failing test — internal_client гледа читачки**

Додади во `tests/Feature/Payroll/PayrollAccessTest.php`:

```php
    private function internalClient(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('internal_client');

        return $user;
    }

    public function test_an_internal_client_can_view_their_own_companys_payroll_runs(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->internalClient($company))
            ->get(route('payroll-runs.index', $company))
            ->assertOk();
    }

    public function test_an_internal_client_cannot_view_another_companys_payroll_runs(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->internalClient(Company::factory()->create()))
            ->get(route('payroll-runs.index', $company))
            ->assertForbidden();
    }

    public function test_an_internal_client_cannot_create_a_payroll_run(): void
    {
        $company = Company::factory()->create();

        Livewire::actingAs($this->internalClient($company))
            ->test(PayrollRunIndex::class, ['company' => $company])
            ->set('newMonth', 7)
            ->call('createRun')
            ->assertForbidden();
    }

    public function test_an_internal_client_cannot_confirm_a_payroll_run(): void
    {
        $company = Company::factory()->create();
        PayrollMonthHours::firstOrCreate(['year' => 2026, 'month' => 7], ['hours' => 184]);
        PayrollParameter::forDate('2026-07-31');
        $employee = Employee::factory()->for($company)->create([
            'employed_on' => '2026-01-01', 'prior_service_months' => 0,
        ]);
        EmployeeSalary::create([
            'employee_id' => $employee->id, 'effective_from' => '2026-01-01',
            'amount' => 38507, 'basis' => 'gross',
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        $run = app(PayrollRunService::class)->open($company, 2026, 7);

        Livewire::actingAs($this->internalClient($company))
            ->test(PayrollRunShow::class, ['company' => $company, 'run' => $run])
            ->call('confirm')
            ->assertForbidden();

        $this->assertSame('draft', $run->fresh()->status);
    }
```

Додади ги потребните `use` (`App\Livewire\Payroll\PayrollRunIndex`,
`App\Livewire\Payroll\PayrollRunShow`, `Livewire\Livewire`) на врвот на
фајлот ако недостасуваат.

- [ ] **Step 2: Пушти — очекувано FAIL**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Payroll/PayrollAccessTest.php --filter internal_client`
Expected: FAIL на првиот тест (403 денес, зашто EnsureAccountingAccess сè уште ја затвора целата група за секој освен admin/accountant)

- [ ] **Step 3: Додади ја managePayroll во CompanyPolicy**

```php
    /**
     * Пресметување, уредување и потврдување плата — само канцеларијата.
     * internal_client гледа читачки преку `view` (visibleCompanies() веќе го
     * пропушта), но не смее да допре ниту едно дејство што пишува.
     */
    public function managePayroll(User $user, Company $company): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->hasRole('accountant')
            && $user->visibleCompanies()->whereKey($company->id)->exists();
    }
```

- [ ] **Step 4: Раздели ги payroll-рутите во routes/web.php**

Замени го постојниот блок (двете `EnsureAccountingAccess`-групи за `payroll.`
и `payroll-runs.`) со:

```php
    // Читачки: PDF-от веќе прашува Gate::authorize('view', $company) внатре,
    // internal_client го гледа сопствениот платопис/рекапитулар. Мора да
    // остане пред payroll-runs. подолу — /payroll-runs/{run}/recap.pdf не
    // смее да се проголта од /payroll-runs/{run}.
    Route::middleware(['auth', EnsureLegalEntity::class, EnsureCompanyModule::class.':payroll'])->prefix('companies/{company}')->name('payroll.')->group(function () {
        Route::get('/payroll-runs/{run}/recap.pdf', PayrollRecapPdfController::class)->name('recap-pdf');
        Route::get('/payroll-runs/{run}/payslip/{runEmployee}.pdf', PayslipPdfController::class)->name('payslip-pdf');
    });

    // МПИН извозот пишува во run (mpin_exported_at) и е канцелариска задача
    // — останува затворено.
    Route::middleware(['auth', EnsureAccountingAccess::class, EnsureLegalEntity::class, EnsureCompanyModule::class.':payroll'])->prefix('companies/{company}')->name('payroll.')->group(function () {
        Route::get('/payroll-runs/{run}/mpin.xml', MpinExportController::class)->name('mpin-export');
    });

    // Читачки: mount() веќе прашува Gate::authorize('view', $company).
    // Секое дејство што пишува (createRun, saveLine, deleteLine, confirm,
    // returnToDraft) си носи сопствена CompanyPolicy::managePayroll проверка
    // однатре — EnsureAccountingAccess веќе не се повторува на овие дејства
    // штом ја нема во почетната рута.
    Route::middleware(['auth', EnsureLegalEntity::class, EnsureCompanyModule::class.':payroll'])->prefix('companies/{company}')->name('payroll-runs.')->group(function () {
        Route::get('/payroll-runs', [PayrollRunIndex::class, '__invoke'])->name('index');
        Route::get('/payroll-runs/{run}', [PayrollRunShow::class, '__invoke'])->name('show');
    });
```

- [ ] **Step 5: Заштити ги петте дејства**

Во `app/Livewire/Payroll/PayrollRunIndex.php`, на почетокот на `createRun()`:

```php
    public function createRun(PayrollRunService $service): mixed
    {
        Gate::authorize('managePayroll', $this->company);

        $this->validate([
```

Во `app/Livewire/Payroll/PayrollRunShow.php`, замени го секое од четирите
постојни `Gate::authorize('view', $this->company);` во `saveLine()`,
`deleteLine()`, `confirm()`, `returnToDraft()` со:

```php
        Gate::authorize('managePayroll', $this->company);
```

(`mount()` останува непроменето — `Gate::authorize('view', $company)`.)

- [ ] **Step 6: Пушти — очекувано PASS**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Payroll/`
Expected: PASS (вклучувајќи ги постојните admin/accountant тестови — тие
поминуваат низ `managePayroll` исто како порано низ `view`)

- [ ] **Step 7: Отвори ја ставката во менито**

Во `app/Support/Menu.php`, во `legalTree()`, ставката „Плата (МПИН)":

```php
                    ['label' => 'Плата (МПИН)', 'url' => route('payroll-runs.index', $company), 'pattern' => 'payroll-runs.*', 'roles' => null, 'module' => CompanyModule::PAYROLL],
```

(само `'roles' => ['admin', 'accountant']` → `'roles' => null`, ништо друго
на редот не се менува)

- [ ] **Step 8: Ажурирај го MenuTest**

Во `tests/Unit/Support/MenuTest.php`,
`test_a_client_sees_the_payroll_group_with_only_the_built_item()` веќе
тврди дека клиент гледа само „Вработени" — сега мора да гледа и „Плата (МПИН)":

```php
    public function test_a_client_sees_the_payroll_group_with_only_the_built_item(): void
    {
        $company = Company::factory()->create();

        $this->assertSame(
            ['Вработени', 'Плата (МПИН)'],
            $this->itemLabels(Menu::for($this->userWithRole('internal_client', $company), $company, PortalApp::PLATA), 'payroll')
        );
        $this->assertSame(
            ['Вработени', 'Плата (МПИН)', 'е-ПДД'],
            $this->itemLabels(Menu::for($this->userWithRole('admin'), $company, PortalApp::PLATA), 'payroll')
        );
    }
```

(хелперот `userWithRole()` е веќе поправен во Задача 3, Step 13, да го
препознае `'internal_client'` заедно со `'freelancer_client'` — овој чекор
само го менува очекуваното тврдење.)

- [ ] **Step 9: Пушти — очекувано PASS**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Unit/Support/MenuTest.php`
Expected: PASS

- [ ] **Step 10: Комит**

```bash
git add routes/web.php app/Policies/CompanyPolicy.php app/Livewire/Payroll/PayrollRunIndex.php app/Livewire/Payroll/PayrollRunShow.php app/Support/Menu.php tests/Feature/Payroll/PayrollAccessTest.php tests/Unit/Support/MenuTest.php
git commit -m "$(cat <<'EOF'
Отвори читачки пристап до платата за internal_client

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: internal_client — ДДВ-04, бруто биланс, аналитичка картица

Трите екрана се чисто читачки (само `mount()`+`render()`, без ниту едно
дејство што пишува) — не им треба нов `managePayroll`-стил заштитник, само
отстранување на `EnsureAccountingAccess` од нивните рути. Групата
`accounting.` останува затворена за контен план/дневник/картички.

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Support/Menu.php`
- Test: `tests/Feature/AccountingAccessTest.php`
- Test: `tests/Unit/Support/MenuTest.php`

**Interfaces:**
- Consumes: `User::isClient()` преку `visibleCompanies()` (Задача 3).

- [ ] **Step 1: Failing test**

Додади во `tests/Feature/AccountingAccessTest.php`:

```php
    private function internalClient(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('internal_client');

        return $user;
    }

    public static function readOnlyReportRoutes(): array
    {
        return [
            'ddv04' => ['reports.ddv04'],
            'reports index' => ['reports.index'],
            'trial balance' => ['accounting.reports.trial-balance'],
            'ledger card' => ['accounting.reports.ledger-card'],
        ];
    }

    #[DataProvider('readOnlyReportRoutes')]
    public function test_an_internal_client_reaches_the_read_only_reports(string $routeName): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->internalClient($company))
            ->get(route($routeName, $company))
            ->assertOk();
    }

    public static function stillClosedAccountingRoutes(): array
    {
        return [
            'chart of accounts' => ['accounting.accounts.index'],
            'journal groups' => ['accounting.journal-groups.index'],
            'journal entries' => ['accounting.journal-entries.index'],
        ];
    }

    #[DataProvider('stillClosedAccountingRoutes')]
    public function test_an_internal_client_is_still_refused_the_books(string $routeName): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->internalClient($company))
            ->get(route($routeName, $company))
            ->assertForbidden();
    }

    public function test_an_internal_client_cannot_reach_another_companys_reports(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->internalClient(Company::factory()->create()))
            ->get(route('reports.ddv04', $company))
            ->assertForbidden();
    }
```

- [ ] **Step 2: Пушти — очекувано FAIL**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/AccountingAccessTest.php --filter internal_client`
Expected: FAIL на читачките тестови (сè уште 403, EnsureAccountingAccess е тука)

- [ ] **Step 3: Раздели ги рутите во routes/web.php**

Го наоѓаш блокот `Route::...->name('accounting.')->group(...)` со седумте рути
(accounts, journal-groups×2, journal-entries×3, journal-entries.pdf, потоа
reports.ledger-card, reports.trial-balance) и го делиш на два:

```php
    Route::middleware(['auth', EnsureAccountingAccess::class, EnsureLegalEntity::class, EnsureCompanyModule::class.':finance'])->prefix('companies/{company}')->name('accounting.')->group(function () {
        Route::get('/accounts', [AccountIndex::class, '__invoke'])->name('accounts.index');
        Route::get('/journal-groups', [JournalGroupIndex::class, '__invoke'])->name('journal-groups.index');
        Route::get('/journal-groups/{journalGroup}/entries', [JournalEntryIndex::class, '__invoke'])->name('journal-groups.entries');
        Route::get('/journal-entries', [JournalEntryIndex::class, '__invoke'])->name('journal-entries.index');
        Route::get('/journal-entries/create', [JournalEntryForm::class, '__invoke'])->name('journal-entries.create');
        Route::get('/journal-entries/{journalEntry}/edit', [JournalEntryForm::class, '__invoke'])->name('journal-entries.edit');
        Route::get('/journal-entries/{journalEntry}/pdf', [JournalEntryPdfController::class, '__invoke'])->name('journal-entries.pdf');
    });

    // Читачки извештаи: LedgerCardReport/TrialBalanceReport немаат ниту едно
    // дејство што пишува, само mount()+render(), веќе штитени со
    // Gate::authorize('view', $company). internal_client ги гледа сопствените.
    Route::middleware(['auth', EnsureLegalEntity::class, EnsureCompanyModule::class.':finance'])->prefix('companies/{company}')->name('accounting.')->group(function () {
        Route::get('/reports/ledger-card', [LedgerCardReport::class, '__invoke'])->name('reports.ledger-card');
        Route::get('/reports/trial-balance', [TrialBalanceReport::class, '__invoke'])->name('reports.trial-balance');
    });
```

Потоа во `reports.`-групата (ддв04 + report-index), тргни го
`EnsureAccountingAccess::class`:

```php
    Route::middleware(['auth', EnsureLegalEntity::class, EnsureCompanyModule::class.':finance'])->prefix('companies/{company}')->name('reports.')->group(function () {
        Route::get('/reports', [ReportIndex::class, '__invoke'])->name('index');
        Route::get('/reports/ddv04', [Ddv04Report::class, '__invoke'])->name('ddv04');
    });
```

- [ ] **Step 4: Поправи го постојниот data provider — тој сè уште тврди дека овие три се забранети**

`accountingRoutes()` во истиот фајл ги содржи `ledger card`, `trial balance` и
`ddv04` заедно со `chart of accounts`/`journal groups`/`journal entries` —
`test_a_client_is_refused_every_accounting_url` над него сега би паднал лажно
(бара 403 таму каде internal_client веќе смее 200). Извади ги трите:

```php
    public static function accountingRoutes(): array
    {
        return [
            'chart of accounts' => ['accounting.accounts.index'],
            'journal groups' => ['accounting.journal-groups.index'],
            'journal entries' => ['accounting.journal-entries.index'],
            'new journal entry' => ['accounting.journal-entries.create'],
        ];
    }
```

(`test_an_admin_still_reaches_every_accounting_url` и
`test_an_accountant_still_reaches_every_accounting_url` над истиот provider
остануваат точни — admin/accountant секогаш смееле сè, независно од оваа
поделба.)

- [ ] **Step 5: Пушти — очекувано PASS**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/AccountingAccessTest.php`
Expected: PASS (сите)

- [ ] **Step 6: Отвори ја ставката во менито**

Во `app/Support/Menu.php`, `legalTree()`, ставката „Извештаи и обрасци":

```php
                    ['label' => 'Извештаи и обрасци', 'url' => route('reports.index', $company), 'pattern' => 'reports.*', 'roles' => null, 'module' => CompanyModule::FINANCE],
```

(само `'roles' => ['admin', 'accountant']` → `'roles' => null`; „Главна книга"
и „Контен план" остануваат непроменети — сè уште `['admin', 'accountant']`)

- [ ] **Step 7: Ажурирај го MenuTest**

`test_an_admin_sees_the_full_finance_and_settings_items()` веќе тврди дека
само admin гледа „Извештаи и обрасци" преку групата — провери дали има
одделен тест за клиент во ФИНАНСИИ групата и додади:

```php
    public function test_an_internal_client_sees_reports_but_not_the_general_ledger(): void
    {
        $company = Company::factory()->create();
        $financeMenu = Menu::for($this->userWithRole('internal_client', $company), $company, PortalApp::FINANSII);

        $this->assertSame(['Извештаи и обрасци'], $this->itemLabels($financeMenu, 'finance'));
    }
```

- [ ] **Step 8: Пушти — очекувано PASS**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Unit/Support/MenuTest.php`
Expected: PASS

- [ ] **Step 9: Комит**

```bash
git add routes/web.php app/Support/Menu.php tests/Feature/AccountingAccessTest.php tests/Unit/Support/MenuTest.php
git commit -m "$(cat <<'EOF'
Отвори читачки пристап до ДДВ-04/бруто биланс/аналитичка картица за internal_client

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: internal_client — изводи (гледа + качува)

Рутата `bank-statements.` веќе НЕМА `EnsureAccountingAccess` (само
`EnsureLegalEntity` + `EnsureCompanyModule:finance`); `BankStatementIndex`
веќе прашува само `Gate::authorize('view', $company)` и за гледање и за
качување. Единствената пречка е менито — оваа задача е чисто менска измена.

**Files:**
- Modify: `app/Support/Menu.php`
- Test: `tests/Unit/Support/MenuTest.php`
- Test: `tests/Feature/Bank/BankStatementIndexTest.php`

**Interfaces:**
- Consumes: `User::isClient()` преку `visibleCompanies()` (Задача 3).

- [ ] **Step 1: Failing test — internal_client качува извод преку екранот**

Додади во `tests/Feature/Bank/BankStatementIndexTest.php` (провери го
постојниот setUp/helpers за улогата пред да копираш):

```php
    public function test_an_internal_client_can_upload_a_statement(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        Livewire::actingAs($client)
            ->test(BankStatementIndex::class, ['company' => $company])
            ->set('bank', 'Стопанска банка')
            ->set('account', 'MK07300000000001234')
            ->set('kind', BankStatementKind::DENAR->value)
            ->set('number', '1')
            ->set('statementDate', now()->toDateString())
            ->set('newFile', UploadedFile::fake()->create('izvod-1.pdf', 20))
            ->call('upload')
            ->assertHasNoErrors();

        $this->assertSame(1, BankStatement::where('company_id', $company->id)->count());
    }
```

- [ ] **Step 2: Пушти — очекувано PASS веднаш**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Bank/BankStatementIndexTest.php --filter internal_client`
Expected: PASS (рутата/компонентата веќе го дозволуваат ова — овој тест само
го докажува тоа изречно, ништо не се менува во `app/` за оваа задача)

- [ ] **Step 3: Отвори ја ставката во менито**

Во `app/Support/Menu.php`, `legalTree()`, ставката „Банкарски документи":

```php
                    ['label' => 'Банкарски документи', 'url' => route('bank-statements.index', $company), 'pattern' => 'bank-statements.*', 'roles' => null, 'module' => CompanyModule::FINANCE],
```

- [ ] **Step 4: Ажурирај го MenuTest**

Во `test_an_internal_client_sees_reports_but_not_the_general_ledger()` (од
Задача 6), ставката веќе е дел од истата ФИНАНСИИ група — прошири го
тврдењето:

```php
        $this->assertSame(['Извештаи и обрасци', 'Банкарски документи'], $this->itemLabels($financeMenu, 'finance'));
```

- [ ] **Step 5: Пушти — очекувано PASS**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Unit/Support/MenuTest.php tests/Feature/Bank/`
Expected: PASS

- [ ] **Step 6: Комит**

```bash
git add app/Support/Menu.php tests/Unit/Support/MenuTest.php tests/Feature/Bank/BankStatementIndexTest.php
git commit -m "$(cat <<'EOF'
Отвори ги изводите во менито за internal_client

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Целосна проверка + DemoDataSeeder

**Files:**
- Modify: `database/seeders/DemoDataSeeder.php` (веројатно веќе точен по sed-от од Задача 2 — провери)
- Test: целиот пакет

**Interfaces:** нема нови.

- [ ] **Step 1: Провери го DemoDataSeeder рачно**

Run: `grep -n "client\|Role\|assignRole" database/seeders/DemoDataSeeder.php`
Expected: линијата со демо-клиентот сега гласи
`$client->assignRole('internal_client');` (correct — демо клиентот е на
`companyA`, стандарден `CompanyType::LEGAL`). Ако сакаш и демо freelancer,
тоа е надвор од опсегот на оваа задача (сопственикот не побара демо податоци
за freelancer_client) — остави го seeder-от каков што е.

- [ ] **Step 2: Целосен пакет, еднаш**

Run: `php -d memory_limit=1G vendor/bin/phpunit`
Expected: PASS, секој тест — ова е единствениот целосен пуштен пакет во целава
задача; трае ~45 минути.

- [ ] **Step 3: npm run build (последен пат, ако Blade се менувал по последното градење)**

Run: `npm run build`
Expected: успешно

- [ ] **Step 4: Прегледај го целиот дифф пред спојување**

Run: `git diff main --stat`
Expected: листа од фајлови допрени низ сите осум задачи — рачна визуелна
проверка дека нема заборавен `'client'` остаток и дека сите миграции се
внесени.

- [ ] **Step 5: Финален комит (ако има преостанати измени)**

```bash
git add -A
git commit -m "$(cat <<'EOF'
Финална проверка: видови профили и овластувања

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

Оваа задача НЕ спојува во `main` и НЕ пушта — тоа е одлука на сопственикот,
по негово барање, откако ќе го прегледа целиот сет промени.
