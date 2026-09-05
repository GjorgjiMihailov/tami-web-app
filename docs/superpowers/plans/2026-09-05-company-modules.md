# Модули по клиент — план за изведба

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Админ штиклира кои модули (Материјално + подмодул Залиха, Плата, Финансии) ги користи една фирма, а исклучениот модул исчезнува од менито и од почетниот екран и враќа 403 на своите адреси.

**Architecture:** Четири `boolean` колони на `companies` со стандардна вредност `true`, придружен enum `App\Support\CompanyModule`, и една метода `Company::usesModule()` што ја чита целата логика (подмодулот Залиха, и исклучокот за физичко лице). Менито филтрира по ставка преку постојниот `Menu::itemVisible()`, а рутите ги брани ново middleware `EnsureCompanyModule` — огледало на постојното `EnsureLegalEntity`.

**Tech Stack:** Laravel, Livewire 3, Blade, Tailwind, PHPUnit, SQLite во тестови.

**Спецификација:** `docs/superpowers/specs/2026-09-05-company-modules-design.md`

## Global Constraints

- Целиот текст видлив за корисник е на **македонска кирилица**. Коментарите во кодот исто така, како во постојниот код.
- Ниту еден чекор **не брише податоци**. Исклучен модул само крие и брани.
- Стандардната вредност на сите четири колони е `true`, за да ниту една постоечка фирма не се смени при мигрирање.
- Физичко лице **никогаш** не се затвора со модул — `usesModule()` враќа `true` за секој модул кога типот е физичко лице.
- Работната гранка е `company-modules`, веќе создадена, со комитиран дизајн (`da76fd7`).
- **Додека трае работата се пушта само тест-фајлот што се допира** (~4 секунди). Целата пакет-серија се пушта **еднаш**, на крајот, и дури откако корисникот ќе каже. Никогаш не пуштај долга серија во позадина.
- Команда за еден фајл: `php artisan test tests/Feature/ИмеTest.php`

---

### Task 1: Колоните, enum-от и `usesModule()`

Основата. Сѐ понатаму ја чита оваа метода, па таа прва добива тестови.

**Files:**
- Create: `database/migrations/2026_09_05_100000_add_module_flags_to_companies_table.php`
- Create: `app/Support/CompanyModule.php`
- Modify: `app/Models/Company.php` (`$fillable`, `casts()`, нова метода)
- Test: `tests/Feature/CompanyModulesTest.php` (нов)

**Interfaces:**
- Produces:
  - `App\Support\CompanyModule` — enum со случаи `MATERIAL`, `STOCK`, `PAYROLL`, `FINANCE` (вредности `'material'`, `'stock'`, `'payroll'`, `'finance'`), методи `label(): string` и `column(): string`.
  - `App\Models\Company::usesModule(CompanyModule $module): bool`
  - Колони на `companies`: `uses_material`, `uses_stock`, `uses_payroll`, `uses_finance` — сите `boolean`, `default(true)`.

- [ ] **Step 1: Напиши го тестот што паѓа**

Create `tests/Feature/CompanyModulesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Support\CompanyModule;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_company_uses_every_module(): void
    {
        $company = Company::factory()->create();

        foreach (CompanyModule::cases() as $module) {
            $this->assertTrue(
                $company->usesModule($module),
                "Стандардно {$module->value} треба да е вклучен."
            );
        }
    }

    public function test_a_switched_off_module_reads_as_off(): void
    {
        $company = Company::factory()->create(['uses_payroll' => false]);

        $this->assertFalse($company->usesModule(CompanyModule::PAYROLL));
        $this->assertTrue($company->usesModule(CompanyModule::FINANCE));
    }

    public function test_stock_is_off_when_material_is_off_whatever_the_column_says(): void
    {
        // Редот е намерно противречен: Залиха вклучена, Материјално исклучено.
        // Таква состојба формата не прави, но рака во базата може.
        $company = Company::factory()->create([
            'uses_material' => false,
            'uses_stock' => true,
        ]);

        $this->assertFalse($company->usesModule(CompanyModule::STOCK));
        $this->assertFalse($company->usesModule(CompanyModule::MATERIAL));
    }

    public function test_an_individual_profile_is_never_closed_by_a_module(): void
    {
        // Модулите не важат за физичко лице — типот веќе одлучува што гледа.
        // Дури и ако колоните се исклучени, ниту еден екран не смее да падне.
        $company = Company::factory()->create([
            'type' => CompanyType::INDIVIDUAL,
            'uses_material' => false,
            'uses_stock' => false,
            'uses_payroll' => false,
            'uses_finance' => false,
        ]);

        foreach (CompanyModule::cases() as $module) {
            $this->assertTrue($company->usesModule($module));
        }
    }
}
```

- [ ] **Step 2: Пушти го тестот и потврди дека паѓа**

Run: `php artisan test tests/Feature/CompanyModulesTest.php`
Expected: FAIL — `Class "App\Support\CompanyModule" not found`

- [ ] **Step 3: Направи ја миграцијата**

Create `database/migrations/2026_09_05_100000_add_module_flags_to_companies_table.php`:

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
            // Стандардно вклучено, за да ниту еден постоечки профил не се
            // смени кога оваа миграција ќе помине. Изборот е нешто што админ
            // го прави свесно; стандардната вредност никогаш не смее да
            // затвори екран што вчера бил отворен.
            $table->boolean('uses_material')->default(true)->after('type');
            $table->boolean('uses_stock')->default(true)->after('uses_material');
            $table->boolean('uses_payroll')->default(true)->after('uses_stock');
            $table->boolean('uses_finance')->default(true)->after('uses_payroll');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['uses_material', 'uses_stock', 'uses_payroll', 'uses_finance']);
        });
    }
};
```

- [ ] **Step 4: Направи го enum-от**

Create `app/Support/CompanyModule.php`:

```php
<?php

namespace App\Support;

/**
 * Што користи оваа фирма од апликацијата.
 *
 * За разлика од `CompanyType`, кој се избира еднаш и не се менува, модулите се
 * менуваат: клиент вработува првиот работник во март и тогаш се вклучува Плата.
 *
 * Залиха е подмодул на Материјално — самата нема смисла без влезни и излезни
 * документи. Тоа правило живее во `Company::usesModule()`, не тука, зашто enum
 * не знае што пишува во редот.
 *
 * Модулите важат само за правно лице. Кај физичко лице `CompanyType` веќе
 * одлучува што се гледа.
 */
enum CompanyModule: string
{
    case MATERIAL = 'material';
    case STOCK = 'stock';
    case PAYROLL = 'payroll';
    case FINANCE = 'finance';

    public function label(): string
    {
        return match ($this) {
            self::MATERIAL => 'Материјално работење',
            self::STOCK => 'Залиха',
            self::PAYROLL => 'Плата',
            self::FINANCE => 'Финансии',
        };
    }

    /** Колоната на `companies` што го чува овој модул. */
    public function column(): string
    {
        return match ($this) {
            self::MATERIAL => 'uses_material',
            self::STOCK => 'uses_stock',
            self::PAYROLL => 'uses_payroll',
            self::FINANCE => 'uses_finance',
        };
    }
}
```

- [ ] **Step 5: Дополни го моделот**

Modify `app/Models/Company.php`:

Во `$fillable`, по `'type',` додај:

```php
        'uses_material', 'uses_stock', 'uses_payroll', 'uses_finance',
```

Во `casts()`, до `'is_vat_registered' => 'boolean',` додај:

```php
            'uses_material' => 'boolean',
            'uses_stock' => 'boolean',
            'uses_payroll' => 'boolean',
            'uses_finance' => 'boolean',
```

Додај го `use App\Support\CompanyModule;` кон увозите, и новата метода веднаш по `hasEfakturaAccess()`:

```php
    /**
     * Дали оваа фирма го користи модулот.
     *
     * Единственото место каде се чита состојбата на модул. Менито, плочките и
     * `EnsureCompanyModule` сите поминуваат оттука, за правилото за Залиха и
     * исклучокот за физичко лице да живеат само на едно место.
     */
    public function usesModule(CompanyModule $module): bool
    {
        // Модулите не важат за физичко лице. Типот веќе одлучува што гледа тој
        // профил, па колона со заостаната вредност не смее да му затвори екран
        // што типот му го дава. Истата грешка беше вистинска кај
        // `is_vat_registered`, каде стандардна вредност во базата го запиша
        // физичкото лице како ДДВ обврзник.
        if ($this->type->isIndividual()) {
            return true;
        }

        // Залиха е подмодул: без Материјално не постои, без разлика што пишува
        // во колоната. Формата ја отштиклира и зачувувањето ја запишува како
        // исклучена — ова е третата брана, за ред сменет со рака.
        if ($module === CompanyModule::STOCK && ! $this->uses_material) {
            return false;
        }

        return (bool) $this->{$module->column()};
    }
```

- [ ] **Step 6: Пушти го тестот и потврди дека поминува**

Run: `php artisan test tests/Feature/CompanyModulesTest.php`
Expected: PASS — 4 тестови

- [ ] **Step 7: Комитирај**

```bash
git add database/migrations/2026_09_05_100000_add_module_flags_to_companies_table.php app/Support/CompanyModule.php app/Models/Company.php tests/Feature/CompanyModulesTest.php
git commit -m "feat(modules): module flags on a company, with the stock sub-module rule"
```

---

### Task 2: Менито ги крие ставките на исклучен модул

**Files:**
- Modify: `app/Support/Menu.php`
- Test: `tests/Unit/Support/MenuTest.php`

**Interfaces:**
- Consumes: `Company::usesModule(CompanyModule)`, `CompanyModule` од Task 1.
- Produces: секоја ставка во дрвото носи клуч `'module' => CompanyModule|null`; `Menu::for()` останува со истиот потпис и истата форма на резултат.

**Мапата модул → ставка** (`legalTree()` само; `individualTree()` не добива модули):

| Група | Ставка | Модул |
|---|---|---|
| ФИНАНСИИ | Главна книга, Извештаи и обрасци, Банкарски документи | `FINANCE` |
| ПРОДАЖБА | Излезни фактури, Профактури (наскоро) | `MATERIAL` |
| ПРОДАЖБА | **Кооперанти** | `null` — ги бара и книжењето |
| ТРОШОЦИ | Влезни фактури, Други трошоци | `MATERIAL` |
| ЗАЛИХА | сите, вклучно Попис (наскоро) | `STOCK` |
| ПЛАТИ И ЧР | Вработени, Плата (МПИН), е-ПДД (наскоро) | `PAYROLL` |
| ПОСТАВКИ | Контен план | `FINANCE` |
| ПОСТАВКИ | Параметри за плата | `PAYROLL` |
| ПОСТАВКИ | Компанија, е-Фактура барања | `null` |

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Додај ги во `tests/Unit/Support/MenuTest.php`, на крајот од класата. Додај и `use App\Support\CompanyModule;` кон увозите (ако е потребен) — тестовите подолу користат само `Company::factory()`.

```php
    public function test_a_switched_off_module_takes_its_group_out_of_the_menu(): void
    {
        $company = Company::factory()->create(['uses_payroll' => false]);

        $this->assertNotContains(
            'ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ',
            $this->groupLabels(Menu::for($this->userWithRole('admin'), $company))
        );
    }

    public function test_switching_off_finance_takes_the_chart_of_accounts_out_of_settings(): void
    {
        $company = Company::factory()->create(['uses_finance' => false]);
        $menu = Menu::for($this->userWithRole('admin'), $company);

        $this->assertNotContains('ФИНАНСИИ', $this->groupLabels($menu));
        $this->assertNotContains('Контен план', $this->itemLabels($menu, 'settings'));
        // Останатите поставки остануваат — тие немаат модул.
        $this->assertContains('Компанија', $this->itemLabels($menu, 'settings'));
    }

    public function test_partners_survive_when_material_is_switched_off(): void
    {
        // Партнерите ги бара и книжењето, не само фактурирањето, па намерно
        // немаат модул. Групата ПРОДАЖБА останува со неа единствена внатре.
        $company = Company::factory()->create(['uses_material' => false]);
        $menu = Menu::for($this->userWithRole('admin'), $company);

        $this->assertSame(['Кооперанти'], $this->itemLabels($menu, 'sales'));
        $this->assertNotContains('ТРОШОЦИ', $this->groupLabels($menu));
    }

    public function test_stock_disappears_with_material_even_when_its_own_flag_is_on(): void
    {
        $company = Company::factory()->create([
            'uses_material' => false,
            'uses_stock' => true,
        ]);

        $this->assertNotContains(
            'ЗАЛИХА',
            $this->groupLabels(Menu::for($this->userWithRole('admin'), $company))
        );
    }

    public function test_stock_can_be_switched_off_on_its_own(): void
    {
        $company = Company::factory()->create(['uses_stock' => false]);
        $menu = Menu::for($this->userWithRole('admin'), $company);

        $this->assertNotContains('ЗАЛИХА', $this->groupLabels($menu));
        $this->assertContains('ПРОДАЖБА', $this->groupLabels($menu));
        $this->assertContains('ТРОШОЦИ', $this->groupLabels($menu));
    }

    public function test_an_individual_profile_ignores_the_module_flags(): void
    {
        $company = Company::factory()->create([
            'type' => \App\Support\CompanyType::INDIVIDUAL,
            'uses_material' => false,
            'uses_finance' => false,
        ]);

        $this->assertSame(
            ['ПРОДАЖБА', 'БАНКАРСКИ ДОКУМЕНТИ', 'ПРИЈАВИ', 'ПОСТАВКИ'],
            $this->groupLabels(Menu::for($this->userWithRole('admin'), $company))
        );
    }
```

- [ ] **Step 2: Пушти ги и потврди дека паѓаат**

Run: `php artisan test tests/Unit/Support/MenuTest.php`
Expected: FAIL — новите тестови паѓаат (групите сѐ уште се појавуваат); постојните поминуваат.

- [ ] **Step 3: Дополни го `Menu.php`**

Додај `use App\Support\CompanyModule;` кон увозите.

Во `for()`, предај ја фирмата во филтерот:

```php
            $items = array_values(array_filter(
                $group['items'],
                fn (array $item) => self::itemVisible($user, $company, $item)
            ));
```

Замени го `itemVisible()` со:

```php
    private static function itemVisible(User $user, Company $company, array $item): bool
    {
        // Unbuilt entries double as the admin's remaining-work map. A client
        // only ever sees working features.
        if (($item['soon'] ?? false) && ! $user->hasAnyRole(['admin', 'accountant'])) {
            return false;
        }

        // Модулот се проверува пред улогата: ставка од исклучен модул не ја
        // гледа никој, ниту админ. Модулот е поставка на фирмата, не право на
        // корисникот. Групата потоа сама исчезнува кога ќе остане празна —
        // правилото веќе постои во `for()` и не се менува.
        $module = $item['module'] ?? null;

        if ($module instanceof CompanyModule && ! $company->usesModule($module)) {
            return false;
        }

        $roles = $item['roles'] ?? null;

        return $roles === null || $user->hasAnyRole($roles);
    }
```

Замени го `soon()` со верзија што прима модул:

```php
    private static function soon(Company $company, string $slug, ?CompanyModule $module = null): array
    {
        return [
            'label' => self::SOON_FEATURES[$slug]['label'],
            'url' => route('coming-soon', [$company, $slug]),
            'pattern' => 'coming-soon',
            'soon' => true,
            'module' => $module,
        ];
    }
```

Во `legalTree()` додај `'module' => …` на секоја ставка според мапата погоре. На пример групата ФИНАНСИИ станува:

```php
            [
                'key' => 'finance',
                'label' => 'ФИНАНСИИ',
                'items' => [
                    ['label' => 'Главна книга', 'url' => route('accounting.journal-groups.index', $company), 'pattern' => 'accounting.journal-groups.*', 'roles' => ['admin', 'accountant'], 'module' => CompanyModule::FINANCE],
                    ['label' => 'Извештаи и обрасци', 'url' => route('reports.index', $company), 'pattern' => 'reports.*', 'roles' => ['admin', 'accountant'], 'module' => CompanyModule::FINANCE],
                    ['label' => 'Банкарски документи', 'url' => route('bank-statements.index', $company), 'pattern' => 'bank-statements.*', 'roles' => ['admin', 'accountant'], 'module' => CompanyModule::FINANCE],
                ],
            ],
```

а повиците за „наскоро" стануваат:

```php
                    self::soon($company, 'profakturi', CompanyModule::MATERIAL),
                    self::soon($company, 'popis', CompanyModule::STOCK),
                    self::soon($company, 'e-pdd', CompanyModule::PAYROLL),
```

`individualTree()` **не се менува** — таму ставките остануваат без клуч `'module'`, а `usesModule()` и онака враќа `true` за физичко лице.

- [ ] **Step 4: Пушти ги и потврди дека поминуваат**

Run: `php artisan test tests/Unit/Support/MenuTest.php`
Expected: PASS — сите, стари и нови

- [ ] **Step 5: Комитирај**

```bash
git add app/Support/Menu.php tests/Unit/Support/MenuTest.php
git commit -m "feat(modules): the menu drops what the company does not use"
```

---

### Task 3: Вистинската брана — `EnsureCompanyModule` врз рутите

Ова е делот поради кој фазата воопшто вреди. Без него исклучениот модул е само сокриен, а стар обележувач го отвора.

**Files:**
- Create: `app/Http/Middleware/EnsureCompanyModule.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/CompanyModuleAccessTest.php` (нов)

**Interfaces:**
- Consumes: `Company::usesModule(CompanyModule)`, `CompanyModule` од Task 1.
- Produces: middleware со параметар, се пишува како `EnsureCompanyModule::class.':material'`.

**Кои групи кој модул добиваат** (сите во `routes/web.php`):

| Модул | Групи по `name(...)` |
|---|---|
| `material` | `sales-invoices.`, двете `sales-invoices.efaktura.`, `purchase-invoices.`, двете `incoming-efaktura.`, `other-costs.` |
| `stock` | `inventory.` |
| `payroll` | `employees.`, `payroll-parameters.`, `payroll.`, `payroll-runs.` |
| `finance` | `accounting.`, `bank-statements.`, `reports.` |

**Намерно без брана:** `partners.`, `documents.`, `coming-soon`, `form743.`, `form743.worklist`. Кооперантите ги бара книжењето; документите и 743 обрасците не припаѓаат на ниту еден модул; страницата „наскоро" е една реченица текст и нема што да протече.

- [ ] **Step 1: Напиши го тестот што паѓа**

Create `tests/Feature/CompanyModuleAccessTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    /** По една адреса од секој модул, и колоната што ја затвора. */
    public static function guardedRoutes(): array
    {
        return [
            'излезни фактури' => ['sales-invoices.index', 'uses_material'],
            'влезни фактури' => ['purchase-invoices.index', 'uses_material'],
            'други трошоци' => ['other-costs.index', 'uses_material'],
            'магацини' => ['inventory.warehouses.index', 'uses_stock'],
            'состојба' => ['inventory.reports.stock-on-hand', 'uses_stock'],
            'вработени' => ['employees.index', 'uses_payroll'],
            'плати' => ['payroll-runs.index', 'uses_payroll'],
            'параметри за плата' => ['payroll-parameters.index', 'uses_payroll'],
            'контен план' => ['accounting.accounts.index', 'uses_finance'],
            'главна книга' => ['accounting.journal-groups.index', 'uses_finance'],
            'извештаи' => ['reports.index', 'uses_finance'],
            'изводи' => ['bank-statements.index', 'uses_finance'],
        ];
    }

    #[DataProvider('guardedRoutes')]
    public function test_a_switched_off_module_refuses_its_screen(string $route, string $column): void
    {
        $company = Company::factory()->create([$column => false]);

        $this->actingAs($this->admin())
            ->get(route($route, $company))
            ->assertForbidden();
    }

    #[DataProvider('guardedRoutes')]
    public function test_the_same_screen_opens_when_the_module_is_on(string $route, string $column): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route($route, $company))
            ->assertOk();
    }

    public function test_an_accountant_is_refused_too_not_only_a_client(): void
    {
        // Модулот е поставка на канцеларијата, не право на корисникот. Брана
        // што има исклучоци не е брана — ако сметководителот треба да влезе,
        // админ го враќа штиклирањето.
        $company = Company::factory()->create(['uses_payroll' => false]);
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $this->actingAs($accountant)
            ->get(route('employees.index', $company))
            ->assertForbidden();
    }

    public function test_stock_screens_close_with_material_even_when_their_own_flag_is_on(): void
    {
        $company = Company::factory()->create([
            'uses_material' => false,
            'uses_stock' => true,
        ]);

        $this->actingAs($this->admin())
            ->get(route('inventory.items.index', $company))
            ->assertForbidden();
    }

    public function test_partners_and_documents_stay_open_with_every_module_off(): void
    {
        $company = Company::factory()->create([
            'uses_material' => false,
            'uses_stock' => false,
            'uses_payroll' => false,
            'uses_finance' => false,
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('partners.index', $company))->assertOk();
        $this->actingAs($admin)->get(route('documents.index', $company))->assertOk();
        $this->actingAs($admin)->get(route('companies.profile', $company))->assertOk();
    }

    public function test_an_individual_profile_is_never_closed_by_a_module(): void
    {
        $company = Company::factory()->create([
            'type' => CompanyType::INDIVIDUAL,
            'uses_material' => false,
        ]);

        $this->actingAs($this->admin())
            ->get(route('sales-invoices.index', $company))
            ->assertOk();
    }
}
```

- [ ] **Step 2: Пушти го и потврди дека паѓа**

Run: `php artisan test tests/Feature/CompanyModuleAccessTest.php`
Expected: FAIL — екраните се отвораат (200 наместо 403)

- [ ] **Step 3: Направи го middleware-от**

Create `app/Http/Middleware/EnsureCompanyModule.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\CompanyModule;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Екраните на модул што фирмата не го користи не се достапни.
 *
 * Ова е вистинската брана. Криењето во менито само спречува кликање — без ова,
 * секој од тие екрани останува достапен со впишување адреса или со стар
 * обележувач. Истата дупка беше вистинска за улогата клиент пред да се затвори
 * со `EnsureAccountingAccess`, и за физичко лице пред `EnsureLegalEntity`.
 *
 * Се применува врз цели групи рути, за екран додаден подоцна да биде покриен
 * стандардно наместо со сеќавање.
 */
class EnsureCompanyModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $company = $request->route('company');

        abort_if(
            $company instanceof Company && ! $company->usesModule(CompanyModule::from($module)),
            403,
            'Овој модул не е вклучен за оваа фирма.'
        );

        return $next($request);
    }
}
```

- [ ] **Step 4: Закачи го врз рутните групи**

Modify `routes/web.php`. Додај кон увозите:

```php
use App\Http\Middleware\EnsureCompanyModule;
```

Потоа во секоја од групите долу додај го параметризираниот запис во низата middleware, **по** постојните:

- `->name('accounting.')` → додај `EnsureCompanyModule::class.':finance'`
- `->name('reports.')` → додај `EnsureCompanyModule::class.':finance'`
- `->name('bank-statements.')` → додај `EnsureCompanyModule::class.':finance'`
- `->name('inventory.')` → додај `EnsureCompanyModule::class.':stock'`
- `->name('employees.')` → додај `EnsureCompanyModule::class.':payroll'`
- `->name('payroll-parameters.')` → додај `EnsureCompanyModule::class.':payroll'`
- `->name('payroll.')` → додај `EnsureCompanyModule::class.':payroll'`
- `->name('payroll-runs.')` → додај `EnsureCompanyModule::class.':payroll'`
- `->name('sales-invoices.')` → додај `EnsureCompanyModule::class.':material'`
- **двете** групи `->name('sales-invoices.efaktura.')` → додај `EnsureCompanyModule::class.':material'`
- `->name('purchase-invoices.')` → додај `EnsureCompanyModule::class.':material'`
- **двете** групи `->name('incoming-efaktura.')` → додај `EnsureCompanyModule::class.':material'`
- `->name('other-costs.')` → додај `EnsureCompanyModule::class.':material'`

Пример како изгледа готовата линија:

```php
Route::middleware(['auth', EnsureLegalEntity::class, EnsureCompanyModule::class.':stock'])->prefix('companies/{company}')->name('inventory.')->group(function () {
```

Не ги допирај `partners.`, `documents.`, `coming-soon`, `form743.`, ниту `form743.worklist`.

- [ ] **Step 5: Пушти го тестот и потврди дека поминува**

Run: `php artisan test tests/Feature/CompanyModuleAccessTest.php`
Expected: PASS

- [ ] **Step 6: Пушти ги и постојните тестови за пристап, за да не се скршиле**

Run: `php artisan test tests/Feature/IndividualProfileAccessTest.php tests/Feature/AccountingAccessTest.php tests/Feature/InventoryRoutesTest.php tests/Feature/InvoicingRoutesTest.php`
Expected: PASS — фабриката прави фирма со сите модули вклучени, па ништо не смее да се смени.

- [ ] **Step 7: Комитирај**

```bash
git add app/Http/Middleware/EnsureCompanyModule.php routes/web.php tests/Feature/CompanyModuleAccessTest.php
git commit -m "feat(modules): refuse the screens of a module the company does not use"
```

---

### Task 4: Кутиите во формата за нова фирма

**Files:**
- Modify: `app/Livewire/CompanyIndex.php`
- Modify: `resources/views/livewire/company-index.blade.php`
- Test: `tests/Feature/CompanyIndexTest.php`

**Interfaces:**
- Consumes: `CompanyModule`, колоните од Task 1.
- Produces: својства `newUsesMaterial`, `newUsesStock`, `newUsesPayroll`, `newUsesFinance` (`bool`, стандардно `true`) на `App\Livewire\CompanyIndex`.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Додај ги на крајот од класата во `tests/Feature/CompanyIndexTest.php`. Фајлот веќе ги има `Livewire\Livewire`, `App\Livewire\CompanyIndex` и `App\Models\Company` меѓу увозите, и веќе има помошна метода `actAsAdmin(): void` — тестовите подолу ја користат неа.

```php
    public function test_a_new_company_is_created_with_the_ticked_modules(): void
    {
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newType', \App\Support\CompanyType::LEGAL->value)
            ->set('newName', 'Тест ДООЕЛ')
            ->set('newUsesPayroll', false)
            ->set('newUsesStock', false)
            ->call('addCompany')
            ->assertHasNoErrors();

        $company = Company::where('name', 'Тест ДООЕЛ')->sole();

        $this->assertTrue($company->uses_material);
        $this->assertTrue($company->uses_finance);
        $this->assertFalse($company->uses_payroll);
        $this->assertFalse($company->uses_stock);
    }

    public function test_stock_is_written_off_when_material_is_not_ticked(): void
    {
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newType', \App\Support\CompanyType::LEGAL->value)
            ->set('newName', 'Без материјално ДОО')
            ->set('newUsesMaterial', false)
            ->set('newUsesStock', true)
            ->call('addCompany')
            ->assertHasNoErrors();

        $company = Company::where('name', 'Без материјално ДОО')->sole();

        $this->assertFalse($company->uses_material);
        $this->assertFalse($company->uses_stock);
    }

    public function test_an_individual_profile_is_created_with_every_module_on(): void
    {
        // Кутиите не се појавуваат за физичко лице, па што и да останало во
        // компонентата од претходен избор не смее да го затвори профилот.
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newType', \App\Support\CompanyType::LEGAL->value)
            ->set('newUsesPayroll', false)
            ->set('newType', \App\Support\CompanyType::INDIVIDUAL->value)
            ->set('newName', 'Петар Петров')
            ->call('addCompany')
            ->assertHasNoErrors();

        $company = Company::where('name', 'Петар Петров')->sole();

        $this->assertTrue($company->uses_material);
        $this->assertTrue($company->uses_stock);
        $this->assertTrue($company->uses_payroll);
        $this->assertTrue($company->uses_finance);
    }

    public function test_the_module_boxes_only_show_for_a_legal_entity(): void
    {
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newType', \App\Support\CompanyType::LEGAL->value)
            ->assertSee('Материјално работење')
            ->set('newType', \App\Support\CompanyType::INDIVIDUAL->value)
            ->assertDontSee('Материјално работење');
    }
```

- [ ] **Step 2: Пушти го и потврди дека паѓа**

Run: `php artisan test tests/Feature/CompanyIndexTest.php`
Expected: FAIL — `Property [$newUsesPayroll] not found`

- [ ] **Step 3: Дополни ја компонентата**

Modify `app/Livewire/CompanyIndex.php`.

По `public string $newAddress = '';` додај:

```php
    // Стандардно сѐ вклучено: најчестиот случај е клиент што користи сѐ, а
    // отштиклирањето е свесен потег.
    public bool $newUsesMaterial = true;

    public bool $newUsesStock = true;

    public bool $newUsesPayroll = true;

    public bool $newUsesFinance = true;
```

Во `validate([...])`, по `'newAddress' => 'nullable|string|max:255',` додај:

```php
            'newUsesMaterial' => 'boolean',
            'newUsesStock' => 'boolean',
            'newUsesPayroll' => 'boolean',
            'newUsesFinance' => 'boolean',
```

Пред повикот `Company::create([...])` додај:

```php
        // Модулите важат само за правно лице. Кај физичко лице формата не ги
        // ни прикажува, па вредноста заостаната во компонентата од претходен
        // избор на тип не смее да заврши во базата — истата замка како кај
        // ЕДБ/ЕМБГ и `is_vat_registered` погоре.
        $usesMaterial = $isLegal ? $validated['newUsesMaterial'] : true;
```

Во низата на `Company::create([...])`, по `'is_vat_registered' => $isLegal,` додај:

```php
            'uses_material' => $usesMaterial,
            // Залиха без Материјално не постои. Се запишува исклучена без
            // разлика што дошло од формата.
            'uses_stock' => $usesMaterial && ($isLegal ? $validated['newUsesStock'] : true),
            'uses_payroll' => $isLegal ? $validated['newUsesPayroll'] : true,
            'uses_finance' => $isLegal ? $validated['newUsesFinance'] : true,
```

И во `$this->reset([...])` додај ги четирите имиња, за следната фирма да почне пак со сѐ вклучено:

```php
        $this->reset(['newName', 'newType', 'newTaxId', 'newEmbg', 'newEmail', 'newPhone', 'newAddress',
            'newUsesMaterial', 'newUsesStock', 'newUsesPayroll', 'newUsesFinance']);
```

- [ ] **Step 4: Додај ги кутиите во формата**

Modify `resources/views/livewire/company-index.blade.php`. Вметни го блокот **веднаш пред** `<x-primary-button type="submit">Додади фирма</x-primary-button>`:

```blade
                {{-- Модулите важат само за правно лице — кај физичко лице
                     типот веќе одлучува што се гледа. Изборот погоре е
                     wire:model.live, па блокот се појавува штом типот ќе се
                     избере. --}}
                @if ($newType === \App\Support\CompanyType::LEGAL->value)
                    <div class="w-full">
                        <span class="block text-sm font-medium text-gray-700 mb-1">Што користи клиентот</span>
                        <div class="flex flex-wrap gap-x-6 gap-y-2">
                            <div>
                                <label class="flex items-center gap-2 text-sm">
                                    {{-- .live, зашто подкутијата Залиха сивее
                                         штом Материјално се отштиклира. --}}
                                    <input type="checkbox" wire:model.live="newUsesMaterial">
                                    Материјално работење
                                </label>
                                <label class="flex items-center gap-2 text-sm ms-6 mt-1 {{ $newUsesMaterial ? '' : 'text-gray-400' }}">
                                    <input type="checkbox" wire:model="newUsesStock" @disabled(! $newUsesMaterial)>
                                    Залиха
                                </label>
                            </div>
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" wire:model="newUsesPayroll">
                                Плата
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" wire:model="newUsesFinance">
                                Финансии
                            </label>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Може да се смени подоцна во профилот на фирмата.</p>
                    </div>
                @endif
```

- [ ] **Step 5: Пушти го тестот и потврди дека поминува**

Run: `php artisan test tests/Feature/CompanyIndexTest.php`
Expected: PASS

- [ ] **Step 6: Комитирај**

```bash
git add app/Livewire/CompanyIndex.php resources/views/livewire/company-index.blade.php tests/Feature/CompanyIndexTest.php
git commit -m "feat(modules): tick what the client uses when the profile is created"
```

---

### Task 5: Менување на модулите во профилот на фирмата

`CompanyPolicy::update()` е веќе само за админ, па не треба нова проверка — кутиите влегуваат во постојниот образец за уредување.

**Files:**
- Modify: `app/Livewire/CompanyProfile.php`
- Modify: `resources/views/livewire/company-profile.blade.php`
- Test: `tests/Feature/CompanyProfileModulesTest.php` (нов)

**Interfaces:**
- Consumes: колоните од Task 1.
- Produces: својства `editUsesMaterial`, `editUsesStock`, `editUsesPayroll`, `editUsesFinance` (`bool`) на `App\Livewire\CompanyProfile`.

- [ ] **Step 1: Напиши го тестот што паѓа**

Create `tests/Feature/CompanyProfileModulesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Livewire\CompanyProfile;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyProfileModulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_an_admin_switches_a_module_off_later(): void
    {
        $company = Company::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('editUsesPayroll', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($company->fresh()->uses_payroll);
        $this->assertTrue($company->fresh()->uses_finance);
    }

    public function test_switching_material_off_writes_stock_off_too(): void
    {
        $company = Company::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('editUsesMaterial', false)
            ->set('editUsesStock', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($company->fresh()->uses_material);
        $this->assertFalse($company->fresh()->uses_stock);
    }

    public function test_switching_a_module_back_on_returns_the_data_untouched(): void
    {
        // Исклучувањето само крие и брани — ниту еден ред не се брише.
        $company = Company::factory()->create();
        $partner = \App\Models\Partner::factory()->create(['company_id' => $company->id]);

        $company->update(['uses_material' => false]);
        $company->update(['uses_material' => true]);

        $this->assertDatabaseHas('partners', ['id' => $partner->id]);
    }

    public function test_an_accountant_cannot_change_the_modules(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        Livewire::actingAs($accountant)
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->assertForbidden();
    }

    public function test_an_individual_profile_shows_no_module_boxes(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->assertDontSee('Материјално работење');
    }
}
```

- [ ] **Step 2: Пушти го и потврди дека паѓа**

Run: `php artisan test tests/Feature/CompanyProfileModulesTest.php`
Expected: FAIL — `Property [$editUsesPayroll] not found`

- [ ] **Step 3: Дополни ја компонентата**

Modify `app/Livewire/CompanyProfile.php`.

По `public bool $editIsVatRegistered = true;` додај:

```php
    public bool $editUsesMaterial = true;

    public bool $editUsesStock = true;

    public bool $editUsesPayroll = true;

    public bool $editUsesFinance = true;
```

Во `startEdit()`, до `$this->editIsVatRegistered = $this->company->is_vat_registered;` додај:

```php
        $this->editUsesMaterial = $this->company->uses_material;
        $this->editUsesStock = $this->company->uses_stock;
        $this->editUsesPayroll = $this->company->uses_payroll;
        $this->editUsesFinance = $this->company->uses_finance;
```

Во `save()`, во низата на `$this->validate([...])`, до `'editIsVatRegistered' => 'boolean',` додај:

```php
            'editUsesMaterial' => 'boolean',
            'editUsesStock' => 'boolean',
            'editUsesPayroll' => 'boolean',
            'editUsesFinance' => 'boolean',
```

Во блокот `if ($isLegal) { ... }` што ги запишува `is_vat_registered`, `mpin_obvrznik_code` и останатите, додај:

```php
                $companyData['uses_material'] = $validated['editUsesMaterial'];
                // Залиха без Материјално не постои — се запишува исклучена без
                // разлика што дошло од формата.
                $companyData['uses_stock'] = $validated['editUsesMaterial'] && $validated['editUsesStock'];
                $companyData['uses_payroll'] = $validated['editUsesPayroll'];
                $companyData['uses_finance'] = $validated['editUsesFinance'];
```

Модулите намерно **не** се запишуваат за физичко лице — блокот `if ($isLegal)` е точното место.

- [ ] **Step 4: Додај ги кутиите во образецот**

Modify `resources/views/livewire/company-profile.blade.php`. Вметни го блокот веднаш **по** постојниот блок за „Во ДДВ систем" (околу линија 200), сѐ уште внатре во истиот `@if` за правно лице:

```blade
                            <div class="pt-2">
                                <span class="block text-sm font-medium text-gray-700 mb-1">Што користи клиентот</span>
                                <div class="flex flex-wrap gap-x-6 gap-y-2">
                                    <div>
                                        <label class="flex items-center gap-2 text-sm">
                                            {{-- .live, зашто подкутијата Залиха сивее
                                                 штом Материјално се отштиклира. --}}
                                            <input type="checkbox" wire:model.live="editUsesMaterial">
                                            Материјално работење
                                        </label>
                                        <label class="flex items-center gap-2 text-sm ms-6 mt-1 {{ $editUsesMaterial ? '' : 'text-gray-400' }}">
                                            <input type="checkbox" wire:model="editUsesStock" @disabled(! $editUsesMaterial)>
                                            Залиха
                                        </label>
                                    </div>
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" wire:model="editUsesPayroll">
                                        Плата
                                    </label>
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" wire:model="editUsesFinance">
                                        Финансии
                                    </label>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">
                                    Исклучен модул исчезнува од менито и неговите екрани не се отвораат.
                                    Внесените податоци остануваат и се враќаат кога модулот пак ќе се вклучи.
                                </p>
                            </div>
```

- [ ] **Step 5: Пушти го тестот и потврди дека поминува**

Run: `php artisan test tests/Feature/CompanyProfileModulesTest.php`
Expected: PASS

- [ ] **Step 6: Пушти ги и постојните тестови за профилот**

Run: `php artisan test tests/Feature/CompanyProfileTest.php tests/Feature/CompanyProfileFieldsTest.php`
Expected: PASS

- [ ] **Step 7: Комитирај**

```bash
git add app/Livewire/CompanyProfile.php resources/views/livewire/company-profile.blade.php tests/Feature/CompanyProfileModulesTest.php
git commit -m "feat(modules): change what a client uses from the company profile"
```

---

### Task 6: Плочките на почетниот екран ги следат модулите

**Files:**
- Modify: `resources/views/livewire/company-dashboard.blade.php`
- Test: `tests/Feature/CompanyDashboardTilesTest.php`

**Interfaces:**
- Consumes: `Company::usesModule(CompanyModule)` од Task 1.

**Мапа плочка → модул** (само делот за правно лице):

| Плочка | Модул |
|---|---|
| Приход за работната година, Трошоци за работната година, Разлика, Ненаплатено од купувачи, Обврски кон добавувачи, е-Фактура | `MATERIAL` |
| ДДВ за тековниот период | `FINANCE` (покрај постојниот `$canSeeVat`) |
| Вредност на залихата | `STOCK` |

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Додај ги на крајот од класата во `tests/Feature/CompanyDashboardTilesTest.php`. Фајлот веќе ги има увозите `App\Livewire\CompanyDashboard`, `App\Models\Company`, `App\Support\CompanyType` и `Livewire\Livewire`, и веќе има помошна метода `admin(): User`. Тестовите го следат истиот образец како постојните таму — `Livewire::actingAs(...)->test(CompanyDashboard::class, ...)`, не HTTP повик.

```php
    public function test_the_stock_tile_disappears_when_stock_is_switched_off(): void
    {
        $company = Company::factory()->create([
            'type' => CompanyType::LEGAL,
            'uses_stock' => false,
        ]);

        Livewire::actingAs($this->admin())
            ->test(CompanyDashboard::class, ['company' => $company])
            ->assertDontSee('Вредност на залихата')
            ->assertSee('Приход за работната година');
    }

    public function test_the_material_tiles_disappear_when_material_is_switched_off(): void
    {
        $company = Company::factory()->create([
            'type' => CompanyType::LEGAL,
            'uses_material' => false,
        ]);

        Livewire::actingAs($this->admin())
            ->test(CompanyDashboard::class, ['company' => $company])
            ->assertDontSee('Приход за работната година')
            ->assertDontSee('Обврски кон добавувачи')
            // Залиха паѓа заедно со Материјално.
            ->assertDontSee('Вредност на залихата');
    }

    public function test_the_vat_tile_disappears_when_finance_is_switched_off(): void
    {
        $company = Company::factory()->create([
            'type' => CompanyType::LEGAL,
            'uses_finance' => false,
        ]);

        Livewire::actingAs($this->admin())
            ->test(CompanyDashboard::class, ['company' => $company])
            ->assertDontSee('ДДВ за тековниот период');
    }
```

- [ ] **Step 2: Пушти го и потврди дека паѓа**

Run: `php artisan test tests/Feature/CompanyDashboardTilesTest.php`
Expected: FAIL — плочките сѐ уште се прикажуваат

- [ ] **Step 3: Заврти ги плочките во проверка по модул**

Modify `resources/views/livewire/company-dashboard.blade.php`.

Внатре во `@if ($company->type->isLegal())`, веднаш по редот со работната година, додај:

```blade
        @php
            $usesMaterial = $company->usesModule(\App\Support\CompanyModule::MATERIAL);
            $usesStock = $company->usesModule(\App\Support\CompanyModule::STOCK);
            $usesFinance = $company->usesModule(\App\Support\CompanyModule::FINANCE);
        @endphp
```

Потоа:

1. Обвиткај ги петте плочки од Приход до „Обврски кон добавувачи" (постојните линии 9–40) во `@if ($usesMaterial) … @endif`.
2. Смени го условот на ДДВ плочката од `@if ($canSeeVat)` во `@if ($canSeeVat && $usesFinance)`.
3. Обвиткај ја плочката „Вредност на залихата" во `@if ($usesStock) … @endif`.
4. Обвиткај ја плочката „е-Фактура: испратени и со грешка" во `@if ($usesMaterial) … @endif`.

Делот за физичко лице (`@if ($company->type->isIndividual())`) **не се менува**.

- [ ] **Step 4: Пушти го тестот и потврди дека поминува**

Run: `php artisan test tests/Feature/CompanyDashboardTilesTest.php`
Expected: PASS

- [ ] **Step 5: Пушти ги и соседните тестови за почетниот екран**

Run: `php artisan test tests/Feature/CompanyDashboardLandingTest.php tests/Feature/CompanyDashboardQueryTest.php`
Expected: PASS

- [ ] **Step 6: Комитирај**

```bash
git add resources/views/livewire/company-dashboard.blade.php tests/Feature/CompanyDashboardTilesTest.php
git commit -m "feat(modules): the dashboard tiles follow the company's modules"
```

---

### Task 7: Целата серија и спојување

- [ ] **Step 1: Прашај го корисникот пред долгото пуштање**

Целата серија трае околу 45 минути. Прашај дали да се пушти сега. Не ја пуштај во позадина — прекината серија почнува од почеток.

- [ ] **Step 2: Пушти ја целата серија**

Run: `php artisan test`
Expected: PASS — сите тестови, вклучително ~1280 постоечки

- [ ] **Step 3: Ако нешто падне, поправи и пушти го само тој фајл**

Не ја пуштај целата серија повторно за една поправка. Пушти го фајлот што паднал, и целата серија само еднаш на крајот.

- [ ] **Step 4: Спој на `main` и пушти**

```bash
git checkout main
git merge --no-ff company-modules -m "Merge company-modules: what each client actually uses"
git push origin main
```

---

## Self-review

Проверено против спецификацијата:

| Барање во спецификацијата | Задача |
|---|---|
| Четири колони со стандардно `true` | 1 |
| `CompanyModule` enum со `label()` | 1 |
| Правило за Залиха на три места (форма, зачувување, `usesModule`) | 1 (`usesModule`), 4 и 5 (форма и зачувување) |
| Физичко лице никогаш не се затвора | 1, 2, 3 |
| Менито крие по ставка, групата паѓа сама | 2 |
| Кооперанти без модул | 2 |
| Контен план → Финансии, Параметри за плата → Плата | 2 |
| Ставките „наскоро" го носат модулот на својата група | 2 |
| 403 на адресите, важи и за сметководител | 3 |
| Кутии во формата за нова фирма, скриени за физичко лице | 4 |
| Менување подоцна во профилот, само админ | 5 |
| Плочките ги следат модулите | 6 |
| Ништо не се брише | 5 (тест), сите задачи (ниту една не брише ред) |
