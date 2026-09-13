# Девизна фактура на англиски за физичко лице — план за изведба

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Физичко лице да може да издаде излезна фактура на англиски и целосно во EUR, USD, GBP или CHF, без ништо да се смени кај правните лица.

**Architecture:** Три нови колони на `sales_invoices` (`language`, `currency`, `exchange_rate`), по две на `partners` и `company_bank_accounts`. Јазикот е backed enum `App\Support\InvoiceLanguage` што носи и речник на етикети и форматирање на броеви и датуми — PDF-темплејтот останува **еден**, само зборовите и форматот поминуваат низ него. Книжењето се претвора во денари по курсот на фактурата во `SalesInvoiceService`.

**Tech Stack:** Laravel v13.20.0, PHP 8.3, Livewire 3, dompdf 3.1.6 (barryvdh/laravel-dompdf), PHPUnit, bcmath, Tailwind.

## Global Constraints

- **Важи само за `CompanyType::INDIVIDUAL`.** Кај правно лице ниту една форма не смее да добие ново видливо поле, и серверот мора да ја присилува MKD без оглед на тоа што пристигнало во барањето.
- **Македонскиот PDF мора да остане непроменет.** Секоја задача што го допира `resources/views/pdf/sales-invoice.blade.php` завршува со доказ дека денарска фактура на македонски рендерира идентична содржина како пред задачата.
- **`@script` блоковите во `sales-invoice-index.blade.php` и `sales-invoice-show.blade.php` не се допираат.** Ниту еден знак. Тие го носат живиот тек за потпишување со хардверски токен и немаат свое тестирање.
- **Дозволени валути:** `MKD`, `EUR`, `USD`, `GBP`, `CHF` — точно овие пет, во `SalesInvoice::CURRENCIES`.
- **Стандардни вредности:** `language='mk'`, `currency='MKD'`, `exchange_rate='1.000000'`. Мора да стојат и во `$attributes` на моделот, не само во миграцијата — колона со `default` во базата **не** полни свежо создаден објект во меморија.
- **Курсот е рачно поле со копче „НБРМ".** Се повикува **постоечкиот** `App\Services\ExchangeRateService` — не се пишува нов, не се допира постоечкиот. Полето секогаш останува препишливо; паднат НБРМ не смее да блокира фактура.
- **Девизниот износ се запишува и во главната книга.** `journal_entry_lines` веќе има `currency_code`, `exchange_rate` и `foreign_amount` и формата за рачно книжење веќе ги полни. Девизната фактура го користи истиот образец, не измислува свој.
- **Курсни разлики не се пресметуваат** — наплатата се книжи по курсот на фактурата.
- **Валидација во контролери што се тестираат со `postJson()` оди со рачни `in_array()`,** не со `Rule::in()`. Во Livewire компоненти `Rule::in()` е во ред и веќе се користи.
- **Серија:** `php artisan test` директно, никогаш `composer test` (паѓа на лимитот од 300s а враќа exit 0). Целата серија трае ~8,5 минути — во текот на задачите пуштај само засегнатиот тест-фајл.
- Коментарите во кодот се на македонски, како во остатокот од проектот. Комит-пораките се на македонски.

## Структура на фајлови

**Нови:**

| Фајл | Одговорност |
|---|---|
| `app/Support/InvoiceLanguage.php` | Backed enum `mk`/`en`. Речник на етикети за PDF-от + форматирање на износ и датум по јазик. Единственото место што знае како фактура „звучи" на даден јазик. |
| `database/migrations/2026_09_13_100100_add_language_and_currency_to_sales_invoices_table.php` | Трите колони на фактурата. |
| `database/migrations/2026_09_13_100200_add_invoice_language_and_country_to_partners_table.php` | Двете колони на кооперантот. |
| `database/migrations/2026_09_13_100300_add_iban_and_swift_to_company_bank_accounts_table.php` | Двете колони на банкарската сметка. |
| `tests/Unit/InvoiceLanguageTest.php` | Чисти тестови за речникот и форматирањето. |
| `tests/Feature/ForeignCurrencyInvoiceTest.php` | Форма, книжење и е-Фактура заклучување. |
| `tests/Feature/SalesInvoiceEnglishPdfTest.php` | Англискиот PDF + доказ дека македонскиот е непроменет. |

**Се менуваат:**

| Фајл | Што |
|---|---|
| `app/Models/SalesInvoice.php` | `CURRENCIES`, `$fillable`, `$attributes`, каст на `language` и `exchange_rate`, `isForeignCurrency()`. |
| `app/Models/Partner.php` | `$fillable`, `$attributes`, каст на `invoice_language`. |
| `app/Models/CompanyBankAccount.php` | `$fillable`. |
| `app/Livewire/Invoicing/SalesInvoiceForm.php` | Валута, курс, наследување на јазикот од кооперантот. |
| `resources/views/livewire/invoicing/sales-invoice-form.blade.php` | Две нови полиња, видливи само кај физичко лице. |
| `app/Livewire/PartnerShow.php` + `resources/views/livewire/partner-show.blade.php` | Јазик на фактура и држава. |
| `app/Livewire/CompanyProfile.php` + `resources/views/livewire/company-profile.blade.php` | IBAN и SWIFT на секој ред од банкарските сметки. |
| `resources/views/pdf/sales-invoice.blade.php` | Секој видлив збор оди низ `InvoiceLanguage`. |
| `app/Services/Invoicing/SalesInvoiceService.php` | Претворање во денари при потврда и при наплата. |
| `app/Http/Controllers/EfakturaSendController.php` | Заклучување на девизна фактура кон УЈП. |

---

### Task 1: Схема и модели

**Files:**
- Create: `database/migrations/2026_09_13_100100_add_language_and_currency_to_sales_invoices_table.php`
- Create: `database/migrations/2026_09_13_100200_add_invoice_language_and_country_to_partners_table.php`
- Create: `database/migrations/2026_09_13_100300_add_iban_and_swift_to_company_bank_accounts_table.php`
- Modify: `app/Models/SalesInvoice.php`
- Modify: `app/Models/Partner.php`
- Modify: `app/Models/CompanyBankAccount.php`
- Test: `tests/Feature/ForeignCurrencyInvoiceTest.php`

**Interfaces:**
- Produces: `SalesInvoice::CURRENCIES` (`array<int,string>`), `SalesInvoice::isForeignCurrency(): bool`, колони `sales_invoices.language|currency|exchange_rate`, `partners.invoice_language|country`, `company_bank_accounts.iban|swift`.
- Consumes: ништо од претходни задачи.

**Забелешка:** кастот на `language` во `InvoiceLanguage::class` **не** се додава во оваа задача — enum-от се создава во Task 2. Тука колоната е обичен стринг. Task 2 го додава кастот.

- [ ] **Step 1: Напиши го тестот што паѓа**

Создај `tests/Feature/ForeignCurrencyInvoiceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ForeignCurrencyInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
    }

    public function test_a_fresh_invoice_defaults_to_macedonian_denars_in_memory(): void
    {
        // Колона со default во базата НЕ полни свеж објект во меморија —
        // затоа стандардните вредности мора да стојат и во $attributes.
        $invoice = new SalesInvoice;

        $this->assertSame('mk', $invoice->language);
        $this->assertSame('MKD', $invoice->currency);
        $this->assertSame('1.000000', (string) $invoice->exchange_rate);
        $this->assertFalse($invoice->isForeignCurrency());
    }

    public function test_an_invoice_in_a_foreign_currency_reports_itself_as_foreign(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'currency' => 'EUR',
            'exchange_rate' => '61.500000',
        ]);

        $this->assertTrue($invoice->fresh()->isForeignCurrency());
        $this->assertSame('61.500000', (string) $invoice->fresh()->exchange_rate);
    }

    public function test_the_five_allowed_currencies_are_exactly_these(): void
    {
        $this->assertSame(['MKD', 'EUR', 'USD', 'GBP', 'CHF'], SalesInvoice::CURRENCIES);
    }

    public function test_a_partner_defaults_to_macedonian_and_can_store_a_country(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create([
            'invoice_language' => 'en',
            'country' => 'Germany',
        ]);

        $this->assertSame('en', $partner->fresh()->invoice_language);
        $this->assertSame('Germany', $partner->fresh()->country);
        $this->assertSame('mk', (new Partner)->invoice_language);
    }

    public function test_a_bank_account_can_store_an_iban_and_a_swift(): void
    {
        $company = Company::factory()->create();
        $account = $company->bankAccounts()->create([
            'bank_name' => 'Komercijalna',
            'account_number' => '300000000000123',
            'iban' => 'MK07300701104789126',
            'swift' => 'KOBSMK2X',
            'position' => 0,
        ]);

        $this->assertSame('MK07300701104789126', $account->fresh()->iban);
        $this->assertSame('KOBSMK2X', $account->fresh()->swift);
    }

    public function test_existing_invoices_are_untouched_by_the_migration(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);

        $this->assertSame('MKD', $invoice->fresh()->currency);
        $this->assertSame('mk', $invoice->fresh()->language);
        $this->assertFalse($invoice->fresh()->isForeignCurrency());
    }
}
```

- [ ] **Step 2: Пушти го и потврди дека паѓа**

Run: `php artisan test tests/Feature/ForeignCurrencyInvoiceTest.php`
Expected: FAIL — `Undefined constant App\Models\SalesInvoice::CURRENCIES` и непознати колони.

- [ ] **Step 3: Напиши ги трите миграции**

`database/migrations/2026_09_13_100100_add_language_and_currency_to_sales_invoices_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            // Стандардните вредности ја репродуцираат денешната состојба, па
            // ниту една постоечка фактура не си го менува ниту изгледот ниту
            // книжењето: сите остануваат денарски и на македонски.
            $table->string('language', 2)->default('mk')->after('notes');
            $table->string('currency', 3)->default('MKD')->after('language');
            $table->decimal('exchange_rate', 12, 6)->default(1)->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn(['language', 'currency', 'exchange_rate']);
        });
    }
};
```

`database/migrations/2026_09_13_100200_add_invoice_language_and_country_to_partners_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('invoice_language', 2)->default('mk')->after('city');
            $table->string('country')->nullable()->after('invoice_language');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['invoice_language', 'country']);
        });
    }
};
```

`database/migrations/2026_09_13_100300_add_iban_and_swift_to_company_bank_accounts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_bank_accounts', function (Blueprint $table) {
            // account_number останува како што е — кај дел од клиентите таму
            // веќе стои IBAN. Ова поле е за случајот кога двете се разликуваат.
            $table->string('iban')->nullable()->after('account_number');
            $table->string('swift', 20)->nullable()->after('iban');
        });
    }

    public function down(): void
    {
        Schema::table('company_bank_accounts', function (Blueprint $table) {
            $table->dropColumn(['iban', 'swift']);
        });
    }
};
```

- [ ] **Step 4: Дополни ги трите модели**

Во `app/Models/SalesInvoice.php`, веднаш под `EFAKTURA_ACCEPTED_STATUS_CODES`, додај:

```php
    /** Валутите во кои може да се издаде фактура. Точно овие пет. */
    public const CURRENCIES = ['MKD', 'EUR', 'USD', 'GBP', 'CHF'];
```

Во `$fillable`, на крајот од редот со `'status', 'payment_type_code', ...`, додај ги трите нови имиња:

```php
    protected $fillable = [
        'company_id', 'partner_id', 'warehouse_id', 'journal_entry_id',
        'fiscal_year', 'invoice_number', 'invoice_number_formatted', 'invoice_date', 'due_date',
        'status', 'payment_type_code', 'sent_at', 'notes', 'created_by',
        'language', 'currency', 'exchange_rate',
        'efaktura_status', 'efaktura_doc_id', 'efaktura_sent_at', 'efaktura_error',
        'efaktura_ujp_status_code', 'efaktura_ujp_status_name', 'efaktura_pdf_path',
    ];
```

Веднаш под `$fillable` додај го блокот со стандардни вредности. **Внимание:** тука одат само трите нови клучеви. `status`, `payment_type_code` и `efaktura_status` намерно се изоставени — тие веќе работат како што работат и додавање овде би променило постоечко однесување.

```php
    /**
     * Колона со `default` во базата НЕ полни свежо создаден објект во меморија.
     * Формата и сервисот читаат од објектот пред тој да биде зачуван, па
     * стандардните вредности мора да стојат и овде.
     */
    protected $attributes = [
        'language' => 'mk',
        'currency' => 'MKD',
        'exchange_rate' => '1.000000',
    ];
```

Во `casts()` додај го курсот (јазикот доаѓа во Task 2):

```php
    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'sent_at' => 'datetime',
            'efaktura_sent_at' => 'datetime',
            'exchange_rate' => 'decimal:6',
        ];
    }
```

Под `isEfakturaAccepted()` додај:

```php
    /** Дали фактурата е во странска валута, т.е. дали курсот воопшто значи нешто. */
    public function isForeignCurrency(): bool
    {
        return $this->currency !== 'MKD';
    }
```

Во `app/Models/Partner.php` замени го `$fillable` и додај `$attributes`:

```php
    protected $fillable = [
        'company_id', 'name', 'type', 'tax_id', 'registration_number',
        'director_name', 'is_vat_registered', 'vat_number',
        'email', 'phone', 'address', 'street_address', 'street_number', 'postal_code', 'city',
        'invoice_language', 'country',
    ];

    protected $attributes = [
        'invoice_language' => 'mk',
    ];
```

Во `app/Models/CompanyBankAccount.php`:

```php
    protected $fillable = ['company_id', 'bank_name', 'account_number', 'iban', 'swift', 'position'];
```

- [ ] **Step 5: Пушти го тестот и потврди дека поминува**

Run: `php artisan test tests/Feature/ForeignCurrencyInvoiceTest.php`
Expected: PASS — 6 тестови.

- [ ] **Step 6: Потврди дека постоечките тестови за фактури не се расипани**

Run: `php artisan test tests/Feature/SalesInvoiceFormTest.php tests/Feature/SalesInvoicePdfTest.php tests/Feature/SalesInvoiceShowTest.php`
Expected: PASS, ист број тестови како пред задачата.

- [ ] **Step 7: Комит**

```bash
git add database/migrations app/Models tests/Feature/ForeignCurrencyInvoiceTest.php
git commit -m "feat: колони за јазик, валута и курс на фактура, IBAN и SWIFT на сметка"
```

---

### Task 2: `InvoiceLanguage` — речник и форматирање

**Files:**
- Create: `app/Support/InvoiceLanguage.php`
- Create: `tests/Unit/InvoiceLanguageTest.php`
- Modify: `app/Models/SalesInvoice.php` (каст на `language`)
- Modify: `app/Models/Partner.php` (каст на `invoice_language`)
- Modify: `tests/Feature/ForeignCurrencyInvoiceTest.php` (два теста веќе очекуваат стринг, сега добиваат enum)

**Interfaces:**
- Consumes: колоните од Task 1.
- Produces: `App\Support\InvoiceLanguage` (backed enum, случаи `MK='mk'` и `EN='en'`) со:
  - `label(): string`
  - `t(string $key): string`
  - `money(string|float|int $amount, string $currency = 'MKD'): string`
  - `date(mixed $value): string`
  - `vatTreatment(string $treatment): string`
  - По кастот, `$invoice->language` и `$partner->invoice_language` враќаат **`InvoiceLanguage` инстанца**, не стринг. Секоја подоцнежна задача се потпира на тоа.

- [ ] **Step 1: Напиши го тестот што паѓа**

Создај `tests/Unit/InvoiceLanguageTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\Format;
use App\Support\InvoiceLanguage;
use PHPUnit\Framework\TestCase;

class InvoiceLanguageTest extends TestCase
{
    public function test_macedonian_money_is_byte_identical_to_the_existing_formatter(): void
    {
        // Оваа еднаквост е единствената причина македонската фактура да остане
        // непроменета. Ако падне, печатената денарска фактура се смени.
        $this->assertSame(Format::money('1234.5'), InvoiceLanguage::MK->money('1234.5', 'MKD'));
        $this->assertSame('1.234,50 ден', InvoiceLanguage::MK->money('1234.5', 'MKD'));
    }

    public function test_macedonian_money_uses_the_currency_code_when_it_is_not_denars(): void
    {
        $this->assertSame('1.234,50 EUR', InvoiceLanguage::MK->money('1234.5', 'EUR'));
    }

    public function test_english_money_uses_english_separators_and_the_currency_code(): void
    {
        $this->assertSame('1,234.50 EUR', InvoiceLanguage::EN->money('1234.5', 'EUR'));
        $this->assertSame('0.00 USD', InvoiceLanguage::EN->money('0', 'USD'));
        $this->assertSame('1,000,000.00 CHF', InvoiceLanguage::EN->money('1000000', 'CHF'));
    }

    public function test_macedonian_date_is_byte_identical_to_the_existing_formatter(): void
    {
        $this->assertSame(Format::date('2026-09-13'), InvoiceLanguage::MK->date('2026-09-13'));
        $this->assertSame('13.09.2026', InvoiceLanguage::MK->date('2026-09-13'));
    }

    public function test_english_date_spells_the_month_so_it_cannot_be_misread(): void
    {
        // 13.09.2026 американски клиент го чита како 9 септември. Затоа месецот
        // се пишува со букви.
        $this->assertSame('13 Sep 2026', InvoiceLanguage::EN->date('2026-09-13'));
        $this->assertSame('1 Jan 2027', InvoiceLanguage::EN->date('2027-01-01'));
    }

    public function test_the_dictionary_translates_the_invoice_headings(): void
    {
        $this->assertSame('ФАКТУРА', InvoiceLanguage::MK->t('invoice'));
        $this->assertSame('INVOICE', InvoiceLanguage::EN->t('invoice'));
        $this->assertSame('Издавач', InvoiceLanguage::MK->t('seller'));
        $this->assertSame('Seller', InvoiceLanguage::EN->t('seller'));
        $this->assertSame('AUTHORISED SIGNATURE', InvoiceLanguage::EN->t('signature_issuer'));
        $this->assertSame('Not registered for VAT.', InvoiceLanguage::EN->t('not_vat_registered'));
    }

    public function test_an_unknown_key_returns_the_key_itself_instead_of_blowing_up(): void
    {
        // Полупразна фактура е полоша од фактура со чуден збор на неа, но
        // празна ќелија на печатена хартија е најлоша — затоа fallback, не грешка.
        $this->assertSame('nema_takov_kluc', InvoiceLanguage::EN->t('nema_takov_kluc'));
    }

    public function test_macedonian_vat_treatment_is_byte_identical_to_the_existing_formatter(): void
    {
        foreach (['standard', 'export', 'exempt_with_credit', 'exempt_without_credit'] as $treatment) {
            $this->assertSame(
                Format::vatTreatment($treatment),
                InvoiceLanguage::MK->vatTreatment($treatment)
            );
        }
    }

    public function test_english_vat_treatment_is_translated(): void
    {
        $this->assertSame('Export', InvoiceLanguage::EN->vatTreatment('export'));
        $this->assertSame('exempt with input tax credit', InvoiceLanguage::EN->vatTreatment('exempt_with_credit'));
        $this->assertSame('exempt without input tax credit', InvoiceLanguage::EN->vatTreatment('exempt_without_credit'));
    }

    public function test_labels_are_what_the_user_picks_from(): void
    {
        $this->assertSame('Македонски', InvoiceLanguage::MK->label());
        $this->assertSame('English', InvoiceLanguage::EN->label());
    }
}
```

- [ ] **Step 2: Пушти го и потврди дека паѓа**

Run: `php artisan test tests/Unit/InvoiceLanguageTest.php`
Expected: FAIL — `Class "App\Support\InvoiceLanguage" not found`.

- [ ] **Step 3: Создај го enum-от**

`app/Support/InvoiceLanguage.php`:

```php
<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * На кој јазик „звучи" една фактура.
 *
 * Еден темплејт печати и македонска и англиска фактура. Распоредот е ист —
 * уплатницата, ДДВ по ставка, линиите за потпис — менуваат се само зборовите,
 * разделниците на броевите и обликот на датумот. Сите три живеат овде, за да
 * не се раздвои темплејтот на две копии што потоа се поправаат двапати.
 *
 * Македонската страна намерно ги повикува постоечките `Format::` методи
 * наместо да ги преповторува: така печатената денарска фактура е докажливо
 * непроменета, а не „изгледа исто".
 */
enum InvoiceLanguage: string
{
    case MK = 'mk';
    case EN = 'en';

    public function label(): string
    {
        return match ($this) {
            self::MK => 'Македонски',
            self::EN => 'English',
        };
    }

    /**
     * Еден збор од фактурата. Непознат клуч се враќа каков што е — празна
     * ќелија на испечатена хартија е полоша од чуден збор.
     */
    public function t(string $key): string
    {
        return self::DICTIONARY[$key][$this->value] ?? $key;
    }

    public function money(string|float|int $amount, string $currency = 'MKD'): string
    {
        if ($this === self::MK) {
            return Format::money($amount, $currency === 'MKD' ? 'ден' : $currency);
        }

        return number_format((float) $amount, 2, '.', ',').' '.$currency;
    }

    public function date(mixed $value): string
    {
        if ($this === self::MK) {
            return Format::date($value);
        }

        // „13 Sep 2026“, не „13.09.2026“ — американски клиент второто го чита
        // како 9 септември.
        return Carbon::parse($value)->format('j M Y');
    }

    public function vatTreatment(string $treatment): string
    {
        if ($this === self::MK) {
            return Format::vatTreatment($treatment);
        }

        return match ($treatment) {
            'standard' => 'Standard',
            'export' => 'Export',
            'exempt_with_credit' => 'exempt with input tax credit',
            'exempt_without_credit' => 'exempt without input tax credit',
            default => str_replace('_', ' ', $treatment),
        };
    }

    private const DICTIONARY = [
        'invoice' => ['mk' => 'ФАКТУРА', 'en' => 'INVOICE'],
        'invoice_date' => ['mk' => 'Датум на фактура', 'en' => 'Invoice date'],
        'due_date' => ['mk' => 'Датум на доспевање', 'en' => 'Due date'],
        'seller' => ['mk' => 'Издавач', 'en' => 'Seller'],
        'buyer' => ['mk' => 'Купувач', 'en' => 'Buyer'],
        'tax_id' => ['mk' => 'ЕДБ', 'en' => 'Tax no.'],
        'registration_number' => ['mk' => 'ЕМБС', 'en' => 'Reg. no.'],
        'line_no' => ['mk' => 'Р.б.', 'en' => 'No.'],
        'description' => ['mk' => 'Опис', 'en' => 'Description'],
        'quantity' => ['mk' => 'Кол.', 'en' => 'Qty'],
        'unit_price' => ['mk' => 'Ед. цена', 'en' => 'Unit price'],
        'vat_percent' => ['mk' => 'ДДВ %', 'en' => 'VAT %'],
        'vat_amount' => ['mk' => 'Износ на ДДВ', 'en' => 'VAT amount'],
        'total_with_vat' => ['mk' => 'Вкупно со ДДВ', 'en' => 'Total incl. VAT'],
        'total' => ['mk' => 'Вкупно', 'en' => 'Total'],
        'payment_details' => ['mk' => 'Начин на плаќање', 'en' => 'Payment details'],
        'beneficiary' => ['mk' => 'Назив на примач', 'en' => 'Beneficiary'],
        'beneficiary_bank' => ['mk' => 'Банка на примач', 'en' => 'Bank'],
        'account' => ['mk' => 'Сметка', 'en' => 'Account'],
        'iban' => ['mk' => 'IBAN', 'en' => 'IBAN'],
        'swift' => ['mk' => 'SWIFT', 'en' => 'SWIFT'],
        'amount' => ['mk' => 'Износ', 'en' => 'Amount'],
        'payment_reference' => ['mk' => 'Цел на дознака', 'en' => 'Payment reference'],
        'no_bank_account' => ['mk' => 'Нема внесена банкарска сметка.', 'en' => 'No bank account on file.'],
        'subtotal' => ['mk' => 'Основа', 'en' => 'Subtotal'],
        'vat' => ['mk' => 'ДДВ', 'en' => 'VAT'],
        'balance_due' => ['mk' => 'За доплата', 'en' => 'Balance due'],
        'signature_issuer' => ['mk' => 'ОВЛАСТЕНО ЛИЦЕ', 'en' => 'AUTHORISED SIGNATURE'],
        'signature_receiver' => ['mk' => 'ПРИМИЛ', 'en' => 'RECEIVED BY'],
        'not_vat_registered' => ['mk' => 'Фирмава не е ДДВ обврзник.', 'en' => 'Not registered for VAT.'],
        'seller_country' => ['mk' => 'Северна Македонија', 'en' => 'North Macedonia'],
    ];
}
```

- [ ] **Step 4: Пушти го тестот и потврди дека поминува**

Run: `php artisan test tests/Unit/InvoiceLanguageTest.php`
Expected: PASS — 10 тестови.

- [ ] **Step 5: Додај го кастот на двата модела**

Во `app/Models/SalesInvoice.php`, `casts()`:

```php
    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'sent_at' => 'datetime',
            'efaktura_sent_at' => 'datetime',
            'exchange_rate' => 'decimal:6',
            'language' => \App\Support\InvoiceLanguage::class,
        ];
    }
```

Во `app/Models/Partner.php`:

```php
    protected function casts(): array
    {
        return [
            'is_vat_registered' => 'boolean',
            'invoice_language' => \App\Support\InvoiceLanguage::class,
        ];
    }
```

- [ ] **Step 6: Поправи ги двата теста од Task 1 што сега добиваат enum**

Во `tests/Feature/ForeignCurrencyInvoiceTest.php` замени ги двете тврдења за јазик:

```php
        $this->assertSame(\App\Support\InvoiceLanguage::MK, $invoice->language);
```

(во `test_a_fresh_invoice_defaults_to_macedonian_denars_in_memory`),

```php
        $this->assertSame(\App\Support\InvoiceLanguage::EN, $partner->fresh()->invoice_language);
        ...
        $this->assertSame(\App\Support\InvoiceLanguage::MK, (new Partner)->invoice_language);
```

(во `test_a_partner_defaults_to_macedonian_and_can_store_a_country`), и

```php
        $this->assertSame(\App\Support\InvoiceLanguage::MK, $invoice->fresh()->language);
```

(во `test_existing_invoices_are_untouched_by_the_migration`).

- [ ] **Step 7: Пушти ги двата фајла**

Run: `php artisan test tests/Unit/InvoiceLanguageTest.php tests/Feature/ForeignCurrencyInvoiceTest.php`
Expected: PASS.

- [ ] **Step 8: Комит**

```bash
git add app/Support/InvoiceLanguage.php app/Models tests/Unit/InvoiceLanguageTest.php tests/Feature/ForeignCurrencyInvoiceTest.php
git commit -m "feat: InvoiceLanguage — речник и форматирање на фактура по јазик"
```

---

### Task 3: Јазик и држава кај кооперантот

**Files:**
- Modify: `app/Livewire/PartnerShow.php`
- Modify: `resources/views/livewire/partner-show.blade.php`
- Test: `tests/Feature/ForeignCurrencyInvoiceTest.php` (се додаваат тестови)

**Interfaces:**
- Consumes: `partners.invoice_language`, `partners.country` (Task 1), `InvoiceLanguage` (Task 2).
- Produces: кооперант со зачуван `invoice_language`, што Task 5 го чита при создавање нацрт.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Додај ги на крајот од `tests/Feature/ForeignCurrencyInvoiceTest.php`, пред затворачката загради:

```php
    public function test_an_individual_profile_can_set_the_partner_invoice_language(): void
    {
        $company = Company::factory()->create(['type' => 'individual']);
        $partner = Partner::factory()->for($company)->create(['name' => 'Acme Ltd']);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->call('edit')
            ->set('editInvoiceLanguage', 'en')
            ->set('editCountry', 'Germany')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(\App\Support\InvoiceLanguage::EN, $partner->fresh()->invoice_language);
        $this->assertSame('Germany', $partner->fresh()->country);
    }

    public function test_a_legal_entity_never_sees_the_invoice_language_field(): void
    {
        $company = Company::factory()->create(['type' => 'legal']);
        $partner = Partner::factory()->for($company)->create();
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->call('edit')
            ->assertDontSee('Јазик на фактура');
    }

    public function test_a_legal_entity_cannot_force_an_english_partner_through_the_wire(): void
    {
        // Скриено поле во Blade не е заклучување. Серверот мора да одбие.
        $company = Company::factory()->create(['type' => 'legal']);
        $partner = Partner::factory()->for($company)->create();
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->call('edit')
            ->set('editInvoiceLanguage', 'en')
            ->call('save');

        $this->assertSame(\App\Support\InvoiceLanguage::MK, $partner->fresh()->invoice_language);
    }

    public function test_the_country_field_is_available_to_a_legal_entity_too(): void
    {
        // Државата е обична адресна податока, не дел од девизната гранка.
        $company = Company::factory()->create(['type' => 'legal']);
        $partner = Partner::factory()->for($company)->create();
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->call('edit')
            ->set('editCountry', 'Србија')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Србија', $partner->fresh()->country);
    }
```

- [ ] **Step 2: Пушти и потврди дека паѓа**

Run: `php artisan test tests/Feature/ForeignCurrencyInvoiceTest.php`
Expected: FAIL — `Property [$editInvoiceLanguage] not found`.

- [ ] **Step 3: Дополни ја компонентата**

Во `app/Livewire/PartnerShow.php`, до `public string $editCity = '';` додај:

```php
    public string $editInvoiceLanguage = 'mk';

    public string $editCountry = '';
```

Во методот што ги полни полињата при `edit()` (таму каде се поставува `$this->editCity`), додај:

```php
        $this->editInvoiceLanguage = $this->partner->invoice_language->value;
        $this->editCountry = (string) $this->partner->country;
```

Во `save()`, во низата за валидација, по `'editCity' => 'nullable|string|max:255',`:

```php
            'editInvoiceLanguage' => ['required', Rule::in(['mk', 'en'])],
            'editCountry' => 'nullable|string|max:255',
```

Во `save()`, веднаш под `$isLegalEntity = ...`, додај го заклучувањето по тип на фирма:

```php
        // Англиска фактура важи само за физичко лице. Скриено поле во Blade не
        // е заклучување — тоа се прави овде.
        $invoiceLanguage = $this->partner->company->type->isIndividual()
            ? $validated['editInvoiceLanguage']
            : 'mk';
```

Во `$this->partner->update([...])`, по редот со `'city' => ...`:

```php
                'invoice_language' => $invoiceLanguage,
                'country' => $validated['editCountry'] ?: null,
```

- [ ] **Step 4: Дополни го Blade-от**

Во `resources/views/livewire/partner-show.blade.php`, веднаш по блокот со `editCity` (околу ред 63), додај:

```blade
                        <div>
                            <x-input-label for="editCountry" value="Држава" />
                            <x-text-input id="editCountry" wire:model="editCountry" class="w-full" />
                            @error('editCountry') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                        </div>
                        @if ($company->type->isIndividual())
                            <div>
                                <x-input-label for="editInvoiceLanguage" value="Јазик на фактура" />
                                <select id="editInvoiceLanguage" wire:model="editInvoiceLanguage" class="w-full border-gray-300 rounded-md text-sm">
                                    @foreach (\App\Support\InvoiceLanguage::cases() as $case)
                                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                    @endforeach
                                </select>
                                @error('editInvoiceLanguage') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                            </div>
                        @endif
```

Ако компонентата не изложува `$company` во Blade-от, користи `$partner->company` наместо `$company` во условот.

- [ ] **Step 5: Пушти ги тестовите**

Run: `php artisan test tests/Feature/ForeignCurrencyInvoiceTest.php`
Expected: PASS — 10 тестови.

- [ ] **Step 6: Потврди дека постоечките тестови за кооперанти не се расипани**

Run: `php artisan test --filter=Partner`
Expected: PASS.

- [ ] **Step 7: Комит**

```bash
git add app/Livewire/PartnerShow.php resources/views/livewire/partner-show.blade.php tests/Feature/ForeignCurrencyInvoiceTest.php
git commit -m "feat: јазик на фактура и држава кај кооперантот"
```

---

### Task 4: IBAN и SWIFT во профилот

**Files:**
- Modify: `app/Livewire/CompanyProfile.php`
- Modify: `resources/views/livewire/company-profile.blade.php`
- Test: `tests/Feature/ForeignCurrencyInvoiceTest.php`

**Interfaces:**
- Consumes: `company_bank_accounts.iban|swift` (Task 1).
- Produces: зачувани `iban` и `swift` на банкарските сметки, што Task 7 ги печати на англиската фактура.

- [ ] **Step 1: Напиши го тестот што паѓа**

Додај во `tests/Feature/ForeignCurrencyInvoiceTest.php`:

```php
    public function test_the_profile_stores_an_iban_and_a_swift_per_bank_account(): void
    {
        $company = Company::factory()->create(['type' => 'individual']);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\CompanyProfile::class, ['company' => $company])
            ->set('bankAccounts', [[
                'bank_name' => 'Komercijalna',
                'account_number' => '300000000000123',
                'iban' => 'MK07300701104789126',
                'swift' => 'KOBSMK2X',
            ]])
            ->call('save')
            ->assertHasNoErrors();

        $account = $company->fresh()->bankAccounts->first();
        $this->assertSame('MK07300701104789126', $account->iban);
        $this->assertSame('KOBSMK2X', $account->swift);
    }

    public function test_a_row_with_only_an_iban_is_still_kept(): void
    {
        // Празен ред се фрла, но ред со внесен IBAN не е празен.
        $company = Company::factory()->create(['type' => 'individual']);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\CompanyProfile::class, ['company' => $company])
            ->set('bankAccounts', [[
                'bank_name' => '',
                'account_number' => '',
                'iban' => 'DE89370400440532013000',
                'swift' => '',
            ]])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('DE89370400440532013000', $company->fresh()->bankAccounts->first()?->iban);
    }
```

- [ ] **Step 2: Пушти и потврди дека паѓа**

Run: `php artisan test tests/Feature/ForeignCurrencyInvoiceTest.php --filter=iban`
Expected: FAIL — сметката се зачувува без `iban`.

- [ ] **Step 3: Дополни ја компонентата**

Во `app/Livewire/CompanyProfile.php`, во методот што ги полни `$this->bankAccounts` (околу ред 106), додај ги двата клуча во двете гранки:

```php
        $existing = $this->company->bankAccounts()->get();
        $this->bankAccounts = $existing->isEmpty()
            ? [['bank_name' => '', 'account_number' => '', 'iban' => '', 'swift' => '']]
            : $existing->map(fn ($row) => [
                'bank_name' => (string) $row->bank_name,
                'account_number' => (string) $row->account_number,
                'iban' => (string) $row->iban,
                'swift' => (string) $row->swift,
            ])->toArray();
```

Во методот што додава нов празен ред (околу ред 201):

```php
            $this->bankAccounts[] = ['bank_name' => '', 'account_number' => '', 'iban' => '', 'swift' => ''];
```

Во валидацијата (околу ред 233), по редот за `account_number`:

```php
            'bankAccounts.*.iban' => 'nullable|string|max:64',
            'bankAccounts.*.swift' => 'nullable|string|max:20',
```

Во филтерот што ги фрла празните редови (околу ред 314) — сега ред со само IBAN мора да преживее:

```php
            $keptRows = collect($validated['bankAccounts'])
                ->filter(fn ($row) => trim((string) ($row['bank_name'] ?? '')) !== ''
                    || trim((string) ($row['account_number'] ?? '')) !== ''
                    || trim((string) ($row['iban'] ?? '')) !== ''
                    || trim((string) ($row['swift'] ?? '')) !== '')
                ->values()
                ->take(5);
```

И во создавањето (околу ред 320):

```php
                $this->company->bankAccounts()->create([
                    'bank_name' => $row['bank_name'] ?: null,
                    'account_number' => $row['account_number'] ?: null,
                    'iban' => $row['iban'] ?? null ?: null,
                    'swift' => $row['swift'] ?? null ?: null,
                    'position' => $index,
                ]);
```

- [ ] **Step 4: Дополни го Blade-от**

Во `resources/views/livewire/company-profile.blade.php`, веднаш по блокот со `account_number` (околу ред 218), пред затворањето на `</div>` на редот:

```blade
                                    <div>
                                        <x-input-label for="iban_{{ $index }}" value="IBAN (за странство)" />
                                        <x-text-input id="iban_{{ $index }}" wire:model="bankAccounts.{{ $index }}.iban" class="w-64" />
                                    </div>
                                    <div>
                                        <x-input-label for="swift_{{ $index }}" value="SWIFT/BIC" />
                                        <x-text-input id="swift_{{ $index }}" wire:model="bankAccounts.{{ $index }}.swift" class="w-40" />
                                    </div>
```

- [ ] **Step 5: Пушти ги тестовите**

Run: `php artisan test tests/Feature/ForeignCurrencyInvoiceTest.php`
Expected: PASS — 12 тестови.

- [ ] **Step 6: Потврди дека профилот не е расипан**

Run: `php artisan test --filter=CompanyProfile`
Expected: PASS.

- [ ] **Step 7: Комит**

```bash
git add app/Livewire/CompanyProfile.php resources/views/livewire/company-profile.blade.php tests/Feature/ForeignCurrencyInvoiceTest.php
git commit -m "feat: IBAN и SWIFT на банкарските сметки во профилот"
```

---

### Task 5: Валута и курс на формата за фактура

**Files:**
- Modify: `app/Livewire/Invoicing/SalesInvoiceForm.php`
- Modify: `resources/views/livewire/invoicing/sales-invoice-form.blade.php`
- Test: `tests/Feature/ForeignCurrencyInvoiceTest.php`

**Interfaces:**
- Consumes: `SalesInvoice::CURRENCIES`, колоните од Task 1, `InvoiceLanguage` (Task 2), `partners.invoice_language` (Task 3), и **постоечкиот** `App\Services\ExchangeRateService::getRate(string $currencyCode, Carbon $date): float` — се повикува, не се менува.
- Produces: нацрт фактура со пополнети `language`, `currency`, `exchange_rate`, што Task 6/7 ја печатат и Task 8 ја книжи.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Додај во `tests/Feature/ForeignCurrencyInvoiceTest.php`:

```php
    private function individualCompanyWithPartner(string $partnerLanguage = 'en'): array
    {
        $company = Company::factory()->create(['type' => 'individual']);
        $partner = Partner::factory()->for($company)->create([
            'name' => 'Acme Ltd',
            'invoice_language' => $partnerLanguage,
        ]);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        return [$company, $partner, $admin];
    }

    private function draftLine(): array
    {
        return [[
            'item_id' => '',
            'description' => 'Consulting services',
            'quantity' => '1',
            'unit_price' => '500',
            'vat_rate' => '0.00',
            'vat_treatment' => 'standard',
        ]];
    }

    public function test_an_individual_can_save_a_draft_in_euros(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-09-13')
            ->set('dueDate', '2026-09-30')
            ->set('currency', 'EUR')
            ->set('exchangeRate', '61.50')
            ->set('lines', $this->draftLine())
            ->call('save')
            ->assertHasNoErrors();

        $invoice = SalesInvoice::where('company_id', $company->id)->firstOrFail();
        $this->assertSame('EUR', $invoice->currency);
        $this->assertSame('61.500000', (string) $invoice->exchange_rate);
    }

    public function test_the_language_is_taken_from_the_partner_and_frozen_on_the_invoice(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner('en');

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-09-13')
            ->set('dueDate', '2026-09-30')
            ->set('currency', 'EUR')
            ->set('exchangeRate', '61.50')
            ->set('lines', $this->draftLine())
            ->call('save');

        $invoice = SalesInvoice::where('company_id', $company->id)->firstOrFail();
        $this->assertSame(\App\Support\InvoiceLanguage::EN, $invoice->language);

        // Подоцнежна промена кај кооперантот не ја менува издадената фактура.
        $partner->update(['invoice_language' => 'mk']);
        $this->assertSame(\App\Support\InvoiceLanguage::EN, $invoice->fresh()->language);
    }

    public function test_a_foreign_currency_without_a_rate_is_refused(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-09-13')
            ->set('dueDate', '2026-09-30')
            ->set('currency', 'EUR')
            ->set('exchangeRate', '')
            ->set('lines', $this->draftLine())
            ->call('save')
            ->assertHasErrors('exchangeRate');

        $this->assertSame(0, SalesInvoice::where('company_id', $company->id)->count());
    }

    public function test_a_zero_or_negative_rate_is_refused(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();

        foreach (['0', '-1'] as $rate) {
            \Livewire\Livewire::actingAs($admin)
                ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
                ->set('partnerId', (string) $partner->id)
                ->set('invoiceDate', '2026-09-13')
                ->set('dueDate', '2026-09-30')
                ->set('currency', 'EUR')
                ->set('exchangeRate', $rate)
                ->set('lines', $this->draftLine())
                ->call('save')
                ->assertHasErrors('exchangeRate');
        }
    }

    public function test_a_legal_entity_is_forced_back_to_denars_even_if_euros_arrive_over_the_wire(): void
    {
        $company = Company::factory()->create(['type' => 'legal']);
        $partner = Partner::factory()->for($company)->create(['invoice_language' => 'en']);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-09-13')
            ->set('dueDate', '2026-09-30')
            ->set('currency', 'EUR')
            ->set('exchangeRate', '61.50')
            ->set('lines', $this->draftLine())
            ->call('save')
            ->assertHasNoErrors();

        $invoice = SalesInvoice::where('company_id', $company->id)->firstOrFail();
        $this->assertSame('MKD', $invoice->currency);
        $this->assertSame('1.000000', (string) $invoice->exchange_rate);
        $this->assertSame(\App\Support\InvoiceLanguage::MK, $invoice->language);
    }

    public function test_a_legal_entity_never_sees_the_currency_field(): void
    {
        $company = Company::factory()->create(['type' => 'legal']);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->assertDontSee('Валута');
    }

    public function test_switching_to_a_currency_offers_the_last_rate_used_for_it(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();
        SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'currency' => 'EUR',
            'exchange_rate' => '61.480000',
            'invoice_date' => '2026-08-01',
        ]);

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->set('currency', 'EUR')
            ->assertSet('exchangeRate', '61.480000');
    }

    public function test_switching_back_to_denars_resets_the_rate_to_one(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->set('currency', 'EUR')
            ->set('exchangeRate', '61.50')
            ->set('currency', 'MKD')
            ->assertSet('exchangeRate', '1');
    }

    public function test_the_nbrm_button_fills_the_rate_for_the_invoice_date(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();

        // Постоечкиот ExchangeRateService кешира во табелата exchange_rates и
        // повикува мрежа само кога нема кеш. Полниме кеш, па тестот не оди на
        // интернет.
        \App\Models\ExchangeRate::create([
            'rate_date' => '2026-09-13',
            'currency_code' => 'EUR',
            'rate' => '61.4955',
        ]);

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->set('invoiceDate', '2026-09-13')
            ->set('currency', 'EUR')
            ->call('fetchRate')
            ->assertHasNoErrors()
            ->assertSet('exchangeRate', '61.4955');
    }

    public function test_a_failing_nbrm_call_leaves_the_rate_typeable_instead_of_crashing(): void
    {
        // Паднат НБРМ не смее да ја сруши формата — фактурата мора да може да
        // се издаде со рачно впишан курс.
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();

        \Illuminate\Support\Facades\Http::fake([
            'www.nbrm.mk/*' => \Illuminate\Support\Facades\Http::response('', 500),
        ]);

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->set('invoiceDate', '2026-09-13')
            ->set('currency', 'EUR')
            ->call('fetchRate')
            ->assertHasErrors('exchangeRate');
    }

    public function test_the_nbrm_button_does_nothing_for_denars(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Invoicing\SalesInvoiceForm::class, ['company' => $company])
            ->set('currency', 'MKD')
            ->call('fetchRate')
            ->assertSet('exchangeRate', '1');
    }
```

- [ ] **Step 2: Пушти и потврди дека паѓа**

Run: `php artisan test tests/Feature/ForeignCurrencyInvoiceTest.php`
Expected: FAIL — `Property [$currency] not found`.

- [ ] **Step 3: Дополни ја компонентата**

Во `app/Livewire/Invoicing/SalesInvoiceForm.php`, до `public string $paymentTypeCode = 'P12';` додај:

```php
    public string $currency = 'MKD';

    public string $exchangeRate = '1';
```

Во `mount()`, во гранката за постоечка фактура (по `$this->paymentTypeCode = ...`):

```php
            $this->currency = $salesInvoice->currency;
            $this->exchangeRate = (string) $salesInvoice->exchange_rate;
```

Додај го методот што го понудува последниот курс, веднаш под `emptyLine()`:

```php
    /**
     * Кога се менува валутата, се нуди последниот курс што фирмата го користела
     * за неа. Курсот останува рачен — ова е само понуда, не автоматика.
     */
    public function updatedCurrency(string $value): void
    {
        if ($value === 'MKD') {
            $this->exchangeRate = '1';

            return;
        }

        $last = SalesInvoice::where('company_id', $this->company->id)
            ->where('currency', $value)
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->value('exchange_rate');

        $this->exchangeRate = $last === null ? '' : (string) $last;
    }

    /**
     * Копчето „НБРМ“ — истиот сервис што го користи формата за рачно книжење.
     *
     * Полето останува рачно и по ова: паднат НБРМ не смее да блокира издавање
     * фактура, па неуспехот се прикажува како грешка на полето, а корисникот
     * впишува курс сам.
     */
    public function fetchRate(): void
    {
        if ($this->currency === 'MKD') {
            $this->exchangeRate = '1';

            return;
        }

        try {
            $rate = app(ExchangeRateService::class)->getRate(
                $this->currency,
                Carbon::parse($this->invoiceDate)
            );
        } catch (\Throwable $e) {
            $this->addError('exchangeRate', 'Курсот не се презеде од НБРМ — впиши го рачно.');

            return;
        }

        $this->exchangeRate = (string) $rate;
    }
```

Во `use` блокот на фајлот додај:

```php
use App\Services\ExchangeRateService;
use Illuminate\Support\Carbon;
```

Во `save()`, веднаш по `Gate::authorize(...)` и **пред** `$this->validate([...])`:

```php
        // Девизна фактура важи само за физичко лице. Скриено поле во Blade не е
        // заклучување — тоа се прави овде, пред валидацијата.
        if (! $this->company->type->isIndividual()) {
            $this->currency = 'MKD';
            $this->exchangeRate = '1';
        }
```

Во низата за валидација, по редот за `paymentTypeCode`:

```php
            'currency' => ['required', Rule::in(SalesInvoice::CURRENCIES)],
            'exchangeRate' => [
                $this->currency === 'MKD' ? 'nullable' : 'required',
                'numeric',
                'gt:0',
            ],
```

Во `DB::transaction(...)`, по `$invoice->payment_type_code = $this->paymentTypeCode;`:

```php
            $invoice->currency = $this->currency;
            $invoice->exchange_rate = $this->currency === 'MKD' ? '1' : $this->exchangeRate;

            // Јазикот се презема од кооперантот на секое зачувување на нацртот,
            // па промена на купувачот го носи и неговиот јазик. По потврда
            // фактурата повеќе не поминува одовде и текстот останува замрзнат.
            $partner = Partner::where('company_id', $this->company->id)->find($this->partnerId);
            $invoice->language = $this->company->type->isIndividual() && $partner
                ? $partner->invoice_language
                : InvoiceLanguage::MK;
```

И во `use` блокот на фајлот додај:

```php
use App\Support\InvoiceLanguage;
```

- [ ] **Step 4: Дополни го Blade-от**

Во `resources/views/livewire/invoicing/sales-invoice-form.blade.php`, во првата `<x-card>`, по блокот со `paymentTypeCode`:

```blade
                @if ($company->type->isIndividual())
                    <div>
                        <x-input-label for="currency" value="Валута" />
                        <select id="currency" wire:model.live="currency" class="w-full rounded-lg border-gray-300 text-sm">
                            @foreach (\App\Models\SalesInvoice::CURRENCIES as $code)
                                <option value="{{ $code }}">{{ $code }}</option>
                            @endforeach
                        </select>
                        @error('currency') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    @if ($currency !== 'MKD')
                        <div>
                            <x-input-label for="exchangeRate" value="Курс (1 {{ $currency }} = ? ден)" />
                            <div class="flex gap-2">
                                <x-text-input id="exchangeRate" wire:model="exchangeRate" class="w-full" />
                                <button type="button" wire:click="fetchRate" class="shrink-0 px-3 rounded-lg border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">
                                    НБРМ
                                </button>
                            </div>
                            @error('exchangeRate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                        </div>
                    @endif
                @endif
```

- [ ] **Step 5: Додај ги македонските имиња на полињата за пораките за грешка**

Во `lang/mk/validation.php`, во низата `attributes`, додај:

```php
        'currency' => 'валута',
        'exchangeRate' => 'курс',
        'editInvoiceLanguage' => 'јазик на фактура',
        'editCountry' => 'држава',
```

Без нив Laravel го вметнува суровото camelCase име во инаку македонска реченица.

- [ ] **Step 6: Пушти ги тестовите**

Run: `php artisan test tests/Feature/ForeignCurrencyInvoiceTest.php`
Expected: PASS — 23 тестови.

- [ ] **Step 7: Потврди дека постоечката форма не е расипана**

Run: `php artisan test tests/Feature/SalesInvoiceFormTest.php tests/Feature/SalesInvoiceFormPaymentTypeTest.php`
Expected: PASS, ист број тестови како пред задачата.

- [ ] **Step 8: Комит**

```bash
git add app/Livewire/Invoicing/SalesInvoiceForm.php resources/views/livewire/invoicing/sales-invoice-form.blade.php lang/mk/validation.php tests/Feature/ForeignCurrencyInvoiceTest.php
git commit -m "feat: валута и курс на формата за излезна фактура кај физичко лице"
```

---

### Task 6: PDF-от поминува низ речникот, македонскиот останува непроменет

**Files:**
- Modify: `resources/views/pdf/sales-invoice.blade.php`
- Create: `tests/Feature/SalesInvoiceEnglishPdfTest.php`

**Interfaces:**
- Consumes: `InvoiceLanguage` (Task 2), `sales_invoices.language|currency` (Task 1).
- Produces: темплејт што печати на два јазика. Task 7 додава IBAN/SWIFT и држава во истиот темплејт.

**Задолжително:** ова е задачата со најголем ризик во планот. Првиот чекор е снимка на сегашниот македонски излез; таа снимка потоа мора да се совпадне точно.

- [ ] **Step 1: Сними го сегашниот македонски излез**

Run:

```bash
php artisan tinker --execute="\$i = App\Models\SalesInvoice::with(['lines','partner','company.bankAccounts'])->whereNotNull('invoice_number')->first(); file_put_contents(storage_path('mk-invoice-before.html'), view('pdf.sales-invoice', ['invoice' => \$i])->render());"
```

Ако локалната база нема потврдена фактура, прескокни го овој чекор — тестот во Step 2 е вистинската заштита, ова е само дополнителна.

- [ ] **Step 2: Напиши го тестот што ја заклучува македонската фактура**

Создај `tests/Feature/SalesInvoiceEnglishPdfTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceEnglishPdfTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function invoice(array $attributes = [], array $companyAttributes = []): SalesInvoice
    {
        $company = Company::factory()->create(array_merge([
            'name' => 'Stefan Kotev',
            'type' => 'individual',
            'is_vat_registered' => false,
            'tax_id' => '4080012345678',
        ], $companyAttributes));
        $company->bankAccounts()->create([
            'bank_name' => 'Komercijalna banka',
            'account_number' => '300000000000123',
            'position' => 0,
        ]);
        $partner = Partner::factory()->for($company)->create([
            'name' => 'Acme Ltd',
            'address' => '5 Market Street',
        ]);

        $invoice = SalesInvoice::factory()->for($company)->create(array_merge([
            'partner_id' => $partner->id,
            'status' => 'confirmed',
            'fiscal_year' => 2026,
            'invoice_number' => 7,
            'invoice_number_formatted' => '2026/7',
            'invoice_date' => '2026-09-13',
            'due_date' => '2026-09-30',
        ], $attributes));

        $invoice->lines()->create([
            'description' => 'Consulting services',
            'quantity' => '2',
            'unit_price' => '750.00',
            'vat_rate' => '0.00',
            'vat_treatment' => 'standard',
        ]);

        return $invoice->fresh(['lines', 'partner', 'company.bankAccounts']);
    }

    private function render(SalesInvoice $invoice): string
    {
        return view('pdf.sales-invoice', ['invoice' => $invoice])->render();
    }

    public function test_a_denar_invoice_still_prints_every_macedonian_heading(): void
    {
        // Ова е заштитата на веќе живата фактура. Ако падне, нешто на
        // испечатената денарска хартија се сменило.
        $html = $this->render($this->invoice());

        foreach ([
            'ФАКТУРА', 'Датум на фактура', 'Датум на доспевање', 'Издавач', 'Купувач',
            'ЕДБ', 'Р.б.', 'Опис', 'Кол.', 'Ед. цена', 'Вкупно', 'Начин на плаќање',
            'Назив на примач', 'Банка на примач', 'Сметка', 'Износ', 'Цел на дознака',
            'Основа', 'ДДВ', 'За доплата', 'ОВЛАСТЕНО ЛИЦЕ', 'ПРИМИЛ',
            'Фирмава не е ДДВ обврзник.',
        ] as $heading) {
            $this->assertStringContainsString($heading, $html, "Недостасува: {$heading}");
        }
    }

    public function test_a_denar_invoice_still_prints_macedonian_numbers_and_dates(): void
    {
        $html = $this->render($this->invoice());

        $this->assertStringContainsString('1.500,00 ден', $html);
        $this->assertStringContainsString('13.09.2026', $html);
        $this->assertStringNotContainsString('1,500.00', $html);
    }

    public function test_an_english_invoice_prints_english_headings(): void
    {
        $html = $this->render($this->invoice(['language' => 'en', 'currency' => 'EUR', 'exchange_rate' => '61.50']));

        foreach ([
            'INVOICE', 'Invoice date', 'Due date', 'Seller', 'Buyer', 'Tax no.',
            'No.', 'Description', 'Qty', 'Unit price', 'Total', 'Payment details',
            'Beneficiary', 'Amount', 'Payment reference', 'Subtotal', 'Balance due',
            'AUTHORISED SIGNATURE', 'RECEIVED BY', 'Not registered for VAT.',
        ] as $heading) {
            $this->assertStringContainsString($heading, $html, "Недостасува: {$heading}");
        }

        $this->assertStringNotContainsString('ФАКТУРА', $html);
        $this->assertStringNotContainsString('Издавач', $html);
        $this->assertStringNotContainsString('ОВЛАСТЕНО ЛИЦЕ', $html);
    }

    public function test_an_english_invoice_prints_amounts_in_the_invoice_currency(): void
    {
        $html = $this->render($this->invoice(['language' => 'en', 'currency' => 'EUR', 'exchange_rate' => '61.50']));

        $this->assertStringContainsString('1,500.00 EUR', $html);
        $this->assertStringContainsString('13 Sep 2026', $html);
        $this->assertStringNotContainsString('ден', $html);
        $this->assertStringNotContainsString('1.500,00', $html);
    }

    public function test_an_english_invoice_never_prints_the_denar_countervalue(): void
    {
        // Договорена одлука: хартијата е чисто во валутата на фактурата.
        $html = $this->render($this->invoice(['language' => 'en', 'currency' => 'EUR', 'exchange_rate' => '61.50']));

        $this->assertStringNotContainsString('61.50', $html);
        $this->assertStringNotContainsString('92,250', $html);
        $this->assertStringNotContainsString('MKD', $html);
    }

    public function test_the_pdf_route_renders_real_pdf_bytes_for_an_english_invoice(): void
    {
        // dompdf 3.1.6 нема flex — „HTML-от изгледа добро“ не докажува ништо.
        $invoice = $this->invoice(['language' => 'en', 'currency' => 'EUR', 'exchange_rate' => '61.50']);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('sales-invoices.pdf', [$invoice->company, $invoice]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->streamedContent());
    }

    public function test_a_vat_registered_english_invoice_prints_the_vat_columns_in_english(): void
    {
        $invoice = $this->invoice(
            ['language' => 'en', 'currency' => 'EUR', 'exchange_rate' => '61.50'],
            ['is_vat_registered' => true]
        );

        $html = $this->render($invoice);

        $this->assertStringContainsString('VAT %', $html);
        $this->assertStringContainsString('VAT amount', $html);
        $this->assertStringContainsString('Total incl. VAT', $html);
        $this->assertStringNotContainsString('ДДВ %', $html);
    }
}
```

- [ ] **Step 3: Пушти и потврди кои паѓаат**

Run: `php artisan test tests/Feature/SalesInvoiceEnglishPdfTest.php`
Expected: двата македонски теста PASS (темплејтот сè уште е македонски), петте англиски FAIL.

- [ ] **Step 4: Пренеси го темплејтот на речникот**

Во `resources/views/pdf/sales-invoice.blade.php`, во `@php` блокот на врвот, по `$company = $invoice->company;` додај:

```php
        $lang = $invoice->language;
        $currency = $invoice->currency;
```

Потоа замени го **секој** видлив македонски збор со повик на речникот. Точните замени:

| Беше | Станува |
|---|---|
| `ФАКТУРА {{ $invoice->formattedNumber() }}` (двата пати, лево и десно поставено лого) | `{{ $lang->t('invoice') }} {{ $invoice->formattedNumber() }}` |
| `Датум на фактура: {{ \App\Support\Format::date($invoice->invoice_date) }}` | `{{ $lang->t('invoice_date') }}: {{ $lang->date($invoice->invoice_date) }}` |
| `Датум на доспевање: {{ \App\Support\Format::date($invoice->due_date) }}` | `{{ $lang->t('due_date') }}: {{ $lang->date($invoice->due_date) }}` |
| `<h4>Издавач</h4>` | `<h4>{{ $lang->t('seller') }}</h4>` |
| `<h4>Купувач</h4>` | `<h4>{{ $lang->t('buyer') }}</h4>` |
| `ЕДБ: {{ $company->tax_id }}` | `{{ $lang->t('tax_id') }}: {{ $company->tax_id }}` |
| `· ЕМБС: {{ $company->registration_number }}` | `· {{ $lang->t('registration_number') }}: {{ $company->registration_number }}` |
| `ЕДБ: {{ $invoice->partner->tax_id }}` | `{{ $lang->t('tax_id') }}: {{ $invoice->partner->tax_id }}` |
| `<th style="width: 22px;">Р.б.</th>` | `<th style="width: 22px;">{{ $lang->t('line_no') }}</th>` |
| `<th>Опис</th>` | `<th>{{ $lang->t('description') }}</th>` |
| `<th style="width: 42px;">Кол.</th>` | `<th style="width: 42px;">{{ $lang->t('quantity') }}</th>` |
| `<th style="width: 68px;">Ед. цена</th>` | `<th style="width: 68px;">{{ $lang->t('unit_price') }}</th>` |
| `<th style="width: 62px;">ДДВ %</th>` | `<th style="width: 62px;">{{ $lang->t('vat_percent') }}</th>` |
| `<th style="width: 72px;">Износ на ДДВ</th>` | `<th style="width: 72px;">{{ $lang->t('vat_amount') }}</th>` |
| `{{ $vatRegistered ? 'Вкупно со ДДВ' : 'Вкупно' }}` | `{{ $vatRegistered ? $lang->t('total_with_vat') : $lang->t('total') }}` |
| `{{ \App\Support\Format::vatTreatment($line->vat_treatment) }}` | `{{ $lang->vatTreatment($line->vat_treatment) }}` |
| `{{ \App\Support\Format::money($line->unit_price) }}` | `{{ $lang->money($line->unit_price, $currency) }}` |
| `{{ \App\Support\Format::money($line->vatAmount()) }}` | `{{ $lang->money($line->vatAmount(), $currency) }}` |
| `{{ \App\Support\Format::money(bcadd($line->lineTotal(), $line->vatAmount(), 2)) }}` | `{{ $lang->money(bcadd($line->lineTotal(), $line->vatAmount(), 2), $currency) }}` |
| `<h4>Начин на плаќање</h4>` | `<h4>{{ $lang->t('payment_details') }}</h4>` |
| `<td class="pay-label">Назив на примач</td>` | `<td class="pay-label">{{ $lang->t('beneficiary') }}</td>` |
| `<td class="pay-label">Банка на примач</td>` | `<td class="pay-label">{{ $lang->t('beneficiary_bank') }}</td>` |
| `<td class="pay-label">Сметка</td>` | `<td class="pay-label">{{ $lang->t('account') }}</td>` |
| `<td class="pay-label">Износ</td>` | `<td class="pay-label">{{ $lang->t('amount') }}</td>` |
| `<td class="pay-label">Цел на дознака</td>` | `<td class="pay-label">{{ $lang->t('payment_reference') }}</td>` |
| `{{ \App\Support\Format::money($invoice->grandTotal()) }}` (во блокот за плаќање) | `{{ $lang->money($invoice->grandTotal(), $currency) }}` |
| `Нема внесена банкарска сметка.` | `{{ $lang->t('no_bank_account') }}` |
| `Основа` / `ДДВ` / `Вкупно` / `За доплата` (кутијата со износи) | `{{ $lang->t('subtotal') }}` / `{{ $lang->t('vat') }}` / `{{ $lang->t('total') }}` / `{{ $lang->t('balance_due') }}` |
| `{{ \App\Support\Format::money($invoice->subtotal()) }}` | `{{ $lang->money($invoice->subtotal(), $currency) }}` |
| `{{ \App\Support\Format::money($invoice->vatTotal()) }}` | `{{ $lang->money($invoice->vatTotal(), $currency) }}` |
| `{{ \App\Support\Format::money($invoice->grandTotal()) }}` (кутијата со износи) | `{{ $lang->money($invoice->grandTotal(), $currency) }}` |
| `{{ \App\Support\Format::money($invoice->balanceDue()) }}` | `{{ $lang->money($invoice->balanceDue(), $currency) }}` |
| `<div class="sig-label">ОВЛАСТЕНО ЛИЦЕ</div>` | `<div class="sig-label">{{ $lang->t('signature_issuer') }}</div>` |
| `<div class="sig-label">ПРИМИЛ</div>` | `<div class="sig-label">{{ $lang->t('signature_receiver') }}</div>` |
| `$footnotes[] = 'Фирмава не е ДДВ обврзник.';` | `$footnotes[] = $lang->t('not_vat_registered');` |

**Ништо во `<style>` блокот не се менува.** Ниту една ширина, боја, `margin` или `padding`. Ако темплејтот по оваа задача има разлика во CSS, задачата е погрешно изведена.

- [ ] **Step 5: Пушти го тестот**

Run: `php artisan test tests/Feature/SalesInvoiceEnglishPdfTest.php`
Expected: PASS — 7 тестови.

- [ ] **Step 6: Потврди дека постоечкиот PDF тест е недопрен**

Run: `php artisan test tests/Feature/SalesInvoicePdfTest.php`
Expected: PASS, ист број тестови како пред задачата.

- [ ] **Step 7: Потврди дека `@script` блоковите не се допрени**

Run: `git diff --stat`
Expected: `sales-invoice-index.blade.php` и `sales-invoice-show.blade.php` **не се појавуваат** во листата.

- [ ] **Step 8: Комит**

```bash
git add resources/views/pdf/sales-invoice.blade.php tests/Feature/SalesInvoiceEnglishPdfTest.php
git commit -m "feat: фактурата се печати на јазикот запишан на неа"
```

---

### Task 7: IBAN, SWIFT и држава на англиската фактура

**Files:**
- Modify: `resources/views/pdf/sales-invoice.blade.php`
- Test: `tests/Feature/SalesInvoiceEnglishPdfTest.php`

**Interfaces:**
- Consumes: `company_bank_accounts.iban|swift` (Task 4), `partners.country` (Task 3), речникот (Task 2).
- Produces: завршена англиска фактура.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Додај во `tests/Feature/SalesInvoiceEnglishPdfTest.php`:

```php
    public function test_an_english_invoice_prints_the_iban_and_swift_when_they_are_filled(): void
    {
        $invoice = $this->invoice(['language' => 'en', 'currency' => 'EUR', 'exchange_rate' => '61.50']);
        $invoice->company->bankAccounts()->first()->update([
            'iban' => 'MK07300701104789126',
            'swift' => 'KOBSMK2X',
        ]);

        $html = $this->render($invoice->fresh(['lines', 'partner', 'company.bankAccounts']));

        $this->assertStringContainsString('IBAN', $html);
        $this->assertStringContainsString('MK07300701104789126', $html);
        $this->assertStringContainsString('SWIFT', $html);
        $this->assertStringContainsString('KOBSMK2X', $html);
    }

    public function test_an_english_invoice_falls_back_to_the_account_number_when_no_iban_is_set(): void
    {
        // Кај дел од клиентите IBAN-от веќе стои во полето „Сметка (IBAN)“.
        $invoice = $this->invoice(['language' => 'en', 'currency' => 'EUR', 'exchange_rate' => '61.50']);

        $html = $this->render($invoice);

        $this->assertStringContainsString('300000000000123', $html);
    }

    public function test_an_english_invoice_omits_the_swift_row_when_it_is_empty(): void
    {
        // Празен ред на испечатена фактура изгледа како грешка.
        $invoice = $this->invoice(['language' => 'en', 'currency' => 'EUR', 'exchange_rate' => '61.50']);

        $html = $this->render($invoice);

        $this->assertStringNotContainsString('SWIFT', $html);
    }

    public function test_an_english_invoice_prints_both_countries(): void
    {
        $invoice = $this->invoice(['language' => 'en', 'currency' => 'EUR', 'exchange_rate' => '61.50']);
        $invoice->partner->update(['country' => 'Germany']);

        $html = $this->render($invoice->fresh(['lines', 'partner', 'company.bankAccounts']));

        $this->assertStringContainsString('Germany', $html);
        $this->assertStringContainsString('North Macedonia', $html);
    }

    public function test_a_denar_invoice_prints_neither_iban_swift_nor_country(): void
    {
        // Македонската фактура останува каква што беше.
        $invoice = $this->invoice();
        $invoice->company->bankAccounts()->first()->update(['iban' => 'MK07300701104789126', 'swift' => 'KOBSMK2X']);
        $invoice->partner->update(['country' => 'Германија']);

        $html = $this->render($invoice->fresh(['lines', 'partner', 'company.bankAccounts']));

        $this->assertStringNotContainsString('SWIFT', $html);
        $this->assertStringNotContainsString('KOBSMK2X', $html);
        $this->assertStringNotContainsString('Германија', $html);
        $this->assertStringNotContainsString('Северна Македонија', $html);
    }
```

- [ ] **Step 2: Пушти и потврди дека паѓа**

Run: `php artisan test tests/Feature/SalesInvoiceEnglishPdfTest.php`
Expected: FAIL на четирите нови англиски теста.

- [ ] **Step 3: Додај ги трите блока во темплејтот**

Во блокот за плаќање, веднаш по редот со `{{ $lang->t('account') }}`, додај:

```blade
                            @if ($lang === \App\Support\InvoiceLanguage::EN)
                                @php $ibanValue = $mainAccount?->iban ?: $mainAccount?->account_number; @endphp
                                @if ($ibanValue)
                                    <tr>
                                        <td class="pay-label">{{ $lang->t('iban') }}</td>
                                        <td>{{ $ibanValue }}</td>
                                    </tr>
                                @endif
                                @if ($mainAccount && $mainAccount->swift)
                                    <tr>
                                        <td class="pay-label">{{ $lang->t('swift') }}</td>
                                        <td>{{ $mainAccount->swift }}</td>
                                    </tr>
                                @endif
                            @endif
```

**Внимание:** редот со `{{ $lang->t('account') }}` е во `@if ($mainAccount && $mainAccount->account_number)`. Новиот блок оди **по** таа затворачка `@endif`, не внатре во неа — инаку сметка без `account_number` но со IBAN нема да го покаже IBAN-от.

Во блокот за издавачот, по редот со телефон/е-пошта:

```blade
                        @if ($lang === \App\Support\InvoiceLanguage::EN)
                            <div class="small muted">{{ $lang->t('seller_country') }}</div>
                        @endif
```

Во блокот за купувачот, по адресата и пред ЕДБ:

```blade
                        @if ($lang === \App\Support\InvoiceLanguage::EN && $invoice->partner->country)
                            <div class="small muted">{{ $invoice->partner->country }}</div>
                        @endif
```

- [ ] **Step 4: Пушти го тестот**

Run: `php artisan test tests/Feature/SalesInvoiceEnglishPdfTest.php`
Expected: PASS — 12 тестови.

- [ ] **Step 5: Потврди дека македонскиот PDF е и понатаму недопрен**

Run: `php artisan test tests/Feature/SalesInvoicePdfTest.php`
Expected: PASS.

- [ ] **Step 6: Комит**

```bash
git add resources/views/pdf/sales-invoice.blade.php tests/Feature/SalesInvoiceEnglishPdfTest.php
git commit -m "feat: IBAN, SWIFT и држава на англиската фактура"
```

---

### Task 8: Книжење во денари по курсот на фактурата

**Files:**
- Modify: `app/Services/Invoicing/SalesInvoiceService.php`
- Test: `tests/Feature/ForeignCurrencyInvoiceTest.php`

**Interfaces:**
- Consumes: `SalesInvoice::isForeignCurrency()`, `exchange_rate` (Task 1), `App\Support\Bcmath::roundHalfUp()` и колоните `journal_entry_lines.currency_code|exchange_rate|foreign_amount` (двете веќе постојат).
- Produces: книжење во денари со зачуван девизен оригинал. Ништо не се менува за денарска фактура.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Додај во `tests/Feature/ForeignCurrencyInvoiceTest.php`:

```php
    public function test_a_euro_invoice_is_posted_to_the_ledger_in_denars(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'draft',
            'currency' => 'EUR',
            'exchange_rate' => '61.500000',
            'invoice_date' => '2026-09-13',
        ]);
        $invoice->lines()->create([
            'description' => 'Consulting',
            'quantity' => '1',
            'unit_price' => '500.00',
            'vat_rate' => '0.00',
            'vat_treatment' => 'standard',
        ]);

        app(\App\Services\Invoicing\SalesInvoiceService::class)
            ->confirm($invoice->fresh(['lines', 'company']), $admin->id);

        $entry = $invoice->fresh()->journalEntry;
        $receivable = $entry->lines->firstWhere(fn ($line) => $line->account->code === '120');
        $revenue = $entry->lines->firstWhere(fn ($line) => $line->account->code === '740');

        // 500 EUR × 61,50 = 30.750 денари
        $this->assertSame(0, bccomp('30750.00', $receivable->debit, 2));
        $this->assertSame(0, bccomp('30750.00', $revenue->credit, 2));
    }

    public function test_a_euro_invoice_keeps_the_original_amount_on_the_ledger_line(): void
    {
        // journal_entry_lines веќе носи currency_code/exchange_rate/foreign_amount
        // и формата за рачно книжење веќе ги полни. Фактурата го користи истиот
        // образец — денарскиот износ не смее да го проголта оригиналот.
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'draft',
            'currency' => 'EUR',
            'exchange_rate' => '61.500000',
        ]);
        $invoice->lines()->create([
            'description' => 'Consulting',
            'quantity' => '1',
            'unit_price' => '500.00',
            'vat_rate' => '0.00',
            'vat_treatment' => 'standard',
        ]);

        app(\App\Services\Invoicing\SalesInvoiceService::class)
            ->confirm($invoice->fresh(['lines', 'company']), $admin->id);

        $receivable = $invoice->fresh()->journalEntry->lines
            ->firstWhere(fn ($line) => $line->account->code === '120');

        $this->assertSame('EUR', $receivable->currency_code);
        $this->assertSame(0, bccomp('61.500000', $receivable->exchange_rate, 6));
        $this->assertSame(0, bccomp('500.00', $receivable->foreign_amount, 2));
    }

    public function test_a_denar_invoice_leaves_the_currency_columns_at_their_defaults(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner('mk');
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'draft',
            'currency' => 'MKD',
        ]);
        $invoice->lines()->create([
            'description' => 'Услуга',
            'quantity' => '1',
            'unit_price' => '100.00',
            'vat_rate' => '0.00',
            'vat_treatment' => 'standard',
        ]);

        app(\App\Services\Invoicing\SalesInvoiceService::class)
            ->confirm($invoice->fresh(['lines', 'company']), $admin->id);

        $receivable = $invoice->fresh()->journalEntry->lines
            ->firstWhere(fn ($line) => $line->account->code === '120');

        $this->assertSame('MKD', $receivable->currency_code);
        $this->assertNull($receivable->foreign_amount);
    }

    public function test_a_euro_invoice_ledger_entry_balances_to_the_last_denar(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'draft',
            'currency' => 'EUR',
            'exchange_rate' => '61.473300',
        ]);
        $invoice->lines()->create([
            'description' => 'Odd amount',
            'quantity' => '3',
            'unit_price' => '33.33',
            'vat_rate' => '0.00',
            'vat_treatment' => 'standard',
        ]);

        app(\App\Services\Invoicing\SalesInvoiceService::class)
            ->confirm($invoice->fresh(['lines', 'company']), $admin->id);

        $lines = $invoice->fresh()->journalEntry->lines;
        $debits = $lines->reduce(fn ($carry, $line) => bcadd($carry, $line->debit, 2), '0.00');
        $credits = $lines->reduce(fn ($carry, $line) => bcadd($carry, $line->credit, 2), '0.00');

        $this->assertSame(0, bccomp($debits, $credits, 2), "Дебит {$debits} наспроти кредит {$credits}");
    }

    public function test_a_denar_invoice_posts_exactly_the_same_numbers_as_before(): void
    {
        // Курсот е 1 и множењето не смее да го помести ниту еден износ.
        [$company, $partner, $admin] = $this->individualCompanyWithPartner('mk');
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'draft',
            'currency' => 'MKD',
            'exchange_rate' => '1.000000',
        ]);
        $invoice->lines()->create([
            'description' => 'Услуга',
            'quantity' => '1',
            'unit_price' => '1234.56',
            'vat_rate' => '0.00',
            'vat_treatment' => 'standard',
        ]);

        app(\App\Services\Invoicing\SalesInvoiceService::class)
            ->confirm($invoice->fresh(['lines', 'company']), $admin->id);

        $receivable = $invoice->fresh()->journalEntry->lines
            ->firstWhere(fn ($line) => $line->account->code === '120');

        $this->assertSame(0, bccomp('1234.56', $receivable->debit, 2));
    }

    public function test_a_full_payment_on_a_euro_invoice_clears_the_receivable_to_zero(): void
    {
        [$company, $partner, $admin] = $this->individualCompanyWithPartner();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'draft',
            'currency' => 'EUR',
            'exchange_rate' => '61.500000',
        ]);
        $invoice->lines()->create([
            'description' => 'Consulting',
            'quantity' => '1',
            'unit_price' => '500.00',
            'vat_rate' => '0.00',
            'vat_treatment' => 'standard',
        ]);

        $service = app(\App\Services\Invoicing\SalesInvoiceService::class);
        $service->confirm($invoice->fresh(['lines', 'company']), $admin->id);
        $service->recordPayment($invoice->fresh(['lines', 'payments', 'company']), '500.00', '2026-09-20', 'bank', $admin->id);

        // Плаќањето се чува во валутата на фактурата...
        $this->assertSame('500.00', $invoice->fresh(['lines', 'payments'])->paidTotal());
        $this->assertSame('0.00', $invoice->fresh(['lines', 'payments'])->balanceDue());

        // ...а во главната книга сметка 120 се затвора точно на нула.
        $receivableMovement = \App\Models\JournalEntryLine::whereHas('account', fn ($q) => $q->where('code', '120'))
            ->get()
            ->reduce(fn ($carry, $line) => bcsub(bcadd($carry, $line->debit, 2), $line->credit, 2), '0.00');

        $this->assertSame(0, bccomp($receivableMovement, '0', 2), "Остаток на 120: {$receivableMovement}");
    }
```

- [ ] **Step 2: Пушти и потврди дека паѓа**

Run: `php artisan test tests/Feature/ForeignCurrencyInvoiceTest.php --filter=ledger`
Expected: FAIL — книжи 500,00 наместо 30.750,00.

- [ ] **Step 3: Додај го претворањето во сервисот**

Во `app/Services/Invoicing/SalesInvoiceService.php`, веднаш над `private function account(...)`, додај:

```php
    /**
     * Износ од валутата на фактурата во денари.
     *
     * Книгите во Македонија се во денари, па девизната фактура се книжи по
     * курсот запишан на неа. Денарска фактура поминува недопрена — курсот е 1
     * и множењето не смее да помести ниту една пара од веќе книжените записи.
     *
     * Курсни разлики не се пресметуваат: наплатата се книжи по истиот курс, за
     * да се затвори побарувањето точно на нула.
     */
    private function toMkd(SalesInvoice $invoice, string $amount): string
    {
        if (! $invoice->isForeignCurrency()) {
            return $amount;
        }

        return Bcmath::roundHalfUp(bcmul($amount, (string) $invoice->exchange_rate, 10), 2);
    }

    /**
     * Девизните колони на една ставка од книжењето.
     *
     * `journal_entry_lines` веќе ги носи `currency_code`, `exchange_rate` и
     * `foreign_amount`, и формата за рачно книжење веќе ги полни — фактурата го
     * користи истиот образец, за да не се изгуби оригиналниот износ зад
     * денарскиот.
     *
     * Кај денарска фактура враќа празна низа: трите колони остануваат на своите
     * стандардни вредности и записот е буквално идентичен со досегашниот.
     */
    private function currencyColumns(SalesInvoice $invoice, string $foreignAmount): array
    {
        if (! $invoice->isForeignCurrency()) {
            return [];
        }

        return [
            'currency_code' => $invoice->currency,
            'exchange_rate' => (string) $invoice->exchange_rate,
            'foreign_amount' => $foreignAmount,
        ];
    }
```

Во `confirm()`, замени ги трите реда со износите:

```php
            $vatRegistered = $invoice->company->is_vat_registered;
            $net = $this->toMkd($invoice, $invoice->subtotal());
            $vat = $vatRegistered ? $this->toMkd($invoice, $invoice->vatTotal()) : '0.00';
            $gross = bcadd($net, $vat, 2);
```

Потоа во истата метода додај ги девизните колони на трите ставки што носат износ од
фактурата. `$entry->lines()->create([...])` за сметка **120** добива:

```php
            $entry->lines()->create(array_merge([
                'account_id' => $this->account($invoice->company, '120')->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'line_date' => $invoice->invoice_date,
                'debit' => $gross,
                'credit' => '0',
            ], $this->currencyColumns($invoice, bcadd($invoice->subtotal(), $vatRegistered ? $invoice->vatTotal() : '0.00', 2))));
```

за сметка **740**:

```php
            $entry->lines()->create(array_merge([
                'account_id' => $this->account($invoice->company, '740')->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'line_date' => $invoice->invoice_date,
                'debit' => '0',
                'credit' => $net,
            ], $this->currencyColumns($invoice, $invoice->subtotal())));
```

и за сметка **230** (внатре во постоечкиот `if (bccomp($vat, '0', 2) > 0)`):

```php
                $entry->lines()->create(array_merge([
                    'account_id' => $this->account($invoice->company, '230')->id,
                    'partner_id' => $invoice->partner_id,
                    'description' => "VAT on {$label}",
                    'line_date' => $invoice->invoice_date,
                    'debit' => '0',
                    'credit' => $vat,
                ], $this->currencyColumns($invoice, $invoice->vatTotal())));
```

`$cogsTotal` и **двете ставки 701/660 не се менуваат воопшто** — набавната вредност доаѓа
од залихата, веќе е во денари и нема девизен оригинал.

Во `recordPayment()`, веднаш по отворањето на `DB::transaction(...)` и создавањето на `$payment`, додај:

```php
            // Записот за плаќање останува во валутата на фактурата — салдото,
            // статусот и „За доплата“ се сметаат таму. Во главната книга оди
            // денарскиот износ.
            $amountMkd = $this->toMkd($invoice, $amount);
```

и во двете `$entry->lines()->create([...])` замени `'debit' => $amount` со `'debit' => $amountMkd`,
`'credit' => $amount` со `'credit' => $amountMkd`, и завиткај ги двете низи во
`array_merge(..., $this->currencyColumns($invoice, $amount))` — така и ставката за банка и
ставката за побарување го носат оригиналниот девизен износ:

```php
            $entry->lines()->create(array_merge([
                'account_id' => $this->account($invoice->company, $cashOrBankCode)->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'line_date' => $paymentDate,
                'debit' => $amountMkd,
                'credit' => '0',
            ], $this->currencyColumns($invoice, $amount)));

            $entry->lines()->create(array_merge([
                'account_id' => $this->account($invoice->company, '120')->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'line_date' => $paymentDate,
                'debit' => '0',
                'credit' => $amountMkd,
            ], $this->currencyColumns($invoice, $amount)));
```

- [ ] **Step 4: Пушти ги тестовите**

Run: `php artisan test tests/Feature/ForeignCurrencyInvoiceTest.php`
Expected: PASS — 29 тестови.

- [ ] **Step 5: Потврди дека постоечкото книжење не е расипано**

Run: `php artisan test --filter=SalesInvoice`
Expected: PASS, ист број тестови како пред задачата.

- [ ] **Step 6: Комит**

```bash
git add app/Services/Invoicing/SalesInvoiceService.php tests/Feature/ForeignCurrencyInvoiceTest.php
git commit -m "feat: девизната фактура се книжи во денари по курсот запишан на неа"
```

---

### Task 9: Девизна фактура не оди кон УЈП

**Files:**
- Modify: `app/Http/Controllers/EfakturaSendController.php`
- Test: `tests/Feature/ForeignCurrencyInvoiceTest.php`

**Interfaces:**
- Consumes: `SalesInvoice::isForeignCurrency()` (Task 1).
- Produces: серверско заклучување. Ништо не се менува за денарска фактура.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Додај во `tests/Feature/ForeignCurrencyInvoiceTest.php`:

```php
    public function test_a_foreign_currency_invoice_is_refused_by_the_ujp_send_endpoint(): void
    {
        // е-Фактура прима денари. Погрешно испратен износ е поскап од
        // заклучено копче.
        $company = Company::factory()->create([
            'type' => 'individual',
            'efaktura_credential_mode' => \App\Models\Company::EFAKTURA_MODE_OWN,
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'confirmed',
            'currency' => 'EUR',
            'exchange_rate' => '61.500000',
        ]);
        $accountant = \App\Models\User::factory()->create();
        $accountant->assignRole('accountant');

        $this->actingAs($accountant)
            ->postJson(route('sales-invoices.efaktura.signing-input', [$company, $invoice]), [
                'certificateBase64' => 'x',
            ])
            ->assertStatus(422);
    }

    public function test_a_foreign_currency_invoice_cannot_be_sent_either(): void
    {
        $company = Company::factory()->create([
            'type' => 'individual',
            'efaktura_credential_mode' => \App\Models\Company::EFAKTURA_MODE_OWN,
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'confirmed',
            'currency' => 'USD',
            'exchange_rate' => '56.200000',
        ]);
        $accountant = \App\Models\User::factory()->create();
        $accountant->assignRole('accountant');

        $this->actingAs($accountant)
            ->postJson(route('sales-invoices.efaktura.send', [$company, $invoice]), [
                'token' => 'whatever',
                'signature' => 'whatever',
            ])
            ->assertStatus(422);

        $this->assertSame('not_sent', $invoice->fresh()->efaktura_status);
    }
```

- [ ] **Step 2: Пушти и потврди дека паѓа**

Run: `php artisan test tests/Feature/ForeignCurrencyInvoiceTest.php --filter=ujp`
Expected: FAIL — минува понатаму наместо 422.

- [ ] **Step 3: Додај го заклучувањето**

Во `app/Http/Controllers/EfakturaSendController.php`, во `authorizeSigning()`, веднаш по редот `abort_unless($salesInvoice->status === 'confirmed', ...)`:

```php
        // УЈП прима денарски износи. Девизна фактура таму нема што да бара, а
        // погрешно испратен износ е поскап од заклучено копче.
        abort_if(
            $salesInvoice->isForeignCurrency(),
            422,
            'Фактура во странска валута не може да се испрати до УЈП — е-Фактура прима само денарски износи.'
        );
```

- [ ] **Step 4: Пушти ги тестовите**

Run: `php artisan test tests/Feature/ForeignCurrencyInvoiceTest.php`
Expected: PASS — 31 тест.

- [ ] **Step 5: Потврди дека е-Фактура не е расипана**

Run: `php artisan test --filter=Efaktura`
Expected: PASS, ист број тестови како пред задачата.

- [ ] **Step 6: Потврди дека `@script` блоковите се недопрени низ целата гранка**

Run: `git diff main --stat -- resources/views/livewire/invoicing/`
Expected: во листата е **само** `sales-invoice-form.blade.php`. `sales-invoice-index.blade.php` и `sales-invoice-show.blade.php` не смеат да се појават.

- [ ] **Step 7: Комит**

```bash
git add app/Http/Controllers/EfakturaSendController.php tests/Feature/ForeignCurrencyInvoiceTest.php
git commit -m "fix: девизна фактура не може да се испрати до УЈП"
```

---

## Завршна проверка пред спојување

- [ ] Целата серија: `php artisan test` (~8,5 минути). Очекувано: сите поминуваат, бројот е поголем од основниот 1407 за 53 нови теста (31 + 10 + 12).
- [ ] `git diff main --stat` — потврди дека `sales-invoice-index.blade.php` и `sales-invoice-show.blade.php` воопшто не се во листата.
- [ ] Визуелна проверка на англискиот PDF: рендерирај го преку привремен тест и претвори го во PNG со PyMuPDF (`pdftoppm` го нема на оваа машина). Барај: преломен текст, преклопени колони, празен ред во блокот за плаќање.
- [ ] Пред спој, `npm run build` — Tailwind JIT бара финален превод откако сите Blade промени се на место.
