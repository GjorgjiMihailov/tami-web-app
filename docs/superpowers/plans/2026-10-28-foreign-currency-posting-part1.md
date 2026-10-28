# Девизна фактура — книжење и наплата (дел 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Девизната излезна фактура се книжи на 1210 (добра) / 1211 (услуги), а нејзината наплата на 1030 по курсот на денот на приливот, со курсна разлика на 4750/7750 и побарување што се затвора точно на нула.

**Architecture:** Фактурата останува во моторот за шеми (нов услов `foreign`, нови променливи `ВКУПНО_ДОБРА`/`ВКУПНО_УСЛУГИ`, нови редови во стандардната шема + миграција за нетипнатите постојни шеми). Девизната наплата НЕ оди низ шемата: ја книжи нов сервис `ForeignReceiptPosting` со фиксни конта (1030, 4750, 7750) и со сметките на побарувањето прочитани од веќе книженото книжење на фактурата. Курсот на фактурата останува на побарувањето; курсот на денот оди само на 1030.

**Tech Stack:** Laravel, Livewire 3, PHPUnit (`RefreshDatabase`), bcmath (`App\Support\Bcmath::roundHalfUp`), MySQL во CI.

**Spec:** `docs/superpowers/specs/2026-10-28-foreign-currency-posting-design.md` (само дел 1; делови 2 и 3 имаат свој план).

## Global Constraints

- Валути: EUR и USD се наменетите; кодот не ги ограничува повеќе од постојните `SalesInvoice::CURRENCIES`.
- Сметки: **1210** добра, **1211** услуги, **1030** девизна сметка, **4750** негативна, **7750** позитивна курсна разлика. Сите се аналитички во официјалниот контен план.
- Група на налог за девизна наплата од страната на фактурата: `JournalGroupCodes::FOREIGN_BANK` (`'11'`).
- Курс на фактурата останува `sales_invoices.exchange_rate` (`decimal(12,6)`); курсот на наплатата е ист тип.
- Побарувањето се затвора **точно на нула** при секој број плаќања (одлука 2026-09-13). `bcadd` сече, `decimal(15,2)` заокружува: износот се нормализира на влез со `Bcmath::roundHalfUp`, никогаш не поминува низ двата пата.
- Денарска фактура и денарска наплата книжат **идентично** како досега (нема промена на износи, конта или услови што ги гледа корисникот).
- Курсна разлика = денари по курс на приливот − затворено побарување по курс на фактурата. Позитивна → кредит 7750; негативна → дебит 4750; нула → без ред.
- Нема девизна наплата во готово.
- Текстови за корисникот се на македонски. Без измена на `@script` блоковите во `sales-invoice-show.blade.php` (бајт-идентични).
- Без `EnterWorktree`: работи во главниот checkout.

## Review Focus

1. Мешана фактура (добра + услуги): три делумни наплати затвораат и 1210 и 1211 на точно 0,00 и налозите балансираат.
2. Курс на приливот повисок/понизок/ист од курсот на фактурата: 7750, 4750, или без ред за разлика.
3. Фирма чија шема ја менувал сметководител (не е „нетипната"): девизната фактура сè уште се книжи (паѓа на 1200) и наплатата ја затвора таму каде што е отворена.
4. Девизна наплата без курс, со курс ≤ 0, во готово, или над салдото: одбиена, и не остава запис за плаќање без налог.
5. Фирма без конто 1030, 4750 или 7750: јасна грешка на македонски и ништо не се запишува (враќање на трансакцијата).

## File Structure

| Фајл | Улога |
|---|---|
| `app/Services/Posting/PostingSchemeEngine.php` | `KNOWN_FLAGS` + `foreign` |
| `app/Support/Posting/PostingVocabulary.php` | променливи и услов за `SALES_INVOICE` |
| `app/Services/Posting/SalesInvoicePostingContext.php` | флаг `foreign`, `ВКУПНО_ДОБРА`, `ВКУПНО_УСЛУГИ` |
| `app/Services/Posting/PostingSampleContexts.php` | пробен девизен документ |
| `app/Services/Posting/DefaultPostingSchemes.php` | нови редови за излезна фактура |
| `database/migrations/2026_10_28_110000_upgrade_default_sales_scheme_for_foreign_receivables.php` | надградба на нетипнатите шеми |
| `database/migrations/2026_10_28_100000_add_exchange_rate_to_sales_invoice_payments_table.php` | курс на наплата |
| `app/Models/SalesInvoicePayment.php` | `exchange_rate` во `$fillable` и casts |
| `app/Services/Posting/PostedInvoiceAccounts.php` | `receivables()` |
| `app/Services/Posting/ForeignReceiptPosting.php` (нов) | ставки на девизна наплата |
| `app/Services/Invoicing/SalesInvoiceService.php` | `recordPayment()` — девизна гранка |
| `app/Livewire/Invoicing/SalesInvoiceShow.php` + blade | поле за курс, копче НБРМ |
| `app/Console/Commands/CountForeignInvoicesOn1200.php` (нов) + `.github/workflows/deploy.yml` | пријава при пуштање |

---

### Task 1: Услов `foreign` и променливи за девизно побарување

**Files:**
- Modify: `app/Services/Posting/PostingSchemeEngine.php` (`KNOWN_FLAGS`)
- Modify: `app/Support/Posting/PostingVocabulary.php` (`variables()`, `conditions()` за `SALES_INVOICE`)
- Modify: `app/Services/Posting/SalesInvoicePostingContext.php`
- Modify: `app/Services/Posting/PostingSampleContexts.php`
- Test: `tests/Feature/Posting/SalesInvoicePostingContextTest.php`, `tests/Feature/Posting/PostingSchemeEngineTest.php`, `tests/Feature/Posting/PostingSampleContextsTest.php`

**Interfaces:**
- Produces: `PostingContext::flags['foreign']` (bool); `totals['ВКУПНО_ДОБРА']`, `totals['ВКУПНО_УСЛУГИ']` и истите во `foreignTotals`. Условот `foreign` / `not_foreign` е дозволен за `SALES_INVOICE`.

- [ ] **Step 1: Failing tests**
  - `SalesInvoicePostingContextTest`: `test_a_foreign_invoice_splits_gross_into_goods_and_services` — фактура 600 EUR стока + 400 EUR услуга без ДДВ, курс 61,5 → `totals['ВКУПНО_ДОБРА'] === '36900.00'`, `totals['ВКУПНО_УСЛУГИ'] === '24600.00'`, `foreignTotals['ВКУПНО_ДОБРА'] === '600.00'`, `flags['foreign'] === true`. `test_goods_plus_services_always_equals_gross` — со ДДВ 18% и курс 61,4733: `bcadd(ДОБРА, УСЛУГИ, 2) === totals['ВКУПНО']` (услугите се остаток: бруто − добра). `test_a_denar_invoice_is_not_foreign` — `flags['foreign'] === false`.
  - `PostingSchemeEngineTest`: `test_foreign_and_not_foreign_conditions_are_accepted` — ред со услов `foreign` се прескокнува кога флагот е false, а `not_foreign` кога е true.
  - `PostingSampleContextsTest`: `test_sales_invoice_samples_include_a_foreign_document` — меѓу примероците има еден со `flags['foreign'] === true` и двете нови променливи.
- [ ] **Step 2:** Run `php artisan test --filter=SalesInvoicePostingContextTest` → FAIL.
- [ ] **Step 3: Implement.** Во моторот додади `'foreign'` во `KNOWN_FLAGS`. Во речникот додади двете променливи (опис: „Вкупно со ДДВ за добра, во денари" / „… за услуги …") и пар `'foreign' => 'девизна фактура'` за `SALES_INVOICE`. Во `SalesInvoicePostingContext::build()` пресметај `ВКУПНО_ДОБРА` како збир `base+vat` на кришките со `ItemKind::GOODS` **откако** `absorbRounding()` ќе заврши, и `ВКУПНО_УСЛУГИ = ВКУПНО − ВКУПНО_ДОБРА`; истото во валута од `baseForeign+vatForeign`, со услугите како остаток од `$grossForeign`. Додади `'foreign' => $foreign` во `flags`. Во `PostingSampleContexts::salesInvoice()` додади примерок „Девизна фактура — стока и услуга" и дај им на постојните примероци `ВКУПНО_ДОБРА`/`ВКУПНО_УСЛУГИ` само ако некој ред ги бара (постојните шеми не ги користат, па не мора).
- [ ] **Step 4:** Run `php artisan test --filter="SalesInvoicePostingContextTest|PostingSchemeEngineTest|PostingSampleContextsTest"` → PASS.
- [ ] **Step 5: Commit** `git add app tests && git commit -m "feat(posting): foreign flag and goods/services receivable split"`

### Task 2: Стандардна шема на 1210/1211 + надградба на постојните

**Files:**
- Modify: `app/Services/Posting/DefaultPostingSchemes.php` (`salesInvoice()`)
- Create: `database/migrations/2026_10_28_110000_upgrade_default_sales_scheme_for_foreign_receivables.php`
- Test: `tests/Feature/Posting/DefaultPostingSchemesTest.php`, `tests/Feature/Posting/SalesInvoiceSchemePostingTest.php`, `tests/Feature/Posting/UpgradeForeignReceivablesMigrationTest.php` (нов)

**Interfaces:**
- Consumes: Task 1 (`foreign`, `ВКУПНО_ДОБРА`, `ВКУПНО_УСЛУГИ`).
- Produces: стандардна шема со редови: Должи 1200 `ВКУПНО` услов `not_foreign`; Должи 1210 `ВКУПНО_ДОБРА` услов `foreign`; Должи 1211 `ВКУПНО_УСЛУГИ` услов `foreign` (сите `partner: true`, опис `{фактура}`), на истите позиции пред приходот.

- [ ] **Step 1: Failing tests**
  - `SalesInvoiceSchemePostingTest::test_a_euro_invoice_with_goods_and_services_books_receivable_on_1210_and_1211` — Должи 1210 36.900,00 (600 EUR) и 1211 24.600,00 (400 EUR), и двете со `foreign_amount`, `currency_code='EUR'`, `exchange_rate=61.5`; нема ставка на 1200; налогот балансира.
  - `test_a_euro_invoice_with_only_services_has_no_1210_line` (нулата се прескокнува).
  - `test_a_denar_invoice_still_books_on_1200` — исти износи и конто како пред промената.
  - `UpgradeForeignReceivablesMigrationTest`: `test_an_untouched_default_scheme_gets_the_foreign_rows`, `test_a_scheme_edited_by_an_accountant_is_left_alone` (променета формула → редовите непроменети), `test_running_the_migration_twice_adds_the_rows_once`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3: Implement.** Дефиниција: на 1200 додади `'condition' => 'not_foreign'` и вметни ги двата нови реда веднаш по него. Миграцијата ги надградува само шемите чиј скуп на редови е точно сегашната стандардна (споредба како во `2026_10_08_120000_upgrade_default_sales_schemes_for_import_cogs.php`); идемпотентна (ако редот 1210 веќе постои, прескокни); ако на фирмата недостасува 1210 или 1211, прескокни ја шемата. `down()` празен.
- [ ] **Step 4:** Run `php artisan test --filter="Posting"` → PASS.
- [ ] **Step 5: Commit** `git commit -m "feat(posting): default sales scheme books foreign receivables on 1210/1211"`

### Task 3: Курс на наплата

**Files:**
- Create: `database/migrations/2026_10_28_100000_add_exchange_rate_to_sales_invoice_payments_table.php`
- Modify: `app/Models/SalesInvoicePayment.php`
- Test: `tests/Feature/ForeignPaymentRateStorageTest.php`

**Interfaces:**
- Produces: `sales_invoice_payments.exchange_rate` `decimal(12,6)` nullable; модел: `exchange_rate` во `$fillable`, cast `'decimal:6'`. Денарска наплата: `null`.

- [ ] **Step 1: Failing test** `test_a_payment_can_store_the_exchange_rate_of_the_day_and_existing_payments_have_none` — создај плаќање со `exchange_rate = '61.200000'`, прочитај го; постојно плаќање од фабрика има `null`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Миграција со чиста `ADD COLUMN` (nullable, без пополнување, без индекс); модел.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5: Commit** `git commit -m "feat(payments): store the exchange rate of the payment day"`

### Task 4: `ForeignReceiptPosting` — ставките на девизна наплата

**Files:**
- Modify: `app/Services/Posting/PostedInvoiceAccounts.php`
- Create: `app/Services/Posting/ForeignReceiptPosting.php`
- Test: `tests/Feature/Posting/ForeignReceiptPostingTest.php`

**Interfaces:**
- Consumes: книжени ставки на фактурата (`InvoiceJournal::linesOf()`), `Bcmath::roundHalfUp`.
- Produces:
  - `PostedInvoiceAccounts::receivables(SalesInvoice $invoice): list<array{account: Account, debit: string, foreign: string}>` — дебитните ставки на фактурата со партнерот на фактурата, со денарскиот износ и `foreign_amount`.
  - `ForeignReceiptPosting::lines(SalesInvoice $invoice, string $amount, string $paidBefore, string $rate, string $label): list<PostingLine>` — `$amount` и `$paidBefore` во валутата на фактурата; `$rate` курс на денот. Фрла `InvalidInvoiceStateException` ако нема книжено побарување со износ во валута или ако фирмата нема 1030/4750/7750.

Правило (јасно за градителот): за секое побарување A со денарски износ `D_A`, износ во валута `F_A`, вкупно `F = ΣF_A`, затворено до кумулативна уплата `P` е `closed_A(P) = roundHalfUp(D_A × P / F, 2)`; кредитот на A при оваа уплата е `closed_A(paidBefore + amount) − closed_A(paidBefore)`; истото за валутата со `F_A`. Приливот во денари е `roundHalfUp(amount × rate, 2)`. Ставки: Должи 1030 (приливот; валута, износ, курс на денот), Побарува A (затворено; валута, курс на фактурата), и разликата на 4750 (Должи) или 7750 (Побарува) без податоци за валута. Збирот Должи = збирот Побарува по конструкција.

- [ ] **Step 1: Failing tests** (`ForeignReceiptPostingTest`)
  - `test_a_loss_goes_to_4750` — 1.000 EUR услуги, фактура по 61,50; наплата 1.000 EUR по 61,20 → Должи 1030 61.200, Должи 4750 300, Побарува 1211 61.500.
  - `test_a_gain_goes_to_7750` — по 61,80 → Должи 1030 61.800, Побарува 1211 61.500, Побарува 7750 300.
  - `test_equal_rate_has_no_difference_line`.
  - `test_a_mixed_invoice_closes_both_receivables_proportionally` — 600 EUR стока + 400 EUR услуга, наплата 400 EUR по 61,50 → Побарува 1210 14.760, Побарува 1211 9.840, Должи 1030 24.600.
  - `test_three_partial_payments_close_every_receivable_to_exactly_zero` — фактура 1.000,00 EUR по 61,4733 со ДДВ, платена 333,33 + 333,33 + 333,34 по три различни курсеви; збирот на кредитите на секое побарување е еднаков на неговиот дебит, и секој налог балансира.
  - `test_the_1030_line_carries_the_payment_day_rate_and_the_receivable_line_the_invoice_rate`.
  - `test_it_refuses_when_the_company_has_no_1030` — исклучок со македонска порака.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3: Implement** според правилото погоре. Сметките по шифра преку `Account::where('company_id', …)->analytical()->where('code', …)`.
- [ ] **Step 4:** Run `php artisan test --filter=ForeignReceiptPostingTest` → PASS.
- [ ] **Step 5: Commit** `git commit -m "feat(posting): foreign receipt posting with realized exchange difference"`

### Task 5: `recordPayment()` — девизна гранка

**Files:**
- Modify: `app/Services/Invoicing/SalesInvoiceService.php` (`recordPayment`, `paymentJournalGroup`)
- Modify: `tests/Feature/ForeignCurrencyInvoiceTest.php` (тестовите за наплата, околу редови 603–760)
- Test: `tests/Feature/ForeignPaymentTest.php` (нов)

**Interfaces:**
- Consumes: Task 3 (`exchange_rate` на плаќањето), Task 4 (`ForeignReceiptPosting::lines`).
- Produces: `recordPayment(SalesInvoice $invoice, string $amount, string $paymentDate, string $paymentMethod, int $userId, ?string $paymentRate = null): SalesInvoicePayment`. За девизна фактура: `paymentRate` е задолжителен (> 0), `paymentMethod` мора да е `bank`; налогот оди во група 11; плаќањето ја чува стапката. Денарска фактура: непроменето, `paymentRate` се игнорира.

- [ ] **Step 1: Failing tests** (`ForeignPaymentTest`)
  - `test_a_foreign_payment_without_a_rate_is_refused_and_leaves_nothing_behind` (нема запис за плаќање, нема налог).
  - `test_a_zero_or_negative_rate_is_refused`.
  - `test_a_foreign_payment_in_cash_is_refused`.
  - `test_a_payment_above_the_balance_is_still_refused`.
  - `test_a_foreign_payment_is_posted_in_group_11_with_1030_and_stores_the_rate`.
  - `test_a_missing_1030_rolls_everything_back` (бришење на 1030 од фирмата → исклучок, нема плаќање).
  - `test_a_denar_payment_is_unchanged` (1000, група 10).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3: Implement.** Во `recordPayment()`: ако фактурата е девизна, нормализирај `$amount` и `$paymentRate`, провери, па во истата транзакција создај плаќање со `exchange_rate`, налог во `FOREIGN_BANK`, и ставки од `ForeignReceiptPosting::lines(…, $paidBefore = $invoice->paidTotal() пред создавање, …)`. Постојниот пат (`toMkd` + шема `SALES_PAYMENT`) останува само за денарска фактура. **Постојните девизни тестови за наплата** повикуваат `recordPayment` без курс и проверуваат на 1200: ажурирај ги да даваат курс на фактурата и очекувани сметки 1210/1211 + 1030; **не ги ослабнувај** тврдењата „затворено точно на нула" и „налогот балансира".
- [ ] **Step 4:** Run `php artisan test --filter="ForeignPaymentTest|ForeignCurrencyInvoiceTest|SalesPaymentSchemePostingTest"` → PASS.
- [ ] **Step 5: Commit** `git commit -m "feat(invoicing): foreign-currency payment posts to 1030 at the day's rate"`

### Task 6: Екран — курс на денот при наплата

**Files:**
- Modify: `app/Livewire/Invoicing/SalesInvoiceShow.php`
- Modify: `resources/views/livewire/invoicing/sales-invoice-show.blade.php` (формата за наплата, околу редови 406–420; **не** `@script` блоковите)
- Test: `tests/Feature/SalesInvoiceShowForeignPaymentTest.php`

**Interfaces:**
- Consumes: Task 5 (`recordPayment(..., ?string $paymentRate)`).
- Produces: јавни својства `string $paymentRate = ''` и метод `fetchPaymentRate(): void` (НБРМ за `paymentDate`, истиот обрасец како `SalesInvoiceForm::fetchRate`, со `resetErrorBag('paymentRate')` на успех).

- [ ] **Step 1: Failing tests**
  - `test_the_rate_field_is_shown_only_for_a_foreign_invoice`.
  - `test_the_cash_method_is_not_offered_for_a_foreign_invoice`.
  - `test_the_nbrm_button_fills_the_rate_for_the_payment_date` (мок на `ExchangeRateService`, како постојниот тест за фактурата).
  - `test_a_failing_nbrm_call_keeps_the_field_typeable`.
  - `test_recording_a_foreign_payment_through_the_screen_posts_it` (износ, датум, курс → ставка на 1030).
  - `test_a_foreign_payment_without_a_rate_shows_a_field_error`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3: Implement.** Валидација на курсот (`required_if` девизна, `numeric`, `gt:0`, `decimal:0,6`); прикажи полето и копчето „НБРМ" само кога `$invoice->isForeignCurrency()`; на девизна фактура начинот е само „банка".
- [ ] **Step 4:** Run `php artisan test --filter="SalesInvoiceShow"` → PASS (без пад на постојните `SalesInvoiceShowTest`).
- [ ] **Step 5: Commit** `git commit -m "feat(ui): rate of the day on foreign-currency payment"`

### Task 7: Пријава при пуштање — девизни фактури на 1200

**Files:**
- Create: `app/Console/Commands/CountForeignInvoicesOn1200.php`
- Modify: `.github/workflows/deploy.yml` (еден ред покрај постојните `tinker --execute` пријави, околу ред 138)
- Test: `tests/Feature/CountForeignInvoicesOn1200Test.php`

**Interfaces:**
- Produces: `php artisan invoices:count-foreign-on-1200` — печати „Девизни фактури книжени на 1200: N" (потврдени девизни фактури чие побарување е на 1200); не менува ништо.

- [ ] **Step 1: Failing test** `test_it_reports_the_number_of_foreign_invoices_posted_to_1200` — една девизна фактура книжена преку заменета шема на 1200 и една на 1210 → излез „… 1".
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3: Implement** командата; во `deploy.yml` додади `php artisan invoices:count-foreign-on-1200` по `accounts:sync-official`.
- [ ] **Step 4:** Run `php artisan test --filter=CountForeignInvoicesOn1200Test` → PASS.
- [ ] **Step 5: Commit** `git commit -m "chore(deploy): report foreign invoices posted to 1200"`

### Task 8: Завршна проверка на целиот дел

- [ ] **Step 1:** Една `tests/Feature/Posting/ForeignFlowEndToEndTest.php`: `test_mixed_foreign_invoice_two_payments_at_different_rates_closes_to_zero` — фактура 600 EUR стока + 400 EUR услуга со 18% ДДВ по 61,4733, две наплати по различен курс; на крајот салдото на 1210 и 1211 е 0,00, 1030 е збирот на приливите, 4750/7750 е нето разликата, и нема небалансиран налог.
- [ ] **Step 2:** Run the full suite once: `php artisan test` → сè зелено (очекувано: нови тестови над 2425+ постојните; нема пад на постојните).
- [ ] **Step 3: Commit** `git commit -m "test: end-to-end foreign invoice and receipt"`

---

## Self-Review

- **Покриеност на спецификацијата (дел 1):** 1210/1211 по вид (Task 1–2); услов за девизна (Task 1); наплата со износ во валута, курс на денот, денарска вредност, курсна разлика (Task 3–5); копче „Наплата" со курс и 1030 (Task 6); побарување точно на нула (Task 4, 8); постојни шеми (Task 2); пријава на фактури на 1200 (Task 7). Делови 2 и 3, девизен извод, 743, евиденција се надвор.
- **Свесно отстапување од шемите:** девизната **наплата** не е шема-управувана, зашто бара курсна разлика и повеќе побарувања по една уплата; сметките 1030/4750/7750 се константи. Да се пренесе во шема подоцна е можно, но не е во овој дел.
- **Конзистентност на имињата:** `ForeignReceiptPosting::lines`, `PostedInvoiceAccounts::receivables`, `recordPayment(..., ?string $paymentRate)`, `fetchPaymentRate`, `ВКУПНО_ДОБРА`/`ВКУПНО_УСЛУГИ`, флаг `foreign` — исти во сите задачи.
- **Пропорција:** планот е пократок од кодот што го опишува; телата на методите ги пишува градителот.
