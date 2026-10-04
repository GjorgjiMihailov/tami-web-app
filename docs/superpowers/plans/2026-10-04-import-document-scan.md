# Скен на увозни документи Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Три прикачени документи (фактура од добавувач, ЕЦД, шпедитерска фактура) ја полнат формата за увозна влезна фактура: ЕЦД податоци + тарифни редови, ставки претворени во денари по курсот од ЕЦД, транспортот и шпедитерот како „Увозни трошоци", проверки како предупредувања.

**Architecture:** Нов читач за ЕЦД (`CustomsDeclarationReader`, Claude Sonnet) по истиот образец како `ScannedInvoiceReader`. Чисти класи (`CustomsTariffAggregator`, `ImportScanMapper`, `ImportScanChecks`) го носат целиот превод/проверки без Livewire и без мрежа. `PurchaseInvoiceForm::readImportDocuments()` само ги чита документите по редослед ЕЦД → фактура → шпедитер и ги става резултатите во својствата на формата; фактурата поминува низ веќе постоечкиот `applyScan()` откако ќе се претвори во денари.

**Tech Stack:** Laravel 13, Livewire 3, Blade/Tailwind, bcmath преку `App\Support\Bcmath`, `anthropic` SDK (`Anthropic\Client`), PHPUnit.

## Global Constraints

- Спецификација: `docs/superpowers/specs/2026-10-04-import-document-scan-design.md` — прочитај ја пред да почнеш.
- **Ревидирано по завршната ревизија (одлука на сопственикот):** ставката „транспорт“ (`kind = charge`) на фактурата од добавувач НЕ се префрла надвор од фактурата — остануваат ставка (без артикл, ДДВ 0, сметка со шифра `660`, претворена по ист курс), и истовремено се враќа како ред „Увозни трошоци“ за да влезе во магацинската вредност. Инаку долгот кон добавувачот (сметка 220) би бил помал од хартијата. Кодот и тестовите подолу го опишуваат првичниот чекор; важечко е ова. Исто така додадено: валута на фактурата што не е прочитана (курс од ЕЦД или предупредување), чистење на редовите од фактурата при повторно читање во денари, ограничување на големина на слика по место, `\Throwable` по документ, ДДВ на артикли создадени при увоз = стандардниот на фирмата, и проверки 9 (збир на ставки наспроти испишано вкупно) и 10 (шпедитерска со износ еднаков на царина/ДДВ).
- Модел за ЕЦД: **`claude-sonnet-5-5`** (Haiku греши на вистински ЕЦД скен). Фактурата и шпедитерската остануваат на `claude-haiku-4-5`.
- Никаде во тестовите нема мрежа и нема вистински API повик: читачите се менуваат со двојници (`tests/Support/Fake*Reader`); `Http::fake` за НБРМ.
- Вистинските документи (PDF/слики од клиенти) НИКОГАШ не влегуваат во репото. Фикстурите се измислени (фирми, ЕДБ, броеви).
- Износи од скен се нормализираат со `ClaudeScannedInvoiceReader::normalizeAmount($v, thousands: bool)` / `normalizeCurrency()`; износи што ги внесува корисник се нормализираат со `App\Support\VatMath::number()` пред секој bcmath повик (запирка како децимала не смее да руши со 500).
- Пред секој bcmath повик врз вредност од скен/корисник: `App\Support\Bcmath::isPlainNumber(?string)` (додаден во Task 2), НИКОГАШ `is_numeric` (тој прима „ 12“, „1e3“, а bcmath фрла ValueError).
- Секое заокружување е half-up преку `App\Support\Bcmath::roundHalfUp(string $value, int $scale)`; никогаш голо `bcmul`/`bcdiv` како резултат (тоа сече).
- Книжењето не се менува: ништо во овој план не допира `JournalEntry`/сметка 660.
- Читањето е само за `canReadScans()` (админ/сметководител + клуч). Текстовите на екран се на македонски (ист стил на остатокот).
- Износите за ЕЦД се само: A00 = царина, B00 = ДДВ; секоја друга давачка → предупредување, не се пренесува.
- Курс: ЕЦД поле 23; без ЕЦД → `ExchangeRateService::getRate()` на датумот на фактурата + предупредување.

---

### Task 1: ЕЦД читач (DTO, интерфејс, Claude читач, двојник, врзување)

**Files:**
- Create: `app/Services/Invoicing/ScannedCustomsItem.php`
- Create: `app/Services/Invoicing/ScannedCustomsDeclaration.php`
- Create: `app/Services/Invoicing/CustomsDeclarationReader.php`
- Create: `app/Services/Invoicing/NullCustomsDeclarationReader.php`
- Create: `app/Services/Invoicing/ClaudeCustomsDeclarationReader.php`
- Create: `tests/Support/FakeCustomsDeclarationReader.php`
- Modify: `app/Providers/AppServiceProvider.php` (врзување, веднаш под постојното за `ScannedInvoiceReader`)
- Test: `tests/Feature/Invoicing/ClaudeCustomsDeclarationReaderTest.php`

**Interfaces:**
- Produces: `ScannedCustomsItem(?string $tariffCode, ?string $description, ?string $invoiceValueForeign, ?string $statisticalValue, array $charges)` — `$charges` е `array<string,string>` (код → износ, пр. `['A00' => '974', 'B00' => '1177']`).
- Produces: `ScannedCustomsDeclaration(?string $declarationNumber, ?string $date, ?string $importerName, ?string $importerTaxId, ?string $declarantName, ?string $currency, ?string $invoiceTotalForeign, ?string $exchangeRate, ?string $totalDuty, ?string $totalVat, array $referencedInvoiceNumbers, array $items)` — сè именувани, сите по ред како погоре, `$items` е `ScannedCustomsItem[]`, `$referencedInvoiceNumbers` е `string[]`.
- Produces: `interface CustomsDeclarationReader { public function read(UploadedFile $file, Company $company): ScannedCustomsDeclaration; }` (фрла `ScannedInvoiceReadException`).
- Produces: `ClaudeCustomsDeclarationReader::toDeclaration(array $payload): ScannedCustomsDeclaration` (public static, за тестови).
- Produces: `Tests\Support\FakeCustomsDeclarationReader` со `public static ?ScannedCustomsDeclaration $next`, `public static ?\Throwable $throws`, `reset()`.

- [ ] **Step 1: Напиши го тестот што паѓа**

`tests/Feature/Invoicing/ClaudeCustomsDeclarationReaderTest.php`:

```php
<?php

namespace Tests\Feature\Invoicing;

use App\Services\Invoicing\ClaudeCustomsDeclarationReader;
use PHPUnit\Framework\TestCase;

class ClaudeCustomsDeclarationReaderTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'ecd_number' => '26MKIM00000001C000',
            'date' => '2026-03-04',
            'importer_name' => 'ТЕСТ УВОЗНИК ДООЕЛ',
            'importer_tax_id' => 'MK4000000000001',
            'declarant_name' => 'ТЕСТ ШПЕДИТЕР',
            'currency' => 'EUR',
            'invoice_total_foreign' => '1.500,00',
            'exchange_rate' => '61.5000',
            'total_duty' => '1000',
            'total_vat' => '2000',
            'referenced_invoice_numbers' => ['T-0001/26'],
            'items' => [
                [
                    'tariff_code' => '6109 10 00',
                    'description' => 'МАИЦИ - ПАМУК',
                    'invoice_value_foreign' => '100.50',
                    'statistical_value' => '6.200',
                    'charges' => [
                        ['code' => 'A00', 'amount' => '1.250'],
                        ['code' => 'B00', 'amount' => '800'],
                    ],
                ],
            ],
        ], $overrides);
    }

    public function test_it_maps_the_payload_to_a_declaration(): void
    {
        $d = ClaudeCustomsDeclarationReader::toDeclaration($this->payload());

        $this->assertSame('26MKIM00000001C000', $d->declarationNumber);
        $this->assertSame('2026-03-04', $d->date);
        $this->assertSame('EUR', $d->currency);
        $this->assertSame('1500.00', $d->invoiceTotalForeign);
        $this->assertSame('61.5000', $d->exchangeRate);
        $this->assertSame('1000', $d->totalDuty);
        $this->assertSame(['T-0001/26'], $d->referencedInvoiceNumbers);
        $this->assertCount(1, $d->items);
        $this->assertSame('61091000', $d->items[0]->tariffCode);
        $this->assertSame('100.50', $d->items[0]->invoiceValueForeign);
        $this->assertSame('6200', $d->items[0]->statisticalValue);
        $this->assertSame(['A00' => '1250', 'B00' => '800'], $d->items[0]->charges);
    }

    public function test_missing_and_empty_fields_become_null(): void
    {
        $d = ClaudeCustomsDeclarationReader::toDeclaration(['ecd_number' => '', 'items' => []]);

        $this->assertNull($d->declarationNumber);
        $this->assertNull($d->exchangeRate);
        $this->assertSame([], $d->items);
        $this->assertSame([], $d->referencedInvoiceNumbers);
    }

    public function test_an_unreadable_amount_is_kept_as_the_original_string(): void
    {
        $d = ClaudeCustomsDeclarationReader::toDeclaration($this->payload([
            'items' => [['tariff_code' => '61091000', 'description' => '', 'invoice_value_foreign' => '23?.61', 'statistical_value' => '', 'charges' => []]],
        ]));

        $this->assertSame('23?.61', $d->items[0]->invoiceValueForeign);
    }

    public function test_is_blank_detects_a_reading_with_nothing_in_it(): void
    {
        $this->assertTrue(ClaudeCustomsDeclarationReader::isBlank(ClaudeCustomsDeclarationReader::toDeclaration(['items' => []])));
        $this->assertFalse(ClaudeCustomsDeclarationReader::isBlank(ClaudeCustomsDeclarationReader::toDeclaration($this->payload())));
    }

    public function test_the_prompt_mentions_the_charge_codes(): void
    {
        $this->assertStringContainsString('A00', ClaudeCustomsDeclarationReader::prompt());
        $this->assertStringContainsString('B00', ClaudeCustomsDeclarationReader::prompt());
    }
}
```

- [ ] **Step 2: Пушти го за да видиш дека паѓа**

Run: `php artisan test tests/Feature/Invoicing/ClaudeCustomsDeclarationReaderTest.php`
Expected: FAIL — `Class "App\Services\Invoicing\ClaudeCustomsDeclarationReader" not found`.

- [ ] **Step 3: DTO-а**

`app/Services/Invoicing/ScannedCustomsItem.php`:

```php
<?php

namespace App\Services\Invoicing;

/**
 * Една ставка од ЕЦД како што била прочитана од скен.
 *
 * `charges` е код на давачка → износ (A00 = царина, B00 = ДДВ). Сè е стринг и
 * незадолжително: скенот е фотографија, не база.
 */
final readonly class ScannedCustomsItem
{
    /**
     * @param  array<string, string>  $charges
     */
    public function __construct(
        public ?string $tariffCode = null,
        public ?string $description = null,
        public ?string $invoiceValueForeign = null,
        public ?string $statisticalValue = null,
        public array $charges = [],
    ) {}
}
```

`app/Services/Invoicing/ScannedCustomsDeclaration.php`:

```php
<?php

namespace App\Services\Invoicing;

/**
 * Сè што е прочитано од еден скен на царинска декларација (ЕЦД), пред
 * каква било проверка. `totalDuty`/`totalVat` се ВКУПНО од последната
 * страна и служат само за спротивставување со збирот од ставките.
 */
final readonly class ScannedCustomsDeclaration
{
    /**
     * @param  string[]  $referencedInvoiceNumbers
     * @param  ScannedCustomsItem[]  $items
     */
    public function __construct(
        public ?string $declarationNumber = null,
        public ?string $date = null,
        public ?string $importerName = null,
        public ?string $importerTaxId = null,
        public ?string $declarantName = null,
        public ?string $currency = null,
        public ?string $invoiceTotalForeign = null,
        public ?string $exchangeRate = null,
        public ?string $totalDuty = null,
        public ?string $totalVat = null,
        public array $referencedInvoiceNumbers = [],
        public array $items = [],
    ) {}
}
```

- [ ] **Step 4: Интерфејс, Null читач, двојник**

`app/Services/Invoicing/CustomsDeclarationReader.php`:

```php
<?php

namespace App\Services\Invoicing;

use App\Models\Company;
use Illuminate\Http\UploadedFile;

/**
 * Единствениот влез кон читањето на ЕЦД. Постои за да може целата серија
 * тестови да работи со двојник — ниту еден тест не праќа фајл надвор.
 */
interface CustomsDeclarationReader
{
    /**
     * @throws ScannedInvoiceReadException кога фајлот не може да се прочита
     */
    public function read(UploadedFile $file, Company $company): ScannedCustomsDeclaration;
}
```

`app/Services/Invoicing/NullCustomsDeclarationReader.php`:

```php
<?php

namespace App\Services\Invoicing;

use App\Models\Company;
use Illuminate\Http\UploadedFile;

/** Врзан кога нема клуч за Anthropic — врзувањето мора да успее и без клуч. */
class NullCustomsDeclarationReader implements CustomsDeclarationReader
{
    public function read(UploadedFile $file, Company $company): ScannedCustomsDeclaration
    {
        throw new ScannedInvoiceReadException('Читањето скен не е подесено на овој сервер.');
    }
}
```

`tests/Support/FakeCustomsDeclarationReader.php`:

```php
<?php

namespace Tests\Support;

use App\Models\Company;
use App\Services\Invoicing\CustomsDeclarationReader;
use App\Services\Invoicing\ScannedCustomsDeclaration;
use Illuminate\Http\UploadedFile;

/** Двојник за тестови; статичките полиња се чистат со `reset()` во `setUp()`. */
class FakeCustomsDeclarationReader implements CustomsDeclarationReader
{
    public static ?ScannedCustomsDeclaration $next = null;

    public static ?\Throwable $throws = null;

    public static function reset(): void
    {
        self::$next = null;
        self::$throws = null;
    }

    public function read(UploadedFile $file, Company $company): ScannedCustomsDeclaration
    {
        if (self::$throws !== null) {
            throw self::$throws;
        }

        return self::$next ?? new ScannedCustomsDeclaration;
    }
}
```

- [ ] **Step 5: Claude читачот**

`app/Services/Invoicing/ClaudeCustomsDeclarationReader.php`:

```php
<?php

namespace App\Services\Invoicing;

use Anthropic\Client;
use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Ја чита скенираната царинска декларација (ЕЦД) преку Claude.
 *
 * Моделот е Sonnet, не Haiku, свесно: врз вистински скен на ЕЦД (5 страни,
 * фотографија) Haiku погрешно го прочита ЕЦД бројот, пропушти ставки и врати
 * погрешен збир на царина. Sonnet ги прочита сите 11 ставки и
 * збировите излегоа точни. Цената е неколку центи по декларација.
 */
class ClaudeCustomsDeclarationReader implements CustomsDeclarationReader
{
    private const MODEL = 'claude-sonnet-5-5';

    private const TIMEOUT_SECONDS = 90.0;

    private const MAX_RETRIES = 1;

    public function read(UploadedFile $file, Company $company): ScannedCustomsDeclaration
    {
        $key = config('services.anthropic.key');

        if (blank($key)) {
            throw new ScannedInvoiceReadException('Нема клуч за Anthropic.');
        }

        try {
            $data = base64_encode($file->get());
            $mime = $file->getMimeType();

            $block = $mime === 'application/pdf'
                ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $data]]
                : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $data]];

            $client = new Client(apiKey: $key, requestOptions: [
                'transporter' => new \GuzzleHttp\Client(['timeout' => self::TIMEOUT_SECONDS]),
                'maxRetries' => self::MAX_RETRIES,
            ]);

            $message = $client->messages->create(
                model: self::MODEL,
                maxTokens: 12000,
                messages: [[
                    'role' => 'user',
                    'content' => [$block, ['type' => 'text', 'text' => self::prompt()]],
                ]],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => self::schema()]],
            );
        } catch (\Throwable $e) {
            throw new ScannedInvoiceReadException('Читањето не успеа: '.$e->getMessage(), previous: $e);
        }

        Log::info('Прочитана царинска декларација', [
            'company_id' => $company->id,
            'user_id' => auth()->id(),
            'model' => self::MODEL,
            'input_tokens' => (int) ($message->usage->inputTokens ?? 0),
            'output_tokens' => (int) ($message->usage->outputTokens ?? 0),
        ]);

        foreach ($message->content as $contentBlock) {
            if ($contentBlock->type === 'text') {
                $payload = json_decode($contentBlock->text, true);

                if (! is_array($payload)) {
                    throw new ScannedInvoiceReadException('Одговорот не е употреблив.');
                }

                $declaration = self::toDeclaration($payload);

                if (self::isBlank($declaration)) {
                    throw new ScannedInvoiceReadException('Од скенот не можеше да се прочита ништо.');
                }

                return $declaration;
            }
        }

        throw new ScannedInvoiceReadException('Одговорот не содржи текст.');
    }

    public static function isBlank(ScannedCustomsDeclaration $declaration): bool
    {
        if ($declaration->items !== [] || $declaration->referencedInvoiceNumbers !== []) {
            return false;
        }

        foreach (get_object_vars($declaration) as $property => $value) {
            if (! in_array($property, ['items', 'referencedInvoiceNumbers'], true) && $value !== null) {
                return false;
            }
        }

        return true;
    }

    /** Јавна и статична за да може да се тестира без мрежа. */
    public static function toDeclaration(array $payload): ScannedCustomsDeclaration
    {
        $text = static fn (array $from, string $key) => isset($from[$key]) && $from[$key] !== ''
            ? (string) $from[$key]
            : null;

        $amount = static fn (?string $value, bool $thousands = true) => ClaudeScannedInvoiceReader::normalizeAmount($value, $thousands);

        $items = [];

        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $charges = [];

            foreach ((array) ($item['charges'] ?? []) as $charge) {
                if (is_array($charge) && isset($charge['code']) && $charge['code'] !== '') {
                    $charges[strtoupper(trim((string) $charge['code']))] = $amount($text($charge, 'amount')) ?? '';
                }
            }

            $tariff = $text($item, 'tariff_code');

            $items[] = new ScannedCustomsItem(
                tariffCode: $tariff === null ? null : (preg_replace('/\D+/', '', $tariff) ?: $tariff),
                description: $text($item, 'description'),
                invoiceValueForeign: $amount($text($item, 'invoice_value_foreign')),
                statisticalValue: $amount($text($item, 'statistical_value')),
                charges: $charges,
            );
        }

        $referenced = array_values(array_filter(
            array_map(fn ($n) => ClaudeScannedInvoiceReader::normalizeInvoiceNumber(is_string($n) && $n !== '' ? $n : null), (array) ($payload['referenced_invoice_numbers'] ?? [])),
            fn ($n) => $n !== null,
        ));

        return new ScannedCustomsDeclaration(
            declarationNumber: $text($payload, 'ecd_number'),
            date: $text($payload, 'date'),
            importerName: $text($payload, 'importer_name'),
            importerTaxId: $text($payload, 'importer_tax_id'),
            declarantName: $text($payload, 'declarant_name'),
            currency: ClaudeScannedInvoiceReader::normalizeCurrency($text($payload, 'currency')),
            invoiceTotalForeign: $amount($text($payload, 'invoice_total_foreign')),
            exchangeRate: $amount($text($payload, 'exchange_rate'), false),
            totalDuty: $amount($text($payload, 'total_duty')),
            totalVat: $amount($text($payload, 'total_vat')),
            referencedInvoiceNumbers: $referenced,
            items: $items,
        );
    }

    public static function prompt(): string
    {
        return <<<'TEXT'
        Ова е скенирана царинска декларација (ЕЦД) од Северна Македонија. Првата
        страна е заглавието со првата ставка, следните страни се продолжение со
        по до три ставки. Може да е фотографија — чекај го секој број внимателно.

        Врати ги податоците од документот:
        - "ecd_number": бројот од полето „А. РДБ" на врвот (на пример 26MKIM00000001C000) — препиши го знак по знак;
        - "date": датумот на декларацијата во формат ГГГГ-ММ-ДД;
        - "importer_name" и "importer_tax_id": примачот (поле 8), со ДАНОЧНИОТ број (ЕДБ);
        - "declarant_name": подносителот/застапникот (поле 14);
        - "currency": валутата од поле 22 (три латински букви, на пример EUR);
        - "invoice_total_foreign": вкупниот износ на фактурата од поле 22;
        - "exchange_rate": курсот од поле 23, со сите децимали;
        - "referenced_invoice_numbers": бројот(евите) на фактурите наведени во поле 44 (Прилож. док.), на пример T-1/26 — само бројот на фактурата;
        - "total_duty" и "total_vat": збировите од ВКУПНО на последната страна: збирот на сите A00 (царина) и збирот на сите B00 (ДДВ).

        За СЕКОЈА ставка (поле 32, Р.бр.) врати ред во "items":
        - "tariff_code": тарифната ознака од поле 33 (8 цифри);
        - "description": описот на стоката (поле 31);
        - "invoice_value_foreign": фактурната вредност од поле 42 (во странска валута);
        - "statistical_value": статистичката вредност од поле 46 (во денари);
        - "charges": секој ред од поле 47 за таа ставка: "code" (Вид, на пример A00 или B00) и "amount" (Износ — последната колона, не Основица и не Процент).

        Не ги меша ставките меѓу себе и не ги прескокнувај: ставките се нумерирани
        по ред (1, 2, 3 ...) низ сите страни. Ако нешто не можеш да го прочиташ со
        сигурност, врати празен стринг за тоа поле — не погодувај.

        СИТЕ износи врати ги како чисти броеви: точка за децимала, БЕЗ разделник
        за илјади и без ознака за валута.
        TEXT;
    }

    private static function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => [
                'ecd_number' => $string,
                'date' => $string,
                'importer_name' => $string,
                'importer_tax_id' => $string,
                'declarant_name' => $string,
                'currency' => $string,
                'invoice_total_foreign' => $string,
                'exchange_rate' => $string,
                'referenced_invoice_numbers' => ['type' => 'array', 'items' => $string],
                'total_duty' => $string,
                'total_vat' => $string,
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'tariff_code' => $string,
                            'description' => $string,
                            'invoice_value_foreign' => $string,
                            'statistical_value' => $string,
                            'charges' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => ['code' => $string, 'amount' => $string],
                                    'required' => ['code', 'amount'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['tariff_code', 'description', 'invoice_value_foreign', 'statistical_value', 'charges'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => [
                'ecd_number', 'date', 'importer_name', 'importer_tax_id', 'declarant_name', 'currency',
                'invoice_total_foreign', 'exchange_rate', 'referenced_invoice_numbers', 'total_duty', 'total_vat', 'items',
            ],
            'additionalProperties' => false,
        ];
    }
}
```

- [ ] **Step 6: Врзување**

Во `app/Providers/AppServiceProvider.php`, додај ги `use` линиите (по азбучен ред меѓу постојните `use App\Services\Invoicing\...`):

```php
use App\Services\Invoicing\ClaudeCustomsDeclarationReader;
use App\Services\Invoicing\CustomsDeclarationReader;
use App\Services\Invoicing\NullCustomsDeclarationReader;
```

и веднаш по постојниот `$this->app->bind(ScannedInvoiceReader::class, ...)` блок додај:

```php
        $this->app->bind(
            CustomsDeclarationReader::class,
            fn () => filled(config('services.anthropic.key'))
                ? new ClaudeCustomsDeclarationReader
                : new NullCustomsDeclarationReader,
        );
```

- [ ] **Step 7: Пушти го тестот**

Run: `php artisan test tests/Feature/Invoicing/ClaudeCustomsDeclarationReaderTest.php`
Expected: PASS (5 теста). Потоа `php artisan test tests/Feature/Invoicing` — без регресија.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Invoicing/ScannedCustomsItem.php app/Services/Invoicing/ScannedCustomsDeclaration.php app/Services/Invoicing/CustomsDeclarationReader.php app/Services/Invoicing/NullCustomsDeclarationReader.php app/Services/Invoicing/ClaudeCustomsDeclarationReader.php tests/Support/FakeCustomsDeclarationReader.php app/Providers/AppServiceProvider.php tests/Feature/Invoicing/ClaudeCustomsDeclarationReaderTest.php
git commit -m "Add customs declaration reader (Sonnet) with fake and binding"
```

---

### Task 2: `CustomsTariffAggregator`

**Files:**
- Create: `app/Services/Inventory/CustomsTariffAggregator.php`
- Test: `tests/Unit/Services/Inventory/CustomsTariffAggregatorTest.php`

**Interfaces:**
- Consumes: `ScannedCustomsItem` (Task 1).
- Produces: `CustomsTariffAggregator::aggregate(array $items): array` со облик `['rows' => array<int, array{tariff_code: string, foreign_amount: string, customs_duty: string, vat_amount: string}>, 'other_codes' => string[], 'duty_total' => string, 'vat_total' => string, 'foreign_total' => string]`. Редовите се по прво појавување на тарифниот број; износите се bcmath стрингови со 2 децимали; нечитлив (нечист број) износ се третира како `0`.

- [ ] **Step 1: Тест што паѓа**

```php
<?php

namespace Tests\Unit\Services\Inventory;

use App\Services\Inventory\CustomsTariffAggregator;
use App\Services\Invoicing\ScannedCustomsItem;
use PHPUnit\Framework\TestCase;

class CustomsTariffAggregatorTest extends TestCase
{
    public function test_it_sums_items_by_tariff_code_in_first_seen_order(): void
    {
        $result = (new CustomsTariffAggregator)->aggregate([
            new ScannedCustomsItem('61091000', 'a', '100.50', '6200', ['A00' => '1250', 'B00' => '800']),
            new ScannedCustomsItem('58063210', 'b', '20.00', '1230', ['A00' => '200', 'B00' => '400']),
            new ScannedCustomsItem('61091000', 'c', '80.25', '4900', ['A00' => '1000', 'B00' => '650']),
        ]);

        $this->assertSame([
            ['tariff_code' => '61091000', 'foreign_amount' => '180.75', 'customs_duty' => '2250.00', 'vat_amount' => '1450.00'],
            ['tariff_code' => '58063210', 'foreign_amount' => '20.00', 'customs_duty' => '200.00', 'vat_amount' => '400.00'],
        ], $result['rows']);
        $this->assertSame('2450.00', $result['duty_total']);
        $this->assertSame('1850.00', $result['vat_total']);
        $this->assertSame('200.75', $result['foreign_total']);
        $this->assertSame([], $result['other_codes']);
    }

    public function test_other_charge_codes_are_reported_and_not_summed(): void
    {
        $result = (new CustomsTariffAggregator)->aggregate([
            new ScannedCustomsItem('61091000', 'a', '10.00', '600', ['A00' => '50', 'B00' => '100', 'A10' => '30', 'C99' => '5']),
            new ScannedCustomsItem('61091000', 'b', '10.00', '600', ['A10' => '30']),
        ]);

        $this->assertSame(['A10', 'C99'], $result['other_codes']);
        $this->assertSame('50.00', $result['rows'][0]['customs_duty']);
    }

    public function test_unreadable_amounts_count_as_zero_and_a_missing_tariff_code_gets_a_dash(): void
    {
        $result = (new CustomsTariffAggregator)->aggregate([
            new ScannedCustomsItem(null, 'a', '1?.00', '', ['A00' => 'x', 'B00' => '']),
        ]);

        $this->assertSame('—', $result['rows'][0]['tariff_code']);
        $this->assertSame('0.00', $result['rows'][0]['foreign_amount']);
        $this->assertSame('0.00', $result['rows'][0]['customs_duty']);
    }

    public function test_no_items_gives_empty_result(): void
    {
        $result = (new CustomsTariffAggregator)->aggregate([]);

        $this->assertSame([], $result['rows']);
        $this->assertSame('0.00', $result['duty_total']);
    }
}
```

- [ ] **Step 2:** Run: `php artisan test tests/Unit/Services/Inventory/CustomsTariffAggregatorTest.php` — Expected: FAIL (класата не постои).

- [ ] **Step 3: Имплементација**

```php
<?php

namespace App\Services\Inventory;

use App\Services\Invoicing\ScannedCustomsItem;

/**
 * Ги собира ставките од ЕЦД по тарифен број — истиот приказ што го дава
 * постојниот царински софтвер: еден ред по тарифа, со збир на фактурната
 * вредност, царината (A00) и ДДВ (B00). Чиста класа, без база и мрежа.
 */
class CustomsTariffAggregator
{
    public const DUTY_CODE = 'A00';

    public const VAT_CODE = 'B00';

    /**
     * @param  ScannedCustomsItem[]  $items
     * @return array{rows: array<int, array{tariff_code: string, foreign_amount: string, customs_duty: string, vat_amount: string}>, other_codes: string[], duty_total: string, vat_total: string, foreign_total: string}
     */
    public function aggregate(array $items): array
    {
        $rows = [];
        $otherCodes = [];
        $dutyTotal = '0.00';
        $vatTotal = '0.00';
        $foreignTotal = '0.00';

        foreach ($items as $item) {
            $code = $item->tariffCode !== null && $item->tariffCode !== '' ? $item->tariffCode : '—';

            $rows[$code] ??= ['tariff_code' => $code, 'foreign_amount' => '0.00', 'customs_duty' => '0.00', 'vat_amount' => '0.00'];

            $foreign = $this->amount($item->invoiceValueForeign);
            $duty = $this->amount($item->charges[self::DUTY_CODE] ?? null);
            $vat = $this->amount($item->charges[self::VAT_CODE] ?? null);

            $rows[$code]['foreign_amount'] = bcadd($rows[$code]['foreign_amount'], $foreign, 2);
            $rows[$code]['customs_duty'] = bcadd($rows[$code]['customs_duty'], $duty, 2);
            $rows[$code]['vat_amount'] = bcadd($rows[$code]['vat_amount'], $vat, 2);

            $foreignTotal = bcadd($foreignTotal, $foreign, 2);
            $dutyTotal = bcadd($dutyTotal, $duty, 2);
            $vatTotal = bcadd($vatTotal, $vat, 2);

            foreach (array_keys($item->charges) as $chargeCode) {
                if (! in_array($chargeCode, [self::DUTY_CODE, self::VAT_CODE], true)) {
                    $otherCodes[$chargeCode] = $chargeCode;
                }
            }
        }

        $otherCodes = array_values($otherCodes);
        sort($otherCodes);

        return [
            'rows' => array_values($rows),
            'other_codes' => $otherCodes,
            'duty_total' => $dutyTotal,
            'vat_total' => $vatTotal,
            'foreign_total' => $foreignTotal,
        ];
    }

    private function amount(?string $value): string
    {
        return $value !== null && is_numeric($value) ? $value : '0';
    }
}
```

- [ ] **Step 4:** Run the test — Expected: PASS (4 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Inventory/CustomsTariffAggregator.php tests/Unit/Services/Inventory/CustomsTariffAggregatorTest.php
git commit -m "Add CustomsTariffAggregator summing ECD items by tariff code"
```

---

### Task 3: Читач на фактури — ставка „charge" и поголем лимит

**Files:**
- Modify: `app/Services/Invoicing/ScannedInvoiceLine.php`
- Modify: `app/Services/Invoicing/ClaudeScannedInvoiceReader.php`
- Test: `tests/Feature/Invoicing/ClaudeScannedInvoiceReaderTest.php` (додај тестови)

**Interfaces:**
- Produces: `ScannedInvoiceLine` добива последен незадолжителен параметар `public ?string $kind = null` (`'goods'` | `'charge'` | `null`). Постојните повици (4 позиционални аргументи) остануваат валидни.
- Produces: `ClaudeScannedInvoiceReader::toScannedInvoice()` го мапира `lines[].kind` (само `goods`/`charge`, друго → `null`).

- [ ] **Step 1: Тестови што паѓаат** — додај на крајот на класата `ClaudeScannedInvoiceReaderTest` (користи го постојниот стил; `toScannedInvoice` е јавна статична):

```php
    public function test_a_line_kind_is_mapped_and_unknown_values_become_null(): void
    {
        $invoice = ClaudeScannedInvoiceReader::toScannedInvoice([
            'lines' => [
                ['description' => 'Рукавици', 'quantity' => '2', 'unit_price' => '12.50', 'vat_rate' => '0', 'kind' => 'goods'],
                ['description' => 'ТРОШКОВИ НА ТРАНСПОРТА', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0', 'kind' => 'charge'],
                ['description' => 'Нешто', 'quantity' => '1', 'unit_price' => '1', 'vat_rate' => '0', 'kind' => 'bogus'],
                ['description' => 'Без вид', 'quantity' => '1', 'unit_price' => '1', 'vat_rate' => '0'],
            ],
        ]);

        $this->assertSame('goods', $invoice->lines[0]->kind);
        $this->assertSame('charge', $invoice->lines[1]->kind);
        $this->assertNull($invoice->lines[2]->kind);
        $this->assertNull($invoice->lines[3]->kind);
    }

    public function test_the_prompt_asks_for_the_line_kind(): void
    {
        $this->assertStringContainsString('"kind"', ClaudeScannedInvoiceReader::prompt('Фирма'));
    }
```

(Ако класата на тестот не е веќе `use`-нала `ClaudeScannedInvoiceReader`, постои — провери го почетокот на фајлот.)

- [ ] **Step 2:** Run: `php artisan test tests/Feature/Invoicing/ClaudeScannedInvoiceReaderTest.php --filter="line_kind|asks_for_the_line_kind"` — Expected: FAIL.

- [ ] **Step 3: DTO** — во `ScannedInvoiceLine.php` додај параметар по `$vatRate`:

```php
        public ?string $vatRate = null,
        // 'goods' или 'charge' (транспорт, осигурување, пакување). Само при
        // увоз се користи: 'charge' оди во увозни трошоци, не во стока.
        public ?string $kind = null,
```

- [ ] **Step 4: Читач** — во `ClaudeScannedInvoiceReader.php`:

(а) Во `toScannedInvoice()`, во `new ScannedInvoiceLine(...)` додај по `vatRate:`:

```php
                    kind: in_array($line['kind'] ?? null, ['goods', 'charge'], true) ? $line['kind'] : null,
```

(б) Во `prompt()` додај пред реченицата „Датумите врати ги во формат ГГГГ-ММ-ДД.":

```
        За секоја ставка во "lines" врати "kind": "charge" ако ставката НЕ е
        стока туку надоместок — транспорт, превоз, шпедиција, осигурување,
        пакување, манипулативни трошоци (на пример „ТРОШКОВИ НА ТРАНСПОРТА",
        „freight", „shipping"); инаку "goods".

```

(в) Во `schema()` во `lines.items.properties` додај `'kind' => ['type' => 'string', 'enum' => ['goods', 'charge']],` и додај `'kind'` во `'required'` на ставката.

(г) Зголеми ги лимитите: `maxTokens: 4096,` → `maxTokens: 16000,` и `private const TIMEOUT_SECONDS = 30.0;` → `private const TIMEOUT_SECONDS = 90.0;` (коментарот над константата ажурирај го: фактура од ~110 ставки дава над 4096 излезни токени, затоа 16000 и 90 сек; најлош случај 2×90с).

- [ ] **Step 5:** Run: `php artisan test tests/Feature/Invoicing` — Expected: PASS (нови + сите постојни; постојниот тест што ја проверува транспортната опција/лимит, ако проверува конкретна вредност `30.0`, ажурирај го на `90.0`).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Invoicing/ScannedInvoiceLine.php app/Services/Invoicing/ClaudeScannedInvoiceReader.php tests/Feature/Invoicing/ClaudeScannedInvoiceReaderTest.php
git commit -m "Scan reader: classify charge lines, raise token and time limits for long invoices"
```

---

### Task 4: `ImportScanMapper`

**Files:**
- Create: `app/Services/Invoicing/ImportScanMapper.php`
- Test: `tests/Unit/Services/Invoicing/ImportScanMapperTest.php`

**Interfaces:**
- Consumes: `ScannedInvoice`, `ScannedInvoiceLine` (со `kind`, Task 3), `ScannedCustomsDeclaration` (Task 1), `App\Support\Bcmath::roundHalfUp`.
- Produces:
  - `ImportScanMapper::convertInvoice(ScannedInvoice $invoice, string $rate): array{invoice: ScannedInvoice, costs: array<int, array<string,string>>, warnings: string[]}` — `invoice` е истата фактура со СИТЕ ставки (и `charge`, со зачуван `kind`), `unitPrice = roundHalfUp(цена × курс, 2)`, `vatRate = '0'`, `currency = 'MKD'`; ставките `charge` ДОПОЛНИТЕЛНО даваат редови во `costs` со облик `['payee_name', 'reference_number', 'foreign_amount', 'base_amount', 'vat_amount', 'source' => 'invoice']` (сите стрингови).
  - `ImportScanMapper::forwarderCost(ScannedInvoice $forwarder): array{row: array<string,string>, warnings: string[]}` — `row` има `source => 'forwarder'`, `foreign_amount => ''`.
  - `ImportScanMapper::declarationFields(ScannedCustomsDeclaration $d): array{customsDeclarationNumber: string, importDate: string, importCurrencyCode: ?string, importExchangeRate: string}`.

- [ ] **Step 1: Тест што паѓа**

```php
<?php

namespace Tests\Unit\Services\Invoicing;

use App\Services\Invoicing\ImportScanMapper;
use App\Services\Invoicing\ScannedCustomsDeclaration;
use App\Services\Invoicing\ScannedInvoice;
use App\Services\Invoicing\ScannedInvoiceLine;
use PHPUnit\Framework\TestCase;

class ImportScanMapperTest extends TestCase
{
    private function foreignInvoice(): ScannedInvoice
    {
        return new ScannedInvoice(
            sellerName: 'FOREIGN DOO',
            invoiceNumber: 'T-1/26',
            invoiceDate: '2026-02-24',
            currency: 'EUR',
            printedTotal: '1125.00',
            lines: [
                new ScannedInvoiceLine('Рукавици', '2', '12.50', '0', 'goods'),
                new ScannedInvoiceLine('Капа', '3', '33.33', '0', null),
                new ScannedInvoiceLine('ТРОШКОВИ НА ТРАНСПОРТА', '1', '100.00', '0', 'charge'),
            ],
        );
    }

    public function test_it_converts_goods_to_denars_with_half_up_rounding_and_zero_vat(): void
    {
        $result = (new ImportScanMapper)->convertInvoice($this->foreignInvoice(), '61.5000');

        $this->assertSame('MKD', $result['invoice']->currency);
        $this->assertCount(2, $result['invoice']->lines);
        // 12.50 * 61.5000 = 768.75 ; 33.33 * 61.5000 = 2049.795 -> 2049.80
        $this->assertSame('768.75', $result['invoice']->lines[0]->unitPrice);
        $this->assertSame('2049.80', $result['invoice']->lines[1]->unitPrice);
        $this->assertSame('0', $result['invoice']->lines[0]->vatRate);
        $this->assertSame('2', $result['invoice']->lines[0]->quantity);
        $this->assertSame('T-1/26', $result['invoice']->invoiceNumber);
    }

    public function test_a_charge_line_becomes_an_import_cost_row(): void
    {
        $result = (new ImportScanMapper)->convertInvoice($this->foreignInvoice(), '61.5000');

        $this->assertSame([[
            'payee_name' => 'FOREIGN DOO',
            'reference_number' => 'T-1/26',
            'foreign_amount' => '100.00',
            'base_amount' => '6150.00',
            'vat_amount' => '0.00',
            'source' => 'invoice',
        ]], $result['costs']);
    }

    public function test_an_unreadable_price_is_kept_and_warned_about(): void
    {
        $invoice = new ScannedInvoice(currency: 'EUR', lines: [new ScannedInvoiceLine('Нешто', '1', '1?5', '0', 'goods')]);

        $result = (new ImportScanMapper)->convertInvoice($invoice, '61.5000');

        $this->assertSame('1?5', $result['invoice']->lines[0]->unitPrice);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('1', $result['warnings'][0]);
    }

    public function test_forwarder_cost_sums_net_and_vat(): void
    {
        $forwarder = new ScannedInvoice(
            sellerName: 'ТЕСТ ШПЕДИТЕР ДООЕЛ',
            invoiceNumber: 'F-77/26',
            currency: 'MKD',
            lines: [
                new ScannedInvoiceLine('Царинско посредување', '1', '1950.00', '18'),
                new ScannedInvoiceLine('Манипулација', '2', '25.00', '18'),
            ],
        );

        $result = (new ImportScanMapper)->forwarderCost($forwarder);

        $this->assertSame('ТЕСТ ШПЕДИТЕР ДООЕЛ', $result['row']['payee_name']);
        $this->assertSame('F-77/26', $result['row']['reference_number']);
        $this->assertSame('2000.00', $result['row']['base_amount']);
        $this->assertSame('360.00', $result['row']['vat_amount']);
        $this->assertSame('', $result['row']['foreign_amount']);
        $this->assertSame('forwarder', $result['row']['source']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_a_foreign_currency_forwarder_invoice_warns(): void
    {
        $forwarder = new ScannedInvoice(sellerName: 'X', currency: 'EUR', lines: [new ScannedInvoiceLine('a', '1', '10', '0')]);

        $result = (new ImportScanMapper)->forwarderCost($forwarder);

        $this->assertCount(1, $result['warnings']);
    }

    public function test_declaration_fields(): void
    {
        $fields = (new ImportScanMapper)->declarationFields(new ScannedCustomsDeclaration(
            declarationNumber: '26MKIM00000001C000',
            date: '2026-03-04',
            currency: 'EUR',
            exchangeRate: '61.5000',
        ));

        $this->assertSame([
            'customsDeclarationNumber' => '26MKIM00000001C000',
            'importDate' => '2026-03-04',
            'importCurrencyCode' => 'EUR',
            'importExchangeRate' => '61.5000',
        ], $fields);
    }

    public function test_an_unsupported_currency_gives_null_code(): void
    {
        $fields = (new ImportScanMapper)->declarationFields(new ScannedCustomsDeclaration(currency: 'JPY'));

        $this->assertNull($fields['importCurrencyCode']);
    }
}
```

- [ ] **Step 2:** Run: `php artisan test tests/Unit/Services/Invoicing/ImportScanMapperTest.php` — Expected: FAIL (класата не постои).

- [ ] **Step 3: Имплементација**

```php
<?php

namespace App\Services\Invoicing;

use App\Support\Bcmath;

/**
 * Преводот на прочитаните увозни документи во облик што формата го разбира.
 * Чиста класа: без Livewire, без база, без мрежа — сè што е нејасно се
 * враќа како предупредување, никогаш не се измислува.
 */
class ImportScanMapper
{
    /** Валутите што формата за увоз ги нуди (иста листа како во Blade). */
    public const CURRENCIES = ['EUR', 'USD', 'GBP', 'CHF'];

    /**
     * Фактурата од странски добавувач во денари по зададениот курс.
     *
     * @return array{invoice: ScannedInvoice, costs: array<int, array<string, string>>, warnings: string[]}
     */
    public function convertInvoice(ScannedInvoice $invoice, string $rate): array
    {
        $lines = [];
        $costs = [];
        $warnings = [];

        foreach ($invoice->lines as $index => $line) {
            $position = $index + 1;

            if ($line->kind === 'charge') {
                $foreign = $this->lineTotal($line);

                if ($foreign === null) {
                    $warnings[] = "Ставка {$position} („{$line->description}“): износот не е читлив — внеси го трошокот рачно.";

                    continue;
                }

                $costs[] = [
                    'payee_name' => (string) ($invoice->sellerName ?? ''),
                    'reference_number' => (string) ($invoice->invoiceNumber ?? ''),
                    'foreign_amount' => $foreign,
                    'base_amount' => Bcmath::roundHalfUp(bcmul($foreign, $rate, 12), 2),
                    'vat_amount' => '0.00',
                    'source' => 'invoice',
                ];

                continue;
            }

            if (! Bcmath::isPlainNumber($line->unitPrice)) {
                $warnings[] = "Ставка {$position} („{$line->description}“): цената не е читлива и не е претворена во денари.";
                $lines[] = $line;

                continue;
            }

            $lines[] = new ScannedInvoiceLine(
                description: $line->description,
                quantity: $line->quantity,
                unitPrice: Bcmath::roundHalfUp(bcmul($line->unitPrice, $rate, 12), 2),
                vatRate: '0',
                kind: $line->kind,
            );
        }

        return [
            'invoice' => $this->copyWith($invoice, $lines),
            'costs' => $costs,
            'warnings' => $warnings,
        ];
    }

    /**
     * Шпедитерската фактура како еден ред „Увозни трошоци" (денарска).
     *
     * @return array{row: array<string, string>, warnings: string[]}
     */
    public function forwarderCost(ScannedInvoice $forwarder): array
    {
        $warnings = [];
        $net = '0.00';
        $vat = '0.00';

        foreach ($forwarder->lines as $index => $line) {
            $total = $this->lineTotal($line);

            if ($total === null) {
                $warnings[] = 'Шпедитерска фактура, ставка '.($index + 1).': износот не е читлив — провери го редот.';

                continue;
            }

            $rate = Bcmath::isPlainNumber($line->vatRate) ? $line->vatRate : '0';

            $net = bcadd($net, $total, 2);
            $vat = bcadd($vat, Bcmath::roundHalfUp(bcdiv(bcmul($total, $rate, 12), '100', 12), 2), 2);
        }

        if ($forwarder->currency !== null && $forwarder->currency !== 'MKD') {
            $warnings[] = "Шпедитерската фактура е во {$forwarder->currency}, не во денари — износите не се претворени, провери го редот.";
        }

        return [
            'row' => [
                'payee_name' => (string) ($forwarder->sellerName ?? ''),
                'reference_number' => (string) ($forwarder->invoiceNumber ?? ''),
                'foreign_amount' => '',
                'base_amount' => $net,
                'vat_amount' => $vat,
                'source' => 'forwarder',
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{customsDeclarationNumber: string, importDate: string, importCurrencyCode: ?string, importExchangeRate: string}
     */
    public function declarationFields(ScannedCustomsDeclaration $declaration): array
    {
        return [
            'customsDeclarationNumber' => (string) ($declaration->declarationNumber ?? ''),
            'importDate' => (string) ($declaration->date ?? ''),
            'importCurrencyCode' => in_array($declaration->currency, self::CURRENCIES, true) ? $declaration->currency : null,
            'importExchangeRate' => (string) ($declaration->exchangeRate ?? ''),
        ];
    }

    private function lineTotal(ScannedInvoiceLine $line): ?string
    {
        if (! Bcmath::isPlainNumber($line->quantity) || ! Bcmath::isPlainNumber($line->unitPrice)) {
            return null;
        }

        return Bcmath::roundHalfUp(bcmul($line->quantity, $line->unitPrice, 12), 2);
    }

    /** @param ScannedInvoiceLine[] $lines */
    private function copyWith(ScannedInvoice $i, array $lines): ScannedInvoice
    {
        return new ScannedInvoice(
            sellerTaxId: $i->sellerTaxId,
            buyerName: $i->buyerName,
            buyerTaxId: $i->buyerTaxId,
            buyerStreetAddress: $i->buyerStreetAddress,
            buyerStreetNumber: $i->buyerStreetNumber,
            buyerPostalCode: $i->buyerPostalCode,
            buyerCity: $i->buyerCity,
            invoiceNumber: $i->invoiceNumber,
            invoiceDate: $i->invoiceDate,
            dueDate: $i->dueDate,
            currency: 'MKD',
            printedTotal: $i->printedTotal,
            lines: $lines,
            sellerName: $i->sellerName,
            invoiceCount: $i->invoiceCount,
            ourCompanyRole: $i->ourCompanyRole,
        );
    }
}
```

- [ ] **Step 4:** Run the test — Expected: PASS (7 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Invoicing/ImportScanMapper.php tests/Unit/Services/Invoicing/ImportScanMapperTest.php
git commit -m "Add ImportScanMapper: convert foreign invoice, forwarder cost, ECD fields"
```

---

### Task 5: `ImportScanChecks`

**Files:**
- Create: `app/Services/Invoicing/ImportScanChecks.php`
- Test: `tests/Unit/Services/Invoicing/ImportScanChecksTest.php`

**Interfaces:**
- Consumes: `ScannedCustomsDeclaration`, `ScannedInvoice`, `CustomsTariffAggregator::aggregate()` (Task 2).
- Produces: `ImportScanChecks::run(?ScannedCustomsDeclaration $ecd, ?ScannedInvoice $invoice, ?ScannedInvoice $forwarder, string $companyTaxId, bool $rateFromNbrm): array` → `string[]` предупредувања на македонски, по редослед; празно = сè се совпаѓа. Секоја проверка се прескока кога некој од потребните податоци недостасува.

- [ ] **Step 1: Тест што паѓа**

```php
<?php

namespace Tests\Unit\Services\Invoicing;

use App\Services\Invoicing\ImportScanChecks;
use App\Services\Invoicing\ScannedCustomsDeclaration;
use App\Services\Invoicing\ScannedCustomsItem;
use App\Services\Invoicing\ScannedInvoice;
use PHPUnit\Framework\TestCase;

class ImportScanChecksTest extends TestCase
{
    private function ecd(array $over = []): ScannedCustomsDeclaration
    {
        $args = array_merge([
            'declarationNumber' => '26MKIM00000001C000',
            'importerTaxId' => 'MK4000000000001',
            'declarantName' => 'ТЕСТ ШПЕДИТЕР',
            'currency' => 'EUR',
            'invoiceTotalForeign' => '100.00',
            'exchangeRate' => '61.5000',
            'totalDuty' => '50',
            'totalVat' => '100',
            'referencedInvoiceNumbers' => ['T-1/26'],
            'items' => [
                new ScannedCustomsItem('61091000', 'a', '60.00', '3700', ['A00' => '30', 'B00' => '60']),
                new ScannedCustomsItem('61091000', 'b', '40.00', '2470', ['A00' => '20', 'B00' => '40']),
            ],
        ], $over);

        return new ScannedCustomsDeclaration(...$args);
    }

    private function run(?ScannedCustomsDeclaration $ecd, ?ScannedInvoice $invoice = null, ?ScannedInvoice $fwd = null, string $tax = '4000000000001', bool $nbrm = false): array
    {
        return (new ImportScanChecks)->run($ecd, $invoice, $fwd, $tax, $nbrm);
    }

    public function test_everything_matching_gives_no_warnings(): void
    {
        $invoice = new ScannedInvoice(invoiceNumber: 'T-1/26', currency: 'EUR', printedTotal: '100.00');
        $fwd = new ScannedInvoice(sellerName: 'ТЕСТ ШПЕДИТЕР ДООЕЛ');

        $this->assertSame([], $this->run($this->ecd(), $invoice, $fwd));
    }

    public function test_duty_and_vat_totals_must_match_the_declaration_totals(): void
    {
        $warnings = $this->run($this->ecd(['totalDuty' => '51', 'totalVat' => '99']));

        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('царина', $warnings[0]);
        $this->assertStringContainsString('ДДВ', $warnings[1]);
    }

    public function test_item_value_sum_must_match_the_declaration_total(): void
    {
        $warnings = $this->run($this->ecd(['invoiceTotalForeign' => '110.00']));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('110', $warnings[0]);
    }

    public function test_invoice_total_differing_from_the_declaration_warns_with_the_difference(): void
    {
        $warnings = $this->run($this->ecd(), new ScannedInvoice(invoiceNumber: 'T-1/26', currency: 'EUR', printedTotal: '122.50'));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('22.50', $warnings[0]);
    }

    public function test_invoice_number_not_referenced_in_the_declaration_warns(): void
    {
        $warnings = $this->run($this->ecd(), new ScannedInvoice(invoiceNumber: 'OTHER-9', currency: 'EUR', printedTotal: '100.00'));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('OTHER-9', $warnings[0]);
    }

    public function test_importer_must_be_the_current_company(): void
    {
        $warnings = $this->run($this->ecd(), null, null, '4999999999999');

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('фирма', $warnings[0]);
    }

    public function test_currency_mismatch_between_declaration_and_invoice_warns(): void
    {
        $warnings = $this->run($this->ecd(), new ScannedInvoice(invoiceNumber: 'T-1/26', currency: 'USD', printedTotal: '100.00'));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('USD', $warnings[0]);
    }

    public function test_forwarder_name_not_matching_the_declarant_is_informational(): void
    {
        $warnings = $this->run($this->ecd(), null, new ScannedInvoice(sellerName: 'СОВСЕМ ДРУГА ФИРМА'));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('шпедитер', mb_strtolower($warnings[0]));
    }

    public function test_nbrm_rate_is_always_flagged(): void
    {
        $warnings = $this->run(null, new ScannedInvoice(currency: 'EUR'), null, '4000000000001', true);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('НБРМ', $warnings[0]);
    }

    public function test_other_charge_codes_are_flagged(): void
    {
        $ecd = $this->ecd(['items' => [new ScannedCustomsItem('61091000', 'a', '100.00', '6000', ['A00' => '50', 'B00' => '100', 'A10' => '7'])]]);

        $warnings = $this->run($ecd);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('A10', $warnings[0]);
    }
}
```

- [ ] **Step 2:** Run the test — Expected: FAIL (класата не постои).

- [ ] **Step 3: Имплементација**

```php
<?php

namespace App\Services\Invoicing;

use App\Services\Inventory\CustomsTariffAggregator;
use App\Support\Bcmath;

/**
 * Проверки врз прочитаните увозни документи. Враќа листа предупредувања —
 * ништо не блокира: човекот е тој што одлучува, а скенот е фотографија.
 * Чиста класа без база и мрежа.
 */
class ImportScanChecks
{
    /**
     * @return string[]
     */
    public function run(
        ?ScannedCustomsDeclaration $ecd,
        ?ScannedInvoice $invoice,
        ?ScannedInvoice $forwarder,
        string $companyTaxId,
        bool $rateFromNbrm,
    ): array {
        $warnings = [];

        if ($ecd !== null) {
            $summary = (new CustomsTariffAggregator)->aggregate($ecd->items);

            if ($this->differs($summary['duty_total'], $ecd->totalDuty)) {
                $warnings[] = "Збирот на царината по ставка ({$summary['duty_total']}) не е ист со ВКУПНО од ЕЦД ({$ecd->totalDuty}) — провери ги ставките.";
            }

            if ($this->differs($summary['vat_total'], $ecd->totalVat)) {
                $warnings[] = "Збирот на ДДВ по ставка ({$summary['vat_total']}) не е ист со ВКУПНО од ЕЦД ({$ecd->totalVat}) — провери ги ставките.";
            }

            if ($this->differs($summary['foreign_total'], $ecd->invoiceTotalForeign)) {
                $warnings[] = "Збирот на фактурните вредности по ставка ({$summary['foreign_total']}) не е ист со вкупната вредност од ЕЦД ({$ecd->invoiceTotalForeign}) — провери ги ставките.";
            }

            if ($summary['other_codes'] !== []) {
                $codes = implode(', ', $summary['other_codes']);
                $warnings[] = "ЕЦД содржи и други давачки ({$codes}) освен царина (A00) и ДДВ (B00) — тие НЕ се пренесени, додади ги рачно ако треба.";
            }

            $ours = preg_replace('/\D+/', '', $companyTaxId) ?? '';
            $importer = preg_replace('/\D+/', '', (string) $ecd->importerTaxId) ?? '';

            if ($importer !== '' && $ours !== '' && $importer !== $ours) {
                $warnings[] = 'Увозник на ЕЦД не е тековната фирма (ЕДБ '.$importer.') — провери дали е прикачена вистинската декларација.';
            }

            if ($invoice !== null) {
                if ($invoice->currency !== null && $ecd->currency !== null && $invoice->currency !== $ecd->currency) {
                    $warnings[] = "Валутата на фактурата ({$invoice->currency}) не е иста со валутата на ЕЦД ({$ecd->currency}).";
                }

                if ($invoice->printedTotal !== null && $ecd->invoiceTotalForeign !== null
                    && Bcmath::isPlainNumber($invoice->printedTotal) && Bcmath::isPlainNumber($ecd->invoiceTotalForeign)
                    && bccomp($invoice->printedTotal, $ecd->invoiceTotalForeign, 2) !== 0) {
                    $difference = ltrim(bcsub($invoice->printedTotal, $ecd->invoiceTotalForeign, 2), '-');
                    $warnings[] = "Вкупно на фактурата ({$invoice->printedTotal}) не е исто со вредноста во ЕЦД ({$ecd->invoiceTotalForeign}) — разлика {$difference}.";
                }

                if ($invoice->invoiceNumber !== null && $ecd->referencedInvoiceNumbers !== []
                    && ! in_array($this->normalizeNumber($invoice->invoiceNumber), array_map($this->normalizeNumber(...), $ecd->referencedInvoiceNumbers), true)) {
                    $warnings[] = "Бројот на фактурата ({$invoice->invoiceNumber}) не се спомнува во ЕЦД (поле 44).";
                }
            }

            if ($forwarder !== null && filled($ecd->declarantName) && filled($forwarder->sellerName)
                && ! $this->namesOverlap((string) $ecd->declarantName, (string) $forwarder->sellerName)) {
                $warnings[] = "Шпедитерот на ЕЦД ({$ecd->declarantName}) не се совпаѓа со издавачот на шпедитерската фактура ({$forwarder->sellerName}).";
            }
        }

        if ($rateFromNbrm) {
            $warnings[] = 'Нема ЕЦД — курсот е од НБРМ на датумот на фактурата. Со ЕЦД курсот би бил оној од декларацијата.';
        }

        return $warnings;
    }

    private function differs(string $computed, ?string $printed): bool
    {
        return Bcmath::isPlainNumber($printed) && bccomp($computed, $printed, 2) !== 0;
    }

    private function normalizeNumber(string $number): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $number) ?? $number);
    }

    /** Имињата се совпаѓаат ако делат барем еден збор од 4+ знаци (ДООЕЛ и слично не се броат). */
    private function namesOverlap(string $a, string $b): bool
    {
        $words = fn (string $s) => array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s)) ?: [],
            fn ($w) => mb_strlen($w) >= 4 && ! in_array($w, ['доел', 'доо', 'дооел', 'скопје', 'doel', 'dooel'], true),
        );

        return array_intersect($words($a), $words($b)) !== [];
    }
}
```

- [ ] **Step 4:** Run the test — Expected: PASS (10 теста). Ако `test_everything_matching_gives_no_warnings` не успее, провери дека `'ТЕСТ ШПЕДИТЕР'` и `'ТЕСТ ШПЕДИТЕР ДООЕЛ'` делат збор од 4+ знаци (`шпедитер`) — тоа е очекувано.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Invoicing/ImportScanChecks.php tests/Unit/Services/Invoicing/ImportScanChecksTest.php
git commit -m "Add ImportScanChecks: warnings for mismatched import documents"
```

---

### Task 6: `PurchaseInvoiceForm` — читање на документите и бројни ставки како артикли

**Files:**
- Modify: `app/Livewire/Invoicing/PurchaseInvoiceForm.php`
- Test: `tests/Feature/Invoicing/PurchaseInvoiceImportScanTest.php`

**Interfaces:**
- Consumes: `CustomsDeclarationReader`, `ScannedInvoiceReader` (врзани), `ImportScanMapper` (T4), `CustomsTariffAggregator` (T2), `ImportScanChecks` (T5), `ExchangeRateService::getRate(string, Carbon): float`, постојниот `applyScan(ScannedInvoice)` и `canReadScans()` од формата.
- Produces: јавни својства `$ecdFile`, `$importInvoiceFile`, `$forwarderFile` (Livewire upload), `array $importScanWarnings`; акции `readImportDocuments(): void`, `addAllUnknownLinesAsItems(): void`.

Редовите во `importCosts`/`tariffLines` од скен носат дополнителен клуч `'source'` (`'invoice'`|`'forwarder'`) — `persist()` го игнорира (чита само познати клучеви).

- [ ] **Step 1: Тест што паѓа** — `tests/Feature/Invoicing/PurchaseInvoiceImportScanTest.php`:

```php
<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\PurchaseInvoiceForm;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\User;
use App\Services\Invoicing\CustomsDeclarationReader;
use App\Services\Invoicing\ScannedCustomsDeclaration;
use App\Services\Invoicing\ScannedCustomsItem;
use App\Services\Invoicing\ScannedInvoice;
use App\Services\Invoicing\ScannedInvoiceLine;
use App\Services\Invoicing\ScannedInvoiceReader;
use App\Services\Invoicing\ScannedInvoiceReadException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeCustomsDeclarationReader;
use Tests\Support\FakeScannedInvoiceReader;
use Tests\TestCase;

class PurchaseInvoiceImportScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        FakeScannedInvoiceReader::reset();
        FakeCustomsDeclarationReader::reset();
        Storage::fake('local');
        config(['services.anthropic.key' => 'test-key']);
        $this->app->bind(ScannedInvoiceReader::class, FakeScannedInvoiceReader::class);
        $this->app->bind(CustomsDeclarationReader::class, FakeCustomsDeclarationReader::class);
    }

    private function company(): Company
    {
        $company = Company::factory()->create(['tax_id' => '4000000000001']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('admin');
        $this->actingAs($user);

        return $company;
    }

    private function ecd(): ScannedCustomsDeclaration
    {
        return new ScannedCustomsDeclaration(
            declarationNumber: '26MKIM00000001C000',
            date: '2026-03-04',
            importerTaxId: 'MK4000000000001',
            declarantName: 'ТЕСТ ШПЕДИТЕР',
            currency: 'EUR',
            invoiceTotalForeign: '100.00',
            exchangeRate: '61.5000',
            totalDuty: '50',
            totalVat: '100',
            referencedInvoiceNumbers: ['T-1/26'],
            items: [
                new ScannedCustomsItem('61091000', 'a', '60.00', '3700', ['A00' => '30', 'B00' => '60']),
                new ScannedCustomsItem('61091000', 'b', '40.00', '2470', ['A00' => '20', 'B00' => '40']),
            ],
        );
    }

    private function foreignInvoice(): ScannedInvoice
    {
        return new ScannedInvoice(
            sellerTaxId: '1234567890',
            sellerName: 'FOREIGN DOO',
            buyerTaxId: '4000000000001',
            invoiceNumber: 'T-1/26',
            invoiceDate: '2026-02-24',
            currency: 'EUR',
            printedTotal: '100.00',
            ourCompanyRole: 'buyer',
            lines: [
                new ScannedInvoiceLine('Рукавици зимски', '2', '12.50', '0', 'goods'),
                new ScannedInvoiceLine('ТРОШКОВИ НА ТРАНСПОРТА', '1', '100.00', '0', 'charge'),
            ],
        );
    }

    private function files()
    {
        return [
            'ecdFile' => UploadedFile::fake()->create('ecd.pdf', 200, 'application/pdf'),
            'importInvoiceFile' => UploadedFile::fake()->create('faktura.pdf', 200, 'application/pdf'),
            'forwarderFile' => UploadedFile::fake()->create('shpediter.pdf', 200, 'application/pdf'),
        ];
    }

    public function test_the_ecd_alone_fills_declaration_fields_and_aggregated_tariff_lines(): void
    {
        $company = $this->company();
        FakeCustomsDeclarationReader::$next = $this->ecd();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('ecdFile', $this->files()['ecdFile'])
            ->call('readImportDocuments')
            ->assertSet('isImport', true)
            ->assertSet('customsDeclarationNumber', '26MKIM00000001C000')
            ->assertSet('importDate', '2026-03-04')
            ->assertSet('importCurrencyCode', 'EUR')
            ->assertSet('importExchangeRate', '61.5000')
            ->assertCount('tariffLines', 1)
            ->assertSet('tariffLines.0.tariff_code', '61091000')
            ->assertSet('tariffLines.0.foreign_amount', '100.00')
            ->assertSet('tariffLines.0.customs_duty', '50.00')
            ->assertSet('tariffLines.0.vat_amount', '100.00')
            ->assertSet('importScanWarnings', []);
    }

    public function test_all_three_documents_use_the_ecd_rate_and_move_the_transport_to_costs(): void
    {
        $company = $this->company();
        FakeCustomsDeclarationReader::$next = $this->ecd();
        FakeScannedInvoiceReader::$next = $this->foreignInvoice();

        $files = $this->files();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('ecdFile', $files['ecdFile'])
            ->set('importInvoiceFile', $files['importInvoiceFile'])
            ->call('readImportDocuments')
            ->assertSet('supplierInvoiceNumber', 'T-1/26')
            // 12.50 EUR * 61.5000 = 768.75
            ->assertSet('lines.0.unit_price', '768.75')
            ->assertSet('lines.0.description', 'Рукавици зимски')
            ->assertSet('lines.0.vat_rate', '0')
            ->assertCount('lines', 1)
            ->assertCount('importCosts', 1)
            ->assertSet('importCosts.0.payee_name', 'FOREIGN DOO')
            ->assertSet('importCosts.0.foreign_amount', '100.00')
            ->assertSet('importCosts.0.base_amount', '6150.00')
            ->assertSet('importCosts.0.source', 'invoice');
    }

    public function test_the_forwarder_invoice_adds_one_cost_row_and_rereading_replaces_it(): void
    {
        $company = $this->company();
        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerName: 'ТЕСТ ШПЕДИТЕР ДООЕЛ',
            invoiceNumber: 'F-77/26',
            currency: 'MKD',
            lines: [new ScannedInvoiceLine('Посредување', '1', '2000.00', '18')],
        );

        $component = Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('forwarderFile', $this->files()['forwarderFile'])
            ->call('readImportDocuments')
            ->assertCount('importCosts', 1)
            ->assertSet('importCosts.0.payee_name', 'ТЕСТ ШПЕДИТЕР ДООЕЛ')
            ->assertSet('importCosts.0.base_amount', '2000.00')
            ->assertSet('importCosts.0.vat_amount', '360.00')
            ->assertSet('importCosts.0.source', 'forwarder');

        $component->set('forwarderFile', $this->files()['forwarderFile'])
            ->call('readImportDocuments')
            ->assertCount('importCosts', 1);
    }

    public function test_without_an_ecd_the_rate_comes_from_nbrm_on_the_invoice_date_with_a_warning(): void
    {
        Http::fake(['nbrm.mk/*' => Http::response([['oznaka' => 'EUR', 'sreden' => 61.7, 'nomin' => 1, 'datum' => '2026-02-24T00:00:00']], 200)]);
        $company = $this->company();
        FakeScannedInvoiceReader::$next = $this->foreignInvoice();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('importInvoiceFile', $this->files()['importInvoiceFile'])
            ->call('readImportDocuments')
            ->assertSet('importExchangeRate', '61.7')
            ->assertSet('lines.0.unit_price', '771.25')
            ->assertSee('НБРМ');
    }

    public function test_a_failing_reader_shows_an_error_and_keeps_the_rest(): void
    {
        $company = $this->company();
        FakeCustomsDeclarationReader::$throws = new ScannedInvoiceReadException('x');
        FakeScannedInvoiceReader::$next = $this->foreignInvoice();
        $files = $this->files();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('ecdFile', $files['ecdFile'])
            ->set('importInvoiceFile', $files['importInvoiceFile'])
            ->call('readImportDocuments')
            ->assertHasErrors('ecdFile')
            ->assertSet('supplierInvoiceNumber', 'T-1/26');
    }

    public function test_reading_with_no_file_shows_an_error(): void
    {
        $company = $this->company();

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->call('readImportDocuments')
            ->assertHasErrors('importDocuments');
    }

    public function test_reading_requires_the_scan_permission(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);
        config(['services.anthropic.key' => '']);

        Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->call('readImportDocuments')
            ->assertForbidden();
    }

    public function test_add_all_unknown_lines_as_items_creates_each_once_and_reuses_existing(): void
    {
        $company = $this->company();
        $known = Item::factory()->create(['company_id' => $company->id, 'name' => 'Позната', 'code' => 'A-0001']);

        $component = Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('lines', [
                ['item_id' => '', 'account_id' => '', 'description' => 'Позната', 'quantity' => '1', 'unit_price' => '10', 'unit_price_gross' => '10', 'price_basis' => 'net', 'vat_rate' => '0', 'discount_percent' => '0', 'vat_deductible' => true, 'needs_review' => false],
                ['item_id' => '', 'account_id' => '', 'description' => 'Нова една', 'quantity' => '1', 'unit_price' => '10', 'unit_price_gross' => '10', 'price_basis' => 'net', 'vat_rate' => '0', 'discount_percent' => '0', 'vat_deductible' => true, 'needs_review' => false],
                ['item_id' => '', 'account_id' => '', 'description' => 'Нова една', 'quantity' => '2', 'unit_price' => '10', 'unit_price_gross' => '10', 'price_basis' => 'net', 'vat_rate' => '0', 'discount_percent' => '0', 'vat_deductible' => true, 'needs_review' => false],
                ['item_id' => '', 'account_id' => '', 'description' => '', 'quantity' => '1', 'unit_price' => '10', 'unit_price_gross' => '10', 'price_basis' => 'net', 'vat_rate' => '0', 'discount_percent' => '0', 'vat_deductible' => true, 'needs_review' => false],
            ])
            ->call('addAllUnknownLinesAsItems');

        $this->assertSame((string) $known->id, $component->get('lines.0.item_id'));
        $this->assertNotSame('', $component->get('lines.1.item_id'));
        $this->assertSame($component->get('lines.1.item_id'), $component->get('lines.2.item_id'));
        $this->assertSame('', $component->get('lines.3.item_id'));
        $this->assertSame(2, Item::where('company_id', $company->id)->count());
    }
}
```

- [ ] **Step 2:** Run: `php artisan test tests/Feature/Invoicing/PurchaseInvoiceImportScanTest.php` — Expected: FAIL (својствата/акциите не постојат).

- [ ] **Step 3: Имплементација во `PurchaseInvoiceForm.php`**

(а) Додај `use` линии: `App\Services\ExchangeRateService;` (веќе е додадена од увозната фаза — не дуплирај), `App\Services\Inventory\CustomsTariffAggregator;`, `App\Services\Invoicing\CustomsDeclarationReader;`, `App\Services\Invoicing\ImportScanChecks;`, `App\Services\Invoicing\ImportScanMapper;`, `App\Services\Invoicing\ScannedInvoiceReadException;` (провери што веќе постои).

(б) Јавни својства (до `$scanFile`):

```php
    public $ecdFile = null;

    public $importInvoiceFile = null;

    public $forwarderFile = null;

    /** @var string[] */
    public array $importScanWarnings = [];
```

(в-1) Додај `use App\Support\Bcmath;` ако го нема.

(в) Нови методи (по `readScan()`/`createSuggestedPartner()`):

```php
    /**
     * Чита до три увозни документи по фиксен редослед: ЕЦД (од него е
     * курсот), па фактурата од добавувач (се претвора по тој курс), па
     * шпедитерската. Секое ново читање ги заменува редовите од истиот
     * извор, не ги удвојува. Грешка на еден документ не ги крши другите.
     */
    public function readImportDocuments(): void
    {
        abort_unless($this->canReadScans(), 403);

        // Три повици кон API на еден барање; ограничувањето на PHP не е
        // проверено на серверот, па се бара повеќе време наместо да се верува
        // на стандардните 30 секунди.
        @set_time_limit(240);

        $this->resetErrorBag(['ecdFile', 'importInvoiceFile', 'forwarderFile', 'importDocuments']);
        $this->importScanWarnings = [];

        $rules = 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240';
        $this->validate(['ecdFile' => $rules, 'importInvoiceFile' => $rules, 'forwarderFile' => $rules]);

        if ($this->ecdFile === null && $this->importInvoiceFile === null && $this->forwarderFile === null) {
            $this->addError('importDocuments', 'Прикачи барем еден документ.');

            return;
        }

        $mapper = new ImportScanMapper;
        $warnings = [];
        $ecd = null;
        $invoice = null;
        $forwarder = null;
        $rateFromNbrm = false;

        if ($this->ecdFile !== null) {
            try {
                $ecd = app(CustomsDeclarationReader::class)->read($this->ecdFile, $this->company);
            } catch (ScannedInvoiceReadException $e) {
                report($e);
                $this->addError('ecdFile', 'Не можев да ја прочитам ЕЦД — внеси ја рачно.');
            }
        }

        if ($this->importInvoiceFile !== null) {
            try {
                $invoice = app(ScannedInvoiceReader::class)->read($this->importInvoiceFile, $this->company);
            } catch (ScannedInvoiceReadException $e) {
                report($e);
                $this->addError('importInvoiceFile', 'Не можев да ја прочитам фактурата — внеси ја рачно.');
            }
        }

        if ($this->forwarderFile !== null) {
            try {
                $forwarder = app(ScannedInvoiceReader::class)->read($this->forwarderFile, $this->company);
            } catch (ScannedInvoiceReadException $e) {
                report($e);
                $this->addError('forwarderFile', 'Не можев да ја прочитам шпедитерската фактура — внеси ја рачно.');
            }
        }

        if ($ecd === null && $invoice === null && $forwarder === null) {
            return;
        }

        $this->isImport = true;

        if ($ecd !== null) {
            $fields = $mapper->declarationFields($ecd);

            $this->customsDeclarationNumber = $fields['customsDeclarationNumber'];
            $this->importDate = $fields['importDate'];
            $this->importCurrencyCode = $fields['importCurrencyCode'] ?? $this->importCurrencyCode;
            $this->importExchangeRate = $fields['importExchangeRate'];

            if ($fields['importCurrencyCode'] === null && filled($ecd->currency)) {
                $warnings[] = "Валутата на ЕЦД ({$ecd->currency}) не е меѓу понудените — избери ја рачно.";
            }

            $summary = (new CustomsTariffAggregator)->aggregate($ecd->items);

            $this->tariffLines = array_map(fn (array $row) => $row + ['source' => 'ecd'], $summary['rows']);
        }

        if ($invoice !== null) {
            $rate = Bcmath::isPlainNumber($this->importExchangeRate) ? $this->importExchangeRate : null;

            if ($invoice->currency !== null && $invoice->currency !== 'MKD') {
                if ($rate === null) {
                    $date = filled($invoice->invoiceDate) ? $invoice->invoiceDate : $this->invoiceDate;

                    try {
                        $rate = (string) app(ExchangeRateService::class)->getRate($invoice->currency, Carbon::parse($date));
                        $this->importExchangeRate = $rate;
                        $this->importCurrencyCode = in_array($invoice->currency, ImportScanMapper::CURRENCIES, true) ? $invoice->currency : $this->importCurrencyCode;
                        $rateFromNbrm = true;
                    } catch (\Throwable) {
                        $this->addError('importExchangeRate', 'Нема ЕЦД и не можев да го повлечам курсот од НБРМ — внеси го рачно и прочитај ја фактурата повторно.');
                        $rate = null;
                    }
                }

                if ($rate !== null) {
                    $converted = $mapper->convertInvoice($invoice, $rate);
                    $warnings = array_merge($warnings, $converted['warnings']);

                    $this->applyScan($converted['invoice']);
                    $this->scanRead = true;

                    $this->importCosts = array_values(array_filter($this->importCosts, fn ($row) => ($row['source'] ?? '') !== 'invoice'));
                    $this->importCosts = array_merge($this->importCosts, $converted['costs']);
                }
            } else {
                $this->applyScan($invoice);
                $this->scanRead = true;
            }
        }

        if ($forwarder !== null) {
            $cost = $mapper->forwarderCost($forwarder);
            $warnings = array_merge($warnings, $cost['warnings']);

            $this->importCosts = array_values(array_filter($this->importCosts, fn ($row) => ($row['source'] ?? '') !== 'forwarder'));
            $this->importCosts[] = $cost['row'];
        }

        $warnings = array_merge($warnings, (new ImportScanChecks)->run(
            $ecd, $invoice, $forwarder, (string) $this->company->tax_id, $rateFromNbrm,
        ));

        $this->importScanWarnings = $warnings;
        $this->ecdFile = $this->importInvoiceFile = $this->forwarderFile = null;
    }

    /**
     * Еден клик за сите ставки без артикл (фактура од ~110 ставки): исто
     * правило како „Внеси како артикл" — исто име (без разлика на големи/мали
     * букви) го користи постоечкиот артикл — но со еден бројач на шифри.
     */
    public function addAllUnknownLinesAsItems(): void
    {
        Gate::authorize('create', Item::class);

        $existing = Item::where('company_id', $this->company->id)->get(['id', 'code', 'name']);
        $byName = $existing->keyBy(fn ($item) => mb_strtolower($item->name));
        $codes = $existing->pluck('code')->flip();
        $next = $existing->count() + 1;

        foreach ($this->lines as $index => $line) {
            if (($line['item_id'] ?? '') !== '') {
                continue;
            }

            $name = trim((string) ($line['description'] ?? ''));

            if ($name === '') {
                continue;
            }

            $item = $byName->get(mb_strtolower($name));

            if (! $item) {
                do {
                    $code = 'A-'.str_pad((string) $next++, 4, '0', STR_PAD_LEFT);
                } while ($codes->has($code));

                $item = Item::create([
                    'company_id' => $this->company->id,
                    'code' => $code,
                    'name' => mb_substr($name, 0, 255),
                    'unit_of_measure' => 'бр.',
                    'vat_rate' => is_numeric($line['vat_rate'] ?? null) ? $line['vat_rate'] : '18.00',
                    'type' => 'product',
                    'is_active' => true,
                ]);

                $byName->put(mb_strtolower($name), $item);
                $codes->put($code, true);
            }

            $this->lines[$index]['item_id'] = (string) $item->id;
            $this->lines[$index]['account_id'] = '';
        }
    }
```

(г) Во `updatedIsImport(bool $value)` не менувај ништо.

- [ ] **Step 4:** Run: `php artisan test tests/Feature/Invoicing/PurchaseInvoiceImportScanTest.php` — Expected: PASS (8 теста). Ако `test_reading_requires_the_scan_permission` не фрла 403 затоа што `canReadScans()` бара клуч И улога — коректно е: корисникот нема улога `admin`/`accountant` и нема клуч.

- [ ] **Step 5:** Run regression: `php artisan test tests/Feature/Invoicing tests/Feature/PurchaseInvoiceFormTest.php tests/Feature/PurchaseInvoiceImportFormTest.php` — Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Invoicing/PurchaseInvoiceForm.php tests/Feature/Invoicing/PurchaseInvoiceImportScanTest.php
git commit -m "Purchase invoice form: read ECD, supplier and forwarder documents; bulk-create items"
```

---

### Task 7: Blade — три места за прикачување, предупредувања, копче за непознати, штикло секогаш видливо

**Files:**
- Modify: `resources/views/livewire/invoicing/purchase-invoice-form.blade.php`
- Modify: `tests/Feature/PurchaseInvoiceImportFormTest.php` (постојниот тест за штиклото)
- Test: `tests/Feature/Invoicing/PurchaseInvoiceImportScanViewTest.php`

**Interfaces:**
- Consumes: сè од Task 6 (`ecdFile`, `importInvoiceFile`, `forwarderFile`, `importScanWarnings`, `readImportDocuments`, `addAllUnknownLinesAsItems`, `canReadScans()`), постојните `$isImport`, `$lines`, `$rows`.

- [ ] **Step 1: Тестови што паѓаат**

Во `tests/Feature/PurchaseInvoiceImportFormTest.php` постојниот `test_import_checkbox_is_hidden_until_a_line_has_a_stock_item` се преименува и обрнува: `test_import_checkbox_is_visible_even_before_any_stock_line` со `->assertSee('Фактура од увоз')`.

`tests/Feature/Invoicing/PurchaseInvoiceImportScanViewTest.php`:

```php
<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\PurchaseInvoiceForm;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoiceImportScanViewTest extends TestCase
{
    use RefreshDatabase;

    private function form(bool $withKey, bool $admin = true)
    {
        Role::findOrCreate('admin');
        config(['services.anthropic.key' => $withKey ? 'test-key' : '']);
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        if ($admin) {
            $user->assignRole('admin');
        }
        $this->actingAs($user);

        return Livewire::test(PurchaseInvoiceForm::class, ['company' => $company]);
    }

    public function test_the_three_upload_slots_show_only_when_import_is_ticked_and_scanning_is_available(): void
    {
        $this->form(true)
            ->assertDontSee('Царинска декларација (ЕЦД)')
            ->set('isImport', true)
            ->assertSee('Фактура од добавувач')
            ->assertSee('Царинска декларација (ЕЦД)')
            ->assertSee('Шпедитерска фактура')
            ->assertSee('Прочитај ги документите');
    }

    public function test_no_upload_slots_without_a_key(): void
    {
        $this->form(false)->set('isImport', true)->assertDontSee('Прочитај ги документите');
    }

    public function test_the_regular_scan_card_is_hidden_while_import_is_ticked(): void
    {
        $this->form(true)
            ->assertSee('Прикачи скенирана фактура')
            ->set('isImport', true)
            ->assertDontSee('Прикачи скенирана фактура');
    }

    public function test_scan_warnings_are_listed(): void
    {
        $this->form(true)
            ->set('isImport', true)
            ->set('importScanWarnings', ['Тест предупредување број еден.'])
            ->assertSee('Тест предупредување број еден.');
    }

    public function test_the_bulk_item_button_shows_when_a_line_has_a_description_but_no_item(): void
    {
        $this->form(true)
            ->set('lines.0.description', 'Нешто')
            ->assertSee('Внеси ги сите непознати како артикли');
    }
}
```

- [ ] **Step 2:** Run: `php artisan test tests/Feature/Invoicing/PurchaseInvoiceImportScanViewTest.php tests/Feature/PurchaseInvoiceImportFormTest.php` — Expected: FAIL.

- [ ] **Step 3: Blade измени**

(а) Картичката „Прикачи скенирана фактура" на врвот: условот `@if ($this->canReadScans() && ! $purchaseInvoice)` стане `@if ($this->canReadScans() && ! $purchaseInvoice && ! $isImport)`.

(б) Картичката со штиклото „Фактура од увоз": отстрани го опкружувачкиот `@if ($requiresWarehouse)` и неговиот одговарачки `@endif` (затвора непосредно пред `<x-card padding="p-0" class="overflow-hidden">` на „Ставки"). Картичката е сега секогаш видлива.

(в) Во портокаловиот „Увоз" бокс, веднаш по `<div class="flex items-center gap-2 mb-3">…ЕЦД…</div>` (насловот) а пред решетката со полињата `grid grid-cols-2 md:grid-cols-4`, додај:

```blade
                        @if ($this->canReadScans())
                            <div class="mb-4 rounded-lg border border-orange-200 bg-white px-3 py-3">
                                <p class="text-xs text-stone mb-2">Прикачи ги документите од увозот — PDF, JPG или PNG, до 10 МБ секој. Секој е по избор. Тами ги чита по ред: ЕЦД, фактурата, шпедитерската.</p>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                    <div>
                                        <x-input-label for="importInvoiceFile" value="Фактура од добавувач" />
                                        <input id="importInvoiceFile" type="file" wire:model="importInvoiceFile" accept=".pdf,.jpg,.jpeg,.png" class="text-xs w-full" />
                                        @error('importInvoiceFile') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <x-input-label for="ecdFile" value="Царинска декларација (ЕЦД)" />
                                        <input id="ecdFile" type="file" wire:model="ecdFile" accept=".pdf,.jpg,.jpeg,.png" class="text-xs w-full" />
                                        @error('ecdFile') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <x-input-label for="forwarderFile" value="Шпедитерска фактура" />
                                        <input id="forwarderFile" type="file" wire:model="forwarderFile" accept=".pdf,.jpg,.jpeg,.png" class="text-xs w-full" />
                                        @error('forwarderFile') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                                @error('importDocuments') <p class="text-red-600 text-xs mt-2">{{ $message }}</p> @enderror
                                <div class="mt-3 flex items-center gap-3">
                                    <x-secondary-button type="button" wire:click="readImportDocuments" wire:loading.attr="disabled" wire:target="readImportDocuments,ecdFile,importInvoiceFile,forwarderFile">
                                        <span wire:loading.remove wire:target="readImportDocuments">Прочитај ги документите</span>
                                        <span wire:loading wire:target="readImportDocuments">Читам… (може да потрае)</span>
                                    </x-secondary-button>
                                </div>
                            </div>
                        @endif

                        @if ($importScanWarnings !== [])
                            <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900 space-y-1">
                                <p class="font-semibold">Провери пред да зачуваш:</p>
                                @foreach ($importScanWarnings as $warning)
                                    <p>• {{ $warning }}</p>
                                @endforeach
                            </div>
                        @endif
```

(г) Во карпицата „Ставки" (`<div class="flex items-center justify-between px-4 py-3 border-b border-sand">` со „+ Додади ставка"), покрај копчето „+ Додади ставка", додај (во истиот `flex`, во групирање со `<div class="flex items-center gap-4">`):

```blade
                    @if (collect($lines)->contains(fn ($l) => ($l['item_id'] ?? '') === '' && trim((string) ($l['description'] ?? '')) !== ''))
                        <button type="button" wire:click="addAllUnknownLinesAsItems" class="text-brand text-sm font-medium hover:underline">Внеси ги сите непознати како артикли</button>
                    @endif
```

- [ ] **Step 4:** Run: `php artisan test tests/Feature/Invoicing tests/Feature/PurchaseInvoiceImportFormTest.php tests/Feature/PurchaseInvoiceFormTest.php` — Expected: PASS. Потоа целата серија: `php artisan test` (трае ~9 мин) — Expected: сè зелено.

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/invoicing/purchase-invoice-form.blade.php tests/Feature/PurchaseInvoiceImportFormTest.php tests/Feature/Invoicing/PurchaseInvoiceImportScanViewTest.php
git commit -m "Import section: three document upload slots, scan warnings and bulk item button"
```

---

## Self-Review Notes

- **Покриеност на спецификацијата:** три места + еден клик/редослед ЕЦД→фактура→шпедитер (T6, T7); ЕЦД читач на Sonnet (T1); тарифно собирање по тарифен број (T2); `kind`/charge → увозни трошоци и поголем лимит/рок (T3, T4); курс од ЕЦД, НБРМ без ЕЦД + предупредување (T6, T5); шпедитерски ред (T4, T6); сите 8 проверки (T5: 1-3 збирови, 4 број во ЕЦД, 5 увозник, 6 валута, 7 шпедитер, 8 НБРМ + други давачки); копче за непознати со еден бројач (T6, T7); штиклото секогаш видливо и скриена обична картичка (T7); `set_time_limit(240)` (T6). Заокружување/отстапка од цент е документирана во спецификацијата, не бара код.
- **Не е во планот намерно:** тест со вистински документ (само анонимизирани фикстури); автоматско претворање на шпедитерска во странска валута; други давачки освен A00/B00.
- **Конзистентност на типови:** `ScannedCustomsItem`/`ScannedCustomsDeclaration` (T1) се користат идентично во T2, T5, T6; `ImportScanMapper::convertInvoice()` враќа `invoice|costs|warnings`, `forwarderCost()` враќа `row|warnings`, `declarationFields()` ги враќа 4 клучеви — T6 ги користи точно така; `ScannedInvoiceLine::$kind` (T3) е последен параметар, T4 го користи со именуван `kind:`.
