# е-Фактура чекор 4 — влезни е-фактури за internal_client

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `internal_client` на фирма во режим „свој токен" со запишан токен да ги открива, прифаќа и одбива сопствените влезни е-фактури и да го фати ПДФ-от; истото правило како чекор 2 (`CompanyPolicy::signEfaktura`). Спецификација: `docs/superpowers/specs/2026-09-23-efaktura-internal-client-design.md` (чекор 4). Прифаќањето креира само НАЦРТ влезна фактура (`IncomingPurchaseInvoiceBuilder` → `status = 'draft'`, без книжење), па нема посебен книговодствен дизајн.

**Architecture:** Четирите влезни контролери (`EfakturaIncomingDiscoveryController`, `EfakturaIncomingAcceptController`, `EfakturaIncomingRejectController`, `EfakturaIncomingPdfController`) го заменуваат `abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403)` со `Gate::authorize('signEfaktura', $company)`. Blade-от на екранот за влезни фактури ги менува четирите `can('update', $company)` (ставени во чекор 2 како привремена заштита) во `can('signEfaktura', $company)`.

**Tech Stack:** Laravel 13, Livewire 3, Spatie/laravel-permission, PHPUnit (SQLite).

## Global Constraints

- Тестови: `php -d memory_limit=1G vendor/bin/phpunit <фајл>` само врз фајловите што се допираат; **никогаш целата серија** (агент е убиен по 600s тишина; контролорот ја пушта) и никогаш `composer test`.
- Коментари на македонски, идентификатори на англиски. UTF-8 без BOM. Секој комит завршува ТОЧНО со `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`.
- Ова одговара до УЈП во име на клиент: правото важи и на серверот (не само со криење копче). Редоследот и пораките на постојните проверки не се менуваат: `view` → 404 (документ на друга фирма) → `signEfaktura` → 422 проверки (режим `firm` е НЕподдржан за сите; веќе одлучен документ; ПДФ веќе преземен; не е прифатен).
- `EfakturaIncomingPdfController::download()` останува само со `view` (постоечко, непроменето).
- `firm` режим: клиентот добива 403 од политиката (не 422) — прифатено во чекор 2.
- Постојните тестови што тврдат „клиентот е забранет/не ги гледа контролите" за фирма во режим `own` со запишан токен го кодираат СТАРОТО правило — сменете ги на новото (клиент на своја `own`+токен фирма СМЕЕ; клиент на `firm` фирма, без токен, на туѓа фирма, `freelancer_client` — НЕ смеат), не ја губете заштитата и докажете (со привремено отстранување) дека негативните тестови не се празни.

---

## Task 1: Влезните контролери

**Files:**
- Modify: `app/Http/Controllers/EfakturaIncomingDiscoveryController.php`, `EfakturaIncomingAcceptController.php`, `EfakturaIncomingRejectController.php`, `EfakturaIncomingPdfController.php`
- Test: `tests/Feature/EfakturaIncomingDiscoveryControllerTest.php`, `EfakturaIncomingAcceptControllerTest.php`, `EfakturaIncomingRejectControllerTest.php`, `EfakturaIncomingPdfControllerTest.php`

**Interfaces:**
- Consumes: `CompanyPolicy::signEfaktura(User, Company)` (веќе на main).

- [ ] **Step 1: Failing tests.** Во секој од четирите тест-фајла прочитај ги постојните хелпери (компанија во `own` режим, влезен документ, фејковање на УЈП со `Http::fake`) и најди тест што тврди дека клиентот е забранет (наречен слично на `test_client_role_is_forbidden`). ЗАМЕНИ го (тој сценарио — клиент на `own`+токен фирма — станува ДОЗВОЛЕНО) со:
  - позитивен: `internal_client` на `own`+токен фирма поминува низ целиот тек на контролерот (signing-input 200, па чекорот што праќа/зачувува 200 и резултатот во базата: Accept → документот `decision = accepted` + креирана НАЦРТ влезна фактура; Reject → `decision = rejected`; Discovery → 200 на чекорите што ги покрива постојниот тест; Pdf → signing-input 200 и `store`);
  - негативни (секој 403): клиент на `firm` режим фирма; `internal_client` на `own` фирма БЕЗ запишан токен (`efaktura_token_serial_number` = null); `freelancer_client`; и (каде има документ) клиент на друга фирма кон документ во негова URL (прочитај што враќа постојниот код: 404/403).
  - Не го менувај однесувањето за админ/сметководител: постојните тестови за нив остануваат.
- [ ] **Step 2: Run — expect FAIL:** `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/EfakturaIncomingDiscoveryControllerTest.php tests/Feature/EfakturaIncomingAcceptControllerTest.php tests/Feature/EfakturaIncomingRejectControllerTest.php tests/Feature/EfakturaIncomingPdfControllerTest.php` (позитивните тестови на клиентот добиваат 403).
- [ ] **Step 3: Implement.** Во секој контролер замени го `abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);` (Discovery: `authorizeDiscovery`; Accept/Reject: `authorizeDecision`; Pdf: `authorizePdf`) со `Gate::authorize('signEfaktura', $company);` — на истото место (по `view` и по 404-проверката за документот). Прочитај ги СИТЕ јавни акции на четирите контролери: ако некоја друга акција (освен `EfakturaIncomingPdfController::download`, кое намерно останува на `view`) има своја проверка на улога или нема авторизација, пријави во извештајот — не измислувај ново однесување. Провери дека `grep -n hasAnyRole` врз четирите контролери не остава ништо.
- [ ] **Step 4: Run — expect PASS** (истата команда) плус `tests/Feature/CompanyPolicyTest.php`. Докажи ненепразност: привремено отстрани го `Gate::authorize('signEfaktura', $company)` во ЕДЕН контролер (Accept), види дека негативните тестови на тој контролер (без токен / firm режим / freelancer) паѓаат, врати.
- [ ] **Step 5: Commit** (само четирите контролери + тест-фајловите што се сменети; порака на македонски: „Клиент со свој токен смее да открива, прифаќа и одбива влезни е-фактури").

---

## Task 2: Екранот за влезни фактури

**Files:**
- Modify: `resources/views/livewire/invoicing/purchase-invoice-index.blade.php` (четирите места со `can('update', $company)`, редови ~5, ~18, ~53, ~127)
- Test: `tests/Feature/PurchaseInvoiceIndexTest.php`

**Interfaces:**
- Consumes: `CompanyPolicy::signEfaktura`; Task 1.

- [ ] **Step 1: Failing tests** — во `PurchaseInvoiceIndexTest.php` најди ги тестовите од чекор 2 што тврдат дека `internal_client` на `own`+токен фирма НЕ гледа „Провери за е-Фактури", Прифати/Одбиј и влезниот ПДФ (`incomingEfakturaPdfFetch(`), и смени ги во ПОЗИТИВНИ (клиентот со токен ги гледа); додади негативни: `internal_client` на `own` фирма БЕЗ токен ги НЕ гледа; `internal_client` на `firm` фирма ги НЕ гледа; `freelancer_client` ги НЕ гледа; админ и доделен сметководител ги гледаат (постоечко).
- [ ] **Step 2: Run — expect FAIL** (позитивните за клиент): `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/PurchaseInvoiceIndexTest.php`
- [ ] **Step 3: Implement** — на секое од четирите места замени `auth()->user()->can('update', $company)` со `auth()->user()->can('signEfaktura', $company)`. Ништо друго во фајлот не менувај.
- [ ] **Step 4: Run — expect PASS:** `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/PurchaseInvoiceIndexTest.php tests/Feature/PurchaseInvoiceShowTest.php tests/Feature/PurchaseInvoiceFormTest.php`; потоа `npm run build`. Докажи ненепразност за ЧЕТИРИТЕ места: привремено врати `can('update', $company)` на едно по едно, види дека соодветниот негативен тест (без токен / firm / freelancer) паѓа, врати.
- [ ] **Step 5: Commit** (порака на македонски: „Екранот за влезни фактури ги покажува е-Фактура копчињата според правото за потпишување").
