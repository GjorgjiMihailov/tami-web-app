# Масовен внес на излезни фактури — план за изведба

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (или subagent-driven-development) за изведба задача по задача. Чекорите користат `- [ ]`.

**Goal:** Од Excel шаблон (број, датум, кооперант, ЕДБ, вкупно) да се создаваат нацрт-фактури без ставки, со број од хартија, заклучени за е-Фактура; нивните ставки ги додава корисникот рачно.

**Architecture:** Чист парсер-сервис (`SalesInvoiceImportParser`) претвора редови од Excel во проверени редови; Livewire екран по угледот на `ItemBulkImport` ги покажува, дозволува избор на кооперант за нејасните и во една транзакција создава нацрти. Постојното „број од хартија" однесување на `SalesInvoiceService::confirm()` го носи бројот низ потврдата. Две мали проширувања: знаме `is_imported`/`imported_total` на фактурата и поле „Следен број" на фирмата.

**Tech Stack:** Laravel 13, Livewire 3, maatwebsite/excel (`Excel::toArray`, `FromArray`), PHPUnit/Livewire testing, PhpSpreadsheet за тест-фајлови.

**Spec:** `docs/superpowers/specs/2026-10-16-bulk-invoice-import-design.md`

## Global Constraints

- Сите текстови за корисникот на македонски (строго македонски, не бугарски).
- Не се користи worktree — работа во главната папка (`feedback_avoid_worktree_this_project`).
- Нова миграција со рачно именувани индекси ако има `unique()` на 3+ колони (MySQL лимит 64 знаци). Овој план додава само обични колони.
- Секој износ од корисник поминува низ проверка пред bcmath (`App\Support\Bcmath::isPlainNumber()`); `bcadd` сече, `decimal(15,2)` заокружува — не мешај.
- Една тест-датотека додека се работи; целата серија (`php artisan test`, директно, не `composer test`) еднаш на крај.
- Максимум 500 реда по прикачување; формат .xlsx (прима и .csv како `ItemBulkImport`).
- Увезена фактура: `company_id`, `partner_id`, `invoice_date`, `due_date`, `status='draft'`, `invoice_number_formatted=<број од хартија>`, `fiscal_year=null`, `invoice_number=null`, `is_imported=true`, `created_by=auth id`, валута MKD.

## Review Focus

Влезови што спецификацијата ги подразбира, а задачите ги пинуваат со тест:
1. Датум во Excel ќелија што е вистински датум (серија од број), не текст `ДД.ММ.ГГГГ` — мора да се прочита (Task 3).
2. Број на фактура што Excel го претворил во број (пр. `00001` → `1`): колоната во шаблонот е формат „текст" (Task 4); парсерот го зема како што стигнува и не го „поправа" (Task 3).
3. Празни места пред/по име и ЕДБ; големи/мали букви во име (Task 3).
4. Ист број во различна година (`00001-26` и `00001-27`) е дозволен; ист број во иста година — не (Task 3).
5. Двоен клик на „Потврди" не смее да создаде дупликати (Task 5: парсираните редови се празнат по успех; уникатниот индекс е мрежа).

---

### Task 1: Колони и модел

**Files:**
- Create: `database/migrations/2026_10_16_100000_add_import_flags_and_next_number.php`
- Modify: `app/Models/SalesInvoice.php` (fillable, casts, нови методи), `app/Models/Company.php` (fillable)
- Test: `tests/Feature/Invoicing/SalesInvoiceImportFlagsTest.php`

**Interfaces:**
- Produces: `sales_invoices.is_imported` (bool, default false), `sales_invoices.imported_total` (decimal 15,2, nullable), `companies.invoice_number_next` (unsignedInteger, nullable); `SalesInvoice::isImported(): bool`; `SalesInvoice::importedTotalMismatch(): ?string` — `null` ако нема `imported_total` или `grandTotal()` е еднаков, инаку текст на македонски со двата износа.

- [ ] **Step 1: Тест** `test_importedTotalMismatch_is_null_without_a_paper_total`, `…_is_null_when_totals_match` (ставка 1000 + 18% ДДВ ⇒ `imported_total` `1180.00`), `…_returns_a_message_naming_both_amounts_when_they_differ`, `test_a_new_invoice_defaults_to_not_imported` (свеж `new SalesInvoice` има `is_imported === false` — `$attributes` мора да се постави изречно, DB default не полни меморија).
- [ ] **Step 2:** Пушти ја датотеката — паѓа.
- [ ] **Step 3:** Миграција + модел. `grandTotal()` е од `HasInvoiceTotals`; споредба со `bccomp(...,2)` откако двете се нормализирани на 2 децимали.
- [ ] **Step 4:** Пушти — поминува.
- [ ] **Step 5:** Commit `Фактури: знаме за увезена фактура и следен број на фирма (колони + модел)`.

### Task 2: „Следен број" — поле и подлога на бројачот

**Files:**
- Modify: `app/Livewire/Invoicing/InvoiceSettings.php` (својство `nextNumber` како `?int`/string, mount, валидација `nullable|integer|min:1|max:999999`, update), `resources/views/livewire/invoicing/invoice-settings.blade.php`, `app/Services/Invoicing/SalesInvoiceService.php` (линија со `$invoiceNumber = ($maxNumber ?? 0) + 1;`)
- Test: `tests/Feature/Invoicing/InvoiceNextNumberTest.php`

**Interfaces:**
- Consumes: `companies.invoice_number_next` од Task 1.
- Produces: правило — при потврда без број од хартија, `$invoiceNumber = max($maxNumber ?? 0, ($company->invoice_number_next ?? 1) - 1) + 1`.

- [ ] **Step 1: Тестови.** `test_confirm_starts_the_series_at_the_configured_next_number` (`invoice_number_next=201`, нема фактури ⇒ `invoice_number === 201`, формат од `InvoiceNumber::format`); `test_next_number_is_only_a_floor` (постои фактура 250, next=201 ⇒ добива 251); `test_without_a_next_number_the_series_is_unchanged`; `test_settings_screen_saves_the_next_number_and_rejects_zero` (`set('nextNumber', 0)` ⇒ грешка на `nextNumber`, празно ⇒ `null` во базата).
- [ ] **Step 2:** Паѓаат.
- [ ] **Step 3:** Имплементирај (поле под „Должина на бројот"; помош-текст: „За фирма што ја презема од друга програма — бројот од кој продолжуваат новите фактури").
- [ ] **Step 4:** Поминуваат; потоа и постојните `InvoiceSettingsTest` + `SalesInvoicePaperNumberTest` (не смее ништо да се скрши).
- [ ] **Step 5:** Commit `Поставки → Фактурирање: Следен број за фирма што доаѓа од друга програма`.

### Task 3: Парсер на редови

**Files:**
- Create: `app/Services/Invoicing/SalesInvoiceImportParser.php`
- Test: `tests/Feature/Invoicing/SalesInvoiceImportParserTest.php`

**Interfaces:**
- Produces: `public function parse(array $rows, int $companyId): array` — `$rows` е `Excel::toArray` листот (прв ред може да е заглавие: се прескокнува ако првата ќелија е `Број на фактура`; сосема празни редови се прескокнуваат; повеќе од 500 непразни редови ⇒ фрла `\InvalidArgumentException('Најмногу 500 редови по прикачување.')`). Секој резултат: `['row_number'=>int (ред во Excel), 'number'=>string, 'date'=>?string (Y-m-d), 'partner_name'=>string, 'tax_id'=>string, 'total'=>?string ('1180.00'), 'partner_id'=>?int, 'status'=>'ready'|'unmatched'|'error', 'errors'=>string[]]`.
- Колони по позиција: 0 број, 1 датум, 2 кооперант, 3 ЕДБ, 4 вкупно.

Правила:
- Број: `trim`; празен ⇒ error „Недостасува број на фактура".
- Датум: прима текст `ДД.ММ.ГГГГ`, `ДД/ММ/ГГГГ`, `ГГГГ-ММ-ДД` и Excel-серија (`PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject` за float/int); сè друго (вкл. две цифри за година, невозможен датум) ⇒ error „Неважечки датум".
- Вкупно: празно ⇒ `null`; запирка→точка, без празни места; мора да е `^\d+(\.\d{1,2})?$` (преку `Bcmath::isPlainNumber`), инаку error „Неважечки износ".
- Дупликат: ист `number` + иста година на датумот веќе постои како `invoice_number_formatted` кај `company_id` ⇒ error „Бројот веќе постои во {година}"; ист пар (број, година) претходно во листот ⇒ error „Бројот се повторува во прикачувањето (ред N)".
- Кооперант: прво по `tax_id` (непразен, точно, во `partners` на фирмата); потоа по име (`mb_strtolower`, колабирани празни места) — само ако точно еден кооперант се совпаѓа; нула или повеќе ⇒ `status='unmatched'`, `partner_id=null`. Празно име ⇒ error „Недостасува кооперант".
- `status='error'` ако `errors` не е празно; инаку `ready` (ако е препознаен) или `unmatched`.

- [ ] **Step 1: Тестови** (по еден, со вистински `Partner` од фабрика): препознавање по ЕДБ со вишок празни места; по име без разлика на големи/мали букви; две фирми со исто име ⇒ `unmatched`; датум како Excel-серија `46026` ⇒ `2026-01-04`... (пресметај вистинската серија во тестот со `Date::dateTimeToExcel`); `04.01.2026`, `2026-01-04` ок; `4.1.26` ⇒ error; износ `2360,00` ⇒ `2360.00`, `2.360,00` ⇒ error; дупликат во базата; дупликат во листот; ист број во 2026 и 2027 ⇒ двата ок; заглавие и празен ред се прескокнуваат и `row_number` останува вистинскиот Excel ред; 501 ред ⇒ исклучок.
- [ ] **Step 2:** Паѓаат.
- [ ] **Step 3:** Имплементирај; една SQL-прашалка за кооперантите на фирмата и една за постојните броеви на годините во листот (не по ред).
- [ ] **Step 4:** Поминуваат.
- [ ] **Step 5:** Commit `Масовен внес на фактури: парсер на редови (препознавање, дупликати, датуми, износи)`.

### Task 4: Шаблон за преземање

**Files:**
- Create: `app/Exports/SalesInvoiceImportTemplateExport.php`, `app/Http/Controllers/SalesInvoiceImportTemplateController.php`
- Modify: `routes/web.php` (во групата `sales-invoices.`, пред `/sales-invoices/{salesInvoice}`): `GET /sales-invoices/bulk-import/template` ⇒ име `sales-invoices.bulk-import.template`
- Test: `tests/Feature/Invoicing/SalesInvoiceImportTemplateTest.php`

**Interfaces:**
- Produces: .xlsx `fakturi-obrazec.xlsx` со заглавие `Број на фактура | Датум на издавање | Кооперант | ЕДБ на кооперант | Вкупно од хартија (незадолжително)` и еден примерен ред (`00001-26 | 04.01.2026 | Пример ДООЕЛ | 4043000000000 | 2360`); колона A и D форматирани како **текст** (`WithColumnFormatting` + `NumberFormat::FORMAT_TEXT`).
- Маршрутата мора да стои пред `{salesInvoice}` — инаку „bulk-import" се чита како ID.

- [ ] **Step 1: Тест:** одговор 200 со xlsx content-type; прочитан назад (`Excel::toArray`) заглавието е точно; клиент без право добива 403 (ист образец како `ItemBulkImportTest`).
- [ ] **Step 2–4:** Паѓа → имплементирај → поминува.
- [ ] **Step 5:** Commit `Масовен внес на фактури: шаблон за преземање`.

### Task 5: Екран за внес и преглед

**Files:**
- Create: `app/Livewire/Invoicing/SalesInvoiceBulkImport.php`, `resources/views/livewire/invoicing/sales-invoice-bulk-import.blade.php`, `app/Imports/SalesInvoicesImport.php` (празна класа како `ItemsImport`)
- Modify: `routes/web.php` (`GET /sales-invoices/bulk-import` ⇒ `sales-invoices.bulk-import`), `resources/views/livewire/invoicing/sales-invoice-index.blade.php` (копче „Масовен внес" до „+ Нова фактура", само за `admin`/`accountant`)
- Test: `tests/Feature/Invoicing/SalesInvoiceBulkImportTest.php`

**Interfaces:**
- Consumes: `SalesInvoiceImportParser::parse`, `SalesInvoice::is_imported/imported_total`.
- Produces: Livewire компонента со `public $importFile`, `public array $parsedRows`, `public array $partnerChoices` (клуч = нормализирано име, вредност = `partner_id` како цел број или `'new'`), `preview()`, `choosePartner(string $nameKey, string $choice)`, `confirmImport()`, `public ?string $summary`.

Однесување:
- `mount`: `Gate::authorize('view', $company)` + `abort_unless(auth()->user()->hasAnyRole(['admin','accountant']), 403)`.
- `preview()`: валидација `required|file|max:5120`; нечитлив фајл ⇒ грешка како кај артикли; исклучок на парсерот (над 500) ⇒ грешка на `importFile`.
- Прегледот групира `unmatched` по нормализирано име (една редица избор за сите фактури со тоа име) со паѓачко мени од кооперантите на фирмата + опција „Создај нов кооперант „{име}„". Сумирање горе: „N јасни, M чекаат избор, K со грешка (не се увезуваат)".
- „Потврди го увозот" е недостапно додека има `unmatched` без избор или нема ниту еден `ready`.
- `confirmImport()`: една `DB::transaction`: за секое име со избор `'new'` создај `Partner` (`company_id`, `name`, `type='legal_entity'`, `tax_id` од првиот ред со ЕДБ или `null`) — `Gate::authorize('create', Partner::class)`; потоа по ред: `SalesInvoice::create([...Global Constraints...])`, `due_date` = датум + `payment_terms_days` на кооперантот, а без рок = датумот на фактурата (исто правило како `SalesInvoiceForm` околу ред 379). Редови со `error` се прескокнуваат. По успех: `parsedRows=[]`, `partnerChoices=[]`, `summary="N нацрт-фактури се увезени. Отворете ја секоја и додајте ставки."` со врска кон листата. Грешка во транзакција ⇒ `addError('confirm', …)`, ништо зачувано.

- [ ] **Step 1: Тестови.** Страницата се отвора (HTTP 200) за admin и accountant, 403 за `internal_client`; копчето не се рендерира за клиент; прикачен вистински xlsx (направен со PhpSpreadsheet како во `ItemBulkImportTest`) покажува ред по ред; `confirmImport` со само јасни редови создава нацрти со `is_imported`, `invoice_number_formatted`, без ставки, `fiscal_year` `null`; нејасно име без избор ⇒ копчето/акцијата одбива и ништо не се создава; `choosePartner('…','new')` создава еден кооперант за три фактури со исто име; избор на постоен ⇒ сите три фактури кон него; `imported_total` се чува од „Вкупно"; редовите со грешка не се создаваат, а другите да; двоен `confirmImport()` не создава втор сет; доспевање = датум + рок на кооперантот.
- [ ] **Step 2:** Паѓаат.
- [ ] **Step 3:** Имплементирај. Табела со густина `py-1 px-3` (мора да поминува `TableDensityTest`, редот користи `hover:bg-orange-50`). Не именувај Livewire акции `upload/get/set/call` (резервирани — `feedback_livewire_reserved_action_names`).
- [ ] **Step 4:** Поминуваат; пушти и `TableDensityTest` + `ItemBulkImportTest`.
- [ ] **Step 5:** Commit `Масовен внес на излезни фактури: екран, преглед, избор на кооперант, создавање нацрти`.

### Task 6: Заклучување за е-Фактура и предупредување за износ

**Files:**
- Modify: `app/Http/Controllers/EfakturaSendController.php` (`authorizeSigning`), `resources/views/livewire/invoicing/sales-invoice-show.blade.php` (блокот `@if ($invoice->status === 'confirmed' && … workWithEfaktura …)` на ред ~183 + предупредување), `resources/views/livewire/invoicing/sales-invoice-form.blade.php` (предупредување кога постои `salesInvoice`)
- Test: `tests/Feature/Invoicing/ImportedInvoiceEfakturaLockTest.php`

**Interfaces:**
- Consumes: `SalesInvoice::isImported()`, `importedTotalMismatch()` од Task 1.

- [ ] **Step 1: Тестови.** `signingInput` и `send` за потврдена увезена фактура ⇒ 422 со порака „Фактурата е увезена од друга програма и не се испраќа до УЈП."; истите за обична фактура НЕ ја враќаат таа порака (контрола); Show-страницата на увезена потврдена фактура не го содржи `efakturaSend` блокот; кога збирот не се совпаѓа Show и Form покажуваат амбер порака со двата износа, а кога се совпаѓа — не; потврда (`SalesInvoiceService::confirm`) на увезен нацрт со ставки ја задржува бројот од хартија и `invoice_number` останува `null` (бројачот не се троши).
- [ ] **Step 2:** Паѓаат.
- [ ] **Step 3:** Имплементирај (`abort_if($salesInvoice->isImported(), 422, …)` веднаш по `status === 'confirmed'` проверката).
- [ ] **Step 4:** Поминуваат + `SalesInvoicePaperNumberTest`, `SalesInvoicePaperNumberFormTest`.
- [ ] **Step 5:** Commit `Увезена фактура: заклучена за е-Фактура + предупредување кога збирот не е како на хартија`.

### Task 7: Целосна проверка и пуштање

- [ ] **Step 1:** `php artisan test` директно (~9 мин; не `composer test` — паѓа на лимит а враќа exit 0). Очекувано: сè зелено, без нови прескокнати.
- [ ] **Step 2:** Рачна проверка на екранот со статична/локална страница ако е можно (artisan serve е премногу бавен — по потреба статичка проба); барем HTTP-тестовите од Task 5.
- [ ] **Step 3:** Спој во `main`, `git push`, потврди дека CI (`test`+`deploy`) е зелен пред да се наречe „живо" (постои MySQL-само-грешка ризик — миграцијата е обична колона, но CI е мерилото).
- [ ] **Step 4:** Запиши лог `docs/superpowers/2026-10-16-bulk-invoice-import-log.md` и ажурирај ја меморијата (`tami-web-app-project`).
