# Девизен извод (дел 2) — план за изградба

> **За агентите:** REQUIRED SUB-SKILL: superpowers:executing-plans (нативно, по избор на сопственикот). Чекорите се TDD: тест → гледај да падне → код → гледај да помине.

**Цел:** Девизен извод со валута (EUR/USD) што се книжи на 1030 (наплата на фактура, продажба на девиза → 1039), и денарски извод што на ред од 1039 носи девизен податок и сам ја предлага курсната разлика на 4750/7750.

**Архитектура:** Девизниот извод е посебен екран (`ForeignStatementBook`) и посебен книжач (`ForeignStatementPoster`) што `BankStatementPoster::post` го повикува кога изводот е девизен. Наплатата ја користи постојната `ForeignReceiptPosting` (дел 1), но ставките ги пишува во налогот на изводот, не во свој налог. Денарскиот извод добива три нови полиња на редот (девизен износ, валута, „книжи ја разликата“) и мал сервис `Settlement1039` што пресметува предлог од салдото на 1039.

**Спец:** `docs/superpowers/specs/2026-10-28-foreign-currency-posting-design.md` (дел 2). Дел 1: `…-part1.md`.

## Глобални ограничувања

- Македонски текст на екран и пораки; без бугаризми.
- `bcadd` сече, `decimal(15,2)` заокружува: износите се нормализираат со `Bcmath::roundHalfUp` на влез (одлука 2026-09-14).
- Девизен извод: `amount` на ставката е во **валутата на изводот**; денарската вредност е `roundHalfUp(amount × курс, 2)`. Состојбите (почетна/крајна) се во валутата.
- Налогот е во група **11** (`JournalGroupCodes::FOREIGN_BANK`), преку `BankAccountGroups::groupFor`.
- Курсната разлика на конверзијата 1030→1039 е **нула**: двете ставки се по истиот курс (одлука на сопственикот).
- Налогот секогаш балансира; нема тивко неурамнотежен налог.
- Не се менува ниеден веќе прокнижен налог.
- Тестови: `php artisan test --filter=…` по задача; целата серија (~15 мин) еднаш пред спојување.
- Никогаш worktree за овој проект.

## Мои пресуди (поправи ако не се согласуваш)

1. **Врска со 743 НЕ се гради во овој дел.** Изводите се само за правни лица, а 743 само за физички — врската нема каде да живее. Одлука на сопственикот (2026-11-01): **правно лице добива и 743**, а подобро е да се направи општ дел „Банкарски обрасци“ (743, 746, заклучници…). Тоа е посебен проект по овој дел; врската „наплата ↔ 743“ се додава на негов крај.
2. На девизен извод се дозволени само две ставки: **наплата на фактура** (уплата) и **продажба на девиза** (исплата). Провизија и друго остануваат за друг пат.
3. Продажба на девиза = **Должи 1039 / Побарува 1030**, двете по курсот на ставката, со девизен износ на двете.
4. Предлогот за разлика на 1039 се покажува по „Зачувај нацрт“ (се пресметува од зачуваното, како листата проблеми) и се книжи само кога сметководителот ќе го штиклира.
5. Предлогот се дава само кога по редот девизното салдо на 1039 (за таа валута) е нула, а денарското не е.
6. Нова колона `currency` на изводот; MT940 за девизни останува надвор.

## Датотеки

- Нова миграција `database/migrations/2026_11_01_100000_add_foreign_fields_to_bank_statements.php`
- `app/Support/Bank/LineKind.php`, `app/Models/BankStatement.php`, `app/Models/BankStatementLine.php`
- `app/Livewire/Bank/BankStatementIndex.php` + `resources/views/livewire/bank/bank-statement-index.blade.php`
- `app/Services/Invoicing/SalesInvoiceService.php`
- `app/Services/Bank/ForeignStatementPoster.php` (ново), `app/Services/Bank/BankStatementPoster.php`
- `app/Support/Bank/StatementControls.php`
- `app/Livewire/Bank/ForeignStatementBook.php` (ново) + `resources/views/livewire/bank/foreign-statement-book.blade.php` (ново), `routes/web.php`
- `app/Services/Bank/Settlement1039.php` (ново); `BankStatementBook.php` + `bank-statement-book.blade.php`
- Тестови во `tests/Feature/Bank/`

---

### Задача 1: Подлога — валута на изводот и девизни полиња на ставката

**Files:** миграцијата горе; `LineKind`, двата модела, `BankStatementIndex` + view; тест `tests/Feature/Bank/ForeignStatementUploadTest.php`.

**Interfaces — Produces:**
- `bank_statements.currency` string(3) nullable.
- `bank_statement_lines`: `currency` string(3) nullable, `exchange_rate` decimal(12,6) nullable, `foreign_amount` decimal(15,2) nullable, `settle_difference` boolean default false.
- `LineKind::CURRENCY_SALE = 'currency_sale'`, етикета „Продажба на девиза“.
- Моделите: ги имаат во `$fillable`; `exchange_rate` cast `decimal:6`, `foreign_amount` `decimal:2`, `settle_difference` `boolean`; `BankStatementLine::$attributes` + `'settle_difference' => false`.
- `BankStatementIndex::$currency` (string, основно `'EUR'`).

- [ ] **Чекор 1: тестови.** `test_a_foreign_statement_is_saved_with_its_currency` (kind foreign, currency USD → `statement->currency === 'USD'`); `test_a_foreign_statement_without_a_valid_currency_is_refused` (currency `GBP` и празно → `assertHasErrors('currency')`, нема запис); `test_a_denar_statement_keeps_no_currency` (kind denar, currency EUR → `currency === null`); `test_the_list_links_a_foreign_statement_to_its_own_screen` (view содржи `route('bank-statements.book-foreign', …)` — се доцрнува до задача 5, па овој тест се пишува таму).
- [ ] **Чекор 2:** пушти ги (црвено). **Чекор 3:** миграција, enum, модели, `saveStatement` (валидација `currency` => `Rule::requiredIf(kind foreign)` + `in:EUR,USD`; запишува `currency` само за девизен), select во формата за качување што се покажува само за девизен вид.
- [ ] **Чекор 4:** зелено + `php artisan test --filter=BankStatement` (постојните не паѓаат). **Чекор 5:** комит.

### Задача 2: Наплата на девизна фактура без свој налог

**Files:** `SalesInvoiceService.php`; тест `tests/Feature/Invoicing/ForeignPaymentRecordTest.php`.

**Interfaces — Produces:** `SalesInvoiceService::createForeignPaymentRecord(SalesInvoice $invoice, string $amount, string $paymentDate, string $rate, int $userId, string $label): array{0: SalesInvoicePayment, 1: list<PostingLine>}` — `assertPayable`, фактурата мора да е девизна, курсот `Bcmath::isPlainNumber` и > 0, плаќањето се создава (`payment_method bank`, `exchange_rate`), ставките доаѓаат од `ForeignReceiptPosting::lines` со `$paidBefore`/`$legacyBefore` пресметани ПРЕД создавањето; **не создава налог**. `recordForeignPayment` се рефакторира да го користи ова (истото однесување).

- [ ] **Чекор 1: тестови.** `test_it_creates_the_payment_and_returns_the_lines_without_a_journal_entry` (бројот на `JournalEntry` не се менува; првата ставка е 1030 Должи по курсот; има 1210/1211 Побарува); `test_a_denar_invoice_is_refused`; `test_a_missing_or_zero_rate_is_refused`; `test_a_partial_second_receipt_closes_the_receivable_exactly` (две ставки 400+600 EUR → збирот на кредитите на 1211 = дебитот на фактурата).
- [ ] **Чекор 2:** црвено. **Чекор 3:** имплементација + рефакторирање на `recordForeignPayment`. **Чекор 4:** зелено + `--filter=ForeignPayment` и `--filter=ForeignFlow` (постојните од дел 1). **Чекор 5:** комит.

### Задача 3: Книжење на девизен извод

**Files:** `ForeignStatementPoster.php` (ново), `BankStatementPoster.php` (разгранува); тест `tests/Feature/Bank/ForeignStatementPosterTest.php`.

**Interfaces — Consumes:** задача 1 (полињата), задача 2.
**Produces:** `ForeignStatementPoster::book(BankStatement $statement, int $userId): JournalEntry` — повикано од `BankStatementPoster::post` по заклучувањето, проверката на проблеми и `isBooked`, во истата транзакција, само кога `$statement->kind->isForeign()`; го создава налогот (група 11, опис „Девизен извод бр. N — банка сметка“), ставка по ставка од изводот, па `status = booked`, `journal_entry_id`. Не вика `BankCounterpartyMemory`.
- Ставка `INVOICE_PAYMENT` (уплата): `createForeignPaymentRecord`, ставките на налогот; на ставката се запишуваат `sales_invoice_payment_id` и `created_payment = true` (за да работи постојното `reopen`).
- Ставка `CURRENCY_SALE` (исплата): `Побарува 1030` и `Должи 1039`, износ `roundHalfUp(amount × rate, 2)`, девизен износ `amount`, валута на изводот, курс; без партнер.
- Недостасува 1030 или 1039 (аналитичко) → `InvalidBankStatementException` со објаснување, транзакцијата се враќа.

- [ ] **Чекор 1: тестови.** `test_a_receipt_at_a_lower_rate_posts_1030_and_a_loss_on_4750` (фактура 1000 EUR@61,50, наплата @61,20 → 1030 Должи 61.200, 4750 Должи 300, 1211 Побарува 61.500, налогот балансира, група 11); `test_a_receipt_at_a_higher_rate_posts_a_gain_on_7750`; `test_a_conversion_posts_1039_debit_and_1030_credit_at_the_same_rate` (1000 EUR@61,50 → 61.500 на двете страни, девизните колони пополнети, без 4750/7750); `test_a_receipt_creates_the_payment_and_reduces_the_invoice_balance`; `test_one_statement_with_receipt_and_conversion_is_one_balanced_entry`; `test_reopen_removes_the_entry_and_the_payment`; `test_posting_without_1039_is_refused_and_leaves_nothing` (нема налог, нема плаќање).
- [ ] **Чекор 2–5:** црвено → код → зелено (`--filter=ForeignStatementPoster` + `--filter=BankStatementPoster`) → комит.

### Задача 4: Проверки пред книжење (девизен и 1039)

**Files:** `StatementControls.php`; тест `tests/Feature/Bank/ForeignStatementControlsTest.php`.

**Interfaces — Produces:** во `lineProblems`, за девизен извод: допуштени се само `INVOICE_PAYMENT`+`IN` и `CURRENCY_SALE`+`OUT`, во друго → „на девизен извод се дозволени само наплата на фактура и продажба на девиза.“; `exchange_rate` мора да е > 0 („внесете курс.“); за наплата, фактурата мора да е девизна и да ја има валутата на изводот („фактурата е во друга валута.“) — останатото (потврдена, фирма, салдо, споделена фактура) го прават постојните проверки. За денарски извод: ставка на конто со код `1039` и `foreign_amount` бара `foreign_amount > 0` и валута `EUR|USD` („девизниот износ треба валута.“).

- [ ] **Чекор 1: тестови.** По еден за секоја нова порака + `test_a_valid_foreign_statement_has_no_problems` (почетна+движење=крајна во валутата, наплата и продажба) + `test_a_denar_statement_is_unchanged`.
- [ ] **Чекор 2–5:** црвено → код → зелено (`--filter=StatementControls` + новиот) → комит.

### Задача 5: Екран за девизен извод

**Files:** `ForeignStatementBook.php`, `foreign-statement-book.blade.php`, `routes/web.php` (`bank-statements.book-foreign`, `/izvodi/{statement}/devizno`, истите посредници), `bank-statement-index.blade.php` (за девизен: „Книжи“ / „Прокнижен · Отвори“ води на новата рута; се трга „—“); тест `tests/Feature/Bank/ForeignStatementBookTest.php`.

**Interfaces — Produces:** компонента со `company`, `statement`, `openingBalance`, `closingBalance`, `lines[]` (`_key, id, line_date, kind, amount, exchange_rate, partner_id, invoice_id, description`); методи `addLine()`, `removeLine(int)`, `fetchRate(int)` (НБРМ за `statement->currency` на датумот на ставката, во грешка порака „Курсот не се презеде од НБРМ — впиши го рачно.“), `invoiceOptions(int): array` (потврдени девизни фактури на партнерот во валутата на изводот со салдо > 0), `save()`, `post(BankStatementPoster)`, `reopen(BankStatementPoster)`, пресметани `difference()` и `totals()` во валутата. `kind` одредува насока (наплата = уплата, продажба = исплата). Изборот на фактура го нуди салдото како износ кога износот е празен. Покажува денарска вредност (`износ × курс`). Правата и 404 како кај `BankStatementBook` (`Gate view company`, `create JournalEntry`, друга фирма/денарски извод → 404); `BankStatementBook` останува 404 за девизен.
- `persist()` ја повторува заштитата од изменет прозорец (непознат `id` → грешка „Изводот е изменет во друг прозорец…“) и ги брише/создава ставките одново.

- [ ] **Чекор 1: тестови.** `test_a_foreign_statement_opens_and_a_denar_one_is_404`; `test_another_companys_statement_is_404`; `test_a_receipt_and_a_conversion_can_be_saved_with_their_rates`; `test_invoice_options_list_only_open_foreign_invoices_in_the_statements_currency` (EUR фактура се нуди на EUR извод; USD, денарска и платена не); `test_choosing_an_invoice_fills_the_open_balance`; `test_post_creates_the_entry_and_the_screen_shows_it_booked`; `test_post_with_problems_shows_them_and_books_nothing`; `test_fetching_the_rate_fills_it_in` (ExchangeRateService се замени со фејк во контејнерот) и `…_reports_a_failure`; `test_the_difference_strip_follows_the_currency_balances`; `test_the_list_links_a_foreign_statement_to_its_own_screen`.
- [ ] **Чекор 2–5:** црвено → код → зелено → отвори го екранот во прелистувач со статичко/локално сервирање ако е можно, инаку само `Livewire::test` (запиши во книгата што е проверено како) → комит.

### Задача 6: Денарски извод — девизен износ на 1039 и предлог за разлика

**Files:** `Settlement1039.php` (ново), `BankStatementPoster.php`, `BankStatementBook.php`, `bank-statement-book.blade.php`; тест `tests/Feature/Bank/Settlement1039Test.php` и `…/Bank/BankStatementSettlementPostingTest.php`.

**Interfaces — Produces:**
- `Settlement1039::proposals(BankStatement $statement): array<int, array{side: 'loss'|'gain', amount: string}>` (клуч = `id` на ставката). Салдото на 1039 е од **прокнижените** налози на фирмата (денари = Должи − Побарува; девизно по валута: `foreign_amount` со знак според страната). По ред на ставките на изводот: за ставка на 1039 со `direction IN`, `foreign_amount > 0`, валута: од салдата се одзема денарскиот и девизниот износ; ако девизното на таа валута е 0, а денарското не е, предлогот е тоа денарско (> 0 → `loss`, < 0 → `gain` со апсолутна вредност); потоа денарското се смета за затворено (0).
- Книжењето (денарски извод): ставката на 1039 со `foreign_amount` пишува `currency_code`, `foreign_amount`, `exchange_rate = roundHalfUp(amount ÷ foreign_amount, 6)`. Ако `settle_difference` е вклучено **и** постои предлог за таа ставка: `loss` → `Должи 4750 / Побарува 1039`; `gain` → `Должи 1039 / Побарува 7750`; во истиот налог, без допир до 1000. Недостасува 4750/7750 → `InvalidBankStatementException`.
- Екранот: под изборот на конто, кога контото е 1039, се појавуваат „Девизен износ“ и валута (EUR/USD); ако има предлог, рамка „Предлог: курсна разлика X ден на 4750/7750“ со штикла „Книжи ја разликата“ (врзана за `settle_difference`); ако не е зачувано, порака „Зачувајте за да се пресмета предлогот“. Полињата влегуваат во `lines[]`, `mount`, `addLine`, `persist` (чува `currency`, `foreign_amount`, `settle_difference` само за ставка на 1039, инаку null/false).

- [ ] **Чекор 1: тестови (сервис).** `test_a_loss_is_proposed_when_the_bank_pays_fewer_denars_than_1039_holds` (1039: Должи 424.368,63 / 6.900 EUR; ставка IN 424.338,96 со 6.900 EUR → `loss` 29,67); `test_a_gain_is_proposed_when_the_bank_pays_more`; `test_no_proposal_when_the_amounts_match`; `test_no_proposal_while_foreign_balance_remains` (ставка за 3.000 од 6.900 EUR); `test_unposted_entries_do_not_count`; `test_other_company_balances_do_not_count`.
- [ ] **Чекор 2: тестови (книжење).** `test_the_1039_line_carries_the_foreign_data`; `test_accepting_the_proposal_posts_4750_against_1039_and_the_entry_balances`; `test_not_accepting_leaves_the_difference_on_1039`; `test_the_gain_posts_7750`; `test_accepting_with_nothing_to_settle_posts_nothing_extra`; `test_the_bank_total_on_1000_is_unchanged_by_the_settlement`.
- [ ] **Чекор 3: тестови (екран).** `test_the_foreign_fields_show_only_for_account_1039`; `test_saving_stores_the_foreign_amount_currency_and_flag`; `test_the_proposal_shows_after_saving`.
- [ ] **Чекор 4–6:** црвено → код → зелено (`--filter=BankStatement` + новите) → комит.

### Задача 7: Цел тек од крај до крај

**Files:** тест `tests/Feature/Bank/ForeignStatementEndToEndTest.php`.

- [ ] **Чекор 1:** `test_the_whole_flow_closes_receivable_1030_and_1039`: правно лице, девизна фактура 6.900 EUR на услуга @61,50 (потврдена), девизен извод (EUR) со наплата 6.900 @61,40 и продажба 6.900 @61,5027, прокнижен; денарски извод со ставка IN на 1039 (424.338,96; девизно 6.900 EUR) со штиклирана разлика, прокнижен. Тврдења: салдото на 1211 = 0; девизното салдо на 1030 = 0; салдото на 1039 (денари и девизно) = 0; 4750 = курсна разлика од наплатата + разликата од 1039; секој од трите налози балансира; изводот на 1000 е само 424.338,96; `invoice->balanceDue() === '0.00'`.
- [ ] **Чекор 2:** пушти (мора да помине; ако падне, дебагирај со systematic-debugging, не го менувај тврдењето). **Чекор 3:** целата серија (`php artisan test`, надвор од долгите чекања, во позадина) — мора зелено. **Чекор 4:** комит.

### Задача 8: Документација и меморија

- [ ] Спецификацијата: статус на дел 2, пресудата за 743; `memory/tami_foreign_currency_posting.md` и редот во `MEMORY.md`. Комит.

## Review Focus (за завршната ревизија)

1. Ставка на девизен извод со фактура од друга валута/фирма или веќе платена (двојно книжење).
2. Две наплати на иста фактура во еден извод — збирот не смее да ја надмине; побарувањето точно на нула.
3. Отворање на прокнижен девизен извод (`reopen`) не оставува плаќање ниту налог.
4. Предлогот за 1039 при делумна продажба или при повеќе конверзии на 1039 — не смее да предложи „разлика“ што е всушност друга конверзија.
5. Денарски извод без девизни полиња на 1039 се однесува точно како порано.
