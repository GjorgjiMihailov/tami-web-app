# Апликации по субдомејн — план за изведба

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Порталот се дели на четири адреси — `portal` (најава, фирми, поставки) и три апликации `prodazba`, `finansii`, `plata` — со заедничка најава, мени по апликација, панел „АПЛИКАЦИИ“ и право по корисник за секоја апликација.

**Architecture:** Едно репо, една база, еден сервер. Рутите се групираат со `Route::domain()` врз вредности од `config/apps.php`, па `route()` сам произведува адреса на вистинскиот субдомејн. Рутите за најава остануваат без домен и затоа се фаќаат на сите четири хостови; колачето на сесијата се врзува за `.financebuddy.mk`. `App\Support\PortalApp` е единственото место што знае кои апликации постојат.

**Tech Stack:** Laravel 12/13, Livewire 3 + Volt, Tailwind (JIT преку Vite), MySQL 8 во продукција и CI, SQLite во меморија за тестови, PHPUnit (class-based, `test_*` методи), Spatie Permission за улоги.

**Спец:** `docs/superpowers/specs/2026-09-15-app-subdomains-design.md`

## Global Constraints

- Сиот текст видлив за корисник е на **строг македонски** (кирилица). Без бугаризми.
- **Ниту едно име на рута не се менува.** Се додава само домен на групите. Ако тест бара преименување, тестот се менува, не името.
- Постојните брани остануваат непроменети и не се заобиколуваат: `EnsureAccountingAccess`, `EnsureLegalEntity`, `EnsureIndividual`, `EnsureCompanyModule`.
- Тестовите се PHPUnit класи во `tests/Feature` или `tests/Unit`, со `use RefreshDatabase;` каде има база, методи `test_*`, пораки за тврдењата на македонски (постојниот стил во `tests/Feature/CompanyModulesTest.php`).
- Тестовите се пуштаат со `php artisan test --filter=...` — **не** со `composer test` (се задавува на 300s лимитот на composer и враќа `exit 0` иако паднал).
- Комит по задача, порака на македонски, со `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- Livewire компонента **не смее** да дефинира сопствен `__invoke` — доаѓа од `HandlesPageComponents`.
- Ако задача менува Blade, на крајот од задачата се пушта `npm run build` (Tailwind JIT ги гледа класите само при градба).
- Стандардна вредност на колона во базата **не** полни свежосоздаден модел во меморија — секоја нова boolean колона со `DEFAULT true` мора да добие и `protected $attributes` вредност на моделот.
- Целата серија (`php artisan test`, ~8.5 мин) се пушта **еднаш**, во последната задача. Не се пушта додека друг агент менува фајлови.

## Преглед на фајлови

**Се создаваат:**
- `config/apps.php` — домените на четирите хостови.
- `app/Support/PortalApp.php` — енум: кои апликации постојат, име, колона на правото, домен, препознавање по хост.
- `app/Support/AppSwitcher.php` — списокот апликации достапни за даден корисник и фирма (го користат и панелот и плочките).
- `app/Support/LandingUrl.php` — каде слетува човек по најава кога нема барана адреса.
- `app/Http/Middleware/EnsureAppAccess.php` — браната пред секоја апликација.
- `resources/views/errors/app-access.blade.php` — екранот „Немате пристап“.
- `database/migrations/2026_09_15_000100_add_app_access_to_users_table.php`
- тестови: `tests/Unit/Support/PortalAppTest.php`, `tests/Feature/AppDomainRoutingTest.php`, `tests/Feature/AppAccessTest.php`, `tests/Feature/AppSwitcherTest.php`, `tests/Feature/UserAppAccessToggleTest.php`

**Се менуваат:**
- `routes/web.php` — четири `Route::domain()` обвивки.
- `app/Support/Menu.php` — секоја група добива апликација; `for()` филтрира по апликација; нов `firstUrl()`.
- `app/Models/User.php` — `$attributes`, `canAccessApp()`.
- `app/Livewire/Layout/Sidebar.php` + `resources/views/livewire/layout/sidebar.blade.php` — мени и заглавие по апликација.
- `resources/views/livewire/layout/navigation.blade.php` — копче и панел „АПЛИКАЦИИ“.
- `app/Livewire/CompanyDashboard.php` + `resources/views/livewire/company-dashboard.blade.php` — плочки со апликации.
- `app/Livewire/CompanyUsers.php`, `app/Livewire/OfficeUsers.php` + нивните погледи — квадратчиња за апликации.
- `resources/views/livewire/pages/auth/{login,accept-invitation,confirm-password,verify-email}.blade.php` и `app/Http/Controllers/Auth/VerifyEmailController.php` — пренасочувањата по најава добиваат хост.
- `phpunit.xml`, `.env.example`, `tests/Unit/Support/MenuTest.php`.

---

### Task 1: `PortalApp` и `config/apps.php`

**Files:**
- Create: `config/apps.php`
- Create: `app/Support/PortalApp.php`
- Create: `tests/Unit/Support/PortalAppTest.php`
- Modify: `phpunit.xml`
- Modify: `.env.example`

**Interfaces:**
- Produces: `App\Support\PortalApp` (enum, string-backed) со случаи `PORTAL='portal'`, `PRODAZBA='prodazba'`, `FINANSII='finansii'`, `PLATA='plata'` и методи `domain(): string`, `label(): string`, `userColumn(): ?string`, `accent(): string`, `fromHost(?string $host): ?self`, `workApps(): list<self>`.
- Consumes: ништо.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Support/PortalAppTest.php`:

```php
<?php

namespace Tests\Unit\Support;

use App\Support\PortalApp;
use Tests\TestCase;

class PortalAppTest extends TestCase
{
    public function test_domain_comes_from_config(): void
    {
        config(['apps.domains.prodazba' => 'prodazba.example']);

        $this->assertSame('prodazba.example', PortalApp::PRODAZBA->domain());
    }

    public function test_host_is_matched_back_to_its_app(): void
    {
        $this->assertSame(PortalApp::FINANSII, PortalApp::fromHost(PortalApp::FINANSII->domain()));
    }

    public function test_an_unknown_host_matches_nothing(): void
    {
        $this->assertNull(PortalApp::fromHost('tuѓ.example'));
    }

    public function test_work_apps_are_the_three_without_the_portal(): void
    {
        $this->assertSame(
            [PortalApp::PRODAZBA, PortalApp::FINANSII, PortalApp::PLATA],
            PortalApp::workApps()
        );
    }

    public function test_the_portal_has_no_permission_column(): void
    {
        $this->assertNull(PortalApp::PORTAL->userColumn());
        $this->assertSame('app_prodazba', PortalApp::PRODAZBA->userColumn());
        $this->assertSame('app_finansii', PortalApp::FINANSII->userColumn());
        $this->assertSame('app_plata', PortalApp::PLATA->userColumn());
    }

    public function test_every_app_has_a_macedonian_label(): void
    {
        $this->assertSame('Продажба', PortalApp::PRODAZBA->label());
        $this->assertSame('Финансии', PortalApp::FINANSII->label());
        $this->assertSame('Плата', PortalApp::PLATA->label());
        $this->assertSame('Портал', PortalApp::PORTAL->label());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PortalAppTest`
Expected: FAIL — `Class "App\Support\PortalApp" not found`.

- [ ] **Step 3: Write `config/apps.php`**

```php
<?php

// Домените на четирите хостови. Стандардните вредности се оние што ги користат
// тестовите и локалната работа, па серијата поминува без .env.
return [
    'domains' => [
        'portal' => env('APP_DOMAIN_PORTAL', 'portal.test'),
        'prodazba' => env('APP_DOMAIN_PRODAZBA', 'prodazba.test'),
        'finansii' => env('APP_DOMAIN_FINANSII', 'finansii.test'),
        'plata' => env('APP_DOMAIN_PLATA', 'plata.test'),
    ],
];
```

- [ ] **Step 4: Write `app/Support/PortalApp.php`**

```php
<?php

namespace App\Support;

/**
 * Кои апликации постојат. Единственото место што ги знае имињата, домените и
 * колоната со правото — рутите, менито, панелот и браната сите читаат од тука.
 */
enum PortalApp: string
{
    case PORTAL = 'portal';
    case PRODAZBA = 'prodazba';
    case FINANSII = 'finansii';
    case PLATA = 'plata';

    public function domain(): string
    {
        return (string) config("apps.domains.{$this->value}");
    }

    public function label(): string
    {
        return match ($this) {
            self::PORTAL => 'Портал',
            self::PRODAZBA => 'Продажба',
            self::FINANSII => 'Финансии',
            self::PLATA => 'Плата',
        };
    }

    /**
     * Колоната на `users` што го носи правото. Порталот нема — најавен корисник
     * секогаш смее да влезе во порталот, инаку не би имал каде да отиде.
     */
    public function userColumn(): ?string
    {
        return $this === self::PORTAL ? null : 'app_'.$this->value;
    }

    /**
     * Акцентната боја. Класите се испишани цели зашто Tailwind JIT чита текст,
     * не составува имиња на класи во време на извршување.
     */
    public function accent(): string
    {
        return match ($this) {
            self::PRODAZBA => 'text-brand',
            self::FINANSII => 'text-emerald-700',
            self::PLATA => 'text-indigo-700',
            self::PORTAL => 'text-brand',
        };
    }

    public static function fromHost(?string $host): ?self
    {
        foreach (self::cases() as $app) {
            if ($host !== null && $app->domain() === $host) {
                return $app;
            }
        }

        return null;
    }

    /** @return list<self> */
    public static function workApps(): array
    {
        return [self::PRODAZBA, self::FINANSII, self::PLATA];
    }
}
```

- [ ] **Step 5: Add the four domains to `phpunit.xml`**

Во блокот `<php>`, по `<env name="APP_ENV" value="testing"/>`:

```xml
        <env name="APP_DOMAIN_PORTAL" value="portal.test"/>
        <env name="APP_DOMAIN_PRODAZBA" value="prodazba.test"/>
        <env name="APP_DOMAIN_FINANSII" value="finansii.test"/>
        <env name="APP_DOMAIN_PLATA" value="plata.test"/>
```

- [ ] **Step 6: Add the same keys to `.env.example`**

Под `APP_URL`:

```
APP_DOMAIN_PORTAL=portal.test
APP_DOMAIN_PRODAZBA=prodazba.test
APP_DOMAIN_FINANSII=finansii.test
APP_DOMAIN_PLATA=plata.test
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=PortalAppTest`
Expected: PASS (6 тестови).

- [ ] **Step 8: Commit**

```bash
git add config/apps.php app/Support/PortalApp.php tests/Unit/Support/PortalAppTest.php phpunit.xml .env.example
git commit -m "feat: PortalApp и домени по апликација"
```

---

### Task 2: Рутите се делат по домен

**Files:**
- Modify: `routes/web.php`
- Create: `tests/Feature/AppDomainRoutingTest.php`

**Interfaces:**
- Consumes: `PortalApp::domain()` од Task 1.
- Produces: секоја постојна рута е врзана за точно еден хост; `route()` враќа апсолутна адреса со тој хост. Имињата на рутите не се менуваат.

**Распоред (пресликан од спецификацијата):**

| Домен | Групи и поединечни рути |
|---|---|
| portal | `/` (`home`), `dashboard`, `profile`, `companies.index`, `companies.office`, `efaktura.access-requests`, групата `companies/{company}` со `companies.dashboard/profile/modules/users`, `form743.worklist` |
| prodazba | `sales-invoices.*`, `sales-invoices.efaktura.*`, `invoice-settings.*`, `purchase-invoices.*`, `incoming-efaktura.*`, `other-costs.*`, `partners.*`, `inventory.*`, `documents.*`, `coming-soon` |
| finansii | `accounting.*`, `reports.*`, `bank-statements.*`, `form743.index`, `form743.download` |
| plata | `employees.*`, `payroll-runs.*`, `payroll.*`, `payroll-parameters.*` |

`require __DIR__.'/auth.php';` останува **надвор** од секоја домен-обвивка.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AppDomainRoutingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\PortalApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppDomainRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_sales_invoices_live_on_the_prodazba_host(): void
    {
        $company = Company::factory()->create();

        $this->assertStringStartsWith(
            'http://'.PortalApp::PRODAZBA->domain(),
            route('sales-invoices.index', $company)
        );
    }

    public function test_the_ledger_lives_on_the_finansii_host(): void
    {
        $company = Company::factory()->create();

        $this->assertStringStartsWith(
            'http://'.PortalApp::FINANSII->domain(),
            route('accounting.journal-groups.index', $company)
        );
    }

    public function test_payroll_lives_on_the_plata_host(): void
    {
        $company = Company::factory()->create();

        $this->assertStringStartsWith(
            'http://'.PortalApp::PLATA->domain(),
            route('payroll-runs.index', $company)
        );
    }

    public function test_company_settings_stay_on_the_portal(): void
    {
        $company = Company::factory()->create();

        $this->assertStringStartsWith(
            'http://'.PortalApp::PORTAL->domain(),
            route('companies.profile', $company)
        );
    }

    public function test_an_app_screen_is_not_served_by_the_portal_host(): void
    {
        $company = Company::factory()->create();

        $response = $this->actingAs($this->admin())
            ->get('http://'.PortalApp::PORTAL->domain()."/companies/{$company->id}/sales-invoices");

        $response->assertNotFound();
    }

    public function test_the_login_form_is_served_by_every_host(): void
    {
        foreach (PortalApp::cases() as $app) {
            $this->get('http://'.$app->domain().'/login')->assertOk();
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AppDomainRoutingTest`
Expected: FAIL — адресите немаат хост (`route()` враќа `http://localhost/...`).

- [ ] **Step 3: Wrap the route groups**

Во `routes/web.php`, по `use` блокот, воведи ги четирите обвивки и премести ги постојните групи **непроменети** во соодветната обвивка. Обликот:

```php
use App\Support\PortalApp;

Route::domain(PortalApp::PORTAL->domain())->group(function () {
    // Јавната влезна страна, dashboard, profile, companies.*, office,
    // efaktura.access-requests, form743.worklist — точно како што се сега.
});

Route::domain(PortalApp::PRODAZBA->domain())->group(function () {
    // sales-invoices.*, sales-invoices.efaktura.*, invoice-settings.*,
    // purchase-invoices.*, incoming-efaktura.*, other-costs.*, partners.*,
    // inventory.*, documents.*, coming-soon — точно како што се сега.
});

Route::domain(PortalApp::FINANSII->domain())->group(function () {
    // accounting.*, reports.*, bank-statements.*, form743.index, form743.download
});

Route::domain(PortalApp::PLATA->domain())->group(function () {
    // employees.*, payroll-runs.*, payroll.*, payroll-parameters.*
});

require __DIR__.'/auth.php';
```

Правила при преместувањето:

- Ништо во самите групи не се менува — ни `middleware`, ни `prefix`, ни `name`, ни коментарите над нив (тие објаснуваат зошто се во тој редослед).
- Редоследот внатре во `prodazba` мора да го задржи постојниот: `invoice-settings.` останува во своја група вон `sales-invoices.`, а `payroll.` (PDF/МПИН) останува **пред** `payroll-runs.` во `plata`.
- `form743.worklist` е работен список на канцеларијата над сите клиенти и оди во `portal`; `form743.index` и `form743.download` се по фирма и одат во `finansii`. Тоа значи дека групата `form743.` се дели на две — коментарот над неа се дополнува со оваа причина.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AppDomainRoutingTest`
Expected: PASS (6 тестови).

- [ ] **Step 5: Run the route list as a sanity check**

Run: `php artisan route:list --columns=domain,name | head -60`
Expected: секоја рута има домен освен `login`, `password.*`, `invitation.accept`, `verification.*`, `confirm-password`, `logout` и внатрешните `livewire/*`.

- [ ] **Step 6: Run the neighbouring suites that touch routes hardest**

Run: `php artisan test --filter="SalesInvoice|Payroll|Accounting"`
Expected: PASS. Ако падне тест што споредува точна адреса, се поправа очекуваната адреса во тестот (името на рутата останува исто).

- [ ] **Step 7: Commit**

```bash
git add routes/web.php tests/Feature/AppDomainRoutingTest.php
git commit -m "feat: рутите се делат по субдомејн на апликација"
```

---

### Task 3: Право по корисник (`users.app_*`)

**Files:**
- Create: `database/migrations/2026_09_15_000100_add_app_access_to_users_table.php`
- Modify: `app/Models/User.php`
- Create: `tests/Feature/AppAccessTest.php`

**Interfaces:**
- Consumes: `PortalApp` од Task 1.
- Produces: `User::canAccessApp(PortalApp $app): bool` и три колони `app_prodazba`, `app_finansii`, `app_plata` (`boolean NOT NULL DEFAULT true`).

- [ ] **Step 1: Write the failing test**

`tests/Feature/AppAccessTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PortalApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_user_may_enter_every_app(): void
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        foreach (PortalApp::workApps() as $app) {
            $this->assertTrue($user->canAccessApp($app), "Стандардно {$app->value} треба да е дозволен.");
        }
    }

    public function test_an_unticked_app_is_closed(): void
    {
        $user = User::factory()->create(['app_finansii' => false]);
        $user->assignRole('client');

        $this->assertFalse($user->canAccessApp(PortalApp::FINANSII));
        $this->assertTrue($user->canAccessApp(PortalApp::PRODAZBA));
    }

    public function test_an_admin_ignores_the_ticks(): void
    {
        $user = User::factory()->create([
            'app_prodazba' => false,
            'app_finansii' => false,
            'app_plata' => false,
        ]);
        $user->assignRole('admin');

        foreach (PortalApp::workApps() as $app) {
            $this->assertTrue($user->canAccessApp($app), 'Админ не смее да се заклучи сам.');
        }
    }

    public function test_the_portal_is_open_to_every_signed_in_user(): void
    {
        $user = User::factory()->create([
            'app_prodazba' => false,
            'app_finansii' => false,
            'app_plata' => false,
        ]);
        $user->assignRole('client');

        $this->assertTrue($user->canAccessApp(PortalApp::PORTAL));
    }

    public function test_a_fresh_unsaved_user_already_reads_as_allowed(): void
    {
        // Стандардната вредност на колоната во базата НЕ полни модел во меморија.
        $this->assertTrue((new User)->canAccessApp(PortalApp::PRODAZBA));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AppAccessTest`
Expected: FAIL — `Call to undefined method App\Models\User::canAccessApp()`.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_09_15_000100_add_app_access_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Стандардно вклучени: миграцијата не смее да одземе пристап на никого што
     * денес работи. Затворањето е свесен чекор на админот, не нуспојава.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('app_prodazba')->default(true);
            $table->boolean('app_finansii')->default(true);
            $table->boolean('app_plata')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['app_prodazba', 'app_finansii', 'app_plata']);
        });
    }
};
```

- [ ] **Step 4: Extend the `User` model**

Во `app/Models/User.php` додај го увозот `use App\Support\PortalApp;`, потоа:

```php
    /**
     * Стандардната вредност на колоната важи за базата, не за свежосоздаден
     * модел во меморија — без ова `new User` чита `null` и правото паѓа.
     */
    protected $attributes = [
        'app_prodazba' => true,
        'app_finansii' => true,
        'app_plata' => true,
    ];
```

во `casts()` додај:

```php
            'app_prodazba' => 'boolean',
            'app_finansii' => 'boolean',
            'app_plata' => 'boolean',
```

и метод:

```php
    /**
     * Смее ли овој човек да влезе во оваа апликација. Порталот е отворен за секој
     * најавен корисник, а админот ги прескокнува штиклирањата — не смее да се
     * заклучи сам од себе.
     */
    public function canAccessApp(PortalApp $app): bool
    {
        $column = $app->userColumn();

        if ($column === null || $this->hasRole('admin')) {
            return true;
        }

        return (bool) $this->{$column};
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AppAccessTest`
Expected: PASS (5 тестови).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_09_15_000100_add_app_access_to_users_table.php app/Models/User.php tests/Feature/AppAccessTest.php
git commit -m "feat: право по корисник за секоја апликација"
```

---

### Task 4: Менито се дели по апликација

**Files:**
- Modify: `app/Support/Menu.php`
- Modify: `tests/Unit/Support/MenuTest.php`
- Modify: `app/Livewire/Layout/Sidebar.php`

**Interfaces:**
- Consumes: `PortalApp` (Task 1).
- Produces:
  - `Menu::for(User $user, Company $company, PortalApp $app): list<array{key: string, label: string, items: list<array{label: string, url: string, pattern: string, soon: bool}>}>` — потписот добива трет задолжителен параметар.
  - `Menu::firstUrl(User $user, Company $company, PortalApp $app): ?string` — адресата на првиот екран што тој човек смее да го отвори во таа апликација (прво не-„наскоро“ ставка; ако ги нема, првата воопшто; `null` кога менито е празно).

**Распоред на групите по апликација:**

| Апликација | Групи (правно лице) | Групи (физичко лице) |
|---|---|---|
| prodazba | `sales`, `costs`, `stock`, `sales-settings` (ПОСТАВКИ: Фактурирање) | `sales`, `sales-settings` (ПОСТАВКИ: Фактурирање) |
| finansii | `finance`, `finance-settings` (ПОСТАВКИ: Контен план) | `bank` |
| plata | `payroll`, `payroll-settings` (ПОСТАВКИ: Параметри за плата) | `filings` |
| portal | `settings` (Компанија, е-Фактура барања) | `settings` (Профил) |

- [ ] **Step 1: Write the failing test**

Додај во `tests/Unit/Support/MenuTest.php` (постојните тестови се менуваат само со додавање на третиот параметар):

```php
    public function test_each_app_shows_only_its_own_groups(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $keys = fn (PortalApp $app) => array_column(Menu::for($admin, $company, $app), 'key');

        $this->assertSame(['sales', 'costs', 'stock', 'sales-settings'], $keys(PortalApp::PRODAZBA));
        $this->assertSame(['finance', 'finance-settings'], $keys(PortalApp::FINANSII));
        $this->assertSame(['payroll', 'payroll-settings'], $keys(PortalApp::PLATA));
        $this->assertSame(['settings'], $keys(PortalApp::PORTAL));
    }

    public function test_an_individual_sees_its_own_split(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $keys = fn (PortalApp $app) => array_column(Menu::for($admin, $company, $app), 'key');

        $this->assertSame(['sales', 'sales-settings'], $keys(PortalApp::PRODAZBA));
        $this->assertSame(['bank'], $keys(PortalApp::FINANSII));
        $this->assertSame(['filings'], $keys(PortalApp::PLATA));
    }

    public function test_first_url_skips_a_coming_soon_entry(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertSame(
            route('sales-invoices.index', $company),
            Menu::firstUrl($admin, $company, PortalApp::PRODAZBA)
        );
    }

    public function test_first_url_is_null_when_the_app_has_nothing_for_this_company(): void
    {
        $company = Company::factory()->create(['uses_payroll' => false]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertNull(Menu::firstUrl($admin, $company, PortalApp::PLATA));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=MenuTest`
Expected: FAIL — `Menu::for()` прима два параметра, `firstUrl()` не постои.

- [ ] **Step 3: Tag every group with its app and split the settings group**

Во `app/Support/Menu.php`:

1. Секоја група во `legalTree()` и `individualTree()` добива клуч `'app' => PortalApp::X`.
2. Групата `settings` во `legalTree()` се дели на четири:

```php
            [
                'key' => 'sales-settings',
                'app' => PortalApp::PRODAZBA,
                'label' => 'ПОСТАВКИ',
                'items' => [
                    ['label' => 'Фактурирање', 'url' => route('invoice-settings.index', $company), 'pattern' => 'invoice-settings.*', 'roles' => null, 'module' => CompanyModule::MATERIAL],
                ],
            ],
            [
                'key' => 'finance-settings',
                'app' => PortalApp::FINANSII,
                'label' => 'ПОСТАВКИ',
                'items' => [
                    ['label' => 'Контен план', 'url' => route('accounting.accounts.index', $company), 'pattern' => 'accounting.accounts.*', 'roles' => ['admin', 'accountant'], 'module' => CompanyModule::FINANCE],
                ],
            ],
            [
                'key' => 'payroll-settings',
                'app' => PortalApp::PLATA,
                'label' => 'ПОСТАВКИ',
                'items' => [
                    ['label' => 'Параметри за плата', 'url' => route('payroll-parameters.index', $company), 'pattern' => 'payroll-parameters.*', 'roles' => ['admin'], 'module' => CompanyModule::PAYROLL],
                ],
            ],
            [
                'key' => 'settings',
                'app' => PortalApp::PORTAL,
                'label' => 'ПОСТАВКИ',
                'items' => [
                    ['label' => 'Компанија', 'url' => route('companies.profile', $company), 'pattern' => 'companies.profile', 'roles' => null],
                    ['label' => 'е-Фактура барања', 'url' => route('efaktura.access-requests'), 'pattern' => 'efaktura.access-requests', 'roles' => ['admin']],
                ],
            ],
```

Истото во `individualTree()`: `sales` и нова `sales-settings` (Фактурирање) → `PRODAZBA`; `bank` → `FINANSII`; `filings` → `PLATA`; `settings` со само „Профил“ → `PORTAL`.

3. `for()` добива трет параметар и едно сито повеќе:

```php
    public static function for(User $user, Company $company, PortalApp $app): array
    {
        $groups = [];

        foreach (self::tree($company) as $group) {
            // Групите на туѓа апликација не се ни филтрираат — во оваа
            // апликација тие не постојат.
            if ($group['app'] !== $app) {
                continue;
            }

            // ... остатокот останува непроменет
```

4. Нов метод:

```php
    /**
     * Почетниот екран на апликацијата за овој човек: првата ставка што ја гледа.
     * „Наскоро“ ставка се прескокнува — таа е мапа на преостаната работа, лош
     * прв екран.
     */
    public static function firstUrl(User $user, Company $company, PortalApp $app): ?string
    {
        $items = array_merge(...array_column(self::for($user, $company, $app), 'items')) ?: [];

        foreach ($items as $item) {
            if (! $item['soon']) {
                return $item['url'];
            }
        }

        return $items[0]['url'] ?? null;
    }
```

`array_merge(...[])` фрла грешка на празен список, затоа `?: []` стои околу повикот — провери го тоа со тестот од Step 1 што очекува `null`.

- [ ] **Step 4: Make the sidebar pass the current app**

Во `app/Livewire/Layout/Sidebar.php`:

```php
    // Хостот е тој што ја одредува апликацијата, а /livewire/update доаѓа на
    // истиот хост — значи важи и при освежување на компонентата. Сепак се
    // запишува во својство при mount, за да не зависи render() од барањето.
    public string $appKey = 'portal';
```

во `mount()`, пред `$this->menu = ...`:

```php
        $app = PortalApp::fromHost(request()->getHost()) ?? PortalApp::PORTAL;
        $this->appKey = $app->value;
```

и:

```php
        $this->menu = Menu::for(auth()->user(), $this->company, $app);
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter="MenuTest|Sidebar"`
Expected: PASS. Постојните `MenuTest` тестови бараат дополнување со третиот параметар — тоа е дел од оваа задача.

- [ ] **Step 6: Commit**

```bash
git add app/Support/Menu.php app/Livewire/Layout/Sidebar.php tests/Unit/Support/MenuTest.php
git commit -m "feat: мени по апликација и почетен екран"
```

---

### Task 5: Браната `EnsureAppAccess` и екранот „Немате пристап“

**Files:**
- Create: `app/Http/Middleware/EnsureAppAccess.php`
- Create: `resources/views/errors/app-access.blade.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/AppAccessTest.php`

**Interfaces:**
- Consumes: `User::canAccessApp()` (Task 3), `Menu::for()` (Task 4), `PortalApp` (Task 1).
- Produces: middleware со потпис `EnsureAppAccess:{app}` што се лепи на трите домен-групи.

**Зошто проверката на модулот е „празно мени“, а не повик до `usesModule()`:** менито веќе ги знае модулот, улогата и типот клиент, и тоа е истата листа што ја гледа човекот. Втор список „која апликација бара кој модул“ би морал да се одржува паралелно и би ја одзел Кооперанти од фирма без Материјално — ставка што намерно нема модул.

- [ ] **Step 1: Write the failing test**

Додај во `tests/Feature/AppAccessTest.php`:

```php
    public function test_an_unticked_app_returns_the_no_access_screen(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id, 'app_finansii' => false]);
        $user->assignRole('accountant');
        $company->accountants()->attach($user->id);

        $response = $this->actingAs($user)->get(route('accounting.journal-groups.index', $company));

        $response->assertStatus(403);
        $response->assertSee('Немате пристап до оваа апликација');
    }

    public function test_a_switched_off_module_returns_the_no_access_screen(): void
    {
        $company = Company::factory()->create(['uses_payroll' => false]);
        $user = User::factory()->create();
        $user->assignRole('admin');

        $response = $this->actingAs($user)->get(route('employees.index', $company));

        $response->assertStatus(403);
        $response->assertSee('Овој модул не е вклучен за оваа фирма');
    }

    public function test_a_ticked_app_opens_normally(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user)->get(route('sales-invoices.index', $company))->assertOk();
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AppAccessTest`
Expected: FAIL — екранот не постои, првиот тест добива 200 наместо 403.

- [ ] **Step 3: Write the middleware**

`app/Http/Middleware/EnsureAppAccess.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\Menu;
use App\Support\PortalApp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Браната пред цела апликација. Апликацијата доаѓа како параметар на групата, не
 * од хостот — така не може да се измами преку адресата.
 *
 * Два клуча: правото на човекот (`users.app_*`) и тоа дали апликацијата воопшто
 * има што да покаже за оваа фирма. Второто се чита од менито, кое веќе ги знае
 * модулот, улогата и типот на клиент.
 */
class EnsureAppAccess
{
    public function handle(Request $request, Closure $next, string $app): Response
    {
        $portalApp = PortalApp::from($app);
        $user = $request->user();
        $company = $request->route('company');

        if ($user === null) {
            return $next($request);
        }

        if (! $user->canAccessApp($portalApp)) {
            return $this->denied($portalApp, 'Немате пристап до оваа апликација.');
        }

        if ($company instanceof Company && Menu::for($user, $company, $portalApp) === []) {
            return $this->denied($portalApp, 'Овој модул не е вклучен за оваа фирма.');
        }

        return $next($request);
    }

    private function denied(PortalApp $app, string $message): Response
    {
        return response()->view('errors.app-access', [
            'app' => $app,
            'message' => $message,
        ], 403);
    }
}
```

- [ ] **Step 4: Write the screen**

`resources/views/errors/app-access.blade.php`:

```blade
<x-guest-layout>
    <div class="text-center space-y-4">
        <h1 class="text-lg font-semibold text-gray-800">{{ $app->label() }}</h1>

        <p class="text-sm text-gray-600">{{ $message }}</p>

        <a href="{{ route('companies.index') }}"
           class="inline-block px-4 py-2 rounded-lg bg-brand text-white text-sm font-medium">
            Назад кон порталот
        </a>
    </div>
</x-guest-layout>
```

`x-guest-layout` се користи намерно: екранот не смее да бара сајдбар на апликација во која човекот нема право.

- [ ] **Step 5: Apply the middleware to the three domain groups**

Во `routes/web.php`, на секоја од трите домен-обвивки (не на порталот):

```php
Route::domain(PortalApp::PRODAZBA->domain())->middleware(EnsureAppAccess::class.':prodazba')->group(function () {
```

исто за `finansii` и `plata`, со увоз `use App\Http\Middleware\EnsureAppAccess;`.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=AppAccessTest`
Expected: PASS (8 тестови).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/EnsureAppAccess.php resources/views/errors/app-access.blade.php routes/web.php tests/Feature/AppAccessTest.php
git commit -m "feat: брана по апликација со екран Немате пристап"
```

---

### Task 6: Панел „АПЛИКАЦИИ“

**Files:**
- Create: `app/Support/AppSwitcher.php`
- Create: `tests/Feature/AppSwitcherTest.php`
- Modify: `resources/views/livewire/layout/navigation.blade.php`

**Interfaces:**
- Consumes: `Menu::firstUrl()` (Task 4), `User::canAccessApp()` (Task 3).
- Produces: `AppSwitcher::for(User $user, ?Company $company): list<array{key: string, label: string, url: string, accent: string}>` — само апликациите што овој човек смее да ги отвори за оваа фирма и што имаат барем еден екран. Без фирма враќа празен список.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AppSwitcherTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\AppSwitcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppSwitcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_sees_all_three_apps(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertSame(
            ['prodazba', 'finansii', 'plata'],
            array_column(AppSwitcher::for($admin, $company), 'key')
        );
    }

    public function test_an_unticked_app_is_absent_not_greyed(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id, 'app_plata' => false]);
        $user->assignRole('client');

        $this->assertSame(
            ['prodazba'],
            array_column(AppSwitcher::for($user, $company), 'key'),
            'Клиент не гледа Финансии (книжењето е на канцеларијата) ниту исклучена Плата.'
        );
    }

    public function test_an_app_without_screens_for_this_company_is_absent(): void
    {
        $company = Company::factory()->create(['uses_payroll' => false]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertSame(
            ['prodazba', 'finansii'],
            array_column(AppSwitcher::for($admin, $company), 'key')
        );
    }

    public function test_without_a_company_the_list_is_empty(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertSame([], AppSwitcher::for($admin, null));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AppSwitcherTest`
Expected: FAIL — `Class "App\Support\AppSwitcher" not found`.

- [ ] **Step 3: Write `AppSwitcher`**

```php
<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;

/**
 * Кои апликации му се отворени на овој човек за оваа фирма. Го користат и
 * панелот „АПЛИКАЦИИ“ и плочките на порталот, па правилото живее на едно место.
 *
 * Апликација без ниту еден екран не се прикажува — плочка што води кон 403 е
 * полоша од отсутна плочка.
 */
class AppSwitcher
{
    /** @return list<array{key: string, label: string, url: string, accent: string}> */
    public static function for(User $user, ?Company $company): array
    {
        if ($company === null) {
            return [];
        }

        $apps = [];

        foreach (PortalApp::workApps() as $app) {
            if (! $user->canAccessApp($app)) {
                continue;
            }

            $url = Menu::firstUrl($user, $company, $app);

            if ($url === null) {
                continue;
            }

            $apps[] = [
                'key' => $app->value,
                'label' => $app->label(),
                'url' => $url,
                'accent' => $app->accent(),
            ];
        }

        return $apps;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AppSwitcherTest`
Expected: PASS (4 тестови).

- [ ] **Step 5: Add the button and the right-hand panel**

Во `resources/views/livewire/layout/navigation.blade.php`:

1. Во PHP делот на Volt компонентата, додај:

```php
use App\Models\Company;
use App\Support\AppSwitcher;
use App\Support\PortalApp;
```

и во класата:

```php
    /** @var list<array{key: string, label: string, url: string, accent: string}> */
    public array $apps = [];

    public string $currentApp = 'portal';

    public function mount(): void
    {
        $company = request()->route('company');

        $this->currentApp = (PortalApp::fromHost(request()->getHost()) ?? PortalApp::PORTAL)->value;
        $this->apps = AppSwitcher::for(auth()->user(), $company instanceof Company ? $company : null);
    }
```

2. Во `<nav x-data="{ open: false }"` замени го со `x-data="{ open: false, appsOpen: false }"`.

3. Веднаш пред `<!-- Settings Dropdown -->` додај го копчето:

```blade
            @if ($apps !== [])
                <button type="button" @click="appsOpen = true"
                        class="inline-flex items-center gap-2 px-3 py-2 me-2 text-xs font-semibold tracking-wide text-gray-600 rounded-lg hover:bg-gray-100 transition">
                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M3 3h4v4H3V3zm6 0h4v4H9V3zm6 0h2v4h-2V3zM3 9h4v4H3V9zm6 0h4v4H9V9zm6 0h2v4h-2V9zM3 15h4v2H3v-2zm6 0h4v2H9v-2zm6 0h2v2h-2v-2z" />
                    </svg>
                    АПЛИКАЦИИ
                </button>
            @endif
```

4. Пред затворањето на `</nav>` додај го панелот:

```blade
    {{-- Панелот влегува од десно. Истите правила како мобилната фиока: Esc,
         клик надвор и копче го затвораат. --}}
    <div x-show="appsOpen" x-cloak @keydown.escape.window="appsOpen = false">
        <div x-transition.opacity @click="appsOpen = false" class="fixed inset-0 z-40 bg-gray-900/50"></div>

        <div x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
             class="fixed inset-y-0 right-0 z-50 w-72 bg-white border-l border-gray-100 p-4 space-y-2">
            <div class="flex items-center justify-between pb-2 border-b border-gray-100">
                <span class="text-xs font-semibold tracking-wide text-gray-500">АПЛИКАЦИИ</span>
                <button type="button" @click="appsOpen = false" aria-label="Затвори"
                        class="p-2 -me-2 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition">
                    <svg class="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            @foreach ($apps as $app)
                <a href="{{ $app['url'] }}"
                   class="flex items-center justify-between px-3 py-2 rounded-lg text-sm {{ $app['key'] === $currentApp ? 'bg-orange-50 font-semibold' : 'hover:bg-gray-50' }}">
                    <span class="{{ $app['accent'] }}">{{ $app['label'] }}</span>
                    @if ($app['key'] === $currentApp)
                        <span class="text-[10px] uppercase tracking-wide text-gray-400">тука си</span>
                    @endif
                </a>
            @endforeach

            <a href="{{ route('companies.index') }}"
               class="block px-3 py-2 mt-2 rounded-lg text-sm text-gray-600 border-t border-gray-100 hover:bg-gray-50">
                Портал — фирми и поставки
            </a>
        </div>
    </div>
```

Врските намерно **немаат** `wire:navigate`: преминот е кон друг хост, а `wire:navigate` работи во рамките на еден origin.

- [ ] **Step 6: Rebuild the assets and check the panel in a browser**

Run: `npm run build`
Потоа отвори ја апликацијата и провери: копчето се гледа горе десно, панелот влегува од десно, `Esc` и клик надвор го затвораат, изборот носи на друга апликација со иста фирма.

- [ ] **Step 7: Commit**

```bash
git add app/Support/AppSwitcher.php tests/Feature/AppSwitcherTest.php resources/views/livewire/layout/navigation.blade.php public/build
git commit -m "feat: панел АПЛИКАЦИИ во заглавието"
```

---

### Task 7: Плочки со апликации на порталот

**Files:**
- Modify: `app/Livewire/CompanyDashboard.php`
- Modify: `resources/views/livewire/company-dashboard.blade.php`
- Create: `tests/Feature/CompanyDashboardAppTilesTest.php`

**Interfaces:**
- Consumes: `AppSwitcher::for()` (Task 6).
- Produces: променлива `$apps` во погледот `livewire.company-dashboard`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/CompanyDashboardAppTilesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyDashboardAppTilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_shows_a_tile_for_every_open_app(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('companies.dashboard', $company));

        $response->assertOk();
        $response->assertSee('Продажба');
        $response->assertSee('Финансии');
        $response->assertSee('Плата');
    }

    public function test_a_switched_off_module_has_no_tile(): void
    {
        $company = Company::factory()->create(['uses_payroll' => false]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('companies.dashboard', $company));

        $response->assertOk();
        // Се бара адресата на плочката, не зборот „Плата" — тој збор се
        // појавува и на друго место на таблата.
        $response->assertDontSee(route('employees.index', $company));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CompanyDashboardAppTilesTest`
Expected: FAIL — вториот тест паѓа (нема плочки, но „Плата“ може да се појави од друг текст на таблата; ако првиот помине случајно, тоа го покажува токму вториот).

- [ ] **Step 3: Pass the list into the view**

Во `app/Livewire/CompanyDashboard.php` додај `use App\Support\AppSwitcher;` и во **двата** `return view(...)` повика (физичко и правно лице) додај:

```php
                'apps' => AppSwitcher::for(auth()->user(), $this->company),
```

- [ ] **Step 4: Render the tiles at the top of the dashboard**

На почетокот на `resources/views/livewire/company-dashboard.blade.php`, пред постојната содржина:

```blade
    @if ($apps !== [])
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">
            @foreach ($apps as $app)
                <a href="{{ $app['url'] }}"
                   class="block rounded-xl border border-gray-100 bg-white p-4 hover:border-gray-200 hover:shadow-sm transition">
                    <div class="text-sm font-semibold {{ $app['accent'] }}">{{ $app['label'] }}</div>
                    <div class="text-xs text-gray-500 mt-1">Отвори ја апликацијата</div>
                </a>
            @endforeach
        </div>
    @endif
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=CompanyDashboardAppTilesTest`
Expected: PASS (2 тестови).

- [ ] **Step 6: Rebuild assets**

Run: `npm run build`

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/CompanyDashboard.php resources/views/livewire/company-dashboard.blade.php tests/Feature/CompanyDashboardAppTilesTest.php public/build
git commit -m "feat: плочки со апликации на таблата на фирмата"
```

---

### Task 8: Квадратчиња за апликации кај Корисници и Канцеларија

**Files:**
- Create: `app/Livewire/Concerns/TogglesAppAccess.php`
- Modify: `app/Livewire/CompanyUsers.php`
- Modify: `resources/views/livewire/company-users.blade.php`
- Modify: `app/Livewire/OfficeUsers.php`
- Modify: `resources/views/livewire/office-users.blade.php`
- Create: `tests/Feature/UserAppAccessToggleTest.php`

**Interfaces:**
- Consumes: `PortalApp::workApps()`, `User::canAccessApp()`.
- Produces: `TogglesAppAccess` (trait) со јавен `toggleApp(int $userId, string $app): void` и апстрактен `appAccessTarget(int $userId): User`, што двете компоненти го исполнуваат со својот постоен опсег (`companyUser()` односно `officeUser()`).

Двете компоненти го делат истото правило, па тоа живее во особина, како постојната `App\Livewire\Concerns\SendsInvitations`. Разликува само опсегот — кој корисник смее да се допре.

- [ ] **Step 1: Write the failing test**

`tests/Feature/UserAppAccessToggleTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Livewire\CompanyUsers;
use App\Livewire\OfficeUsers;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserAppAccessToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_closes_an_app_for_a_client(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        Livewire::actingAs($admin)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('toggleApp', $client->id, 'plata');

        $this->assertFalse($client->fresh()->app_plata);
    }

    public function test_the_same_call_switches_it_back_on(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $client = User::factory()->create(['company_id' => $company->id, 'app_plata' => false]);
        $client->assignRole('client');

        Livewire::actingAs($admin)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('toggleApp', $client->id, 'plata');

        $this->assertTrue($client->fresh()->app_plata);
    }

    public function test_a_user_from_another_company_cannot_be_touched(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $stranger = User::factory()->create(['company_id' => $other->id]);
        $stranger->assignRole('client');

        Livewire::actingAs($admin)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('toggleApp', $stranger->id, 'plata')
            ->assertStatus(404);

        $this->assertTrue($stranger->fresh()->app_plata);
    }

    public function test_a_client_cannot_open_an_app_for_itself(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id, 'app_plata' => false]);
        $client->assignRole('client');

        Livewire::actingAs($client)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('toggleApp', $client->id, 'plata')
            ->assertStatus(403);

        $this->assertFalse($client->fresh()->app_plata);
    }

    public function test_an_admin_closes_an_app_for_an_accountant(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');

        Livewire::actingAs($admin)
            ->test(OfficeUsers::class)
            ->call('toggleApp', $accountant->id, 'finansii');

        $this->assertFalse($accountant->fresh()->app_finansii);
    }

    public function test_an_unknown_app_name_is_refused(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        Livewire::actingAs($admin)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('toggleApp', $client->id, 'portal')
            ->assertStatus(403);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UserAppAccessToggleTest`
Expected: FAIL — `Method toggleApp does not exist`.

- [ ] **Step 3: Write the shared concern**

`app/Livewire/Concerns/TogglesAppAccess.php`:

```php
<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use App\Support\PortalApp;
use Illuminate\Support\Facades\Gate;

/**
 * Штиклирањето „во која апликација влегува овој човек". Правилото е исто на
 * двата екрана; разликува само опсегот — кој корисник смее да се допре — па него
 * го дава компонентата преку appAccessTarget().
 */
trait TogglesAppAccess
{
    public function toggleApp(int $userId, string $app): void
    {
        Gate::authorize('create', User::class);

        $portalApp = PortalApp::tryFrom($app);

        // Порталот нема квадратче: најавен човек мора да има каде да влезе.
        abort_if($portalApp === null || $portalApp->userColumn() === null, 403);

        $user = $this->appAccessTarget($userId);
        $column = $portalApp->userColumn();

        $user->forceFill([$column => ! $user->{$column}])->save();
    }

    /**
     * Корисникот што овој екран смее да го менува. Мора да фрли (404/403) за
     * секој што е надвор од неговиот опсег.
     */
    abstract protected function appAccessTarget(int $userId): User;
}
```

- [ ] **Step 4: Wire the concern into both components**

Во `app/Livewire/CompanyUsers.php`: `use TogglesAppAccess;` покрај `SendsInvitations`, увоз `use App\Livewire\Concerns\TogglesAppAccess;`, и:

```php
    protected function appAccessTarget(int $userId): User
    {
        return $this->companyUser($userId);
    }
```

Во `app/Livewire/OfficeUsers.php`: истото, но:

```php
    protected function appAccessTarget(int $userId): User
    {
        return $this->officeUser($userId);
    }
```

- [ ] **Step 5: Add the checkboxes to both views**

Во `resources/views/livewire/company-users.blade.php` и `resources/views/livewire/office-users.blade.php`, во редот на секој корисник (нова колона „Апликации“ во заглавието на табелата):

```blade
                        <td class="px-3 py-2">
                            <div class="flex items-center gap-3">
                                @foreach (\App\Support\PortalApp::workApps() as $app)
                                    <label class="inline-flex items-center gap-1 text-xs text-gray-600">
                                        <input type="checkbox"
                                               wire:click="toggleApp({{ $user->id }}, '{{ $app->value }}')"
                                               @checked($user->{$app->userColumn()})
                                               class="rounded border-gray-300 text-brand focus:ring-brand">
                                        {{ $app->label() }}
                                    </label>
                                @endforeach
                            </div>
                        </td>
```

Во заглавието на истата табела додај `<th class="px-3 py-2 text-left">Апликации</th>` на истото место.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=UserAppAccessToggleTest`
Expected: PASS (6 тестови).

- [ ] **Step 7: Rebuild assets and check both screens in a browser**

Run: `npm run build`
Провери: квадратчињата се појавуваат кај Поставки → Корисници и кај Канцеларија, кликот останува запишан по освежување.

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/CompanyUsers.php app/Livewire/OfficeUsers.php resources/views/livewire/company-users.blade.php resources/views/livewire/office-users.blade.php tests/Feature/UserAppAccessToggleTest.php public/build
git commit -m "feat: штиклирање апликации кај Корисници и Канцеларија"
```

---

### Task 9: Име и боја по апликација во сајдбарот

**Files:**
- Modify: `app/Livewire/Layout/Sidebar.php`
- Modify: `resources/views/livewire/layout/sidebar.blade.php`
- Create: `tests/Feature/SidebarAppBrandingTest.php`

**Interfaces:**
- Consumes: `$appKey` од Task 4, `PortalApp::label()`, `PortalApp::accent()`.
- Produces: заглавие „FinanceBuddy {име на апликација}“ и врска што води на почетниот екран на истата апликација.

- [ ] **Step 1: Write the failing test**

`tests/Feature/SidebarAppBrandingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\PortalApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarAppBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sidebar_names_the_app_it_is_in(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('payroll-runs.index', $company));

        $response->assertOk();
        $response->assertSee('FinanceBuddy Плата');
    }

    public function test_the_portal_keeps_the_plain_name(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('companies.dashboard', $company));

        $response->assertOk();
        $response->assertDontSee('FinanceBuddy Плата');
    }

    public function test_the_office_wide_links_show_only_on_the_portal(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('sales-invoices.index', $company))->assertDontSee('743 обрасци');
        $this->actingAs($admin)->get(route('companies.dashboard', $company))->assertSee('743 обрасци');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SidebarAppBrandingTest`
Expected: FAIL — заглавието го пишува само `config('app.name')`, а „743 обрасци“ се гледа на секој хост.

- [ ] **Step 3: Expose the app on the component**

Во `app/Livewire/Layout/Sidebar.php` додај:

```php
    public function app(): PortalApp
    {
        return PortalApp::from($this->appKey);
    }
```

- [ ] **Step 4: Use it in the view**

Во `resources/views/livewire/layout/sidebar.blade.php`:

1. Заглавието:

```blade
        <a href="{{ $this->app() === \App\Support\PortalApp::PORTAL ? route('dashboard') : ($company ? \App\Support\Menu::firstUrl(auth()->user(), $company, $this->app()) : route('dashboard')) }}"
           class="font-bold text-sm {{ $this->app()->accent() }}">
            {{ config('app.name', 'Laravel') }}@if ($this->app() !== \App\Support\PortalApp::PORTAL) {{ $this->app()->label() }}@endif
        </a>
```

2. Трите глобални врски (`Почетна`, `Фирми`, `743 обрасци`) се затвораат во еден услов — тие се екрани на порталот:

```blade
        @if ($this->app() === \App\Support\PortalApp::PORTAL)
            ... постојните три блока непроменети ...
        @endif
```

3. Врската „Документи“ на дното останува, но само во Продажба:

```blade
                @if (! $company->type->isIndividual() && $this->app() === \App\Support\PortalApp::PRODAZBA)
```

4. Избирачот на фирма **останува непроменет** — и понатаму води на `companies.dashboard`, значи на порталот. Причината се запишува како коментар над `<select>`:

```blade
                    {{-- Менувањето фирма секогаш се враќа на порталната табла.
                         Новата фирма може да ги нема истите модули, па почетниот
                         екран на тековната апликација за неа може и да не
                         постои — таблата е единствената адреса што сигурно
                         постои за секоја фирма. --}}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=SidebarAppBrandingTest`
Expected: PASS (3 тестови).

- [ ] **Step 6: Rebuild assets**

Run: `npm run build`

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Layout/Sidebar.php resources/views/livewire/layout/sidebar.blade.php tests/Feature/SidebarAppBrandingTest.php public/build
git commit -m "feat: име и боја по апликација во сајдбарот"
```

---

### Task 10: Најавата враќа таму каде што човекот дошол

**Files:**
- Create: `app/Support/LandingUrl.php`
- Create: `tests/Feature/LoginLandingTest.php`
- Modify: `resources/views/livewire/pages/auth/login.blade.php`
- Modify: `resources/views/livewire/pages/auth/accept-invitation.blade.php`
- Modify: `resources/views/livewire/pages/auth/confirm-password.blade.php`
- Modify: `resources/views/livewire/pages/auth/verify-email.blade.php`
- Modify: `app/Http/Controllers/Auth/VerifyEmailController.php`

**Interfaces:**
- Consumes: `Menu::firstUrl()` (Task 4), `PortalApp::fromHost()` (Task 1), `User::visibleCompanies()` (постои).
- Produces: `LandingUrl::for(User $user, ?PortalApp $app): string` — апсолутна адреса каде оди човек што нема барана адреса.

**Зошто задачата е нужна:** шест места денес пренасочуваат на `route('dashboard', absolute: false)`, значи на **патека без хост**. На `plata.financebuddy.mk` таа патека се решава кон истиот хост, каде рутата `dashboard` не постои — најавата би завршувала со 404. Сите шест мора да станат апсолутни.

- [ ] **Step 1: Write the failing test**

`tests/Feature/LoginLandingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\LandingUrl;
use App\Support\PortalApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginLandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_lands_in_the_app_it_signed_into(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->assertSame(
            route('sales-invoices.index', $company),
            LandingUrl::for($client, PortalApp::PRODAZBA)
        );
    }

    public function test_an_accountant_with_many_companies_lands_on_the_portal(): void
    {
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        Company::factory()->count(2)->create()
            ->each(fn (Company $company) => $company->accountants()->attach($accountant->id));

        $this->assertSame(route('dashboard'), LandingUrl::for($accountant, PortalApp::PRODAZBA));
    }

    public function test_the_portal_host_always_lands_on_the_portal(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->assertSame(route('dashboard'), LandingUrl::for($client, PortalApp::PORTAL));
    }

    public function test_a_closed_app_falls_back_to_the_portal(): void
    {
        $company = Company::factory()->create(['uses_payroll' => false]);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->assertSame(route('dashboard'), LandingUrl::for($client, PortalApp::PLATA));
    }

    public function test_every_landing_url_carries_a_host(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        foreach (PortalApp::cases() as $app) {
            $this->assertStringStartsWith('http://', LandingUrl::for($client, $app));
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LoginLandingTest`
Expected: FAIL — `Class "App\Support\LandingUrl" not found`.

- [ ] **Step 3: Write `LandingUrl`**

```php
<?php

namespace App\Support;

use App\Models\User;

/**
 * Каде оди човек што нема барана адреса. Барана адреса (`redirectIntended`)
 * секогаш победува — ова е само резервата.
 *
 * Со точно една видлива фирма (случајот на клиент) најавата на субдомејн
 * останува во таа апликација. Со повеќе фирми нема што да се погоди, па се оди
 * на порталот, каде човекот бира фирма.
 */
class LandingUrl
{
    public static function for(User $user, ?PortalApp $app): string
    {
        if ($app === null || $app === PortalApp::PORTAL) {
            return route('dashboard');
        }

        $companies = $user->visibleCompanies()->limit(2)->get();

        if ($companies->count() !== 1) {
            return route('dashboard');
        }

        return Menu::firstUrl($user, $companies->first(), $app) ?? route('dashboard');
    }
}
```

- [ ] **Step 4: Use it in the four Volt pages**

Во `login.blade.php`, `confirm-password.blade.php` и `verify-email.blade.php` замени го редот:

```php
        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
```

со:

```php
        $this->redirectIntended(
            default: LandingUrl::for(auth()->user(), PortalApp::fromHost(request()->getHost())),
            navigate: false,
        );
```

Во `accept-invitation.blade.php` замени:

```php
        $this->redirect(route('dashboard', absolute: false), navigate: true);
```

со:

```php
        $this->redirect(
            LandingUrl::for(auth()->user(), PortalApp::fromHost(request()->getHost())),
            navigate: false,
        );
```

Во сите четири додај `use App\Support\LandingUrl;` и `use App\Support\PortalApp;`.

`navigate: false` е задолжително: пренасочувањето може да води на друг хост, а `wire:navigate` работи само во рамките на еден origin.

- [ ] **Step 5: Fix `VerifyEmailController`**

И двата реда:

```php
            return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
```

стануваат:

```php
            return redirect()->intended(route('dashboard').'?verified=1');
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter="LoginLandingTest|Auth"`
Expected: PASS. Тестови што очекуваат `/dashboard` како патека се поправаат да очекуваат `route('dashboard')`.

- [ ] **Step 7: Commit**

```bash
git add app/Support/LandingUrl.php tests/Feature/LoginLandingTest.php resources/views/livewire/pages/auth/ app/Http/Controllers/Auth/VerifyEmailController.php
git commit -m "feat: најавата враќа во апликацијата на која е направена"
```

---

### Task 11: Заедничка сесија, упатство за серверот и цела серија

**Files:**
- Modify: `.env.example`
- Create: `docs/superpowers/2026-09-15-app-subdomains-deploy.md`
- Modify: `docs/superpowers/specs/2026-09-15-app-subdomains-design.md` (само ако нешто се разминало при изведбата)

**Interfaces:**
- Consumes: сѐ претходно.
- Produces: упатство со точните команди за droplet-от и потврда дека целата серија е зелена.

- [ ] **Step 1: Add the session domain to `.env.example`**

Под `SESSION_DOMAIN=null` замени со:

```
# Со точка напред: колачето важи и за portal, prodazba, finansii и plata.
SESSION_DOMAIN=null
```

(вредноста во примерот останува `null` — вистинската се внесува само на серверот.)

- [ ] **Step 2: Write the deployment note**

`docs/superpowers/2026-09-15-app-subdomains-deploy.md` со точно четирите чекора што ги прави сопственикот **на droplet-от преку SSH** (DNS записите се кај регистрарот, не на серверот):

1. DNS: `A` записи `prodazba`, `finansii`, `plata` → `46.101.177.209`.
2. Apache: `ServerAlias prodazba.financebuddy.mk finansii.financebuddy.mk plata.financebuddy.mk` во постојниот vhost, потоа `apachectl configtest` и `systemctl reload apache2`.
3. Сертификат: `certbot --apache -d portal.financebuddy.mk -d prodazba.financebuddy.mk -d finansii.financebuddy.mk -d plata.financebuddy.mk`.
4. `.env`: `SESSION_DOMAIN=.financebuddy.mk` и четирите `APP_DOMAIN_*` со вистинските имиња, потоа `php artisan config:clear && php artisan config:cache`.

Секој чекор во документот пишува **на која машина** се пушта и што треба да се види ако успеал.

- [ ] **Step 3: Run the whole suite**

Run: `php artisan test`
Expected: PASS, ~1480 теста (1474 постојни + новите), ~8.5 минути. Не се пушта додека друг агент менува фајлови.

- [ ] **Step 4: Fix what the suite finds**

Очекувани поправки: тестови што споредуваат точна адреса на пренасочување и повици на `Menu::for()` со два параметра. Името на рутата останува исто — се менува очекувањето во тестот, не рутата.

- [ ] **Step 5: Commit**

```bash
git add .env.example docs/superpowers/2026-09-15-app-subdomains-deploy.md
git commit -m "docs: упатство за пуштање на субдомејните"
```

---

## Проверка пред спојување

- [ ] Целата серија е зелена (`php artisan test`).
- [ ] `npm run build` е пуштен последен пат по сите измени на Blade.
- [ ] Во прелистувач, на секоја од трите адреси: екранот се отвора, сајдбарот е на таа апликација, панелот АПЛИКАЦИИ работи, а Livewire реагира (кликни на нешто што праќа `wire:click` — ако `/livewire/update` фати домен, страницата ќе молчи наместо да пукне).
- [ ] Најава директно на `plata` враќа на екран од Плата, не на порталот.
- [ ] Клиент со исклучена Плата ја нема плочката, ја нема во панелот и добива „Немате пристап“ на директна адреса.
