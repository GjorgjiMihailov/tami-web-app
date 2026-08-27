# Типови профили на клиенти — фаза А: план за изведба

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Профилот на клиент добива тип — правно или физичко лице — и менито, пристапот, полињата и почетниот екран го почитуваат.

**Architecture:** Тип како enum врз нова колона на `companies`, по истиот образец како `MpinObvrznik`. Менито е веќе податок во една класа, па гранењето е на едно место. Пристапот се затвора со middleware врз групи рути, како постоечкиот `EnsureAccountingAccess`. Постоечкиот `CompanyDashboard` се дели на почетен екран со бројки и екран за уредување профил.

**Tech Stack:** Laravel 12, Livewire 3, PHPUnit 12, Tailwind, Spatie Permission.

**Spec:** `docs/superpowers/specs/2026-08-21-client-profile-types-design.md`

## Global Constraints

- Целиот текст видлив за корисник е на **македонски**. Читај ја секоја низа наглас како реченица пред да ја комитираш; внимавај на латинични букви (`а е о с р х`) внатре во кирилични зборови — невидливи се и стигнуваат до корисникот.
- Тест-рамката е **PHPUnit 12 со класи**, НЕ Pest. Нема `vendor/bin/pest`. Методите се англиски `snake_case` со префикс `test_`, тврдењата се `$this->assertSame()` и слични, никогаш `expect()->toBe()`.
- Unit тестови што не допираат база extend-аат `PHPUnit\Framework\TestCase`; сè што допира база extend-а `Tests\TestCase` со `use RefreshDatabase`.
- **`php artisan test` е скршен во worktree** — `laravel/pao` останува без меморија пред да се пушти ниту еден тест. Употребувај `php -d memory_limit=1G vendor/bin/phpunit <патека>`. **Секогаш во преден план**, никогаш во заднина.
- Целиот пакет трае **~45 минути**. Задачите вртат само свои фајлови; целиот пакет се врти еднаш, во последната задача.
- Улогите доаѓаат од Spatie. `User::factory()` **нема** состојба `admin()` — `Role::findOrCreate('admin')` во `setUp()`, потоа `$user->assignRole('admin')`.
- **Никогаш `git add -A`** — работното дрво носи неследени УЈП PDF-ови и `.claude/`. Само изречни патеки.
- По секоја измена во Blade оди `npm run build`. `/public/build` е игнорирана, па нема што да се комитира од тоа.
- Тест-пакетот е SQLite, продукцијата MySQL. Оваа фаза додава колони и middleware — буџетирај можен циклус со грешка што се гледа само во CI.
- **Оваа фаза почнува откако `payroll-5c-mpin-export` е споена во `main`.** Таа гранка го допира `CompanyDashboard`, кој задача 3 го дели на два.

---

### Task 1: Типот како enum и колона

**Files:**
- Create: `app/Support/CompanyType.php`
- Create: `database/migrations/2026_08_21_100000_add_type_to_companies_table.php`
- Modify: `app/Models/Company.php` (`$fillable`, `casts()`)
- Modify: `database/factories/CompanyFactory.php`
- Test: `tests/Unit/CompanyTypeTest.php`, `tests/Unit/Models/CompanyTest.php`

**Interfaces:**
- Consumes: ништо
- Produces:
  - `App\Support\CompanyType` — backed enum, случаи `LEGAL = 'legal'` и `INDIVIDUAL = 'individual'`, методи `label(): string`, `isIndividual(): bool`, `isLegal(): bool`
  - `Company::$type` фрлен во `CompanyType`, **не nullable**

- [ ] **Step 1: Напиши го тестот за enum-от**

`tests/Unit/CompanyTypeTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\CompanyType;
use PHPUnit\Framework\TestCase;

class CompanyTypeTest extends TestCase
{
    public function test_a_legal_entity_is_not_an_individual(): void
    {
        $this->assertTrue(CompanyType::LEGAL->isLegal());
        $this->assertFalse(CompanyType::LEGAL->isIndividual());
        $this->assertSame('legal', CompanyType::LEGAL->value);
    }

    public function test_an_individual_is_not_a_legal_entity(): void
    {
        $this->assertTrue(CompanyType::INDIVIDUAL->isIndividual());
        $this->assertFalse(CompanyType::INDIVIDUAL->isLegal());
        $this->assertSame('individual', CompanyType::INDIVIDUAL->value);
    }

    public function test_every_case_carries_its_macedonian_label(): void
    {
        $this->assertSame('Правно лице', CompanyType::LEGAL->label());
        $this->assertSame('Физичко лице', CompanyType::INDIVIDUAL->label());
    }
}
```

- [ ] **Step 2: Пушти го и потврди дека паѓа**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Unit/CompanyTypeTest.php`
Expected: FAIL — класата не постои.

- [ ] **Step 3: Напиши го enum-от**

`app/Support/CompanyType.php`:

```php
<?php

namespace App\Support;

/**
 * Што е клиентот, и оттаму што гледа.
 *
 * Правно лице е фирма: главна книга, ДДВ, залиха, плати. Физичко лице е човек
 * со ЕМБГ и без ЕДБ — приход од изнајмување, авторски хонорар, договор на дело
 * — што повремено издава и излезна фактура. Тие два профила делат многу малку,
 * па типот се чита на секое место каде разликата има значење: менито,
 * пристапот, полињата на профилот и почетниот екран.
 *
 * Се бира при создавање и никогаш не се менува — одлука на корисникот. Затоа
 * нема поле за уредување, само избор во формата за нов профил.
 */
enum CompanyType: string
{
    case LEGAL = 'legal';
    case INDIVIDUAL = 'individual';

    public function label(): string
    {
        return match ($this) {
            self::LEGAL => 'Правно лице',
            self::INDIVIDUAL => 'Физичко лице',
        };
    }

    public function isLegal(): bool
    {
        return $this === self::LEGAL;
    }

    public function isIndividual(): bool
    {
        return $this === self::INDIVIDUAL;
    }
}
```

- [ ] **Step 4: Пушти ги тестовите**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Unit/CompanyTypeTest.php`
Expected: PASS

- [ ] **Step 5: Напиши ја миграцијата**

`database/migrations/2026_08_21_100000_add_type_to_companies_table.php`:

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
            // Стандардната вредност ги опишува постоечките редови: секој профил
            // отворен пред оваа колона е правно лице, зашто друг вид немаше.
            // Останува како стандардна и потоа — формата за нов профил бара
            // изречен избор, па стандардната никогаш не решава наместо човек.
            $table->string('type', 16)->default('legal')->after('short_name');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
```

- [ ] **Step 6: Врзи го на моделот и во фабриката**

`app/Models/Company.php` — додај `'type'` во `$fillable`, и во `casts()`:

```php
'type' => \App\Support\CompanyType::class,
```

`database/factories/CompanyFactory.php` — додај во `definition()`:

```php
// Фабриката прави правно лице, зашто така изгледа секој постоечки тест.
// Тест за физичко лице го поставува изречно.
'type' => \App\Support\CompanyType::LEGAL,
```

- [ ] **Step 7: Напиши го тестот за моделот**

Додај во `tests/Unit/Models/CompanyTest.php` (ако тој фајл не постои, создај го со `namespace Tests\Unit\Models;`, `extends Tests\TestCase`, `use RefreshDatabase`):

```php
    public function test_the_type_is_cast_to_the_enum(): void
    {
        $company = \App\Models\Company::factory()->create([
            'type' => \App\Support\CompanyType::INDIVIDUAL,
        ]);

        $this->assertSame(\App\Support\CompanyType::INDIVIDUAL, $company->fresh()->type);
    }

    public function test_a_profile_created_without_a_type_is_a_legal_entity(): void
    {
        $company = \App\Models\Company::create(['name' => 'ТЕСТ ДООЕЛ']);

        $this->assertSame(\App\Support\CompanyType::LEGAL, $company->fresh()->type);
    }
```

- [ ] **Step 8: Пушти ги тестовите**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Unit/CompanyTypeTest.php tests/Unit/Models/CompanyTest.php`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add app/Support/CompanyType.php database/migrations/2026_08_21_100000_add_type_to_companies_table.php app/Models/Company.php database/factories/CompanyFactory.php tests/Unit/CompanyTypeTest.php tests/Unit/Models/CompanyTest.php
git commit -m "feat(profiles): give a client profile its type"
```

---

### Task 2: Изборот на тип при создавање профил

**Files:**
- Modify: `app/Livewire/CompanyIndex.php`
- Modify: `resources/views/livewire/company-index.blade.php`
- Test: `tests/Feature/CompanyIndexTest.php`

**Interfaces:**
- Consumes: `CompanyType` од Task 1
- Produces: нов профил секогаш носи изречно избран тип

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Додај во `tests/Feature/CompanyIndexTest.php`. Прочитај го фајлот прво — тој веќе има `setUp()` со улогите и помошник за админ; употреби ги, не дуплирај ги.

```php
    public function test_a_new_profile_records_the_chosen_type(): void
    {
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newName', 'Марко Марковски')
            ->set('newType', 'individual')
            ->call('addCompany')
            ->assertHasNoErrors();

        $this->assertSame(
            \App\Support\CompanyType::INDIVIDUAL,
            Company::where('name', 'Марко Марковски')->first()->type,
        );
    }

    public function test_the_type_must_be_chosen(): void
    {
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newName', 'ТЕСТ ДООЕЛ')
            ->set('newType', '')
            ->call('addCompany')
            ->assertHasErrors('newType');
    }

    public function test_an_unknown_type_is_rejected(): void
    {
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newName', 'ТЕСТ ДООЕЛ')
            ->set('newType', 'something-else')
            ->call('addCompany')
            ->assertHasErrors('newType');
    }
```

Ако фајлот нема `actAsAdmin()`, погледни го `tests/Feature/EmployeeFormTest.php` — таму постои токму таков приватен помошник — и напиши го истиот тука.

- [ ] **Step 2: Пушти ги и потврди дека паѓаат**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/CompanyIndexTest.php`
Expected: FAIL — својството `newType` не постои.

- [ ] **Step 3: Додај го полето во компонентата**

`app/Livewire/CompanyIndex.php`:

```php
public string $newType = '';
```

Во `addCompany()`, во низата за валидација:

```php
'newType' => ['required', Rule::enum(\App\Support\CompanyType::class)],
```

Во низата што го создава профилот:

```php
'type' => $validated['newType'],
```

Ако `Rule` не е внесен во фајлот, додај `use Illuminate\Validation\Rule;`.

- [ ] **Step 4: Додај го изборот во Blade**

`resources/views/livewire/company-index.blade.php`, во формата за нов профил, **прво поле** — типот го одредува сè друго, па стои најгоре:

```blade
<label class="block">
    <span class="block text-sm text-gray-700">Вид на клиент</span>
    <select wire:model="newType" class="mt-1 w-full rounded border-gray-300 text-sm">
        <option value="">— изберете —</option>
        @foreach (\App\Support\CompanyType::cases() as $case)
            <option value="{{ $case->value }}">{{ $case->label() }}</option>
        @endforeach
    </select>
    <span class="mt-1 block text-xs text-gray-500">
        Не може да се смени подоцна.
    </span>
    @error('newType')
        <span class="mt-1 block text-sm text-red-600">{{ $message }}</span>
    @enderror
</label>
```

Погледни како изгледаат постоечките полиња во тој фајл и следи ги нивните класи наместо овие, ако се разликуваат — доследноста со соседите е поважна од буквалниот код овде.

- [ ] **Step 5: Изгради ги стиловите**

Run: `npm run build`

- [ ] **Step 6: Пушти ги тестовите**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/CompanyIndexTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/CompanyIndex.php resources/views/livewire/company-index.blade.php tests/Feature/CompanyIndexTest.php
git commit -m "feat(profiles): choose the client type when a profile is created"
```

---

### Task 3: Раздвојување на почетниот екран од уредувањето профил

**Files:**
- Create: `app/Livewire/CompanyProfile.php`
- Create: `resources/views/livewire/company-profile.blade.php`
- Modify: `app/Livewire/CompanyDashboard.php`
- Modify: `resources/views/livewire/company-dashboard.blade.php`
- Modify: `routes/web.php`
- Modify: `app/Support/Menu.php` (ставката „Компанија" покажува на новата рута)
- Test: `tests/Feature/CompanyProfileTest.php`, и постоечките `CompanyDashboard*Test.php`

**Interfaces:**
- Consumes: `CompanyType` од Task 1
- Produces:
  - рута `companies.profile` на `/companies/{company}/profile`
  - `App\Livewire\CompanyProfile` — целото уредување на профилот
  - `App\Livewire\CompanyDashboard` — само почетен екран, без форма

**Зошто:** `CompanyDashboard` денес е и почетен екран и форма за уредување (име, адреса, лого, банкарски сметки, е-Фактура режим, потписен уред) и е околу 300 линии. Задачите 7–9 му додаваат бројки. Без делење, тој фајл станува нечитлив.

- [ ] **Step 1: Прочитај го постоечкиот екран пред да мрднеш нешто**

Прочитај ги целите `app/Livewire/CompanyDashboard.php` и `resources/views/livewire/company-dashboard.blade.php`. Забележи ги сите јавни методи (`mount`, `startEdit`, `cancelEdit`, `save`, `updated`, `requestFirmEfakturaAccess`, `registerSigningDevice`) и сите `edit*` својства. **Сето уредување се сели, ништо не се препишува.**

Постојат четири тест-фајла што го покриваат тој екран: `CompanyDashboardTest`, `CompanyDashboardStructuredAddressTest`, `CompanyDashboardSigningDeviceTest`, `CompanyDashboardEfakturaRequestTest`, плус `CompanyDashboardMpinObvrznikTest` од фаза 5c. **Тие ќе паднат** штом компонентата се преименува — тоа е очекувано и е дел од задачата.

- [ ] **Step 2: Пресели го уредувањето во нова компонента**

Создај `app/Livewire/CompanyProfile.php` со **точно** својствата и методите за уредување од `CompanyDashboard`, непроменети. Изгледот се сели во `resources/views/livewire/company-profile.blade.php`.

Не менувај однесување во оваа задача. Единствената промена е каде живее кодот. Секое подобрување што ти паѓа на памет оди во следна задача, не во оваа — инаку прегледот не може да разликува преселба од измена.

- [ ] **Step 3: Испразни го почетниот екран**

`CompanyDashboard` останува со `mount()` и `render()`. Приказот засега прикажува само име на профилот и неговиот тип:

```blade
<div class="p-4">
    <h1 class="text-lg font-medium text-gray-900">{{ $company->name }}</h1>
    <p class="mt-1 text-sm text-gray-500">{{ $company->type->label() }}</p>
</div>
```

Бројките доаѓаат во задачите 7–9.

- [ ] **Step 4: Регистрирај ја рутата**

`routes/web.php`, во групата на ред ~74 (`prefix('companies/{company}')`, без именски префикс), до постоечката `companies.dashboard`:

```php
Route::get('/profile', [CompanyProfile::class, '__invoke'])->name('companies.profile');
```

Внеси ја класата на врвот од фајлот како соседите.

- [ ] **Step 5: Пренасочи ја ставката во менито**

`app/Support/Menu.php`, во групата ПОСТАВКИ, ставката „Компанија":

```php
['label' => 'Компанија', 'url' => route('companies.profile', $company), 'pattern' => 'companies.profile', 'roles' => null],
```

- [ ] **Step 6: Пресели ги тестовите**

Четирите (или петте) `CompanyDashboard*Test` фајлови сега тестираат `CompanyProfile`. Преименувај ги во `CompanyProfile*Test`, промени го `Livewire::test(CompanyDashboard::class, ...)` во `CompanyProfile::class`, и **не менувај ниту едно тврдење** — тие докажуваат дека преселбата ништо не скршила. Ако некое тврдење бара измена за да помине, преселбата не е чиста; застани и пријави.

- [ ] **Step 7: Напиши тест дека почетниот екран го покажува типот**

`tests/Feature/CompanyProfileTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Livewire\CompanyDashboard;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_the_landing_screen_names_the_profile_type(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create([
            'name' => 'Марко Марковски',
            'type' => CompanyType::INDIVIDUAL,
        ]);

        Livewire::actingAs($admin)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->assertSee('Марко Марковски')
            ->assertSee('Физичко лице');
    }
}
```

- [ ] **Step 8: Изгради ги стиловите и пушти сè што го допира екранот**

Run: `npm run build`
Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature --filter CompanyProfile`
Потоа: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/SidebarTest.php`
Expected: PASS и на двете.

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/CompanyProfile.php app/Livewire/CompanyDashboard.php resources/views/livewire/company-profile.blade.php resources/views/livewire/company-dashboard.blade.php routes/web.php app/Support/Menu.php tests/Feature
git commit -m "refactor(profiles): split the landing screen from profile editing"
```

---

### Task 4: ЕМБГ и полиња што важат по тип

**Files:**
- Create: `database/migrations/2026_08_21_100100_add_embg_to_companies_table.php`
- Modify: `app/Models/Company.php` (`$fillable`)
- Modify: `app/Livewire/CompanyProfile.php`
- Modify: `resources/views/livewire/company-profile.blade.php`
- Test: `tests/Feature/CompanyProfileFieldsTest.php`

**Interfaces:**
- Consumes: `CompanyType`, `CompanyProfile` од Task 3
- Produces: `Company::$embg` (`?string`)

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

`tests/Feature/CompanyProfileFieldsTest.php`. Следи го шаблонот на `tests/Feature/CompanyProfileStructuredAddressTest.php` (преименуван во Task 3) за `setUp()` и доделување улога.

```php
    public function test_an_individual_profile_stores_a_valid_embg(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('editEmbg', '3101980455019')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('3101980455019', $company->fresh()->embg);
    }

    public function test_an_invalid_embg_is_rejected(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('editEmbg', '1234567890123')
            ->call('save')
            ->assertHasErrors('editEmbg');
    }

    public function test_a_legal_profile_does_not_show_the_embg_field(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::LEGAL]);

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->assertDontSee('ЕМБГ');
    }

    public function test_an_individual_profile_does_not_show_the_company_only_fields(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->assertDontSee('НКД')
            ->assertDontSee('Директор');
    }
```

`3101980455019` е ЕМБГ со точна контролна цифра — истиот што го користи `EmployeeFactory`. `1234567890123` намерно не е.

- [ ] **Step 2: Пушти ги и потврди дека паѓаат**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/CompanyProfileFieldsTest.php`
Expected: FAIL — својството `editEmbg` не постои.

- [ ] **Step 3: Напиши ја миграцијата**

```php
Schema::table('companies', function (Blueprint $table) {
    $table->string('embg', 13)->nullable()->after('tax_id');
});
```

`down()` го брише полето. Додај `'embg'` во `$fillable` на `Company`.

- [ ] **Step 4: Додај го полето во компонентата**

`app/Livewire/CompanyProfile.php`:

```php
public string $editEmbg = '';
```

Во полнењето на `edit*` својствата:

```php
$this->editEmbg = (string) $this->company->embg;
```

Во правилата — задолжително за физичко лице, забрането за правно:

```php
'editEmbg' => $this->company->type->isIndividual()
    ? ['required', new \App\Rules\ValidEmbg]
    : ['nullable'],
```

Во зачувувањето:

```php
'embg' => $validated['editEmbg'] ?: null,
```

- [ ] **Step 5: Гранај ги полињата во Blade**

`resources/views/livewire/company-profile.blade.php`. Полињата за правно лице — ЕДБ, матичен број, НКД шифра, НКД име, директор (име, телефон, е-пошта), ДДВ обврзник, е-Фактура режим — се завиткуваат во:

```blade
@if ($company->type->isLegal())
    {{-- постоечките полиња, непроменети --}}
@endif
```

Полето за ЕМБГ се завиткува во `@if ($company->type->isIndividual())`.

Заедничките полиња — име, адреса, е-пошта, телефон, банкарски сметки, лого — остануваат надвор од обете гранки.

- [ ] **Step 6: Изгради и пушти**

Run: `npm run build`
Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature --filter CompanyProfile`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_21_100100_add_embg_to_companies_table.php app/Models/Company.php app/Livewire/CompanyProfile.php resources/views/livewire/company-profile.blade.php tests/Feature/CompanyProfileFieldsTest.php
git commit -m "feat(profiles): an individual profile carries an ЕМБГ, not a company registry"
```

---

### Task 5: Менито гранка по тип

**Files:**
- Modify: `app/Support/Menu.php`
- Test: `tests/Feature/MenuByTypeTest.php`

**Interfaces:**
- Consumes: `CompanyType` од Task 1
- Produces: `Menu::for()` враќа различно дрво по тип

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

`tests/Feature/MenuByTypeTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\CompanyType;
use App\Support\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MenuByTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function groupsFor(CompanyType $type): array
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['type' => $type]);

        return array_column(Menu::for($admin, $company), 'label');
    }

    public function test_a_legal_entity_sees_the_full_menu(): void
    {
        $groups = $this->groupsFor(CompanyType::LEGAL);

        $this->assertContains('ФИНАНСИИ', $groups);
        $this->assertContains('ПРОДАЖБА', $groups);
        $this->assertContains('ТРОШОЦИ', $groups);
        $this->assertContains('ЗАЛИХА', $groups);
        $this->assertContains('ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ', $groups);
    }

    public function test_an_individual_sees_neither_bookkeeping_nor_stock_nor_payroll(): void
    {
        $groups = $this->groupsFor(CompanyType::INDIVIDUAL);

        $this->assertNotContains('ФИНАНСИИ', $groups);
        $this->assertNotContains('ТРОШОЦИ', $groups);
        $this->assertNotContains('ЗАЛИХА', $groups);
        $this->assertNotContains('ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ', $groups);
    }

    public function test_an_individual_sees_sales_bank_documents_and_filings(): void
    {
        $groups = $this->groupsFor(CompanyType::INDIVIDUAL);

        $this->assertContains('ПРОДАЖБА', $groups);
        $this->assertContains('БАНКАРСКИ ДОКУМЕНТИ', $groups);
        $this->assertContains('ПРИЈАВИ', $groups);
        $this->assertContains('ПОСТАВКИ', $groups);
    }

    /**
     * Влезните фактури беа во ПРОДАЖБА; барањето беше да се одвојат. Овој тест
     * паѓа ако некој ги врати назад.
     */
    public function test_purchase_invoices_moved_out_of_sales(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['type' => CompanyType::LEGAL]);

        $tree = collect(Menu::for($admin, $company))->keyBy('label');

        $sales = array_column($tree['ПРОДАЖБА']['items'], 'label');
        $costs = array_column($tree['ТРОШОЦИ']['items'], 'label');

        $this->assertNotContains('Влезни фактури', $sales);
        $this->assertContains('Влезни фактури', $costs);
    }
}
```

- [ ] **Step 2: Пушти ги и потврди дека паѓаат**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/MenuByTypeTest.php`
Expected: FAIL — нема група ТРОШОЦИ, и физичкото лице го добива истото дрво.

- [ ] **Step 3: Прошири ги ставките „наскоро"**

`app/Support/Menu.php`, во `SOON_FEATURES` — преименувај го `izvodi` и додај го `743`:

```php
'izvodi' => [
    'label' => 'Банкарски документи',
    'sentence' => 'Овде ќе се прикачуваат и прегледуваат изводите од банка, денарски и девизни.',
],
'obrazec-743' => [
    'label' => '743 обрасци',
    'sentence' => 'Овде клиентот ќе ги прикачува обрасците 743 добиени од банка.',
],
'drugi-trosoci' => [
    'label' => 'Други трошоци',
    'sentence' => 'Овде ќе се внесуваат трошоци што не доаѓаат преку влезна фактура, со прикачување на документ.',
],
```

`profakturi`, `popis` и `e-pdd` остануваат како што се.

- [ ] **Step 4: Гранај го дрвото**

`tree(Company $company)` станува гранка врз типот. Извади ги двете дрва во два приватни метода — `legalTree()` и `individualTree()` — за да остане секое читливо:

```php
private static function tree(Company $company): array
{
    return $company->type->isIndividual()
        ? self::individualTree($company)
        : self::legalTree($company);
}
```

`legalTree()` е денешното дрво со **една измена**: „Влезни фактури" излегува од ПРОДАЖБА и добива своја група веднаш по неа:

```php
[
    'key' => 'costs',
    'label' => 'ТРОШОЦИ',
    'items' => [
        ['label' => 'Влезни фактури', 'url' => route('purchase-invoices.index', $company), 'pattern' => 'purchase-invoices.*', 'roles' => null],
        self::soon($company, 'drugi-trosoci'),
    ],
],
```

`individualTree()`:

```php
private static function individualTree(Company $company): array
{
    return [
        [
            'key' => 'sales',
            'label' => 'ПРОДАЖБА',
            'items' => [
                ['label' => 'Излезни фактури', 'url' => route('sales-invoices.index', $company), 'pattern' => 'sales-invoices.*', 'roles' => null],
                ['label' => 'Кооперанти', 'url' => route('partners.index', $company), 'pattern' => 'partners.*', 'roles' => null],
            ],
        ],
        [
            'key' => 'bank',
            'label' => 'БАНКАРСКИ ДОКУМЕНТИ',
            'items' => [
                self::soon($company, 'obrazec-743'),
            ],
        ],
        [
            'key' => 'filings',
            'label' => 'ПРИЈАВИ',
            'items' => [
                self::soon($company, 'e-pdd') + ['roles' => ['admin', 'accountant']],
            ],
        ],
        [
            'key' => 'settings',
            'label' => 'ПОСТАВКИ',
            'items' => [
                ['label' => 'Профил', 'url' => route('companies.profile', $company), 'pattern' => 'companies.profile', 'roles' => null],
            ],
        ],
    ];
}
```

- [ ] **Step 5: Сокриј ги Документи за физичко лице**

`tests/Feature/SidebarTest.php::test_documents_stands_alone_outside_the_groups` покажува дека Документи се прикажува вон групите. Најди го тоа место во изгледот на страничната лента и завиткај го во `@if (! $company->type->isIndividual())`.

- [ ] **Step 6: Изгради и пушти**

Run: `npm run build`
Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/MenuByTypeTest.php tests/Feature/SidebarTest.php`
Expected: PASS. Ако некој постоечки тест во `SidebarTest` падне, најверојатно очекува „Влезни фактури" во ПРОДАЖБА — тоа е намерната измена, поправи го тврдењето.

- [ ] **Step 7: Commit**

```bash
git add app/Support/Menu.php resources/views tests/Feature/MenuByTypeTest.php tests/Feature/SidebarTest.php
git commit -m "feat(profiles): branch the menu on the client type"
```

---

### Task 6: Пристапот на серверско ниво

**Files:**
- Create: `app/Http/Middleware/EnsureLegalEntity.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/IndividualProfileAccessTest.php`

**Interfaces:**
- Consumes: `CompanyType` од Task 1
- Produces: middleware `EnsureLegalEntity`

**Зошто:** менито што крие не е заштита. Истата класа дупка беше вистинска пред плановите 1 и 2 — клиент добиваше HTTP 200 на контниот план преку позната адреса — и се затвори дури откако тест ја докажа.

- [ ] **Step 1: Напиши го тестот што паѓа**

`tests/Feature/IndividualProfileAccessTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IndividualProfileAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public static function forbiddenRoutes(): array
    {
        return [
            'контен план' => ['accounting.accounts.index'],
            'налози' => ['accounting.journal-groups.index'],
            'извештаи' => ['reports.index'],
            'магацини' => ['inventory.warehouses.index'],
            'артикли' => ['inventory.items.index'],
            'вработени' => ['employees.index'],
            'плати' => ['payroll-runs.index'],
            'параметри за плата' => ['payroll-parameters.index'],
            'влезни фактури' => ['purchase-invoices.index'],
            'документи' => ['documents.index'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenRoutes')]
    public function test_an_individual_profile_refuses_a_screen_that_does_not_apply(string $route): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        $this->actingAs($this->admin())
            ->get(route($route, $company))
            ->assertForbidden();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenRoutes')]
    public function test_a_legal_profile_still_reaches_the_same_screen(string $route): void
    {
        $company = Company::factory()->create(['type' => CompanyType::LEGAL]);

        $this->actingAs($this->admin())
            ->get(route($route, $company))
            ->assertOk();
    }

    public function test_an_individual_profile_still_reaches_its_own_screens(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('sales-invoices.index', $company))->assertOk();
        $this->actingAs($admin)->get(route('partners.index', $company))->assertOk();
        $this->actingAs($admin)->get(route('companies.profile', $company))->assertOk();
    }
}
```

Провери го точното име на секоја рута со `php artisan route:list --name=<дел>` пред да се потпреш на неа; поправи го списокот ако некое се разликува.

**Вториот тест е тој што вреди најмногу**: без него, middleware што забранува сè би поминал.

- [ ] **Step 2: Пушти ги и потврди дека паѓаат**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/IndividualProfileAccessTest.php`
Expected: FAIL — физичкото лице добива 200 наместо 403.

- [ ] **Step 3: Напиши го middleware-от**

`app/Http/Middleware/EnsureLegalEntity.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Екраните што важат само за фирма не се достапни на профил на физичко лице.
 *
 * Ова е вистинската брана. Криењето во менито само спречува кликање — без ова,
 * секој од тие екрани останува достапен со впишување адреса или со стар
 * обележувач. Истата дупка беше вистинска за улогата клиент пред да се затвори
 * со `EnsureAccountingAccess`, и беше затворена дури откако тест ја докажа.
 *
 * Се применува врз цели групи рути, за екран додаден подоцна да биде покриен
 * стандардно наместо со сеќавање.
 */
class EnsureLegalEntity
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->route('company');

        abort_if(
            $company instanceof Company && $company->type->isIndividual(),
            403,
            'Овој екран важи само за профил на правно лице.'
        );

        return $next($request);
    }
}
```

- [ ] **Step 4: Примени го врз групите рути**

`routes/web.php`. Додај `EnsureLegalEntity::class` во низата middleware на групите: `accounting.`, `reports.`, `inventory.`, `employees.`, `payroll-parameters.`, `payroll.`, `payroll-runs.`, `purchase-invoices.`, `incoming-efaktura.`, `documents.`.

**Не** го додавај на: групата на ред ~74 (`companies.dashboard`, `companies.profile`), `sales-invoices.`, `partners.`.

Внеси ја класата на врвот од фајлот.

- [ ] **Step 5: Пушти ги тестовите**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/IndividualProfileAccessTest.php`
Expected: PASS

- [ ] **Step 6: Пушти го сето што допира рути**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/AccountingAccessTest.php tests/Feature/AccountingRoutesTest.php tests/Feature/PayrollAccessTest.php tests/Feature/Payroll`
Expected: PASS — фабриката прави правно лице, па ниту еден постоечки тест не се менува.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/EnsureLegalEntity.php routes/web.php tests/Feature/IndividualProfileAccessTest.php
git commit -m "feat(profiles): refuse a company screen on an individual profile"
```

---

### Task 7: Пресметките за почетниот екран

**Files:**
- Create: `app/Services/CompanyDashboardQuery.php`
- Test: `tests/Feature/CompanyDashboardQueryTest.php`

**Interfaces:**
- Consumes: `Company`, `WorkingYear`
- Produces: `CompanyDashboardQuery` со методи што враќаат `string` (bcmath) или `int`:
  - `revenue(Company $c, int $year): string`
  - `costs(Company $c, int $year): string`
  - `receivable(Company $c, int $year): string`
  - `receivableOverdue(Company $c, int $year): string`
  - `payable(Company $c, int $year): string`
  - `payableOverdue(Company $c, int $year): string`
  - `efakturaFailed(Company $c, int $year): int`

**Одлука што не смее да се заобиколи:** износите се собираат преку постоечкиот `App\Models\Concerns\HasInvoiceTotals` (`total()`), а **не** со SQL збир над `*_invoice_lines`. Тој трејт заокружува по ставка со bcmath; SQL збир би се разидувал за денар од она што фактурата го покажува на својот екран. Тоа е истата класа грешка што фазата 5c ја имаше меѓу ниво 2 и ниво 3 на МПИН, и цената е реална.

Значи заврти ги фактурите со `->with('lines')` и собери во PHP. За канцеларија со стотици фактури годишно тоа е сосема доволно; ако некогаш не биде, оптимизацијата е на **едно** место.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

`tests/Feature/CompanyDashboardQueryTest.php`.

Една ставка од фабриката е количина `1.000` по цена `50000.00` со ДДВ `18.00`, што значи `lineTotal()` = 50.000, ДДВ = 9.000 и `total()` = **59.000**. Сите очекувани бројки подолу произлегуваат од тоа.

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesInvoicePayment;
use App\Services\CompanyDashboardQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyDashboardQueryTest extends TestCase
{
    use RefreshDatabase;

    /** Потврдена излезна фактура со една ставка од 59.000 вкупно. */
    private function invoice(
        Company $company,
        string $date,
        string $status = 'confirmed',
        ?string $dueDate = null,
    ): SalesInvoice {
        $invoice = SalesInvoice::factory()->for($company)->create([
            'invoice_date' => $date,
            'due_date' => $dueDate ?? $date,
            'status' => $status,
        ]);

        SalesInvoiceLine::factory()->for($invoice, 'salesInvoice')->create([
            'quantity' => '1.000',
            'unit_price' => '50000.00',
            'vat_rate' => '18.00',
        ]);

        return $invoice->fresh();
    }

    public function test_revenue_counts_only_confirmed_invoices_of_the_working_year(): void
    {
        $company = Company::factory()->create();

        $this->invoice($company, '2026-03-10');
        $this->invoice($company, '2026-04-10');
        // Нацртот не е приход додека не е издаден.
        $this->invoice($company, '2026-05-10', 'draft');

        $this->assertSame('118000.00', CompanyDashboardQuery::revenue($company, 2026));
    }

    public function test_revenue_excludes_another_year(): void
    {
        $company = Company::factory()->create();

        $this->invoice($company, '2025-11-10');
        $this->invoice($company, '2026-03-10');

        $this->assertSame('59000.00', CompanyDashboardQuery::revenue($company, 2026));
    }

    public function test_receivable_is_what_is_invoiced_minus_what_is_paid(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->invoice($company, '2026-03-10');

        SalesInvoicePayment::factory()->for($invoice, 'salesInvoice')->create([
            'amount' => '20000.00',
            'paid_on' => '2026-03-20',
        ]);

        $this->assertSame('39000.00', CompanyDashboardQuery::receivable($company, 2026));
    }

    public function test_only_a_past_due_date_counts_as_overdue(): void
    {
        Carbon::setTestNow('2026-06-15');

        $company = Company::factory()->create();
        $this->invoice($company, '2026-03-10', 'confirmed', '2026-04-10');
        $this->invoice($company, '2026-03-10', 'confirmed', '2026-12-31');

        $this->assertSame('118000.00', CompanyDashboardQuery::receivable($company, 2026));
        $this->assertSame('59000.00', CompanyDashboardQuery::receivableOverdue($company, 2026));

        Carbon::setTestNow();
    }

    public function test_a_failed_efaktura_send_is_counted(): void
    {
        $company = Company::factory()->create();

        $this->invoice($company, '2026-03-10')->update(['efaktura_status' => 'failed']);
        $this->invoice($company, '2026-04-10');
        $this->invoice($company, '2026-05-10');

        $this->assertSame(1, CompanyDashboardQuery::efakturaFailed($company, 2026));
    }
}
```

Провери ги имињата на колоните во `SalesInvoicePaymentFactory` пред да се потпреш на `amount`/`paid_on`; ако се разликуваат, употреби ги вистинските и остави ги бројките исти.

Трошоците и обврските кон добавувачи се истата шема врз `PurchaseInvoice` — напиши ги по истиот калап откако горните ќе позеленат, за да не пишуваш шест теста пред да видиш еден како поминува.

- [ ] **Step 2: Пушти ги и потврди дека паѓаат**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/CompanyDashboardQueryTest.php`
Expected: FAIL — класата не постои.

- [ ] **Step 3: Напиши ја класата**

Сите методи се статички, секој со свој кус docblock што кажува **што брои и што намерно не брои**. Приходот брои потврдени излезни фактури чија `invoice_date` е во работната година; трошокот истото за влезни. Ненаплатеното е збир на `total()` минус збир на уплатите; доспеаното е истото ограничено на `due_date < today`.

`efakturaFailed()` брои излезни фактури со `efaktura_status = 'failed'` во таа година.

- [ ] **Step 4: Пушти ги тестовите**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/CompanyDashboardQueryTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/CompanyDashboardQuery.php tests/Feature/CompanyDashboardQueryTest.php
git commit -m "feat(profiles): compute the figures the landing screen shows"
```

---

### Task 8: Почетниот екран на правно лице

**Files:**
- Modify: `app/Livewire/CompanyDashboard.php`
- Modify: `resources/views/livewire/company-dashboard.blade.php`
- Test: `tests/Feature/CompanyDashboardTilesTest.php`

**Interfaces:**
- Consumes: `CompanyDashboardQuery` од Task 7, `Ddv04Query`, извештајот „Состојба"
- Produces: ништо ново

- [ ] **Step 1: Напиши го тестот што паѓа**

```php
    public function test_a_legal_profile_shows_the_money_tiles(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::LEGAL]);
        $this->invoice($company, '2026-03-10');   // истиот помошник како во Task 7

        Livewire::actingAs($this->admin())
            ->test(CompanyDashboard::class, ['company' => $company])
            ->assertSee('Приход')
            ->assertSee('59.000')
            ->assertSee('Ненаплатено');
    }
```

Формата на бројот мора да се совпаѓа со онаа што апликацијата веќе ја користи — прочитај го `app/Support/Format.php` (има `tests/Unit/FormatTest.php`) и употреби го истиот помошник, не свој.

- [ ] **Step 2: Пушти и потврди дека паѓа**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/CompanyDashboardTilesTest.php`
Expected: FAIL

- [ ] **Step 3: Пополни го `render()`**

`CompanyDashboard::render()` ја зема работната година преку `App\Support\WorkingYear` (прочитај како другите екрани ја земаат) и ги предава бројките во приказот. Осум плочки: приход, трошоци, разлика, ненаплатено (со доспеан дел), обврски (со доспеан дел), ДДВ за тековниот период преку `Ddv04Query`, вредност на залиха преку постоечкиот извештај, и неуспешни е-Фактура испраќања.

Секоја плочка е врска кон екранот што ја објаснува — ненаплатеното кон излезните фактури, неуспешните е-Фактури кон истиот список филтриран. Бројка што не води никаде тера рачно барање.

- [ ] **Step 4: Изгради и пушти**

Run: `npm run build`
Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/CompanyDashboardTilesTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/CompanyDashboard.php resources/views/livewire/company-dashboard.blade.php tests/Feature/CompanyDashboardTilesTest.php
git commit -m "feat(profiles): give a company profile a landing screen worth opening"
```

---

### Task 9: Почетниот екран на физичко лице и затворање на фазата

**Files:**
- Modify: `app/Livewire/CompanyDashboard.php`
- Modify: `resources/views/livewire/company-dashboard.blade.php`
- Test: `tests/Feature/CompanyDashboardTilesTest.php`

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

```php
    public function test_an_individual_profile_shows_income_and_what_is_owed(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
        $this->invoice($company, '2026-03-10');

        Livewire::actingAs($this->admin())
            ->test(CompanyDashboard::class, ['company' => $company])
            ->assertSee('Приход')
            ->assertSee('Ненаплатено');
    }

    public function test_an_individual_profile_does_not_show_the_company_tiles(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        Livewire::actingAs($this->admin())
            ->test(CompanyDashboard::class, ['company' => $company])
            ->assertDontSee('Вредност на залиха')
            ->assertDontSee('ДДВ');
    }

    public function test_an_individual_profile_names_what_is_still_missing(): void
    {
        // Празно ветување е полошо од ништо; именувано ветување му кажува на
        // сметководителот што допрва доаѓа.
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        Livewire::actingAs($this->admin())
            ->test(CompanyDashboard::class, ['company' => $company])
            ->assertSee('Поднесени пријави')
            ->assertSee('наскоро');
    }
```

- [ ] **Step 2: Пушти и потврди дека паѓаат**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/CompanyDashboardTilesTest.php`
Expected: FAIL

- [ ] **Step 3: Гранај го приказот**

Две вистински плочки — приход за работната година и ненаплатено — плус јасно означен блок што ги именува преостанатите: поднесени пријави, обработени пријави, износ на ДЛД, примени 743 обрасци. Тие се сиви, без бројки, со зборот „наскоро".

- [ ] **Step 4: Изгради ги стиловите**

Run: `npm run build`

- [ ] **Step 5: Пушти го целиот пакет**

Run: `php -d memory_limit=1G vendor/bin/phpunit`

**Во преден план, не во заднина.** Трае околу 45 минути. Пријави ги бројките точно како што излегуваат. Ако падне нешто надвор од профилите, **не поправај тивко** — пријави го излезот.

- [ ] **Step 6: Дополни ја спецификацијата ако нешто отстапило**

Ако при изведбата се појавила разлика од `docs/superpowers/specs/2026-08-21-client-profile-types-design.md`, запиши ја таму со причината. Спецификацијата е она што фазите Б–Д ќе го читаат.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/CompanyDashboard.php resources/views/livewire/company-dashboard.blade.php tests/Feature/CompanyDashboardTilesTest.php docs/superpowers/specs/2026-08-21-client-profile-types-design.md
git commit -m "feat(profiles): give an individual profile the two figures that are real"
```

---

## По планот

Пред спојување:

1. Цел пакет зелен.
2. Преглед на целата гранка.
3. Корисникот отвора **вистински профил на физичко лице**, го внесува ЕМБГ, издава фактура и гледа дали почетниот екран ја покажува. Тоа е потврдата што ниту еден тест не ја дава.

Фазите Б (банкарски документи), В (други трошоци), Г (е-ПДД) и Д (полни dashboard-и) се градат врз ова, по кој било ред.
