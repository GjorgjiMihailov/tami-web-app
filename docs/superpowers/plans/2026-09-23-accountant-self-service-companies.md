# Сметководител сам ги доделува фирмите — план за изведба

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сметководител сам создава и целосно поставува (профил, модули, корисници) секоја фирма на која работи, без одобрување од админ — контролата останува кај админот, но по факт (тој гледа сè), не пред факт.

**Architecture:** Едно правило (`CompanyPolicy::update`) станува вратата за целата поставка на фирмата и веќе е искористено на три екрана (Профил, Модули); проширувањето таму автоматски ги отвора сите три. `CompanyUsers` и споделениот `TogglesAppAccess` преминуваат од глобалното `UserPolicy::create` (кое мора да остане админ-само зашто го користи и `OfficeUsers`) на истото `update` врз конкретната фирма. `CompanyCreator` — местото каде фирма навистина се создава — прима опционален актер и сам го закачува сметководителот, за да не можат двата повикувачки екрана да се разидат како што веќе еднаш се случи.

**Tech Stack:** Laravel 12, Livewire 3, Spatie Permission, PHPUnit преку `php artisan test`.

Спецификација: `docs/superpowers/specs/2026-09-23-accountant-self-service-companies-design.md`

## Global Constraints

- Сите видливи низи се на македонски. Никогаш бугарски облици.
- Тестовите се пуштаат со `php artisan test --filter=ИмеНаТест`. Целата серија еднаш пред спојување, со `php artisan test`.
- **Тест готча (носена од претходниот круг):** Livewire `->assertSee('текст')` гледа во исчистен текст и не забележува неисцртана Blade компонента ниту изгубена класа. Кога проверката зависи од присуство/отсуство на контрола (штиклирано поле, копче), провери го HTML-от изрично (`->html()` + `assertStringContainsString`/`assertMatchesRegularExpression`), не гол текст.
- **Опсег готча:** секое ново правило што допира сметка на клиент мора да остане недостижно за сметка на **друга** фирма — секој нов позитивен тест во оваа задача има спротивен пар (моја фирма → да, туѓа фирма → не).
- **Заштитена граница:** `UserPolicy::create` НЕ се менува. `OfficeUsers` (екранот „Канцеларија" — сметки на самата канцеларија) останува целосно затворен за не-админ, на секое ниво — mount, штиклирање апликација, исклучување сметка. Задача 4 постои токму за да го докаже ова со тест по секое проширување.
- Секој commit завршува со `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`.
- Гранката е `smetkovoditel-samostojno-firmi`, веќе создадена, со спецификацијата на неа.

---

## Структура на фајлови

**Се менува:**

**Се создава:**

| Фајл | Одговорност |
|---|---|
| `tests/Feature/UserPolicyTest.php` | директни тестови врз `UserPolicy::invite`/`disable`, зашто `CompanyUsers` веќе го скролира таргетот по фирма пред политиката воопшто да се повика — негативната гранка на политиката не е достижна низ таа компонента |

**Се менува:**

| Фајл | Што |
|---|---|
| `app/Services/CompanyCreator.php` | нов опционален параметар `?User $actor`; закачува сметководител-актер |
| `app/Livewire/CompanyIndex.php` | `mount()` се отвора за сметководител; `addCompany()` го предава актерот |
| `app/Livewire/FirstClient.php` | `save()` го предава актерот наместо рачно да закачува |
| `app/Policies/CompanyPolicy.php` | `create()` без ограничување; `update()` отворено за сметководител на таа фирма |
| `app/Policies/UserPolicy.php` | `invite()`/`disable()` проширени преку сметка-на-фирма, со заедничка приватна метода |
| `app/Livewire/CompanyUsers.php` | сите `Gate::authorize('create', User::class)` → `Gate::authorize('update', $this->company)` |
| `app/Livewire/Concerns/TogglesAppAccess.php` | нов апстрактен метод `authorizeAppAccessChange()` наместо тврдо вградена проверка |
| `app/Livewire/OfficeUsers.php` | имплементира `authorizeAppAccessChange()` со истата стара, админ-само проверка |
| `resources/views/livewire/company-users.blade.php` | 4× `@can('create', \App\Models\User::class)` → `@can('update', $company)` |
| `resources/views/livewire/company-index.blade.php` | таб „Канцеларија" само за админ; имињата на фирмите стануваат врски |
| `tests/Unit/Services/CompanyCreatorTest.php` | нов тест за `$actor` параметарот |
| `tests/Feature/CompanyPolicyTest.php` | заменет тестот за второ создавање; проширен тестот за `update` |
| `tests/Feature/CompanyIndexTest.php` | заменет тестот „само админ"; нови тестови за сметководител |
| `tests/Feature/Users/CompanyUsersTest.php` | заменет `test_an_accountant_cannot_open_an_account`; нови позитивни/негативни тестови |
| `tests/Feature/Users/CompanyAccountantsTest.php` | нови тестови за сметководител што доделува/симнува колега |
| `tests/Feature/UserAppAccessToggleTest.php` | нови тестови за сметководител на `CompanyUsers` (штиклирање, живи наспроти мртви квадратчиња) |
| `tests/Feature/FirstClientTest.php` | проверка дека закачувањето сепак се случува (сега преку `CompanyCreator`) |

---

## Task 1: `CompanyCreator` прима актер и сам закачува

**Files:**
- Modify: `app/Services/CompanyCreator.php`
- Modify: `tests/Unit/Services/CompanyCreatorTest.php`

**Interfaces:**
- Consumes: ништо ново
- Produces: `CompanyCreator::create(string $name, CompanyType $type, ?string $taxId = null, ?string $embg = null, ?User $actor = null): Company`. Task 3 го предава `auth()->user()` како `$actor` (двата повикувачки екрана).

Ова е единственото место каде „закачи го создавачот" смее да живее — вчера токму разликата меѓу два повикувачки екрана го создаде дефектот што спецификацијата го именува.

- [ ] **Step 1: Прочитај го постојниот тест за да не го скршиш**

Постојниот `tests/Unit/Services/CompanyCreatorTest.php` има три теста (правно лице, физичко лице, стандардни вредности), ниту еден не предава петти аргумент. Тие мора да останат зелени без измена — доказ дека новиот параметар е строго опционален.

- [ ] **Step 2: Напиши го тестот што паѓа**

Додади во `tests/Unit/Services/CompanyCreatorTest.php`:

```php
    public function test_an_accountant_actor_is_attached_as_the_companys_accountant(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('accountant');
        $accountant = \App\Models\User::factory()->create();
        $accountant->assignRole('accountant');

        $company = CompanyCreator::create('ТЕСТ ДООЕЛ', CompanyType::LEGAL, actor: $accountant);

        $this->assertTrue(
            $company->accountants->contains($accountant),
            'Без ова сметководителот веднаш ја губи фирмата што штотуку ја создал.'
        );
    }

    public function test_an_admin_actor_is_not_attached(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('admin');
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        $company = CompanyCreator::create('ТЕСТ ДООЕЛ', CompanyType::LEGAL, actor: $admin);

        $this->assertFalse(
            $company->accountants->contains($admin),
            'Админ гледа сè без ред во company_accountant — закачување би било вишок ред.'
        );
    }

    public function test_no_actor_means_no_attachment(): void
    {
        $company = CompanyCreator::create('ТЕСТ ДООЕЛ', CompanyType::LEGAL);

        $this->assertCount(0, $company->accountants);
    }
```

- [ ] **Step 3: Пушти ги за да видиш дека паѓаат**

Run: `php artisan test --filter=CompanyCreatorTest`
Expected: FAIL — `create()` не прифаќа именуван аргумент `actor`.

- [ ] **Step 4: Прошири го `CompanyCreator`**

Во `app/Services/CompanyCreator.php`, додади `use App\Models\User;` и измени го потписот и телото:

```php
    public static function create(
        string $name,
        CompanyType $type,
        ?string $taxId = null,
        ?string $embg = null,
        ?User $actor = null,
    ): Company {
        $isLegal = $type->isLegal();

        $company = Company::create([
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

        // Сметководител што создава фирма мора веднаш да ја гледа — инаку
        // исчезнува од сопствениот список штом visibleCompanies() се
        // пресмета одново. Админ никогаш не се закачува: тој гледа сè и без
        // ред во пивот-табелата (Company::accountants()).
        //
        // Овој чекор намерно живее ТУКА, не во секој повикувачки екран
        // одделно — двете места (CompanyIndex, FirstClient) веќе еднаш се
        // разидоа кога закачувањето беше рачно во секој од нив.
        if ($actor?->hasRole('accountant')) {
            $company->accountants()->attach($actor->id);
        }

        return $company;
    }
```

- [ ] **Step 5: Пушти ги сите тестови на фајлот**

Run: `php artisan test --filter=CompanyCreatorTest`
Expected: PASS (6 теста — 3 стари, 3 нови)

- [ ] **Step 6: Commit**

```bash
git add app/Services/CompanyCreator.php tests/Unit/Services/CompanyCreatorTest.php
git commit -m "feat: CompanyCreator сам го закачува сметководителот-создавач

Опционален $actor параметар, строго компатибилен со постојните
повикувања. Закачувањето живее на едно место наместо во секој
повикувачки екран — двете веќе еднаш се разидоа.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 2: `CompanyPolicy` — create без ограничување, update отворено на сопствена фирма

**Files:**
- Modify: `app/Policies/CompanyPolicy.php`
- Modify: `tests/Feature/CompanyPolicyTest.php`

**Interfaces:**
- Consumes: ништо
- Produces: `CompanyPolicy::create(User $user): bool` враќа `true` за секој сметководител, без разлика на бројот фирми. `CompanyPolicy::update(User $user, Company $company): bool` враќа `true` и за сметководител чиј `visibleCompanies()` ја содржи таа фирма.

- [ ] **Step 1: Прочитај го постојниот тест `test_the_same_accountant_may_not_create_a_second_one`**

Тој тест го тврди токму она што сега го отфрламе — мора да се ЗАМЕНИ, не остане покрај новиот. Оставен покрај новиот би паднал засекогаш.

- [ ] **Step 2: Напиши ги тестовите (замена + нови)**

Во `tests/Feature/CompanyPolicyTest.php`, замени го целиот `test_the_same_accountant_may_not_create_a_second_one` со:

```php
    public function test_an_accountant_with_existing_companies_may_still_create_another(): void
    {
        // Спротивно од порано: правото повеќе не се затвора по првото
        // создавање. Сопственикот побара сметководителот сам да ги внесува
        // сите свои клиенти, не само првиот.
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        Company::factory()->create()->accountants()->attach($accountant);
        Company::factory()->create()->accountants()->attach($accountant);

        $this->assertTrue($accountant->can('create', Company::class));
    }
```

Замени го `test_only_admin_can_update_a_company` со:

```php
    public function test_an_accountant_may_update_only_the_companies_they_work_on(): void
    {
        $mine = Company::factory()->create();
        $notMine = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($mine->id);

        $this->assertTrue($accountant->can('update', $mine));
        $this->assertFalse($accountant->can('update', $notMine));
    }

    public function test_an_admin_and_the_assigned_accountant_can_update_a_company_a_client_cannot(): void
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

- [ ] **Step 3: Пушти ги за да видиш дека паѓаат**

Run: `php artisan test --filter=CompanyPolicyTest`
Expected: FAIL на `test_an_accountant_with_existing_companies_may_still_create_another` и на `test_an_accountant_may_update_only_the_companies_they_work_on`.

- [ ] **Step 4: Измени го `CompanyPolicy`**

Во `app/Policies/CompanyPolicy.php` замени го целиот `create()` и `update()`:

```php
    /**
     * Админ секогаш. Сметководител — секогаш, без ограничување: сам ги
     * внесува сите свои клиенти, не само првиот. Порано ова важеше само
     * додека немаше ниту една фирма (излезот за App\Livewire\FirstClient);
     * тоа ограничување е тргнато со изречна одлука на сопственикот — види
     * docs/superpowers/specs/2026-09-23-accountant-self-service-companies-design.md.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('accountant');
    }

    /**
     * Вратата за целата поставка на фирмата: профил (CompanyProfile),
     * модули (CompanyModules), и корисници (CompanyUsers, преку ова исто
     * правило — не UserPolicy::create, кое мора да остане админ-само зашто
     * го користи и OfficeUsers за сметки на канцеларијата).
     *
     * Админ секогаш. Сметководител — само за фирма на која работи
     * (visibleCompanies() ја содржи).
     */
    public function update(User $user, Company $company): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->hasRole('accountant')
            && $user->visibleCompanies()->whereKey($company->id)->exists();
    }
```

- [ ] **Step 5: Пушти ги сите тестови на фајлот**

Run: `php artisan test --filter=CompanyPolicyTest`
Expected: PASS

- [ ] **Step 6: Провери дека ништо друго не паднало (CompanyProfile/Modules веќе го користат `update`)**

Run: `php artisan test --filter="CompanyProfile|CompanyModules"`
Expected: PASS — овие екрани веќе повикуваат `Gate::authorize('update', $company)`, па автоматски се отвораат за сметководител на таа фирма, без ниту една измена во нив.

- [ ] **Step 7: Commit**

```bash
git add app/Policies/CompanyPolicy.php tests/Feature/CompanyPolicyTest.php
git commit -m "feat: create без ограничување, update отворено за сопствена фирма

create() веќе не се затвора по првата фирма. update() — правилото
кое веќе го носат Профил и Модули — се отвора за сметководител на
таа конкретна фирма; двата екрана го добиваат ова бесплатно, без
измена во нив.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 3: Екранот „Фирми" се отвора за сметководител, актерот се предава на `CompanyCreator`

**Files:**
- Modify: `app/Livewire/CompanyIndex.php`
- Modify: `app/Livewire/FirstClient.php`
- Modify: `resources/views/livewire/company-index.blade.php`
- Modify: `tests/Feature/CompanyIndexTest.php`
- Modify: `tests/Feature/FirstClientTest.php`

**Interfaces:**
- Consumes: `CompanyCreator::create(..., actor: $user)` од Task 1; `CompanyPolicy::create`/`update` од Task 2
- Produces: ништо ново за понатаму

- [ ] **Step 1: Прочитај го постојниот тест `test_only_an_admin_may_open_the_companies_screen`**

Тој тврди дека сметководител добива `assertForbidden()` — мора да се замени, не остане покрај новиот.

- [ ] **Step 2: Напиши ги тестовите (замена + нови) во `CompanyIndexTest`**

Замени го целиот `test_only_an_admin_may_open_the_companies_screen` со:

```php
    public function test_a_client_may_not_open_the_companies_screen_but_an_accountant_may(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $this->actingAs($client)->get(route('companies.index'))->assertForbidden();
        $this->actingAs($accountant)->get(route('companies.index'))->assertOk();
    }
```

Додади ги (веднаш по `test_admin_sees_all_companies`):

```php
    public function test_an_accountant_sees_only_their_own_companies(): void
    {
        $mine = Company::factory()->create(['name' => 'Мојата ДООЕЛ']);
        $notMine = Company::factory()->create(['name' => 'Туѓата ДООЕЛ']);
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $mine->accountants()->attach($accountant);

        $this->actingAs($accountant);

        Livewire::test(CompanyIndex::class)
            ->assertSee('Мојата ДООЕЛ')
            ->assertDontSee('Туѓата ДООЕЛ');
    }

    public function test_an_accountant_can_add_a_company_and_it_stays_visible_to_them(): void
    {
        // Регресија за замката именувана во спецификацијата: без закачување
        // на создавачот, фирмата веднаш би исчезнала од visibleCompanies().
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');

        Livewire::actingAs($accountant)
            ->test(CompanyIndex::class)
            ->set('newName', 'Нов Клиент ДООЕЛ')
            ->set('newType', 'legal')
            ->call('addCompany')
            ->assertHasNoErrors();

        $company = Company::where('name', 'Нов Клиент ДООЕЛ')->firstOrFail();

        $this->assertTrue($accountant->fresh()->visibleCompanies()->whereKey($company->id)->exists());
    }

    public function test_an_accountant_with_several_companies_can_still_add_another(): void
    {
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        Company::factory()->create()->accountants()->attach($accountant);

        Livewire::actingAs($accountant)
            ->test(CompanyIndex::class)
            ->set('newName', 'Втора Фирма ДООЕЛ')
            ->set('newType', 'legal')
            ->call('addCompany')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('companies', ['name' => 'Втора Фирма ДООЕЛ']);
    }

    public function test_the_office_tab_is_hidden_from_an_accountant(): void
    {
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');

        $this->actingAs($accountant)
            ->get(route('companies.index'))
            ->assertOk()
            ->assertDontSee(route('companies.office'), false);
    }

    public function test_the_office_tab_is_shown_to_an_admin(): void
    {
        $this->actingAs($this->admin())
            ->get(route('companies.index'))
            ->assertOk()
            ->assertSee(route('companies.office'), false);
    }

    public function test_a_company_name_links_to_its_dashboard(): void
    {
        $company = Company::factory()->create(['name' => 'Линкувана ДООЕЛ']);

        $this->actingAs($this->admin())
            ->get(route('companies.index'))
            ->assertOk()
            ->assertSee(route('companies.dashboard', $company), false);
    }
```

- [ ] **Step 3: Пушти ги за да видиш дека паѓаат**

Run: `php artisan test --filter=CompanyIndexTest`
Expected: FAIL на новите/заменетиот тест — екранот сè уште е `abort_unless(hasRole('admin'))`, тестот за линк уште не поминува, табот за Канцеларија сè уште е гол линк без заштита.

- [ ] **Step 4: Отвори го екранот и предај го актерот**

Во `app/Livewire/CompanyIndex.php` замени го `mount()`:

```php
    public function mount(): void
    {
        // Фирми — сега достапен и за сметководител, скроен на неговите
        // фирми преку visibleCompanies() во render(). Само клиент останува
        // надвор.
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
    }
```

И во `addCompany()`, додади `auth()->user()` како петти аргумент:

```php
        $company = CompanyCreator::create(
            $validated['newName'],
            CompanyType::from($validated['newType']),
            $validated['newTaxId'],
            $validated['newEmbg'],
            auth()->user(),
        );
```

- [ ] **Step 5: Скриј го табот „Канцеларија" за не-админ**

Во `resources/views/livewire/company-index.blade.php`, замени го блокот `<x-tab-strip>`. Провери со `hasRole()` директно, не `@role`/`@endrole` — овој проект никаде не ја користи таа Blade директива, секаде оди со `auth()->user()->hasRole(...)` во `@if` (истиот образец како во `sidebar.blade.php`):

```blade
    @if (auth()->user()->hasRole('admin'))
        <x-tab-strip :tabs="[
            ['label' => 'Клиенти', 'url' => route('companies.index'), 'active' => true],
            ['label' => 'Канцеларија', 'url' => route('companies.office'), 'active' => false],
        ]" />
    @else
        <x-tab-strip :tabs="[
            ['label' => 'Клиенти', 'url' => route('companies.index'), 'active' => true],
        ]" />
    @endif
```

- [ ] **Step 6: Направи ги имињата на фирмите врски**

Во истиот фајл, замени го блокот со списокот:

```blade
    @if ($companies->isEmpty())
        <p class="text-gray-500">Нема додадено фирми.</p>
    @else
        <ul class="divide-y divide-gray-200">
            @foreach ($companies as $company)
                <li class="py-3">
                    <a href="{{ route('companies.dashboard', $company) }}" wire:navigate class="font-medium text-brand hover:underline">
                        {{ $company->name }}
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
```

- [ ] **Step 7: Преселка на `FirstClient` — CompanyCreator сега сам закачува**

Во `app/Livewire/FirstClient.php`, замени го блокот во `save()`:

```php
        $company = CompanyCreator::create(
            $validated['name'],
            CompanyType::from($validated['type']),
            $validated['taxId'],
            $validated['embg'],
            auth()->user(),
        );

        return $this->redirect(route('companies.profile', $company), navigate: true);
```

(отстрани го редот `$company->accountants()->attach(auth()->id());` и коментарот над него — `CompanyCreator` сега го прави тоа).

- [ ] **Step 8: Пушти ги сите засегнати тестови**

Run: `php artisan test --filter="CompanyIndexTest|FirstClientTest"`
Expected: PASS. Постојниот `FirstClientTest::test_saving_creates_the_company_and_attaches_the_accountant` мора да остане зелен без измена — тоа е доказ дека преселбата на закачувањето во `CompanyCreator` не го смени однесувањето на тој екран.

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/CompanyIndex.php app/Livewire/FirstClient.php resources/views/livewire/company-index.blade.php tests/Feature/CompanyIndexTest.php tests/Feature/FirstClientTest.php
git commit -m "feat: Фирми се отвора за сметководител, скроен на неговиот список

Табот Канцеларија останува само за админ. Имињата на фирмите стануваат
врски — без нив сметководителот немаше начин да се врати на веќе
создадена фирма од овој екран.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 4: `UserPolicy` — покана и исклучување преку сопствена фирма, `OfficeUsers` останува затворен

**Files:**
- Modify: `app/Policies/UserPolicy.php`
- Create: `tests/Feature/UserPolicyTest.php`
- Modify: `tests/Feature/Users/CompanyUsersTest.php` (нови случаи; постојните остануваат)

**Interfaces:**
- Consumes: ништо
- Produces: `UserPolicy::invite(User $user, User $target): bool` и `UserPolicy::disable(User $user, User $target): bool` враќаат `true` и за сметководител чија `visibleCompanies()` ја содржи `$target->company_id`.

Ова е задачата со најмала маргина за грешка: сметка на канцеларија (`company_id === null`) мора да остане недостижна за секој не-админ. Секој нов тест има спротивен пар.

**Важна ситница за начинот на тестирање:** `CompanyUsers` веќе го скролира таргетот по фирма ПРЕД да стигне до `UserPolicy` (`companyUser()` бара `User::where('company_id', $this->company->id)->findOrFail($userId)`), а `mount()` веќе го брани целиот екран со `Gate::authorize('view', $company)`. Значи сценариото „сметководител на фирма А ја повикува `disable()` врз клиент на фирма Б" НИКОГАШ не стигнува до `UserPolicy::disable` низ тој екран — блокирано е порано, на друго место, со друг облик на одбивање. За да се докаже дека самото правило во `UserPolicy` навистина го прави тоа разграничување (не случајно преку туѓ слој), негативниот случај се тестира **директно врз политиката**, исто како `CompanyPolicyTest` тестира `CompanyPolicy`.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Создај `tests/Feature/UserPolicyTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_an_accountant_may_invite_and_disable_a_client_of_a_company_they_work_on(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($company->id);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->assertTrue($accountant->can('invite', $client));
        $this->assertTrue($accountant->can('disable', $client));
    }

    public function test_an_accountant_may_not_touch_a_client_of_a_company_they_dont_work_on(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        // Намерно НЕ закачен на $company.
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->assertFalse($accountant->can('invite', $client));
        $this->assertFalse($accountant->can('disable', $client));
    }

    public function test_an_accountant_may_never_touch_an_office_account_even_if_it_has_no_company(): void
    {
        // Сметка на канцеларија (друг сметководител/админ) секогаш има
        // company_id === null. Тоа мора да остане надвор од дофат на
        // сметководителскиот услов без разлика на кои фирми работи —
        // единствениот услов проверен тука е дека company_id е null.
        $officeAccountant = User::factory()->create(['company_id' => null]);
        $officeAccountant->assignRole('accountant');

        $actor = User::factory()->create();
        $actor->assignRole('accountant');

        $this->assertFalse($actor->can('invite', $officeAccountant));
        $this->assertFalse($actor->can('disable', $officeAccountant));
    }

    public function test_an_admin_may_touch_any_client_and_any_office_account(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $officeAccountant = User::factory()->create(['company_id' => null]);
        $officeAccountant->assignRole('accountant');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertTrue($admin->can('invite', $client));
        $this->assertTrue($admin->can('disable', $client));
        $this->assertTrue($admin->can('disable', $officeAccountant));
    }

    public function test_invite_still_refuses_a_disabled_account_even_for_the_assigned_accountant(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($company->id);
        $client = User::factory()->create(['company_id' => $company->id, 'disabled_at' => now()]);
        $client->assignRole('client');

        $this->assertFalse($accountant->can('invite', $client));
    }
}
```

Додади во `tests/Feature/Users/CompanyUsersTest.php` (овие се интеграциски — докажуваат дека компонентата навистина го користи проширеното правило, не само политиката сама):

```php
    public function test_an_accountant_can_reinvite_a_client_of_their_own_company(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $accountant = $this->userWithRole('accountant');
        $company->accountants()->attach($accountant);
        $client = $this->userWithRole('client', $company);

        Livewire::actingAs($accountant)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('reinvite', $client->id)
            ->assertHasNoErrors();

        Notification::assertSentTo($client, UserInvitationNotification::class);
    }

    public function test_an_accountant_disables_and_restores_a_client_of_their_own_company(): void
    {
        $company = Company::factory()->create();
        $accountant = $this->userWithRole('accountant');
        $company->accountants()->attach($accountant);
        $client = $this->userWithRole('client', $company);

        Livewire::actingAs($accountant)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('disable', $client->id)
            ->assertHasNoErrors();

        $this->assertNotNull($client->fresh()->disabled_at);
    }
```

**Не го допирај** `test_an_accountant_cannot_open_an_account` во оваа задача. Тој тест го проверува `addUser()`, кој сè уште чита `UserPolicy::create` (админ-само) — тоа се менува дури во Task 5. Останува зелен непроменет низ оваа задача; неговата замена доаѓа во Task 5, каде навистина припаѓа.

**Нема нов тест за `OfficeUsersTest` во оваа задача.** `OfficeUsers::mount()` веќе фрла `403` за секој не-админ ПРЕД компонентата воопшто да се монтира. Пробав емпириски: верижење `->test(OfficeUsers::class)->call('disable', ...)` по неуспешен `mount()` не враќа чист `403`/`AuthorizationException` за да се тврди — фрла `InvalidArgumentException: Invalid Livewire snapshot structure`, зашто компонентата никогаш не завршила монтирање. Значи `disable()`/`invite()` во `OfficeUsers` контекст НИКОГАШ не се достижни за не-админ преку `Livewire::test()` — единствената смислена брана е `mount()`, веќе докажана со постојниот `test_an_accountant_cannot_reach_the_office_screen` (веб-барање, не Livewire-компонента), која останува непроменета низ оваа задача.

- [ ] **Step 2: Пушти ги за да видиш дека паѓаат**

Run: `php artisan test --filter="UserPolicyTest|CompanyUsersTest|OfficeUsersTest"`
Expected: FAIL на новите позитивни случаи (сметководител сепак добива `false`/403 бидејќи `UserPolicy::invite`/`disable` сè уште се админ-само).

- [ ] **Step 3: Прошири го `UserPolicy`**

Во `app/Policies/UserPolicy.php` замени го целиот фајл:

```php
<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Секој ја гледа листата на својата фирма — самиот екран потоа е ограничен
     * со CompanyPolicy::view врз фирмата.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Само за сметки на КАНЦЕЛАРИЈАТА (админи/сметководители) —
     * App\Livewire\OfficeUsers. Намерно останува админ-само и не се менува
     * со проширувањето подолу: сметка на канцеларија никогаш нема company_id,
     * па manages() below секогаш ѝ одбива на не-админ актер и без оваа
     * граница — но create() е засебна одлука (отворање НОВА сметка), не
     * управување со постојна, па останува чисто admin-само со свое, кратко
     * правило.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function invite(User $user, User $target): bool
    {
        // Покана за исклучена сметка не може да се прифати (UserInvitations::accept),
        // па не смее ни да се издаде — инаку екранот ветува линк што не работи.
        return $this->manages($user, $target) && $target->disabled_at === null;
    }

    /**
     * Админ не може да си го одземе сопствениот пристап — тоа е единствениот
     * начин да се остане без ниту една сметка што може да отвора сметки.
     */
    public function disable(User $user, User $target): bool
    {
        return $this->manages($user, $target) && ! $user->is($target);
    }

    /**
     * Админ секогаш. Сметководител — само врз клиентска сметка (company_id
     * не е null) на фирма на која тој работи. Сметка на канцеларија
     * (company_id === null) никогаш не поминува преку овој услов — останува
     * достижна само за админ, автоматски, без посебна проверка: токму затоа
     * App\Livewire\OfficeUsers останува безбеден и понатаму да ги користи
     * истите 'invite'/'disable' правила.
     */
    private function manages(User $user, User $target): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->hasRole('accountant')
            && $target->company_id !== null
            && $user->visibleCompanies()->whereKey($target->company_id)->exists();
    }
}
```

- [ ] **Step 4: Пушти ги сите засегнати тестови**

Run: `php artisan test --filter="UserPolicyTest|CompanyUsersTest|OfficeUsersTest|UserAppAccessToggleTest"`
Expected: PASS

- [ ] **Step 5: Пушти го и постојниот регресиски тест за cross-tenant enumeration**

Run: `php artisan test --filter=CompanyUsersTest`
Expected: PASS, вклучувајќи `test_a_client_disabling_a_stranger_from_another_company_looks_like_disabling_a_nonexistent_id` и `test_disable_enable_and_reinvite_agree_on_the_shape_of_an_out_of_company_id` — овие докажуваат дека опсегот по фирма (`companyUser()`) сè уште е првата брана, пред UserPolicy воопшто да се повика.

- [ ] **Step 6: Commit**

```bash
git add app/Policies/UserPolicy.php tests/Feature/UserPolicyTest.php tests/Feature/Users/CompanyUsersTest.php
git commit -m "feat: сметководител покажува/исклучува клиенти на своја фирма

UserPolicy::create останува чисто админ-само (сметки на КАНЦЕЛАРИЈАТА).
invite/disable минуваат низ заедничка manages(): сметка на канцеларија
(company_id null) никогаш не поминува преку сметководителскиот услов,
па OfficeUsers останува безбеден без измена во него.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 5: `CompanyUsers` — отворање сметка и доделување колега преку `update`

**Files:**
- Modify: `app/Livewire/CompanyUsers.php`
- Modify: `resources/views/livewire/company-users.blade.php`
- Modify: `tests/Feature/Users/CompanyUsersTest.php`
- Modify: `tests/Feature/Users/CompanyAccountantsTest.php`

**Interfaces:**
- Consumes: `CompanyPolicy::update` од Task 2
- Produces: ништо ново

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Во `tests/Feature/Users/CompanyUsersTest.php`, замени го целиот `test_an_accountant_cannot_open_an_account` со:

```php
    public function test_an_accountant_of_that_company_can_open_an_account(): void
    {
        // Спротивно од порано: сметководителот сега целосно ја поставува
        // фирмата на која работи, без одобрување.
        Notification::fake();

        $company = Company::factory()->create();
        $accountant = $this->userWithRole('accountant');
        $company->accountants()->attach($accountant);

        Livewire::actingAs($accountant)
            ->test(CompanyUsers::class, ['company' => $company])
            ->set('newName', 'Нов Некој')
            ->set('newEmail', 'nov@primer.mk')
            ->call('addUser')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'nov@primer.mk']);
    }
```

Додади во `tests/Feature/Users/CompanyAccountantsTest.php`:

```php
    public function test_an_accountant_of_the_company_assigns_and_removes_a_colleague(): void
    {
        $company = Company::factory()->create();
        $me = $this->userWithRole('accountant');
        $company->accountants()->attach($me);
        $colleague = $this->userWithRole('accountant');

        Livewire::actingAs($me)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('assignAccountant', $colleague->id)
            ->assertHasNoErrors();

        $this->assertTrue($company->fresh()->accountants->contains($colleague));

        Livewire::actingAs($me)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('removeAccountant', $colleague->id)
            ->assertHasNoErrors();

        $this->assertFalse($company->fresh()->accountants->contains($colleague));
    }

    public function test_an_accountant_not_on_the_company_cannot_even_reach_the_screen(): void
    {
        // Истата причина како во CompanyUsersTest: mount() (view()) веќе
        // одбива пред assignAccountant() да се повика — нема верижење по
        // неуспешен mount().
        $company = Company::factory()->create();
        $stranger = $this->userWithRole('accountant');

        Livewire::actingAs($stranger)
            ->test(CompanyUsers::class, ['company' => $company])
            ->assertForbidden();

        $this->assertSame(0, $company->fresh()->accountants->count());
    }
```

- [ ] **Step 2: Пушти ги за да видиш дека паѓаат**

Run: `php artisan test --filter="CompanyAccountantsTest|CompanyUsersTest"`
Expected: FAIL — `addUser`/`assignAccountant`/`removeAccountant` сè уште бараат `create` (админ-само).

- [ ] **Step 3: Измени ги четирите места во `CompanyUsers`**

Во `app/Livewire/CompanyUsers.php`, замени го секое `Gate::authorize('create', User::class);` со `Gate::authorize('update', $this->company);` — во `addUser()`, `assignAccountant()`, `removeAccountant()`. Провери со пребарување дека нема заборавено четврто место (провери и дека `use App\Models\User;` останува ако сè уште е потребен за друг тип во истиот фајл — `assignAccountant` користи `User::role('accountant')->findOrFail($userId)`, па изјавата останува).

- [ ] **Step 4: Замени ги проверките во Blade**

Во `resources/views/livewire/company-users.blade.php`, замени ги сите четири `@can('create', \App\Models\User::class)` со `@can('update', $company)` (линии околу 8, 64, 110, 120 во постојниот фајл — провери ги точните места пред измена, distancing може да се поместил).

- [ ] **Step 5: Постави ја `authorizeAppAccessChange()` куката (подготовка за Task 6)**

Оваа задача НЕ ја менува `TogglesAppAccess` — тоа е Task 6. За сега `toggleApp` сè уште користи `Gate::authorize('create', User::class)` внатре во трејтот, па штиклирањето сепак останува админ-само до крајот на оваа задача. Тестовите за штиклирање доаѓаат во Task 6, не тука.

- [ ] **Step 6: Пушти ги засегнатите тестови**

Run: `php artisan test --filter="CompanyAccountantsTest|CompanyUsersTest"`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/CompanyUsers.php resources/views/livewire/company-users.blade.php tests/Feature/Users/CompanyUsersTest.php tests/Feature/Users/CompanyAccountantsTest.php
git commit -m "feat: отворање сметка и доделување колега преку update на фирмата

Четирите места во CompanyUsers што порано бараа глобалното
create-на-User (админ-само) сега бараат update врз конкретната
фирма — истото правило како профилот и модулите.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 6: Штиклирање апликација — `TogglesAppAccess` со сопствена проверка по екран

**Files:**
- Modify: `app/Livewire/Concerns/TogglesAppAccess.php`
- Modify: `app/Livewire/CompanyUsers.php`
- Modify: `app/Livewire/OfficeUsers.php`
- Modify: `tests/Feature/UserAppAccessToggleTest.php`

**Interfaces:**
- Consumes: `CompanyPolicy::update` од Task 2
- Produces: `TogglesAppAccess` бара имплементација на `authorizeAppAccessChange(): void` од секој користник

Ова е местото каде една невнимателна измена би отворила штиклирање апликации за сметки на канцеларијата од сметководител — трејтот е споделен со `OfficeUsers`. Затоа не се проширува заедничката проверка; секој екран добива своја.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Додади во `tests/Feature/UserAppAccessToggleTest.php`:

```php
    public function test_an_accountant_of_the_company_toggles_an_app_for_a_client(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        Livewire::actingAs($accountant)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('toggleApp', $client->id, 'plata');

        $this->assertFalse($client->fresh()->app_plata);
    }

    public function test_an_accountant_not_on_the_company_cannot_even_reach_the_screen_to_toggle(): void
    {
        // Истата причина: mount() (view()) веќе одбива пред toggleApp() да
        // се повика — нема сценарио каде овој метод воопшто се стигнува за
        // фирма на која актерот не е доделен.
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        Livewire::actingAs($accountant)
            ->test(CompanyUsers::class, ['company' => $company])
            ->assertForbidden();

        $this->assertTrue($client->fresh()->app_plata);
    }

```

**Нема нов тест за „сметководител не смее да штиклира на Канцеларија" во оваа задача.** `OfficeUsers::mount()` веќе фрла `403` за секој не-админ ПРЕД компонентата воопшто да се монтира — `toggleApp()` (а со него и `authorizeAppAccessChange()`) никогаш не се достигнува за таков актер преку `Livewire::test()`. Постојниот `test_an_accountant_cannot_reach_the_office_screen` во `OfficeUsersTest.php` (веб-барање, не Livewire-компонента) веќе го докажува ова целосно и останува непроменет низ оваа задача — тој е чекот за регресија, не нов тест.

- [ ] **Step 2: Пушти ги за да видиш дека паѓаат**

Run: `php artisan test --filter=UserAppAccessToggleTest`
Expected: FAIL на `test_an_accountant_of_the_company_toggles_an_app_for_a_client` (сè уште 403 — трејтот бара `create`).

- [ ] **Step 3: Измени го трејтот**

Во `app/Livewire/Concerns/TogglesAppAccess.php`, замени го целиот фајл:

```php
<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use App\Support\PortalApp;

/**
 * Штиклирањето „во која апликација влегува овој човек". Механизмот е ист на
 * двата екрана (CompanyUsers, OfficeUsers); опсегот (кој корисник смее да се
 * допре) го дава appAccessTarget(), а ПРАВОТО (смее ли воопшто актерот) го
 * дава authorizeAppAccessChange() — намерно ОДДЕЛНО за секој екран.
 *
 * Не смее да има заедничка проверка тука: CompanyUsers сега е отворен за
 * сметководител на таа фирма (CompanyPolicy::update), а OfficeUsers мора да
 * остане строго админ-само — заедничка проверка би значела дека проширување
 * на едната автоматски протекува во другата.
 */
trait TogglesAppAccess
{
    public function toggleApp(int $userId, string $app): void
    {
        $this->authorizeAppAccessChange();

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

    /**
     * Смее ли актерот воопшто да го допре квадратчето на овој екран. Секој
     * екран го дава своето — ова НЕ смее да биде заедничка, тврдо вградена
     * проверка во трејтот.
     */
    abstract protected function authorizeAppAccessChange(): void;
}
```

- [ ] **Step 4: Имплементирај во `CompanyUsers`**

Во `app/Livewire/CompanyUsers.php`, додади метода веднаш по `appAccessTarget()` (или каде е дефиниран `companyUser()`/сличен приватен помошник):

```php
    protected function authorizeAppAccessChange(): void
    {
        Gate::authorize('update', $this->company);
    }
```

- [ ] **Step 5: Имплементирај во `OfficeUsers`**

Во `app/Livewire/OfficeUsers.php`, додади метода веднаш по постојниот `appAccessTarget()`:

```php
    protected function authorizeAppAccessChange(): void
    {
        // Намерно НЕ CompanyPolicy::update — оваа проверка мора да остане
        // строго админ-само, без разлика како се менуваат правилата за
        // фирмите. Ист образец како mount() погоре.
        abort_unless(auth()->user()->hasRole('admin'), 403);
    }
```

- [ ] **Step 6: Пушти ги сите засегнати тестови**

Run: `php artisan test --filter=UserAppAccessToggleTest`
Expected: PASS (сите постојни + двата нови)

- [ ] **Step 7: Пушти ги и `CompanyUsersTest`, `OfficeUsersTest` — вториот е чистата регресиска проверка дека Канцеларија останала недопрена**

Run: `php artisan test --filter="CompanyUsersTest|OfficeUsersTest"`
Expected: PASS, вклучувајќи `test_an_accountant_cannot_reach_the_office_screen` — недопрен, а сепак зелен по измената на трејтот.

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Concerns/TogglesAppAccess.php app/Livewire/CompanyUsers.php app/Livewire/OfficeUsers.php tests/Feature/UserAppAccessToggleTest.php
git commit -m "feat: штиклирање апликација — сопствена проверка по екран

TogglesAppAccess веќе не носи тврдо вградена, заедничка проверка.
CompanyUsers бара update на фирмата; OfficeUsers останува строго
админ-само со сопствена, непроменета проверка — двата екрана веќе не
можат да протечат еден во друг.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 7: Читливост на HTML — живи наспроти само-за-читање квадратчиња за сметководител

**Files:**
- Modify: `tests/Feature/UserAppAccessToggleTest.php`

**Interfaces:**
- Consumes: ништо ново (докажува веќе изградено однесување од Task 6)
- Produces: ништо

Задачите 5 и 6 веќе го градат саканото однесување. Оваа задача само го докажува преку HTML-от — истата мерка со која постојниот тест докажува дека клиент гледа „мртви" квадратчиња, а админ „живи".

- [ ] **Step 1: Напиши го тестот**

Додади во `tests/Feature/UserAppAccessToggleTest.php`:

```php
    public function test_an_accountant_of_the_company_sees_live_checkboxes_a_stranger_accountant_sees_forbidden(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $mine = User::factory()->create();
        $mine->assignRole('accountant');
        $company->accountants()->attach($mine);

        $html = Livewire::actingAs($mine)
            ->test(CompanyUsers::class, ['company' => $company])
            ->assertOk()
            ->html();

        $this->assertStringContainsString(
            "wire:click=\"toggleApp({$client->id}, 'plata')\"",
            $html,
            'Сметководител на таа фирма треба да гледа живи квадратчиња, како админ.'
        );
    }
```

- [ ] **Step 2: Пушти го — веќе треба да минува**

Run: `php artisan test --filter=UserAppAccessToggleTest`
Expected: PASS без ниту една измена во `app/`. Ако падне, задача 5 или 6 не се точно завршени — врати се таму, не крпи го тука.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/UserAppAccessToggleTest.php
git commit -m "test: сметководител на фирмата гледа живи квадратчиња

Докажува однесување веќе изградено во претходните две задачи, преку
истата мерка со која постојниот тест го докажува за клиент/админ.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Пред спојување

- [ ] **Целата серија**

```bash
php artisan test
```

Expected: PASS. Овој круг допира права на автентикација и достапност на клиентски податоци (не XML кон УЈП, ниту печатена фактура), но е точно областа каде претходниот круг веќе еднаш најде и поправи регресија (cross-tenant enumeration во `CompanyUsersTest`) — пушти ја целата серија, не само засегнатите фајлови.

- [ ] **Стилот на кодот**

```bash
vendor/bin/pint --test
```

Ако пријави фајлови надвор од оние што ги допревме во оваа задача (задачите 1–7 погоре), тоа е затекната состојба од `main` — не ги допирај.

- [ ] **`/code-review` пред спојување**

Овој круг директно го менува тоа кој гледа туѓи финансиски податоци (кросфирмен пристап). Задолжително `/code-review` пред спојување, со посебен фокус на:
- дали `OfficeUsers`/`UserPolicy::create` останале навистина недопрени;
- дали секој нов позитивен тест во задачите 3–7 има спротивен, негативен пар за туѓа фирма;
- дали `manages()` во `UserPolicy` навистина никогаш не поминува за `target->company_id === null` освен преку `hasRole('admin')`.

- [ ] **Со очи, во прелистувач**

1. Нов сметководител со веќе една фирма → екранот „Фирми" → „Додади фирма" → втора фирма се создава и веднаш се гледа во списокот.
2. Кликни на име на фирма во списокот → отвора табла на таа фирма.
3. Табот „Канцеларија" го нема за сметководител, го има за админ.
4. На „Корисници" кај своја фирма: сметководител отвора клиентска сметка, ги штиклира апликациите, доделува колега сметководител.
5. Обиди се (со рачно менета адреса) сметководител да отвори „Корисници" на фирма што не ја работи → 403.
6. Екранот „Канцеларија" останува целосно затворен за сметководител.
