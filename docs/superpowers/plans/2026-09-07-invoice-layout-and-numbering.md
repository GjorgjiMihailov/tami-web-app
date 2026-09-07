# Изглед на фактурата и формат на бројот — план за изведба

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Излезната фактура добива блок за плаќање како уплатница, износ на ДДВ по ставка и линии за потпис, а секоја фирма си го поставува форматот на бројот на фактурата.

**Architecture:** Шест нови колони на `companies` ги држат поставките за форматот. Една класа, `App\Support\InvoiceNumber`, е единственото место што склопува број од тие поставки. При потврдување на фактура готовиот текст се запишува во нова колона `sales_invoices.invoice_number_formatted` и оттаму натаму фактурата го носи својот број со себе; сите осум места што досега рачно лепеа `fiscal_year` и `invoice_number` минуваат на `SalesInvoice::formattedNumber()`. Изгледот на PDF-от се менува само во `resources/views/pdf/sales-invoice.blade.php`.

**Tech Stack:** Laravel 13, Livewire 3, PHPUnit (не Pest), dompdf 3.1.6, Tailwind, MySQL во продукција и CI, SQLite во меморија локално.

**Спецификација:** `docs/superpowers/specs/2026-09-07-invoice-layout-and-numbering-design.md`

## Global Constraints

- Тестовите се пишуваат **пред** имплементацијата и мора да паднат пред да поминат.
- Единечен тест-фајл додека се работи: `php artisan test tests/Path/To/Test.php`. Целата серија се пушта **еднаш пред спојување**, не по секоја задача.
- Локално базата е SQLite во меморија, во CI е MySQL. **Ниту еден SQL што не е портабилен** — без `CONCAT`, без `||`, без `IF()`. Пополнувањата на податоци се пишуваат преку Eloquent, како во `2026_07_27_090200_migrate_company_bank_account_to_company_bank_accounts.php`.
- Стандардните вредности на нови колони се повторуваат во `Company::$attributes`. Стандардна вредност во базата **не** го полни свежо создадениот модел во меморија — веќе документирано во `app/Models/Company.php` кај модулите.
- dompdf 3.1.6 нема flex. Секое повеќеколонско распоредување во PDF темплејтот е `<table>`/`<td>` со експлицитни ширини.
- Прозата во кодот (коментари, пораки, етикети) е на **строг македонски**. Имињата на тест-методите се на англиски, `snake_case`, како во постоечките тестови.
- Стандардните поставки за формат мора да даваат точно `2026/1` — денешниот изглед. Ниту еден постоечки тест што бара `2026/1` не смее да падне.
- Комит по секоја задача, со `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>` на крајот на пораката.

---

## Структура на фајлови

**Ново:**

| Фајл | Одговорност |
|---|---|
| `database/migrations/2026_09_07_100000_add_invoice_number_format_to_companies_table.php` | Шест колони со поставки за формат |
| `database/migrations/2026_09_07_100100_add_formatted_number_to_sales_invoices_table.php` | Колона со замрзнат број + пополнување на постојните |
| `app/Support/InvoiceNumber.php` | Единственото место што склопува број од поставки |
| `app/Livewire/Invoicing/InvoiceSettings.php` | Екран за поставки, со жив преглед |
| `resources/views/livewire/invoicing/invoice-settings.blade.php` | Изглед на тој екран |
| `tests/Unit/Support/InvoiceNumberTest.php` | Форматирањето, без база |
| `tests/Feature/Invoicing/InvoiceNumberFormatTest.php` | Замрзнување, бројач, испис на сите места |
| `tests/Feature/Invoicing/InvoiceSettingsTest.php` | Екранот, пристап и валидација |

**Изменето:**

| Фајл | Измена |
|---|---|
| `app/Models/Company.php` | `$fillable`, `$attributes`, `casts()` |
| `app/Models/SalesInvoice.php` | `$fillable`, `formattedNumber()` |
| `app/Services/Invoicing/SalesInvoiceService.php` | Запишува замрзнат број; опсег на бројачот; описи во дневникот |
| `app/Policies/CompanyPolicy.php` | Способност `updateInvoiceSettings` |
| `app/Support/Menu.php` | Ставка `Фактурирање` во двете менија |
| `app/Services/Efaktura/EfakturaDocumentBuilder.php` | `docNumber` |
| `app/Http/Controllers/SalesInvoicePdfController.php` | Име на датотека |
| `app/Http/Controllers/EfakturaPdfController.php` | Име на датотека |
| `routes/web.php` | Рута `sales-invoices.settings` |
| `resources/views/pdf/sales-invoice.blade.php` | Значка, блок за плаќање, колона ДДВ, потписи |
| `resources/views/livewire/invoicing/sales-invoice-index.blade.php` | Колона со број |
| `resources/views/livewire/invoicing/sales-invoice-show.blade.php` | Наслов |
| `tests/Feature/SidebarTest.php`, `tests/Unit/Support/MenuTest.php` | Ако бројат ставки во менито |

---

## Task 1: Поставки за формат на фирма и класата што форматира

**Files:**
- Create: `database/migrations/2026_09_07_100000_add_invoice_number_format_to_companies_table.php`
- Create: `app/Support/InvoiceNumber.php`
- Modify: `app/Models/Company.php:27-75`
- Test: `tests/Unit/Support/InvoiceNumberTest.php`

**Interfaces:**
- Produces: `App\Support\InvoiceNumber::format(Company $company, int $fiscalYear, int $sequence): string`
- Produces: колони на `companies` — `invoice_number_prefix` (`?string`), `invoice_number_include_year` (`bool`), `invoice_number_year_first` (`bool`), `invoice_number_year_digits` (`int`, 2 или 4), `invoice_number_separator` (`string`, може празен), `invoice_number_padding` (`int`, 1–6)

- [ ] **Step 1: Напиши го тестот што паѓа**

Создај `tests/Unit/Support/InvoiceNumberTest.php`:

```php
<?php

namespace Tests\Unit\Support;

use App\Models\Company;
use App\Support\InvoiceNumber;
use Tests\TestCase;

/**
 * Форматирањето не допира база — работи врз модел во меморија, за да може
 * екранот со поставки да го користи истиот код за живиот преглед.
 */
class InvoiceNumberTest extends TestCase
{
    private function company(array $overrides = []): Company
    {
        return new Company($overrides);
    }

    public function test_the_default_settings_produce_the_old_format(): void
    {
        $this->assertSame('2026/1', InvoiceNumber::format($this->company(), 2026, 1));
    }

    public function test_the_year_can_come_after_the_number(): void
    {
        $company = $this->company(['invoice_number_year_first' => false]);

        $this->assertSame('1/2026', InvoiceNumber::format($company, 2026, 1));
    }

    public function test_it_pads_the_number_and_shortens_the_year(): void
    {
        $company = $this->company([
            'invoice_number_year_first' => false,
            'invoice_number_year_digits' => 2,
            'invoice_number_separator' => '-',
            'invoice_number_padding' => 5,
        ]);

        $this->assertSame('00001-26', InvoiceNumber::format($company, 2026, 1));
    }

    public function test_the_year_can_be_left_out_entirely(): void
    {
        $company = $this->company([
            'invoice_number_include_year' => false,
            'invoice_number_padding' => 5,
        ]);

        $this->assertSame('00001', InvoiceNumber::format($company, 2026, 1));
    }

    public function test_a_prefix_goes_in_front_of_everything(): void
    {
        $company = $this->company([
            'invoice_number_prefix' => 'ФА-',
            'invoice_number_padding' => 3,
        ]);

        $this->assertSame('ФА-2026/001', InvoiceNumber::format($company, 2026, 1));
    }

    public function test_an_empty_separator_joins_the_parts_directly(): void
    {
        $company = $this->company([
            'invoice_number_year_first' => false,
            'invoice_number_year_digits' => 2,
            'invoice_number_separator' => '',
            'invoice_number_padding' => 5,
        ]);

        $this->assertSame('0000126', InvoiceNumber::format($company, 2026, 1));
    }

    public function test_a_number_longer_than_the_padding_is_not_truncated(): void
    {
        $company = $this->company(['invoice_number_padding' => 3]);

        $this->assertSame('2026/1234', InvoiceNumber::format($company, 2026, 1234));
    }

    public function test_the_maximum_padding_is_six_digits(): void
    {
        $company = $this->company(['invoice_number_padding' => 6]);

        $this->assertSame('2026/000001', InvoiceNumber::format($company, 2026, 1));
    }
}
```

- [ ] **Step 2: Пушти го тестот и провери дека паѓа**

Run: `php artisan test tests/Unit/Support/InvoiceNumberTest.php`
Expected: FAIL — `Class "App\Support\InvoiceNumber" not found`

- [ ] **Step 3: Направи ја миграцијата**

Создај `database/migrations/2026_09_07_100000_add_invoice_number_format_to_companies_table.php`:

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
            // Стандардните вредности даваат точно ГГГГ/Н — форматот што досега
            // беше зашиен во кодот. Ниту една постоечка фирма не си го менува
            // изгледот на фактурите кога оваа миграција ќе помине.
            $table->string('invoice_number_prefix', 10)->nullable()->after('invoice_footer_note');
            $table->boolean('invoice_number_include_year')->default(true)->after('invoice_number_prefix');
            $table->boolean('invoice_number_year_first')->default(true)->after('invoice_number_include_year');
            $table->unsignedTinyInteger('invoice_number_year_digits')->default(4)->after('invoice_number_year_first');
            $table->string('invoice_number_separator', 3)->default('/')->after('invoice_number_year_digits');
            $table->unsignedTinyInteger('invoice_number_padding')->default(1)->after('invoice_number_separator');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_number_prefix',
                'invoice_number_include_year',
                'invoice_number_year_first',
                'invoice_number_year_digits',
                'invoice_number_separator',
                'invoice_number_padding',
            ]);
        });
    }
};
```

- [ ] **Step 4: Дополни го моделот `Company`**

Во `app/Models/Company.php`, на крајот од `$fillable` (по `'uses_material', 'uses_stock', 'uses_payroll', 'uses_finance',`) додај:

```php
        'invoice_number_prefix', 'invoice_number_include_year', 'invoice_number_year_first',
        'invoice_number_year_digits', 'invoice_number_separator', 'invoice_number_padding',
```

Во `$attributes`, по постоечките клучеви за модули, додај (истата причина што е веќе објаснета во коментарот над низата — важи и тука):

```php
        'invoice_number_include_year' => true,
        'invoice_number_year_first' => true,
        'invoice_number_year_digits' => 4,
        'invoice_number_separator' => '/',
        'invoice_number_padding' => 1,
```

`invoice_number_prefix` намерно не влегува во `$attributes` — неговата стандардна вредност е `null`, а тоа е и стандардот на неиницијализирано својство.

Во `casts()`, по `'uses_finance' => 'boolean',`, додај:

```php
            'invoice_number_include_year' => 'boolean',
            'invoice_number_year_first' => 'boolean',
            'invoice_number_year_digits' => 'integer',
            'invoice_number_padding' => 'integer',
```

- [ ] **Step 5: Напиши ја класата**

Создај `app/Support/InvoiceNumber.php`:

```php
<?php

namespace App\Support;

use App\Models\Company;

/**
 * Единственото место што склопува број на фактура од поставките на фирмата.
 *
 * Пред ова, `fiscal_year` и `invoice_number` се лепеа рачно на осум места, со
 * два различни разделника — на PDF-от коса црта, кон УЈП цртичка. Секое ново
 * место што прикажува број мора да поминува оттука.
 */
class InvoiceNumber
{
    public static function format(Company $company, int $fiscalYear, int $sequence): string
    {
        $prefix = (string) ($company->invoice_number_prefix ?? '');

        // Стеснувањето е одбрана од невалидна вредност во базата, не замена за
        // валидација на екранот — str_pad со должина 0 би вратил празно.
        $padding = max(1, min(6, (int) $company->invoice_number_padding));
        $number = str_pad((string) $sequence, $padding, '0', STR_PAD_LEFT);

        if (! $company->invoice_number_include_year) {
            return $prefix.$number;
        }

        $year = ((int) $company->invoice_number_year_digits) === 2
            ? substr((string) $fiscalYear, -2)
            : (string) $fiscalYear;

        $separator = (string) ($company->invoice_number_separator ?? '');

        return $company->invoice_number_year_first
            ? $prefix.$year.$separator.$number
            : $prefix.$number.$separator.$year;
    }
}
```

- [ ] **Step 6: Пушти го тестот и провери дека поминува**

Run: `php artisan test tests/Unit/Support/InvoiceNumberTest.php`
Expected: PASS — 8 тестови

- [ ] **Step 7: Провери дека постоечките тестови за `Company` не паднале**

Run: `php artisan test tests/Unit/CompanyTest.php tests/Feature/CompanyProfileTest.php tests/Feature/CompanyModulesTest.php`
Expected: PASS

- [ ] **Step 8: Комит**

```bash
git add database/migrations/2026_09_07_100000_add_invoice_number_format_to_companies_table.php app/Support/InvoiceNumber.php app/Models/Company.php tests/Unit/Support/InvoiceNumberTest.php
git commit -m "feat: поставки за формат на бројот на фактура по фирма

Шест колони на companies и една класа што ги склопува во број.
Стандардните вредности даваат ГГГГ/Н, точно како досега.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 2: Замрзнување на бројот при потврдување

**Files:**
- Create: `database/migrations/2026_09_07_100100_add_formatted_number_to_sales_invoices_table.php`
- Modify: `app/Models/SalesInvoice.php:36-40`
- Modify: `app/Services/Invoicing/SalesInvoiceService.php:128-133`
- Test: `tests/Feature/Invoicing/InvoiceNumberFormatTest.php`

**Interfaces:**
- Consumes: `App\Support\InvoiceNumber::format(Company, int, int): string` од Task 1
- Produces: `SalesInvoice::formattedNumber(): ?string` — враќа замрзнатиот текст, или го пресметува во лет ако колоната е празна, или `null` за нацрт
- Produces: колона `sales_invoices.invoice_number_formatted` (`?string`, 40 знаци)

- [ ] **Step 1: Напиши го тестот што паѓа**

Создај го директориумот и фајлот `tests/Feature/Invoicing/InvoiceNumberFormatTest.php`:

```php
<?php

namespace Tests\Feature\Invoicing;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceNumberFormatTest extends TestCase
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
        $this->actingAs($admin);

        return $admin;
    }

    private function draft(Company $company, string $date = '2026-03-10'): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'invoice_date' => $date,
            'status' => 'draft',
        ]);
        $invoice->lines()->create([
            'description' => 'Услуга',
            'quantity' => '1',
            'unit_price' => '1000.00',
            'vat_rate' => '18.00',
        ]);

        return $invoice->fresh(['lines']);
    }

    public function test_confirming_freezes_the_formatted_number(): void
    {
        // CompanyObserver го сее целиот официјален контен план при создавање,
        // па секој конто што confirm() го бара постои.
        $company = Company::factory()->create([
            'invoice_number_year_first' => false,
            'invoice_number_year_digits' => 2,
            'invoice_number_separator' => '-',
            'invoice_number_padding' => 5,
        ]);
        $admin = $this->admin();

        $invoice = app(SalesInvoiceService::class)->confirm($this->draft($company), $admin->id);

        $this->assertSame('00001-26', $invoice->fresh()->invoice_number_formatted);
        $this->assertSame('00001-26', $invoice->fresh()->formattedNumber());
    }

    public function test_changing_the_format_later_does_not_move_a_confirmed_invoice(): void
    {
        $company = Company::factory()->create();
        $admin = $this->admin();

        $invoice = app(SalesInvoiceService::class)->confirm($this->draft($company), $admin->id);
        $this->assertSame('2026/1', $invoice->fresh()->formattedNumber());

        $company->update([
            'invoice_number_separator' => '-',
            'invoice_number_padding' => 5,
        ]);

        $this->assertSame('2026/1', $invoice->fresh()->formattedNumber());
    }

    public function test_a_draft_has_no_number(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        $this->assertNull($this->draft($company)->formattedNumber());
    }

    public function test_a_row_with_no_frozen_text_falls_back_to_the_current_settings(): void
    {
        $company = Company::factory()->create(['invoice_number_padding' => 4]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'confirmed',
            'fiscal_year' => 2026,
            'invoice_number' => 7,
            'invoice_number_formatted' => null,
        ]);

        $this->assertSame('2026/0007', $invoice->formattedNumber());
    }
}
```

- [ ] **Step 2: Пушти го тестот и провери дека паѓа**

Run: `php artisan test tests/Feature/Invoicing/InvoiceNumberFormatTest.php`
Expected: FAIL — `Unknown column 'invoice_number_formatted'`, односно `Call to undefined method ... formattedNumber()`

- [ ] **Step 3: Направи ја миграцијата со пополнување**

Создај `database/migrations/2026_09_07_100100_add_formatted_number_to_sales_invoices_table.php`:

```php
<?php

use App\Models\SalesInvoice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('invoice_number_formatted', 40)->nullable()->after('invoice_number');
        });

        // Пополнувањето оди преку Eloquent, не преку CONCAT: локално базата е
        // SQLite, во CI и продукција MySQL, а CONCAT не постои во SQLite.
        // Секоја постоечка потврдена фактура го добива точно својот сегашен
        // број, ГГГГ/Н — ниту една веќе испечатена фактура не си го менува.
        SalesInvoice::whereNotNull('invoice_number')
            ->each(function (SalesInvoice $invoice) {
                $invoice->updateQuietly([
                    'invoice_number_formatted' => $invoice->fiscal_year.'/'.$invoice->invoice_number,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn('invoice_number_formatted');
        });
    }
};
```

- [ ] **Step 4: Дополни го моделот `SalesInvoice`**

Во `app/Models/SalesInvoice.php`, во `$fillable`, веднаш по `'invoice_number',` додај `'invoice_number_formatted',`.

Додај го методот (внеси го веднаш по `$fillable`/`casts()` блокот, пред релациите), и додај `use App\Support\InvoiceNumber;` меѓу `use` изјавите:

```php
    /**
     * Бројот што стои на фактурата.
     *
     * Замрзнатиот текст е вистината — се запишува при потврдување и подоцнежна
     * промена на форматот на фирмата не го допира. Пресметката во лет служи
     * само за редови што останале без текст (нацрт што сè уште нема број, или
     * ред создаден заобиколувајќи го сервисот во тест).
     */
    public function formattedNumber(): ?string
    {
        if (filled($this->invoice_number_formatted)) {
            return $this->invoice_number_formatted;
        }

        if ($this->invoice_number === null) {
            return null;
        }

        return InvoiceNumber::format($this->company, (int) $this->fiscal_year, (int) $this->invoice_number);
    }
```

- [ ] **Step 5: Нека `confirm()` го запишува**

Во `app/Services/Invoicing/SalesInvoiceService.php` додај `use App\Support\InvoiceNumber;` меѓу `use` изјавите, и во `confirm()` дополни ја низата на `$invoice->update([...])` (околу линија 128) со еден клуч:

```php
            $invoice->update([
                'fiscal_year' => $fiscalYear,
                'invoice_number' => $invoiceNumber,
                'invoice_number_formatted' => InvoiceNumber::format($invoice->company, $fiscalYear, $invoiceNumber),
                'journal_entry_id' => $entry->id,
                'status' => 'confirmed',
            ]);
```

- [ ] **Step 6: Пушти го тестот и провери дека поминува**

Run: `php artisan test tests/Feature/Invoicing/InvoiceNumberFormatTest.php`
Expected: PASS — 4 тестови

- [ ] **Step 7: Провери дека потврдувањето не е скршено на друго место**

Run: `php artisan test tests/Unit/SalesInvoiceServiceTest.php tests/Feature/WorkingYearNumberingIntegrityTest.php`
Expected: PASS

- [ ] **Step 8: Комит**

```bash
git add database/migrations/2026_09_07_100100_add_formatted_number_to_sales_invoices_table.php app/Models/SalesInvoice.php app/Services/Invoicing/SalesInvoiceService.php tests/Feature/Invoicing/InvoiceNumberFormatTest.php
git commit -m "feat: бројот на фактурата се замрзнува при потврдување

Готовиот текст се запишува во sales_invoices.invoice_number_formatted.
Постојните фактури се пополнуваат со својот сегашен број ГГГГ/Н.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 3: Опсег на бројачот според форматот

**Files:**
- Modify: `app/Services/Invoicing/SalesInvoiceService.php:41-47`
- Test: `tests/Feature/Invoicing/InvoiceNumberFormatTest.php` (дополнување)

**Interfaces:**
- Consumes: `SalesInvoice::formattedNumber()` и `confirm()` од Task 2
- Produces: ништо ново — менува само однесување

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Додај ги овие два метода на крајот од `tests/Feature/Invoicing/InvoiceNumberFormatTest.php`, пред затворачката заграда:

```php
    public function test_a_format_with_a_year_restarts_the_counter_each_year(): void
    {
        $company = Company::factory()->create();
        $admin = $this->admin();
        $service = app(SalesInvoiceService::class);

        $service->confirm($this->draft($company, '2026-03-10'), $admin->id);
        $second = $service->confirm($this->draft($company, '2027-01-05'), $admin->id);

        $this->assertSame(1, $second->fresh()->invoice_number);
        $this->assertSame('2027/1', $second->fresh()->formattedNumber());
    }

    public function test_a_format_without_a_year_keeps_counting_across_years(): void
    {
        // Без година во бројот, рестартирањето би дало две фактури со ист број
        // 00001 во две различни години — истиот број на два документа.
        $company = Company::factory()->create([
            'invoice_number_include_year' => false,
            'invoice_number_padding' => 5,
        ]);
        $admin = $this->admin();
        $service = app(SalesInvoiceService::class);

        $service->confirm($this->draft($company, '2026-03-10'), $admin->id);
        $second = $service->confirm($this->draft($company, '2027-01-05'), $admin->id);

        $this->assertSame(2, $second->fresh()->invoice_number);
        $this->assertSame('00002', $second->fresh()->formattedNumber());
    }

    public function test_the_fiscal_year_still_follows_the_invoice_date_without_a_year_in_the_number(): void
    {
        // fiscal_year останува основа за ДДВ и извештаите, без оглед на тоа
        // дали годината се гледа во бројот.
        $company = Company::factory()->create(['invoice_number_include_year' => false]);
        $admin = $this->admin();

        $invoice = app(SalesInvoiceService::class)->confirm($this->draft($company, '2027-01-05'), $admin->id);

        $this->assertSame(2027, (int) $invoice->fresh()->fiscal_year);
    }

    public function test_counters_never_leak_between_companies(): void
    {
        $first = Company::factory()->create(['invoice_number_include_year' => false]);
        $second = Company::factory()->create(['invoice_number_include_year' => false]);
        $admin = $this->admin();
        $service = app(SalesInvoiceService::class);

        $service->confirm($this->draft($first), $admin->id);
        $other = $service->confirm($this->draft($second), $admin->id);

        $this->assertSame(1, $other->fresh()->invoice_number);
    }
```

- [ ] **Step 2: Пушти ги и провери дека паѓаат**

Run: `php artisan test tests/Feature/Invoicing/InvoiceNumberFormatTest.php --filter=keeps_counting_across_years`
Expected: FAIL — очекувано 2, добиено 1

- [ ] **Step 3: Смени го опсегот на бројачот**

Во `app/Services/Invoicing/SalesInvoiceService.php`, во `confirm()`, замени го блокот што го бара `$maxNumber` (околу линии 41–47) со:

```php
            $fiscalYear = $invoice->invoice_date->year;

            // Опсегот на бројачот го диктира форматот. Со година во бројот,
            // сериите се одвојуваат по година како досега. Без година, серијата
            // мора да тече непрекинато — инаку 2027 би почнала пак од 1 и две
            // фактури би носеле ист број.
            $numberQuery = SalesInvoice::where('company_id', $invoice->company_id);

            if ($invoice->company->invoice_number_include_year) {
                $numberQuery->where('fiscal_year', $fiscalYear);
            }

            $maxNumber = $numberQuery->lockForUpdate()->max('invoice_number');
            $invoiceNumber = ($maxNumber ?? 0) + 1;
```

`$fiscalYear` и понатаму се полни од датумот на фактурата и се запишува непроменето — само влегува во условот условно.

- [ ] **Step 4: Пушти го целиот фајл и провери дека поминува**

Run: `php artisan test tests/Feature/Invoicing/InvoiceNumberFormatTest.php`
Expected: PASS — 8 тестови

- [ ] **Step 5: Провери го тестот за интегритет на работната година**

Run: `php artisan test tests/Feature/WorkingYearNumberingIntegrityTest.php tests/Unit/SalesInvoiceServiceTest.php`
Expected: PASS

- [ ] **Step 6: Комит**

```bash
git add app/Services/Invoicing/SalesInvoiceService.php tests/Feature/Invoicing/InvoiceNumberFormatTest.php
git commit -m "feat: бројачот тече непрекинато кога форматот нема година

Со година во бројот се рестартира секој јануари, како досега.
fiscal_year и понатаму го следи датумот на фактурата.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 4: Сите осум места го користат замрзнатиот број

**Files:**
- Modify: `app/Services/Efaktura/EfakturaDocumentBuilder.php:14`
- Modify: `app/Http/Controllers/SalesInvoicePdfController.php:23`
- Modify: `app/Http/Controllers/EfakturaPdfController.php:83`
- Modify: `app/Services/Invoicing/SalesInvoiceService.php:171,213`
- Modify: `resources/views/pdf/sales-invoice.blade.php:67,85`
- Modify: `resources/views/livewire/invoicing/sales-invoice-index.blade.php:43`
- Modify: `resources/views/livewire/invoicing/sales-invoice-show.blade.php:3`
- Test: `tests/Feature/Invoicing/InvoiceNumberFormatTest.php` (дополнување)

**Interfaces:**
- Consumes: `SalesInvoice::formattedNumber(): ?string` од Task 2

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Додај ги на крајот од `tests/Feature/Invoicing/InvoiceNumberFormatTest.php`, и додај `use App\Services\Efaktura\EfakturaDocumentBuilder;` меѓу `use` изјавите на фајлот:

```php
    public function test_the_efaktura_document_number_matches_the_printed_number(): void
    {
        $company = Company::factory()->create([
            'invoice_number_year_digits' => 2,
            'invoice_number_separator' => '-',
            'invoice_number_padding' => 4,
        ]);
        $admin = $this->admin();

        $invoice = app(SalesInvoiceService::class)->confirm($this->draft($company), $admin->id);
        $document = app(EfakturaDocumentBuilder::class)
            ->build($invoice->fresh(['lines', 'partner', 'company']));

        $this->assertSame('26-0001', $invoice->fresh()->formattedNumber());
        $this->assertSame('26-0001', $document['document']['header']['docNumber']);
        // docId го носи истиот број — мора да се движи заедно со docNumber.
        $this->assertSame('26-0001', $document['document']['header']['docId']);
    }

    public function test_the_pdf_shows_the_formatted_number(): void
    {
        $company = Company::factory()->create([
            'invoice_number_separator' => '-',
            'invoice_number_padding' => 5,
        ]);
        $admin = $this->admin();

        $invoice = app(SalesInvoiceService::class)->confirm($this->draft($company), $admin->id);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('ФАКТУРА 2026-00001', $html);
    }

    public function test_the_pdf_filename_carries_no_slash(): void
    {
        $company = Company::factory()->create();
        $admin = $this->admin();

        $invoice = app(SalesInvoiceService::class)->confirm($this->draft($company), $admin->id);

        $response = $this->actingAs($admin)
            ->get(route('sales-invoices.pdf', [$company, $invoice]));

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('invoice-2026-1.pdf', $disposition);
        $this->assertStringNotContainsString('2026/1', $disposition);
    }
```

`build()` враќа вгнездена низа: бројот седи на `['document']['header']['docNumber']`, а истата вредност се повторува и на `['document']['header']['docId']` — затоа тестот ги проверува двете.

- [ ] **Step 2: Пушти ги и провери дека паѓаат**

Run: `php artisan test tests/Feature/Invoicing/InvoiceNumberFormatTest.php --filter=efaktura_document_number`
Expected: FAIL — добиено `2026-1` наместо `26-0001`

- [ ] **Step 3: Смени го градителот за е-Фактура**

Во `app/Services/Efaktura/EfakturaDocumentBuilder.php`, линија 14:

```php
        $docNumber = $invoice->formattedNumber();
```

- [ ] **Step 4: Смени ги имињата на PDF-датотеките**

Во `app/Http/Controllers/SalesInvoicePdfController.php`, линија 23:

```php
        // Форматираниот број може да содржи коса црта, а таа не смее во име на
        // датотека. Сè што не е буква, цифра или цртичка станува цртичка.
        $slug = preg_replace('/[^\p{L}\p{N}-]+/u', '-', $salesInvoice->formattedNumber());

        return $pdf->download("invoice-{$slug}.pdf");
```

Во `app/Http/Controllers/EfakturaPdfController.php`, линија 83:

```php
        $slug = preg_replace('/[^\p{L}\p{N}-]+/u', '-', $salesInvoice->formattedNumber());
        $filename = "faktura-{$slug}.pdf";
```

- [ ] **Step 5: Смени ги описите во дневникот**

Во `app/Services/Invoicing/SalesInvoiceService.php`, линија 171:

```php
                'description' => "Reversal of invoice {$invoice->formattedNumber()}",
```

и линија 213:

```php
            $label = "Payment for invoice {$invoice->formattedNumber()}";
```

- [ ] **Step 6: Смени ги трите изгледи**

Во `resources/views/pdf/sales-invoice.blade.php`, **двете** појави на значката (линии 67 и 85) стануваат:

```blade
                        <span class="badge">ФАКТУРА {{ $invoice->formattedNumber() }}</span>
```

Во `resources/views/livewire/invoicing/sales-invoice-index.blade.php`, линија 43:

```blade
                    <td class="py-1 px-3">{{ $invoice->formattedNumber() ?? '—' }}</td>
```

Во `resources/views/livewire/invoicing/sales-invoice-show.blade.php`, линија 3:

```blade
        {{ $invoice->status === 'confirmed' ? "Фактура бр. {$invoice->formattedNumber()}" : 'Нацрт фактура' }}
```

- [ ] **Step 7: Пушти ги тестовите и провери дека поминуваат**

Run: `php artisan test tests/Feature/Invoicing/InvoiceNumberFormatTest.php`
Expected: PASS — 11 тестови

- [ ] **Step 8: Провери ги сите места што го покажуваат бројот**

Run: `php artisan test tests/Feature/SalesInvoicePdfTest.php tests/Feature/SalesInvoiceIndexTest.php tests/Feature/SalesInvoiceShowTest.php tests/Feature/EfakturaPdfControllerTest.php tests/Feature/EfakturaSendControllerTest.php tests/Unit/Services/Efaktura`
Expected: PASS. Ако некој паѓа затоа што бара `2026-1` во `docNumber`, тоа е **очекувана** промена — исправи го тврдењето на `2026/1` и запиши во пораката на комитот дека кон УЈП сега оди истиот број како на хартија.

- [ ] **Step 9: Комит**

```bash
git add app/Services/Efaktura/EfakturaDocumentBuilder.php app/Http/Controllers/SalesInvoicePdfController.php app/Http/Controllers/EfakturaPdfController.php app/Services/Invoicing/SalesInvoiceService.php resources/views/pdf/sales-invoice.blade.php resources/views/livewire/invoicing/sales-invoice-index.blade.php resources/views/livewire/invoicing/sales-invoice-show.blade.php tests/Feature/Invoicing/InvoiceNumberFormatTest.php
git commit -m "feat: еден ист број на фактурата насекаде

PDF, листа, екран, имиња на датотеки, описи во дневникот и docNumber
кон УЈП минуваат на formattedNumber(). Дотогаш кон УЈП одеше ГГГГ-Н,
а на хартија ГГГГ/Н — двата броја никогаш не се совпаѓале.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 5: Екран „Поставки → Фактурирање"

**Files:**
- Create: `app/Livewire/Invoicing/InvoiceSettings.php`
- Create: `resources/views/livewire/invoicing/invoice-settings.blade.php`
- Modify: `app/Policies/CompanyPolicy.php`
- Modify: `routes/web.php:183-190`
- Modify: `app/Support/Menu.php:193-203` и `:236-242`
- Test: `tests/Feature/Invoicing/InvoiceSettingsTest.php`

**Interfaces:**
- Consumes: `App\Support\InvoiceNumber::format(Company, int, int): string` од Task 1
- Produces: рута `sales-invoices.settings` (`GET /companies/{company}/sales-invoices/settings`)
- Produces: `CompanyPolicy::updateInvoiceSettings(User $user, Company $company): bool`

- [ ] **Step 1: Напиши го тестот што паѓа**

Создај `tests/Feature/Invoicing/InvoiceSettingsTest.php`:

```php
<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\InvoiceSettings;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    private function client(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('client');

        return $user;
    }

    public function test_a_client_can_open_the_settings_for_their_own_company(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->client($company))
            ->get(route('sales-invoices.settings', $company))
            ->assertOk()
            ->assertSee('Формат на бројот на фактурата');
    }

    public function test_a_client_cannot_open_the_settings_of_another_company(): void
    {
        $own = Company::factory()->create();
        $other = Company::factory()->create();

        $this->actingAs($this->client($own))
            ->get(route('sales-invoices.settings', $other))
            ->assertForbidden();
    }

    public function test_a_client_can_save_the_format(): void
    {
        $company = Company::factory()->create();

        Livewire::actingAs($this->client($company))
            ->test(InvoiceSettings::class, ['company' => $company])
            ->set('includeYear', true)
            ->set('yearFirst', false)
            ->set('yearDigits', 2)
            ->set('separator', '-')
            ->set('padding', 5)
            ->set('prefix', '')
            ->call('save')
            ->assertHasNoErrors();

        $company->refresh();
        $this->assertFalse($company->invoice_number_year_first);
        $this->assertSame(2, $company->invoice_number_year_digits);
        $this->assertSame('-', $company->invoice_number_separator);
        $this->assertSame(5, $company->invoice_number_padding);
        $this->assertNull($company->invoice_number_prefix);
    }

    public function test_the_preview_follows_what_is_typed_before_saving(): void
    {
        $company = Company::factory()->create();
        $year = (int) now()->year;

        Livewire::actingAs($this->client($company))
            ->test(InvoiceSettings::class, ['company' => $company])
            ->assertViewHas('preview', $year.'/1')
            ->set('separator', 'none')
            ->set('padding', 4)
            ->assertViewHas('preview', $year.'0001');
    }

    public function test_a_format_without_a_year_previews_only_the_number(): void
    {
        $company = Company::factory()->create();

        Livewire::actingAs($this->client($company))
            ->test(InvoiceSettings::class, ['company' => $company])
            ->set('includeYear', false)
            ->set('padding', 5)
            ->assertViewHas('preview', '00001');
    }

    public function test_it_rejects_impossible_values(): void
    {
        $company = Company::factory()->create();

        Livewire::actingAs($this->client($company))
            ->test(InvoiceSettings::class, ['company' => $company])
            ->set('yearDigits', 3)
            ->set('padding', 7)
            ->set('prefix', str_repeat('А', 11))
            ->call('save')
            ->assertHasErrors(['yearDigits', 'padding', 'prefix']);
    }

    public function test_saving_does_not_touch_already_confirmed_invoices(): void
    {
        $company = Company::factory()->create();
        $invoice = \App\Models\SalesInvoice::factory()->for($company)->create([
            'status' => 'confirmed',
            'fiscal_year' => 2026,
            'invoice_number' => 3,
            'invoice_number_formatted' => '2026/3',
        ]);

        Livewire::actingAs($this->client($company))
            ->test(InvoiceSettings::class, ['company' => $company])
            ->set('separator', '-')
            ->set('padding', 5)
            ->call('save');

        $this->assertSame('2026/3', $invoice->fresh()->formattedNumber());
    }
}
```

Клиентот се врзува за фирма преку `company_id` на корисникот — истиот начин како во `tests/Feature/CompanyPolicyTest.php:37`. `CompanyPolicy::view` потоа поминува преку `visibleCompanies()`.

- [ ] **Step 2: Пушти го тестот и провери дека паѓа**

Run: `php artisan test tests/Feature/Invoicing/InvoiceSettingsTest.php`
Expected: FAIL — `Route [sales-invoices.settings] not defined`

- [ ] **Step 3: Додај ја способноста во политиката**

Во `app/Policies/CompanyPolicy.php`, по `update()`:

```php
    /**
     * Пошироко од `update` намерно: форматот на бројот на фактурата е одлука на
     * клиентот, а целиот профил на фирмата останува само за админ.
     */
    public function updateInvoiceSettings(User $user, Company $company): bool
    {
        return $this->view($user, $company);
    }
```

- [ ] **Step 4: Регистрирај ја рутата**

Во `routes/web.php`, во групата `sales-invoices.`, ставката оди **пред** `/sales-invoices/{salesInvoice}` — инаку врзувањето на моделот би се обидело да ја прочита низата `settings` како фактура:

```php
Route::middleware(['auth', EnsureCompanyModule::class.':material'])->prefix('companies/{company}')->name('sales-invoices.')->group(function () {
    Route::get('/sales-invoices', [SalesInvoiceIndex::class, '__invoke'])->name('index');
    Route::get('/sales-invoices/create', [SalesInvoiceForm::class, '__invoke'])->name('create');
    // Мора да стои пред `{salesInvoice}`, како `create` погоре.
    Route::get('/sales-invoices/settings', [InvoiceSettings::class, '__invoke'])->name('settings');
    Route::get('/sales-invoices/{salesInvoice}/edit', [SalesInvoiceForm::class, '__invoke'])->name('edit');
    Route::get('/sales-invoices/{salesInvoice}', [SalesInvoiceShow::class, '__invoke'])->name('show');
    Route::get('/sales-invoices/{salesInvoice}/pdf', [SalesInvoicePdfController::class, '__invoke'])->name('pdf');
});
```

Додај `use App\Livewire\Invoicing\InvoiceSettings;` меѓу `use` изјавите на врвот од `routes/web.php`, покрај останатите Livewire класи.

- [ ] **Step 5: Напиши ја компонентата**

Создај `app/Livewire/Invoicing/InvoiceSettings.php`:

```php
<?php

namespace App\Livewire\Invoicing;

use App\Models\Company;
use App\Support\InvoiceNumber;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class InvoiceSettings extends Component
{
    public Company $company;

    public bool $includeYear = true;

    public bool $yearFirst = true;

    public int $yearDigits = 4;

    /** Празен разделник се пренесува како 'none' — празна низа не поминува низ `in:`. */
    public string $separator = '/';

    public int $padding = 1;

    public string $prefix = '';

    public bool $saved = false;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;
        $this->includeYear = (bool) $company->invoice_number_include_year;
        $this->yearFirst = (bool) $company->invoice_number_year_first;
        $this->yearDigits = (int) $company->invoice_number_year_digits;
        $this->separator = $company->invoice_number_separator === '' ? 'none' : (string) $company->invoice_number_separator;
        $this->padding = (int) $company->invoice_number_padding;
        $this->prefix = (string) ($company->invoice_number_prefix ?? '');
    }

    public function save(): void
    {
        Gate::authorize('updateInvoiceSettings', $this->company);

        $validated = $this->validate([
            'includeYear' => 'boolean',
            'yearFirst' => 'boolean',
            'yearDigits' => 'required|integer|in:2,4',
            'separator' => 'required|string|in:/,-,.,none',
            'padding' => 'required|integer|min:1|max:6',
            'prefix' => 'nullable|string|max:10',
        ]);

        $this->company->update([
            'invoice_number_include_year' => $validated['includeYear'],
            'invoice_number_year_first' => $validated['yearFirst'],
            'invoice_number_year_digits' => $validated['yearDigits'],
            'invoice_number_separator' => $this->separatorValue(),
            'invoice_number_padding' => $validated['padding'],
            'invoice_number_prefix' => $validated['prefix'] !== '' ? $validated['prefix'] : null,
        ]);

        $this->saved = true;
    }

    private function separatorValue(): string
    {
        return $this->separator === 'none' ? '' : $this->separator;
    }

    /**
     * Прегледот се гради врз незачувана фирма и минува низ истата класа што
     * доделува вистински броеви. Второ, паралелно пресметување би можело да
     * покажува едно, а фактурата да носи друго.
     */
    private function previewCompany(): Company
    {
        $company = new Company;
        $company->invoice_number_prefix = $this->prefix !== '' ? $this->prefix : null;
        $company->invoice_number_include_year = $this->includeYear;
        $company->invoice_number_year_first = $this->yearFirst;
        $company->invoice_number_year_digits = $this->yearDigits;
        $company->invoice_number_separator = $this->separatorValue();
        $company->invoice_number_padding = $this->padding;

        return $company;
    }

    public function render()
    {
        return view('livewire.invoicing.invoice-settings', [
            'preview' => InvoiceNumber::format($this->previewCompany(), (int) now()->year, 1),
        ]);
    }
}
```

**Не пишувај `__invoke` на компонентата.** Рутите го повикуваат `[Class::class, '__invoke']`, но тој метод доаѓа од Livewire — `Livewire\Component` го зема преку `HandlesPageComponents` и токму тој ја претвора компонентата во цела страница. Сопствен `__invoke` би го пребришал и рутата би вратила празно. Ниту една од постоечките компоненти во `app/Livewire/Invoicing/` нема свој.

- [ ] **Step 6: Напиши го изгледот**

Создај `resources/views/livewire/invoicing/invoice-settings.blade.php`:

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">Формат на бројот на фактурата</h1>
    <p class="text-sm text-gray-500 mb-4">
        Промената важи за фактурите што ќе ги потврдите отсега. Веќе потврдените го задржуваат својот број.
    </p>

    <x-card class="max-w-2xl">
        <form wire:submit="save" class="grid gap-4">
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model.live="includeYear" class="rounded border-gray-300">
                <span class="text-sm text-gray-700">Прикажи ја годината во бројот</span>
            </label>

            @if ($includeYear)
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model.live="yearFirst" class="rounded border-gray-300">
                    <span class="text-sm text-gray-700">Годината оди прво (2026/1), инаку по бројот (1/2026)</span>
                </label>

                <div class="grid gap-3 md:grid-cols-2">
                    <div>
                        <x-input-label for="yearDigits" value="Цифри за годината" />
                        <select id="yearDigits" wire:model.live="yearDigits" class="w-full border-gray-300 rounded-md shadow-sm">
                            <option value="4">4 — 2026</option>
                            <option value="2">2 — 26</option>
                        </select>
                        @error('yearDigits') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-input-label for="separator" value="Разделник" />
                        <select id="separator" wire:model.live="separator" class="w-full border-gray-300 rounded-md shadow-sm">
                            <option value="/">Коса црта — /</option>
                            <option value="-">Цртичка — -</option>
                            <option value=".">Точка — .</option>
                            <option value="none">Без разделник</option>
                        </select>
                        @error('separator') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                </div>
            @endif

            <div class="grid gap-3 md:grid-cols-2">
                <div>
                    <x-input-label for="padding" value="Должина на бројот" />
                    <select id="padding" wire:model.live="padding" class="w-full border-gray-300 rounded-md shadow-sm">
                        <option value="1">1 — 1</option>
                        <option value="2">2 — 01</option>
                        <option value="3">3 — 001</option>
                        <option value="4">4 — 0001</option>
                        <option value="5">5 — 00001</option>
                        <option value="6">6 — 000001</option>
                    </select>
                    @error('padding') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="prefix" value="Префикс (незадолжително)" />
                    <x-text-input id="prefix" wire:model.live="prefix" class="w-full" placeholder="пр. ФА-" />
                    @error('prefix') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="bg-gray-50 rounded-lg px-4 py-3">
                <div class="text-xs uppercase tracking-wide text-gray-500">Вака ќе изгледа</div>
                <div class="text-xl font-semibold text-gray-800 mt-1">{{ $preview }}</div>
            </div>

            <div class="flex items-center gap-3">
                <x-primary-button type="submit">Зачувај</x-primary-button>
                @if ($saved)
                    <span class="text-sm text-green-700">Зачувано.</span>
                @endif
            </div>
        </form>
    </x-card>
</div>
```

- [ ] **Step 7: Додај ја ставката во двете менија**

Во `app/Support/Menu.php`, во групата `settings` за **правни лица** (околу линија 196), по ставката `Компанија`:

```php
                    ['label' => 'Фактурирање', 'url' => route('sales-invoices.settings', $company), 'pattern' => 'sales-invoices.settings', 'roles' => null, 'module' => CompanyModule::MATERIAL],
```

Во групата `settings` за **физички лица** (околу линија 240), по ставката `Профил` — таму `Излезни фактури` оди без модул, па и оваа оди без:

```php
                    ['label' => 'Фактурирање', 'url' => route('sales-invoices.settings', $company), 'pattern' => 'sales-invoices.settings', 'roles' => null],
```

- [ ] **Step 8: Пушти го тестот и провери дека поминува**

Run: `php artisan test tests/Feature/Invoicing/InvoiceSettingsTest.php`
Expected: PASS — 7 тестови

- [ ] **Step 9: Провери го менито и рутите**

Run: `php artisan test tests/Unit/Support/MenuTest.php tests/Feature/MenuByTypeTest.php tests/Feature/SidebarTest.php tests/Feature/InvoicingRoutesTest.php tests/Feature/CompanyPolicyTest.php tests/Feature/CompanyModuleAccessTest.php`
Expected: PASS. Ако некој тест брои ставки во групата ПОСТАВКИ, дополни го бројот — тоа е очекувана промена.

- [ ] **Step 10: Комит**

```bash
git add app/Livewire/Invoicing/InvoiceSettings.php resources/views/livewire/invoicing/invoice-settings.blade.php app/Policies/CompanyPolicy.php app/Support/Menu.php routes/web.php tests/Feature/Invoicing/InvoiceSettingsTest.php
git commit -m "feat: екран Поставки → Фактурирање со жив преглед на бројот

Клиентот сам си го поставува форматот; истиот екран им е достапен на
сметководител и админ. Способноста updateInvoiceSettings е поширока од
update намерно — не го отвора целиот профил на фирмата.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 6: Блок „Начин на плаќање" како уплатница

**Files:**
- Modify: `resources/views/pdf/sales-invoice.blade.php:40-42` (CSS), `:159-168` (блок)
- Test: `tests/Feature/SalesInvoicePdfTest.php` (дополнување)

**Interfaces:**
- Consumes: `SalesInvoice::formattedNumber()` од Task 2, `SalesInvoice::grandTotal()` (постои)

- [ ] **Step 1: Напиши го тестот што паѓа**

Додај ги на крајот од `tests/Feature/SalesInvoicePdfTest.php`, пред затворачката заграда:

```php
    public function test_the_payment_block_reads_like_a_payment_slip(): void
    {
        $company = Company::factory()->create(['name' => 'Fajnens Badi DOOEL']);
        $company->bankAccounts()->create([
            'bank_name' => 'Комерцијална банка',
            'account_number' => '300000000000123',
            'position' => 0,
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'confirmed',
            'fiscal_year' => 2026,
            'invoice_number' => 7,
            'invoice_number_formatted' => '2026/7',
        ]);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('Назив на примач', $html);
        $this->assertStringContainsString('Банка на примач', $html);
        $this->assertStringContainsString('Сметка', $html);
        $this->assertStringContainsString('Износ', $html);
        $this->assertStringContainsString('Цел на дознака', $html);
        $this->assertStringContainsString('Fajnens Badi DOOEL', $html);
        $this->assertStringContainsString('Комерцијална банка', $html);
        $this->assertStringContainsString('300000000000123', $html);
        // 1000.00 основа + 18% ДДВ = 1180.00
        $this->assertStringContainsString(\App\Support\Format::money('1180.00'), $html);
        $this->assertStringContainsString('2026/7', $html);
    }

    public function test_the_payment_block_uses_only_the_first_bank_account(): void
    {
        $company = Company::factory()->create();
        $company->bankAccounts()->create(['bank_name' => 'Прва банка', 'account_number' => '300000000000111', 'position' => 0]);
        $company->bankAccounts()->create(['bank_name' => 'Втора банка', 'account_number' => '300000000000222', 'position' => 1]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('Прва банка', $html);
        $this->assertStringNotContainsString('Втора банка', $html);
    }

    public function test_the_pdf_still_renders_without_a_bank_account(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('Нема внесена банкарска сметка.', $html);
        $this->assertStringContainsString('Цел на дознака', $html);
    }
```

- [ ] **Step 2: Пушти ги и провери дека паѓаат**

Run: `php artisan test tests/Feature/SalesInvoicePdfTest.php --filter=payment_slip`
Expected: FAIL — нема `Назив на примач` во HTML-от

- [ ] **Step 3: Додај CSS за табелата во блокот**

Во `resources/views/pdf/sales-invoice.blade.php`, веднаш по правилото `.pay-box h4 { … }` (околу линија 42):

```css
        table.pay-table { width: 100%; border-collapse: collapse; }
        table.pay-table td { padding: 1px 0; vertical-align: top; }
        td.pay-label { width: 108px; color: #6b7280; }
```

- [ ] **Step 4: Замени го содржината на блокот**

Замени го целото тело на `<div class="pay-box">` (`@forelse … @empty … @endforelse`, околу линии 161–167) со:

```blade
                        <h4>Начин на плаќање</h4>
                        @php $mainAccount = $company->bankAccounts->first(); @endphp
                        <table class="pay-table">
                            <tr>
                                <td class="pay-label">Назив на примач</td>
                                <td>{{ $company->name }}</td>
                            </tr>
                            @if ($mainAccount && $mainAccount->bank_name)
                                <tr>
                                    <td class="pay-label">Банка на примач</td>
                                    <td>{{ $mainAccount->bank_name }}</td>
                                </tr>
                            @endif
                            @if ($mainAccount && $mainAccount->account_number)
                                <tr>
                                    <td class="pay-label">Сметка</td>
                                    <td>{{ $mainAccount->account_number }}</td>
                                </tr>
                            @endif
                            <tr>
                                <td class="pay-label">Износ</td>
                                <td>{{ \App\Support\Format::money($invoice->grandTotal()) }}</td>
                            </tr>
                            <tr>
                                <td class="pay-label">Цел на дознака</td>
                                <td>{{ $invoice->formattedNumber() }}</td>
                            </tr>
                        </table>
                        @unless ($mainAccount)
                            <div class="muted" style="margin-top: 4px;">Нема внесена банкарска сметка.</div>
                        @endunless
```

Задржи го отворањето `<div class="pay-box">` и затворањето `</div>` како што се.

- [ ] **Step 5: Пушти ги тестовите и провери дека поминуваат**

Run: `php artisan test tests/Feature/SalesInvoicePdfTest.php`
Expected: PASS

- [ ] **Step 6: Комит**

```bash
git add resources/views/pdf/sales-invoice.blade.php tests/Feature/SalesInvoicePdfTest.php
git commit -m "feat: блокот за плаќање на фактурата е уплатница

Назив на примач, Банка, Сметка, Износ и Цел на дознака, од првата
банкарска сметка на фирмата. Дотогаш стоеше само гола листа со сметки.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 7: Колона „Износ на ДДВ" по ставка

**Files:**
- Modify: `resources/views/pdf/sales-invoice.blade.php:128-155`
- Test: `tests/Feature/SalesInvoicePdfTest.php` (дополнување)

**Interfaces:**
- Consumes: `SalesInvoiceLine::vatAmount(): string` (постои)

- [ ] **Step 1: Напиши го тестот што паѓа**

Додај ги на крајот од `tests/Feature/SalesInvoicePdfTest.php`:

```php
    public function test_it_shows_the_vat_amount_for_each_line(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Prva stavka', 'quantity' => '2', 'unit_price' => '500.00', 'vat_rate' => '18.00']);
        $invoice->lines()->create(['description' => 'Vtora stavka', 'quantity' => '1', 'unit_price' => '300.00', 'vat_rate' => '5.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('Износ на ДДВ', $html);
        // 2 * 500.00 = 1000.00 основа, 18% = 180.00
        $this->assertStringContainsString(\App\Support\Format::money('180.00'), $html);
        // 300.00 основа, 5% = 15.00
        $this->assertStringContainsString(\App\Support\Format::money('15.00'), $html);
    }

    public function test_a_company_outside_vat_gets_no_vat_amount_column(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => false]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringNotContainsString('Износ на ДДВ', $html);
    }
```

- [ ] **Step 2: Пушти ги и провери дека паѓаат**

Run: `php artisan test tests/Feature/SalesInvoicePdfTest.php --filter=vat_amount`
Expected: FAIL — нема `Износ на ДДВ` во HTML-от

- [ ] **Step 3: Додај ја колоната во заглавието и преработи ги ширините**

Во `resources/views/pdf/sales-invoice.blade.php`, во `<thead>` на `table.items`:

```blade
                <tr>
                    <th style="width: 22px;">Р.б.</th>
                    <th>Опис</th>
                    <th style="width: 42px;">Кол.</th>
                    <th style="width: 68px;">Ед. цена</th>
                    @if ($vatRegistered)
                        <th style="width: 62px;">ДДВ %</th>
                        <th style="width: 72px;">Износ на ДДВ</th>
                    @endif
                    <th style="width: 82px;">{{ $vatRegistered ? 'Вкупно со ДДВ' : 'Вкупно' }}</th>
                </tr>
```

- [ ] **Step 4: Додај ја ќелијата во телото**

Во `<tbody>`, внатре во `@if ($vatRegistered)` блокот, по ќелијата со процентот:

```blade
                        @if ($vatRegistered)
                            <td>{{ $line->vat_rate }}{{ $line->vat_treatment !== 'standard' ? ' ('.\App\Support\Format::vatTreatment($line->vat_treatment).')' : '' }}</td>
                            <td>{{ \App\Support\Format::money($line->vatAmount()) }}</td>
                        @endif
```

- [ ] **Step 5: Пушти ги тестовите и провери дека поминуваат**

Run: `php artisan test tests/Feature/SalesInvoicePdfTest.php`
Expected: PASS

- [ ] **Step 6: Комит**

```bash
git add resources/views/pdf/sales-invoice.blade.php tests/Feature/SalesInvoicePdfTest.php
git commit -m "feat: износ на ДДВ по ставка на фактурата

Пресметката веќе постоеше во SalesInvoiceLine::vatAmount(), само не се
прикажуваше. Ширините на колоните преработени за да собере седмата.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 8: Линии за потпис

**Files:**
- Modify: `resources/views/pdf/sales-invoice.blade.php` (CSS + крај на `.content`)
- Test: `tests/Feature/SalesInvoicePdfTest.php` (дополнување)

**Interfaces:** нема нови

- [ ] **Step 1: Напиши го тестот што паѓа**

Додај го на крајот од `tests/Feature/SalesInvoicePdfTest.php`:

```php
    public function test_it_prints_signature_lines_for_both_sides(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringContainsString('ОВЛАСТЕНО ЛИЦЕ', $html);
        $this->assertStringContainsString('ПРИМИЛ', $html);
    }

    public function test_signature_lines_appear_even_when_there_is_no_footnote(): void
    {
        $company = Company::factory()->create([
            'is_vat_registered' => true,
            'invoice_footer_note' => null,
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $html = view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();

        $this->assertStringNotContainsString('footnotes', $html);
        $this->assertStringContainsString('ОВЛАСТЕНО ЛИЦЕ', $html);
    }
```

- [ ] **Step 2: Пушти ги и провери дека паѓаат**

Run: `php artisan test tests/Feature/SalesInvoicePdfTest.php --filter=signature`
Expected: FAIL — нема `ОВЛАСТЕНО ЛИЦЕ` во HTML-от

- [ ] **Step 3: Додај CSS**

Во `resources/views/pdf/sales-invoice.blade.php`, во `<style>`, **надвор** од условните `@if` блокови — веднаш по правилото `.totals-box tr.grand td { … }`:

```css
        table.signatures { width: 100%; border-collapse: collapse; margin-top: 34px; page-break-inside: avoid; }
        table.signatures td { width: 50%; padding: 0 24px; vertical-align: bottom; }
        .sig-line { border-top: 1px solid #9ca3af; height: 0; font-size: 0; }
        .sig-label { text-align: center; font-size: 9px; color: #6b7280; margin-top: 4px; letter-spacing: .05em; }
```

- [ ] **Step 4: Додај го блокот**

Во `resources/views/pdf/sales-invoice.blade.php`, по `@endif` што го затвора блокот со фусноти и **пред** затворањето на `</div>` од `.content`:

```blade
        <table class="signatures">
            <tr>
                <td>
                    <div class="sig-line"></div>
                    <div class="sig-label">ОВЛАСТЕНО ЛИЦЕ</div>
                </td>
                <td>
                    <div class="sig-line"></div>
                    <div class="sig-label">ПРИМИЛ</div>
                </td>
            </tr>
        </table>
```

- [ ] **Step 5: Пушти ги тестовите и провери дека поминуваат**

Run: `php artisan test tests/Feature/SalesInvoicePdfTest.php`
Expected: PASS

- [ ] **Step 6: Погледни го вистинскиот PDF**

dompdf ги распоредува табелите поинаку од прелистувач, а тестот проверува само присуство на текст, не изглед. Направи PDF со вистински податоци и погледни го:

```bash
php artisan tinker --execute="\$i = App\Models\SalesInvoice::whereNotNull('invoice_number')->latest('id')->first(); file_put_contents(storage_path('app/proba-faktura.pdf'), Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.sales-invoice', ['invoice' => \$i->load(['lines', 'partner', 'company.bankAccounts'])])->output());"
```

Провери: линиите за потпис не се раскинати преку две страници, седумте колони собираат на ширина без прелевање, и блокот за плаќање не се преклопува со кутијата со вкупни износи. Ако нешто се прелева, стесни ги `Кол.` и `Ед. цена` уште по 4 пиксели и повтори.

- [ ] **Step 7: Комит**

```bash
git add resources/views/pdf/sales-invoice.blade.php tests/Feature/SalesInvoicePdfTest.php
git commit -m "feat: линии за потпис ОВЛАСТЕНО ЛИЦЕ и ПРИМИЛ на фактурата

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Пред спојување

- [ ] **Пушти ја целата серија**

Run: `composer test`
Expected: PASS. Основата пред оваа гранка е 1370 тестови; овој план додава околу 30.

- [ ] **Ако некој постоечки тест падне**, поправи ја причината, не тврдењето — освен во двата документирани случаи: `docNumber` кон УЈП што сега носи коса црта наместо цртичка, и бројот на ставки во групата ПОСТАВКИ во менито.

- [ ] **Побарај преглед на целата гранка** пред спојување, не само по задача. Во претходните фази прегледот на целата гранка фаќаше сериозни пропусти што ниту еден преглед по задача не ги видел, додека серијата беше зелена.

---

## Што овој план намерно не прави

- Не воведува фуснота по конкретна фактура — постоечката по фирма останува единствената.
- Не додава поле „главна сметка" — редоследот во профилот на фирмата решава.
- Не дозволува рачно внесување на бројот на фактурата.
- Не го менува изгледот на влезните фактури.
- Не ги повторува броевите преку повеќе фирми.
