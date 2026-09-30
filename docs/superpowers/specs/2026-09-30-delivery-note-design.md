# Испратница (Delivery Note) — Design

## Context

Испратницата е стандарден комерцијален документ во Македонија — доказ за
физичко движење стока кон купувачот. За разлика од фактурата, нема законски
пропишана форма или УЈП-стандард (нема е-Испратница систем); секоја фирма ја
дефинира сама, но вообичаената пракса (потврдена и низ реален образец,
giveko.mk/content/Dokumenti/ispratnica.pdf) бара:

- Заглавие: издавач, место, датум, број на испратница (посебна секвенца)
- Страни: испраќач (нашата фирма) и примач (партнерот)
- Табела на стоки: реден број, шифра/код, назив, единица мера, количина —
  **без цени и без ДДВ** (штом стои цена, документот по пракса станува
  фактура, не испратница)
- Потпис: „предал" (издавач) и „примил" (примач)

Целта на оваа фаза: копче на потврдена профактура и на потврдена фактура што
ја издава (или повторно ја отвора) испратницата како PDF.

## Scope

- Нова табела `delivery_notes` + модел `DeliveryNote`, со полиморфна врска кон
  изворот (`ProformaInvoice` или `SalesInvoice`).
- Своја секвенца на број, по фирма/година, со свој префикс во Поставки
  (стандардно „ИСП-"), иста логика за форматирање како кај фактура/профактура
  (`App\Support\InvoiceNumber::format()`).
- Нов PDF-шаблон `resources/views/pdf/delivery-note.blade.php`, по примерот на
  `pdf/sales-invoice.blade.php` (исто лого/боја/рамки), без ценовни/ДДВ колони.
- Копче „Испратница" на:
  - `proforma-index.blade.php` — видливо само кога `$selected->status === 'confirmed'`
  - `sales-invoice-show.blade.php` — видливо само кога `$invoice->status === 'confirmed'`
- Копчето е достапно само за фирми со вклучено Материјално работење
  (`EnsureCompanyModule::class.':material'`, истата гејт-рута како профактура/фактура).
- Клик на копчето: ако веќе постои испратница за таа профактура/фактура, се
  преземa истиот веќе-издаден PDF (ист број); ако не постои, се создава и се
  презема. Нема повеќе испратници по еден извор во оваа фаза.
- Само македонски јазик (без EN верзија, за разлика од фактурата).
- Содржина: реден број, шифра, опис, единица мера, количина, потпис
  „Предал"/„Примил", и повикување на бројот на фактурата/профактурата од која
  е издадена. Без регистарска ознака на возило, без посебна адреса за
  испорака — во оваа фаза.

## Out of scope

- Не допира залиха/движење стока — тоа веќе го прави `SalesInvoiceService`
  при потврдување фактура.
- Нема сопствени линии (снапшот на количини) — PDF-от ги чита ставките живо
  од изворот (`$deliveryNote->deliverable->lines`), исто како профактура PDF
  денес. Ако некој ја измени потврдена профактура откако е издадена
  испратница, PDF-от при следно преземање ќе ги покаже новите количини — овој
  ризик веќе постои и кај профактура PDF денес.
- Нема делумни испораки (multiple испратници по еден извор) — секое
  барање враќа иста испратница.
- Нема е-Испратница/УЈП интеграција (не постои таков систем во Македонија).

## Data model

### Migration: `delivery_notes`

| column | type | notes |
|---|---|---|
| `id` | bigint | |
| `company_id` | FK → companies | |
| `deliverable_type` / `deliverable_id` | morph | `ProformaInvoice` или `SalesInvoice` |
| `fiscal_year` | int | исто правило како кај профактура/фактура |
| `delivery_note_number` | int | сурова секвенца |
| `delivery_note_number_formatted` | string | замрзнат текст, преку `InvoiceNumber::format()` |
| `delivery_date` | date | датум на издавање (= датум на создавање) |
| `created_by` | FK → users | |
| timestamps | | |

Unique index на (`deliverable_type`, `deliverable_id`) — спречува втора
испратница по ист извор (согласно "иста испратница, ист број" одлуката).

### Model: `App\Models\DeliveryNote`

- `belongsTo(Company::class)`, `morphTo()` кон изворот, `belongsTo(User::class, 'created_by')`
- `formattedNumber()` — паралелно со `SalesInvoice::formattedNumber()`

### Company

Нова колона `delivery_note_number_prefix` (default `'ИСП-'`), во истата листа
на fillable како `proforma_number_prefix`. Ново поле во
`InvoiceSettings`/`invoice-settings.blade.php`, веднаш до полето за
профактура-префикс.

## Service

`App\Services\Invoicing\DeliveryNoteService::createOrGetFor(Model $source): DeliveryNote`

- прифаќа `ProformaInvoice` или `SalesInvoice`
- фрла исклучок ако изворот не е потврден (`status !== 'confirmed'`)
- ако веќе постои запис за тој извор (`firstWhere` на morph-колоните), го
  враќа истиот
- инаку, во транзакција со `lockForUpdate()->max('delivery_note_number')`,
  ист образец како `ProformaService::create()`, доделува нов број и создава
  запис

## Routes & controller

Под истата гејт-група `EnsureCompanyModule::class.':material'`:

```
GET companies/{company}/proformas/{proforma}/delivery-note        → proformas.delivery-note
GET companies/{company}/sales-invoices/{salesInvoice}/delivery-note → sales-invoices.delivery-note
```

Два тенки контролери, по примерот на `ProformaPdfController`/`SalesInvoicePdfController`:
`ProformaDeliveryNotePdfController` и `SalesInvoiceDeliveryNotePdfController` — секој:
1. `Gate::authorize('view', $source)`
2. `abort_if($source->company_id !== $company->id, 404)`
3. `abort_unless($source->status === 'confirmed', 403)`
4. `DeliveryNoteService::createOrGetFor($source)`
5. `Pdf::loadView('pdf.delivery-note', [...])->download(...)`

## PDF template

`resources/views/pdf/delivery-note.blade.php`, копија на летерхед/боја стилот
од `pdf/sales-invoice.blade.php`:

- Наслов „Испратница" + број + датум, наместо „Фактура"
- Купувач/Продавач кутии — исти како кај фактура
- Табела: Ред. бр. | Шифра | Опис | Ед. мера | Количина (без цени/ДДВ колони)
- Ред со текст: „По фактура/профактура бр. {formattedNumber}"
- Потпис „Предал" / „Примил" — идентично со `table.signatures` од фактурата
- Само македонски (нема `$lang`/`InvoiceLanguage` избор)

## UI changes

- `proforma-index.blade.php`: копче „Испратница" веднаш до „Преземи PDF",
  видливо само `@if ($selected->status === 'confirmed')`
- `sales-invoice-show.blade.php`: копче „Испратница" веднаш до „Преземи PDF",
  видливо само `@if ($invoice->status === 'confirmed')`
- `invoice-settings.blade.php` + `InvoiceSettings.php`: ново поле за префикс
  на испратница

## Testing

- `DeliveryNoteServiceTest`: нумерирање по фирма/година (паралелно со
  постојниот тест за профактура), повторен повик враќа ист запис/број,
  исклучок за непотврден извор
- `DeliveryNotePdfTest` (feature, како `SalesInvoicePdfControllerTest` ако
  постои): 404 на туѓа фирма, 403 на непотврдена профактура/фактура, PDF нема
  цени/ДДВ во содржината, копчето отсуствува кога фирмата нема Материјално
  работење (гејт веќе тестиран на постојните рути — доволно е да се потврди
  дека новата рута е во истата гејт-група)
- UI тест: копчето „Испратница" се гледа/не се гледа според статус, исто како
  постојните тестови за видливост на копчиња во `SidebarTest`/`ProformaIndex`
  тестови

## Open questions (none blocking)

Нема отворени прашања — сите клучни одлуки (сопствена секвенца, се чува како
запис, ист број на повторен клик, без цени, без дополнителни полиња, само
македонски) се потврдени со сопственикот.
