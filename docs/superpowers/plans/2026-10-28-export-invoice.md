# Извозна фактура (дел 4) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Штикла „Извозна фактура" на формата за излезна фактура: девиза и курс, сите ставки без ДДВ, и фактурата се презема како PDF на македонски и на англиски.

**Architecture:** Една нова колона `sales_invoices.is_export`. Формата ја наметнува (девиза ≠ MKD, ставки „Извоз" со 0%) и во Blade и на серверот во `persist()`. PDF-контролерот добива параметар `lang`. Книжењето, наплатата и забраната кон е-Фактура не се менуваат: извозната фактура е секогаш девизна, па дел 1 и постојното правило по валута ја покриваат.

**Tech Stack:** Laravel, Livewire 3, dompdf, PHPUnit (`RefreshDatabase`).

**Spec:** `docs/superpowers/specs/2026-10-28-export-invoice-design.md`

## Global Constraints

- Извозот на стоки и услуги оди **без ДДВ**: ставки со `vat_treatment = 'export'` и `vat_rate = '0.00'`, секогаш, и за ДДВ-обврзник.
- Извозна фактура е во девиза од `SalesInvoice::CURRENCIES` **без `MKD`**.
- ДДВ-04 **не се менува** (`Ddv04Query`: „Извоз" останува на поле 07).
- Извозна фактура не оди кон е-Фактура (постојното правило по валута).
- Јазикот на PDF се бира по копче (`lang=mk|en`), не се врзува за кооперантот; други вредности се одбиваат.
- Серверот го наметнува правилото, не само Blade (скриено поле не е заклучување).
- Постојните фактури се непроменети: `is_export` е стандардно `false`; чиста `ADD COLUMN`.
- Физичко лице без штикла: постојниот тек (валута, јазик од кооперантот) останува непроменет.
- Текстови за корисникот на македонски. Без `EnterWorktree`: работи во главниот checkout.

## Review Focus

1. Изменет запрос: правно лице со `isExport = false` и EUR → паѓа на MKD (постојното правило); со `isExport = true` и MKD → одбиено; ставка „Стандарден 18%" со штикла → зачувана како „Извоз" 0%.
2. ДДВ-обврзник со штикла: нула ДДВ на износите, во PDF-от и на книжењето, и кога ставката е внесена со стапка 18.
3. Одштиклување на нацрт во EUR: назад на MKD и курс 1, ставките на „Стандарден" — не остава EUR со ДДВ стапки или MKD со „Извоз".
4. PDF: `lang=xx` одбиено, непотврдена фактура останува 403, фактура од друга фирма 404, и без `lang` работи како досега.
5. Регресија: физичко лице без штикла ја задржува девизата и јазикот од кооперантот; правно лице без штикла остава MKD.

## File Structure

| Фајл | Улога |
|---|---|
| `database/migrations/2026_10_28_130000_add_is_export_to_sales_invoices_table.php` (нов) | колоната |
| `app/Models/SalesInvoice.php` | `is_export` во `$fillable`, `$attributes`, `casts` |
| `app/Livewire/Invoicing/SalesInvoiceForm.php` | својство `isExport`, правила во `persist()`, реакција на штиклата |
| `resources/views/livewire/invoicing/sales-invoice-form.blade.php` | штиклата, валутата, третманот на ставките |
| `app/Http/Controllers/SalesInvoicePdfController.php` | `lang` |
| `resources/views/livewire/invoicing/sales-invoice-show.blade.php` | две копчиња за PDF (само за извозна) |
| `tests/Feature/ExportInvoiceTest.php` (нов) | форма, сервер, PDF, книжење |

---

### Task 1: Колона `is_export`

**Files:**
- Create: `database/migrations/2026_10_28_130000_add_is_export_to_sales_invoices_table.php`
- Modify: `app/Models/SalesInvoice.php` (`$fillable`, `$attributes`, `casts()`)
- Test: `tests/Feature/ExportInvoiceTest.php`

**Interfaces:**
- Produces: `SalesInvoice::$is_export` (bool, по подразбирање `false`, и во свеж модел во меморија), масовно доделиво.

- [ ] **Step 1: Failing test** `test_a_fresh_invoice_is_not_an_export_and_the_flag_can_be_stored` — `new SalesInvoice` има `is_export === false`; фактура создадена со `'is_export' => true` се чита назад како `true`; постојна фабричка фактура е `false`.
- [ ] **Step 2:** Run `php artisan test --filter=ExportInvoiceTest` → FAIL.
- [ ] **Step 3:** Миграција (`boolean`, `default(false)`, без индекс); модел: полето во `$fillable`, `'is_export' => false` во `$attributes` (DB-default не го полни свеж модел), cast `boolean`.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5: Commit** `feat(invoicing): is_export flag on sales invoices`

### Task 2: Формата — штикла, девиза, ставки без ДДВ

**Files:**
- Modify: `app/Livewire/Invoicing/SalesInvoiceForm.php`
- Modify: `resources/views/livewire/invoicing/sales-invoice-form.blade.php`
- Test: `tests/Feature/ExportInvoiceTest.php`

**Interfaces:**
- Consumes: Task 1.
- Produces: јавно својство `bool $isExport = false` на `SalesInvoiceForm`; метод `updatedIsExport(bool $value): void`; зачувана фактура со `is_export`, `currency`, `exchange_rate`, ставки на „Извоз" со 0.

Правила за `persist()`:
- Постојното „не-физичко лице → MKD" важи само кога `!$this->isExport`.
- Со `isExport`: `currency` мора да е од `SalesInvoice::CURRENCIES` и `!== 'MKD'` (грешка на полето `currency`, македонска порака); курсот е задолжителен како досега.
- Со `isExport`: секоја ставка се наметнува на `vat_treatment = 'export'`, `vat_rate = '0.00'` (пред валидацијата, ист обрасец како постојното нулирање на стапката за не-стандарден третман, со `refreshPrices($index)`).
- `is_export` се запишува на фактурата; при уредување на нацрт се вчитува во `mount()`.

`updatedIsExport()`: при вклучување, ако валутата е MKD → `EUR` (преку постојниот `updatedCurrency` за да го понуди последниот курс) и сите ставки на „Извоз"/0; при исклучување → валута `MKD`, курс `1`, ставките на „Стандарден" со стандардната стапка на фирмата (`'18.00'` за ДДВ-обврзник, инаку `'0.00'`).

Blade: штиклата („Извозна фактура") се прикажува за секој тип фирма; блокот за валута и курс се прикажува кога `$company->type->isIndividual() || $isExport`; кога `$isExport`, изборот на валута не нуди MKD, а изборот на третман на ставка е скриен/заклучен.

- [ ] **Step 1: Failing tests** (`ExportInvoiceTest`, по обрасците од `ForeignCurrencyInvoiceTest`: правно лице = `Company::factory()` со `type='legal'`, админ со улога)
  - `test_a_legal_entity_can_save_an_export_invoice_in_euros_without_vat` — `isExport=true`, EUR, курс 61.5, една ставка 500 со стапка 18 и третман „стандарден" → зачувана `is_export`, `currency='EUR'`, ставка со `vat_treatment='export'`, `vat_rate='0.00'`, `grandTotal()==='500.00'` (ДДВ-обврзник).
  - `test_an_export_invoice_in_denars_is_refused` — `isExport=true`, `currency='MKD'` → `assertHasErrors('currency')`.
  - `test_without_the_tick_a_legal_entity_is_still_forced_to_denars` — `isExport=false`, EUR преку wire → `currency==='MKD'`, `exchange_rate` 1.
  - `test_the_export_checkbox_and_currency_are_shown_to_a_legal_entity` — `assertSee('Извозна фактура')`; валутата е видлива само кога штиклата е вклучена.
  - `test_ticking_the_box_switches_to_euros_and_export_lines`; `test_unticking_the_box_returns_to_denars_and_standard_lines` (со ДДВ-обврзник: ставката назад на 18.00/standard).
  - `test_an_individual_without_the_tick_keeps_the_existing_foreign_flow` — физичко лице, EUR без штикла, јазик од кооперантот.
- [ ] **Step 2:** Run `php artisan test --filter=ExportInvoiceTest` → FAIL.
- [ ] **Step 3:** Имплементација според правилата погоре.
- [ ] **Step 4:** Run `php artisan test --filter="ExportInvoiceTest|SalesInvoiceForm|ForeignCurrencyInvoiceTest"` → PASS (без пад на постојните).
- [ ] **Step 5: Commit** `feat(invoicing): export invoice checkbox on the sales invoice form`

### Task 3: PDF на македонски и на англиски

**Files:**
- Modify: `app/Http/Controllers/SalesInvoicePdfController.php`
- Modify: `resources/views/livewire/invoicing/sales-invoice-show.blade.php` (редот со копчето „Преземи PDF", околу ред 164; **не** `@script` блоковите)
- Test: `tests/Feature/ExportInvoiceTest.php`

**Interfaces:**
- Consumes: Task 1.
- Produces: рутата `sales-invoices.pdf` прима опционален `lang` (`mk` или `en`). Без `lang` — однесување како досега (јазикот на фактурата). Со `lang` на извозна фактура — тој јазик во PDF-от, без да се зачува. Датотеката: `invoice-{slug}-{lang}.pdf` кога има `lang`. `lang` кај фактура што не е извозна се игнорира.

- [ ] **Step 1: Failing tests**
  - `test_an_export_invoice_pdf_is_available_in_both_languages` — потврдена извозна фактура: `?lang=en` враќа 200 и PDF што содржи „Invoice"-стил текст, `?lang=mk` македонски (проверка на излезот од `getContent()` како постојните PDF тестови); `Content-Disposition` носи `-en`/`-mk`.
  - `test_an_unknown_language_is_refused` — `?lang=xx` → 404 (или 422; едното, фиксирано во тестот).
  - `test_the_language_parameter_is_ignored_on_a_normal_invoice` — домашна фактура со `?lang=en` останува на својот јазик.
  - `test_an_unconfirmed_invoice_is_still_forbidden_and_another_company_still_404`.
  - `test_the_show_page_offers_two_pdf_buttons_only_for_an_export_invoice` — извозна: двете врски; обична: еден „Преземи PDF".
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Во контролерот прочитај `lang` (`in:mk,en`), поставен на моделот во меморија (`InvoiceLanguage::from($lang)`) пред `loadView`, само за `is_export`. Во погледот, за `$invoice->is_export` прикажи две врски („PDF — македонски", „PDF — English") наместо една.
- [ ] **Step 4:** Run `php artisan test --filter="ExportInvoiceTest|SalesInvoicePdf|SalesInvoiceEnglishPdf|SalesInvoiceShow"` → PASS.
- [ ] **Step 5: Commit** `feat(invoicing): export invoice PDF in Macedonian and English`

### Task 4: Книжење, е-Фактура и ДДВ-04 за извозна фактура

**Files:**
- Test: `tests/Feature/ExportInvoiceTest.php` (без измена на производствен код, освен ако тестот открие дефект — тогаш поправка со свој RED).

**Interfaces:**
- Consumes: Task 2 (зачувана извозна фактура), дел 1 (1210/1211, 1030).

- [ ] **Step 1: Tests** (проверка дека составот работи од крај до крај)
  - `test_an_export_invoice_of_a_legal_entity_posts_to_1211_and_7423_with_no_vat` — потврдена извозна услуга на ДДВ-обврзник: `1211 D`, `7423 C`, нема ставка на 2300/2301.
  - `test_an_export_invoice_with_goods_and_services_posts_to_1210_1211_7421_7423`.
  - `test_an_export_invoice_cannot_be_sent_to_ujp` — 422 од `authorizeSigning()`, како кај девизна фактура.
  - `test_both_goods_and_service_export_lines_land_in_ddv04_field_07` — `Ddv04Query` за периодот: поле 07 е збирот на двете, останатите ДДВ полиња 0.
  - `test_a_payment_of_an_export_invoice_posts_to_1030` — `recordPayment` со курс (дел 1) затвора 1211 на нула.
- [ ] **Step 2:** Run `php artisan test --filter=ExportInvoiceTest`. Очекувано: PASS (сè е веќе имплементирано). Ако нешто падне, тоа е дефект: напиши минимална поправка со тест што прво падна.
- [ ] **Step 3: Commit** `test: export invoice end to end`

### Task 5: Завршна проверка

- [ ] **Step 1:** Run the full suite once: `php artisan test` → сè зелено, без пад на постојните.
- [ ] **Step 2:** Прегледај дека `@script` блоковите во `sales-invoice-show.blade.php` се бајт-идентични (diff на гранката не ги допира).

---

## Self-Review

- **Покриеност на спецификацијата:** штикла и девиза/курс (Task 2); без ДДВ и наметнување на серверот (Task 2); без MKD (Task 2); два PDF (Task 3); без е-Фактура, книжење, ДДВ-04 непроменето (Task 4); `is_export` (Task 1); физичко лице непроменето (Task 2, регресија).
- **Конзистентност на имињата:** `isExport` / `updatedIsExport` / `is_export` / `lang` — исти во сите задачи.
- **Пропорција:** планот не ги пишува телата на методите; ги дава правилата и тестовите.
- **Отворено за градителот:** точниот код за `lang=xx` (404 или 422) — фиксирај во тестот; штиклата е дозволена и за физичко лице (мој избор, за еднообразно однесување).
