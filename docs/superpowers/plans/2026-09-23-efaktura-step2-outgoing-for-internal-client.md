# е-Фактура чекор 2 — излезни фактури за internal_client со свој токен

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `internal_client` на фирма во режим „свој токен" (`efaktura_credential_mode === 'own'`) со запишан токен да може да потпише и прати излезна е-Фактура до УЈП, да ја освежи состојбата и да го симне ПДФ-от; и сам да го запише својот токен. Спецификација: `docs/superpowers/specs/2026-09-23-efaktura-internal-client-design.md` (чекор 2).

**Architecture:** Едно ново право на едно место — `CompanyPolicy::signEfaktura(User, Company)` (потпишување/праќање) и `CompanyPolicy::manageEfakturaDevice(User, Company)` (запишување токен). Трите контролери (`EfakturaSendController`, `EfakturaStatusController`, `EfakturaPdfController`) го заменуваат `abort_unless(auth()->user()->hasAnyRole(['admin','accountant']), 403)` со `Gate::authorize('signEfaktura', $company)`. Blade-екраните и Livewire-дејството `CompanyProfile::registerSigningDevice` ја користат истата политика.

**Tech Stack:** Laravel 13, Livewire 3, Spatie/laravel-permission, PHPUnit (SQLite).

## Global Constraints

- Тестови: `php -d memory_limit=1G vendor/bin/phpunit <фајл>` само врз фајловите што се допираат. **Никогаш целата серија** (агент е убиен по 600s тишина; контролорот ја пушта) и никогаш `composer test`.
- Коментари на македонски, идентификатори на англиски. UTF-8 без BOM. Секој комит завршува со `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`.
- Ова праќа до УЈП во име на клиент: секое ново право мора да важи и на серверот (не само со криење копче), и секое Livewire-дејство што го отвораме за клиент мора да си носи сопствен `Gate::authorize` (Livewire не го повторува `mount()`).
- Режимот `firm` останува НЕподдржан за праќање за СИТЕ (постојните 422-пораки во контролерите не се менуваат). `freelancer_client` (физичко лице) нема е-Фактура. Клиентот НЕ го менува режимот `efaktura_credential_mode` ниту `efaktura_eujp_id` (тоа остава за админ/сметководител преку постојната `update` порта).
- Постојните тестови што тврдат „клиентот е забранет" за фирма во режим `own` со запишан токен го кодираат СТАРОТО правило — сменете ги на новото правило (клиент на своја `own`+токен фирма СМЕЕ; клиент на `firm` фирма, на туѓа фирма, `freelancer_client` — НЕ смеат), не ги бришете молчешкум и не ја губете заштитата.

---

## Task 1: Правата во CompanyPolicy

**Files:**
- Modify: `app/Policies/CompanyPolicy.php`
- Test: `tests/Feature/CompanyPolicyTest.php`

**Interfaces:**
- Produces: `CompanyPolicy::signEfaktura(User $user, Company $company): bool`, `CompanyPolicy::manageEfakturaDevice(User $user, Company $company): bool` (достапни како `$user->can('signEfaktura', $company)` / `Gate::authorize(...)`).

- [ ] **Step 1: Failing tests** — додади во `tests/Feature/CompanyPolicyTest.php` (setUp мора да ги создава улогите `admin`, `accountant`, `internal_client`, `freelancer_client`; додади ги ако недостасуваат; `use App\Support\CompanyType;` ако недостасува):

```php
    private function ownModeCompany(array $overrides = []): Company
    {
        return Company::factory()->create($overrides + [
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
    }

    private function internalClientOf(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('internal_client');

        return $user;
    }

    public function test_admin_and_the_assigned_accountant_can_sign_efaktura_an_unassigned_accountant_cannot(): void
    {
        $company = $this->ownModeCompany();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $mine = User::factory()->create();
        $mine->assignRole('accountant');
        $company->accountants()->attach($mine);
        $other = User::factory()->create();
        $other->assignRole('accountant');

        $this->assertTrue($admin->can('signEfaktura', $company));
        $this->assertTrue($mine->can('signEfaktura', $company));
        $this->assertFalse($other->can('signEfaktura', $company));
        $this->assertTrue($admin->can('manageEfakturaDevice', $company));
        $this->assertTrue($mine->can('manageEfakturaDevice', $company));
        $this->assertFalse($other->can('manageEfakturaDevice', $company));
    }

    public function test_an_internal_client_with_an_own_token_can_sign_and_manage_the_device(): void
    {
        $company = $this->ownModeCompany();
        $client = $this->internalClientOf($company);

        $this->assertTrue($client->can('signEfaktura', $company));
        $this->assertTrue($client->can('manageEfakturaDevice', $company));
    }

    public function test_an_internal_client_without_a_registered_token_can_register_but_not_sign(): void
    {
        $company = $this->ownModeCompany(['efaktura_token_serial_number' => null]);
        $client = $this->internalClientOf($company);

        $this->assertTrue($client->can('manageEfakturaDevice', $company));
        $this->assertFalse($client->can('signEfaktura', $company));
    }

    public function test_an_internal_client_of_a_firm_mode_company_can_do_neither(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM,
            'efaktura_firm_access_status' => Company::EFAKTURA_STATUS_APPROVED,
        ]);
        $client = $this->internalClientOf($company);

        $this->assertFalse($client->can('signEfaktura', $company));
        $this->assertFalse($client->can('manageEfakturaDevice', $company));
    }

    public function test_an_internal_client_cannot_touch_another_companys_efaktura(): void
    {
        $mine = $this->ownModeCompany();
        $theirs = $this->ownModeCompany();
        $client = $this->internalClientOf($mine);

        $this->assertFalse($client->can('signEfaktura', $theirs));
        $this->assertFalse($client->can('manageEfakturaDevice', $theirs));
    }

    public function test_a_freelancer_client_has_no_efaktura(): void
    {
        $company = $this->ownModeCompany(['type' => CompanyType::INDIVIDUAL]);
        $freelancer = User::factory()->create(['company_id' => $company->id]);
        $freelancer->assignRole('freelancer_client');

        $this->assertFalse($freelancer->can('signEfaktura', $company));
        $this->assertFalse($freelancer->can('manageEfakturaDevice', $company));
    }
```

- [ ] **Step 2: Run — expect FAIL** (`signEfaktura` не постои → сите тврдења за `can(...)` враќаат false, па паѓаат позитивните):
`php -d memory_limit=1G vendor/bin/phpunit tests/Feature/CompanyPolicyTest.php --filter "efaktura|sign"`

- [ ] **Step 3: Implement** — во `app/Policies/CompanyPolicy.php` додади (на крајот на класата):

```php
    /**
     * Запишување на потпишувачкиот уред (токенот) на фирмата. Админ и
     * сметководител на таа фирма — секогаш. Клиентот со интерно сметководство —
     * само за СВОЈАТА фирма, само кога е правно лице и во режим „свој токен":
     * токенот е физички кај него, па само тој може да го прочита. Режимот и
     * ЕУЈП-идентификаторот ги менува канцеларијата (правото `update`).
     */
    public function manageEfakturaDevice(User $user, Company $company): bool
    {
        if ($this->update($user, $company)) {
            return true;
        }

        return $user->hasRole('internal_client')
            && $user->visibleCompanies()->whereKey($company->id)->exists()
            && $company->type->isLegal()
            && $company->efaktura_credential_mode === Company::EFAKTURA_MODE_OWN;
    }

    /**
     * Потпишување и праќање/прием на е-Фактура. Единствено место што одговара
     * „смее ли овој човек". Клиентот дополнително бара веќе запишан токен
     * (`hasEfakturaAccess()`); канцеларијата не бара, зашто ја регистрира.
     */
    public function signEfaktura(User $user, Company $company): bool
    {
        if ($this->update($user, $company)) {
            return true;
        }

        return $this->manageEfakturaDevice($user, $company) && $company->hasEfakturaAccess();
    }
```

- [ ] **Step 4: Run — expect PASS:** `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/CompanyPolicyTest.php`

- [ ] **Step 5: Commit** (`git add app/Policies/CompanyPolicy.php tests/Feature/CompanyPolicyTest.php`; порака на македонски: „Право за потпишување на е-Фактура и запишување токен").

---

## Task 2: Контролерите за праќање, состојба и ПДФ

**Files:**
- Modify: `app/Http/Controllers/EfakturaSendController.php`, `app/Http/Controllers/EfakturaStatusController.php`, `app/Http/Controllers/EfakturaPdfController.php`
- Test: `tests/Feature/EfakturaSendControllerTest.php`, `tests/Feature/EfakturaStatusControllerTest.php`, `tests/Feature/EfakturaPdfControllerTest.php`

**Interfaces:**
- Consumes: `CompanyPolicy::signEfaktura` (Task 1).

- [ ] **Step 1: Failing tests.**

`tests/Feature/EfakturaSendControllerTest.php` (setUp мора да ги создава и `accountant`, `freelancer_client`; постои `makeConfirmedOwnModeInvoice()`): ЗАМЕНИ го `test_client_role_is_forbidden` (клиент на `own`+токен фирма — тоа сега е ДОЗВОЛЕНО) со:

```php
    public function test_an_internal_client_with_an_own_token_can_get_a_signing_input_and_send(): void
    {
        Http::fake(['*' => Http::response(['euid' => 'euid-9'], 200)]);
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        $signing = $this->actingAs($client)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->assertOk()->json();

        $this->actingAs($client)->postJson(
            route('sales-invoices.efaktura.send', [$company, $invoice]),
            ['token' => $signing['token'], 'signature' => 'fake-signature']
        )->assertOk();

        $this->assertSame('sent', $invoice->fresh()->efaktura_status);
    }

    public function test_an_internal_client_of_a_firm_mode_company_is_forbidden_as_json(): void
    {
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $company->update(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        $response = $this->actingAs($client)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
        // bootstrap/app.php shouldRenderJsonWhen мора да ги покрива е-Фактура рутите.
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_an_internal_client_cannot_send_for_another_company(): void
    {
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $otherCompany->id]);
        $client->assignRole('internal_client');

        $this->actingAs($client)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->assertStatus(403);
    }

    public function test_an_internal_client_cannot_send_before_registering_a_token(): void
    {
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $company->update(['efaktura_token_serial_number' => null]);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        $this->actingAs($client)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->assertStatus(403);
    }

    public function test_a_freelancer_client_cannot_use_efaktura(): void
    {
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $freelancer = User::factory()->create(['company_id' => $company->id]);
        $freelancer->assignRole('freelancer_client');

        $this->actingAs($freelancer)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->assertStatus(403);
    }
```

`tests/Feature/EfakturaStatusControllerTest.php`: ЗАМЕНИ го `test_client_role_is_forbidden` (постои `makeOwnModeCompany()`/`makeSentInvoice()`) со истиот образец: (а) `internal_client` на `own`+токен фирма добива 200 на `route('sales-invoices.efaktura.refresh-statuses.signing-input', $company)` со `['certificateBase64' => base64_encode('fake-cert')]` (постои чека�ча фактура во `makeSentInvoice`); (б) `internal_client` на фирма во режим `firm` → 403; (в) `internal_client` на друга фирма → 403.

`tests/Feature/EfakturaPdfControllerTest.php`: прочитај го фајлот, најди го хелперот што гради фактура прифатена кај УЈП (за `signing-input`), и додади ист тип тестови: `internal_client` на `own`+токен фирма → signing-input 200; на `firm` фирма → 403; на друга фирма → 403.

- [ ] **Step 2: Run — expect FAIL** (клиентот сега добива 403 таму каде треба 200):
`php -d memory_limit=1G vendor/bin/phpunit tests/Feature/EfakturaSendControllerTest.php tests/Feature/EfakturaStatusControllerTest.php tests/Feature/EfakturaPdfControllerTest.php`

- [ ] **Step 3: Implement.** Во СЕКОЈ од трите контролери најди го `abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);` (Send: `authorizeSigning`, Status: `authorizeRefresh`, Pdf: `authorizePdf` — провери и дали `download()` има своја проверка на улога и ако има, истото) и замени го со:

```php
        Gate::authorize('signEfaktura', $company);
```

Редоследот на останатите проверки не се менува (прво `view`, па 404 за туѓа фактура, па оваа, па 422-проверките). Додади `use Illuminate\Support\Facades\Gate;` ако недостасува. Не менувај ги 422 пораките за `firm` режим.

- [ ] **Step 4: Run — expect PASS** ист command како Step 2, плус `tests/Feature/SalesInvoiceShowEfakturaTest.php` не се допира тука.

- [ ] **Step 5: Commit** (само три контролери + три тест-фајла; порака на македонски: „Клиент со свој токен смее да праќа, освежува и симнува е-Фактура").

---

## Task 3: Екраните и запишувањето на токенот

**Files:**
- Modify: `resources/views/livewire/invoicing/sales-invoice-show.blade.php` (ред ~80), `resources/views/livewire/invoicing/sales-invoice-index.blade.php` (редови ~5 и ~61), `resources/views/livewire/company-profile.blade.php` (ред ~31), `app/Livewire/CompanyProfile.php` (`registerSigningDevice`)
- Test: `tests/Feature/SalesInvoiceShowEfakturaTest.php`, `tests/Feature/CompanyProfileSigningDeviceTest.php`, `tests/Feature/SalesInvoiceIndexTest.php` (или соодветниот постоечки тест за индексот; ако нема, додади во `SalesInvoiceShowEfakturaTest.php`)

**Interfaces:**
- Consumes: `CompanyPolicy::signEfaktura`, `CompanyPolicy::manageEfakturaDevice` (Task 1).

- [ ] **Step 1: Failing tests.**

`tests/Feature/CompanyProfileSigningDeviceTest.php` (setUp мора да создава `freelancer_client`): ЗАМЕНИ `test_client_cannot_register_a_signing_device` (фирмата таму е со стандарден режим `firm`, па клиентот и понатаму НЕ смее) — задржи го, но направи го експлицитно за `firm` режим; додади:

```php
    public function test_an_internal_client_of_an_own_mode_company_can_register_the_device(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
        ]);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        Livewire::actingAs($client)
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('registerSigningDevice', '1A2B3C', 'CN=Test Company', '2025-01-01T00:00:00Z', '2027-01-01T00:00:00Z')
            ->assertHasNoErrors();

        $this->assertSame('1A2B3C', $company->fresh()->efaktura_token_serial_number);
    }

    public function test_an_internal_client_of_a_firm_mode_company_cannot_register_the_device(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        Livewire::actingAs($client)
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('registerSigningDevice', '1A2B3C', 'CN=Test Company', '2025-01-01T00:00:00Z', '2027-01-01T00:00:00Z')
            ->assertForbidden();

        $this->assertNull($company->fresh()->efaktura_token_serial_number);
    }

    public function test_an_internal_client_cannot_switch_the_mode_or_the_eujp_id(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN, 'efaktura_eujp_id' => 'EUJP-1']);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        Livewire::actingAs($client)
            ->test(CompanyProfile::class, ['company' => $company])
            ->set('editEfakturaMode', Company::EFAKTURA_MODE_FIRM)
            ->call('save')
            ->assertForbidden();

        $this->assertSame(Company::EFAKTURA_MODE_OWN, $company->fresh()->efaktura_credential_mode);
    }
```
(Ако методот за зачувување на профилот не се вика `save`, најди го вистинското име во `CompanyProfile.php`; целта на последниот тест е да докаже дека клиентот и понатаму не може да го менува режимот — постојната `update` порта, која тука НЕ се проширува.)

`tests/Feature/SalesInvoiceShowEfakturaTest.php` (setUp мора да создава `accountant`): ЗАМЕНИ `test_sign_and_send_button_hidden_for_client` (фирмата е `own`+токен, па клиентот сега ГО ГЛЕДА копчето) со:

```php
    public function test_sign_and_send_button_visible_for_an_internal_client_with_an_own_token(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        Livewire::actingAs($client)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('Потпиши и испрати до УЈП');
    }

    public function test_sign_and_send_button_hidden_for_an_internal_client_of_a_firm_mode_company(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        Livewire::actingAs($client)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertDontSee('Потпиши и испрати до УЈП');
    }
```

Индекс-екранот: додади тест (во постоечкиот `tests/Feature/SalesInvoiceIndexTest.php` ако постои — провери; инаку во `SalesInvoiceShowEfakturaTest.php`) дека `internal_client` на `firm` фирма НЕ ја гледа „освежи статус" контролата, а на `own`+токен ја гледа (прочитај го Blade-от на индексот за точниот текст на копчето).

- [ ] **Step 2: Run — expect FAIL:** `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/CompanyProfileSigningDeviceTest.php tests/Feature/SalesInvoiceShowEfakturaTest.php`

- [ ] **Step 3: Implement.**

`sales-invoice-show.blade.php` ред ~80: замени `auth()->user()->hasAnyRole(['admin', 'accountant'])` со `auth()->user()->can('signEfaktura', $company)`.

`sales-invoice-index.blade.php`: на редот ~5 (`@if ($company->hasEfakturaAccess() && ... OWN)`) и на редот ~61 додади и `&& auth()->user()->can('signEfaktura', $company)` (така клиентот на `firm` фирма не гледа копче што секогаш враќа 403).

`company-profile.blade.php` ред ~31: замени `auth()->user()->hasAnyRole(['admin', 'accountant'])` со `auth()->user()->can('manageEfakturaDevice', $company)` (условот `$company->type->isLegal()` остани).

`app/Livewire/CompanyProfile.php`, `registerSigningDevice`: замени го блокот

```php
        abort_unless(
            auth()->user()->hasAnyRole(['admin', 'accountant'])
                && auth()->user()->visibleCompanies()->whereKey($this->company->id)->exists(),
            403
        );
```

со

```php
        // Livewire не го повторува mount() при дејство, па правото се проверува тука.
        Gate::authorize('manageEfakturaDevice', $this->company);
```
(остави ја проверката `abort_if($this->company->type->isIndividual(), 403, …)` подолу.) Додади `use Illuminate\Support\Facades\Gate;` ако недостасува (веќе се користи во датотеката).

- [ ] **Step 4: Run — expect PASS:** `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/CompanyProfileSigningDeviceTest.php tests/Feature/SalesInvoiceShowEfakturaTest.php tests/Feature/CompanyProfileTest.php tests/Feature/CompanyProfileEfakturaRequestTest.php tests/Feature/SalesInvoiceShowTest.php` (+ индекс-тестот). Потоа `npm run build` (Blade е менувана).

- [ ] **Step 5: Commit** (порака на македонски: „Клиентот со свој токен го запишува токенот и ги гледа копчињата за е-Фактура").

---

## Task 4: Совет за клиент што уште нема запишан токен

Финалниот наод на ревизијата на Task 3: `internal_client` во режим „свој токен" кој уште нема запишан токен не гледа НИШТО во е-Фактура блокот на фактурата (целиот блок е зад `signEfaktura`), па нема поим дека токенот се запишува на профилот на фирмата.

**Files:**
- Modify: `resources/views/livewire/invoicing/sales-invoice-show.blade.php` (ред ~80)
- Test: `tests/Feature/SalesInvoiceShowEfakturaTest.php`

- [ ] **Step 1: Failing tests** (додади во `SalesInvoiceShowEfakturaTest.php`; постојат helper-и/образец за компанија+фактура во истиот фајл; текстот на советот е точно: `Регистрирај потпишувачки уред за оваа компанија`):
  - `internal_client` на `own` фирма со eUJP-id, БЕЗ запишан токен (`efaktura_token_serial_number` = null), потврдена фактура → ГО ГЛЕДА советот и НЕ го гледа копчето „Потпиши и испрати до УЈП".
  - `internal_client` на `firm` фирма → НЕ го гледа советот.
  - `freelancer_client` на `own` фирма без токен → НЕ го гледа советот.
  - Постојните тестови за админ (совет кога нема уред) остануваат непроменети.

- [ ] **Step 2: Run — expect FAIL** на првиот тест: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/SalesInvoiceShowEfakturaTest.php`

- [ ] **Step 3: Implement** — на редот ~80 замени `auth()->user()->can('signEfaktura', $company)` со `(auth()->user()->can('signEfaktura', $company) || auth()->user()->can('manageEfakturaDevice', $company))`. Внатрешните услови не се менуваат: кога нема запишан токен се прикажува постојниот совет, а копчето само кога `signEfaktura` навистина важи (внатрешниот `else` бара `hasEfakturaAccess()`, а клиентот со `manageEfakturaDevice` без токен никогаш не стигнува до него).

- [ ] **Step 4: Run — expect PASS:** `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/SalesInvoiceShowEfakturaTest.php tests/Feature/SalesInvoiceShowTest.php tests/Feature/SalesInvoiceIndexTest.php`. Докажи дека негативните тестови не се празни: привремено врати ја старата состојба (само `signEfaktura`) и види дека првиот тест паѓа; и привремено замени го `manageEfakturaDevice` со `true` и види дека `freelancer_client` тестот паѓа; врати.

- [ ] **Step 5: Commit** (порака на македонски: „Клиент без запишан токен добива совет каде да го запише").
