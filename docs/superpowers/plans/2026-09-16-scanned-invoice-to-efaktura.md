# Скенирана фактура → е-Фактура: план за изведба

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Во формата за нова излезна фактура да може да се прикачи скенирана фактура, Claude да ја прочита, полињата да се пополнат за проверка од човек, а хартиениот број да преживее до УЈП.

**Architecture:** Читачот е изолиран зад интерфејс `ScannedInvoiceReader`, па целата апликација и целата серија тестови работат со двојник и никогаш не допираат надворешен сервис. Вистинскиот читач (Anthropic PHP SDK) се пишува последен, откако сè друго е докажано. Патот кон УЈП не се менува — само влезот во нацрт фактурата и еден услов во `SalesInvoiceService::confirm()`.

**Tech Stack:** Laravel 13, Livewire 3, PHPUnit (`Tests\TestCase` + `RefreshDatabase`), `anthropic-ai/sdk` (се додава во задача 7), модел `claude-haiku-4-5`.

**Спецификација:** `docs/superpowers/specs/2026-09-16-scanned-invoice-to-efaktura-design.md`

## Global Constraints

- Сиот текст видлив за корисник е на **строг македонски** (не бугарски формулации).
- Коментарите во кодот се на македонски, како во остатокот од проектот; се пишува **зошто**, не што.
- Ниту еден тест не смее да прави вистинско барање кон Anthropic. Двојникот е единствениот читач што серијата го гледа.
- Модел: точно `claude-haiku-4-5`. Без датумски наставки.
- Клуч: `ANTHROPIC_API_KEY` во `.env`, читан преку `config('services.anthropic.key')`. Ако е празен, полето за прикачување не се прикажува.
- Дозволи за прикачување и читање: само `admin` и `accountant`. Никогаш `client`.
- Границата на фајлот е 10 МБ; дозволени видови: `pdf`, `jpg`, `jpeg`, `png`.
- Прагот за неслагање на збирот е **1 денар**.
- `invoice_number` останува `null` за фактура со хартиен број. Никогаш не се троши број од серијата.
- Нема нова миграција: `invoice_number` и `invoice_number_formatted` веќе постојат и се nullable.
- Секоја задача завршува со зелена серија за нејзините тест-фајлови и еден commit.

---

### Task 1: Договорот, податочниот објект и двојникот

Ова е скелетот што сите подоцнежни задачи го користат. Нема повик кон надвор.

**Files:**
- Create: `app/Services/Invoicing/ScannedInvoiceLine.php`
- Create: `app/Services/Invoicing/ScannedInvoice.php`
- Create: `app/Services/Invoicing/ScannedInvoiceReader.php`
- Create: `app/Services/Invoicing/ScannedInvoiceReadException.php`
- Create: `tests/Support/FakeScannedInvoiceReader.php`
- Modify: `config/services.php` (на крајот на низата, по `'efaktura' => [...]`)
- Modify: `app/Providers/AppServiceProvider.php` (во `register()`)
- Test: `tests/Feature/Invoicing/ScannedInvoiceReaderContractTest.php`

**Interfaces:**
- Consumes: ништо.
- Produces:
  - `App\Services\Invoicing\ScannedInvoiceLine` — readonly: `?string $description`, `?string $quantity`, `?string $unitPrice`, `?string $vatRate`
  - `App\Services\Invoicing\ScannedInvoice` — readonly: `?string $sellerTaxId`, `?string $buyerName`, `?string $buyerTaxId`, `?string $buyerStreetAddress`, `?string $buyerStreetNumber`, `?string $buyerPostalCode`, `?string $buyerCity`, `?string $invoiceNumber`, `?string $invoiceDate` (`Y-m-d`), `?string $dueDate` (`Y-m-d`), `?string $currency`, `?string $printedTotal`, `array $lines` (`ScannedInvoiceLine[]`)
  - `App\Services\Invoicing\ScannedInvoiceReader` — `read(TemporaryUploadedFile $file, Company $company): ScannedInvoice`
  - `App\Services\Invoicing\ScannedInvoiceReadException extends \RuntimeException`
  - `Tests\Support\FakeScannedInvoiceReader` — `public static ?ScannedInvoice $next = null;` и `public static ?\Throwable $throws = null;`
  - `config('services.anthropic.key')`

- [ ] **Step 1: Напиши го тестот што паѓа**

`tests/Feature/Invoicing/ScannedInvoiceReaderContractTest.php`:

```php
<?php

namespace Tests\Feature\Invoicing;

use App\Models\Company;
use App\Services\Invoicing\ScannedInvoice;
use App\Services\Invoicing\ScannedInvoiceLine;
use App\Services\Invoicing\ScannedInvoiceReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakeScannedInvoiceReader;
use Tests\TestCase;

class ScannedInvoiceReaderContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_container_resolves_a_reader(): void
    {
        $this->assertInstanceOf(ScannedInvoiceReader::class, app(ScannedInvoiceReader::class));
    }

    public function test_the_fake_returns_what_it_was_given(): void
    {
        $company = Company::factory()->create();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            buyerName: 'Купувач ДООЕЛ',
            buyerTaxId: '4080087654321',
            buyerStreetAddress: 'Партизанска',
            buyerStreetNumber: '10',
            buyerPostalCode: '1000',
            buyerCity: 'Скопје',
            invoiceNumber: '2026/45',
            invoiceDate: '2026-03-01',
            dueDate: '2026-03-15',
            currency: 'MKD',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $this->app->bind(ScannedInvoiceReader::class, FakeScannedInvoiceReader::class);

        $result = app(ScannedInvoiceReader::class)->read(
            UploadedFile::fake()->create('skan.pdf', 20),
            $company,
        );

        $this->assertSame('2026/45', $result->invoiceNumber);
        $this->assertSame('Услуга', $result->lines[0]->description);
    }

    public function test_the_anthropic_key_is_configured(): void
    {
        config(['services.anthropic.key' => 'test-key']);

        $this->assertSame('test-key', config('services.anthropic.key'));
    }
}
```

- [ ] **Step 2: Пушти го тестот и потврди дека паѓа**

Run: `php artisan test tests/Feature/Invoicing/ScannedInvoiceReaderContractTest.php`
Expected: FAIL — `Class "App\Services\Invoicing\ScannedInvoice" not found`

- [ ] **Step 3: Напиши го податочниот објект за ставка**

`app/Services/Invoicing/ScannedInvoiceLine.php`:

```php
<?php

namespace App\Services\Invoicing;

/**
 * Една ставка како што била прочитана од скен.
 *
 * Сè е стринг и сè е незадолжително намерно: скенот е слика на хартија, не
 * база. Прочитано „количина: —“ мора да може да помине до формата и таму да го
 * види човек, наместо тука да пукне обработката.
 */
final readonly class ScannedInvoiceLine
{
    public function __construct(
        public ?string $description = null,
        public ?string $quantity = null,
        public ?string $unitPrice = null,
        public ?string $vatRate = null,
    ) {}
}
```

- [ ] **Step 4: Напиши го податочниот објект за фактура**

`app/Services/Invoicing/ScannedInvoice.php`:

```php
<?php

namespace App\Services\Invoicing;

/**
 * Сè што е прочитано од еден скен, пред каква било проверка.
 *
 * `sellerTaxId` постои само за една работа: да се провери дали качениот фајл е
 * навистина излезна фактура на оваа фирма, а не влезна. `printedTotal` е
 * вкупното испишано на хартијата и служи за спротивставување со пресметаното.
 */
final readonly class ScannedInvoice
{
    /**
     * @param  ScannedInvoiceLine[]  $lines
     */
    public function __construct(
        public ?string $sellerTaxId = null,
        public ?string $buyerName = null,
        public ?string $buyerTaxId = null,
        public ?string $buyerStreetAddress = null,
        public ?string $buyerStreetNumber = null,
        public ?string $buyerPostalCode = null,
        public ?string $buyerCity = null,
        public ?string $invoiceNumber = null,
        public ?string $invoiceDate = null,
        public ?string $dueDate = null,
        public ?string $currency = null,
        public ?string $printedTotal = null,
        public array $lines = [],
    ) {}
}
```

- [ ] **Step 5: Напиши го интерфејсот и исклучокот**

`app/Services/Invoicing/ScannedInvoiceReader.php`:

```php
<?php

namespace App\Services\Invoicing;

use App\Models\Company;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Единствениот влез кон читањето на скен.
 *
 * Постои за да може целата серија тестови да работи со двојник — ниту еден
 * тест не смее да праќа фајл надвор, ниту да троши пари.
 */
interface ScannedInvoiceReader
{
    /**
     * @throws ScannedInvoiceReadException кога фајлот не може да се прочита
     */
    public function read(TemporaryUploadedFile $file, Company $company): ScannedInvoice;
}
```

`app/Services/Invoicing/ScannedInvoiceReadException.php`:

```php
<?php

namespace App\Services\Invoicing;

use RuntimeException;

class ScannedInvoiceReadException extends RuntimeException {}
```

- [ ] **Step 6: Напиши го двојникот**

`tests/Support/FakeScannedInvoiceReader.php`:

```php
<?php

namespace Tests\Support;

use App\Models\Company;
use App\Services\Invoicing\ScannedInvoice;
use App\Services\Invoicing\ScannedInvoiceReader;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Читач за тестови. Статичките полиња се чистат во `setUp()` на секој тест што
 * го користи — инаку нагодување од еден тест би протекло во следниот.
 */
class FakeScannedInvoiceReader implements ScannedInvoiceReader
{
    public static ?ScannedInvoice $next = null;

    public static ?\Throwable $throws = null;

    public static function reset(): void
    {
        self::$next = null;
        self::$throws = null;
    }

    public function read(TemporaryUploadedFile $file, Company $company): ScannedInvoice
    {
        if (self::$throws !== null) {
            throw self::$throws;
        }

        return self::$next ?? new ScannedInvoice;
    }
}
```

`composer.json` веќе има `"Tests\\": "tests/"` во `autoload-dev.psr-4`, па `tests/Support` е покриен без промена.

- [ ] **Step 7: Додај ја конфигурацијата**

Во `config/services.php`, веднаш по блокот `'efaktura' => [...]`:

```php
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
    ],
```

Во `.env.example` додај на крајот:

```
ANTHROPIC_API_KEY=
```

- [ ] **Step 8: Врзи го читачот во сад**

Во `app/Providers/AppServiceProvider.php`, во `register()`:

```php
        // Вистинскиот читач се пишува во задача 7. До тогаш врзувањето покажува
        // на договорот, а тестовите го заменуваат со двојник.
        $this->app->bind(
            \App\Services\Invoicing\ScannedInvoiceReader::class,
            \App\Services\Invoicing\NullScannedInvoiceReader::class,
        );
```

И создај `app/Services/Invoicing/NullScannedInvoiceReader.php`:

```php
<?php

namespace App\Services\Invoicing;

use App\Models\Company;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Читачот што не чита ништо.
 *
 * Стои врзан кога нема клуч за Anthropic. Формата и онака не го покажува
 * копчето без клуч, но врзувањето мора да успее и на сервер без клуч —
 * инаку целата апликација паѓа при подигање.
 */
class NullScannedInvoiceReader implements ScannedInvoiceReader
{
    public function read(TemporaryUploadedFile $file, Company $company): ScannedInvoice
    {
        throw new ScannedInvoiceReadException('Читањето скен не е подесено на овој сервер.');
    }
}
```

- [ ] **Step 9: Пушти ги тестовите**

Run: `php artisan test tests/Feature/Invoicing/ScannedInvoiceReaderContractTest.php`
Expected: PASS — 3 тестa

- [ ] **Step 10: Commit**

```bash
git add app/Services/Invoicing tests/Support tests/Feature/Invoicing/ScannedInvoiceReaderContractTest.php config/services.php .env.example app/Providers/AppServiceProvider.php
git commit -m "feat: договор и двојник за читање скенирана фактура

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: Хартиениот број преживува потврда

Чиста промена во сервисот, без екран. Ова е највредното парче и се докажува само.

**Files:**
- Modify: `app/Services/Invoicing/SalesInvoiceService.php:42-56` (бројачот) и `:143-148` (запишувањето)
- Test: `tests/Feature/Invoicing/SalesInvoicePaperNumberTest.php`

**Interfaces:**
- Consumes: ништо од задача 1.
- Produces: однесување на `SalesInvoiceService::confirm()` — кога `invoice_number_formatted` е пополнет на нацртот, тој се задржува, `invoice_number` останува `null`, а судир со друг ист испишан број во истата година фрла `InvalidInvoiceStateException`.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

`tests/Feature/Invoicing/SalesInvoicePaperNumberTest.php`:

```php
<?php

namespace Tests\Feature\Invoicing;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoicePaperNumberTest extends TestCase
{
    use RefreshDatabase;

    private function draft(Company $company, array $attributes = []): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();

        $invoice = SalesInvoice::factory()->for($company)->create(array_merge([
            'partner_id' => $partner->id,
            'status' => 'draft',
            'invoice_date' => '2026-03-01',
            'due_date' => '2026-03-15',
            'warehouse_id' => null,
        ], $attributes));

        $invoice->lines()->create([
            'item_id' => null,
            'description' => 'Услуга',
            'quantity' => '1',
            'unit_price' => '1000.00',
            'vat_rate' => '18.00',
            'vat_treatment' => 'standard',
        ]);

        return $invoice->fresh();
    }

    public function test_a_paper_number_survives_confirmation(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $invoice = $this->draft($company, ['invoice_number_formatted' => '2026/45']);

        $confirmed = app(SalesInvoiceService::class)->confirm($invoice, $user->id);

        $this->assertSame('2026/45', $confirmed->invoice_number_formatted);
        $this->assertSame('2026/45', $confirmed->formattedNumber());
        $this->assertNull($confirmed->invoice_number);
        $this->assertSame(2026, (int) $confirmed->fiscal_year);
    }

    public function test_a_paper_number_does_not_consume_the_company_counter(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $service = app(SalesInvoiceService::class);

        $first = $service->confirm($this->draft($company), $user->id);
        $this->assertSame(1, (int) $first->invoice_number);

        $service->confirm($this->draft($company, ['invoice_number_formatted' => '2026/99']), $user->id);

        $third = $service->confirm($this->draft($company), $user->id);

        // Скенот меѓу нив не смее да остави дупка — следната своја фактура
        // ја добива бројката што ќе ја добиеше и без него.
        $this->assertSame(2, (int) $third->invoice_number);
    }

    public function test_a_duplicate_paper_number_in_the_same_year_is_refused(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $service = app(SalesInvoiceService::class);

        $service->confirm($this->draft($company, ['invoice_number_formatted' => '2026/45']), $user->id);

        $this->expectException(InvalidInvoiceStateException::class);

        $service->confirm($this->draft($company, ['invoice_number_formatted' => '2026/45']), $user->id);
    }

    public function test_the_same_paper_number_in_a_different_year_is_allowed(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $service = app(SalesInvoiceService::class);

        $service->confirm($this->draft($company, [
            'invoice_number_formatted' => '001',
            'invoice_date' => '2025-03-01',
            'due_date' => '2025-03-15',
        ]), $user->id);

        $second = $service->confirm($this->draft($company, [
            'invoice_number_formatted' => '001',
            'invoice_date' => '2026-03-01',
            'due_date' => '2026-03-15',
        ]), $user->id);

        $this->assertSame('001', $second->invoice_number_formatted);
    }

    public function test_an_invoice_without_a_paper_number_behaves_as_before(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();

        $confirmed = app(SalesInvoiceService::class)->confirm($this->draft($company), $user->id);

        $this->assertSame(1, (int) $confirmed->invoice_number);
        $this->assertNotNull($confirmed->invoice_number_formatted);
    }
}
```

- [ ] **Step 2: Пушти ги и потврди дека паѓаат**

Run: `php artisan test tests/Feature/Invoicing/SalesInvoicePaperNumberTest.php`
Expected: FAIL — `test_a_paper_number_survives_confirmation` добива `2026/1` наместо `2026/45`, бидејќи `confirm()` секогаш го препишува бројот.

- [ ] **Step 3: Смени го доделувањето на бројот**

Во `app/Services/Invoicing/SalesInvoiceService.php`, во `confirm()`, замени го блокот што почнува со `$fiscalYear = $invoice->invoice_date->year;` и завршува со `$invoiceNumber = ($maxNumber ?? 0) + 1;` со:

```php
            $fiscalYear = $invoice->invoice_date->year;

            // Фактура внесена од скен си го носи бројот од хартијата. Бројачот
            // на фирмата не смее да го потроши: ако земеше број од серијата, во
            // сопствената нумерација ќе останеше дупка за фактура што никогаш не
            // била издадена на тој број.
            $paperNumber = $invoice->invoice_number_formatted;

            if (filled($paperNumber)) {
                $clash = SalesInvoice::where('company_id', $invoice->company_id)
                    ->whereKeyNot($invoice->id)
                    ->where('invoice_number_formatted', $paperNumber)
                    ->whereYear('invoice_date', $fiscalYear)
                    ->lockForUpdate()
                    ->exists();

                if ($clash) {
                    throw new InvalidInvoiceStateException("Во {$fiscalYear} веќе постои фактура со број {$paperNumber}.");
                }

                $invoiceNumber = null;
                $formattedNumber = $paperNumber;
            } else {
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
                $formattedNumber = InvoiceNumber::format($invoice->company, $fiscalYear, $invoiceNumber);
            }
```

- [ ] **Step 4: Смени го запишувањето**

Во истата метода, во `$invoice->update([...])` (околу линија 143), замени ги двете линии:

```php
                'invoice_number' => $invoiceNumber,
                'invoice_number_formatted' => InvoiceNumber::format($invoice->company, $fiscalYear, $invoiceNumber),
```

со:

```php
                'invoice_number' => $invoiceNumber,
                'invoice_number_formatted' => $formattedNumber,
```

- [ ] **Step 5: Пушти ги тестовите**

Run: `php artisan test tests/Feature/Invoicing/SalesInvoicePaperNumberTest.php`
Expected: PASS — 5 тестa

- [ ] **Step 6: Пушти ги постоечките тестови за фактури да не е скршено нешто**

Run: `php artisan test --filter=SalesInvoice`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/Invoicing/SalesInvoiceService.php tests/Feature/Invoicing/SalesInvoicePaperNumberTest.php
git commit -m "feat: хартиениот број на фактурата преживува потврда

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: Полето „Број од фактурата" во формата

**Files:**
- Modify: `app/Livewire/Invoicing/SalesInvoiceForm.php` (својство, `mount()`, `save()`)
- Modify: `resources/views/livewire/invoicing/sales-invoice-form.blade.php` (во првата `x-card`)
- Test: `tests/Feature/Invoicing/SalesInvoicePaperNumberFormTest.php`

**Interfaces:**
- Consumes: однесувањето на `confirm()` од задача 2.
- Produces: на `SalesInvoiceForm` — `public string $paperNumber = '';`. Кога е пополнет, се запишува во `invoice_number_formatted` на нацртот. Задачи 4 и 5 го поставуваат ова својство од прочитаниот скен.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

`tests/Feature/Invoicing/SalesInvoicePaperNumberFormTest.php`:

```php
<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoicePaperNumberFormTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function admin(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('admin');
        $this->actingAs($user);

        return $user;
    }

    private function fill($component, Company $company, Partner $partner)
    {
        return $component
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines', [[
                'item_id' => '',
                'description' => 'Услуга',
                'quantity' => '1',
                'unit_price' => '1000.00',
                'vat_rate' => '18.00',
                'vat_treatment' => 'standard',
            ]]);
    }

    public function test_a_paper_number_is_saved_on_the_draft(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin($company);

        $this->fill(Livewire::test(SalesInvoiceForm::class, ['company' => $company]), $company, $partner)
            ->set('paperNumber', '2026/45')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sales_invoices', [
            'company_id' => $company->id,
            'invoice_number_formatted' => '2026/45',
            'status' => 'draft',
        ]);
    }

    public function test_an_empty_paper_number_leaves_the_column_null(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin($company);

        $this->fill(Livewire::test(SalesInvoiceForm::class, ['company' => $company]), $company, $partner)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(SalesInvoice::where('company_id', $company->id)->first()->invoice_number_formatted);
    }

    public function test_a_duplicate_paper_number_in_the_same_year_is_refused(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin($company);

        SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'invoice_date' => '2026-05-01',
            'invoice_number_formatted' => '2026/45',
        ]);

        $this->fill(Livewire::test(SalesInvoiceForm::class, ['company' => $company]), $company, $partner)
            ->set('paperNumber', '2026/45')
            ->call('save')
            ->assertHasErrors('paperNumber');
    }

    public function test_the_same_paper_number_in_another_year_is_allowed(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin($company);

        SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'invoice_date' => '2025-05-01',
            'invoice_number_formatted' => '001',
        ]);

        $this->fill(Livewire::test(SalesInvoiceForm::class, ['company' => $company]), $company, $partner)
            ->set('paperNumber', '001')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_editing_a_draft_keeps_its_paper_number_in_the_field(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin($company);

        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'draft',
            'invoice_number_formatted' => '2026/45',
        ]);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSet('paperNumber', '2026/45');
    }
}
```

- [ ] **Step 2: Пушти ги и потврди дека паѓаат**

Run: `php artisan test tests/Feature/Invoicing/SalesInvoicePaperNumberFormTest.php`
Expected: FAIL — `Property [$paperNumber] not found on component`

- [ ] **Step 3: Додај го својството и полнењето при менување нацрт**

Во `app/Livewire/Invoicing/SalesInvoiceForm.php`, по `public string $notes = '';`:

```php
    /**
     * Бројот што фактурата веќе го носи на хартија.
     *
     * Празно значи обична фактура — бројот го дава серијата на фирмата при
     * потврда. Пополнето значи фактура внесена од скен и тој број оди и на
     * печатената фактура и кон УЈП.
     */
    public string $paperNumber = '';
```

Во `mount()`, во гранката `if ($salesInvoice) { ... }`, по `$this->notes = (string) $salesInvoice->notes;`:

```php
            $this->paperNumber = (string) $salesInvoice->invoice_number_formatted;
```

- [ ] **Step 4: Додај ја валидацијата и запишувањето**

Во `save()`, во низата предадена на `$this->validate([...])`, веднаш по редот за `'dueDate'` (низата нема ред за `'notes'`):

```php
            'paperNumber' => 'nullable|string|max:40',
```

Веднаш **по** повикот `$this->validate([...])` (пред јамката за `vat_treatment`):

```php
        // Двоен испишан број во иста фирма и иста година е вистински проблем —
        // кон УЈП би заминале две фактури со ист `docNumber`. Годината се зема
        // од датумот на фактурата, бидејќи `fiscal_year` се полни дури при
        // потврда, а проверката мора да важи и на нацрт.
        if ($this->paperNumber !== '') {
            $clash = SalesInvoice::where('company_id', $this->company->id)
                ->where('invoice_number_formatted', $this->paperNumber)
                ->whereYear('invoice_date', Carbon::parse($this->invoiceDate)->year)
                ->when($this->salesInvoice, fn ($query) => $query->whereKeyNot($this->salesInvoice->id))
                ->exists();

            if ($clash) {
                $this->addError('paperNumber', "Веќе постои фактура со број {$this->paperNumber} во таа година.");

                return;
            }
        }
```

Во `DB::transaction(...)`, по `$invoice->notes = $this->notes ?: null;`:

```php
            $invoice->invoice_number_formatted = $this->paperNumber ?: null;
```

`Carbon` веќе е внесен во фајлот (`use Illuminate\Support\Carbon;`).

- [ ] **Step 5: Додај го полето во екранот**

Во `resources/views/livewire/invoicing/sales-invoice-form.blade.php`, внатре во првата `<x-card class="grid ...">`, веднаш по блокот за `dueDate`:

```blade
                @if ($paperNumber !== '' || $scanRead ?? false)
                    <div>
                        <x-input-label for="paperNumber" value="Број од фактурата" />
                        <x-text-input id="paperNumber" type="text" wire:model="paperNumber" class="w-full" />
                        <p class="text-xs text-gray-500 mt-1">Бројот како што стои на хартијата. Празно значи дека Тами ќе издаде свој број.</p>
                        @error('paperNumber') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                @endif
```

`$scanRead` доаѓа во задача 4; `?? false` го држи екранот исправен дотогаш.

- [ ] **Step 6: Пушти ги тестовите**

Run: `php artisan test tests/Feature/Invoicing/SalesInvoicePaperNumberFormTest.php`
Expected: PASS — 5 тестa

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Invoicing/SalesInvoiceForm.php resources/views/livewire/invoicing/sales-invoice-form.blade.php tests/Feature/Invoicing/SalesInvoicePaperNumberFormTest.php
git commit -m "feat: поле за бројот од хартиената фактура

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: Прикачување и читање во формата

**Files:**
- Modify: `app/Livewire/Invoicing/SalesInvoiceForm.php`
- Modify: `resources/views/livewire/invoicing/sales-invoice-form.blade.php` (нова картичка над формата)
- Test: `tests/Feature/Invoicing/SalesInvoiceScanReadTest.php`

**Interfaces:**
- Consumes: `ScannedInvoiceReader`, `ScannedInvoice`, `ScannedInvoiceLine`, `ScannedInvoiceReadException`, `FakeScannedInvoiceReader` (задача 1); `$paperNumber` (задача 3).
- Produces: на `SalesInvoiceForm` — `public $scanFile = null;`, `public bool $scanRead = false;`, `public array $scanWarnings = [];`, метода `readScan(): void`, метода `canReadScans(): bool`.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

`tests/Feature/Invoicing/SalesInvoiceScanReadTest.php`:

```php
<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use App\Services\Invoicing\ScannedInvoice;
use App\Services\Invoicing\ScannedInvoiceLine;
use App\Services\Invoicing\ScannedInvoiceReadException;
use App\Services\Invoicing\ScannedInvoiceReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeScannedInvoiceReader;
use Tests\TestCase;

class SalesInvoiceScanReadTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
        FakeScannedInvoiceReader::reset();
        Storage::fake('local');
        config(['services.anthropic.key' => 'test-key']);
        $this->app->bind(ScannedInvoiceReader::class, FakeScannedInvoiceReader::class);
    }

    private function actAs(Company $company, string $role): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    private function scan(): UploadedFile
    {
        return UploadedFile::fake()->create('faktura.pdf', 200, 'application/pdf');
    }

    public function test_reading_a_scan_fills_the_form(): void
    {
        $company = Company::factory()->create(['tax_id' => '4080012345678']);
        $partner = Partner::factory()->for($company)->create(['tax_id' => '4080087654321']);
        $this->actAs($company, 'admin');

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            buyerName: $partner->name,
            buyerTaxId: '4080087654321',
            invoiceNumber: '2026/45',
            invoiceDate: '2026-03-01',
            dueDate: '2026-03-15',
            currency: 'MKD',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Консултантски услуги', '1', '1000.00', '18')],
        );

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('scanFile', $this->scan())
            ->call('readScan')
            ->assertSet('scanRead', true)
            ->assertSet('paperNumber', '2026/45')
            ->assertSet('invoiceDate', '2026-03-01')
            ->assertSet('dueDate', '2026-03-15')
            ->assertSet('partnerId', (string) $partner->id)
            ->assertSet('lines.0.description', 'Консултантски услуги')
            ->assertSet('lines.0.quantity', '1')
            ->assertSet('lines.0.unit_price', '1000.00')
            ->assertSet('lines.0.vat_rate', '18')
            ->assertSet('lines.0.vat_treatment', 'standard')
            // Ставките од скен се слободен текст: без артикл нема поместување
            // залиха и нема потреба од магацин.
            ->assertSet('lines.0.item_id', '')
            ->assertSet('warehouseId', '');
    }

    public function test_a_failed_read_leaves_the_form_empty_and_reports_it(): void
    {
        $company = Company::factory()->create();
        $this->actAs($company, 'accountant');

        FakeScannedInvoiceReader::$throws = new ScannedInvoiceReadException('нема врска');

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('scanFile', $this->scan())
            ->call('readScan')
            ->assertSet('scanRead', false)
            ->assertSet('partnerId', '')
            ->assertHasErrors('scanFile');
    }

    public function test_a_client_cannot_read_scans(): void
    {
        $company = Company::factory()->create();
        $this->actAs($company, 'client');

        $component = Livewire::test(SalesInvoiceForm::class, ['company' => $company]);

        $this->assertFalse($component->instance()->canReadScans());

        $component->set('scanFile', $this->scan())->call('readScan')->assertForbidden();
    }

    public function test_without_a_key_the_reader_is_switched_off(): void
    {
        config(['services.anthropic.key' => null]);

        $company = Company::factory()->create();
        $this->actAs($company, 'admin');

        $this->assertFalse(
            Livewire::test(SalesInvoiceForm::class, ['company' => $company])->instance()->canReadScans()
        );
    }

    public function test_the_upload_field_is_hidden_when_reading_is_unavailable(): void
    {
        config(['services.anthropic.key' => null]);

        $company = Company::factory()->create();
        $this->actAs($company, 'admin');

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->assertDontSee('Прикачи скенирана фактура');
    }

    public function test_the_upload_field_is_shown_to_an_accountant_with_a_key(): void
    {
        $company = Company::factory()->create();
        $this->actAs($company, 'accountant');

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->assertSee('Прикачи скенирана фактура');
    }
}
```

- [ ] **Step 2: Пушти ги и потврди дека паѓаат**

Run: `php artisan test tests/Feature/Invoicing/SalesInvoiceScanReadTest.php`
Expected: FAIL — `Method readScan does not exist`

- [ ] **Step 3: Додај ги својствата и `canReadScans()`**

Во `app/Livewire/Invoicing/SalesInvoiceForm.php`, додај го trait-от за качување на врвот на класата:

```php
use Livewire\WithFileUploads;
```

и внатре во класата, веднаш по `class SalesInvoiceForm extends Component`:

```php
    use WithFileUploads;
```

По `public string $paperNumber = '';`:

```php
    public $scanFile = null;

    /**
     * Дали формата е пополнета од скен. Го отклучува полето за хартиениот број
     * и жолтата лента „провери пред потврда“.
     */
    public bool $scanRead = false;

    /** @var string[] Предупредувања од проверките врз прочитаното. */
    public array $scanWarnings = [];
```

И метода (стави ја над `save()`):

```php
    /**
     * Читањето чини пари од буџетот на канцеларијата, па клиентите остануваат
     * надвор иако смеат да создаваат фактури. Без клуч функцијата воопшто ја
     * нема — сервер без клуч работи како досега.
     */
    public function canReadScans(): bool
    {
        return filled(config('services.anthropic.key'))
            && auth()->user()?->hasAnyRole(['admin', 'accountant']);
    }
```

- [ ] **Step 4: Напиши ја `readScan()`**

Веднаш по `canReadScans()`:

```php
    public function readScan(): void
    {
        abort_unless($this->canReadScans(), 403);

        $this->resetErrorBag('scanFile');
        $this->scanWarnings = [];

        $this->validate([
            'scanFile' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        try {
            $scanned = app(ScannedInvoiceReader::class)->read($this->scanFile, $this->company);
        } catch (\Throwable $e) {
            report($e);
            $this->addError('scanFile', 'Не можев да ја прочитам фактурата — внеси ја рачно.');

            return;
        }

        $this->applyScan($scanned);
        $this->scanRead = true;
    }

    /**
     * Прочитаното се прелива во формата. Секое поле е незадолжително: ако Claude
     * не прочитал нешто, старата вредност останува и човекот ја дополнува.
     */
    private function applyScan(ScannedInvoice $scanned): void
    {
        if (filled($scanned->invoiceNumber)) {
            $this->paperNumber = substr($scanned->invoiceNumber, 0, 40);
        }

        if (filled($scanned->invoiceDate)) {
            $this->invoiceDate = $scanned->invoiceDate;
        }

        if (filled($scanned->dueDate)) {
            $this->dueDate = $scanned->dueDate;
        }

        if (filled($scanned->currency) && in_array($scanned->currency, SalesInvoice::CURRENCIES, true)) {
            $this->currency = $scanned->currency;
        }

        if (filled($scanned->buyerTaxId)) {
            $partner = Partner::where('company_id', $this->company->id)
                ->where('tax_id', $scanned->buyerTaxId)
                ->first();

            if ($partner) {
                $this->partnerId = (string) $partner->id;
            }
        }

        if ($scanned->lines !== []) {
            $this->lines = array_map(fn (ScannedInvoiceLine $line) => [
                // Ставките од скен се секогаш слободен текст. Врзувањето за
                // артикл од шифрарникот би повлекло и поместување залиха за
                // стока што веќе е издадена надвор од Тами.
                'item_id' => '',
                'description' => (string) $line->description,
                'quantity' => filled($line->quantity) ? $line->quantity : '1',
                'unit_price' => filled($line->unitPrice) ? $line->unitPrice : '0',
                'vat_rate' => filled($line->vatRate) ? $line->vatRate : '0.00',
                // Ослободувањата и преносот на обврска не ги погодува машина.
                'vat_treatment' => 'standard',
            ], $scanned->lines);
        }
    }
```

Додај ги потребните `use` изјави на врвот на фајлот:

```php
use App\Services\Invoicing\ScannedInvoice;
use App\Services\Invoicing\ScannedInvoiceLine;
use App\Services\Invoicing\ScannedInvoiceReader;
```

- [ ] **Step 5: Додај ја картичката во екранот**

Во `resources/views/livewire/invoicing/sales-invoice-form.blade.php`, веднаш по `<h1>...</h1>` и **пред** `<form wire:submit="save" ...>`:

```blade
    @if ($this->canReadScans() && ! $salesInvoice)
        <x-card class="mb-4">
            <x-input-label for="scanFile" value="Прикачи скенирана фактура" />
            <p class="text-xs text-gray-500 mt-1 mb-2">PDF, JPG или PNG, до 10 МБ. Тами ќе ја прочита и ќе ги пополни полињата подолу.</p>
            <div class="flex items-center gap-3">
                <input id="scanFile" type="file" wire:model="scanFile" accept=".pdf,.jpg,.jpeg,.png" class="text-sm" />
                <x-secondary-button type="button" wire:click="readScan" wire:loading.attr="disabled" wire:target="readScan,scanFile">
                    <span wire:loading.remove wire:target="readScan">Прочитај ја фактурата</span>
                    <span wire:loading wire:target="readScan">Читам…</span>
                </x-secondary-button>
            </div>
            @error('scanFile') <p class="text-red-600 text-sm mt-2">{{ $message }}</p> @enderror
        </x-card>
    @endif

    @if ($scanRead)
        <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Податоците се прочитани од скен — провери ги пред потврда.
            @foreach ($scanWarnings as $warning)
                <p class="mt-1 font-semibold">{{ $warning }}</p>
            @endforeach
        </div>
    @endif
```

`x-secondary-button` веќе постои во `resources/views/components/secondary-button.blade.php`.

- [ ] **Step 6: Пушти ги тестовите**

Run: `php artisan test tests/Feature/Invoicing/SalesInvoiceScanReadTest.php`
Expected: PASS — 6 тестa

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Invoicing/SalesInvoiceForm.php resources/views/livewire/invoicing/sales-invoice-form.blade.php tests/Feature/Invoicing/SalesInvoiceScanReadTest.php
git commit -m "feat: прикачување и читање скенирана фактура во формата

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: Трите проверки врз прочитаното

**Files:**
- Modify: `app/Livewire/Invoicing/SalesInvoiceForm.php` (`applyScan()`, нова `createSuggestedPartner()`)
- Modify: `resources/views/livewire/invoicing/sales-invoice-form.blade.php` (блок за понудениот партнер)
- Test: `tests/Feature/Invoicing/SalesInvoiceScanChecksTest.php`

**Interfaces:**
- Consumes: сè од задача 4.
- Produces: на `SalesInvoiceForm` — `public ?array $suggestedPartner = null;` (клучеви: `name`, `tax_id`, `street_address`, `street_number`, `postal_code`, `city`), метода `createSuggestedPartner(): void`. `$scanWarnings` се полни од трите проверки.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

`tests/Feature/Invoicing/SalesInvoiceScanChecksTest.php`:

```php
<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use App\Services\Invoicing\ScannedInvoice;
use App\Services\Invoicing\ScannedInvoiceLine;
use App\Services\Invoicing\ScannedInvoiceReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeScannedInvoiceReader;
use Tests\TestCase;

class SalesInvoiceScanChecksTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        FakeScannedInvoiceReader::reset();
        Storage::fake('local');
        config(['services.anthropic.key' => 'test-key']);
        $this->app->bind(ScannedInvoiceReader::class, FakeScannedInvoiceReader::class);
    }

    private function company(): Company
    {
        $company = Company::factory()->create(['tax_id' => '4080012345678']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('admin');
        $this->actingAs($user);

        return $company;
    }

    private function read(Company $company)
    {
        return Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('scanFile', UploadedFile::fake()->create('faktura.pdf', 200, 'application/pdf'))
            ->call('readScan');
    }

    public function test_a_foreign_seller_tax_id_warns_about_the_wrong_file(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080099999999',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $warnings = $this->read($company)->get('scanWarnings');

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('влезна', implode(' ', $warnings));
    }

    public function test_a_matching_seller_tax_id_does_not_warn(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $this->assertStringNotContainsString('влезна', implode(' ', $this->read($company)->get('scanWarnings')));
    }

    public function test_an_unknown_buyer_is_offered_for_creation(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            buyerName: 'Нов Купувач ДООЕЛ',
            buyerTaxId: '4080055555555',
            buyerStreetAddress: 'Партизанска',
            buyerStreetNumber: '10',
            buyerPostalCode: '1000',
            buyerCity: 'Скопје',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $component = $this->read($company);

        $this->assertSame('Нов Купувач ДООЕЛ', $component->get('suggestedPartner')['name']);
        $this->assertSame('', $component->get('partnerId'));

        // Ништо не смее да влезе во шифрарникот без клик.
        $this->assertDatabaseMissing('partners', ['tax_id' => '4080055555555']);

        $component->call('createSuggestedPartner');

        $this->assertDatabaseHas('partners', [
            'company_id' => $company->id,
            'tax_id' => '4080055555555',
            'name' => 'Нов Купувач ДООЕЛ',
            'city' => 'Скопје',
        ]);

        $partner = Partner::where('tax_id', '4080055555555')->first();
        $component->assertSet('partnerId', (string) $partner->id)
            ->assertSet('suggestedPartner', null);
    }

    public function test_a_known_buyer_is_selected_and_not_offered(): void
    {
        $company = $this->company();
        $partner = Partner::factory()->for($company)->create(['tax_id' => '4080055555555']);

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            buyerTaxId: '4080055555555',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $this->read($company)
            ->assertSet('partnerId', (string) $partner->id)
            ->assertSet('suggestedPartner', null);
    }

    public function test_a_total_that_does_not_add_up_warns(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            // 1 × 1000 + 18% = 1180, а на хартијата пишува 1500.
            printedTotal: '1500.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $warnings = implode(' ', $this->read($company)->get('scanWarnings'));

        $this->assertStringContainsString('1500.00', $warnings);
        $this->assertStringContainsString('1180.00', $warnings);
    }

    public function test_a_total_within_one_denar_does_not_warn(): void
    {
        $company = $this->company();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            printedTotal: '1180.50',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $this->assertSame([], $this->read($company)->get('scanWarnings'));
    }
}
```

- [ ] **Step 2: Пушти ги и потврди дека паѓаат**

Run: `php artisan test tests/Feature/Invoicing/SalesInvoiceScanChecksTest.php`
Expected: FAIL — `Property [$suggestedPartner] not found on component`

- [ ] **Step 3: Додај го својството за понудениот партнер**

Во `SalesInvoiceForm`, по `public array $scanWarnings = [];`:

```php
    /**
     * Купувач прочитан од скен што го нема во шифрарникот.
     *
     * Стои само во меморија додека човекот не кликне „Создај партнер“ — лошо
     * прочитано име не смее тивко да се залепи во шифрарникот и да се чисти
     * подоцна.
     *
     * @var array{name: string, tax_id: string, street_address: string, street_number: string, postal_code: string, city: string}|null
     */
    public ?array $suggestedPartner = null;
```

- [ ] **Step 4: Додај ги трите проверки во `applyScan()`**

На почетокот на `applyScan()`, пред сè друго:

```php
        $this->suggestedPartner = null;

        // Проверка 1: качен погрешен фајл. Ако продавачот на хартијата не е оваа
        // фирма, најверојатно е влезна фактура во папката на излезните.
        if (filled($scanned->sellerTaxId) && filled($this->company->tax_id)
            && $this->digits($scanned->sellerTaxId) !== $this->digits($this->company->tax_id)) {
            $this->scanWarnings[] = 'Изгледа дека ова е влезна, не излезна фактура — провери го фајлот.';
        }
```

Замени го блокот за партнерот (од задача 4) со:

```php
        // Проверка 2: партнер по ЕДБ. Точно совпаѓање, без погодување по име —
        // две фирми со слично име се почеста грешка од непостоечки ЕДБ.
        if (filled($scanned->buyerTaxId)) {
            $partner = Partner::where('company_id', $this->company->id)
                ->where('tax_id', $scanned->buyerTaxId)
                ->first();

            if ($partner) {
                $this->partnerId = (string) $partner->id;
            } else {
                $this->suggestedPartner = [
                    'name' => (string) $scanned->buyerName,
                    'tax_id' => (string) $scanned->buyerTaxId,
                    'street_address' => (string) $scanned->buyerStreetAddress,
                    'street_number' => (string) $scanned->buyerStreetNumber,
                    'postal_code' => (string) $scanned->buyerPostalCode,
                    'city' => (string) $scanned->buyerCity,
                ];
            }
        }
```

На крајот на `applyScan()`, по полнењето на `$this->lines`:

```php
        // Проверка 3: сметката мора да се сложи. Пресметаното од ставките се
        // спротивставува со вкупното испишано на хартијата — најсилната
        // заштита од погрешно прочитана бројка.
        if (filled($scanned->printedTotal)) {
            $computed = '0.00';

            foreach ($this->lines as $line) {
                $net = bcmul((string) $line['unit_price'], (string) $line['quantity'], 4);
                $vat = bcdiv(bcmul($net, (string) $line['vat_rate'], 4), '100', 4);
                $computed = bcadd($computed, bcadd($net, $vat, 4), 4);
            }

            $computed = number_format((float) $computed, 2, '.', '');
            $printed = number_format((float) $scanned->printedTotal, 2, '.', '');

            if (abs((float) $computed - (float) $printed) > 1.0) {
                $this->scanWarnings[] = "Пресметаното вкупно е {$computed}, а на скенот пишува {$printed} — провери ги ставките.";
            }
        }
```

И помошна метода на крајот на класата:

```php
    /**
     * ЕДБ-то на хартија понекогаш носи префикс „МК“ или празни места. Се
     * споредуваат само цифрите.
     */
    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
```

- [ ] **Step 5: Напиши ја `createSuggestedPartner()`**

По `readScan()`:

```php
    public function createSuggestedPartner(): void
    {
        abort_unless($this->canReadScans(), 403);

        if ($this->suggestedPartner === null) {
            return;
        }

        $partner = Partner::create([
            'company_id' => $this->company->id,
            'name' => $this->suggestedPartner['name'],
            'tax_id' => $this->suggestedPartner['tax_id'],
            'street_address' => $this->suggestedPartner['street_address'] ?: null,
            'street_number' => $this->suggestedPartner['street_number'] ?: null,
            'postal_code' => $this->suggestedPartner['postal_code'] ?: null,
            'city' => $this->suggestedPartner['city'] ?: null,
        ]);

        $this->partnerId = (string) $partner->id;
        $this->suggestedPartner = null;
    }
```

- [ ] **Step 6: Додај го блокот во екранот**

Во `sales-invoice-form.blade.php`, веднаш по жолтата лента `@if ($scanRead)`:

```blade
    @if ($suggestedPartner)
        <div class="mb-4 rounded-lg border border-blue-300 bg-blue-50 px-4 py-3 text-sm">
            <p class="font-semibold text-blue-900">Купувачот од скенот го нема во шифрарникот.</p>
            <p class="mt-1 text-blue-900">
                {{ $suggestedPartner['name'] }} — ЕДБ {{ $suggestedPartner['tax_id'] }}
                @if ($suggestedPartner['city'])
                    , {{ $suggestedPartner['street_address'] }} {{ $suggestedPartner['street_number'] }}, {{ $suggestedPartner['postal_code'] }} {{ $suggestedPartner['city'] }}
                @endif
            </p>
            <x-secondary-button type="button" wire:click="createSuggestedPartner" class="mt-2">Создај партнер</x-secondary-button>
        </div>
    @endif
```

- [ ] **Step 7: Пушти ги тестовите**

Run: `php artisan test tests/Feature/Invoicing/SalesInvoiceScanChecksTest.php`
Expected: PASS — 6 тестa

- [ ] **Step 8: Пушти ги и тестовите од задача 4 да не е скршено полнењето**

Run: `php artisan test tests/Feature/Invoicing/SalesInvoiceScanReadTest.php`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/Invoicing/SalesInvoiceForm.php resources/views/livewire/invoicing/sales-invoice-form.blade.php tests/Feature/Invoicing/SalesInvoiceScanChecksTest.php
git commit -m "feat: проверки врз прочитаната скенирана фактура

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 6: Скенот се закачува како прилог

**Files:**
- Modify: `app/Livewire/Invoicing/SalesInvoiceForm.php` (`save()`)
- Test: `tests/Feature/Invoicing/SalesInvoiceScanAttachmentTest.php`

**Interfaces:**
- Consumes: `$scanFile`, `$scanRead` (задача 4); `App\Services\DocumentStorage`.
- Produces: по успешно зачувување, `Document` со `category = 'Invoice'`, врзан за фактурата.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

`tests/Feature/Invoicing/SalesInvoiceScanAttachmentTest.php`:

```php
<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\ScannedInvoice;
use App\Services\Invoicing\ScannedInvoiceLine;
use App\Services\Invoicing\ScannedInvoiceReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeScannedInvoiceReader;
use Tests\TestCase;

class SalesInvoiceScanAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        FakeScannedInvoiceReader::reset();
        Storage::fake('local');
        Storage::fake('google');
        config(['services.anthropic.key' => 'test-key']);
        $this->app->bind(ScannedInvoiceReader::class, FakeScannedInvoiceReader::class);
    }

    public function test_the_scan_is_attached_to_the_saved_invoice(): void
    {
        $company = Company::factory()->create(['tax_id' => '4080012345678']);
        $partner = Partner::factory()->for($company)->create(['tax_id' => '4080055555555']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('admin');
        $this->actingAs($user);

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            buyerTaxId: '4080055555555',
            invoiceNumber: '2026/45',
            invoiceDate: '2026-03-01',
            dueDate: '2026-03-15',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('scanFile', UploadedFile::fake()->create('faktura.pdf', 200, 'application/pdf'))
            ->call('readScan')
            ->call('save')
            ->assertHasNoErrors();

        $invoice = SalesInvoice::where('company_id', $company->id)->firstOrFail();

        $this->assertDatabaseHas('documents', [
            'company_id' => $company->id,
            'documentable_id' => $invoice->id,
            'original_filename' => 'faktura.pdf',
            'category' => 'Invoice',
        ]);
    }

    public function test_an_invoice_saved_without_a_scan_has_no_attachment(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('admin');
        $this->actingAs($user);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines', [[
                'item_id' => '', 'description' => 'Услуга', 'quantity' => '1',
                'unit_price' => '1000.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard',
            ]])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('documents', 0);
    }
}
```

- [ ] **Step 2: Пушти ги и потврди дека паѓаат**

Run: `php artisan test tests/Feature/Invoicing/SalesInvoiceScanAttachmentTest.php`
Expected: FAIL — `documents` табелата е празна во првиот тест

- [ ] **Step 3: Закачи го скенот при зачувување**

Во `save()`, **по** `DB::transaction(...)` и **пред** `$this->redirect(...)`:

```php
        // Качувањето оди по трансакцијата намерно: складот е Google Drive, а
        // мрежен повик внатре во отворена трансакција ја држи базата заклучена
        // додека трае. Ако качувањето падне, фактурата е веќе зачувана и
        // прилогот може да се додаде рачно од екранот на фактурата.
        if ($this->scanFile !== null && $this->scanRead) {
            try {
                DocumentStorage::store($this->salesInvoice, $this->scanFile, 'Invoice', 'Скенирана фактура');
            } catch (\Throwable $e) {
                report($e);
                session()->flash('warning', 'Фактурата е зачувана, но скенот не се прикачи — додај го рачно од екранот на фактурата.');
            }

            $this->scanFile = null;
        }
```

Додај го внесот на врвот на фајлот:

```php
use App\Services\DocumentStorage;
```

- [ ] **Step 4: Пушти ги тестовите**

Run: `php artisan test tests/Feature/Invoicing/SalesInvoiceScanAttachmentTest.php`
Expected: PASS — 2 тестa

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Invoicing/SalesInvoiceForm.php tests/Feature/Invoicing/SalesInvoiceScanAttachmentTest.php
git commit -m "feat: скенот се закачува како прилог на фактурата

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 7: Вистинскиот читач преку Anthropic

Последна задача намерно: сè друго е веќе докажано со двојник, па тука се докажува само преводот од одговор на Claude во `ScannedInvoice`.

**Files:**
- Modify: `composer.json` (нова зависност)
- Create: `app/Services/Invoicing/ClaudeScannedInvoiceReader.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Invoicing/ClaudeScannedInvoiceReaderTest.php`

**Interfaces:**
- Consumes: `ScannedInvoiceReader`, `ScannedInvoice`, `ScannedInvoiceLine`, `ScannedInvoiceReadException` (задача 1).
- Produces: `ClaudeScannedInvoiceReader` со `public static function toScannedInvoice(array $payload): ScannedInvoice` — јавна и статична за да може преводот да се тестира без мрежа.

- [ ] **Step 1: Инсталирај го SDK-то**

```bash
composer require "anthropic-ai/sdk"
```

- [ ] **Step 2: Напиши го тестот што паѓа**

`tests/Feature/Invoicing/ClaudeScannedInvoiceReaderTest.php`:

```php
<?php

namespace Tests\Feature\Invoicing;

use App\Services\Invoicing\ClaudeScannedInvoiceReader;
use Tests\TestCase;

class ClaudeScannedInvoiceReaderTest extends TestCase
{
    public function test_a_full_payload_becomes_a_scanned_invoice(): void
    {
        $result = ClaudeScannedInvoiceReader::toScannedInvoice([
            'seller_tax_id' => '4080012345678',
            'buyer_name' => 'Купувач ДООЕЛ',
            'buyer_tax_id' => '4080055555555',
            'buyer_street_address' => 'Партизанска',
            'buyer_street_number' => '10',
            'buyer_postal_code' => '1000',
            'buyer_city' => 'Скопје',
            'invoice_number' => '2026/45',
            'invoice_date' => '2026-03-01',
            'due_date' => '2026-03-15',
            'currency' => 'MKD',
            'printed_total' => '1180.00',
            'lines' => [
                ['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18'],
            ],
        ]);

        $this->assertSame('Купувач ДООЕЛ', $result->buyerName);
        $this->assertSame('2026/45', $result->invoiceNumber);
        $this->assertSame('Скопје', $result->buyerCity);
        $this->assertCount(1, $result->lines);
        $this->assertSame('1000.00', $result->lines[0]->unitPrice);
    }

    public function test_missing_fields_become_null_instead_of_breaking(): void
    {
        $result = ClaudeScannedInvoiceReader::toScannedInvoice(['invoice_number' => '7']);

        $this->assertSame('7', $result->invoiceNumber);
        $this->assertNull($result->buyerName);
        $this->assertNull($result->printedTotal);
        $this->assertSame([], $result->lines);
    }

    public function test_lines_that_are_not_a_list_are_ignored(): void
    {
        $result = ClaudeScannedInvoiceReader::toScannedInvoice(['lines' => 'нешто чудно']);

        $this->assertSame([], $result->lines);
    }

    public function test_numbers_returned_as_numbers_become_strings(): void
    {
        $result = ClaudeScannedInvoiceReader::toScannedInvoice([
            'printed_total' => 1180.5,
            'lines' => [['description' => 'Услуга', 'quantity' => 2, 'unit_price' => 500, 'vat_rate' => 18]],
        ]);

        $this->assertSame('1180.5', $result->printedTotal);
        $this->assertSame('2', $result->lines[0]->quantity);
        $this->assertSame('500', $result->lines[0]->unitPrice);
        $this->assertSame('18', $result->lines[0]->vatRate);
    }
}
```

- [ ] **Step 3: Пушти го и потврди дека паѓа**

Run: `php artisan test tests/Feature/Invoicing/ClaudeScannedInvoiceReaderTest.php`
Expected: FAIL — `Class "App\Services\Invoicing\ClaudeScannedInvoiceReader" not found`

- [ ] **Step 4: Напиши го читачот**

`app/Services/Invoicing/ClaudeScannedInvoiceReader.php`:

```php
<?php

namespace App\Services\Invoicing;

use Anthropic\Client;
use App\Models\Company;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Го чита скенот преку Claude и го враќа прочитаното како обичен објект.
 *
 * Единственото место во апликацијата што знае дека постои надворешен сервис.
 * Моделот е Haiku 4.5 свесно: секоја фактура и онака ја прегледува човек пред
 * потврда, па поевтиниот модел не носи ризик што евтиниот тон не го покрива.
 */
class ClaudeScannedInvoiceReader implements ScannedInvoiceReader
{
    private const MODEL = 'claude-haiku-4-5';

    /** Цена по милион токени во УСД, за приближната сметка во дневникот. */
    private const INPUT_PRICE = 1.0;

    private const OUTPUT_PRICE = 5.0;

    public function read(TemporaryUploadedFile $file, Company $company): ScannedInvoice
    {
        $key = config('services.anthropic.key');

        if (blank($key)) {
            throw new ScannedInvoiceReadException('Нема клуч за Anthropic.');
        }

        $data = base64_encode($file->get());
        $mime = $file->getMimeType();

        $block = $mime === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $data]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $data]];

        try {
            $message = (new Client(apiKey: $key))->messages->create(
                model: self::MODEL,
                maxTokens: 4096,
                messages: [[
                    'role' => 'user',
                    'content' => [$block, ['type' => 'text', 'text' => $this->prompt($company)]],
                ]],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $this->schema()]],
            );
        } catch (\Throwable $e) {
            throw new ScannedInvoiceReadException('Читањето не успеа: '.$e->getMessage(), previous: $e);
        }

        $this->logCost($company, $message);

        foreach ($message->content as $contentBlock) {
            if ($contentBlock->type === 'text') {
                $payload = json_decode($contentBlock->text, true);

                if (! is_array($payload)) {
                    throw new ScannedInvoiceReadException('Одговорот не е употреблив.');
                }

                return self::toScannedInvoice($payload);
            }
        }

        throw new ScannedInvoiceReadException('Одговорот не содржи текст.');
    }

    /**
     * Преводот е јавен и статичен за да може да се тестира без мрежа — тоа е
     * делот што најверојатно ќе се менува, а најскапо е да се тестира преку API.
     */
    public static function toScannedInvoice(array $payload): ScannedInvoice
    {
        $text = static fn (string $key) => isset($payload[$key]) && $payload[$key] !== ''
            ? (string) $payload[$key]
            : null;

        $lines = [];

        if (isset($payload['lines']) && is_array($payload['lines'])) {
            foreach ($payload['lines'] as $line) {
                if (! is_array($line)) {
                    continue;
                }

                $lineText = static fn (string $key) => isset($line[$key]) && $line[$key] !== ''
                    ? (string) $line[$key]
                    : null;

                $lines[] = new ScannedInvoiceLine(
                    description: $lineText('description'),
                    quantity: $lineText('quantity'),
                    unitPrice: $lineText('unit_price'),
                    vatRate: $lineText('vat_rate'),
                );
            }
        }

        return new ScannedInvoice(
            sellerTaxId: $text('seller_tax_id'),
            buyerName: $text('buyer_name'),
            buyerTaxId: $text('buyer_tax_id'),
            buyerStreetAddress: $text('buyer_street_address'),
            buyerStreetNumber: $text('buyer_street_number'),
            buyerPostalCode: $text('buyer_postal_code'),
            buyerCity: $text('buyer_city'),
            invoiceNumber: $text('invoice_number'),
            invoiceDate: $text('invoice_date'),
            dueDate: $text('due_date'),
            currency: $text('currency'),
            printedTotal: $text('printed_total'),
            lines: $lines,
        );
    }

    private function prompt(Company $company): string
    {
        return <<<TEXT
        Ова е фактура издадена од фирмата "{$company->name}" со ЕДБ {$company->tax_id}.
        Таа фирма е ПРОДАВАЧОТ. Извади ја ДРУГАТА страна како купувач.

        Извади го само она што навистина е испишано на документот. Ако нешто го
        нема или не можеш да го прочиташ со сигурност, врати празен стринг за тоа
        поле — не погодувај.

        Датумите врати ги во формат ГГГГ-ММ-ДД.
        Износите врати ги како броеви со точка за децимала, без ознака за валута
        и без разделник за илјади.
        ДДВ стапката врати ја како број без знакот за процент (на пример: 18).
        Во "printed_total" врати го ВКУПНИОТ износ за плаќање како што е испишан
        на документот, со ДДВ.
        TEXT;
    }

    private function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => [
                'seller_tax_id' => $string,
                'buyer_name' => $string,
                'buyer_tax_id' => $string,
                'buyer_street_address' => $string,
                'buyer_street_number' => $string,
                'buyer_postal_code' => $string,
                'buyer_city' => $string,
                'invoice_number' => $string,
                'invoice_date' => $string,
                'due_date' => $string,
                'currency' => $string,
                'printed_total' => $string,
                'lines' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'description' => $string,
                            'quantity' => $string,
                            'unit_price' => $string,
                            'vat_rate' => $string,
                        ],
                        'required' => ['description', 'quantity', 'unit_price', 'vat_rate'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => [
                'seller_tax_id', 'buyer_name', 'buyer_tax_id', 'buyer_street_address',
                'buyer_street_number', 'buyer_postal_code', 'buyer_city', 'invoice_number',
                'invoice_date', 'due_date', 'currency', 'printed_total', 'lines',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Трошокот се запишува за да може на крајот на месецот да се прочита од
     * дневникот, наместо да се чека сметката.
     */
    private function logCost(Company $company, mixed $message): void
    {
        $input = (int) ($message->usage->inputTokens ?? 0);
        $output = (int) ($message->usage->outputTokens ?? 0);
        $usd = ($input / 1_000_000 * self::INPUT_PRICE) + ($output / 1_000_000 * self::OUTPUT_PRICE);

        Log::info('Прочитана скенирана фактура', [
            'company_id' => $company->id,
            'user_id' => auth()->id(),
            'model' => self::MODEL,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'usd' => round($usd, 5),
        ]);
    }
}
```

Ако имињата на својствата за токени во SDK-то се поинакви од `inputTokens`/`outputTokens`, точни се имињата од самиот SDK — прочитај ги од `vendor/anthropic-ai/sdk/` и поправи ги; `?? 0` штити дневникот да не пукне.

- [ ] **Step 5: Замени го врзувањето**

Во `app/Providers/AppServiceProvider.php`, замени го врзувањето од задача 1 со:

```php
        // Без клуч се врзува читачот што не чита — апликацијата мора да се
        // подигне и на сервер каде клучот не е поставен.
        $this->app->bind(
            \App\Services\Invoicing\ScannedInvoiceReader::class,
            fn () => filled(config('services.anthropic.key'))
                ? new \App\Services\Invoicing\ClaudeScannedInvoiceReader
                : new \App\Services\Invoicing\NullScannedInvoiceReader,
        );
```

- [ ] **Step 6: Пушти ги тестовите**

Run: `php artisan test tests/Feature/Invoicing/ClaudeScannedInvoiceReaderTest.php`
Expected: PASS — 4 тестa

- [ ] **Step 7: Пушти ја целата серија**

Run: `php artisan test`
Expected: PASS — сите тестови, вклучително постоечките 1541

- [ ] **Step 8: Пресоздај го Tailwind**

Blade-фајловите добија нови класи, па JIT мора да мине последен пат откако сè е на место.

```bash
npm run build
```

- [ ] **Step 9: Commit**

```bash
git add composer.json composer.lock app/Services/Invoicing/ClaudeScannedInvoiceReader.php app/Providers/AppServiceProvider.php tests/Feature/Invoicing/ClaudeScannedInvoiceReaderTest.php public/build
git commit -m "feat: читање скенирана фактура преку Claude

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Рачна проверка пред спојување

Ова не може да го покрие тест — бара вистинска фактура и вистински клуч.

- [ ] Постави `ANTHROPIC_API_KEY` во `.env` локално.
- [ ] Земи една вистинска скенирана излезна фактура од клиент.
- [ ] Прикачи ја, прочитај ја, спореди ги сите полиња со хартијата.
- [ ] Провери дека бројот на екранот е истиот како на хартијата.
- [ ] Потврди ја фактурата и провери дека `invoice_number` во базата е `NULL`, а `invoice_number_formatted` го носи хартиениот број.
- [ ] Отвори го PDF-от на фактурата и провери дека бројот е хартиениот.
- [ ] Ако фирмата има потпишувачки уред: испрати ја кон УЈП и потврди дека `docNumber` е хартиениот број.
- [ ] Провери во `storage/logs/laravel.log` дека има ред `Прочитана скенирана фактура` со потрошени токени и цена.
