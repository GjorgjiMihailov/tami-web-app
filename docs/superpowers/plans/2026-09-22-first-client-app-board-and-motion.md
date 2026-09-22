# Прв клиент, табла на Продажба и движење насекаде — план за изведба

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сметководител без фирма да може сам да го внесе првиот клиент; Продажба да се отвора на своја табла со три копчиња и три табели; сите списоци и панелот АПЛИКАЦИИ да добијат заедничко движење.

**Architecture:** Создавањето фирма се вади во `App\Services\CompanyCreator` за да го делат двата екрана. Правото за создавање се отвора тесно во `CompanyPolicy::create` и само по себе се затвора. Таблата на Продажба е нов Livewire екран на доменот на Продажба, а ново место `Menu::landingUrl()` одлучува каде се влегува во апликација. Движењето е CSS во `resources/css/app.css`, закачено на една нова класа `app-main` на `<main>` во заедничкиот изглед.

**Tech Stack:** Laravel 12, Livewire 3, Alpine, Tailwind (JIT), PHPUnit преку `php artisan test`, Spatie Permission за улоги.

Спецификација: `docs/superpowers/specs/2026-09-22-first-client-app-board-and-motion-design.md`

## Global Constraints

- Сите видливи низи се на македонски. Никогаш бугарски облици.
- Тестовите се пуштаат со `php artisan test --filter=ИмеНаТест`. Целата серија еднаш пред спојување, со `php artisan test`.
- **Blade готча 1:** `@foreach (... as $x)` ја презапишува променливата и по јамката — Blade нема опсег на јамка. Променливи за класи носат долги имиња (`$lineLabelClass`, не `$label`).
- **Blade готча 2:** `@disabled(...)` распослана низ повеќе редови внатре во ознака на Blade компонента НЕ се компајлира. Користи `:disabled="израз"` или држи ја во еден ред.
- **Тест готча:** Livewire `->assertSee('текст')` гледа во исчистен текст и не забележува ни неисцртана компонента ни погрешна класа. Секој нов екран со Blade компоненти добива `->assertSee('...', false)` за конкретна класа и `->assertDontSee('<x-', false)`.
- **CSS готча:** `animation-fill-mode` оди на `backwards`, не `both`. Со `both` завршната рамка го заклучува `transform` и подигнувањето при покажување престанува.
- **CSS готча 2:** во `resources/css/app.css` постојат ДВА `@media (prefers-reduced-motion: reduce)` блока. Општиот (на дното) само ја скратува траењето на `0.01ms`; анимација со `animation-delay` и `backwards` под него останува НЕВИДЛИВА додека чека. Секоја нова анимација со задоцнување МОРА да се додаде и во првиот блок, оној со `animation: none`.
- **Tailwind готча:** по сите промени во Blade, `npm run build` пред рачна проверка во прелистувач — JIT чита текст од шаблоните.
- Секој commit завршува со `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- Гранката е `prv-klient-tabla-dvizenje`, веќе создадена, со спецификацијата на неа.

---

## Структура на фајлови

**Се создава:**

| Фајл | Одговорност |
|---|---|
| `app/Services/CompanyCreator.php` | Едно место што создава фирма со сите вредности што зависат од типот |
| `app/Livewire/FirstClient.php` | Екранот за внесување на првиот клиент |
| `resources/views/livewire/first-client.blade.php` | Неговиот изглед |
| `app/Livewire/Apps/SalesDashboard.php` | Таблата на Продажба |
| `resources/views/livewire/apps/sales-dashboard.blade.php` | Нејзиниот изглед |
| `tests/Unit/Services/CompanyCreatorTest.php` | |
| `tests/Feature/FirstClientTest.php` | |
| `tests/Feature/SalesDashboardTest.php` | |
| `tests/Feature/MotionKitTest.php` | |

**Се менува:**

| Фајл | Што |
|---|---|
| `app/Livewire/CompanyIndex.php` | `addCompany()` го вика `CompanyCreator` |
| `app/Policies/CompanyPolicy.php` | `create()` се отвора за сметководител без фирма |
| `app/Livewire/Dashboard.php` | пренасочување кон екранот за прв клиент |
| `app/Support/Menu.php` | нов `landingUrl()` |
| `app/Support/AppSwitcher.php` | вика `landingUrl` наместо `firstUrl` |
| `app/Support/LandingUrl.php` | исто |
| `app/Livewire/Layout/Sidebar.php` | `brandUrl` исто; ново својство `boardUrl` |
| `resources/views/livewire/layout/sidebar.blade.php` | врска „Табла" над групите |
| `resources/views/livewire/layout/navigation.blade.php` | нов изглед на панелот АПЛИКАЦИИ |
| `resources/views/layouts/app.blade.php` | класа `app-main` и прекинувачот `motion-in` на `<main>` |
| `resources/css/app.css` | комплетот за движење и картичките во панелот |
| `routes/web.php` | две нови рути |
| `tests/Feature/DashboardTest.php` | новиот случај „сметководител без фирма" |
| `tests/Feature/AppSwitcherTest.php` | Продажба сега враќа табла |

---

## Task 1: `CompanyCreator` — создавањето фирма на едно место

**Files:**
- Create: `app/Services/CompanyCreator.php`
- Create: `tests/Unit/Services/CompanyCreatorTest.php`
- Modify: `app/Livewire/CompanyIndex.php` (методот `addCompany()`)

**Interfaces:**
- Consumes: ништо
- Produces: `App\Services\CompanyCreator::create(string $name, CompanyType $type, ?string $taxId = null, ?string $embg = null): Company` — статична метода. Task 3 ја вика од `FirstClient`.

Ова е чиста преселба без промена на однесувањето. Постојните тестови во `CompanyIndexTest` мора да останат зелени без ниту една измена во нив — тоа е доказот дека преселбата е точна.

- [ ] **Step 1: Напиши го тестот што паѓа**

Создај `tests/Unit/Services/CompanyCreatorTest.php`:

```php
<?php

namespace Tests\Unit\Services;

use App\Models\Company;
use App\Services\CompanyCreator;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyCreatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_legal_entity_gets_a_tax_id_and_is_a_vat_payer(): void
    {
        $company = CompanyCreator::create('ТЕСТ ДООЕЛ', CompanyType::LEGAL, '4080012345678', '');

        $this->assertSame('ТЕСТ ДООЕЛ', $company->name);
        $this->assertTrue($company->type->isLegal());
        $this->assertSame('4080012345678', $company->tax_id);
        $this->assertNull($company->embg);
        $this->assertTrue($company->is_vat_registered);
    }

    public function test_an_individual_gets_an_embg_and_is_not_a_vat_payer(): void
    {
        $company = CompanyCreator::create('Петар Петров', CompanyType::INDIVIDUAL, '', '0101990450006');

        $this->assertTrue($company->type->isIndividual());
        $this->assertNull($company->tax_id);
        $this->assertSame('0101990450006', $company->embg);
        $this->assertFalse(
            $company->is_vat_registered,
            'Физичко лице не смее да остане ДДВ обврзник — инаку на фактурата излегува ДДВ што не постои.'
        );
    }

    public function test_the_type_dependent_defaults_are_set_on_the_instance_not_left_to_the_database(): void
    {
        // Стандардна вредност на колона во базата НЕ полни свежо создаден модел
        // во меморија. Затоа се проверува вратениот примерок, не повторно
        // прочитаниот ред.
        $company = CompanyCreator::create('ТЕСТ ДООЕЛ', CompanyType::LEGAL);

        $this->assertTrue($company->uses_material);
        $this->assertTrue($company->uses_stock);
        $this->assertTrue($company->uses_payroll);
        $this->assertTrue($company->uses_finance);
        $this->assertSame(Company::EFAKTURA_MODE_FIRM, $company->efaktura_credential_mode);
    }
}
```

- [ ] **Step 2: Пушти го за да видиш дека паѓа**

Run: `php artisan test --filter=CompanyCreatorTest`
Expected: FAIL — `Class "App\Services\CompanyCreator" not found`

- [ ] **Step 3: Напиши го `CompanyCreator`**

Создај `app/Services/CompanyCreator.php`:

```php
<?php

namespace App\Services;

use App\Models\Company;
use App\Support\CompanyType;

/**
 * Единственото место што создава фирма.
 *
 * Пишано како одделен клас зашто ДВА екрана создаваат фирми — „Фирми" на
 * админот (App\Livewire\CompanyIndex) и екранот за прв клиент
 * (App\Livewire\FirstClient). Две копии од листата подолу се разидуваат, а
 * разликата се гледа дури на печатена фактура.
 *
 * Ниту едно поле што зависи од типот не смее да остане на стандардна вредност
 * од базата: стандардна вредност на колона НЕ полни свежо создаден модел во
 * меморија, па физичко лице би останало ДДВ обврзник сè до првото повторно
 * читање од базата. Причината е опишана во
 * docs/superpowers/specs/2026-08-21-client-profile-types-design.md.
 */
class CompanyCreator
{
    public static function create(
        string $name,
        CompanyType $type,
        ?string $taxId = null,
        ?string $embg = null,
    ): Company {
        $isLegal = $type->isLegal();

        return Company::create([
            'name' => $name,
            'type' => $type,
            'tax_id' => $isLegal ? ($taxId ?: null) : null,
            'embg' => $isLegal ? null : ($embg ?: null),
            'is_vat_registered' => $isLegal,
            // Сите модули вклучени; се исклучуваат на картичката „Модули".
            'uses_material' => true,
            'uses_stock' => true,
            'uses_payroll' => true,
            'uses_finance' => true,
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM,
        ]);
    }
}
```

- [ ] **Step 4: Пушти го тестот**

Run: `php artisan test --filter=CompanyCreatorTest`
Expected: PASS (3 теста)

- [ ] **Step 5: Пресели го `CompanyIndex` на него**

Во `app/Livewire/CompanyIndex.php`, во `addCompany()`, замени го целиот блок од `$type = CompanyType::from(...)` до крајот на `Company::create([...]);` со:

```php
        $company = CompanyCreator::create(
            $validated['newName'],
            CompanyType::from($validated['newType']),
            $validated['newTaxId'],
            $validated['newEmbg'],
        );
```

и додади го `use App\Services\CompanyCreator;` меѓу постојните `use` изјави (по азбучен ред, пред `App\Support\CompanyType`).

Отстрани ги `$type` и `$isLegal` локалните променливи — повеќе не се користат.

- [ ] **Step 6: Постојните тестови мора да останат зелени, недопрени**

Run: `php artisan test --filter=CompanyIndexTest`
Expected: PASS, без ниту една измена во тој фајл. Ако падне, преселбата е погрешна — поправи го `CompanyCreator`, не тестот.

- [ ] **Step 7: Commit**

```bash
git add app/Services/CompanyCreator.php app/Livewire/CompanyIndex.php tests/Unit/Services/CompanyCreatorTest.php
git commit -m "refactor: создавањето фирма се вади на едно место

Два екрана наскоро создаваат фирми. Листата вредности што зависат од
типот (ДДВ обврзник, ЕДБ наспроти ЕМБГ, модулите) во две копии се
разидува, а разликата се гледа дури на печатена фактура.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 2: Правото за создавање фирма се отвора тесно

**Files:**
- Modify: `app/Policies/CompanyPolicy.php` (методот `create()`)
- Modify: `tests/Feature/CompanyPolicyTest.php`

**Interfaces:**
- Consumes: `User::visibleCompanies(): Builder` (постои)
- Produces: `CompanyPolicy::create(User $user): bool` враќа `true` за админ и за сметководител без ниту една видлива фирма.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Додади ги во `tests/Feature/CompanyPolicyTest.php` (провери ги постојните `use` изјави и `setUp()` — ако улогата `accountant` не е создадена во `setUp()`, додади `Role::findOrCreate('accountant');`):

```php
    public function test_an_accountant_without_a_single_company_may_create_one(): void
    {
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');

        $this->assertTrue($accountant->can('create', Company::class));
    }

    public function test_the_same_accountant_may_not_create_a_second_one(): void
    {
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        Company::factory()->create()->accountants()->attach($accountant);

        $this->assertFalse(
            $accountant->can('create', Company::class),
            'Правото важи само додека сметководителот нема ниту една фирма и само по себе се затвора.'
        );
    }

    public function test_a_client_may_never_create_a_company(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->assertFalse($client->can('create', Company::class));
    }
```

- [ ] **Step 2: Пушти ги за да видиш дека паѓа првиот**

Run: `php artisan test --filter=CompanyPolicyTest`
Expected: FAIL на `test_an_accountant_without_a_single_company_may_create_one` — сега враќа `false`.

- [ ] **Step 3: Отвори го правото**

Во `app/Policies/CompanyPolicy.php` замени го `create()`:

```php
    /**
     * Админ секогаш. Сметководител — само додека нема ниту една фирма, за да
     * може сам да го внесе првиот клиент (App\Livewire\FirstClient).
     *
     * Правилото се затвора само по себе: штом првата фирма е создадена и
     * закачена на него, visibleCompanies() повеќе не е празно. Намерно не е
     * напишано како трајно право — тоа би била друга одлука од таа во
     * docs/superpowers/specs/2026-09-22-first-client-app-board-and-motion-design.md.
     */
    public function create(User $user): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->hasRole('accountant') && ! $user->visibleCompanies()->exists();
    }
```

- [ ] **Step 4: Пушти ги тестовите**

Run: `php artisan test --filter=CompanyPolicyTest`
Expected: PASS

- [ ] **Step 5: Провери дека екранот „Фирми" останува само за админ**

Правото за создавање не е право за екранот. `CompanyIndex::mount()` и понатаму мора да враќа 403 за сметководител.

Run: `php artisan test --filter=CompanyIndexTest`
Expected: PASS, вклучувајќи го `test_only_an_admin_may_open_the_companies_screen`.

- [ ] **Step 6: Commit**

```bash
git add app/Policies/CompanyPolicy.php tests/Feature/CompanyPolicyTest.php
git commit -m "feat: сметководител без фирма смее да создаде фирма

Правилото се затвора само по себе штом ќе се искористи. Екранот Фирми
останува само за админ — ова е право на дејството, не на екранот.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 3: Екранот за прв клиент

**Files:**
- Create: `app/Livewire/FirstClient.php`
- Create: `resources/views/livewire/first-client.blade.php`
- Create: `tests/Feature/FirstClientTest.php`
- Modify: `routes/web.php` (порталната група)
- Modify: `app/Livewire/Dashboard.php` (методот `mount()`)
- Modify: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Consumes: `CompanyCreator::create(...)` од Task 1; `CompanyPolicy::create` од Task 2
- Produces: рута со име `onboarding.first-client` (без параметри)

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Создај `tests/Feature/FirstClientTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Livewire\FirstClient;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FirstClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    private function accountantWithoutCompanies(): User
    {
        $user = User::factory()->create();
        $user->assignRole('accountant');

        return $user;
    }

    public function test_an_accountant_without_companies_lands_here_on_login(): void
    {
        $this->actingAs($this->accountantWithoutCompanies())
            ->get(route('dashboard'))
            ->assertRedirect(route('onboarding.first-client'));
    }

    public function test_the_screen_opens_and_names_what_it_wants(): void
    {
        $this->actingAs($this->accountantWithoutCompanies())
            ->get(route('onboarding.first-client'))
            ->assertOk()
            ->assertSee('прв клиент', false);
    }

    public function test_no_blade_component_is_left_uncompiled(): void
    {
        // Livewire assertSee гледа во исчистен текст и не забележува ни
        // неисцртана компонента. Двапати веќе беше испорачан расипан екран
        // низ зелена серија.
        $this->actingAs($this->accountantWithoutCompanies())
            ->get(route('onboarding.first-client'))
            ->assertDontSee('<x-', false);
    }

    public function test_an_accountant_who_already_has_a_company_is_sent_away(): void
    {
        $accountant = $this->accountantWithoutCompanies();
        $company = Company::factory()->create();
        $company->accountants()->attach($accountant);

        $this->actingAs($accountant)
            ->get(route('onboarding.first-client'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_an_admin_is_sent_away_too(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('onboarding.first-client'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_saving_creates_the_company_and_attaches_the_accountant(): void
    {
        $accountant = $this->accountantWithoutCompanies();
        $this->actingAs($accountant);

        Livewire::test(FirstClient::class)
            ->set('name', 'ПРВ КЛИЕНТ ДООЕЛ')
            ->set('type', CompanyType::LEGAL->value)
            ->set('taxId', '4080012345678')
            ->call('save')
            ->assertHasNoErrors();

        $company = Company::where('name', 'ПРВ КЛИЕНТ ДООЕЛ')->firstOrFail();

        $this->assertTrue($company->is_vat_registered);
        $this->assertTrue(
            $accountant->fresh()->visibleCompanies()->whereKey($company->id)->exists(),
            'Без закачување сметководителот се враќа на истиот екран во круг.'
        );
    }

    public function test_saving_redirects_to_the_company_profile(): void
    {
        $this->actingAs($this->accountantWithoutCompanies());

        Livewire::test(FirstClient::class)
            ->set('name', 'ПРВ КЛИЕНТ ДООЕЛ')
            ->set('type', CompanyType::LEGAL->value)
            ->call('save')
            ->assertRedirect(route('companies.profile', Company::where('name', 'ПРВ КЛИЕНТ ДООЕЛ')->firstOrFail()));
    }

    public function test_an_individual_is_not_created_as_a_vat_payer(): void
    {
        $this->actingAs($this->accountantWithoutCompanies());

        Livewire::test(FirstClient::class)
            ->set('name', 'Петар Петров')
            ->set('type', CompanyType::INDIVIDUAL->value)
            ->set('embg', '0101990450006')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(Company::where('name', 'Петар Петров')->firstOrFail()->is_vat_registered);
    }

    public function test_the_name_is_required(): void
    {
        $this->actingAs($this->accountantWithoutCompanies());

        Livewire::test(FirstClient::class)
            ->set('name', '')
            ->set('type', CompanyType::LEGAL->value)
            ->call('save')
            ->assertHasErrors(['name' => 'required']);
    }

    public function test_a_nonsense_embg_is_refused(): void
    {
        $this->actingAs($this->accountantWithoutCompanies());

        Livewire::test(FirstClient::class)
            ->set('name', 'Петар Петров')
            ->set('type', CompanyType::INDIVIDUAL->value)
            ->set('embg', '1111111111111')
            ->call('save')
            ->assertHasErrors('embg');
    }

    public function test_the_route_requires_authentication(): void
    {
        $this->get(route('onboarding.first-client'))->assertRedirect(route('login'));
    }
}
```

- [ ] **Step 2: Пушти ги за да видиш дека паѓаат**

Run: `php artisan test --filter=FirstClientTest`
Expected: FAIL — рутата `onboarding.first-client` не постои.

- [ ] **Step 3: Додади ја рутата**

Во `routes/web.php`, во `Route::domain(PortalApp::PORTAL->domain())->group(...)`, веднаш по редот со `dashboard`:

```php
    // Излезот за сметководител што сè уште нема ниту еден клиент. Стои на
    // порталот зашто тој човек нема фирма, па нема ни апликациски екран што
    // би можел да го отвори.
    Route::get('prv-klient', [FirstClient::class, '__invoke'])
        ->middleware(['auth', 'verified'])
        ->name('onboarding.first-client');
```

и `use App\Livewire\FirstClient;` меѓу постојните `use App\Livewire\...` изјави, по азбучен ред (по `EmployeeIndex`, пред `Inventory\ItemBulkImport`).

- [ ] **Step 4: Напиши ја компонентата**

Создај `app/Livewire/FirstClient.php`:

```php
<?php

namespace App\Livewire;

use App\Models\Company;
use App\Rules\ValidEmbg;
use App\Services\CompanyCreator;
use App\Support\CompanyType;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Првиот клиент на нов сметководител.
 *
 * Постои зашто сметководител без ниту една фирма беше заглавен: најавата го
 * носеше на екранот „Изберете фирма", а единствената врска таму водеше на
 * companies.index, кој за него враќа 403.
 *
 * Екранот сам се брани од двете страни. Кој нема работа тука — админ, клиент,
 * или сметководител што веќе има фирма — се враќа на dashboard. Без тоа,
 * страната би станала заден влез за создавање фирми.
 */
#[Layout('layouts.app')]
class FirstClient extends Component
{
    public string $name = '';

    public string $type = '';

    public string $taxId = '';

    public string $embg = '';

    public function mount()
    {
        $user = auth()->user();

        if (! $user->hasRole('accountant') || $user->visibleCompanies()->exists()) {
            return $this->redirect(route('dashboard'));
        }

        return null;
    }

    public function save()
    {
        // Истата брана како на екранот на админот (CompanyIndex::addCompany()
        // вика Gate::authorize исто вака). Правилото живее во CompanyPolicy,
        // не тука.
        Gate::authorize('create', Company::class);

        $validated = $this->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::enum(CompanyType::class)],
            'taxId' => 'nullable|string|max:255',
            // ЕМБГ се проверува со контролна цифра само кога навистина е внесен
            // и кога типот е физичко лице — истиот образец како во
            // App\Livewire\CompanyIndex::addCompany().
            'embg' => $this->type === CompanyType::INDIVIDUAL->value && $this->embg !== ''
                ? ['nullable', 'max:13', new ValidEmbg]
                : ['nullable', 'max:13'],
        ]);

        $company = CompanyCreator::create(
            $validated['name'],
            CompanyType::from($validated['type']),
            $validated['taxId'],
            $validated['embg'],
        );

        // Без ова visibleCompanies() останува празно и човекот се враќа на
        // истиот екран во круг.
        $company->accountants()->attach(auth()->id());

        return $this->redirect(route('companies.profile', $company), navigate: true);
    }

    public function render()
    {
        return view('livewire.first-client', ['types' => CompanyType::cases()]);
    }
}
```

- [ ] **Step 5: Напиши го изгледот**

Создај `resources/views/livewire/first-client.blade.php`:

```blade
<div class="max-w-xl mx-auto mt-6">
    <x-card padding="p-6">
        <h1 class="text-xl font-bold text-ink">Внесете го вашиот прв клиент</h1>
        <p class="mt-2 text-sm text-stone">
            Сè уште немате ниту една фирма на која работите. Внесете ја првата тука и
            веднаш преминувате на нејзиниот профил, каде ги дополнувате остатокот од
            податоците.
        </p>

        <form wire:submit="save" class="mt-6 space-y-4">
            <div>
                <x-input-label for="first-client-name" value="Име на клиентот" />
                <x-text-input id="first-client-name" wire:model="name" type="text" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="first-client-type" value="Тип" />
                <select id="first-client-type" wire:model.live="type"
                        class="mt-1 block w-full rounded-lg border-sand text-sm focus:border-brand focus:ring-brand">
                    <option value="">Изберете тип</option>
                    @foreach ($types as $companyType)
                        <option value="{{ $companyType->value }}">{{ $companyType->label() }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('type')" class="mt-2" />
            </div>

            @if ($type === \App\Support\CompanyType::LEGAL->value)
                <div>
                    <x-input-label for="first-client-tax-id" value="ЕДБ" />
                    <x-text-input id="first-client-tax-id" wire:model="taxId" type="text" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('taxId')" class="mt-2" />
                </div>
            @endif

            @if ($type === \App\Support\CompanyType::INDIVIDUAL->value)
                <div>
                    <x-input-label for="first-client-embg" value="ЕМБГ" />
                    <x-text-input id="first-client-embg" wire:model="embg" type="text" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('embg')" class="mt-2" />
                </div>
            @endif

            <x-primary-button class="press">Зачувај и продолжи</x-primary-button>
        </form>
    </x-card>
</div>
```

- [ ] **Step 6: Пренасочи го `Dashboard`**

Во `app/Livewire/Dashboard.php`, во `mount()`, веднаш по `if ($user->hasRole('admin')) { return null; }`:

```php
        // Сметководител што сè уште нема ниту еден клиент нема каде да отиде:
        // екранот „Изберете фирма" би му понудил само врска што за него враќа
        // 403. Го праќаме да го внесе првиот.
        if ($user->hasRole('accountant') && ! $user->visibleCompanies()->exists()) {
            return $this->redirect(route('onboarding.first-client'));
        }
```

- [ ] **Step 7: Пушти ги тестовите**

Run: `php artisan test --filter=FirstClientTest`
Expected: PASS (11 теста)

- [ ] **Step 8: Додади го случајот и во `DashboardTest`**

Во `tests/Feature/DashboardTest.php`:

```php
    public function test_an_accountant_without_a_single_company_is_sent_to_enter_their_first_client(): void
    {
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');

        $this->actingAs($accountant)
            ->get(route('dashboard'))
            ->assertRedirect(route('onboarding.first-client'));
    }
```

- [ ] **Step 9: Пушти ги двата фајла**

Run: `php artisan test --filter="DashboardTest|FirstClientTest"`
Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add app/Livewire/FirstClient.php resources/views/livewire/first-client.blade.php app/Livewire/Dashboard.php routes/web.php tests/Feature/FirstClientTest.php tests/Feature/DashboardTest.php
git commit -m "feat: екран за прв клиент кога сметководителот нема ниту една фирма

Порано таквиот човек беше заглавен: единствената врска што ја гледаше
водеше на companies.index, кој за него враќа 403.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 4: Таблата на Продажба — рута, екран и врска во менито

**Files:**
- Create: `app/Livewire/Apps/SalesDashboard.php`
- Create: `resources/views/livewire/apps/sales-dashboard.blade.php`
- Create: `tests/Feature/SalesDashboardTest.php`
- Modify: `routes/web.php` (групата на доменот на Продажба)
- Modify: `app/Livewire/Layout/Sidebar.php`
- Modify: `resources/views/livewire/layout/sidebar.blade.php`

**Interfaces:**
- Consumes: ништо од претходните задачи
- Produces: рута `prodazba.dashboard` со параметар `{company}`; `App\Livewire\Apps\SalesDashboard` со јавно својство `Company $company`; `Sidebar::$boardUrl` (string, празна кога нема табла)

Во оваа задача таблата е само наслов. Копчињата доаѓаат во Task 5, табелите во Task 6. Влезот во апликацијата сè уште НЕ се менува — тоа е Task 7, за да не се пренасочи никој на полупразен екран.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Создај `tests/Feature/SalesDashboardTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\PortalApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_the_board_lives_on_the_prodazba_host(): void
    {
        $company = Company::factory()->create();

        $this->assertStringStartsWith(
            'http://'.PortalApp::PRODAZBA->domain(),
            route('prodazba.dashboard', $company)
        );
    }

    public function test_the_board_opens_and_names_the_company(): void
    {
        $company = Company::factory()->create(['name' => 'ТЕСТ ДООЕЛ']);

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertOk()
            ->assertSee('ТЕСТ ДООЕЛ');
    }

    public function test_no_blade_component_is_left_uncompiled(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertDontSee('<x-', false);
    }

    public function test_the_board_survives_material_being_switched_off(): void
    {
        // Кооперанти немаат модул, па таблата мора да се отвори и кога
        // Материјално е исклучено. Затоа рутата НЕ носи EnsureCompanyModule.
        $company = Company::factory()->create(['uses_material' => false]);

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertOk();
    }

    public function test_a_stranger_may_not_open_someone_elses_board(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $mine->id]);
        $client->assignRole('client');

        $this->actingAs($client)
            ->get(route('prodazba.dashboard', $theirs))
            ->assertForbidden();
    }

    public function test_the_route_requires_authentication(): void
    {
        $company = Company::factory()->create();

        $this->get(route('prodazba.dashboard', $company))->assertRedirect();
    }

    public function test_the_sidebar_carries_a_link_to_the_board(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('sales-invoices.index', $company))
            ->assertOk()
            ->assertSee(route('prodazba.dashboard', $company), false)
            ->assertSee('Табла');
    }

    public function test_other_apps_have_no_board_link_in_their_sidebar(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('accounting.journal-groups.index', $company))
            ->assertOk()
            ->assertDontSee(route('prodazba.dashboard', $company), false);
    }
}
```

- [ ] **Step 2: Пушти ги за да видиш дека паѓаат**

Run: `php artisan test --filter=SalesDashboardTest`
Expected: FAIL — рутата `prodazba.dashboard` не постои.

- [ ] **Step 3: Додади ја рутата**

Во `routes/web.php`, во `Route::domain(PortalApp::PRODAZBA->domain())->middleware(EnsureAppAccess::class.':prodazba')->group(...)`, веднаш по рутата за коренот `/`:

```php
    // Таблата на апликацијата. Намерно БЕЗ EnsureCompanyModule: Кооперанти
    // немаат модул (партнерите ги бара и книжењето), па таблата мора да
    // преживее исклучено Материјално. Секое копче на неа сама си одлучува
    // дали да се нацрта.
    Route::middleware(['auth'])->get('/companies/{company}/tabla', [SalesDashboard::class, '__invoke'])
        ->name('prodazba.dashboard');
```

и `use App\Livewire\Apps\SalesDashboard;` меѓу постојните `use App\Livewire\...` изјави (по `App\Livewire\Accounting\TrialBalanceReport`, пред `App\Livewire\Bank\BankStatementIndex`).

- [ ] **Step 4: Напиши ја компонентата**

Создај `app/Livewire/Apps/SalesDashboard.php`:

```php
<?php

namespace App\Livewire\Apps;

use App\Livewire\Concerns\InteractsWithWorkingYear;
use App\Models\Company;
use App\Support\WorkingYear;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Влезниот екран на апликацијата Продажба.
 *
 * Порано кликот на „Продажба" паѓаше право во Излезни фактури, зашто
 * AppSwitcher ја земаше буквално првата ставка од менито.
 */
#[Layout('layouts.app')]
class SalesDashboard extends Component
{
    use InteractsWithWorkingYear;

    public Company $company;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);
    }

    public function render()
    {
        return view('livewire.apps.sales-dashboard');
    }
}
```

- [ ] **Step 5: Напиши го изгледот**

Создај `resources/views/livewire/apps/sales-dashboard.blade.php`:

```blade
<div>
    <h1 class="text-lg font-bold text-ink">{{ $company->name }}</h1>
    <p class="mt-1 text-sm text-stone">Продажба · работна година {{ $workingYear }}</p>
</div>
```

- [ ] **Step 6: Додади ја врската „Табла" во страничното мени**

Во `app/Livewire/Layout/Sidebar.php`, додади јавно својство до `$brandUrl`:

```php
    // Празна низа кога апликацијата сè уште нема своја табла. Само Продажба
    // има; Финансии и Плати ќе добијат кога ќе се одлучи што има на нив.
    public string $boardUrl = '';
```

и во `mount()`, внатре во блокот по `CurrentCompany::remember($this->company);`, пред `$this->menu = ...`:

```php
        $this->boardUrl = $app === PortalApp::PRODAZBA
            ? route('prodazba.dashboard', $this->company)
            : '';
```

Во `resources/views/livewire/layout/sidebar.blade.php`, внатре во `@if ($company)`, веднаш по затворањето на `<div class="px-4 pb-3 space-y-2">` (значи по двата избирача, пред `@foreach ($menu as $group)`):

```blade
                {{-- Таблата стои над групите зашто не е ставка од ниту една
                     група — таа е влезот во целата апликација. Истиот облик
                     како самостојната врска „Документи" на дното. --}}
                @if ($boardUrl !== '')
                    <a href="{{ $boardUrl }}" wire:navigate
                       class="block px-4 py-2 mb-1 text-sm font-medium rounded-lg mx-3 {{ $currentRoute === 'prodazba.dashboard' ? 'bg-brand text-white press' : 'text-rail-text hover:bg-rail-soft press' }}">
                        Табла
                    </a>
                @endif
```

- [ ] **Step 7: Пушти ги тестовите**

Run: `php artisan test --filter=SalesDashboardTest`
Expected: PASS (8 теста)

- [ ] **Step 8: Пушти ги и тестовите на лушпата, менито и рутирањето**

Run: `php artisan test --filter="AppShellTest|MenuTest|AppDomainRoutingTest|SidebarTest"`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/Apps/SalesDashboard.php resources/views/livewire/apps/sales-dashboard.blade.php app/Livewire/Layout/Sidebar.php resources/views/livewire/layout/sidebar.blade.php routes/web.php tests/Feature/SalesDashboardTest.php
git commit -m "feat: Продажба добива своја табла и врска до неа во менито

Рутата намерно нема EnsureCompanyModule — Кооперанти немаат модул, па
таблата мора да се отвори и кога Материјално е исклучено.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 5: Трите копчиња на таблата

**Files:**
- Modify: `app/Livewire/Apps/SalesDashboard.php` (нов метод `links()`)
- Modify: `resources/views/livewire/apps/sales-dashboard.blade.php`
- Modify: `resources/css/app.css`
- Modify: `tests/Feature/SalesDashboardTest.php`

**Interfaces:**
- Consumes: `SalesDashboard::$company` од Task 4
- Produces: `SalesDashboard::links(): array` — низа од `['key' => string, 'label' => string, 'url' => string, 'tone' => string, 'icon' => string]`. Task 6 го користи истиот `key` за да одлучи која табела се црта.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Додади ги во `tests/Feature/SalesDashboardTest.php`:

```php
    public function test_a_legal_entity_with_everything_on_gets_all_three_buttons(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertSee('Излезни фактури')
            ->assertSee('Влезни фактури')
            ->assertSee('Кооперанти');
    }

    public function test_with_material_off_only_the_partners_button_is_left(): void
    {
        // Копче кон екран затворен со EnsureCompanyModule завршува со
        // „Забранет пристап" — полошо од отсутно копче.
        $company = Company::factory()->create(['uses_material' => false]);

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertDontSee('Излезни фактури')
            ->assertDontSee('Влезни фактури')
            ->assertSee('Кооперанти');
    }

    public function test_an_individual_has_no_incoming_invoices_at_all(): void
    {
        $company = Company::factory()->create(['type' => \App\Support\CompanyType::INDIVIDUAL]);

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertSee('Излезни фактури')
            ->assertDontSee('Влезни фактури')
            ->assertSee('Кооперанти');
    }

    public function test_the_buttons_carry_the_motion_class(): void
    {
        // Однесувањето живее во resources/css/app.css; изгледот само ја носи
        // куката. assertSee на текст не би забележал изгубена класа.
        $company = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertSee('board-link', false);
    }
```

- [ ] **Step 2: Пушти ги за да видиш дека паѓаат**

Run: `php artisan test --filter=SalesDashboardTest`
Expected: FAIL — на таблата сè уште нема ниту едно копче.

- [ ] **Step 3: Додади го `links()` во компонентата**

Во `app/Livewire/Apps/SalesDashboard.php`, додади `use App\Support\CompanyModule;` и метода:

```php
    /**
     * Трите копчиња. Условите се исчитани од Menu::legalTree() и
     * Menu::individualTree(): кај физичко лице „Излезни фактури" и
     * „Кооперанти" воопшто немаат модул, па ништо не ги гаси, а влезни
     * фактури таму не постојат.
     *
     * Иконите се испишани цели (Heroicons патеки) зашто се украс, како во
     * company-dashboard.blade.php.
     *
     * @return list<array{key: string, label: string, url: string, tone: string, icon: string}>
     */
    public function links(): array
    {
        $legal = $this->company->type->isLegal();
        $material = ! $legal || $this->company->usesModule(CompanyModule::MATERIAL);

        $links = [];

        if ($material) {
            $links[] = [
                'key' => 'sales',
                'label' => 'Излезни фактури',
                'url' => route('sales-invoices.index', $this->company),
                'tone' => 'board-link--orange',
                'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.6a1 1 0 01.7.3l4.4 4.4a1 1 0 01.3.7V19a2 2 0 01-2 2z',
            ];
        }

        if ($legal && $material) {
            $links[] = [
                'key' => 'purchases',
                'label' => 'Влезни фактури',
                'url' => route('purchase-invoices.index', $this->company),
                'tone' => 'board-link--green',
                'icon' => 'M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.6a1 1 0 00-.9.55l-.8 1.9a1 1 0 01-.9.55h-3.6a1 1 0 01-.9-.55l-.8-1.9a1 1 0 00-.9-.55H4',
            ];
        }

        $links[] = [
            'key' => 'partners',
            'label' => 'Кооперанти',
            'url' => route('partners.index', $this->company),
            'tone' => 'board-link--indigo',
            'icon' => 'M17 20h5v-2a3 3 0 00-5.4-1.9M17 20H7m10 0v-2c0-.7-.1-1.3-.4-1.9M7 20H2v-2a3 3 0 015.4-1.9M7 20v-2c0-.7.1-1.3.4-1.9m0 0a5 5 0 019.2 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
        ];

        return $links;
    }
```

- [ ] **Step 4: Нацртај ги копчињата**

Замени го `resources/views/livewire/apps/sales-dashboard.blade.php` со:

```blade
<div>
    <h1 class="text-lg font-bold text-ink">{{ $company->name }}</h1>
    <p class="mt-1 text-sm text-stone">Продажба · работна година {{ $workingYear }}</p>

    {{-- Долго име на променливата намерно: @foreach ја презапишува променливата
         и по јамката, па кратко име како $link би се судрило со друго подолу. --}}
    <div class="mt-5 grid grid-cols-1 sm:grid-cols-3 gap-4">
        @foreach ($this->links() as $boardLink)
            <a href="{{ $boardLink['url'] }}" wire:navigate
               class="board-link {{ $boardLink['tone'] }} press"
               style="--i: {{ $loop->index }}">
                <span class="board-link__icon" aria-hidden="true">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $boardLink['icon'] }}" />
                    </svg>
                </span>
                <span class="board-link__label">{{ $boardLink['label'] }}</span>
                <svg class="board-link__arrow h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5-5 5M6 12h12" />
                </svg>
            </a>
        @endforeach
    </div>
</div>
```

- [ ] **Step 5: Додади го стилот**

Во `resources/css/app.css`, веднаш по блокот `@keyframes tile-in { ... }`:

```css
/*
 * Копчињата на таблата на апликација. Истите три тона како плочките, но
 * пониски — тие се навигација, не влезна врата.
 */
.board-link {
    --tone: #ff6600;
    --tone-soft: rgba(255, 102, 0, 0.14);
    display: flex;
    align-items: center;
    gap: 0.75rem;
    border-radius: 1rem;
    border: 1px solid #E5DDD0;
    background: #fff;
    padding: 0.875rem 1rem;
    box-shadow: 0 1px 2px rgba(28, 26, 23, 0.04);
    transition: transform 220ms cubic-bezier(.2, .8, .2, 1), box-shadow 220ms ease-out, border-color 220ms ease-out;
    animation: tile-in 420ms cubic-bezier(.2, .8, .2, 1) backwards;
    animation-delay: calc(var(--i, 0) * 70ms);
}

.board-link--orange { --tone: #ff6600; --tone-soft: rgba(255, 102, 0, 0.16); }
.board-link--green  { --tone: #059669; --tone-soft: rgba(5, 150, 105, 0.16); }
.board-link--indigo { --tone: #4f46e5; --tone-soft: rgba(79, 70, 229, 0.16); }

.board-link:hover {
    transform: translateY(-2px);
    border-color: var(--tone-soft);
    box-shadow: 0 10px 22px -12px var(--tone-soft), 0 2px 5px rgba(28, 26, 23, 0.05);
}

.board-link__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.25rem;
    height: 2.25rem;
    flex: none;
    border-radius: 0.75rem;
    color: #fff;
    background: var(--tone);
    box-shadow: 0 6px 12px -6px var(--tone);
    transition: transform 300ms cubic-bezier(.34, 1.56, .64, 1);
}

.board-link:hover .board-link__icon {
    transform: rotate(-6deg) scale(1.06);
}

.board-link__label {
    flex: 1 1 auto;
    font-size: 0.875rem;
    font-weight: 600;
    color: #1C1A17;
}

.board-link__arrow {
    flex: none;
    color: var(--tone);
    transition: transform 220ms cubic-bezier(.2, .8, .2, 1);
}

.board-link:hover .board-link__arrow {
    transform: translateX(3px);
}
```

**И — задолжително** — додади `.board-link` во ПРВИОТ `@media (prefers-reduced-motion: reduce)` блок (оној со `animation: none`), затоа што носи `animation-delay`:

```css
@media (prefers-reduced-motion: reduce) {
    .app-tile,
    .board-link,
    .menu-group.is-open .menu-item {
        animation: none;
    }
}
```

- [ ] **Step 6: Пушти ги тестовите**

Run: `php artisan test --filter=SalesDashboardTest`
Expected: PASS (12 теста)

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Apps/SalesDashboard.php resources/views/livewire/apps/sales-dashboard.blade.php resources/css/app.css tests/Feature/SalesDashboardTest.php
git commit -m "feat: три копчиња на таблата на Продажба

Копчињата ги следат модулите и типот на клиент — копче кон екран
затворен со EnsureCompanyModule завршува со Забранет пристап.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 6: Трите табели на таблата

**Files:**
- Modify: `app/Livewire/Apps/SalesDashboard.php` (методот `render()`)
- Modify: `resources/views/livewire/apps/sales-dashboard.blade.php`
- Modify: `tests/Feature/SalesDashboardTest.php`

**Interfaces:**
- Consumes: `SalesDashboard::links()` од Task 5 (клучевите `sales`, `purchases`, `partners`)
- Produces: три променливи во изгледот — `$recentSales`, `$recentPurchases`, `$recentPartners`, секоја `Illuminate\Database\Eloquent\Collection` со најмногу 5 записи

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Додади ги во `tests/Feature/SalesDashboardTest.php` (додади ги и `use App\Models\Partner;`, `use App\Models\PurchaseInvoice;`, `use App\Models\SalesInvoice;` на врвот).

Фактите за фабриките, проверени во кодот, за да не се погодуваат:

- `SalesInvoiceFactory` и `PurchaseInvoiceFactory` сами создаваат фирма и партнер ако не им се дадат, па `'company_id'` и `'partner_id'` мора да се дадат изрично за да припаднат на ИСТАТА фирма.
- Колоната е `invoice_number`, **не** `number`, и е `null` кај нацрт. `formattedNumber()` тогаш враќа `null` и на екранот излегува „—".
- Затоа тестовите проверуваат по **име на партнер**, не по број на фактура. Тоа е видлив, единствен и стабилен текст.
- `invoice_date` кај двете фабрики е `now()`, а стандардната работна година е тековната календарска — значи фабричката фактура е во работната година без ништо дополнително.

```php
    /** Партнер на оваа фирма, со дадено име. */
    private function partnerNamed(Company $company, string $name): Partner
    {
        return Partner::factory()->create(['company_id' => $company->id, 'name' => $name]);
    }

    public function test_only_the_five_latest_entered_sales_invoices_are_shown(): void
    {
        $company = Company::factory()->create();

        // Шест фактури, внесени по ред. Првата внесена мора да испадне.
        foreach (range(1, 6) as $i) {
            SalesInvoice::factory()->create([
                'company_id' => $company->id,
                'partner_id' => $this->partnerNamed($company, "КУПУВАЧ {$i} ДООЕЛ")->id,
                'created_at' => now()->addMinutes($i),
            ]);
        }

        $response = $this->actingAs($this->admin())->get(route('prodazba.dashboard', $company));

        $response->assertOk();
        $response->assertSee('Последни излезни фактури');
        $response->assertSee('КУПУВАЧ 6 ДООЕЛ');
        $response->assertSee('КУПУВАЧ 2 ДООЕЛ');
        $response->assertDontSee(
            'КУПУВАЧ 1 ДООЕЛ',
            'Шестата најстара по внесување мора да испадне од петте.'
        );
    }

    public function test_the_order_is_by_entry_not_by_invoice_date(): void
    {
        // Побарано е „последните пет што се ВНЕСЕНИ". Скенирана фактура од
        // минатиот месец, внесена денес, припаѓа на врвот.
        $company = Company::factory()->create();

        SalesInvoice::factory()->create([
            'company_id' => $company->id,
            'partner_id' => $this->partnerNamed($company, 'ВНЕСЕНА ПРВА')->id,
            'invoice_date' => now()->toDateString(),
            'created_at' => now()->subHour(),
        ]);

        SalesInvoice::factory()->create([
            'company_id' => $company->id,
            'partner_id' => $this->partnerNamed($company, 'ВНЕСЕНА ВТОРА')->id,
            'invoice_date' => now()->subMonth()->toDateString(),
            'created_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertSeeInOrder(['ВНЕСЕНА ВТОРА', 'ВНЕСЕНА ПРВА']);
    }

    public function test_only_invoices_from_the_working_year_are_listed(): void
    {
        $company = Company::factory()->create();

        SalesInvoice::factory()->create([
            'company_id' => $company->id,
            'partner_id' => $this->partnerNamed($company, 'ЛАНСКИ КУПУВАЧ')->id,
            'invoice_date' => now()->subYear()->toDateString(),
        ]);

        $response = $this->actingAs($this->admin())->get(route('prodazba.dashboard', $company));

        $response->assertOk();
        $response->assertSee('Нема внесени излезни фактури');
        $response->assertDontSee('ЛАНСКИ КУПУВАЧ');
    }

    public function test_incoming_invoices_have_their_own_table(): void
    {
        $company = Company::factory()->create();

        PurchaseInvoice::factory()->create([
            'company_id' => $company->id,
            'partner_id' => $this->partnerNamed($company, 'ДОБАВУВАЧ ДООЕЛ')->id,
        ]);

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertSee('Последни влезни фактури')
            ->assertSee('ДОБАВУВАЧ ДООЕЛ');
    }

    public function test_partners_are_listed_regardless_of_the_working_year(): void
    {
        $company = Company::factory()->create();
        $this->partnerNamed($company, 'КООПЕРАНТ ДООЕЛ');

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertOk()
            ->assertSee('КООПЕРАНТ ДООЕЛ');
    }

    public function test_another_companys_records_never_appear(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();
        $this->partnerNamed($theirs, 'ТУЃ КООПЕРАНТ');

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $mine))
            ->assertDontSee('ТУЃ КООПЕРАНТ');
    }

    public function test_a_table_whose_button_is_hidden_is_hidden_too(): void
    {
        $company = Company::factory()->create(['uses_material' => false]);

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertDontSee('Последни излезни фактури')
            ->assertDontSee('Последни влезни фактури')
            ->assertSee('Кооперанти');
    }

    public function test_an_empty_table_says_so_instead_of_gaping(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertSee('Нема внесени излезни фактури')
            ->assertSee('Нема внесени влезни фактури')
            ->assertSee('Нема внесени кооперанти');
    }
```

- [ ] **Step 2: Пушти ги за да видиш дека паѓаат**

Run: `php artisan test --filter=SalesDashboardTest`
Expected: FAIL — на таблата сè уште нема ниту една табела.

- [ ] **Step 3: Полни ги табелите во `render()`**

Во `app/Livewire/Apps/SalesDashboard.php` додади `use App\Models\Partner;`, `use App\Models\PurchaseInvoice;`, `use App\Models\SalesInvoice;` и замени го `render()`:

```php
    public function render()
    {
        $keys = array_column($this->links(), 'key');

        return view('livewire.apps.sales-dashboard', [
            // Подредено по created_at, не по invoice_date: сопственикот бара
            // „последните пет што се ВНЕСЕНИ". Скенирана фактура од минатиот
            // месец внесена денес припаѓа на врвот.
            //
            // Опсегот е работната година, како во сите списоци — табла што го
            // игнорира избирачот на година би мешала бројки од две години.
            'recentSales' => in_array('sales', $keys, true)
                ? SalesInvoice::where('company_id', $this->company->id)
                    ->whereBetween('invoice_date', [$this->workingYearStart(), $this->workingYearEnd()])
                    ->with('partner')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit(5)
                    ->get()
                : null,
            'recentPurchases' => in_array('purchases', $keys, true)
                ? PurchaseInvoice::where('company_id', $this->company->id)
                    ->whereBetween('invoice_date', [$this->workingYearStart(), $this->workingYearEnd()])
                    ->with('partner')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit(5)
                    ->get()
                : null,
            // Партнерите немаат година. Врзување за година тука би значело
            // измислување правило што не постои никаде другаде.
            'recentPartners' => Partner::where('company_id', $this->company->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(5)
                ->get(),
        ]);
    }
```

- [ ] **Step 4: Нацртај ги табелите**

Додади го на крајот од `resources/views/livewire/apps/sales-dashboard.blade.php`, внатре во надворешниот `<div>`, по решетката со копчињата:

```blade
    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4">
        @if ($recentSales !== null)
            <x-card padding="p-0" class="overflow-hidden">
                <div class="flex items-center justify-between px-3 py-2 border-b border-sand">
                    <span class="text-xs font-semibold tracking-wide text-stone">Последни излезни фактури</span>
                    <a href="{{ route('sales-invoices.index', $company) }}" wire:navigate class="text-xs text-brand hover:underline">сите →</a>
                </div>
                <table class="min-w-full divide-y divide-gray-200">
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($recentSales as $invoice)
                            <tr class="text-sm hover:bg-orange-50">
                                <td class="py-1 px-3">
                                    <a href="{{ route('sales-invoices.show', [$company, $invoice]) }}" wire:navigate class="text-brand hover:underline">
                                        {{ $invoice->formattedNumber() ?? '—' }}
                                    </a>
                                </td>
                                <td class="py-1 px-3 truncate">{{ $invoice->partner?->name ?? '—' }}</td>
                                <td class="py-1 px-3 text-right whitespace-nowrap">{{ \App\Support\Format::money($invoice->grandTotal()) }}</td>
                            </tr>
                        @empty
                            <tr><td class="py-3 px-3 text-sm text-gray-500">Нема внесени излезни фактури за {{ $workingYear }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-card>
        @endif

        @if ($recentPurchases !== null)
            <x-card padding="p-0" class="overflow-hidden">
                <div class="flex items-center justify-between px-3 py-2 border-b border-sand">
                    <span class="text-xs font-semibold tracking-wide text-stone">Последни влезни фактури</span>
                    <a href="{{ route('purchase-invoices.index', $company) }}" wire:navigate class="text-xs text-brand hover:underline">сите →</a>
                </div>
                <table class="min-w-full divide-y divide-gray-200">
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($recentPurchases as $invoice)
                            <tr class="text-sm hover:bg-orange-50">
                                <td class="py-1 px-3">
                                    <a href="{{ route('purchase-invoices.show', [$company, $invoice]) }}" wire:navigate class="text-brand hover:underline">
                                        {{ $invoice->supplier_invoice_number ?: '—' }}
                                    </a>
                                </td>
                                <td class="py-1 px-3 truncate">{{ $invoice->partner?->name ?? '—' }}</td>
                                <td class="py-1 px-3 text-right whitespace-nowrap">{{ \App\Support\Format::money($invoice->grandTotal()) }}</td>
                            </tr>
                        @empty
                            <tr><td class="py-3 px-3 text-sm text-gray-500">Нема внесени влезни фактури за {{ $workingYear }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-card>
        @endif

        <x-card padding="p-0" class="overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 border-b border-sand">
                <span class="text-xs font-semibold tracking-wide text-stone">Кооперанти</span>
                <a href="{{ route('partners.index', $company) }}" wire:navigate class="text-xs text-brand hover:underline">сите →</a>
            </div>
            <table class="min-w-full divide-y divide-gray-200">
                <tbody class="divide-y divide-gray-100">
                    @forelse ($recentPartners as $partner)
                        <tr class="text-sm hover:bg-orange-50">
                            <td class="py-1 px-3">
                                <a href="{{ route('partners.show', [$company, $partner]) }}" wire:navigate class="text-brand hover:underline">
                                    {{ $partner->name }}
                                </a>
                            </td>
                            <td class="py-1 px-3 text-gray-500 whitespace-nowrap">{{ $partner->tax_id ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-3 px-3 text-sm text-gray-500">Нема внесени кооперанти</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-card>
    </div>
```

- [ ] **Step 5: Пушти ги тестовите**

Run: `php artisan test --filter=SalesDashboardTest`
Expected: PASS (20 теста — 8 од Task 4, 4 од Task 5, 8 од оваа)

- [ ] **Step 6: Пушти ја целата серија еднаш — ова е екран со пресметки**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Apps/SalesDashboard.php resources/views/livewire/apps/sales-dashboard.blade.php tests/Feature/SalesDashboardTest.php
git commit -m "feat: три табели со последните внесени записи на таблата

Подредено по created_at, не по датум на фактура: побарано е
последното ВНЕСЕНО. Фактурите ја почитуваат работната година,
кооперантите немаат година па не се филтрираат.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 7: Кликот на Продажба слета на таблата

**Files:**
- Modify: `app/Support/Menu.php` (нов `landingUrl()`)
- Modify: `app/Support/AppSwitcher.php`
- Modify: `app/Support/LandingUrl.php`
- Modify: `app/Livewire/Layout/Sidebar.php` (`brandUrl`)
- Modify: `tests/Unit/Support/MenuTest.php`
- Modify: `tests/Feature/AppSwitcherTest.php`

**Interfaces:**
- Consumes: рутата `prodazba.dashboard` од Task 4
- Produces: `Menu::landingUrl(User $user, Company $company, PortalApp $app): ?string`

- [ ] **Step 1: Напиши го тестот што паѓа**

Додади го во `tests/Unit/Support/MenuTest.php` (провери ги постојните `use` изјави):

```php
    public function test_prodazba_is_entered_through_its_board_not_through_the_first_screen(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertSame(
            route('prodazba.dashboard', $company),
            Menu::landingUrl($admin, $company, PortalApp::PRODAZBA)
        );
    }

    public function test_apps_without_a_board_still_open_on_their_first_screen(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertSame(
            Menu::firstUrl($admin, $company, PortalApp::FINANSII),
            Menu::landingUrl($admin, $company, PortalApp::FINANSII)
        );
    }

    public function test_an_app_with_no_screens_at_all_has_no_landing(): void
    {
        $company = Company::factory()->create(['uses_payroll' => false]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertNull(Menu::landingUrl($admin, $company, PortalApp::PLATA));
    }
```

И во `tests/Feature/AppSwitcherTest.php`:

```php
    public function test_the_prodazba_entry_points_at_the_board(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $apps = collect(AppSwitcher::for($admin, $company))->keyBy('key');

        $this->assertSame(route('prodazba.dashboard', $company), $apps['prodazba']['url']);
    }
```

- [ ] **Step 2: Пушти ги за да видиш дека паѓаат**

Run: `php artisan test --filter="MenuTest|AppSwitcherTest"`
Expected: FAIL — `Menu::landingUrl()` не постои.

- [ ] **Step 3: Напиши го `landingUrl()`**

Во `app/Support/Menu.php`, веднаш по `firstUrl()`:

```php
    /**
     * Каде се влегува во апликација: нејзината табла ако има, инаку првиот
     * екран.
     *
     * Ова е шевот за „за другите модули потака ќе средиме". Кога ќе се одлучи
     * што има на таблата на Финансии, тука се додава еден ред — сите три
     * места што одлучуваат за влез (AppSwitcher, LandingUrl, brandUrl во
     * Sidebar) веќе читаат од овде.
     *
     * Враќа null кога апликацијата нема ниту еден екран за оваа фирма —
     * тогаш ни таблата не смее да се понуди, зашто би била празна врата.
     */
    public static function landingUrl(User $user, Company $company, PortalApp $app): ?string
    {
        $first = self::firstUrl($user, $company, $app);

        if ($first === null) {
            return null;
        }

        return $app === PortalApp::PRODAZBA
            ? route('prodazba.dashboard', $company)
            : $first;
    }
```

- [ ] **Step 4: Пресели ги трите повикувачи**

Во `app/Support/AppSwitcher.php`, замени `$url = Menu::firstUrl($user, $company, $app);` со:

```php
            $url = Menu::landingUrl($user, $company, $app);
```

Во `app/Support/LandingUrl.php`, замени `return Menu::firstUrl($companies->first(), ...)` редот со:

```php
        return Menu::landingUrl($user, $companies->first(), $app) ?? route('dashboard');
```

Во `app/Livewire/Layout/Sidebar.php`, во `mount()`, замени го изразот за `$this->brandUrl`:

```php
        $this->brandUrl = $app === PortalApp::PORTAL || ! $this->company
            ? route('dashboard')
            : (Menu::landingUrl(auth()->user(), $this->company, $app) ?? route('dashboard'));
```

- [ ] **Step 5: Пушти ги тестовите**

Run: `php artisan test --filter="MenuTest|AppSwitcherTest|SalesDashboardTest|AppRootUrlTest|CompanyDashboardAppTilesTest|CompanyDashboardLandingTest"`
Expected: PASS. Ако некој постоечки тест очекува `sales-invoices.index` како влез во Продажба, **тоа е токму промената што се бара** — поправи го тој тест да очекува `prodazba.dashboard`, и запиши зошто во порака во тестот.

- [ ] **Step 6: Пушти ја целата серија**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Support/Menu.php app/Support/AppSwitcher.php app/Support/LandingUrl.php app/Livewire/Layout/Sidebar.php tests/
git commit -m "feat: кликот на Продажба слета на таблата, не во Излезни фактури

Menu::landingUrl е едното место што одговара каде се влегува во
апликација. Финансии и Плати сè уште паѓаат на првиот екран.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 8: Заедничкиот комплет за движење

**Files:**
- Modify: `resources/views/layouts/app.blade.php` (ознаката `<main>`)
- Modify: `resources/css/app.css`
- Create: `tests/Feature/MotionKitTest.php`

**Interfaces:**
- Consumes: ништо
- Produces: класата `app-main` на `<main>` и прекинувачот `motion-in`, на кои се потпираат сите правила за движење во списоците

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Создај `tests/Feature/MotionKitTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MotionKitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_the_shared_layout_carries_the_motion_hook(): void
    {
        // Самото движење живее во resources/css/app.css. Изгледот има една
        // задача: да ја носи куката на едно место, за сите 15 списоци.
        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('app-main', false);
    }

    public function test_the_entrance_switch_turns_itself_off(): void
    {
        // Редовите во списоците во најголем дел немаат wire:key, па Livewire
        // ги заменува при секое освежување. Без прекинувач што се гаси,
        // табелата би влегувала одново при секоја буква во полето за барање.
        $response = $this->actingAs($this->admin())->get(route('dashboard'));

        $response->assertSee("classList.add('motion-in')", false);
        $response->assertSee("classList.remove('motion-in')", false);
    }

    public function test_every_delayed_animation_is_switched_off_for_reduced_motion(): void
    {
        // Општото правило на дното на app.css само ја скратува траењето на
        // 0.01ms. Анимација со animation-delay и backwards под него останува
        // НЕВИДЛИВА додека чека, па мора да стои и во блокот со animation:none.
        $css = file_get_contents(resource_path('css/app.css'));

        $reducedBlock = substr($css, strpos($css, 'prefers-reduced-motion'));

        foreach (['.app-tile', '.board-link', '.app-main.motion-in table tbody tr'] as $selector) {
            $this->assertStringContainsString(
                $selector,
                $reducedBlock,
                "Анимацијата на {$selector} носи задоцнување и мора да се гаси изречно."
            );
        }
    }
}
```

- [ ] **Step 2: Пушти ги за да видиш дека паѓаат**

Run: `php artisan test --filter=MotionKitTest`
Expected: FAIL — нема `app-main` во изгледот.

- [ ] **Step 3: Закачи ја куката на изгледот**

Во `resources/views/layouts/app.blade.php`, замени ја ознаката `<main class="flex-1 p-4 sm:p-6">` со:

```blade
                {{-- app-main е куката на која се потпира целиот комплет за
                     движење во resources/css/app.css: оттаму правилата ги
                     фаќаат табелите во сите 15 списоци без да се допре ниту
                     еден од нив.

                     motion-in е прекинувач со краток век. Влезот на редовите
                     важи само додека таа класа е тука. Редовите во списоците
                     во најголем дел немаат wire:key, па Livewire ги заменува
                     при секое освежување — без гаснење, табелата би влегувала
                     одново при секоја буква во полето за барање. wire:navigate
                     ја гради страната одново, па Alpine се пушта пак и влезот
                     се гледа при секое вистинско отворање на екран. --}}
                <main class="app-main flex-1 p-4 sm:p-6"
                      x-data
                      x-init="$el.classList.add('motion-in'); setTimeout(() => $el.classList.remove('motion-in'), 700)">
```

- [ ] **Step 4: Напиши го комплетот**

Во `resources/css/app.css`, веднаш пред првиот `@media (prefers-reduced-motion: reduce)` блок:

```css
/*
 * Движењето во списоците. Закачено на .app-main во layouts/app.blade.php, па
 * важи за сите екрани со табели без ниту една измена во нив.
 *
 * Поминувањето со глувчето работи секогаш. Влезот важи само додека .motion-in
 * е присутна — види го коментарот на <main> за причината.
 */
.app-main table tbody tr {
    transition: background-color 150ms ease-out;
}

.app-main table tbody tr td {
    transition: transform 150ms ease-out;
}

.app-main table tbody tr:hover td:first-child {
    transform: translateX(2px);
}

.app-main.motion-in table tbody tr {
    animation: row-in 240ms cubic-bezier(.2, .8, .2, 1) backwards;
}

.app-main.motion-in table tbody tr:nth-child(1)  { animation-delay: 0ms; }
.app-main.motion-in table tbody tr:nth-child(2)  { animation-delay: 22ms; }
.app-main.motion-in table tbody tr:nth-child(3)  { animation-delay: 44ms; }
.app-main.motion-in table tbody tr:nth-child(4)  { animation-delay: 66ms; }
.app-main.motion-in table tbody tr:nth-child(5)  { animation-delay: 88ms; }
.app-main.motion-in table tbody tr:nth-child(6)  { animation-delay: 110ms; }
.app-main.motion-in table tbody tr:nth-child(7)  { animation-delay: 132ms; }
.app-main.motion-in table tbody tr:nth-child(8)  { animation-delay: 154ms; }
.app-main.motion-in table tbody tr:nth-child(9)  { animation-delay: 176ms; }
.app-main.motion-in table tbody tr:nth-child(10) { animation-delay: 198ms; }

/* Од единаесеттиот ред надолу сите тргнуваат заедно: подолга низа се
   претвора во чекање, а не во украс. */
.app-main.motion-in table tbody tr:nth-child(n + 11) { animation-delay: 220ms; }

@keyframes row-in {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

/*
 * Картичките во екраните исто се подигнуваат. Без анимација на влез — тие
 * не се низа, па редослед нема што да раскаже.
 */
.app-main .shadow-card {
    transition: box-shadow 200ms ease-out, transform 200ms cubic-bezier(.2, .8, .2, 1);
}
```

И додади го новиот селектор во ПРВИОТ блок за `prefers-reduced-motion`:

```css
@media (prefers-reduced-motion: reduce) {
    .app-tile,
    .board-link,
    .app-main.motion-in table tbody tr,
    .menu-group.is-open .menu-item {
        animation: none;
    }
}
```

- [ ] **Step 5: Пушти ги тестовите**

Run: `php artisan test --filter=MotionKitTest`
Expected: PASS (3 теста)

- [ ] **Step 6: Изгради и погледни со очи**

```bash
npm run build
```

Отвори еден список со многу редови (Излезни фактури) и провери три работи: редовите влегуваат еднаш при отворање; куцањето во полето за барање НЕ го рестартира влезот; поминувањето со глувчето го поместува првиот стовец. Табеларниот распоред не смее да се распадне — ако редовите скокаат странично, тргни го `transform` од `td:first-child` и остави само боја.

- [ ] **Step 7: Пушти ја целата серија**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add resources/views/layouts/app.blade.php resources/css/app.css tests/Feature/MotionKitTest.php
git commit -m "feat: заеднички комплет за движење во сите списоци

Закачен на една кука во заедничкиот изглед наместо во 15 фајла.
Влезот се гаси по 700ms, зашто редовите немаат wire:key и Livewire
ги заменува при секое освежување.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 9: Панелот АПЛИКАЦИИ

**Files:**
- Modify: `resources/views/livewire/layout/navigation.blade.php`
- Modify: `resources/css/app.css`
- Modify: `tests/Feature/AppSwitcherTest.php`

**Interfaces:**
- Consumes: `AppSwitcher::for()` (веќе враќа `key`, `label`, `url`, `accent`)
- Produces: ништо за понатаму

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Додади ги во `tests/Feature/AppSwitcherTest.php`:

```php
    public function test_the_panel_draws_a_card_per_app(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        // Однесувањето живее во resources/css/app.css. assertSee на текст не
        // би забележал изгубена класа — а класата е тоа што го носи изгледот.
        $this->actingAs($admin)
            ->get(route('companies.dashboard', $company))
            ->assertOk()
            ->assertSee('app-card', false)
            ->assertSee('Продажба')
            ->assertSee('Финансии')
            ->assertSee('Плати');
    }

    public function test_the_panel_leaves_no_blade_component_uncompiled(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('companies.dashboard', $company))
            ->assertDontSee('<x-', false);
    }
```

- [ ] **Step 2: Пушти ги за да видиш дека паѓаат**

Run: `php artisan test --filter=AppSwitcherTest`
Expected: FAIL — нема `app-card`.

- [ ] **Step 3: Пренапиши го телото на фиоката**

Во `resources/views/livewire/layout/navigation.blade.php`, во блокот `x-show="appsOpen"`:

Прво, на позадината додади замаглување — замени го:

```blade
        <div x-transition.opacity @click="appsOpen = false" class="fixed inset-0 z-40 bg-gray-900/50"></div>
```

со:

```blade
        <div x-transition.opacity @click="appsOpen = false" class="fixed inset-0 z-40 bg-gray-900/50 backdrop-blur-sm"></div>
```

Второ, на самата фиока смени ја ширината и забавувањето — замени ги трите реда:

```blade
        <div x-transition:enter="transition ease-out duration-200"
             ...
             class="fixed inset-y-0 right-0 z-50 w-72 bg-white border-l border-sand p-4 space-y-2">
```

со:

```blade
        <div x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
             class="fixed inset-y-0 right-0 z-50 w-80 bg-white border-l border-sand p-4 space-y-3">
```

Трето, замени ја целата `@foreach ($apps as $app)` јамка со:

```blade
            {{-- Описот и тонот се украс, па живеат тука. AppSwitcher останува
                 местото што одлучува КОЈ што гледа. --}}
            @php
                $appLook = [
                    'prodazba' => ['tone' => 'app-card--orange', 'text' => 'Фактури и кооперанти'],
                    'finansii' => ['tone' => 'app-card--green', 'text' => 'Книжење, извештаи и изводи'],
                    'plata' => ['tone' => 'app-card--indigo', 'text' => 'Вработени и пресметка на плата'],
                ];
            @endphp

            @foreach ($apps as $panelApp)
                @php $look = $appLook[$panelApp['key']] ?? $appLook['prodazba']; @endphp
                <a href="{{ $panelApp['url'] }}"
                   class="app-card {{ $look['tone'] }} {{ $panelApp['key'] === $currentApp ? 'is-current' : '' }} press"
                   style="--i: {{ $loop->index }}">
                    <span class="app-card__dot" aria-hidden="true"></span>
                    <span class="app-card__body">
                        <span class="app-card__label">{{ $panelApp['label'] }}</span>
                        <span class="app-card__text">{{ $look['text'] }}</span>
                    </span>
                    @if ($panelApp['key'] === $currentApp)
                        <span class="app-card__here">тука си</span>
                    @endif
                </a>
            @endforeach
```

**Внимание на готчата:** променливата на јамката е `$panelApp`, а не `$app` — во овој фајл `$app` не постои, но `@foreach` ја презапишува променливата и по јамката, па кратките имиња се избегнуваат по правило.

- [ ] **Step 4: Напиши го стилот**

Во `resources/css/app.css`, веднаш по блокот `.board-link` (пред `@keyframes` за менито):

```css
/*
 * Картичките во фиоката АПЛИКАЦИИ. Истите три тона како плочките, но тесни —
 * фиоката е 20rem широка.
 */
.app-card {
    --tone: #ff6600;
    --tone-soft: rgba(255, 102, 0, 0.16);
    display: flex;
    align-items: center;
    gap: 0.625rem;
    border-radius: 0.875rem;
    border: 1px solid #E5DDD0;
    background: #fff;
    padding: 0.625rem 0.75rem;
    transition: transform 200ms cubic-bezier(.2, .8, .2, 1), box-shadow 200ms ease-out, border-color 200ms ease-out;
    animation: tile-in 360ms cubic-bezier(.2, .8, .2, 1) backwards;
    animation-delay: calc(var(--i, 0) * 60ms);
}

.app-card--orange { --tone: #ff6600; --tone-soft: rgba(255, 102, 0, 0.16); }
.app-card--green  { --tone: #059669; --tone-soft: rgba(5, 150, 105, 0.16); }
.app-card--indigo { --tone: #4f46e5; --tone-soft: rgba(79, 70, 229, 0.16); }

.app-card:hover {
    transform: translateY(-2px);
    border-color: var(--tone-soft);
    box-shadow: 0 8px 18px -10px var(--tone-soft);
}

.app-card.is-current {
    border-color: var(--tone);
    background: #FFF8F3;
}

.app-card__dot {
    width: 0.625rem;
    height: 0.625rem;
    flex: none;
    border-radius: 9999px;
    background: var(--tone);
    box-shadow: 0 0 0 3px var(--tone-soft);
}

.app-card__body {
    flex: 1 1 auto;
    min-width: 0;
}

.app-card__label {
    display: block;
    font-size: 0.875rem;
    font-weight: 600;
    color: #1C1A17;
}

.app-card__text {
    display: block;
    font-size: 0.6875rem;
    color: #8A8177;
}

.app-card__here {
    flex: none;
    font-size: 0.625rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #8A8177;
}
```

И додади `.app-card` во ПРВИОТ блок за `prefers-reduced-motion` — и таа носи задоцнување:

```css
@media (prefers-reduced-motion: reduce) {
    .app-tile,
    .board-link,
    .app-card,
    .app-main.motion-in table tbody tr,
    .menu-group.is-open .menu-item {
        animation: none;
    }
}
```

- [ ] **Step 5: Прошири го и тестот за намалено движење**

Во `tests/Feature/MotionKitTest.php`, во `test_every_delayed_animation_is_switched_off_for_reduced_motion`, додади `'.app-card'` во низата селектори.

- [ ] **Step 6: Пушти ги тестовите**

Run: `php artisan test --filter="AppSwitcherTest|MotionKitTest"`
Expected: PASS

- [ ] **Step 7: Изгради и погледни со очи**

```bash
npm run build
```

Отвори ја фиоката АПЛИКАЦИИ. Трите картички влегуваат една по една, позадината е замаглена, тековната апликација е обоена и носи „тука си".

- [ ] **Step 8: Пушти ја целата серија**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add resources/views/livewire/layout/navigation.blade.php resources/css/app.css tests/Feature/AppSwitcherTest.php tests/Feature/MotionKitTest.php
git commit -m "feat: панелот АПЛИКАЦИИ добива картички наместо голи линкови

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Пред спојување

- [ ] **Целата серија**

```bash
php artisan test
```

- [ ] **Стилот на кодот**

```bash
vendor/bin/pint --test
```

- [ ] **Преглед**

Промените не го допираат ниту XML-от кон УЈП, ниту печатената фактура, па `/code-review` не е задолжителен по правилото од меморијата. Сепак, Task 1 (создавањето фирма) и Task 2 (правото) ја менуваат основата на секој нов клиент — пушти `/code-review` барем врз нив.

- [ ] **Со очи, во прелистувач, по `npm run build`**

1. Нов сметководител без фирма → најава → екранот за прв клиент → внес → профил на фирмата.
2. Истиот човек повторно на `/prv-klient` → се враќа на таблата.
3. Клик на „Продажба" → табла со три копчиња и три табели.
4. Фирма со исклучено Материјално → таблата се отвора, останува само Кооперанти.
5. Списокот со излезни фактури → редовите влегуваат еднаш; куцањето во барањето не ги рестартира.
6. Фиоката АПЛИКАЦИИ.
