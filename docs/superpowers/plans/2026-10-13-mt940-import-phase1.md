# MT940 увоз — фаза 1 (читање и увоз) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сметководител качува еден или повеќе MT940 фајлови од Комерцијална банка и од секој добива извод во нацрт (сметка, број, датум, состојби, ставки со другата страна и повикување на број), прикачен како документ; со команда `bank:check-mt940` се проверува цела папка без запишување.

**Architecture:** Чист парсер (`Mt940Parser`) вади структура од текстот; `Mt940Importer` создава `BankStatement` + `BankStatementLine` (сите `unclear`, без партнер) и ги чува предупредувањата; `BankStatementIndex` добива копче „Увези MT940“. Постојниот екран за книжење се прилагодува да не ги губи податоците што дошле со фајлот. Препознавање, „проверка една по една“, запомнување и матчер се фази 2–4 со посебни планови.

**Tech Stack:** Laravel 13, Livewire 3 (`WithFileUploads`), PHPUnit, bcmath, MySQL (продукција) / SQLite (тестови).

**Spec:** `docs/superpowers/specs/2026-10-13-mt940-import-design.md` (фаза 1 од „Фази на градење“).

## Global Constraints

- Само денарски изводи (валута `MKD`); сè друго се одбива со порака.
- Ништо не се книжи при увоз; сите ставки се `kind = unclear`, `partner_id = null`, изводот е `draft`.
- Вистински банкарски фајлови и броеви на сметки **не влегуваат во репото** (ни тестови, ни документација). Тестовите користат само `Tests\Support\Mt940Fixture` со измислени вредности.
- Сите износи преку bcmath, по строг регекс (`^\d+(,\d{0,2})?$`); запирка е децимален разделник на банката.
- Имињата на Livewire акции не смеат да се резервирани (`upload`, `get`, `set`, `call`…).
- Уникатни индекси на 3+ колони: рачно име под 64 знаци (MySQL). Оваа фаза нема нов уникатен индекс.
- Текстот на интерфејсот е на македонски; коментарите во кодот на македонски како околниот код.
- Работи се директно во главната папка на репото (не `EnterWorktree` — `composer install` виси). Гранка `mt940-import`.
- `php artisan test` директно (не `composer test` — паѓа на лимит од 300 с а враќа exit 0). Целата серија (~8,5 мин) само еднаш пред спојување; во текот на работата само засегнатите датотеки.

## Review Focus

Влезови што спецификацијата ги подразбира, а ниту една задача не би ги тестирала сама; секој има свој тест во задачата што го поседува кодот:

1. **Фајл зачуван од Windows** (BOM на почеток, CRLF линии) се увезува исто како чист. → Task 1, Task 3.
2. **Опис/име подолги од колоната (255) и полиња дополнети со празни места** се скратуваат/чистат, не фрлаат 500. → Task 1.
3. **Зачувување на екранот за книжење (двапати) по увоз** не ги губи другата страна и повикувањето на број; изменет `id` во формата не позајмува податоци од туѓ извод. → Task 2.
4. **Истиот фајл двапати во една серија** и **сметка на друга фирма** → втората/туѓата се одбива, ништо не се создава. → Task 3, Task 4.
5. **Ставка `:61:` без `:86:` и две идентични ставки** се зачувуваат обете, без грешка и без тивко здружување. → Task 1.

---

## File Structure

| Датотека | Одговорност |
|---|---|
| `app/Support/Bank/Mt940/Mt940Exception.php` | исклучок со порака на македонски за одбиен фајл |
| `app/Support/Bank/Mt940/Mt940Line.php` | една ставка (вредносен објект) |
| `app/Support/Bank/Mt940/Mt940Statement.php` | еден извод (вредносен објект), `movement()`, `difference()` |
| `app/Support/Bank/Mt940/Mt940Parser.php` | текст → `Mt940Statement`, чиста логика |
| `app/Support/Bank/Mt940/BankDirectory.php` | BIC → име на банка |
| `app/Support/Bank/Mt940/Mt940FolderReport.php` | анализа на повеќе фајлови (дупки, прекини, разлики) |
| `app/Services/Bank/Mt940Importer.php` | создава извод и ставки од парсиран извод; предупредувања |
| `app/Services/Bank/Mt940ImportOutcome.php` | исход по фајл (за приказ) |
| `app/Console/Commands/CheckMt940Folder.php` | `bank:check-mt940 {folder}` |
| `database/migrations/2026_10_13_100000_add_counterparty_to_bank_statement_lines_table.php` | `counterparty_account`, `counterparty_name` |
| `app/Support/Bank/StatementSequence.php` (измена) | `numberTaken()` — едно место за проверка на дупликат |
| `app/Models/BankStatementLine.php` (измена) | `fillable` |
| `app/Livewire/Bank/BankStatementBook.php` + blade (измена) | пренос на податоци по id, прикажување |
| `app/Livewire/Bank/BankStatementIndex.php` + blade (измена) | „Увези MT940“ |
| `tests/Support/Mt940Fixture.php` | синтетички MT940 |

---

### Task 1: Парсер, вредносни објекти, фикстура

**Files:**
- Create: `app/Support/Bank/Mt940/Mt940Exception.php`
- Create: `app/Support/Bank/Mt940/Mt940Line.php`
- Create: `app/Support/Bank/Mt940/Mt940Statement.php`
- Create: `app/Support/Bank/Mt940/Mt940Parser.php`
- Create: `tests/Support/Mt940Fixture.php`
- Test: `tests/Unit/Bank/Mt940ParserTest.php`

**Interfaces:**
- Produces:
  - `Mt940Parser::parse(string $text): Mt940Statement` (фрла `Mt940Exception`); `Mt940Parser::decode(string $raw): string`
  - `Mt940Statement`: `string $account`, `int $number`, `string $date` (Y-m-d), `string $opening`, `string $closing` (со знак, „x.yy“), `list<Mt940Line> $lines`, `?string $bic`; `movement(): string`, `difference(): string`
  - `Mt940Line`: `string $date`, `LineDirection $direction`, `string $amount` (позитивно), `?string $counterpartyAccount`, `?string $counterpartyName`, `?string $referenceNumber`, `string $description`, `?string $bankReference`; `signedAmount(): string`
  - `Mt940Fixture::statement(array $options): string`, `Mt940Fixture::ACCOUNT`

- [ ] **Step 1: Креирај гранка**

```bash
cd "C:/Users/FinanceBuddy.mk/Documents/tami-web-app" && git switch -c mt940-import
```

- [ ] **Step 2: Фикстура** — `tests/Support/Mt940Fixture.php`

```php
<?php

namespace Tests\Support;

use Carbon\Carbon;

/**
 * Синтетички MT940 во обликот на Комерцијална банка. Сметките, имињата и
 * броевите се измислени — вистинските фајлови не влегуваат во репото.
 */
final class Mt940Fixture
{
    public const ACCOUNT = '100000000000001';

    /**
     * Опции: account, number, date (Y-m-d), opening и closing (со знак, „x.yy“),
     * currency, bic, envelope (false = без {1:}{2:}{4:}), eol, lines.
     * Ставка: dir (in|out), amount, date, party, name, refs, desc, bankref,
     * no86 (true = без :86:), raw86 (слободен :86:).
     *
     * @param  array<string, mixed>  $o
     */
    public static function statement(array $o = []): string
    {
        $account = $o['account'] ?? self::ACCOUNT;
        $number = $o['number'] ?? 1;
        $date = $o['date'] ?? '2026-02-02';
        $currency = $o['currency'] ?? 'MKD';
        $lines = $o['lines'] ?? [];
        $opening = $o['opening'] ?? '0.00';
        $closing = $o['closing'] ?? self::closingFor($opening, $lines);
        $ymd = Carbon::parse($date)->format('ymd');
        $eol = $o['eol'] ?? "\n";

        $rows = [
            ':20:STM'.$number.$ymd,
            ':25:'.$account,
            ':28C:'.$number.'/1',
            ':60F:'.self::balance($opening, $ymd, $currency),
        ];

        foreach ($lines as $line) {
            $lineYmd = Carbon::parse($line['date'] ?? $date)->format('ymd');
            $credit = ($line['dir'] ?? 'in') === 'in';
            $bankRef = $line['bankref'] ?? '1000001';

            $rows[] = ':61:'.$lineYmd.substr($lineYmd, 2, 4).($credit ? 'CD' : 'DD').self::money($line['amount'])
                .($credit ? 'NMSCNONREF//'.$bankRef : 'NMSC'.$bankRef);

            if (isset($line['raw86'])) {
                $rows[] = ':86:'.$line['raw86'];
            } elseif (! ($line['no86'] ?? false)) {
                $rows[] = ':86:/'.($credit ? 'ORDP' : 'BENM').'/'.($line['party'] ?? '100000000000002');
                $rows[] = '/'.($line['name'] ?? 'TEST PARTNER DOOEL SKOPJE');
                $rows[] = '/'.($line['refs'] ?? '000//000');
                $rows[] = '/'.($line['desc'] ?? 'Test opis');
            }
        }

        $rows[] = ':62F:'.self::balance($closing, $ymd, $currency);
        $body = implode($eol, $rows);

        if (($o['envelope'] ?? true) === false) {
            return $body;
        }

        $bic = $o['bic'] ?? 'TESTMK2X';

        return '{1:F01'.$bic.'AXXX0000000000}{2:I940'.$bic.'XXXXXN}{4:'.$eol.$body.$eol.'-}';
    }

    /** @param  list<array<string, mixed>>  $lines */
    private static function closingFor(string $opening, array $lines): string
    {
        $closing = $opening;

        foreach ($lines as $line) {
            $amount = bcadd($line['amount'], '0', 2);
            $closing = ($line['dir'] ?? 'in') === 'in' ? bcadd($closing, $amount, 2) : bcsub($closing, $amount, 2);
        }

        return $closing;
    }

    /** Како банката: „17,“ за цел износ, „17,50“ со децимали. */
    private static function money(string $value): string
    {
        [$whole, $cents] = explode('.', ltrim(bcadd($value, '0', 2), '-'));

        return $whole.','.($cents === '00' ? '' : $cents);
    }

    private static function balance(string $signed, string $ymd, string $currency): string
    {
        return (bccomp($signed, '0', 2) < 0 ? 'D' : 'C').$ymd.$currency.self::money($signed);
    }
}
```

- [ ] **Step 3: Напиши ги падачките тестови** — `tests/Unit/Bank/Mt940ParserTest.php`

```php
<?php

namespace Tests\Unit\Bank;

use App\Support\Bank\LineDirection;
use App\Support\Bank\Mt940\Mt940Exception;
use App\Support\Bank\Mt940\Mt940Parser;
use Tests\Support\Mt940Fixture;
use Tests\TestCase;

class Mt940ParserTest extends TestCase
{
    public function test_a_credit_line_is_read_with_both_sides_of_the_account_data(): void
    {
        $statement = Mt940Parser::parse(Mt940Fixture::statement([
            'number' => 1,
            'date' => '2026-02-02',
            'opening' => '0.00',
            'lines' => [[
                'dir' => 'in', 'amount' => '17.00', 'party' => '100000000000002',
                'name' => 'BANKA TEST AD SKOPJE', 'refs' => '1111111111//22222222222',
                'desc' => 'Plakane po osnov uslugi', 'bankref' => '1000007',
            ]],
        ]));

        $this->assertSame(Mt940Fixture::ACCOUNT, $statement->account);
        $this->assertSame(1, $statement->number);
        $this->assertSame('2026-02-02', $statement->date);
        $this->assertSame('0.00', $statement->opening);
        $this->assertSame('17.00', $statement->closing);
        $this->assertSame('TESTMK2X', $statement->bic);
        $this->assertSame('0.00', $statement->difference());
        $this->assertCount(1, $statement->lines);

        $line = $statement->lines[0];
        $this->assertSame('2026-02-02', $line->date);
        $this->assertSame(LineDirection::IN, $line->direction);
        $this->assertSame('17.00', $line->amount);
        $this->assertSame('100000000000002', $line->counterpartyAccount);
        $this->assertSame('BANKA TEST AD SKOPJE', $line->counterpartyName);
        $this->assertSame('1111111111//22222222222', $line->referenceNumber);
        $this->assertSame('Plakane po osnov uslugi', $line->description);
        $this->assertSame('1000007', $line->bankReference);
    }

    public function test_a_debit_line_is_an_outgoing_payment(): void
    {
        $statement = Mt940Parser::parse(Mt940Fixture::statement([
            'opening' => '1000.00',
            'lines' => [['dir' => 'out', 'amount' => '450.00', 'bankref' => '1000008']],
        ]));

        $line = $statement->lines[0];
        $this->assertSame(LineDirection::OUT, $line->direction);
        $this->assertSame('450.00', $line->amount);
        $this->assertSame('-450.00', $line->signedAmount());
        $this->assertSame('1000008', $line->bankReference);
        $this->assertSame('550.00', $statement->closing);
        $this->assertSame('-450.00', $statement->movement());
    }

    public function test_amounts_with_and_without_decimals_are_understood(): void
    {
        $statement = Mt940Parser::parse(Mt940Fixture::statement([
            'lines' => [
                ['dir' => 'in', 'amount' => '17.50'],
                ['dir' => 'in', 'amount' => '123456.00'],
                ['dir' => 'in', 'amount' => '0.05'],
            ],
        ]));

        $this->assertSame(['17.50', '123456.00', '0.05'], array_map(fn ($l) => $l->amount, $statement->lines));
        $this->assertSame('123473.55', $statement->closing);
    }

    public function test_a_debit_balance_is_negative(): void
    {
        $statement = Mt940Parser::parse(Mt940Fixture::statement([
            'opening' => '100.00',
            'lines' => [['dir' => 'out', 'amount' => '150.00']],
        ]));

        $this->assertSame('-50.00', $statement->closing);
        $this->assertSame('0.00', $statement->difference());
    }

    public function test_a_file_saved_by_windows_reads_the_same(): void
    {
        $lines = [['dir' => 'in', 'amount' => '10.00']];
        $clean = Mt940Parser::parse(Mt940Fixture::statement(['lines' => $lines]));
        $windows = Mt940Parser::parse("\xEF\xBB\xBF".Mt940Fixture::statement(['lines' => $lines, 'eol' => "\r\n"]));

        $this->assertEquals($clean, $windows);
    }

    public function test_a_file_without_the_swift_envelope_is_read(): void
    {
        $statement = Mt940Parser::parse(Mt940Fixture::statement([
            'envelope' => false,
            'lines' => [['dir' => 'in', 'amount' => '10.00']],
        ]));

        $this->assertNull($statement->bic);
        $this->assertCount(1, $statement->lines);
    }

    public function test_padded_fields_are_trimmed_and_zero_only_references_are_dropped(): void
    {
        $statement = Mt940Parser::parse(Mt940Fixture::statement([
            'lines' => [[
                'dir' => 'in', 'amount' => '10.00',
                'name' => 'MARKO TESTOV                       ',
                'refs' => '0                       //0                       ',
                'desc' => 'Ostanati plakana0                     ',
            ]],
        ]));

        $line = $statement->lines[0];
        $this->assertSame('MARKO TESTOV', $line->counterpartyName);
        $this->assertNull($line->referenceNumber);
        $this->assertSame('Ostanati plakana0', $line->description);
    }

    public function test_a_description_longer_than_the_column_is_cut(): void
    {
        $statement = Mt940Parser::parse(Mt940Fixture::statement([
            'lines' => [['dir' => 'in', 'amount' => '10.00', 'desc' => str_repeat('x', 400), 'name' => str_repeat('N', 400)]],
        ]));

        $this->assertSame(255, mb_strlen($statement->lines[0]->description));
        $this->assertSame(255, mb_strlen((string) $statement->lines[0]->counterpartyName));
    }

    public function test_an_unstructured_86_becomes_the_description_and_the_other_side_is_unknown(): void
    {
        $statement = Mt940Parser::parse(Mt940Fixture::statement([
            'lines' => [['dir' => 'in', 'amount' => '10.00', 'raw86' => "Prenos na sredstva\nmeki redovi"]],
        ]));

        $line = $statement->lines[0];
        $this->assertNull($line->counterpartyAccount);
        $this->assertNull($line->counterpartyName);
        $this->assertSame('Prenos na sredstva meki redovi', $line->description);
    }

    public function test_a_line_without_86_is_kept_and_twin_lines_are_not_merged(): void
    {
        $statement = Mt940Parser::parse(Mt940Fixture::statement([
            'lines' => [
                ['dir' => 'in', 'amount' => '10.00', 'no86' => true],
                ['dir' => 'in', 'amount' => '10.00', 'party' => '100000000000002', 'name' => 'TWIN'],
                ['dir' => 'in', 'amount' => '10.00', 'party' => '100000000000002', 'name' => 'TWIN'],
            ],
        ]));

        $this->assertCount(3, $statement->lines);
        $this->assertSame('', $statement->lines[0]->description);
        $this->assertNull($statement->lines[0]->counterpartyAccount);
        $this->assertSame('30.00', $statement->movement());
    }

    public function test_a_foreign_currency_statement_is_refused(): void
    {
        $this->expectException(Mt940Exception::class);
        $this->expectExceptionMessage('MKD');

        Mt940Parser::parse(Mt940Fixture::statement(['currency' => 'EUR']));
    }

    public function test_interim_balances_are_refused(): void
    {
        $text = str_replace(':62F:', ":60M:C260202MKD5,\n:62F:", Mt940Fixture::statement());

        $this->expectException(Mt940Exception::class);
        $this->expectExceptionMessage('меѓусостојби');

        Mt940Parser::parse($text);
    }

    public function test_two_statements_in_one_file_are_refused(): void
    {
        $one = Mt940Fixture::statement();

        $this->expectException(Mt940Exception::class);
        $this->expectExceptionMessage('повеќе од еден извод');

        Mt940Parser::parse($one."\n".$one);
    }

    public function test_a_reversal_is_refused(): void
    {
        $text = preg_replace('/(:61:\d{10})CD/', '$1RC', Mt940Fixture::statement([
            'lines' => [['dir' => 'in', 'amount' => '10.00']],
        ]));

        $this->expectException(Mt940Exception::class);
        $this->expectExceptionMessage('Сторно');

        Mt940Parser::parse($text);
    }

    public function test_an_amount_with_a_thousands_separator_refuses_the_whole_file(): void
    {
        $text = str_replace('CD10,', 'CD1.234,56', Mt940Fixture::statement([
            'lines' => [['dir' => 'in', 'amount' => '10.00']],
        ]));

        $this->expectException(Mt940Exception::class);
        $this->expectExceptionMessage('Неважечка ставка');

        Mt940Parser::parse($text);
    }

    public function test_a_missing_account_field_is_refused(): void
    {
        $text = preg_replace('/^:25:.*\n/m', '', Mt940Fixture::statement());

        $this->expectException(Mt940Exception::class);
        $this->expectExceptionMessage('недостасува поле :25:');

        Mt940Parser::parse($text);
    }
}
```

- [ ] **Step 4: Пушти го тестот и потврди дека паѓа**

Run: `php artisan test tests/Unit/Bank/Mt940ParserTest.php`
Expected: FAIL — `Class "App\Support\Bank\Mt940\Mt940Parser" not found`.

- [ ] **Step 5: Исклучок и вредносни објекти**

`app/Support/Bank/Mt940/Mt940Exception.php`:

```php
<?php

namespace App\Support\Bank\Mt940;

/** Фајлот не може безбедно да се прочита; пораката е на македонски и се покажува на корисникот. */
final class Mt940Exception extends \RuntimeException {}
```

`app/Support/Bank/Mt940/Mt940Line.php`:

```php
<?php

namespace App\Support\Bank\Mt940;

use App\Support\Bank\LineDirection;

/** Една ставка од извод. Износот е секогаш позитивен; насоката е од наша гледна точка. */
final class Mt940Line
{
    public function __construct(
        public readonly string $date,
        public readonly LineDirection $direction,
        public readonly string $amount,
        public readonly ?string $counterpartyAccount,
        public readonly ?string $counterpartyName,
        public readonly ?string $referenceNumber,
        public readonly string $description,
        public readonly ?string $bankReference,
    ) {}

    /** + за уплата, − за исплата. */
    public function signedAmount(): string
    {
        return $this->direction === LineDirection::OUT ? bcmul($this->amount, '-1', 2) : bcadd($this->amount, '0', 2);
    }
}
```

`app/Support/Bank/Mt940/Mt940Statement.php`:

```php
<?php

namespace App\Support\Bank\Mt940;

/** Еден прочитан извод. Состојбите се со знак од наша гледна точка: + = пари на сметка. */
final class Mt940Statement
{
    /** @param  list<Mt940Line>  $lines */
    public function __construct(
        public readonly string $account,
        public readonly int $number,
        public readonly string $date,
        public readonly string $opening,
        public readonly string $closing,
        public readonly array $lines,
        public readonly ?string $bic,
    ) {}

    /** Уплати минус исплати. */
    public function movement(): string
    {
        return array_reduce($this->lines, fn (string $carry, Mt940Line $line) => bcadd($carry, $line->signedAmount(), 2), '0.00');
    }

    /** Почетна + движење − крајна; нула значи дека изводот се совпаѓа. */
    public function difference(): string
    {
        return bcsub(bcadd($this->opening, $this->movement(), 2), $this->closing, 2);
    }
}
```

- [ ] **Step 6: Парсерот** — `app/Support/Bank/Mt940/Mt940Parser.php`

```php
<?php

namespace App\Support\Bank\Mt940;

use App\Support\Bank\LineDirection;

/**
 * Чита еден MT940 извод од банка. Чиста класа: ништо не запишува.
 *
 * Проверено на вистински изводи од Комерцијална банка: две облици на :61:,
 * износи со запирка (без децимали за цел износ), :86: со 4 линии
 * (/ORDP|BENM/сметка, име, референци, опис). Сè што не личи на тоа не се
 * погодува — или цел фајл се одбива (пари што не се разбираат), или :86:
 * оди во описот а другата страна останува непозната.
 */
final class Mt940Parser
{
    private const INTERIM_TAGS = ['60M', '62M', '64', '65'];

    private const MAX_TEXT = 255;

    /** Тргнува BOM и ги претвора не-UTF-8 бајти (Windows-1252/ISO-8859-1) во UTF-8. */
    public static function decode(string $raw): string
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;

        return mb_check_encoding($raw, 'UTF-8') ? $raw : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }

    public static function parse(string $text): Mt940Statement
    {
        $text = str_replace("\r", '', self::decode($text));
        $fields = self::fields(self::body($text));
        $tags = array_column($fields, 0);

        if (array_intersect($tags, self::INTERIM_TAGS) !== []) {
            throw new Mt940Exception('Изводот има меѓусостојби (:60M:, :62M:) или состојби :64:/:65: — тоа не е поддржано.');
        }

        foreach (['20', '25', '28C', '60F', '62F'] as $tag) {
            if (count(array_keys($tags, $tag, true)) > 1) {
                throw new Mt940Exception('Фајлот содржи повеќе од еден извод — увезете ги одделно.');
            }
        }

        foreach (['25', '28C', '60F', '62F'] as $tag) {
            if (! in_array($tag, $tags, true)) {
                throw new Mt940Exception("Во фајлот недостасува поле :{$tag}:.");
            }
        }

        $account = self::account(self::value($fields, '25'));
        $number = self::number(self::value($fields, '28C'));
        [$date, $openingCurrency, $opening] = self::balance(self::value($fields, '60F'));
        [, $closingCurrency, $closing] = self::balance(self::value($fields, '62F'));

        foreach (array_unique([$openingCurrency, $closingCurrency]) as $currency) {
            if ($currency !== 'MKD') {
                throw new Mt940Exception("Валутата е {$currency}. Се увезуваат само денарски изводи (MKD).");
            }
        }

        $bic = preg_match('/\{1:F01([A-Z0-9]{8})/', $text, $m) === 1 ? $m[1] : null;

        return new Mt940Statement($account, $number, $date, $opening, $closing, self::lines($fields), $bic);
    }

    private static function body(string $text): string
    {
        $start = strpos($text, '{4:');

        if ($start === false) {
            return $text;
        }

        $body = substr($text, $start + 3);
        $end = strrpos($body, '-}');

        return $end === false ? $body : substr($body, 0, $end);
    }

    /**
     * Поле почнува со „:20:“ / „:28C:“; редовите што следат му припаѓаат на него.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function fields(string $body): array
    {
        $fields = [];

        foreach (explode("\n", $body) as $row) {
            if (preg_match('/^:(\d{2}[A-Z]?):(.*)$/', $row, $m) === 1) {
                $fields[] = [$m[1], $m[2]];
            } elseif ($fields !== [] && trim($row) !== '' && trim($row) !== '-') {
                $fields[count($fields) - 1][1] .= "\n".$row;
            }
        }

        return $fields;
    }

    /** Првиот ред на првото поле со таа ознака. */
    private static function value(array $fields, string $tag): string
    {
        foreach ($fields as [$name, $value]) {
            if ($name === $tag) {
                return trim(explode("\n", $value)[0]);
            }
        }

        return '';
    }

    private static function account(string $raw): string
    {
        $account = str_contains($raw, '/') ? substr($raw, (int) strrpos($raw, '/') + 1) : $raw;

        if (preg_match('/^[A-Za-z0-9]{1,34}$/D', $account) !== 1) {
            throw new Mt940Exception("Неважечка сметка (:25:) „{$raw}“.");
        }

        return $account;
    }

    private static function number(string $raw): int
    {
        if (preg_match('/^(\d{1,6})(?:\/\d+)?$/D', $raw, $m) !== 1 || (int) $m[1] < 1) {
            throw new Mt940Exception("Неважечки број на извод (:28C:) „{$raw}“.");
        }

        return (int) $m[1];
    }

    /** @return array{0: string, 1: string, 2: string} датум, валута, износ со знак */
    private static function balance(string $raw): array
    {
        if (preg_match('/^([CD])(\d{6})([A-Z]{3})(\d+(?:,\d{0,2})?)$/D', $raw, $m) !== 1) {
            throw new Mt940Exception("Неважечка состојба „{$raw}“.");
        }

        $amount = self::amount($m[4]);

        if ($m[1] === 'D' && bccomp($amount, '0', 2) !== 0) {
            $amount = bcmul($amount, '-1', 2);
        }

        return [self::date($m[2]), $m[3], $amount];
    }

    /** „17,“ → 17.00, „17,5“ → 17.50. Нема разделник на илјади — се одбива. */
    private static function amount(string $raw): string
    {
        if (preg_match('/^(\d+)(?:,(\d{0,2}))?$/D', $raw, $m) !== 1) {
            throw new Mt940Exception("Неважечки износ „{$raw}“.");
        }

        return $m[1].'.'.str_pad($m[2] ?? '', 2, '0');
    }

    private static function date(string $yymmdd): string
    {
        $year = 2000 + (int) substr($yymmdd, 0, 2);
        $month = (int) substr($yymmdd, 2, 2);
        $day = (int) substr($yymmdd, 4, 2);

        if (! checkdate($month, $day, $year)) {
            throw new Mt940Exception("Неважечки датум „{$yymmdd}“.");
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Секое :61: добива го следното :86:. :86: пред првата ставка или по
     * затворањето (:62F:) е за целиот извод и не се користи.
     *
     * @param  list<array{0: string, 1: string}>  $fields
     * @return list<Mt940Line>
     */
    private static function lines(array $fields): array
    {
        $pairs = [];
        $closed = false;

        foreach ($fields as [$tag, $value]) {
            if ($tag === '62F') {
                $closed = true;
            } elseif ($tag === '61' && ! $closed) {
                $pairs[] = ['61' => $value, '86' => null];
            } elseif ($tag === '86' && ! $closed && $pairs !== []) {
                $last = count($pairs) - 1;
                $pairs[$last]['86'] = ($pairs[$last]['86'] === null ? '' : $pairs[$last]['86']."\n").$value;
            }
        }

        return array_map(fn (array $pair) => self::line($pair['61'], $pair['86']), $pairs);
    }

    private static function line(string $raw61, ?string $raw86): Mt940Line
    {
        // Вториот ред на :61: е дополнителен опис на банката — не се користи.
        $first = trim(explode("\n", $raw61)[0]);

        if (preg_match('/^(\d{6})(?:\d{4})?(RC|RD|C|D)([A-Z])?(\d+(?:,\d{0,2})?)N([A-Z0-9]{3})(.*)$/D', $first, $m) !== 1) {
            throw new Mt940Exception("Неважечка ставка :61: „{$first}“.");
        }

        if ($m[2] === 'RC' || $m[2] === 'RD') {
            throw new Mt940Exception('Сторно ставка (RC/RD) не е поддржана.');
        }

        [$customer, $bank] = array_pad(explode('//', trim($m[6]), 2), 2, null);
        $bankReference = self::clean($bank ?? $customer);
        $bankReference = $bankReference === 'NONREF' ? null : $bankReference;

        [$account, $name, $references, $description] = self::details($raw86);

        return new Mt940Line(
            date: self::date($m[1]),
            direction: $m[2] === 'C' ? LineDirection::IN : LineDirection::OUT,
            amount: self::amount($m[4]),
            counterpartyAccount: $account,
            counterpartyName: $name,
            referenceNumber: $references,
            description: $description,
            bankReference: $bankReference,
        );
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string, 3: string} */
    private static function details(?string $raw86): array
    {
        if ($raw86 === null) {
            return [null, null, null, ''];
        }

        $rows = explode("\n", $raw86);

        if (preg_match('/^\/(ORDP|BENM)\/([A-Za-z0-9]{1,34})\s*$/', $rows[0], $m) !== 1) {
            return [null, null, null, self::cut((string) self::clean(implode(' ', $rows)))];
        }

        $references = self::clean(self::unslash($rows[2] ?? ''));

        if ($references !== null && preg_match('/^[0\/\s]*$/', $references) === 1) {
            $references = null;
        }

        $description = (string) self::clean(implode(' ', array_map(self::unslash(...), array_slice($rows, 3))));

        return [
            $m[2],
            self::cutOrNull(self::clean(self::unslash($rows[1] ?? ''))),
            $references,
            self::cut($description),
        ];
    }

    private static function unslash(string $row): string
    {
        return str_starts_with($row, '/') ? substr($row, 1) : $row;
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : $value;
    }

    private static function cut(string $value): string
    {
        return mb_substr($value, 0, self::MAX_TEXT);
    }

    private static function cutOrNull(?string $value): ?string
    {
        return $value === null ? null : self::cut($value);
    }
}
```

- [ ] **Step 7: Пушти го тестот и потврди дека поминува**

Run: `php artisan test tests/Unit/Bank/Mt940ParserTest.php`
Expected: PASS (15 tests). Ако `test_a_file_saved_by_windows_reads_the_same` падне на `assertEquals`, спореди ги `bic` и `lines` — BOM мора да е тргнат пред `strpos('{4:')`, а `\r` пред делењето по редови.

- [ ] **Step 8: Commit**

```bash
git add app/Support/Bank/Mt940 tests/Support/Mt940Fixture.php tests/Unit/Bank/Mt940ParserTest.php
git commit -m "$(cat <<'EOF'
MT940 parser: read one statement into a value object, refuse what is not understood

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Нови колони и екранот за книжење не ги губи податоците од фајлот

**Files:**
- Create: `database/migrations/2026_10_13_100000_add_counterparty_to_bank_statement_lines_table.php`
- Modify: `app/Models/BankStatementLine.php:16-21`
- Modify: `app/Livewire/Bank/BankStatementBook.php` (методите `persist()` и `render()`)
- Modify: `resources/views/livewire/bank/bank-statement-book.blade.php` (ќелија „Опис“)
- Modify: `docs/superpowers/specs/2026-10-13-mt940-import-design.md` (точка во „Ограничувања“)
- Test: `tests/Feature/Bank/BankStatementBookImportedDataTest.php`

**Interfaces:**
- Consumes: `Mt940Fixture::statement()`, `Mt940Parser::parse()` (Task 1).
- Produces: колони `bank_statement_lines.counterparty_account` (string 34 nullable), `counterparty_name` (string 255 nullable), во `BankStatementLine::$fillable`; `BankStatementBook` пренесува `reference_number`, `purpose_code`, `counterparty_account`, `counterparty_name` по `id` при секое зачувување, и по зачувување ги освежува `id`-јата во `$this->lines`.

- [ ] **Step 1: Напиши го падачкиот тест** — `tests/Feature/Bank/BankStatementBookImportedDataTest.php`

Тестот создава изводот директно (без увозникот, кој доаѓа во Task 3) за да не зависи од него.

```php
<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementBook;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\User;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BankStatementBookImportedDataTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        $this->company = Company::factory()->create();
    }

    private function importedStatement(int $number, string $party, string $name): BankStatement
    {
        $statement = BankStatement::factory()->for($this->company)->create([
            'number' => $number, 'statement_date' => '2026-02-02',
        ]);
        BankStatementLine::factory()->for($statement)->create([
            'direction' => LineDirection::IN,
            'amount' => '17.00',
            'kind' => LineKind::UNCLEAR,
            'description' => 'Plakane',
            'reference_number' => '1111111111//22222222222',
            'counterparty_account' => $party,
            'counterparty_name' => $name,
        ]);

        return $statement;
    }

    private function screen(BankStatement $statement)
    {
        return Livewire::test(BankStatementBook::class, ['company' => $this->company, 'statement' => $statement]);
    }

    public function test_saving_twice_keeps_what_came_with_the_file(): void
    {
        $statement = $this->importedStatement(1, '100000000000002', 'BANKA TEST AD');

        $this->screen($statement)
            ->set('lines.0.description', 'dopolneto')
            ->call('save')
            ->call('save');

        $line = $statement->fresh()->lines()->firstOrFail();
        $this->assertSame('100000000000002', $line->counterparty_account);
        $this->assertSame('BANKA TEST AD', $line->counterparty_name);
        $this->assertSame('1111111111//22222222222', $line->reference_number);
        $this->assertSame('dopolneto', $line->description);
    }

    public function test_a_line_added_by_hand_has_no_import_data_and_the_imported_one_keeps_its_own(): void
    {
        $statement = $this->importedStatement(1, '100000000000002', 'BANKA TEST AD');

        $this->screen($statement)
            ->call('addLine')
            ->set('lines.1.amount', '5.00')
            ->call('save');

        $lines = $statement->fresh()->lines()->get();
        $this->assertCount(2, $lines);
        $this->assertSame('100000000000002', $lines[0]->counterparty_account);
        $this->assertNull($lines[1]->counterparty_account);
        $this->assertNull($lines[1]->reference_number);
    }

    public function test_a_changed_id_cannot_borrow_data_from_another_statement(): void
    {
        $own = $this->importedStatement(1, '100000000000002', 'OWN');
        $other = $this->importedStatement(2, '100000000000003', 'OTHER');
        $otherLineId = $other->lines()->firstOrFail()->id;

        $this->screen($own)
            ->set('lines.0.id', $otherLineId)
            ->call('save');

        $line = $own->fresh()->lines()->firstOrFail();
        $this->assertNull($line->counterparty_account);
        $this->assertNull($line->counterparty_name);
        $this->assertSame('100000000000003', $other->fresh()->lines()->firstOrFail()->counterparty_account);
    }

    public function test_the_other_side_and_the_reference_are_shown_on_the_screen(): void
    {
        $statement = $this->importedStatement(1, '100000000000002', 'BANKA TEST AD');

        $this->screen($statement)
            ->assertSee('BANKA TEST AD')
            ->assertSee('100000000000002')
            ->assertSee('1111111111//22222222222');
    }
}
```

- [ ] **Step 2: Пушти го тестот и потврди дека паѓа**

Run: `php artisan test tests/Feature/Bank/BankStatementBookImportedDataTest.php`
Expected: FAIL — `table bank_statement_lines has no column named counterparty_account` (или `Unknown column`).

- [ ] **Step 3: Миграција** — `database/migrations/2026_10_13_100000_add_counterparty_to_bank_statement_lines_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            // Другата страна како што ја пишува банката: сметка и име (латиница).
            // Нема ЕДБ — банката не го дава.
            $table->string('counterparty_account', 34)->nullable();
            $table->string('counterparty_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropColumn(['counterparty_account', 'counterparty_name']);
        });
    }
};
```

- [ ] **Step 4: Модел** — `app/Models/BankStatementLine.php`, замени го `$fillable`:

```php
    protected $fillable = [
        'bank_statement_id', 'position', 'line_date', 'direction', 'amount', 'partner_id',
        'description', 'reference_number', 'purpose_code', 'counterparty_account', 'counterparty_name',
        'kind', 'account_id',
        'sales_invoice_id', 'purchase_invoice_id', 'sales_invoice_payment_id',
        'purchase_invoice_payment_id', 'created_payment',
    ];
```

- [ ] **Step 5: Екранот за книжење** — `app/Livewire/Bank/BankStatementBook.php`

Во `persist()` замени го блокот `DB::transaction(...)` и редовите по него:

```php
        DB::transaction(function () {
            $this->statement->update([
                'opening_balance' => $this->openingBalance === '' ? null : $this->openingBalance,
                'closing_balance' => $this->closingBalance === '' ? null : $this->closingBalance,
            ]);

            // Ставките се бришат и создаваат одново. Податоците што дошле со
            // фајлот (другата страна, повикување на број) ги нема во формата, па
            // се пренесуваат по id од досегашните ставки на ОВОЈ извод — не од
            // формата, чии вредности клиентот може да ги измени.
            $previous = $this->statement->lines()->get()->keyBy('id');

            $this->statement->lines()->delete();

            foreach (array_values($this->lines) as $position => $line) {
                $isIn = $line['direction'] === LineDirection::IN->value;
                $isInvoice = $line['kind'] === LineKind::INVOICE_PAYMENT->value;
                $kept = $previous->get($line['id'] ?? 0);

                $this->statement->lines()->create([
                    'position' => $position + 1,
                    'line_date' => $line['line_date'],
                    'direction' => $line['direction'],
                    'amount' => $line['amount'],
                    'partner_id' => $line['partner_id'] ?: null,
                    'description' => $line['description'] ?: null,
                    'reference_number' => $kept?->reference_number,
                    'purpose_code' => $kept?->purpose_code,
                    'counterparty_account' => $kept?->counterparty_account,
                    'counterparty_name' => $kept?->counterparty_name,
                    'kind' => $line['kind'],
                    'account_id' => $isInvoice ? null : ($line['account_id'] ?: null),
                    'sales_invoice_id' => $isInvoice && $isIn ? ($line['invoice_id'] ?: null) : null,
                    'purchase_invoice_id' => $isInvoice && ! $isIn ? ($line['invoice_id'] ?: null) : null,
                    'sales_invoice_payment_id' => $isInvoice && $isIn ? ($line['existing_payment_id'] ?: null) : null,
                    'purchase_invoice_payment_id' => $isInvoice && ! $isIn ? ($line['existing_payment_id'] ?: null) : null,
                ]);
            }
        });

        $this->statement = $this->statement->fresh();

        // Ставките се создадени со нови id-ја; формата мора да ги знае, инаку
        // второто зачувување нема од каде да ги пренесе податоците.
        $this->lines = array_values($this->lines);

        foreach ($this->statement->lines as $position => $saved) {
            $this->lines[$position]['id'] = $saved->id;
        }

        return true;
```

(Редот `return true;` веќе постои — остави само еден.) Во `render()` додај го `lineMeta`:

```php
        return view('livewire.bank.bank-statement-book', [
            'accounts' => Account::where('company_id', $this->company->id)->analytical()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'partners' => Partner::where('company_id', $this->company->id)->orderBy('name')->get(['id', 'name']),
            'problems' => $this->statement->isBooked() ? [] : StatementControls::problems($this->statement->fresh('lines')),
            'lineMeta' => $this->statement->lines()->get()->keyBy('id'),
        ]);
```

- [ ] **Step 6: Blade** — во `resources/views/livewire/bank/bank-statement-book.blade.php` замени ја ќелијата „Опис“ (`<td><x-text-input wire:model="lines.{{ $i }}.description" ... /></td>`):

```blade
                        <td>
                            <x-text-input wire:model="lines.{{ $i }}.description" class="w-full" :disabled="$statement->isBooked()" />
                            @php($meta = ! empty($line['id']) ? $lineMeta->get($line['id']) : null)
                            @if ($meta !== null && ($meta->counterparty_name || $meta->counterparty_account || $meta->reference_number))
                                <div class="mt-1 text-xs text-gray-500">
                                    {{ $meta->counterparty_name }}
                                    @if ($meta->counterparty_account) · {{ $meta->counterparty_account }} @endif
                                    @if ($meta->reference_number) · реф. {{ $meta->reference_number }} @endif
                                </div>
                            @endif
                        </td>
```

- [ ] **Step 7: Усогласи ја спецификацијата** — во `docs/superpowers/specs/2026-10-13-mt940-import-design.md` замени ја првата точка во „Ограничувања што се познати однапред“:

```markdown
- `BankStatementBook::persist()` ги **брише и повторно ги создава** сите ставки при секое зачувување. Податоците од фајлот (`counterparty_*`, `reference_number`, `purpose_code`, подоцна `match_*`, `reviewed_at`) се пренесуваат **по id од досегашните ставки на истиот извод**, не преку јавниот `$lines` (клиентот може да го смени), а по секое зачувување `id`-јата во `$lines` се освежуваат.
```

- [ ] **Step 8: Пушти ги тестовите**

Run: `php artisan test tests/Feature/Bank/BankStatementBookImportedDataTest.php tests/Feature/Bank/BankStatementBookTest.php`
Expected: PASS. Ако постоен тест од `BankStatementBookTest` падне, најверојатно очекува стари `id`-ја во `$lines` — провери дали тестот го чита `lines.N.id` по `save`.

- [ ] **Step 9: Commit**

```bash
git add database/migrations app/Models/BankStatementLine.php app/Livewire/Bank/BankStatementBook.php resources/views/livewire/bank/bank-statement-book.blade.php tests/Feature/Bank/BankStatementBookImportedDataTest.php docs/superpowers/specs/2026-10-13-mt940-import-design.md
git commit -m "$(cat <<'EOF'
Bank statement book keeps import data across saves, carried by id

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Увозник

**Files:**
- Create: `app/Support/Bank/Mt940/BankDirectory.php`
- Create: `app/Services/Bank/Mt940ImportOutcome.php`
- Create: `app/Services/Bank/Mt940Importer.php`
- Modify: `app/Support/Bank/StatementSequence.php` (нов метод)
- Modify: `app/Livewire/Bank/BankStatementIndex.php` (`numberIsTaken` користи заедничкиот метод)
- Test: `tests/Feature/Bank/Mt940ImporterTest.php`

**Interfaces:**
- Consumes: `Mt940Parser::parse`, `Mt940Statement`, `Mt940Line` (Task 1); колони од Task 2.
- Produces:
  - `StatementSequence::numberTaken(int $companyId, string $account, int $number, int $year): bool`
  - `Mt940Importer::importMany(Company $company, array $items): array` — `$items`: `list<array{name: string, text: string, file?: ?TemporaryUploadedFile}>`, враќа `list<Mt940ImportOutcome>`
  - `Mt940Importer::import(Company $company, Mt940Statement $parsed, string $name, ?TemporaryUploadedFile $file = null): Mt940ImportOutcome`
  - `Mt940ImportOutcome`: `bool $ok`, `string $file`, `string $message`, `list<string> $warnings`, `?int $statementId`, `int $lines`; `toArray(): array`

- [ ] **Step 1: Напиши ги падачките тестови** — `tests/Feature/Bank/Mt940ImporterTest.php`

```php
<?php

namespace Tests\Feature\Bank;

use App\Models\BankStatement;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\User;
use App\Services\Bank\Mt940Importer;
use App\Services\Bank\Mt940ImportOutcome;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use App\Support\BankStatementKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\Mt940Fixture;
use Tests\TestCase;

class Mt940ImporterTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
        $this->company = Company::factory()->create();
    }

    /** @param  array<string, mixed>  $options */
    private function importOne(array $options, ?Company $company = null): Mt940ImportOutcome
    {
        return app(Mt940Importer::class)->importMany($company ?? $this->company, [
            ['name' => 'izvod.txt', 'text' => Mt940Fixture::statement($options)],
        ])[0];
    }

    /** @param  list<array<string, mixed>>  $optionSets */
    private function importBatch(array $optionSets): array
    {
        return app(Mt940Importer::class)->importMany($this->company, array_map(
            fn (array $options, int $i) => ['name' => "izvod-{$i}.txt", 'text' => Mt940Fixture::statement($options)],
            $optionSets,
            array_keys($optionSets),
        ));
    }

    public function test_a_statement_is_created_with_its_lines_and_balances(): void
    {
        $outcome = $this->importOne([
            'bic' => 'KOBSMK2X', 'number' => 7, 'date' => '2026-03-04', 'opening' => '5000.00',
            'lines' => [
                ['dir' => 'out', 'amount' => '7800.00', 'party' => '100000000000009', 'name' => 'CARINSKA TEST', 'refs' => 'A1//B2', 'desc' => 'uplata po ECD'],
                ['dir' => 'in', 'amount' => '8000.00', 'party' => '100000000000008', 'name' => 'LICE TEST', 'desc' => 'pozajmica'],
            ],
        ]);

        $this->assertTrue($outcome->ok);
        $this->assertSame(2, $outcome->lines);

        $statement = BankStatement::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame($statement->id, $outcome->statementId);
        $this->assertSame(7, $statement->number);
        $this->assertSame('Комерцијална банка АД Скопје', $statement->bank);
        $this->assertSame(Mt940Fixture::ACCOUNT, $statement->account);
        $this->assertSame(BankStatementKind::DENAR, $statement->kind);
        $this->assertSame('2026-03-04', $statement->statement_date->toDateString());
        $this->assertSame('5000.00', $statement->opening_balance);
        $this->assertSame('5200.00', $statement->closing_balance);
        $this->assertSame(BankStatement::STATUS_DRAFT, $statement->status);
        $this->assertSame($this->admin->id, $statement->uploaded_by);

        $lines = $statement->lines;
        $this->assertCount(2, $lines);
        $this->assertSame(LineDirection::OUT, $lines[0]->direction);
        $this->assertSame('7800.00', $lines[0]->amount);
        $this->assertSame(LineKind::UNCLEAR, $lines[0]->kind);
        $this->assertNull($lines[0]->partner_id);
        $this->assertSame('100000000000009', $lines[0]->counterparty_account);
        $this->assertSame('CARINSKA TEST', $lines[0]->counterparty_name);
        $this->assertSame('A1//B2', $lines[0]->reference_number);
        $this->assertSame('uplata po ECD', $lines[0]->description);
        $this->assertSame(LineDirection::IN, $lines[1]->direction);
    }

    public function test_the_bank_name_from_the_company_profile_wins(): void
    {
        CompanyBankAccount::create([
            'company_id' => $this->company->id, 'bank_name' => 'Профилна банка', 'account_number' => Mt940Fixture::ACCOUNT,
        ]);

        $this->importOne(['bic' => 'KOBSMK2X']);

        $this->assertSame('Профилна банка', BankStatement::firstOrFail()->bank);
    }

    public function test_the_same_statement_cannot_be_imported_twice(): void
    {
        $this->importOne(['number' => 1]);
        $second = $this->importOne(['number' => 1]);

        $this->assertFalse($second->ok);
        $this->assertStringContainsString('веќе постои', $second->message);
        $this->assertSame(1, BankStatement::count());
    }

    public function test_another_companys_account_is_refused_and_nothing_is_created(): void
    {
        $other = Company::factory()->create();
        CompanyBankAccount::create([
            'company_id' => $other->id, 'bank_name' => 'Туѓа', 'account_number' => Mt940Fixture::ACCOUNT,
        ]);

        $outcome = $this->importOne(['number' => 1]);

        $this->assertFalse($outcome->ok);
        $this->assertStringContainsString('друга фирма', $outcome->message);
        $this->assertSame(0, BankStatement::count());
    }

    public function test_an_account_that_has_statements_in_another_company_is_refused(): void
    {
        BankStatement::factory()->create(['account' => Mt940Fixture::ACCOUNT, 'number' => 1]);

        $outcome = $this->importOne(['number' => 2]);

        $this->assertFalse($outcome->ok);
        $this->assertStringContainsString('друга фирма', $outcome->message);
    }

    public function test_an_account_missing_from_the_profile_is_a_warning(): void
    {
        $outcome = $this->importOne(['lines' => [['dir' => 'in', 'amount' => '10.00']]]);

        $this->assertTrue($outcome->ok);
        $this->assertTrue(collect($outcome->warnings)->contains(fn ($w) => str_contains($w, 'профилот')));
    }

    public function test_a_known_account_in_the_profile_gives_no_account_warning(): void
    {
        CompanyBankAccount::create([
            'company_id' => $this->company->id, 'bank_name' => 'Банка', 'account_number' => Mt940Fixture::ACCOUNT,
        ]);

        $outcome = $this->importOne(['lines' => [['dir' => 'in', 'amount' => '10.00']]]);

        $this->assertSame([], $outcome->warnings);
    }

    public function test_a_difference_in_the_balances_is_a_warning_not_a_refusal(): void
    {
        $outcome = $this->importOne([
            'opening' => '0.00', 'closing' => '50.00',
            'lines' => [['dir' => 'in', 'amount' => '100.00']],
        ]);

        $this->assertTrue($outcome->ok);
        $this->assertTrue(collect($outcome->warnings)->contains(fn ($w) => str_contains($w, 'разлика')));
    }

    public function test_a_broken_chain_is_a_warning_not_a_refusal(): void
    {
        $this->importOne(['number' => 1, 'opening' => '0.00', 'lines' => [['dir' => 'in', 'amount' => '100.00']]]);
        $second = $this->importOne([
            'number' => 2, 'date' => '2026-02-11', 'opening' => '90.00',
            'lines' => [['dir' => 'in', 'amount' => '10.00']],
        ]);

        $this->assertTrue($second->ok);
        $this->assertTrue(collect($second->warnings)->contains(fn ($w) => str_contains($w, 'не е еднаква')));
    }

    public function test_files_are_imported_in_date_order_whatever_the_upload_order(): void
    {
        $outcomes = $this->importBatch([
            ['number' => 3, 'date' => '2026-02-12', 'opening' => '150.00', 'lines' => [['dir' => 'in', 'amount' => '50.00']]],
            ['number' => 1, 'date' => '2026-02-02', 'opening' => '0.00', 'lines' => [['dir' => 'in', 'amount' => '100.00']]],
            ['number' => 2, 'date' => '2026-02-11', 'opening' => '100.00', 'lines' => [['dir' => 'in', 'amount' => '50.00']]],
        ]);

        $this->assertCount(3, $outcomes);
        foreach ($outcomes as $outcome) {
            $this->assertTrue($outcome->ok, $outcome->message);
            $this->assertFalse(collect($outcome->warnings)->contains(fn ($w) => str_contains($w, 'не е еднаква')), 'Лажен прекин на ланец.');
        }
        $this->assertSame(3, BankStatement::count());
    }

    public function test_one_bad_file_does_not_stop_the_rest(): void
    {
        $outcomes = $this->importBatch([
            ['currency' => 'EUR', 'number' => 1],
            ['number' => 2, 'date' => '2026-02-11'],
        ]);

        $this->assertSame([false, true], collect($outcomes)->sortBy(fn ($o) => $o->ok)->map->ok->values()->all());
        $this->assertSame(1, BankStatement::count());
        $this->assertStringContainsString('MKD', collect($outcomes)->firstWhere('ok', false)->message);
    }

    public function test_the_same_file_twice_in_one_batch_is_imported_once(): void
    {
        $outcomes = $this->importBatch([['number' => 1], ['number' => 1]]);

        $this->assertSame(1, collect($outcomes)->where('ok', true)->count());
        $this->assertSame(1, collect($outcomes)->where('ok', false)->count());
        $this->assertSame(1, BankStatement::count());
    }

    public function test_a_file_saved_by_windows_is_imported(): void
    {
        $outcomes = app(Mt940Importer::class)->importMany($this->company, [[
            'name' => 'win.txt',
            'text' => "\xEF\xBB\xBF".Mt940Fixture::statement(['eol' => "\r\n", 'lines' => [['dir' => 'in', 'amount' => '10.00']]]),
        ]]);

        $this->assertTrue($outcomes[0]->ok, $outcomes[0]->message);
        $this->assertSame(1, BankStatement::firstOrFail()->lines()->count());
    }
}
```

- [ ] **Step 2: Пушти го тестот и потврди дека паѓа**

Run: `php artisan test tests/Feature/Bank/Mt940ImporterTest.php`
Expected: FAIL — `Class "App\Services\Bank\Mt940Importer" not found`.

- [ ] **Step 3: Заедничка проверка на дупликат** — `app/Support/Bank/StatementSequence.php`, додај метод по `groups()`:

```php
    /** Дали за таа сметка и година веќе постои извод со тој број. */
    public static function numberTaken(int $companyId, string $account, int $number, int $year): bool
    {
        return BankStatement::where('company_id', $companyId)
            ->where('account', $account)
            ->where('number', $number)
            ->whereYear('statement_date', $year)
            ->exists();
    }
```

`app/Livewire/Bank/BankStatementIndex.php` — замени го приватниот `numberIsTaken` така што го користи заедничкиот метод:

```php
    private function numberIsTaken(int $number, string $account, string $date): bool
    {
        return StatementSequence::numberTaken($this->company->id, $account, $number, (int) substr($date, 0, 4));
    }
```

(`StatementSequence` и `BankStatement` се веќе увезени во датотеката; увозот на `BankStatement` останува потребен за `create()`/`render()`.)

- [ ] **Step 4: Именик на банки** — `app/Support/Bank/Mt940/BankDirectory.php`

```php
<?php

namespace App\Support\Bank\Mt940;

/** BIC → име на банка. Само она што е видено на вистински фајл. */
final class BankDirectory
{
    private const NAMES = [
        'KOBSMK2X' => 'Комерцијална банка АД Скопје',
    ];

    public static function name(?string $bic): ?string
    {
        return $bic === null ? null : (self::NAMES[$bic] ?? null);
    }
}
```

- [ ] **Step 5: Исход** — `app/Services/Bank/Mt940ImportOutcome.php`

```php
<?php

namespace App\Services\Bank;

/** Што се случило со еден фајл при увоз; се чува како низа во Livewire. */
final class Mt940ImportOutcome
{
    /** @param  list<string>  $warnings */
    private function __construct(
        public readonly bool $ok,
        public readonly string $file,
        public readonly string $message,
        public readonly array $warnings = [],
        public readonly ?int $statementId = null,
        public readonly int $lines = 0,
    ) {}

    /** @param  list<string>  $warnings */
    public static function created(string $file, string $message, int $statementId, int $lines, array $warnings): self
    {
        return new self(true, $file, $message, $warnings, $statementId, $lines);
    }

    public static function rejected(string $file, string $message): self
    {
        return new self(false, $file, $message);
    }

    /** @return array{ok: bool, file: string, message: string, warnings: list<string>, statement_id: ?int, lines: int} */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'file' => $this->file,
            'message' => $this->message,
            'warnings' => $this->warnings,
            'statement_id' => $this->statementId,
            'lines' => $this->lines,
        ];
    }
}
```

- [ ] **Step 6: Увозникот** — `app/Services/Bank/Mt940Importer.php`

```php
<?php

namespace App\Services\Bank;

use App\Models\BankStatement;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Services\DocumentStorage;
use App\Support\Bank\LineKind;
use App\Support\Bank\Mt940\BankDirectory;
use App\Support\Bank\Mt940\Mt940Exception;
use App\Support\Bank\Mt940\Mt940Parser;
use App\Support\Bank\Mt940\Mt940Statement;
use App\Support\Bank\StatementSequence;
use App\Support\BankStatementKind;
use App\Support\Format;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Од прочитан MT940 создава извод во нацрт со ставки. Ништо не книжи и ништо
 * не погодува: ставките се „неразјаснети“, без партнер — препознавањето е
 * следна фаза.
 */
class Mt940Importer
{
    /**
     * Фајловите се читаат сите најпрво и се увезуваат по сметка и датум,
     * зашто проверката на ланецот гледа претходниот извод.
     *
     * @param  list<array{name: string, text: string, file?: ?TemporaryUploadedFile}>  $items
     * @return list<Mt940ImportOutcome>
     */
    public function importMany(Company $company, array $items): array
    {
        $outcomes = [];
        $ready = [];

        foreach ($items as $item) {
            try {
                $ready[] = ['item' => $item, 'statement' => Mt940Parser::parse($item['text'])];
            } catch (Mt940Exception $e) {
                $outcomes[] = Mt940ImportOutcome::rejected($item['name'], $e->getMessage());
            }
        }

        usort($ready, fn (array $a, array $b) => [$a['statement']->account, $a['statement']->date, $a['statement']->number]
            <=> [$b['statement']->account, $b['statement']->date, $b['statement']->number]);

        foreach ($ready as $entry) {
            $outcomes[] = $this->import($company, $entry['statement'], $entry['item']['name'], $entry['item']['file'] ?? null);
        }

        return $outcomes;
    }

    public function import(Company $company, Mt940Statement $parsed, string $name, ?TemporaryUploadedFile $file = null): Mt940ImportOutcome
    {
        $year = (int) substr($parsed->date, 0, 4);

        $belongsElsewhere = CompanyBankAccount::where('account_number', $parsed->account)->where('company_id', '!=', $company->id)->exists()
            || BankStatement::where('account', $parsed->account)->where('company_id', '!=', $company->id)->exists();

        if ($belongsElsewhere) {
            return Mt940ImportOutcome::rejected($name, "Сметката {$parsed->account} е сметка на друга фирма — изводот не е увезен.");
        }

        if (StatementSequence::numberTaken($company->id, $parsed->account, $parsed->number, $year)) {
            return Mt940ImportOutcome::rejected($name, "Извод бр. {$parsed->number} за сметка {$parsed->account} во {$year} веќе постои.");
        }

        $configured = CompanyBankAccount::where('company_id', $company->id)->where('account_number', $parsed->account)->first();
        $bank = $configured?->bank_name ?: (BankDirectory::name($parsed->bic) ?? $parsed->bic ?? 'Непозната банка');

        $statement = DB::transaction(function () use ($company, $parsed, $bank) {
            $statement = BankStatement::create([
                'company_id' => $company->id,
                'bank' => $bank,
                'account' => $parsed->account,
                'kind' => BankStatementKind::DENAR,
                'number' => $parsed->number,
                'statement_date' => $parsed->date,
                'uploaded_by' => auth()->id(),
                'opening_balance' => $parsed->opening,
                'closing_balance' => $parsed->closing,
            ]);

            foreach ($parsed->lines as $position => $line) {
                $statement->lines()->create([
                    'position' => $position + 1,
                    'line_date' => $line->date,
                    'direction' => $line->direction,
                    'amount' => $line->amount,
                    'description' => $line->description === '' ? null : $line->description,
                    'reference_number' => $line->referenceNumber,
                    'counterparty_account' => $line->counterpartyAccount,
                    'counterparty_name' => $line->counterpartyName,
                    'kind' => LineKind::UNCLEAR,
                ]);
            }

            return $statement;
        });

        if ($file !== null) {
            try {
                DocumentStorage::store($statement, $file, 'Bank Statement');
            } catch (\Throwable $e) {
                // Извод без фајл е ред што не води никаде.
                report($e);
                $statement->delete();

                return Mt940ImportOutcome::rejected($name, 'Фајлот не можеше да се зачува, изводот не е увезен.');
            }
        }

        $lineCount = count($parsed->lines);

        return Mt940ImportOutcome::created(
            $name,
            "Извод бр. {$parsed->number} од ".Format::date($statement->statement_date).": {$lineCount} ставки.",
            $statement->id,
            $lineCount,
            $this->warnings($statement, $parsed, $configured !== null),
        );
    }

    /** @return list<string> */
    private function warnings(BankStatement $statement, Mt940Statement $parsed, bool $accountKnown): array
    {
        $warnings = [];

        if (! $accountKnown) {
            $warnings[] = "Сметката {$parsed->account} не е во профилот на фирмата — ќе се додаде при првото книжење.";
        }

        if ($parsed->lines === []) {
            $warnings[] = 'Изводот нема ставки.';
        }

        $difference = $parsed->difference();

        if (bccomp($difference, '0', 2) !== 0) {
            $warnings[] = 'Почетна состојба + движење не е еднакво на крајната (разлика '.Format::money($difference, '').').';
        }

        $previous = BankStatement::where('company_id', $statement->company_id)
            ->where('account', $statement->account)
            ->where('number', $statement->number - 1)
            ->whereYear('statement_date', $statement->statement_date->year)
            ->first();

        if ($previous !== null && $previous->closing_balance !== null
            && bccomp((string) $previous->closing_balance, $parsed->opening, 2) !== 0) {
            $warnings[] = 'Почетната состојба ('.Format::money($parsed->opening, '').') не е еднаква на крајната од извод '
                .$previous->number.' ('.Format::money((string) $previous->closing_balance, '').').';
        }

        return $warnings;
    }
}
```

- [ ] **Step 7: Пушти ги тестовите**

Run: `php artisan test tests/Feature/Bank/Mt940ImporterTest.php tests/Feature/Bank/BankStatementIndexTest.php`
Expected: PASS. (Индексниот тест го потврдува дека рефакторот на `numberIsTaken` не го скршил рачното качување.)

- [ ] **Step 8: Commit**

```bash
git add app/Support/Bank app/Services/Bank app/Livewire/Bank/BankStatementIndex.php tests/Feature/Bank/Mt940ImporterTest.php
git commit -m "$(cat <<'EOF'
MT940 importer: statements and lines in draft, warnings for chain and balances

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Копче „Увези MT940“ на екранот за изводи

**Files:**
- Modify: `app/Livewire/Bank/BankStatementIndex.php`
- Modify: `resources/views/livewire/bank/bank-statement-index.blade.php`
- Test: `tests/Feature/Bank/Mt940ImportScreenTest.php`

**Interfaces:**
- Consumes: `Mt940Importer::importMany(...)`, `Mt940ImportOutcome::toArray()` (Task 3).
- Produces: Livewire акција `importMt940Files`, јавни својства `array $mt940Files`, `array $importResults`.

- [ ] **Step 1: Напиши ги падачките тестови** — `tests/Feature/Bank/Mt940ImportScreenTest.php`

```php
<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementIndex;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Mt940Fixture;
use Tests\TestCase;

class Mt940ImportScreenTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
        Storage::fake('google');
        $this->company = Company::factory()->create();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function file(string $name, array $options = []): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, Mt940Fixture::statement($options));
    }

    public function test_two_files_are_imported_at_once_and_each_is_kept_as_a_document(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(BankStatementIndex::class, ['company' => $this->company])
            ->set('mt940Files', [
                $this->file('a.txt', ['number' => 1, 'lines' => [['dir' => 'in', 'amount' => '100.00']]]),
                $this->file('b.txt', ['number' => 2, 'date' => '2026-02-11', 'opening' => '100.00', 'lines' => [['dir' => 'out', 'amount' => '30.00']]]),
            ])
            ->call('importMt940Files')
            ->assertHasNoErrors()
            ->assertSee('Извод бр. 1')
            ->assertSee('Извод бр. 2');

        $this->assertSame(2, BankStatement::where('company_id', $this->company->id)->count());

        foreach (BankStatement::all() as $statement) {
            $document = $statement->documents()->firstOrFail();
            Storage::disk('google')->assertExists($document->path);
        }
    }

    public function test_a_refused_file_shows_its_reason_and_creates_nothing(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(BankStatementIndex::class, ['company' => $this->company])
            ->set('mt940Files', [$this->file('eur.txt', ['currency' => 'EUR'])])
            ->call('importMt940Files')
            ->assertSee('eur.txt')
            ->assertSee('MKD');

        $this->assertSame(0, BankStatement::count());
    }

    public function test_a_warning_is_shown_next_to_the_imported_statement(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(BankStatementIndex::class, ['company' => $this->company])
            ->set('mt940Files', [$this->file('a.txt', ['lines' => [['dir' => 'in', 'amount' => '10.00']]])])
            ->call('importMt940Files')
            ->assertSee('не е во профилот');
    }

    public function test_the_same_file_twice_in_one_upload_is_imported_once(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(BankStatementIndex::class, ['company' => $this->company])
            ->set('mt940Files', [$this->file('a.txt', ['number' => 1]), $this->file('a-copy.txt', ['number' => 1])])
            ->call('importMt940Files')
            ->assertSee('веќе постои');

        $this->assertSame(1, BankStatement::count());
    }

    public function test_a_wrong_file_type_is_a_validation_error(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(BankStatementIndex::class, ['company' => $this->company])
            ->set('mt940Files', [UploadedFile::fake()->create('izvod.pdf', 10)])
            ->call('importMt940Files')
            ->assertHasErrors('mt940Files.0');

        $this->assertSame(0, BankStatement::count());
    }

    public function test_no_file_is_a_validation_error(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(BankStatementIndex::class, ['company' => $this->company])
            ->call('importMt940Files')
            ->assertHasErrors('mt940Files');
    }

    public function test_an_internal_client_cannot_import_and_does_not_see_the_button(): void
    {
        $client = User::factory()->create(['company_id' => $this->company->id]);
        $client->assignRole('internal_client');

        $html = Livewire::actingAs($client)->test(BankStatementIndex::class, ['company' => $this->company])->html();
        $this->assertStringNotContainsString('Увези MT940', $html);

        Livewire::actingAs($client)->test(BankStatementIndex::class, ['company' => $this->company])
            ->set('mt940Files', [$this->file('a.txt')])
            ->call('importMt940Files')
            ->assertForbidden();

        $this->assertSame(0, BankStatement::count());
    }

    public function test_an_admin_sees_the_button(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(BankStatementIndex::class, ['company' => $this->company])->assertSee('Увези MT940');
    }
}
```

- [ ] **Step 2: Пушти го тестот и потврди дека паѓа**

Run: `php artisan test tests/Feature/Bank/Mt940ImportScreenTest.php`
Expected: FAIL — `Property [$mt940Files] not found on component`.

- [ ] **Step 3: Компонентата** — `app/Livewire/Bank/BankStatementIndex.php`

Додај увози:

```php
use App\Models\JournalEntry;
use App\Services\Bank\Mt940Importer;
```

Додај својства по `public $newFile = null;`:

```php
    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $mt940Files = [];

    /** @var array<int, array<string, mixed>> */
    public array $importResults = [];
```

Додај ја акцијата по `saveStatement()`:

```php
    /**
     * Увоз на MT940: ист праг како за книжење, зашто од фајлот се создаваат
     * ставки што сметководителот ги книжи.
     */
    public function importMt940Files(Mt940Importer $importer): void
    {
        Gate::authorize('view', $this->company);
        Gate::authorize('create', JournalEntry::class);

        $this->validate([
            'mt940Files' => ['required', 'array', 'min:1', 'max:60'],
            'mt940Files.*' => ['file', 'max:1024', 'extensions:txt,sta,mt940'],
        ], [
            'mt940Files.required' => 'Изберете барем една MT940 датотека.',
            'mt940Files.min' => 'Изберете барем една MT940 датотека.',
            'mt940Files.max' => 'Најмногу 60 датотеки одеднаш.',
            'mt940Files.*.max' => 'Датотеката не смее да биде поголема од 1 MB.',
            'mt940Files.*.extensions' => 'Датотеката мора да е .txt, .sta или .mt940.',
        ]);

        $items = collect($this->mt940Files)->map(fn ($file) => [
            'name' => basename($file->getClientOriginalName()),
            'text' => $file->get(),
            'file' => $file,
        ])->all();

        $this->importResults = array_map(
            fn ($outcome) => $outcome->toArray(),
            $importer->importMany($this->company, $items),
        );

        $this->reset('mt940Files');
    }
```

- [ ] **Step 4: Blade** — во `resources/views/livewire/bank/bank-statement-index.blade.php`, веднаш по `<h1>…</h1>` (пред `<x-card class="mb-6">` со „Прикачи извод“), додај:

```blade
    @if ($canBook)
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Увези MT940</h2>
            <form wire:submit="importMt940Files" class="flex flex-wrap gap-3 items-end">
                <div>
                    <x-input-label for="mt940Files" value="MT940 датотеки (може повеќе одеднаш)" />
                    <input type="file" id="mt940Files" wire:model="mt940Files" multiple accept=".txt,.sta,.mt940" class="text-sm">
                    @error('mt940Files') <span class="text-red-600 text-sm block">{{ $message }}</span> @enderror
                    @error('mt940Files.*') <span class="text-red-600 text-sm block">{{ $message }}</span> @enderror
                </div>
                <x-primary-button type="submit">Увези</x-primary-button>
            </form>

            @if ($importResults !== [])
                <ul class="mt-4 space-y-2 text-sm">
                    @foreach ($importResults as $result)
                        <li class="{{ $result['ok'] ? 'text-green-800' : 'text-red-700' }}">
                            <span class="font-medium">{{ $result['file'] }}</span> —
                            @if ($result['ok'])
                                {{ $result['message'] }}
                                <a href="{{ route('bank-statements.book', [$company, $result['statement_id']]) }}" class="text-brand hover:underline">Отвори</a>
                            @else
                                {{ $result['message'] }}
                            @endif
                            @foreach ($result['warnings'] as $warning)
                                <div class="ml-4 text-amber-700">⚠ {{ $warning }}</div>
                            @endforeach
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    @endif
```

- [ ] **Step 5: Пушти ги тестовите**

Run: `php artisan test tests/Feature/Bank/Mt940ImportScreenTest.php tests/Feature/Bank/BankStatementIndexTest.php tests/Unit/LivewireReservedMethodNamesTest.php`
Expected: PASS. Ако `$file->get()` фрли за `TemporaryUploadedFile`, замени со `file_get_contents($file->getRealPath())` и пушти пак.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Bank/BankStatementIndex.php resources/views/livewire/bank/bank-statement-index.blade.php tests/Feature/Bank/Mt940ImportScreenTest.php
git commit -m "$(cat <<'EOF'
Bank statements screen: import several MT940 files at once

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Командна проверка на папка

**Files:**
- Create: `app/Support/Bank/Mt940/Mt940FolderReport.php`
- Create: `app/Console/Commands/CheckMt940Folder.php`
- Test: `tests/Unit/Bank/Mt940FolderReportTest.php`
- Test: `tests/Feature/Bank/CheckMt940CommandTest.php`

**Interfaces:**
- Consumes: `Mt940Parser::parse`, `Mt940Exception` (Task 1).
- Produces: `Mt940FolderReport::analyse(array $files): array` со клучеви `files`, `statements`, `lines`, `refused` (`list<array{file, reason}>`), `duplicates` (`list<string>`), `accounts` (`array<string, array{first, last, count, gaps, chain_breaks, mismatches}>`); команда `bank:check-mt940 {folder}`.

- [ ] **Step 1: Падачки тестови**

`tests/Unit/Bank/Mt940FolderReportTest.php`:

```php
<?php

namespace Tests\Unit\Bank;

use App\Support\Bank\Mt940\Mt940FolderReport;
use Tests\Support\Mt940Fixture;
use Tests\TestCase;

class Mt940FolderReportTest extends TestCase
{
    private function in(string $amount): array
    {
        return [['dir' => 'in', 'amount' => $amount]];
    }

    public function test_a_clean_run_reports_no_problems(): void
    {
        $report = Mt940FolderReport::analyse([
            'a.txt' => Mt940Fixture::statement(['number' => 1, 'date' => '2026-02-02', 'opening' => '0.00', 'lines' => $this->in('100.00')]),
            'b.txt' => Mt940Fixture::statement(['number' => 2, 'date' => '2026-02-11', 'opening' => '100.00', 'lines' => $this->in('50.00')]),
        ]);

        $this->assertSame(2, $report['files']);
        $this->assertSame(2, $report['statements']);
        $this->assertSame(2, $report['lines']);
        $this->assertSame([], $report['refused']);
        $this->assertSame([], $report['duplicates']);

        $account = $report['accounts'][Mt940Fixture::ACCOUNT.'/2026'];
        $this->assertSame(1, $account['first']);
        $this->assertSame(2, $account['last']);
        $this->assertSame([], $account['gaps']);
        $this->assertSame([], $account['chain_breaks']);
        $this->assertSame([], $account['mismatches']);
    }

    public function test_a_missing_number_is_reported_and_is_not_also_a_chain_break(): void
    {
        $report = Mt940FolderReport::analyse([
            'a.txt' => Mt940Fixture::statement(['number' => 1, 'opening' => '0.00', 'lines' => $this->in('100.00')]),
            'c.txt' => Mt940Fixture::statement(['number' => 3, 'date' => '2026-02-12', 'opening' => '150.00', 'lines' => $this->in('10.00')]),
        ]);

        $account = $report['accounts'][Mt940Fixture::ACCOUNT.'/2026'];
        $this->assertSame(['фали 2'], $account['gaps']);
        $this->assertSame([], $account['chain_breaks']);
    }

    public function test_a_chain_break_and_a_mismatch_are_reported(): void
    {
        $report = Mt940FolderReport::analyse([
            'a.txt' => Mt940Fixture::statement(['number' => 1, 'opening' => '0.00', 'lines' => $this->in('100.00')]),
            'b.txt' => Mt940Fixture::statement(['number' => 2, 'date' => '2026-02-11', 'opening' => '90.00', 'closing' => '999.00', 'lines' => $this->in('10.00')]),
        ]);

        $account = $report['accounts'][Mt940Fixture::ACCOUNT.'/2026'];
        $this->assertCount(1, $account['chain_breaks']);
        $this->assertCount(1, $account['mismatches']);
    }

    public function test_a_refused_file_and_a_duplicate_number_are_listed(): void
    {
        $report = Mt940FolderReport::analyse([
            'a.txt' => Mt940Fixture::statement(['number' => 1]),
            'a-copy.txt' => Mt940Fixture::statement(['number' => 1]),
            'eur.txt' => Mt940Fixture::statement(['number' => 2, 'currency' => 'EUR']),
        ]);

        $this->assertSame('eur.txt', $report['refused'][0]['file']);
        $this->assertStringContainsString('MKD', $report['refused'][0]['reason']);
        $this->assertCount(1, $report['duplicates']);
        $this->assertSame(1, $report['statements']);
    }
}
```

`tests/Feature/Bank/CheckMt940CommandTest.php`:

```php
<?php

namespace Tests\Feature\Bank;

use App\Models\BankStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Mt940Fixture;
use Tests\TestCase;

class CheckMt940CommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mt940-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);

        parent::tearDown();
    }

    private function put(string $name, array $options): void
    {
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.$name, Mt940Fixture::statement($options));
    }

    public function test_a_clean_folder_succeeds_and_prints_a_summary(): void
    {
        $this->put('a.txt', ['number' => 1, 'lines' => [['dir' => 'in', 'amount' => '100.00']]]);
        $this->put('b.TXT', ['number' => 2, 'date' => '2026-02-11', 'opening' => '100.00', 'lines' => [['dir' => 'in', 'amount' => '5.00']]]);

        $this->artisan('bank:check-mt940', ['folder' => $this->dir])
            ->expectsOutputToContain('изводи: 2')
            ->expectsOutputToContain('ставки: 2')
            ->assertExitCode(0);
    }

    public function test_a_gap_makes_the_command_fail(): void
    {
        $this->put('a.txt', ['number' => 1]);
        $this->put('c.txt', ['number' => 3, 'date' => '2026-02-12']);

        $this->artisan('bank:check-mt940', ['folder' => $this->dir])
            ->expectsOutputToContain('фали 2')
            ->assertExitCode(1);
    }

    public function test_it_never_writes_to_the_database(): void
    {
        $this->put('a.txt', ['number' => 1]);

        $this->artisan('bank:check-mt940', ['folder' => $this->dir])->run();

        $this->assertSame(0, BankStatement::count());
    }

    public function test_a_missing_folder_fails(): void
    {
        $this->artisan('bank:check-mt940', ['folder' => $this->dir.'-nema'])->assertExitCode(1);
    }
}
```

- [ ] **Step 2: Пушти ги и потврди дека паѓаат**

Run: `php artisan test tests/Unit/Bank/Mt940FolderReportTest.php tests/Feature/Bank/CheckMt940CommandTest.php`
Expected: FAIL — класа/команда не постои.

- [ ] **Step 3: Извештајот** — `app/Support/Bank/Mt940/Mt940FolderReport.php`

```php
<?php

namespace App\Support\Bank\Mt940;

/**
 * Анализа на повеќе MT940 фајлови одеднаш: дупки во низата, прекини на
 * ланецот на состојби и изводи што не се совпаѓаат. Чиста логика, без база.
 */
final class Mt940FolderReport
{
    /**
     * @param  array<string, string>  $files  име на фајл => содржина
     * @return array{
     *     files: int, statements: int, lines: int,
     *     refused: list<array{file: string, reason: string}>,
     *     duplicates: list<string>,
     *     accounts: array<string, array{first: int, last: int, count: int, gaps: list<string>, chain_breaks: list<string>, mismatches: list<string>}>
     * }
     */
    public static function analyse(array $files): array
    {
        $refused = [];
        $duplicates = [];
        $groups = [];

        foreach ($files as $name => $text) {
            try {
                $statement = Mt940Parser::parse($text);
            } catch (Mt940Exception $e) {
                $refused[] = ['file' => (string) $name, 'reason' => $e->getMessage()];

                continue;
            }

            $key = $statement->account.'/'.substr($statement->date, 0, 4);

            if (isset($groups[$key][$statement->number])) {
                $duplicates[] = "{$key} извод {$statement->number}: {$groups[$key][$statement->number]['file']} и {$name}";

                continue;
            }

            $groups[$key][$statement->number] = ['file' => (string) $name, 'statement' => $statement];
        }

        ksort($groups);

        $accounts = [];
        $statements = 0;
        $lines = 0;

        foreach ($groups as $key => $byNumber) {
            ksort($byNumber);
            $gaps = [];
            $breaks = [];
            $mismatches = [];
            $previous = null;

            foreach ($byNumber as $number => $entry) {
                $statement = $entry['statement'];
                $statements++;
                $lines += count($statement->lines);

                if (bccomp($statement->difference(), '0', 2) !== 0) {
                    $mismatches[] = "извод {$number}: разлика {$statement->difference()}";
                }

                if ($previous !== null) {
                    if ($number > $previous->number + 1) {
                        $gaps[] = $number === $previous->number + 2
                            ? 'фали '.($previous->number + 1)
                            : 'фалат '.($previous->number + 1).'–'.($number - 1);
                    } elseif (bccomp($statement->opening, $previous->closing, 2) !== 0) {
                        $breaks[] = "извод {$number}: почетна {$statement->opening}, крајна на {$previous->number} е {$previous->closing}";
                    }
                }

                $previous = $statement;
            }

            $numbers = array_keys($byNumber);
            $accounts[$key] = [
                'first' => min($numbers),
                'last' => max($numbers),
                'count' => count($numbers),
                'gaps' => $gaps,
                'chain_breaks' => $breaks,
                'mismatches' => $mismatches,
            ];
        }

        return [
            'files' => count($files),
            'statements' => $statements,
            'lines' => $lines,
            'refused' => $refused,
            'duplicates' => $duplicates,
            'accounts' => $accounts,
        ];
    }
}
```

- [ ] **Step 4: Командата** — `app/Console/Commands/CheckMt940Folder.php`

```php
<?php

namespace App\Console\Commands;

use App\Support\Bank\Mt940\Mt940FolderReport;
use Illuminate\Console\Command;

/**
 * Ги чита сите MT940 фајлови од папка и пријавува што не е во ред. Не пристапува
 * до базата — вистинските фајлови се проверуваат на машината, без да влезат во
 * репото или во апликацијата.
 */
class CheckMt940Folder extends Command
{
    protected $signature = 'bank:check-mt940 {folder : Папка со MT940 фајлови (.txt, .sta, .mt940)}';

    protected $description = 'Проверува папка со MT940 фајлови: дупки, прекини на ланец и разлики, без запишување';

    public function handle(): int
    {
        $folder = (string) $this->argument('folder');

        if (! is_dir($folder)) {
            $this->error("Папката не постои: {$folder}");

            return self::FAILURE;
        }

        $files = [];

        foreach (scandir($folder) ?: [] as $name) {
            if (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['txt', 'sta', 'mt940'], true)) {
                $files[$name] = (string) file_get_contents($folder.DIRECTORY_SEPARATOR.$name);
            }
        }

        if ($files === []) {
            $this->error('Нема MT940 фајлови во папката.');

            return self::FAILURE;
        }

        $report = Mt940FolderReport::analyse($files);
        $problems = $report['refused'] !== [] || $report['duplicates'] !== [];

        $this->line("Фајлови: {$report['files']}, изводи: {$report['statements']}, ставки: {$report['lines']}");

        foreach ($report['accounts'] as $key => $account) {
            $this->line(sprintf(
                'Сметка %s: изводи %d–%d (%d); дупки: %s; прекини: %s; разлики: %s',
                $key,
                $account['first'],
                $account['last'],
                $account['count'],
                $account['gaps'] === [] ? 'нема' : implode('; ', $account['gaps']),
                $account['chain_breaks'] === [] ? 'нема' : implode('; ', $account['chain_breaks']),
                $account['mismatches'] === [] ? 'нема' : implode('; ', $account['mismatches']),
            ));

            $problems = $problems || $account['gaps'] !== [] || $account['chain_breaks'] !== [] || $account['mismatches'] !== [];
        }

        foreach ($report['refused'] as $refused) {
            $this->warn("Одбиен {$refused['file']}: {$refused['reason']}");
        }

        foreach ($report['duplicates'] as $duplicate) {
            $this->warn("Дупликат: {$duplicate}");
        }

        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
```

Командите во `app/Console/Commands` се автоматски регистрирани (како `MoveBankPaymentsTo1000`); ако не се, провери `bootstrap/app.php`/`routes/console.php` како е регистрирана постојната.

- [ ] **Step 5: Пушти ги тестовите**

Run: `php artisan test tests/Unit/Bank/Mt940FolderReportTest.php tests/Feature/Bank/CheckMt940CommandTest.php`
Expected: PASS.

- [ ] **Step 6: Проба на вистинските фајлови (само читање)**

Run: `php artisan bank:check-mt940 "C:\Users\FinanceBuddy.mk\Documents\Claude sessions\mt940"`
Expected: `Фајлови: 71, изводи: 71, ставки: 102` и една линија за сметката со `изводи 1–71 (71); дупки: нема; прекини: нема; разлики: нема`, exit 0. Ако нешто друго — парсерот не ги чита вистинските фајлови како што мислиме; стани и истражи пред да продолжиш.

- [ ] **Step 7: Commit**

```bash
git add app/Support/Bank/Mt940/Mt940FolderReport.php app/Console/Commands/CheckMt940Folder.php tests/Unit/Bank/Mt940FolderReportTest.php tests/Feature/Bank/CheckMt940CommandTest.php
git commit -m "$(cat <<'EOF'
bank:check-mt940: check a folder of statements for gaps, chain breaks and mismatches

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Заштита од вистински податоци, цела серија, спојување

**Files:**
- Test: `tests/Unit/NoRealBankDataInRepoTest.php`

**Interfaces:**
- Consumes: сè од претходните задачи.

- [ ] **Step 1: Заштитен тест** — `tests/Unit/NoRealBankDataInRepoTest.php`

Тестот не чува никаков список на вистински броеви (ни хешови: SHA-1 од 15-цифрен број се враќа за секунди). Македонска домашна сметка е 15 цифри со контролни цифри mod 97-10; секој таков број што ја поминува контролата во `tests/` или `docs/` е вистинска сметка, а измислените фикстури (`100000000000001`…) намерно не ја поминуваат.

```php
<?php

namespace Tests\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Вистинските банкарски изводи не влегуваат во репото ни како фикстури ни во
 * документација. Македонска домашна сметка е 15 цифри: 3 на банката, 10 на
 * сметката и 2 контролни (ISO 7064 mod 97-10). Секој таков број што ја
 * поминува контролата е вистинска сметка — измислените фикстури намерно не ја
 * поминуваат. Така нема список со вистински броеви (ни хешови од нив: на
 * толку мал простор хешот се враќа за секунди).
 */
class NoRealBankDataInRepoTest extends TestCase
{
    public function test_no_test_or_document_contains_a_valid_looking_account_number(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach ([base_path('tests'), base_path('docs')] as $root) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (! in_array($file->getExtension(), ['php', 'md', 'txt', 'json', 'xml', 'sta', 'mt940'], true)) {
                    continue;
                }

                $scanned++;
                $text = (string) file_get_contents($file->getPathname());

                preg_match_all('/(?<!\d)\d{15}(?!\d)/', $text, $plain);
                preg_match_all('/(?<!\d)(\d{3})-(\d{10})-(\d{2})(?!\d)/', $text, $dashed, PREG_SET_ORDER);

                $candidates = array_merge($plain[0], array_map(fn (array $m) => $m[1].$m[2].$m[3], $dashed));

                foreach ($candidates as $candidate) {
                    if (self::looksLikeARealAccount($candidate)) {
                        $offenders[] = $file->getPathname();
                    }
                }
            }
        }

        $this->assertGreaterThan(50, $scanned, 'Скенирањето не нашло датотеки.');
        $this->assertSame([], array_values(array_unique($offenders)), 'Број што личи на вистинска сметка: '.implode(', ', array_unique($offenders)));
    }

    public function test_the_check_accepts_a_constructed_valid_account_and_rejects_the_fixture_account(): void
    {
        $body = '3000000012345';
        $check = str_pad((string) (98 - ((int) bcmod($body.'00', '97'))), 2, '0', STR_PAD_LEFT);

        $this->assertTrue(self::looksLikeARealAccount($body.$check));
        $this->assertFalse(self::looksLikeARealAccount('100000000000001'));
    }

    private static function looksLikeARealAccount(string $digits): bool
    {
        if (preg_match('/^\d{15}$/', $digits) !== 1) {
            return false;
        }

        $check = 98 - (int) bcmod(substr($digits, 0, 13).'00', '97');

        return str_pad((string) $check, 2, '0', STR_PAD_LEFT) === substr($digits, 13, 2);
    }
}
```

- [ ] **Step 2: Пушти ги сите тестови за банка + заштитата**

Run: `php artisan test tests/Unit/Bank tests/Feature/Bank tests/Unit/LivewireReservedMethodNamesTest.php tests/Unit/NoRealBankDataInRepoTest.php`
Expected: PASS. Ако заштитата падне, наведената датотека содржи вистинска сметка — замени ја со измислена (`100000000000001`…) пред да продолжиш.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/NoRealBankDataInRepoTest.php
git commit -m "$(cat <<'EOF'
Guard: no real bank account number may appear in tests or docs

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 4: Цела серија (еднаш)**

Run: `php artisan test`
Expected: сите поминуваат (основа пред оваа гранка: 2425 поминати/3 прескокнати + новите). ~8,5 мин; не менувај датотеки додека трча.

- [ ] **Step 5: Спој и пушти**

```bash
git switch main && git merge --no-ff mt940-import -m "Merge MT940 import phase 1 (parser, import screen, folder check)" && git push origin main
```

Потоа следи го CI на `main` (`gh run watch` или `gh run list --branch main --limit 1`): и `test` и `deploy` мора да се `success` пред да се нарече живо. Миграцијата ја извршува deploy-от сам.

- [ ] **Step 6: Белешка за следниот план**

Допиши во `docs/superpowers/2026-10-13-mt940-import-log.md` што е спојено (комит, број на тестови, CI), што е отворено (проба на сервер со вистински фајл; прво нека сопственикот ја пушти `bank:check-mt940` локално) и што следи (план за фаза 2: „проверка една по една“). Комитирај.
