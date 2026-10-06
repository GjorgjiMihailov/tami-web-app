# Шеми за книжење, дел 1: мотор + излезна фактура + нејзината уплата — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Книжењето на излезна фактура и на уплата по неа да оди преку шеми (податоци), со стандардни шеми, формули и подетални конта, без да се скрши ниедна контрола што денес постои.

**Architecture:** Три нови табели (`posting_schemes`, `posting_scheme_rows`, `posting_scheme_matrix_accounts`). Чист `FormulaEvaluator` (+ − × над именувани променливи), `PostingContext` (износи по документ и по кришки вид×даночна група), чист `PostingSchemeEngine` што од шема и контекст враќа ставки и гарантира баланс, `DefaultPostingSchemes` (+ `PostingSchemes::for()` што ги создава лениво). `SalesInvoiceService::confirm` и `recordPayment` ги користат наместо напишаните конта; `BankStatementPoster` ја зема сметката на побарувањето од самата фактура.

**Tech Stack:** Laravel 13, PHP 8.3, bcmath (низи), SQLite (тест) / MySQL (продукција), PHPUnit.

Спецификација: `docs/superpowers/specs/2026-10-06-posting-schemes-design.md` (прочитај ја прво). Овој план ја менува на пет места — види „Измени на спецификацијата“ на крај.

## Global Constraints

- Македонски текстови за корисник (строг македонски); коментари во кодот — македонски.
- Пари: **bcmath низи**, `Bcmath::roundHalfUp($v, 2)` за заокружување; никогаш float.
- Книжење **само на аналитички конта** (`accounts.is_analytical = true`).
- Знаци/текстови што веќе постојат остануваат: налогот е во група 99 („Автоматски (фактури)“), опис на налог `Sales Invoice {број}`, описи на редови `Invoice {број}`, `VAT on Invoice {број}`, `COGS for Invoice {број}`, `Payment for invoice {број}` — бидејќи `changeNumber` ги преименува со `str_replace('Invoice '.$old, …)`.
- Девизна фактура: ВКУПНО се конвертира **еднаш** (`toMkd`), ДДВ се конвертира, последната кришка го апсорбира остатокот; побарувањето мора да се затвори на **точно 0** при уплати (телескопирање, како денес).
- Eloquent: `$attributes` се поставува во моделот за секоја колона со DB-default.
- MySQL: имиња на индекси ≤ 64 знаци (зададувај кратки имиња).
- Не ги допирај: `PurchaseInvoiceService`, плата, `recordPayment` на влезна фактура, `OfficialChartOfAccounts`.
- Тестови: `php artisan test <датотека>` само за датотеката што ја менуваш. **Целата серија се пушта еднаш на крај и прво се прашува корисникот** (~14 мин). Не користи `python` (нема) и не користи `sed` за додавање `use` редови (ги јаде обратните коси црти) — користи Edit.
- Кон секој commit: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Работи на гранка `posting-schemes-part1`, не на `main`.

## Мапа на датотеки

Нови:
- `database/migrations/2026_10_07_100000_create_posting_schemes_tables.php`
- `app/Support/Posting/{PostingDocType,ItemKind,VatGroup,PostingMatrix}.php`, `PostingSlice.php`, `PostingContext.php`, `PostingLine.php`, `FormulaEvaluator.php`
- `app/Exceptions/{PostingFormulaException,PostingSchemeException}.php`
- `app/Models/{PostingScheme,PostingSchemeRow,PostingSchemeMatrixAccount}.php`
- `app/Services/Posting/{PostingSchemeEngine,DefaultPostingSchemes,PostingSchemes,SalesInvoicePostingContext,SalesPaymentPostingContext,PostedInvoiceAccounts}.php`
- `tests/Unit/{FormulaEvaluatorTest,VatGroupTest}.php`, `tests/Feature/Posting/{PostingSchemeModelsTest,SalesInvoicePostingContextTest,PostingSchemeEngineTest,DefaultPostingSchemesTest,SalesInvoiceSchemePostingTest,SalesPaymentSchemePostingTest}.php`

Менувани: `app/Services/Invoicing/SalesInvoiceService.php`, `app/Services/Bank/BankStatementPoster.php`, постојните тестови што бараат стари конта (`tests/Unit/SalesInvoiceServiceTest.php`, `tests/Feature/ForeignCurrencyInvoiceTest.php`, `tests/Feature/Bank/BankStatementPosterTest.php`).

---

### Task 1: Енуми, шема и модели

**Files:**
- Create: `app/Support/Posting/PostingDocType.php`, `ItemKind.php`, `VatGroup.php`, `PostingMatrix.php`
- Create: `database/migrations/2026_10_07_100000_create_posting_schemes_tables.php`
- Create: `app/Models/PostingScheme.php`, `PostingSchemeRow.php`, `PostingSchemeMatrixAccount.php`
- Test: `tests/Unit/VatGroupTest.php`, `tests/Feature/Posting/PostingSchemeModelsTest.php`

**Interfaces:**
- Produces: `PostingDocType::{SALES_INVOICE,SALES_PAYMENT,PURCHASE_INVOICE,PURCHASE_PAYMENT}` (`'sales_invoice'`…), `ItemKind::{GOODS,SERVICE}`, `VatGroup::{GENERAL,REDUCED,EXEMPT_WITH_CREDIT,EXEMPT_WITHOUT_CREDIT,EXPORT}` со `VatGroup::forLine(string $treatment, string $rate): VatGroup`, `PostingMatrix::REVENUE` (`'revenue'`, димензија вид×група) и `PostingMatrix::OUTPUT_VAT` (`'output_vat'`, димензија група) со `PostingMatrix::byKind(string $key): bool`.
- Produces: `PostingScheme` (`company_id, doc_type (PostingDocType), name`; `rows()` по `position`; `matrixAccounts()`), `PostingSchemeRow` (`posting_scheme_id, position, account_mode ('fixed'|'matrix'|'invoice'), account_id, matrix_key, side ('debit'|'credit'), formula, with_partner (bool, default false), description, condition; `account()`), `PostingSchemeMatrixAccount` (`posting_scheme_id, matrix_key, item_kind (nullable), vat_group, account_id; `account()`).

- [ ] **Step 1: Write the failing tests**

`tests/Unit/VatGroupTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\Posting\VatGroup;
use PHPUnit\Framework\TestCase;

class VatGroupTest extends TestCase
{
    public function test_a_line_falls_in_a_group_by_treatment_and_rate(): void
    {
        $this->assertSame(VatGroup::GENERAL, VatGroup::forLine('standard', '18.00'));
        $this->assertSame(VatGroup::GENERAL, VatGroup::forLine('standard', '0.00'));
        $this->assertSame(VatGroup::REDUCED, VatGroup::forLine('standard', '5.00'));
        $this->assertSame(VatGroup::REDUCED, VatGroup::forLine('standard', '10'));
        $this->assertSame(VatGroup::EXPORT, VatGroup::forLine('export', '0.00'));
        $this->assertSame(VatGroup::EXEMPT_WITH_CREDIT, VatGroup::forLine('exempt_with_credit', '0.00'));
        $this->assertSame(VatGroup::EXEMPT_WITHOUT_CREDIT, VatGroup::forLine('exempt_without_credit', '0.00'));
    }
}
```

`tests/Feature/Posting/PostingSchemeModelsTest.php`:

```php
<?php

namespace Tests\Feature\Posting;

use App\Models\Account;
use App\Models\Company;
use App\Models\PostingScheme;
use App\Models\PostingSchemeMatrixAccount;
use App\Models\PostingSchemeRow;
use App\Support\Posting\PostingDocType;
use App\Support\Posting\PostingMatrix;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostingSchemeModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_scheme_has_ordered_rows_and_matrix_accounts(): void
    {
        $company = Company::factory()->create();
        $scheme = PostingScheme::create(['company_id' => $company->id, 'doc_type' => PostingDocType::SALES_INVOICE, 'name' => 'Излезна фактура']);
        $bank = Account::where('company_id', $company->id)->where('code', '1000')->firstOrFail();
        PostingSchemeRow::create(['posting_scheme_id' => $scheme->id, 'position' => 2, 'account_mode' => 'fixed', 'account_id' => $bank->id, 'side' => 'credit', 'formula' => 'ВКУПНО']);
        PostingSchemeRow::create(['posting_scheme_id' => $scheme->id, 'position' => 1, 'account_mode' => 'fixed', 'account_id' => $bank->id, 'side' => 'debit', 'formula' => 'ВКУПНО']);
        PostingSchemeMatrixAccount::create(['posting_scheme_id' => $scheme->id, 'matrix_key' => PostingMatrix::OUTPUT_VAT, 'item_kind' => null, 'vat_group' => 'general', 'account_id' => $bank->id]);

        $fresh = $scheme->fresh();

        $this->assertSame(PostingDocType::SALES_INVOICE, $fresh->doc_type);
        $this->assertSame(['debit', 'credit'], $fresh->rows->pluck('side')->all());
        $this->assertCount(1, $fresh->matrixAccounts);
        $this->assertSame('1000', $fresh->rows->first()->account->code);
    }

    public function test_a_row_defaults_to_no_partner(): void
    {
        $company = Company::factory()->create();
        $scheme = PostingScheme::create(['company_id' => $company->id, 'doc_type' => PostingDocType::SALES_PAYMENT, 'name' => 'x']);
        $row = PostingSchemeRow::create(['posting_scheme_id' => $scheme->id, 'position' => 1, 'account_mode' => 'invoice', 'side' => 'credit', 'formula' => 'ИЗНОС']);

        $this->assertFalse($row->with_partner);
        $this->assertFalse($row->fresh()->with_partner);
    }

    public function test_a_company_has_one_scheme_per_document_type(): void
    {
        $company = Company::factory()->create();
        PostingScheme::create(['company_id' => $company->id, 'doc_type' => PostingDocType::SALES_INVOICE, 'name' => 'a']);

        $this->expectException(QueryException::class);

        PostingScheme::create(['company_id' => $company->id, 'doc_type' => PostingDocType::SALES_INVOICE, 'name' => 'b']);
    }

    public function test_matrix_dimensions(): void
    {
        $this->assertTrue(PostingMatrix::byKind(PostingMatrix::REVENUE));
        $this->assertFalse(PostingMatrix::byKind(PostingMatrix::OUTPUT_VAT));
    }
}
```

- [ ] **Step 2: Run — FAIL.** `php artisan test tests/Unit/VatGroupTest.php tests/Feature/Posting/PostingSchemeModelsTest.php`

- [ ] **Step 3: Implement**

`app/Support/Posting/PostingDocType.php`:

```php
<?php

namespace App\Support\Posting;

enum PostingDocType: string
{
    case SALES_INVOICE = 'sales_invoice';
    case SALES_PAYMENT = 'sales_payment';
    case PURCHASE_INVOICE = 'purchase_invoice';
    case PURCHASE_PAYMENT = 'purchase_payment';

    public function label(): string
    {
        return match ($this) {
            self::SALES_INVOICE => 'Излезна фактура',
            self::SALES_PAYMENT => 'Уплата од купувач',
            self::PURCHASE_INVOICE => 'Влезна фактура',
            self::PURCHASE_PAYMENT => 'Исплата кон добавувач',
        };
    }
}
```

`ItemKind.php`:

```php
<?php

namespace App\Support\Posting;

enum ItemKind: string
{
    case GOODS = 'goods';
    case SERVICE = 'service';

    public function label(): string
    {
        return $this === self::GOODS ? 'Стока' : 'Услуга';
    }
}
```

`VatGroup.php`:

```php
<?php

namespace App\Support\Posting;

/** Даночна група на ставка: одредува на кое конто оди приходот и ДДВ-то. */
enum VatGroup: string
{
    case GENERAL = 'general';
    case REDUCED = 'reduced';
    case EXEMPT_WITH_CREDIT = 'exempt_with_credit';
    case EXEMPT_WITHOUT_CREDIT = 'exempt_without_credit';
    case EXPORT = 'export';

    public function label(): string
    {
        return match ($this) {
            self::GENERAL => 'Општа стапка (18%)',
            self::REDUCED => 'Повластена стапка (5%/10%)',
            self::EXEMPT_WITH_CREDIT => 'Ослободено со право на одбивка',
            self::EXEMPT_WITHOUT_CREDIT => 'Ослободено без право на одбивка',
            self::EXPORT => 'Извоз',
        };
    }

    /** Третманот на ставката (`vat_treatment`) и стапката ја одредуваат групата. */
    public static function forLine(string $treatment, string $rate): self
    {
        return match ($treatment) {
            'export' => self::EXPORT,
            'exempt_with_credit' => self::EXEMPT_WITH_CREDIT,
            'exempt_without_credit' => self::EXEMPT_WITHOUT_CREDIT,
            default => (bccomp($rate, '5', 2) === 0 || bccomp($rate, '10', 2) === 0) ? self::REDUCED : self::GENERAL,
        };
    }
}
```

`PostingMatrix.php`:

```php
<?php

namespace App\Support\Posting;

/** Матрици: табели што одредуваат конто од вид на ставка и/или даночна група. */
final class PostingMatrix
{
    /** Приход: конто по вид (стока/услуга) И даночна група. */
    public const REVENUE = 'revenue';

    /** Излезен ДДВ: конто само по даночна група. */
    public const OUTPUT_VAT = 'output_vat';

    /** Дали матрицата се чита по вид и група (true) или само по група (false). */
    public static function byKind(string $key): bool
    {
        return $key === self::REVENUE;
    }
}
```

Миграција `database/migrations/2026_10_07_100000_create_posting_schemes_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posting_schemes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('doc_type', 24);
            $table->string('name');
            $table->timestamps();

            $table->unique(['company_id', 'doc_type'], 'posting_schemes_company_type_unique');
        });

        Schema::create('posting_scheme_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('posting_scheme_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('account_mode', 12);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('matrix_key', 24)->nullable();
            $table->string('side', 6);
            $table->string('formula');
            $table->boolean('with_partner')->default(false);
            $table->string('description')->nullable();
            $table->string('condition', 16)->nullable();
            $table->timestamps();
        });

        Schema::create('posting_scheme_matrix_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('posting_scheme_id')->constrained()->cascadeOnDelete();
            $table->string('matrix_key', 24);
            $table->string('item_kind', 8)->nullable();
            $table->string('vat_group', 24);
            $table->foreignId('account_id')->constrained('accounts');
            $table->timestamps();

            $table->unique(['posting_scheme_id', 'matrix_key', 'item_kind', 'vat_group'], 'psma_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posting_scheme_matrix_accounts');
        Schema::dropIfExists('posting_scheme_rows');
        Schema::dropIfExists('posting_schemes');
    }
};
```

Модели:

```php
<?php

namespace App\Models;

use App\Support\Posting\PostingDocType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Шема за книжење на еден вид документ, за една фирма. */
class PostingScheme extends Model
{
    protected $fillable = ['company_id', 'doc_type', 'name'];

    protected function casts(): array
    {
        return ['doc_type' => PostingDocType::class];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(PostingSchemeRow::class)->orderBy('position')->orderBy('id');
    }

    public function matrixAccounts(): HasMany
    {
        return $this->hasMany(PostingSchemeMatrixAccount::class);
    }
}
```

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostingSchemeRow extends Model
{
    protected $fillable = [
        'posting_scheme_id', 'position', 'account_mode', 'account_id', 'matrix_key',
        'side', 'formula', 'with_partner', 'description', 'condition',
    ];

    // DB-default не го полни свеж модел во меморија.
    protected $attributes = ['with_partner' => false];

    protected function casts(): array
    {
        return ['with_partner' => 'boolean'];
    }

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(PostingScheme::class, 'posting_scheme_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
```

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostingSchemeMatrixAccount extends Model
{
    protected $fillable = ['posting_scheme_id', 'matrix_key', 'item_kind', 'vat_group', 'account_id'];

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(PostingScheme::class, 'posting_scheme_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
```

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Unit/VatGroupTest.php tests/Feature/Posting/PostingSchemeModelsTest.php`

- [ ] **Step 5: Commit**

```bash
git checkout -b posting-schemes-part1
git add app database tests
git commit -m "Posting schemes: enums, tables and models"
```

---

### Task 2: Парсер на формули

**Files:**
- Create: `app/Exceptions/PostingFormulaException.php`, `app/Support/Posting/FormulaEvaluator.php`
- Test: `tests/Unit/FormulaEvaluatorTest.php`

**Interfaces:**
- Produces: `FormulaEvaluator::evaluate(string $formula, array $variables): string` (износ со 2 децимали, half-up) и `FormulaEvaluator::variablesIn(string $formula): array<int,string>`. Поддржува броеви, имиња (букви вкл. кирилица, `_`, цифри), `+ − *`, загради, унарен минус. Фрла `PostingFormulaException` (македонска порака) за непозната променлива или лош синтаксис. Нема делење.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Exceptions\PostingFormulaException;
use App\Support\Posting\FormulaEvaluator;
use PHPUnit\Framework\TestCase;

class FormulaEvaluatorTest extends TestCase
{
    public function test_arithmetic_with_precedence_and_parentheses(): void
    {
        $this->assertSame('7.00', FormulaEvaluator::evaluate('1 + 2 * 3', []));
        $this->assertSame('9.00', FormulaEvaluator::evaluate('(1 + 2) * 3', []));
        $this->assertSame('-4.50', FormulaEvaluator::evaluate('-(1 + 3.5)', []));
        $this->assertSame('0.30', FormulaEvaluator::evaluate('0.1 + 0.2', []));
    }

    public function test_variables_including_cyrillic_names(): void
    {
        $vars = ['ВКУПНО' => '118.00', 'ДДВ' => '18.00', 'НАБАВНА_ВРЕДНОСТ' => '40.00', 'A' => '2'];

        $this->assertSame('100.00', FormulaEvaluator::evaluate('ВКУПНО - ДДВ', $vars));
        $this->assertSame('-18.00', FormulaEvaluator::evaluate('-ДДВ', $vars));
        $this->assertSame('80.00', FormulaEvaluator::evaluate('НАБАВНА_ВРЕДНОСТ * A', $vars));
        $this->assertSame('36.00', FormulaEvaluator::evaluate('ДДВ*A', $vars));
    }

    public function test_the_result_is_rounded_half_up_to_two_decimals(): void
    {
        $this->assertSame('0.34', FormulaEvaluator::evaluate('0.335', []));
        $this->assertSame('0.33', FormulaEvaluator::evaluate('0.334', []));
    }

    public function test_an_unknown_variable_is_refused(): void
    {
        $this->expectException(PostingFormulaException::class);
        $this->expectExceptionMessage('Непозната променлива „ЦАРИНА“');

        FormulaEvaluator::evaluate('ВКУПНО - ЦАРИНА', ['ВКУПНО' => '1.00']);
    }

    public function test_bad_syntax_is_refused(): void
    {
        foreach (['1 +', '(1 + 2', '1 / 2', '1 2', '* 3', ''] as $formula) {
            try {
                FormulaEvaluator::evaluate($formula, []);
                $this->fail("Очекуван исклучок за „{$formula}“.");
            } catch (PostingFormulaException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_the_variables_used_by_a_formula_can_be_listed(): void
    {
        $this->assertSame(['ВКУПНО', 'ДДВ'], FormulaEvaluator::variablesIn('ВКУПНО - ДДВ * 2 + ВКУПНО'));
        $this->assertSame([], FormulaEvaluator::variablesIn('1 + 2'));
    }
}
```

- [ ] **Step 2: Run — FAIL.** `php artisan test tests/Unit/FormulaEvaluatorTest.php`

- [ ] **Step 3: Implement**

`app/Exceptions/PostingFormulaException.php`:

```php
<?php

namespace App\Exceptions;

class PostingFormulaException extends \RuntimeException {}
```

`app/Support/Posting/FormulaEvaluator.php`:

```php
<?php

namespace App\Support\Posting;

use App\Exceptions\PostingFormulaException;
use App\Support\Bcmath;

/**
 * Чист пресметувач на формули од шемите: броеви, именувани променливи,
 * + − * и загради. Нема eval и нема делење — формулата не може да направи
 * ништо друго освен аритметика над променливите што ги добива.
 */
final class FormulaEvaluator
{
    private const SCALE = 10;

    /** @var list<array{0: string, 1: string}> */
    private array $tokens;

    private int $pos = 0;

    /** @param array<string, string> $variables */
    private function __construct(private readonly string $formula, private readonly array $variables)
    {
        $this->tokens = self::tokenize($formula);
    }

    /** @param array<string, string> $variables */
    public static function evaluate(string $formula, array $variables): string
    {
        $evaluator = new self($formula, $variables);

        if ($evaluator->tokens === []) {
            throw new PostingFormulaException('Формулата е празна.');
        }

        $result = $evaluator->expression();

        if ($evaluator->pos < count($evaluator->tokens)) {
            throw $evaluator->syntax();
        }

        return Bcmath::roundHalfUp($result, 2);
    }

    /** @return list<string> */
    public static function variablesIn(string $formula): array
    {
        $names = [];

        foreach (self::tokenize($formula) as [$type, $value]) {
            if ($type === 'var' && ! in_array($value, $names, true)) {
                $names[] = $value;
            }
        }

        return $names;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function tokenize(string $formula): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($formula);

        while ($offset < $length) {
            if (preg_match('/\G\s+/u', $formula, $m, 0, $offset)) {
                $offset += strlen($m[0]);

                continue;
            }

            if (preg_match('/\G\d+(?:\.\d+)?/u', $formula, $m, 0, $offset)) {
                $tokens[] = ['num', $m[0]];
            } elseif (preg_match('/\G[\p{L}_][\p{L}\p{N}_]*/u', $formula, $m, 0, $offset)) {
                $tokens[] = ['var', $m[0]];
            } elseif (preg_match('/\G[-+*()]/', $formula, $m, 0, $offset)) {
                $tokens[] = ['op', $m[0]];
            } else {
                throw new PostingFormulaException("Формулата „{$formula}“ содржи недозволен знак.");
            }

            $offset += strlen($m[0]);
        }

        return $tokens;
    }

    private function expression(): string
    {
        $value = $this->term();

        while ($this->peekOp(['+', '-'])) {
            $op = $this->next()[1];
            $right = $this->term();
            $value = $op === '+' ? bcadd($value, $right, self::SCALE) : bcsub($value, $right, self::SCALE);
        }

        return $value;
    }

    private function term(): string
    {
        $value = $this->factor();

        while ($this->peekOp(['*'])) {
            $this->next();
            $value = bcmul($value, $this->factor(), self::SCALE);
        }

        return $value;
    }

    private function factor(): string
    {
        $token = $this->next();

        if ($token === null) {
            throw $this->syntax();
        }

        [$type, $value] = $token;

        if ($type === 'num') {
            return $value;
        }

        if ($type === 'var') {
            if (! array_key_exists($value, $this->variables)) {
                throw new PostingFormulaException("Непозната променлива „{$value}“ во формулата „{$this->formula}“.");
            }

            return (string) $this->variables[$value];
        }

        if ($value === '-') {
            return bcmul('-1', $this->factor(), self::SCALE);
        }

        if ($value === '(') {
            $inner = $this->expression();
            $close = $this->next();

            if ($close === null || $close[1] !== ')') {
                throw $this->syntax();
            }

            return $inner;
        }

        throw $this->syntax();
    }

    /** @return array{0: string, 1: string}|null */
    private function next(): ?array
    {
        return $this->tokens[$this->pos++] ?? null;
    }

    /** @param list<string> $ops */
    private function peekOp(array $ops): bool
    {
        $token = $this->tokens[$this->pos] ?? null;

        return $token !== null && $token[0] === 'op' && in_array($token[1], $ops, true);
    }

    private function syntax(): PostingFormulaException
    {
        return new PostingFormulaException("Формулата „{$this->formula}“ не е разбирлива.");
    }
}
```

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Unit/FormulaEvaluatorTest.php`

> `1 / 2` фрла на токенизација (недозволен знак `/`), `1 2` фрла на `pos < count`. Ако некој случај не фрла, поправи ја логиката — не го менувај тестот.

- [ ] **Step 5: Commit**

```bash
git add app tests
git commit -m "Posting schemes: safe formula evaluator"
```

---

### Task 3: Контекст на книжење и сметач за излезна фактура

**Files:**
- Create: `app/Support/Posting/PostingSlice.php`, `PostingContext.php`
- Create: `app/Services/Posting/SalesInvoicePostingContext.php`
- Test: `tests/Feature/Posting/SalesInvoicePostingContextTest.php`

**Interfaces:**
- Produces: `PostingSlice(ItemKind $itemKind, VatGroup $vatGroup, string $base, string $vat, string $baseForeign = '0.00', string $vatForeign = '0.00')` (јавни својства).
- Produces: `PostingContext` (readonly): `array $totals` (променливи во денари), `array $foreignTotals`, `array $slices` (list<PostingSlice>), `array $flags` (`has_goods`, `cash`, `import`), `?int $partnerId`, `string $documentLabel`, `?array $foreign` (`['currency_code','exchange_rate']`), `?Account $invoiceAccount`.
- Produces: `SalesInvoicePostingContext::build(SalesInvoice $invoice, string $formattedNumber, string $cogsTotal): PostingContext`. `$invoice` мора да има вчитано `lines.item` и `company`. Променливи: `ВКУПНО`, `ОСНОВИЦА`, `ДДВ`, `НАБАВНА_ВРЕДНОСТ`. Правила: вкупно се конвертира еднаш; ДДВ вкупно се конвертира; кришките се конвертираат поединечно, а последната ги апсорбира остатоците (збир основици = ВКУПНО − ДДВ; збир ДДВ по кришки = ДДВ). Вид: стока ако `item_id` е сетиран и артиклот не е услуга, инаку услуга. Група: `VatGroup::forLine`. Фирма што не е ДДВ-обврзаник: ДДВ = 0.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Posting;

use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Services\Posting\SalesInvoicePostingContext;
use App\Support\Posting\ItemKind;
use App\Support\Posting\VatGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoicePostingContextTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(Company $company, array $attrs = []): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();

        return SalesInvoice::factory()->for($company)->create(array_merge(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01'], $attrs));
    }

    private function build(SalesInvoice $invoice, string $cogs = '0.00')
    {
        return SalesInvoicePostingContext::build($invoice->load('lines.item', 'company'), '2026/1', $cogs);
    }

    public function test_a_service_at_18_percent(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->invoice($company);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $context = $this->build($invoice);

        $this->assertSame('1180.00', $context->totals['ВКУПНО']);
        $this->assertSame('1000.00', $context->totals['ОСНОВИЦА']);
        $this->assertSame('180.00', $context->totals['ДДВ']);
        $this->assertSame('0.00', $context->totals['НАБАВНА_ВРЕДНОСТ']);
        $this->assertCount(1, $context->slices);
        $this->assertSame(ItemKind::SERVICE, $context->slices[0]->itemKind);
        $this->assertSame(VatGroup::GENERAL, $context->slices[0]->vatGroup);
        $this->assertSame('1000.00', $context->slices[0]->base);
        $this->assertSame('180.00', $context->slices[0]->vat);
        $this->assertFalse($context->flags['has_goods']);
        $this->assertNull($context->foreign);
        $this->assertSame('Invoice 2026/1', $context->documentLabel);
    }

    public function test_goods_and_services_in_different_groups_become_separate_slices(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $product = Item::factory()->for($company)->create(['type' => 'product']);
        $invoice = $this->invoice($company);
        $invoice->lines()->create(['item_id' => $product->id, 'description' => 'Стока', 'quantity' => '2', 'unit_price' => '100.00', 'vat_rate' => '5.00']);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '300.00', 'vat_rate' => '18.00']);
        $invoice->lines()->create(['description' => 'Извоз', 'quantity' => '1', 'unit_price' => '50.00', 'vat_rate' => '0.00', 'vat_treatment' => 'export']);

        $context = $this->build($invoice, '120.00');

        $this->assertCount(3, $context->slices);
        $byKey = collect($context->slices)->keyBy(fn ($s) => $s->itemKind->value.'|'.$s->vatGroup->value);
        $this->assertSame('200.00', $byKey['goods|reduced']->base);
        $this->assertSame('10.00', $byKey['goods|reduced']->vat);
        $this->assertSame('300.00', $byKey['service|general']->base);
        $this->assertSame('54.00', $byKey['service|general']->vat);
        $this->assertSame('50.00', $byKey['service|export']->base);
        $this->assertSame('614.00', $context->totals['ВКУПНО']);
        $this->assertSame('120.00', $context->totals['НАБАВНА_ВРЕДНОСТ']);
        $this->assertTrue($context->flags['has_goods']);
    }

    public function test_a_company_that_is_not_vat_registered_has_no_vat(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => false]);
        $invoice = $this->invoice($company);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $context = $this->build($invoice);

        $this->assertSame('1000.00', $context->totals['ВКУПНО']);
        $this->assertSame('0.00', $context->totals['ДДВ']);
        $this->assertSame('0.00', $context->slices[0]->vat);
    }

    public function test_a_foreign_invoice_closes_to_the_cent_and_keeps_the_foreign_amounts(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->invoice($company, ['currency' => 'EUR', 'exchange_rate' => '61.512345']);
        $invoice->lines()->create(['description' => 'A', 'quantity' => '1', 'unit_price' => '33.33', 'vat_rate' => '18.00']);
        $invoice->lines()->create(['description' => 'B', 'quantity' => '1', 'unit_price' => '66.67', 'vat_rate' => '5.00']);
        $invoice->lines()->create(['description' => 'C', 'quantity' => '1', 'unit_price' => '10.01', 'vat_rate' => '5.00']);

        $context = $this->build($invoice);

        $this->assertSame(['currency_code' => 'EUR', 'exchange_rate' => '61.512345'], $context->foreign);
        $baseSum = collect($context->slices)->reduce(fn ($c, $s) => bcadd($c, $s->base, 2), '0.00');
        $vatSum = collect($context->slices)->reduce(fn ($c, $s) => bcadd($c, $s->vat, 2), '0.00');
        $this->assertSame($context->totals['ОСНОВИЦА'], $baseSum);
        $this->assertSame($context->totals['ДДВ'], $vatSum);
        $this->assertSame($context->totals['ВКУПНО'], bcadd($baseSum, $vatSum, 2));
        // Вкупното е конвертирано ЕДНАШ, не збир од заокружени делови.
        $grossForeign = $invoice->grandTotal();
        $this->assertSame(
            \App\Support\Bcmath::roundHalfUp(bcmul($grossForeign, '61.512345', 10), 2),
            $context->totals['ВКУПНО']
        );
        $this->assertSame($grossForeign, $context->foreignTotals['ВКУПНО']);
        $this->assertSame($invoice->subtotal(), $context->foreignTotals['ОСНОВИЦА']);
    }
}
```

> `Item::factory()` mora da postoi i da прима `type`; ако `type` е `'product'` во шемата (види `Item::isService()` = `type === 'service'`). Провери `database/factories/ItemFactory.php` и прилагоди само ако фабриката бара други задолжителни полиња.

- [ ] **Step 2: Run — FAIL.** `php artisan test tests/Feature/Posting/SalesInvoicePostingContextTest.php`

- [ ] **Step 3: Implement**

`app/Support/Posting/PostingSlice.php`:

```php
<?php

namespace App\Support\Posting;

/** Една кришка од документот: износ за вид на ставка × даночна група. */
final class PostingSlice
{
    public function __construct(
        public ItemKind $itemKind,
        public VatGroup $vatGroup,
        public string $base,
        public string $vat,
        public string $baseForeign = '0.00',
        public string $vatForeign = '0.00',
    ) {}
}
```

`app/Support/Posting/PostingContext.php`:

```php
<?php

namespace App\Support\Posting;

use App\Models\Account;

/**
 * Сè што му треба на моторот за еден документ: променливите (во денари и во
 * валутата на документот), кришките по вид × група, знамињата за услови и
 * партнерот. Нема логика — само податоци.
 */
final class PostingContext
{
    /**
     * @param  array<string, string>  $totals  променливи во денари
     * @param  array<string, string>  $foreignTotals  истите променливи во валутата на документот
     * @param  list<PostingSlice>  $slices
     * @param  array<string, bool>  $flags  has_goods, cash, import
     * @param  array{currency_code: string, exchange_rate: string}|null  $foreign
     */
    public function __construct(
        public readonly array $totals,
        public readonly array $foreignTotals = [],
        public readonly array $slices = [],
        public readonly array $flags = [],
        public readonly ?int $partnerId = null,
        public readonly string $documentLabel = '',
        public readonly ?array $foreign = null,
        public readonly ?Account $invoiceAccount = null,
    ) {}
}
```

`app/Services/Posting/SalesInvoicePostingContext.php`:

```php
<?php

namespace App\Services\Posting;

use App\Models\SalesInvoice;
use App\Support\Bcmath;
use App\Support\Posting\ItemKind;
use App\Support\Posting\PostingContext;
use App\Support\Posting\PostingSlice;
use App\Support\Posting\VatGroup;

/**
 * Ги пресметува износите на излезна фактура за шемите за книжење.
 *
 * Правилото за заокружување е истото како кај досегашното книжење: бруто се
 * конвертира ЕДНАШ (не збир од посебно заокружени делови), ДДВ се конвертира,
 * основицата е остатокот. Кришките се конвертираат поединечно, а последната ги
 * апсорбира остатоците — па збирот на приходите и ДДВ-то е точно колку
 * побарувањето, за секоја фактура и за секој курс.
 */
final class SalesInvoicePostingContext
{
    public static function build(SalesInvoice $invoice, string $formattedNumber, string $cogsTotal): PostingContext
    {
        $vatRegistered = (bool) $invoice->company->is_vat_registered;
        $foreign = $invoice->isForeignCurrency();
        $toMkd = fn (string $amount): string => $foreign
            ? Bcmath::roundHalfUp(bcmul($amount, (string) $invoice->exchange_rate, 10), 2)
            : $amount;

        $buckets = [];

        foreach ($invoice->lines as $line) {
            $kind = ($line->item_id !== null && ! $line->item->isService()) ? ItemKind::GOODS : ItemKind::SERVICE;
            $group = VatGroup::forLine((string) $line->vat_treatment, (string) $line->vat_rate);
            $key = $kind->value.'|'.$group->value;

            $buckets[$key] ??= ['kind' => $kind, 'group' => $group, 'net' => '0.00', 'vat' => '0.00'];
            $buckets[$key]['net'] = bcadd($buckets[$key]['net'], $line->lineTotal(), 2);
            $buckets[$key]['vat'] = bcadd($buckets[$key]['vat'], $vatRegistered ? $line->vatAmount() : '0.00', 2);
        }

        ksort($buckets);

        $subtotalForeign = $invoice->subtotal();
        $vatForeign = $vatRegistered ? $invoice->vatTotal() : '0.00';
        $grossForeign = bcadd($subtotalForeign, $vatForeign, 2);

        $gross = $toMkd($grossForeign);
        $vat = $vatRegistered ? $toMkd($vatForeign) : '0.00';
        $net = bcsub($gross, $vat, 2);

        $slices = [];
        foreach ($buckets as $bucket) {
            $slices[] = new PostingSlice(
                $bucket['kind'],
                $bucket['group'],
                $toMkd($bucket['net']),
                $toMkd($bucket['vat']),
                $bucket['net'],
                $bucket['vat'],
            );
        }

        self::absorbRounding($slices, $net, $vat);

        return new PostingContext(
            totals: ['ВКУПНО' => $gross, 'ОСНОВИЦА' => $net, 'ДДВ' => $vat, 'НАБАВНА_ВРЕДНОСТ' => $cogsTotal],
            foreignTotals: ['ВКУПНО' => $grossForeign, 'ОСНОВИЦА' => $subtotalForeign, 'ДДВ' => $vatForeign, 'НАБАВНА_ВРЕДНОСТ' => '0.00'],
            slices: $slices,
            flags: ['has_goods' => bccomp($cogsTotal, '0', 2) > 0, 'cash' => false, 'import' => false],
            partnerId: $invoice->partner_id,
            documentLabel: 'Invoice '.$formattedNumber,
            foreign: $foreign ? ['currency_code' => $invoice->currency, 'exchange_rate' => (string) $invoice->exchange_rate] : null,
        );
    }

    /**
     * Остатокот од заокружувањето оди на последната кришка (основица) и на
     * последната кришка со ДДВ — така збирот по кришки е точно ОСНОВИЦА и ДДВ.
     *
     * @param  list<PostingSlice>  $slices
     */
    private static function absorbRounding(array $slices, string $net, string $vat): void
    {
        if ($slices === []) {
            return;
        }

        $baseSum = array_reduce($slices, fn (string $carry, PostingSlice $s) => bcadd($carry, $s->base, 2), '0.00');
        $last = $slices[array_key_last($slices)];
        $last->base = bcadd($last->base, bcsub($net, $baseSum, 2), 2);

        $vatSum = array_reduce($slices, fn (string $carry, PostingSlice $s) => bcadd($carry, $s->vat, 2), '0.00');
        $vatDiff = bcsub($vat, $vatSum, 2);

        if (bccomp($vatDiff, '0', 2) !== 0) {
            $target = null;
            foreach ($slices as $slice) {
                if (bccomp($slice->vatForeign, '0', 2) !== 0) {
                    $target = $slice;
                }
            }
            $target ??= $last;
            $target->vat = bcadd($target->vat, $vatDiff, 2);
        }
    }
}
```

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Feature/Posting/SalesInvoicePostingContextTest.php`

- [ ] **Step 5: Commit**

```bash
git add app tests
git commit -m "Posting schemes: context and the sales invoice amounts builder"
```

---

### Task 4: Мотор

**Files:**
- Create: `app/Exceptions/PostingSchemeException.php`, `app/Support/Posting/PostingLine.php`, `app/Services/Posting/PostingSchemeEngine.php`
- Test: `tests/Feature/Posting/PostingSchemeEngineTest.php`

**Interfaces:**
- Consumes: `PostingScheme`/`PostingSchemeRow`/`PostingSchemeMatrixAccount`, `PostingContext`, `FormulaEvaluator`, `PostingMatrix`.
- Produces: `PostingSchemeEngine::lines(PostingScheme $scheme, PostingContext $context): array<int, PostingLine>`; `PostingLine` со: `Account $account`, `string $side` (`debit`|`credit`), `string $amount` (позитивно, 2 децимали), `?int $partnerId`, `string $description`, `?string $foreignAmount`, `?string $currencyCode`, `?string $exchangeRate`, и `journalColumns(mixed $lineDate): array` (`account_id, partner_id, description, line_date, debit, credit` + `currency_code, exchange_rate, foreign_amount` само кога постојат).
- Правила: ред се прескокнува ако условот не важи (`has_goods`, `cash`, `import` и нивните `not_*`; непознат услов → исклучок); `fixed` = `row.account`, `invoice` = `context.invoiceAccount`, `matrix` = се повторува по кришка (матрица `REVENUE` по вид×група; `OUTPUT_VAT` само по група, собрани по вид); износ 0 се прескокнува; негативен износ ја менува страната; сметката мора да е аналитичка и да постои; нема конто во матрицата за кришка со износ → исклучок; на крај Σ должи = Σ побарува, инаку `PostingSchemeException`. Девизни колони само за редови `with_partner` кога контекстот има `foreign`. Опис: `{фактура}` се заменува со `documentLabel`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Posting;

use App\Exceptions\PostingSchemeException;
use App\Models\Account;
use App\Models\Company;
use App\Models\PostingScheme;
use App\Models\PostingSchemeMatrixAccount;
use App\Models\PostingSchemeRow;
use App\Services\Posting\PostingSchemeEngine;
use App\Support\Posting\ItemKind;
use App\Support\Posting\PostingContext;
use App\Support\Posting\PostingDocType;
use App\Support\Posting\PostingMatrix;
use App\Support\Posting\PostingSlice;
use App\Support\Posting\VatGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostingSchemeEngineTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private PostingScheme $scheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->scheme = PostingScheme::create(['company_id' => $this->company->id, 'doc_type' => PostingDocType::SALES_INVOICE, 'name' => 't']);
    }

    private function account(string $code): Account
    {
        return Account::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function row(int $position, string $mode, string $side, string $formula, array $extra = []): PostingSchemeRow
    {
        return PostingSchemeRow::create(array_merge([
            'posting_scheme_id' => $this->scheme->id, 'position' => $position, 'account_mode' => $mode,
            'side' => $side, 'formula' => $formula,
        ], $extra));
    }

    private function matrix(string $key, ?string $kind, string $group, string $code): void
    {
        PostingSchemeMatrixAccount::create(['posting_scheme_id' => $this->scheme->id, 'matrix_key' => $key, 'item_kind' => $kind, 'vat_group' => $group, 'account_id' => $this->account($code)->id]);
    }

    private function context(array $totals, array $slices = [], array $flags = [], array $extra = []): PostingContext
    {
        return new PostingContext(totals: $totals, slices: $slices, flags: $flags + ['has_goods' => false, 'cash' => false, 'import' => false], partnerId: 7, documentLabel: 'Invoice 5', ...$extra);
    }

    private function engine(): PostingSchemeEngine
    {
        return new PostingSchemeEngine;
    }

    public function test_fixed_rows_post_the_formula_amount_with_partner_and_description(): void
    {
        $this->row(1, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('1200')->id, 'with_partner' => true, 'description' => '{фактура}']);
        $this->row(2, 'fixed', 'credit', 'ВКУПНО', ['account_id' => $this->account('74000')->id, 'description' => 'Приход']);

        $lines = $this->engine()->lines($this->scheme->fresh(), $this->context(['ВКУПНО' => '118.00']));

        $this->assertCount(2, $lines);
        $this->assertSame('1200', $lines[0]->account->code);
        $this->assertSame('debit', $lines[0]->side);
        $this->assertSame('118.00', $lines[0]->amount);
        $this->assertSame(7, $lines[0]->partnerId);
        $this->assertSame('Invoice 5', $lines[0]->description);
        $this->assertNull($lines[1]->partnerId);
    }

    public function test_a_matrix_row_repeats_per_slice_and_reads_the_right_account(): void
    {
        $this->row(1, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('1200')->id]);
        $this->row(2, 'matrix', 'credit', 'ОСНОВИЦА', ['matrix_key' => PostingMatrix::REVENUE]);
        $this->row(3, 'matrix', 'credit', 'ДДВ', ['matrix_key' => PostingMatrix::OUTPUT_VAT]);
        $this->matrix(PostingMatrix::REVENUE, 'service', 'general', '74000');
        $this->matrix(PostingMatrix::REVENUE, 'goods', 'reduced', '74101');
        $this->matrix(PostingMatrix::OUTPUT_VAT, null, 'general', '2300');
        $this->matrix(PostingMatrix::OUTPUT_VAT, null, 'reduced', '2301');

        $slices = [
            new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '100.00', '18.00'),
            new PostingSlice(ItemKind::GOODS, VatGroup::REDUCED, '200.00', '10.00'),
            new PostingSlice(ItemKind::GOODS, VatGroup::GENERAL, '0.00', '0.00'),
        ];
        // ВКУПНО = 100 + 18 + 200 + 10
        $lines = $this->engine()->lines($this->scheme->fresh(), $this->context(['ВКУПНО' => '328.00'], $slices));

        $byAccount = collect($lines)->keyBy(fn ($l) => $l->account->code);
        $this->assertSame('100.00', $byAccount['74000']->amount);
        $this->assertSame('200.00', $byAccount['74101']->amount);
        $this->assertSame('18.00', $byAccount['2300']->amount);
        $this->assertSame('10.00', $byAccount['2301']->amount);
        $this->assertCount(5, $lines);
    }

    public function test_the_vat_matrix_is_read_by_group_and_sums_goods_and_services(): void
    {
        $this->row(1, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('1200')->id]);
        $this->row(2, 'fixed', 'credit', 'ОСНОВИЦА', ['account_id' => $this->account('74000')->id]);
        $this->row(3, 'matrix', 'credit', 'ДДВ', ['matrix_key' => PostingMatrix::OUTPUT_VAT]);
        $this->matrix(PostingMatrix::OUTPUT_VAT, null, 'general', '2300');
        $slices = [
            new PostingSlice(ItemKind::GOODS, VatGroup::GENERAL, '50.00', '9.00'),
            new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '50.00', '9.00'),
        ];

        $lines = $this->engine()->lines($this->scheme->fresh(), $this->context(['ВКУПНО' => '118.00', 'ОСНОВИЦА' => '100.00'], $slices));

        $vat = collect($lines)->firstWhere(fn ($l) => $l->account->code === '2300');
        $this->assertSame('18.00', $vat->amount);
    }

    public function test_conditions_skip_rows(): void
    {
        // Секој услов има свој балансиран пар, па секоја комбинација поминува баланс.
        $this->row(1, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('1200')->id]);
        $this->row(2, 'fixed', 'credit', 'ВКУПНО', ['account_id' => $this->account('74000')->id]);
        $this->row(3, 'fixed', 'debit', 'НАБАВНА_ВРЕДНОСТ', ['account_id' => $this->account('7010')->id, 'condition' => 'has_goods']);
        $this->row(4, 'fixed', 'credit', 'НАБАВНА_ВРЕДНОСТ', ['account_id' => $this->account('6600')->id, 'condition' => 'has_goods']);
        $this->row(5, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('1020')->id, 'condition' => 'cash']);
        $this->row(6, 'fixed', 'credit', 'ВКУПНО', ['account_id' => $this->account('74001')->id, 'condition' => 'cash']);
        $this->row(7, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('1000')->id, 'condition' => 'not_cash']);
        $this->row(8, 'fixed', 'credit', 'ВКУПНО', ['account_id' => $this->account('74002')->id, 'condition' => 'not_cash']);

        $totals = ['ВКУПНО' => '10.00', 'НАБАВНА_ВРЕДНОСТ' => '4.00'];
        $codes = fn (array $lines) => collect($lines)->map(fn ($l) => $l->account->code)->sort()->values()->all();

        $plain = $this->engine()->lines($this->scheme->fresh(), $this->context($totals));
        $this->assertSame(['1000', '1200', '74000', '74002'], $codes($plain));

        $goods = $this->engine()->lines($this->scheme->fresh(), $this->context($totals, [], ['has_goods' => true]));
        $this->assertSame(['1000', '1200', '6600', '7010', '74000', '74002'], $codes($goods));

        $cash = $this->engine()->lines($this->scheme->fresh(), $this->context($totals, [], ['cash' => true]));
        $this->assertSame(['1020', '1200', '74000', '74001'], $codes($cash));
    }

    public function test_an_unknown_condition_is_refused(): void
    {
        $this->row(1, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('1200')->id, 'condition' => 'weekend']);

        $this->expectException(PostingSchemeException::class);

        $this->engine()->lines($this->scheme->fresh(), $this->context(['ВКУПНО' => '1.00']));
    }

    public function test_zero_amounts_are_skipped_and_negative_amounts_flip_the_side(): void
    {
        $this->row(1, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('1200')->id]);
        $this->row(2, 'fixed', 'credit', 'ВКУПНО - ДДВ', ['account_id' => $this->account('74000')->id]);
        $this->row(3, 'fixed', 'credit', 'ДДВ', ['account_id' => $this->account('2300')->id]);
        $this->row(4, 'fixed', 'credit', 'НЕЛА', ['account_id' => $this->account('74001')->id]);
        $this->row(5, 'fixed', 'credit', '-ПОПУСТ', ['account_id' => $this->account('7419')->id]);
        $this->row(6, 'fixed', 'credit', 'ПОПУСТ', ['account_id' => $this->account('1020')->id]);

        $lines = $this->engine()->lines($this->scheme->fresh(), $this->context([
            'ВКУПНО' => '118.00', 'ДДВ' => '18.00', 'НЕЛА' => '0.00', 'ПОПУСТ' => '5.00',
        ]));

        $accounts = collect($lines)->map(fn ($l) => $l->account->code)->all();
        $this->assertNotContains('74001', $accounts); // нула се прескокнува
        $flipped = collect($lines)->firstWhere(fn ($l) => $l->account->code === '7419');
        $this->assertSame('debit', $flipped->side); // −5 побарува = 5 должи
        $this->assertSame('5.00', $flipped->amount);
    }

    public function test_the_invoice_mode_uses_the_account_of_the_document_even_a_legacy_heading(): void
    {
        $this->row(1, 'fixed', 'debit', 'ИЗНОС', ['account_id' => $this->account('1000')->id]);
        $this->row(2, 'invoice', 'credit', 'ИЗНОС');

        // Нова фактура: аналитичко 1200; стара фактура книжена на 120 пред шемите.
        foreach (['1200', '120'] as $code) {
            $lines = $this->engine()->lines(
                $this->scheme->fresh(),
                $this->context(['ИЗНОС' => '30.00'], [], [], ['invoiceAccount' => $this->account($code)])
            );

            $this->assertSame($code, $lines[1]->account->code);
        }
    }

    public function test_the_invoice_mode_without_an_account_is_refused(): void
    {
        $this->row(1, 'invoice', 'credit', 'ИЗНОС');

        $this->expectException(PostingSchemeException::class);

        $this->engine()->lines($this->scheme->fresh(), $this->context(['ИЗНОС' => '30.00']));
    }

    public function test_an_unbalanced_scheme_is_refused(): void
    {
        $this->row(1, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('1200')->id]);
        $this->row(2, 'fixed', 'credit', 'ОСНОВИЦА', ['account_id' => $this->account('74000')->id]);

        $this->expectException(PostingSchemeException::class);
        $this->expectExceptionMessage('не се балансира');

        $this->engine()->lines($this->scheme->fresh(), $this->context(['ВКУПНО' => '118.00', 'ОСНОВИЦА' => '100.00']));
    }

    public function test_a_heading_account_is_refused(): void
    {
        $this->row(1, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('120')->id]);
        $this->row(2, 'fixed', 'credit', 'ВКУПНО', ['account_id' => $this->account('74000')->id]);

        $this->expectException(PostingSchemeException::class);
        $this->expectExceptionMessage('не е аналитичко');

        $this->engine()->lines($this->scheme->fresh(), $this->context(['ВКУПНО' => '1.00']));
    }

    public function test_a_slice_without_a_matrix_account_is_refused(): void
    {
        $this->row(1, 'matrix', 'credit', 'ОСНОВИЦА', ['matrix_key' => PostingMatrix::REVENUE]);

        $this->expectException(PostingSchemeException::class);
        $this->expectExceptionMessage('нема конто');

        $this->engine()->lines($this->scheme->fresh(), $this->context(['ВКУПНО' => '1.00'], [new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '1.00', '0.00')]));
    }

    public function test_foreign_columns_go_only_on_partner_rows_of_a_foreign_document(): void
    {
        $this->row(1, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('1200')->id, 'with_partner' => true]);
        $this->row(2, 'fixed', 'credit', 'ВКУПНО', ['account_id' => $this->account('74000')->id]);

        $context = new PostingContext(
            totals: ['ВКУПНО' => '615.12'],
            foreignTotals: ['ВКУПНО' => '10.00'],
            flags: ['has_goods' => false, 'cash' => false, 'import' => false],
            partnerId: 7,
            foreign: ['currency_code' => 'EUR', 'exchange_rate' => '61.512000'],
        );

        $lines = $this->engine()->lines($this->scheme->fresh(), $context);

        $this->assertSame('10.00', $lines[0]->foreignAmount);
        $this->assertSame('EUR', $lines[0]->currencyCode);
        $this->assertNull($lines[1]->foreignAmount);
        $columns = $lines[0]->journalColumns('2026-03-01');
        $this->assertSame('615.12', $columns['debit']);
        $this->assertSame('0', $columns['credit']);
        $this->assertSame('10.00', $columns['foreign_amount']);
        $this->assertArrayNotHasKey('foreign_amount', $lines[1]->journalColumns('2026-03-01'));
    }
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement**

`app/Exceptions/PostingSchemeException.php`:

```php
<?php

namespace App\Exceptions;

class PostingSchemeException extends \RuntimeException {}
```

`app/Support/Posting/PostingLine.php`:

```php
<?php

namespace App\Support\Posting;

use App\Models\Account;

/** Една ставка од книжење што ја произвел моторот: сè уште не е запишана. */
final class PostingLine
{
    public function __construct(
        public readonly Account $account,
        public readonly string $side,
        public readonly string $amount,
        public readonly ?int $partnerId,
        public readonly string $description,
        public readonly ?string $foreignAmount = null,
        public readonly ?string $currencyCode = null,
        public readonly ?string $exchangeRate = null,
    ) {}

    /** Колони за `journal_entry_lines`. Девизните колони се само кога постојат. */
    public function journalColumns(mixed $lineDate): array
    {
        $columns = [
            'account_id' => $this->account->id,
            'partner_id' => $this->partnerId,
            'description' => $this->description,
            'line_date' => $lineDate,
            'debit' => $this->side === 'debit' ? $this->amount : '0',
            'credit' => $this->side === 'credit' ? $this->amount : '0',
        ];

        if ($this->foreignAmount !== null) {
            $columns['currency_code'] = $this->currencyCode;
            $columns['exchange_rate'] = $this->exchangeRate;
            $columns['foreign_amount'] = $this->foreignAmount;
        }

        return $columns;
    }
}
```

`app/Services/Posting/PostingSchemeEngine.php`:

```php
<?php

namespace App\Services\Posting;

use App\Exceptions\PostingSchemeException;
use App\Models\Account;
use App\Models\PostingScheme;
use App\Models\PostingSchemeRow;
use App\Support\Posting\FormulaEvaluator;
use App\Support\Posting\PostingContext;
use App\Support\Posting\PostingLine;
use App\Support\Posting\PostingMatrix;
use App\Support\Posting\PostingSlice;

/**
 * Од шема и контекст на документ ги прави ставките на книжењето. Чист: ништо не
 * запишува. Гарантира: само аналитички конта, нула-износи се прескокнуваат, и
 * збирот Должи = збирот Побарува.
 */
class PostingSchemeEngine
{
    private const KNOWN_FLAGS = ['has_goods', 'cash', 'import'];

    /** @return list<PostingLine> */
    public function lines(PostingScheme $scheme, PostingContext $context): array
    {
        $scheme->loadMissing(['rows.account', 'matrixAccounts.account']);
        $lines = [];

        foreach ($scheme->rows as $row) {
            if (! $this->conditionHolds($row->condition, $context)) {
                continue;
            }

            foreach ($this->expand($scheme, $row, $context) as [$account, $variables, $foreignVariables]) {
                $line = $this->line($row, $account, $variables, $foreignVariables, $context);

                if ($line !== null) {
                    $lines[] = $line;
                }
            }
        }

        $this->assertBalanced($lines, $scheme);

        return $lines;
    }

    private function conditionHolds(?string $condition, PostingContext $context): bool
    {
        if ($condition === null || $condition === '') {
            return true;
        }

        $negated = str_starts_with($condition, 'not_');
        $flag = $negated ? substr($condition, 4) : $condition;

        if (! in_array($flag, self::KNOWN_FLAGS, true)) {
            throw new PostingSchemeException("Непознат услов „{$condition}“ во шемата.");
        }

        $value = (bool) ($context->flags[$flag] ?? false);

        return $negated ? ! $value : $value;
    }

    /** @return list<array{0: Account, 1: array<string, string>, 2: array<string, string>}> */
    private function expand(PostingScheme $scheme, PostingSchemeRow $row, PostingContext $context): array
    {
        if ($row->account_mode === 'fixed') {
            return [[$this->checked($row->account, $row), $context->totals, $context->foreignTotals]];
        }

        if ($row->account_mode === 'invoice') {
            if ($context->invoiceAccount === null) {
                throw new PostingSchemeException('Редот бара сметка од документот, а таа не е позната.');
            }

            // Сметката е веќе употребена при книжењето на самата фактура и не се
            // проверува за аналитичност: стара фактура книжена на 120 (наслов)
            // пред шемите мора да се затвори на 120, не на друго конто.
            return [[$context->invoiceAccount, $context->totals, $context->foreignTotals]];
        }

        if ($row->account_mode !== 'matrix' || $row->matrix_key === null) {
            throw new PostingSchemeException("Непознат начин на конто „{$row->account_mode}“.");
        }

        $expanded = [];

        foreach ($this->groupedSlices($row->matrix_key, $context->slices) as $slice) {
            if (bccomp($slice->base, '0', 2) === 0 && bccomp($slice->vat, '0', 2) === 0) {
                continue;
            }

            $expanded[] = [
                $this->checked($this->matrixAccount($scheme, $row->matrix_key, $slice), $row),
                ['ОСНОВИЦА' => $slice->base, 'ДДВ' => $slice->vat, 'ВКУПНО' => bcadd($slice->base, $slice->vat, 2)],
                ['ОСНОВИЦА' => $slice->baseForeign, 'ДДВ' => $slice->vatForeign, 'ВКУПНО' => bcadd($slice->baseForeign, $slice->vatForeign, 2)],
            ];
        }

        return $expanded;
    }

    /**
     * Матрицата по вид×група ги чува кришките како што се; матрицата само по
     * група ги собира сите видови на иста група во една.
     *
     * @param  list<PostingSlice>  $slices
     * @return list<PostingSlice>
     */
    private function groupedSlices(string $matrixKey, array $slices): array
    {
        if (PostingMatrix::byKind($matrixKey)) {
            return $slices;
        }

        $merged = [];

        foreach ($slices as $slice) {
            $key = $slice->vatGroup->value;

            if (! isset($merged[$key])) {
                $merged[$key] = new PostingSlice($slice->itemKind, $slice->vatGroup, '0.00', '0.00', '0.00', '0.00');
            }

            $merged[$key]->base = bcadd($merged[$key]->base, $slice->base, 2);
            $merged[$key]->vat = bcadd($merged[$key]->vat, $slice->vat, 2);
            $merged[$key]->baseForeign = bcadd($merged[$key]->baseForeign, $slice->baseForeign, 2);
            $merged[$key]->vatForeign = bcadd($merged[$key]->vatForeign, $slice->vatForeign, 2);
        }

        return array_values($merged);
    }

    private function matrixAccount(PostingScheme $scheme, string $matrixKey, PostingSlice $slice): Account
    {
        $byKind = PostingMatrix::byKind($matrixKey);

        $candidates = $scheme->matrixAccounts->filter(
            fn ($m) => $m->matrix_key === $matrixKey
                && $m->vat_group === $slice->vatGroup->value
                && ($byKind ? $m->item_kind === $slice->itemKind->value : $m->item_kind === null)
        );

        $match = $candidates->first() ?? ($byKind
            ? $scheme->matrixAccounts->first(fn ($m) => $m->matrix_key === $matrixKey && $m->vat_group === $slice->vatGroup->value && $m->item_kind === null)
            : null);

        if ($match === null) {
            $kind = $byKind ? $slice->itemKind->label().', ' : '';

            throw new PostingSchemeException("Во матрицата „{$matrixKey}“ нема конто за {$kind}{$slice->vatGroup->label()}.");
        }

        return $match->account;
    }

    private function checked(?Account $account, PostingSchemeRow $row): Account
    {
        if ($account === null) {
            throw new PostingSchemeException('Редот на шемата нема конто (контото е избришано или не е избрано).');
        }

        if (! $account->is_analytical) {
            throw new PostingSchemeException("Контото {$account->code} не е аналитичко — книжењето оди само на аналитички конта.");
        }

        return $account;
    }

    /**
     * @param  array<string, string>  $variables
     * @param  array<string, string>  $foreignVariables
     */
    private function line(PostingSchemeRow $row, Account $account, array $variables, array $foreignVariables, PostingContext $context): ?PostingLine
    {
        $amount = FormulaEvaluator::evaluate($row->formula, $variables);

        if (bccomp($amount, '0', 2) === 0) {
            return null;
        }

        $side = $row->side;
        $negative = bccomp($amount, '0', 2) < 0;

        if ($negative) {
            $amount = bcmul($amount, '-1', 2);
            $side = $side === 'debit' ? 'credit' : 'debit';
        }

        $foreignAmount = null;
        $currencyCode = null;
        $exchangeRate = null;

        if ($row->with_partner && $context->foreign !== null) {
            $foreignAmount = FormulaEvaluator::evaluate($row->formula, $foreignVariables);
            $foreignAmount = $negative ? bcmul($foreignAmount, '-1', 2) : $foreignAmount;
            $currencyCode = $context->foreign['currency_code'];
            $exchangeRate = $context->foreign['exchange_rate'];
        }

        return new PostingLine(
            account: $account,
            side: $side,
            amount: $amount,
            partnerId: $row->with_partner ? $context->partnerId : null,
            description: str_replace('{фактура}', $context->documentLabel, (string) $row->description),
            foreignAmount: $foreignAmount,
            currencyCode: $currencyCode,
            exchangeRate: $exchangeRate,
        );
    }

    /** @param list<PostingLine> $lines */
    private function assertBalanced(array $lines, PostingScheme $scheme): void
    {
        $debit = '0.00';
        $credit = '0.00';

        foreach ($lines as $line) {
            if ($line->side === 'debit') {
                $debit = bcadd($debit, $line->amount, 2);
            } else {
                $credit = bcadd($credit, $line->amount, 2);
            }
        }

        if (bccomp($debit, $credit, 2) !== 0) {
            throw new PostingSchemeException("Шемата „{$scheme->name}“ не се балансира (должи {$debit}, побарува {$credit}).");
        }
    }
}
```

> Тестот `test_unknown_condition_is_refused` бара исклучок и кога условот е на ред што би бил прескокнат — моторот го проверува условот пред секој ред, па е исполнето.

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Feature/Posting/PostingSchemeEngineTest.php`

- [ ] **Step 5: Commit**

```bash
git add app tests
git commit -m "Posting schemes: engine with matrices, conditions and balance guard"
```

---

### Task 5: Стандардни шеми и лено создавање

**Files:**
- Create: `app/Services/Posting/DefaultPostingSchemes.php`, `app/Services/Posting/PostingSchemes.php`
- Test: `tests/Feature/Posting/DefaultPostingSchemesTest.php`

**Interfaces:**
- Produces: `DefaultPostingSchemes::definition(PostingDocType $type): array` (`['name', 'rows' => list<array>, 'matrix' => list<array>]`; за `SALES_INVOICE` и `SALES_PAYMENT`; за другите `LogicException`), `DefaultPostingSchemes::create(Company $company, PostingDocType $type): PostingScheme`, `PostingSchemes::for(Company $company, PostingDocType $type): PostingScheme` (постојна или лено создадена од стандардна, во трансакција).
- Стандардна излезна фактура: 1200 Должи `ВКУПНО` (партнер, `{фактура}`); матрица REVENUE Побарува `ОСНОВИЦА` (партнер, `{фактура}`); матрица OUTPUT_VAT Побарува `ДДВ` (партнер, `VAT on {фактура}`); 7010 Должи `НАБАВНА_ВРЕДНОСТ` (`has_goods`, `COGS for {фактура}`); 6600 Побарува `НАБАВНА_ВРЕДНОСТ` (`has_goods`). Матрица REVENUE: услуга → 74000/74001/74002/74003/7423 (општа/повластена/ослободено со/без право/извоз); стока → 74100/74101/74102/74103/7421. Матрица OUTPUT_VAT: општа 2300, повластена 2301.
- Стандардна уплата од купувач: 1000 Должи `ИЗНОС` (партнер, `not_cash`, `{фактура}`); 1020 Должи `ИЗНОС` (партнер, `cash`); режим `invoice` Побарува `ИЗНОС` (партнер). Описот на уплата е `Payment for invoice` + број — моторот го заменува само `{фактура}`; за уплата `documentLabel` е веќе „Payment for invoice N“ (види Task 7), па описот во шемата е `{фактура}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Posting;

use App\Models\Account;
use App\Models\Company;
use App\Models\PostingScheme;
use App\Services\Posting\DefaultPostingSchemes;
use App\Services\Posting\PostingSchemeEngine;
use App\Services\Posting\PostingSchemes;
use App\Support\Posting\ItemKind;
use App\Support\Posting\PostingContext;
use App\Support\Posting\PostingDocType;
use App\Support\Posting\PostingSlice;
use App\Support\Posting\VatGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DefaultPostingSchemesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_default_account_exists_and_is_analytical_in_the_chart(): void
    {
        $company = Company::factory()->create();

        foreach ([PostingDocType::SALES_INVOICE, PostingDocType::SALES_PAYMENT] as $type) {
            $definition = DefaultPostingSchemes::definition($type);
            $codes = array_filter(array_merge(
                array_column($definition['rows'], 'account'),
                array_column($definition['matrix'], 'account'),
            ));

            foreach ($codes as $code) {
                $account = Account::where('company_id', $company->id)->where('code', $code)->first();
                $this->assertNotNull($account, "Конто {$code} не постои во официјалниот план.");
                $this->assertTrue($account->is_analytical, "Конто {$code} не е аналитичко.");
            }
        }
    }

    public function test_the_scheme_is_created_lazily_once(): void
    {
        $company = Company::factory()->create();
        $this->assertSame(0, PostingScheme::where('company_id', $company->id)->count());

        $first = PostingSchemes::for($company, PostingDocType::SALES_INVOICE);
        $second = PostingSchemes::for($company, PostingDocType::SALES_INVOICE);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PostingScheme::where('company_id', $company->id)->count());
        $this->assertSame(5, $first->rows()->count());
        $this->assertSame(12, $first->matrixAccounts()->count());
    }

    public function test_a_changed_scheme_is_not_overwritten(): void
    {
        $company = Company::factory()->create();
        $scheme = PostingSchemes::for($company, PostingDocType::SALES_INVOICE);
        $scheme->rows()->first()->update(['formula' => 'ВКУПНО - ДДВ']);

        PostingSchemes::for($company, PostingDocType::SALES_INVOICE);

        $this->assertSame('ВКУПНО - ДДВ', $scheme->rows()->first()->fresh()->formula);
    }

    public function test_a_type_without_defaults_is_refused(): void
    {
        $this->expectException(\LogicException::class);

        DefaultPostingSchemes::definition(PostingDocType::PURCHASE_INVOICE);
    }

    /** @return array<string, array{0: list<PostingSlice>, 1: string, 2: string, 3: string, 4: string}> */
    public static function documents(): array
    {
        return [
            'услуга 18%' => [[new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '1000.00', '180.00')], '1180.00', '1000.00', '180.00', '0.00'],
            'стока 18% со залиха' => [[new PostingSlice(ItemKind::GOODS, VatGroup::GENERAL, '1000.00', '180.00')], '1180.00', '1000.00', '180.00', '600.00'],
            'мешано 18/5/извоз' => [[
                new PostingSlice(ItemKind::GOODS, VatGroup::REDUCED, '200.00', '10.00'),
                new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '300.00', '54.00'),
                new PostingSlice(ItemKind::SERVICE, VatGroup::EXPORT, '50.00', '0.00'),
            ], '614.00', '550.00', '64.00', '120.00'],
            'ослободено' => [[
                new PostingSlice(ItemKind::GOODS, VatGroup::EXEMPT_WITH_CREDIT, '70.00', '0.00'),
                new PostingSlice(ItemKind::SERVICE, VatGroup::EXEMPT_WITHOUT_CREDIT, '30.00', '0.00'),
            ], '100.00', '100.00', '0.00', '0.00'],
        ];
    }

    #[DataProvider('documents')]
    public function test_the_default_sales_scheme_balances_for_typical_documents(array $slices, string $gross, string $net, string $vat, string $cogs): void
    {
        $company = Company::factory()->create();
        $scheme = PostingSchemes::for($company, PostingDocType::SALES_INVOICE);
        $context = new PostingContext(
            totals: ['ВКУПНО' => $gross, 'ОСНОВИЦА' => $net, 'ДДВ' => $vat, 'НАБАВНА_ВРЕДНОСТ' => $cogs],
            slices: $slices,
            flags: ['has_goods' => bccomp($cogs, '0', 2) > 0, 'cash' => false, 'import' => false],
            partnerId: 1,
            documentLabel: 'Invoice 1',
        );

        $lines = (new PostingSchemeEngine)->lines($scheme, $context);

        $debit = collect($lines)->where('side', 'debit')->reduce(fn ($c, $l) => bcadd($c, $l->amount, 2), '0.00');
        $this->assertSame($gross, collect($lines)->firstWhere(fn ($l) => $l->account->code === '1200')->amount);
        $this->assertTrue(bccomp($debit, '0', 2) > 0);
    }
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement**

`app/Services/Posting/DefaultPostingSchemes.php`:

```php
<?php

namespace App\Services\Posting;

use App\Models\Account;
use App\Models\Company;
use App\Models\PostingScheme;
use App\Support\Posting\PostingDocType;
use App\Support\Posting\PostingMatrix;
use Illuminate\Support\Facades\DB;

/**
 * Стандардните шеми. Контата се бараат по код во планот на фирмата и мора да
 * бидат аналитички. Сметководителот ги менува потоа; ова се само почеток.
 */
class DefaultPostingSchemes
{
    /**
     * @return array{name: string, rows: list<array<string, mixed>>, matrix: list<array<string, string|null>>}
     */
    public static function definition(PostingDocType $type): array
    {
        return match ($type) {
            PostingDocType::SALES_INVOICE => self::salesInvoice(),
            PostingDocType::SALES_PAYMENT => self::salesPayment(),
            default => throw new \LogicException("Нема стандардна шема за {$type->value} (доаѓа во следниот дел)."),
        };
    }

    public static function create(Company $company, PostingDocType $type): PostingScheme
    {
        $definition = self::definition($type);

        return DB::transaction(function () use ($company, $type, $definition) {
            $scheme = PostingScheme::create(['company_id' => $company->id, 'doc_type' => $type, 'name' => $definition['name']]);

            foreach ($definition['rows'] as $position => $row) {
                $scheme->rows()->create([
                    'position' => $position + 1,
                    'account_mode' => $row['mode'],
                    'account_id' => isset($row['account']) ? self::account($company, $row['account'])->id : null,
                    'matrix_key' => $row['matrix'] ?? null,
                    'side' => $row['side'],
                    'formula' => $row['formula'],
                    'with_partner' => $row['partner'] ?? false,
                    'description' => $row['description'] ?? null,
                    'condition' => $row['condition'] ?? null,
                ]);
            }

            foreach ($definition['matrix'] as $entry) {
                $scheme->matrixAccounts()->create([
                    'matrix_key' => $entry['key'],
                    'item_kind' => $entry['kind'],
                    'vat_group' => $entry['group'],
                    'account_id' => self::account($company, $entry['account'])->id,
                ]);
            }

            return $scheme;
        });
    }

    private static function account(Company $company, string $code): Account
    {
        return Account::where('company_id', $company->id)->analytical()->where('code', $code)->firstOrFail();
    }

    private static function salesInvoice(): array
    {
        $revenue = PostingMatrix::REVENUE;
        $vat = PostingMatrix::OUTPUT_VAT;

        return [
            'name' => 'Излезна фактура',
            'rows' => [
                ['mode' => 'fixed', 'account' => '1200', 'side' => 'debit', 'formula' => 'ВКУПНО', 'partner' => true, 'description' => '{фактура}'],
                ['mode' => 'matrix', 'matrix' => $revenue, 'side' => 'credit', 'formula' => 'ОСНОВИЦА', 'partner' => true, 'description' => '{фактура}'],
                ['mode' => 'matrix', 'matrix' => $vat, 'side' => 'credit', 'formula' => 'ДДВ', 'partner' => true, 'description' => 'VAT on {фактура}'],
                ['mode' => 'fixed', 'account' => '7010', 'side' => 'debit', 'formula' => 'НАБАВНА_ВРЕДНОСТ', 'description' => 'COGS for {фактура}', 'condition' => 'has_goods'],
                ['mode' => 'fixed', 'account' => '6600', 'side' => 'credit', 'formula' => 'НАБАВНА_ВРЕДНОСТ', 'description' => 'COGS for {фактура}', 'condition' => 'has_goods'],
            ],
            'matrix' => [
                ['key' => $revenue, 'kind' => 'service', 'group' => 'general', 'account' => '74000'],
                ['key' => $revenue, 'kind' => 'service', 'group' => 'reduced', 'account' => '74001'],
                ['key' => $revenue, 'kind' => 'service', 'group' => 'exempt_with_credit', 'account' => '74002'],
                ['key' => $revenue, 'kind' => 'service', 'group' => 'exempt_without_credit', 'account' => '74003'],
                ['key' => $revenue, 'kind' => 'service', 'group' => 'export', 'account' => '7423'],
                ['key' => $revenue, 'kind' => 'goods', 'group' => 'general', 'account' => '74100'],
                ['key' => $revenue, 'kind' => 'goods', 'group' => 'reduced', 'account' => '74101'],
                ['key' => $revenue, 'kind' => 'goods', 'group' => 'exempt_with_credit', 'account' => '74102'],
                ['key' => $revenue, 'kind' => 'goods', 'group' => 'exempt_without_credit', 'account' => '74103'],
                ['key' => $revenue, 'kind' => 'goods', 'group' => 'export', 'account' => '7421'],
                ['key' => $vat, 'kind' => null, 'group' => 'general', 'account' => '2300'],
                ['key' => $vat, 'kind' => null, 'group' => 'reduced', 'account' => '2301'],
            ],
        ];
    }

    private static function salesPayment(): array
    {
        return [
            'name' => 'Уплата од купувач',
            'rows' => [
                ['mode' => 'fixed', 'account' => '1000', 'side' => 'debit', 'formula' => 'ИЗНОС', 'partner' => true, 'description' => '{фактура}', 'condition' => 'not_cash'],
                ['mode' => 'fixed', 'account' => '1020', 'side' => 'debit', 'formula' => 'ИЗНОС', 'partner' => true, 'description' => '{фактура}', 'condition' => 'cash'],
                ['mode' => 'invoice', 'side' => 'credit', 'formula' => 'ИЗНОС', 'partner' => true, 'description' => '{фактура}'],
            ],
            'matrix' => [],
        ];
    }
}
```

`app/Services/Posting/PostingSchemes.php`:

```php
<?php

namespace App\Services\Posting;

use App\Models\Company;
use App\Models\PostingScheme;
use App\Support\Posting\PostingDocType;

/** Шемата на фирма за вид документ: постојната, или лено создадена од стандардната. */
class PostingSchemes
{
    public static function for(Company $company, PostingDocType $type): PostingScheme
    {
        return PostingScheme::where('company_id', $company->id)->where('doc_type', $type->value)->first()
            ?? DefaultPostingSchemes::create($company, $type);
    }
}
```

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Feature/Posting/DefaultPostingSchemesTest.php`

> Ако некое од конта (`7421`, `7423`, `74102`, `74103`) не е аналитичко/не постои во планот, тестот ќе го покаже — користи го вистинскиот аналитички наследник од `docs/reference/official-chart-of-accounts.json` и **спомни го во логот** (не молчи).

- [ ] **Step 5: Commit**

```bash
git add app tests
git commit -m "Posting schemes: default sales schemes and lazy creation"
```

---

### Task 6: Излезната фактура се книжи преку шема

**Files:**
- Modify: `app/Services/Invoicing/SalesInvoiceService.php` (конструктор, `confirm`)
- Test: `tests/Feature/Posting/SalesInvoiceSchemePostingTest.php`; ажурирај `tests/Unit/SalesInvoiceServiceTest.php`, `tests/Feature/ForeignCurrencyInvoiceTest.php`

**Interfaces:**
- Consumes: `SalesInvoicePostingContext::build`, `PostingSchemes::for`, `PostingSchemeEngine::lines`, `PostingLine::journalColumns`.
- Produces: `confirm()` создава налог со ставки од шемата. Описот на налогот (`Sales Invoice {број}`), групата 99 и сè друго во `confirm` остануваат исти.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Posting;

use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Invoicing\SalesInvoiceService;
use App\Services\Posting\PostingSchemes;
use App\Support\Posting\PostingDocType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoiceSchemePostingTest extends TestCase
{
    use RefreshDatabase;

    private function draft(Company $company, string $price, string $rate, array $lineExtra = []): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(array_merge(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => $price, 'vat_rate' => $rate], $lineExtra));

        return $invoice;
    }

    private function codes(SalesInvoice $confirmed): array
    {
        return $confirmed->journalEntry->lines()->with('account')->get()
            ->map(fn ($l) => $l->account->code.($l->debit > 0 ? ' D ' : ' C ').($l->debit > 0 ? $l->debit : $l->credit))
            ->all();
    }

    public function test_a_service_invoice_posts_to_1200_74000_and_2300(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->draft($company, '1000.00', '18.00');

        $confirmed = app(SalesInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);

        $this->assertEqualsCanonicalizing(['1200 D 1180.00', '74000 C 1000.00', '2300 C 180.00'], $this->codes($confirmed));
    }

    public function test_a_goods_invoice_posts_to_74100_and_the_cost_of_goods(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create(['type' => 'product']);
        app(\App\Services\Inventory\StockMovementService::class)->receipt($item, $warehouse, '10', '60.00', '2026-02-01', User::factory()->create()->id);
        $invoice = $this->draft($company, '100.00', '18.00', ['item_id' => $item->id, 'quantity' => '5']);
        $invoice->update(['warehouse_id' => $warehouse->id]);

        $confirmed = app(SalesInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);

        $this->assertEqualsCanonicalizing(
            ['1200 D 590.00', '74100 C 500.00', '2300 C 90.00', '7010 D 300.00', '6600 C 300.00'],
            $this->codes($confirmed)
        );
    }

    public function test_the_reduced_rate_goes_to_the_reduced_accounts(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->draft($company, '200.00', '5.00');

        $confirmed = app(SalesInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);

        $this->assertEqualsCanonicalizing(['1200 D 210.00', '74001 C 200.00', '2301 C 10.00'], $this->codes($confirmed));
    }

    public function test_the_entry_keeps_group_99_the_description_and_the_labels(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->draft($company, '100.00', '18.00');

        $confirmed = app(SalesInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);
        $entry = $confirmed->journalEntry()->with('lines', 'journalGroup')->first();

        $this->assertSame('99', $entry->journalGroup->code);
        $this->assertStringStartsWith('Sales Invoice ', $entry->description);
        $this->assertTrue($entry->lines->contains(fn ($l) => str_starts_with($l->description, 'VAT on Invoice ')));
        $this->assertTrue($entry->lines->every(fn ($l) => str_contains($l->description, 'Invoice '.$confirmed->invoice_number_formatted)));
    }

    public function test_changing_the_number_still_renames_the_lines(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->draft($company, '100.00', '18.00');
        $service = app(SalesInvoiceService::class);
        $confirmed = $service->confirm($invoice->fresh(), User::factory()->create()->id);

        $service->changeNumber($confirmed, 'XYZ-9', User::factory()->create()->id);

        $this->assertTrue($confirmed->fresh()->journalEntry->lines->every(fn ($l) => str_contains($l->description, 'Invoice XYZ-9')));
    }

    public function test_a_changed_scheme_changes_the_next_posting_only(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $service = app(SalesInvoiceService::class);
        $user = User::factory()->create();
        $first = $service->confirm($this->draft($company, '100.00', '18.00')->fresh(), $user->id);
        $scheme = PostingSchemes::for($company, PostingDocType::SALES_INVOICE);
        $other = \App\Models\Account::where('company_id', $company->id)->where('code', '74001')->firstOrFail();
        $scheme->matrixAccounts()->where('matrix_key', 'revenue')->where('item_kind', 'service')->where('vat_group', 'general')->update(['account_id' => $other->id]);

        $second = $service->confirm($this->draft($company, '100.00', '18.00')->fresh(), $user->id);

        $this->assertContains('74000 C 100.00', $this->codes($first->fresh()));
        $this->assertContains('74001 C 100.00', $this->codes($second));
    }
}
```

> `changeNumber` е методот околу ред 255–300 (види потпис: `changeNumber(SalesInvoice $invoice, string $newFormattedNumber, int $userId)`; ако се вика поинаку, прилагоди го само викот). `Warehouse::factory()`, `StockMovementService::receipt(...)` потписот — види `PurchaseInvoiceService` (повик на `receipt($item, $warehouse, qty, unitCost, date, userId)`).

- [ ] **Step 2: Run — FAIL.** `php artisan test tests/Feature/Posting/SalesInvoiceSchemePostingTest.php`

- [ ] **Step 3: Implement**

Во `app/Services/Invoicing/SalesInvoiceService.php` замени го конструкторот:

```php
    public function __construct(
        private StockMovementService $stockMovementService,
        private PostingSchemeEngine $postingEngine,
    ) {}
```

(додај `use App\Services\Posting\PostingSchemeEngine; use App\Services\Posting\PostingSchemes; use App\Services\Posting\SalesInvoicePostingContext; use App\Support\Posting\PostingDocType;`). Провери дали некаде се прави `new SalesInvoiceService(`: `grep -rn "new SalesInvoiceService" app tests` — ако има, додај втор аргумент `new PostingSchemeEngine`.

Во `confirm()` замени го блокот од `// Бруто се конвертира ЕДНАШ…` (линија што почнува со `$vatRegistered = …`) до крајот на `if (bccomp($cogsTotal, '0', 2) > 0) { … }` (последниот `lines()->create` за 660) со:

```php
            $label = 'Invoice '.$formattedNumber;
            $context = SalesInvoicePostingContext::build($invoice, $formattedNumber, $cogsTotal);
            $lines = $this->postingEngine->lines(PostingSchemes::for($invoice->company, PostingDocType::SALES_INVOICE), $context);

            $entry = JournalEntry::create([
                'company_id' => $invoice->company_id,
                'journal_group_id' => $this->systemJournalGroup($invoice->company)->id,
                'entry_date' => $invoice->invoice_date,
                'description' => "Sales {$label}",
                'created_by' => $userId,
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create($line->journalColumns($invoice->invoice_date));
            }
```

`$invoice->update([...])` по него останува непроменето. Не го бриши `toMkd` и `currencyColumns` во овој чекор (`recordPayment` уште ги користи; во Task 7 се чисти `currencyColumns`).

- [ ] **Step 4: Run — новиот тест PASS.**

- [ ] **Step 5: Ажурирај ги постојните тестови (мапирање на конта)**

Пушти: `php artisan test tests/Unit/SalesInvoiceServiceTest.php tests/Feature/ForeignCurrencyInvoiceTest.php tests/Feature/SalesInvoiceShowTest.php tests/Feature/SalesInvoiceDocumentsTest.php`. Падовите се само од стари конта во проверки. Мапирање:

| Старо | Ново |
|---|---|
| `120` (побарување, при потврда) | `1200` |
| `740` (приход) | `74000` за ставка без артикл/услуга со 18%; `74100` за стока 18%; `74001`/`74101` за 5%/10% |
| `230` | `2300` (18%) или `2301` (5%/10%) |
| `701` | `7010` |
| `660` | `6600` |

Не ги менувај очекуваните **износи** — ако износ падне, значи грешка во кодот, не во тестот. Тестовите за плаќање (`recordPayment`) уште очекуваат `120` — тоа се менува во Task 7, не тука; остави ги црвени до тогаш или пушти ги заедно по Task 7.

- [ ] **Step 6: Run — PASS (освен тестови за плаќање → Task 7).**

- [ ] **Step 7: Commit**

```bash
git add app tests
git commit -m "Sales invoice posting goes through the posting scheme"
```

---

### Task 7: Уплата преку шема + изводот ја следи сметката на фактурата

**Files:**
- Create: `app/Services/Posting/SalesPaymentPostingContext.php`, `app/Services/Posting/PostedInvoiceAccounts.php`
- Modify: `app/Services/Invoicing/SalesInvoiceService.php` (`recordPayment`; бриши `currencyColumns`), `app/Services/Bank/BankStatementPoster.php`
- Test: `tests/Feature/Posting/SalesPaymentSchemePostingTest.php`; ажурирај `tests/Unit/SalesInvoiceServiceTest.php`, `tests/Feature/ForeignCurrencyInvoiceTest.php`, `tests/Feature/Bank/BankStatementPosterTest.php`

**Interfaces:**
- Produces: `PostedInvoiceAccounts::receivable(SalesInvoice $invoice): Account` — сметката на којашто фактурата го отворила побарувањето (првата ставка Должи на нејзиниот налог со партнерот на фактурата); ако фактурата нема налог → аналитичко `1200` од планот на фирмата. Така уплатата го затвора побарувањето таму каде што е отворено, и за стари фактури книжени на `120` пред шемите.
- Produces: `SalesPaymentPostingContext::build(SalesInvoice $invoice, string $amountMkd, string $amountForeign, bool $cash, string $label, Account $receivable): PostingContext` (променлива `ИЗНОС`; знаме `cash`; `foreignTotals['ИЗНОС']` = `$amountForeign`; `foreign` само за девизна фактура; `invoiceAccount` = `$receivable`; `documentLabel` = `$label`).
- `recordPayment`: телескопирањето (`paidBefore`, `toMkd`) останува; ставките од шемата `SALES_PAYMENT`; описот е `Payment for invoice {број}` (ставен како `documentLabel`).
- `BankStatementPoster`: за уплата (`IN`) спротивното конто е `PostedInvoiceAccounts::receivable($invoice)` наместо `120`. Влезни фактури (`220`) не се менуваат.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Posting;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use App\Services\Posting\PostedInvoiceAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesPaymentSchemePostingTest extends TestCase
{
    use RefreshDatabase;

    private function confirmed(Company $company, string $price = '100.00', array $invoiceAttrs = [], string $vatRate = '0'): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(array_merge(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01'], $invoiceAttrs));
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => $price, 'vat_rate' => $vatRate]);

        return app(SalesInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);
    }

    private function paymentEntry(SalesInvoice $invoice): JournalEntry
    {
        return JournalEntry::where('company_id', $invoice->company_id)->where('id', '!=', $invoice->journal_entry_id)->with('lines.account')->latest('id')->firstOrFail();
    }

    public function test_a_bank_payment_posts_1000_against_the_receivable_1200(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->confirmed($company);

        app(SalesInvoiceService::class)->recordPayment($invoice, '60.00', '2026-03-10', 'bank', User::factory()->create()->id);

        $entry = $this->paymentEntry($invoice);
        $this->assertSame('60.00', (string) $entry->lines->firstWhere('account.code', '1000')->debit);
        $this->assertSame('60.00', (string) $entry->lines->firstWhere('account.code', '1200')->credit);
        $this->assertSame('Payment for invoice '.$invoice->invoice_number_formatted, $entry->description);
        $this->assertTrue($entry->lines->every(fn ($l) => $l->partner_id === $invoice->partner_id));
    }

    public function test_a_cash_payment_posts_to_1020(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->confirmed($company);

        app(SalesInvoiceService::class)->recordPayment($invoice, '100.00', '2026-03-10', 'cash', User::factory()->create()->id);

        $this->assertNotNull($this->paymentEntry($invoice)->lines->firstWhere('account.code', '1020'));
    }

    public function test_a_payment_on_an_invoice_booked_on_120_before_the_schemes_closes_120(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $legacy = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-01-10', 'status' => 'confirmed', 'fiscal_year' => 2026, 'invoice_number' => 1, 'invoice_number_formatted' => '2026/1']);
        $legacy->lines()->create(['description' => 'Стара', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $group = JournalGroup::firstOrCreate(['company_id' => $company->id, 'code' => '99'], ['name' => 'Автоматски', 'sort_order' => 99]);
        $entry = JournalEntry::create(['company_id' => $company->id, 'journal_group_id' => $group->id, 'entry_date' => '2026-01-10', 'description' => 'Sales Invoice 2026/1', 'created_by' => User::factory()->create()->id]);
        $entry->lines()->create(['account_id' => Account::where('company_id', $company->id)->where('code', '120')->value('id'), 'partner_id' => $partner->id, 'line_date' => '2026-01-10', 'debit' => '100.00', 'credit' => '0', 'description' => 'Invoice 2026/1']);
        $entry->lines()->create(['account_id' => Account::where('company_id', $company->id)->where('code', '740')->value('id'), 'partner_id' => $partner->id, 'line_date' => '2026-01-10', 'debit' => '0', 'credit' => '100.00', 'description' => 'Invoice 2026/1']);
        $legacy->update(['journal_entry_id' => $entry->id]);

        $this->assertSame('120', PostedInvoiceAccounts::receivable($legacy->fresh())->code);

        app(SalesInvoiceService::class)->recordPayment($legacy->fresh(), '100.00', '2026-02-01', 'bank', User::factory()->create()->id);

        $payment = $this->paymentEntry($legacy);
        $this->assertSame('100.00', (string) $payment->lines->firstWhere('account.code', '120')->credit);
        $this->assertNull($payment->lines->firstWhere('account.code', '1200'));
    }

    public function test_a_draft_invoice_falls_back_to_the_scheme_receivable(): void
    {
        $company = Company::factory()->create();
        $draft = SalesInvoice::factory()->for($company)->create();

        $this->assertSame('1200', PostedInvoiceAccounts::receivable($draft)->code);
    }

    public function test_a_foreign_invoice_still_closes_to_exactly_zero_in_instalments(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->confirmed($company, '33.33', ['currency' => 'EUR', 'exchange_rate' => '61.512345'], '18.00')->fresh(['lines', 'payments']);
        $service = app(SalesInvoiceService::class);
        $user = User::factory()->create();
        $total = $invoice->grandTotal();
        $first = bcdiv($total, '3', 2);
        $second = bcdiv($total, '3', 2);
        $service->recordPayment($invoice, $first, '2026-03-10', 'bank', $user->id);
        $service->recordPayment($invoice->fresh(['lines', 'payments']), $second, '2026-03-11', 'bank', $user->id);
        $service->recordPayment($invoice->fresh(['lines', 'payments']), bcsub(bcsub($total, $first, 2), $second, 2), '2026-03-12', 'bank', $user->id);

        $receivable = PostedInvoiceAccounts::receivable($invoice->fresh());
        $lines = \App\Models\JournalEntryLine::where('account_id', $receivable->id)->get();
        $balance = $lines->reduce(fn ($c, $l) => bcsub(bcadd($c, (string) $l->debit, 2), (string) $l->credit, 2), '0.00');

        $this->assertSame('0.00', $balance);
        $this->assertSame('EUR', $lines->last()->currency_code);
    }
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement**

`app/Services/Posting/PostedInvoiceAccounts.php`:

```php
<?php

namespace App\Services\Posting;

use App\Models\Account;
use App\Models\SalesInvoice;

/**
 * На која сметка е отворено побарувањето на фактурата. Уплатата го затвора
 * ТАМУ — така стара фактура книжена на 120 пред шемите се затвора на 120, а
 * нова на 1200, без поделено салдо меѓу две конта.
 */
class PostedInvoiceAccounts
{
    public static function receivable(SalesInvoice $invoice): Account
    {
        $entry = $invoice->journalEntry()->with('lines.account')->first();

        $line = $entry?->lines
            ->first(fn ($l) => bccomp((string) $l->debit, '0', 2) > 0 && $l->partner_id === $invoice->partner_id);

        if ($line?->account !== null) {
            return $line->account;
        }

        return Account::where('company_id', $invoice->company_id)->analytical()->where('code', '1200')->firstOrFail();
    }
}
```

`app/Services/Posting/SalesPaymentPostingContext.php`:

```php
<?php

namespace App\Services\Posting;

use App\Models\Account;
use App\Models\SalesInvoice;
use App\Support\Posting\PostingContext;

/** Износи на една уплата од купувач за шемата SALES_PAYMENT. */
final class SalesPaymentPostingContext
{
    public static function build(SalesInvoice $invoice, string $amountMkd, string $amountForeign, bool $cash, string $label, Account $receivable): PostingContext
    {
        return new PostingContext(
            totals: ['ИЗНОС' => $amountMkd],
            foreignTotals: ['ИЗНОС' => $amountForeign],
            flags: ['has_goods' => false, 'cash' => $cash, 'import' => false],
            partnerId: $invoice->partner_id,
            documentLabel: $label,
            foreign: $invoice->isForeignCurrency()
                ? ['currency_code' => $invoice->currency, 'exchange_rate' => (string) $invoice->exchange_rate]
                : null,
            invoiceAccount: $receivable,
        );
    }
}
```

Во `SalesInvoiceService::recordPayment` замени го делот од `$cashOrBankCode = …` до вториот `$entry->lines()->create(…)` (за `120`) со:

```php
            $label = "Payment for invoice {$invoice->formattedNumber()}";

            $entry = JournalEntry::create([
                'company_id' => $invoice->company_id,
                'journal_group_id' => $this->systemJournalGroup($invoice->company)->id,
                'entry_date' => $paymentDate,
                'description' => $label,
                'created_by' => $userId,
            ]);

            $context = SalesPaymentPostingContext::build(
                $invoice,
                $amountMkd,
                $amount,
                $paymentMethod === 'cash',
                $label,
                PostedInvoiceAccounts::receivable($invoice),
            );

            foreach ($this->postingEngine->lines(PostingSchemes::for($invoice->company, PostingDocType::SALES_PAYMENT), $context) as $line) {
                $entry->lines()->create($line->journalColumns($paymentDate));
            }
```

Бриши ја приватната метода `currencyColumns` (веќе не се користи — провери со `grep -n currencyColumns app`). Додај `use App\Services\Posting\PostedInvoiceAccounts; use App\Services\Posting\SalesPaymentPostingContext;`.

Во `app/Services/Bank/BankStatementPoster.php` во `postLine`, за гранката `INVOICE_PAYMENT`: `$counter = $this->account($statement, $isIn ? '120' : '220');` замени со:

```php
            $counter = $isIn
                ? PostedInvoiceAccounts::receivable($invoice)
                : $this->account($statement, '220');
```

(додај `use App\Services\Posting\PostedInvoiceAccounts;`; `$invoice` е веќе свежиот модел од таа гранка.)

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Feature/Posting/SalesPaymentSchemePostingTest.php`

- [ ] **Step 5: Ажурирај ги постојните тестови**

Пушти: `php artisan test tests/Unit/SalesInvoiceServiceTest.php tests/Feature/ForeignCurrencyInvoiceTest.php tests/Feature/Bank/BankStatementPosterTest.php tests/Feature/Bank/BankPaymentAccountTest.php tests/Feature/Bank/CreatePaymentRecordTest.php tests/Feature/SalesInvoiceShowTest.php`. Мапирање за плаќања: `120` → `1200` (за фактури потврдени преку сервисот), готово `102` → `1020`. `BankPaymentAccountTest` бара `1000` и без `100` — остануваат. Износите не се менуваат.

- [ ] **Step 6: Commit**

```bash
git add app tests
git commit -m "Sales payments go through the scheme and follow the invoice's receivable account"
```

---

### Task 8: Документација, целосна серија, спојување

**Files:**
- Modify: `docs/superpowers/specs/2026-10-06-posting-schemes-design.md` (измени подолу)
- Create: `docs/superpowers/2026-10-06-posting-schemes-part1-log.md`

- [ ] **Step 1: Измени на спецификацијата** (запиши ги во „Одстапувања при градењето“ на крајот од спецификацијата):
  1. Видови документи: `sales_invoice`, `sales_payment`, `purchase_invoice`, `purchase_payment` (наместо еден `invoice_payment` со услови `incoming/outgoing`).
  2. Услови: `has_goods`, `import`, `cash` и нивните `not_*` (нема `incoming/outgoing`).
  3. Начин на конто `invoice`: сметката на која документот-фактура го отворил побарувањето/обврската (за уплатите).
  4. Петта даночна група `export` (приход 7421 стоки / 7423 услуги).
  5. Негативен износ на ред ја менува страната (не се чува негативен износ).

- [ ] **Step 2: Лог** `docs/superpowers/2026-10-06-posting-schemes-part1-log.md`: што е направено (8 задачи), одлуки, што е ненаправено (дел 2–4), мапирање на конта (120→1200, 740→74000/74100…), **препорака пред пуштање:** постоечки фирми имаат фактури на 120/740/230 — пробниот биланс покажува старите и новите конта по синтетички родител (исправно); новите уплати на старите фактури одат на 120 (затворање таму).

- [ ] **Step 3: Целосна серија** — **прво прашај го корисникот** („пуштам целосна серија, ~14 мин“), па `php artisan test` (на гранката). Очекувано: сè зелено, 3 прескокнати.

- [ ] **Step 4: Commit, спој и пушти** (стандардно правило на корисникот: зелена серија → спој во `main` и пушти, без прашање за спојот):

```bash
git add docs
git commit -m "Docs: posting schemes part 1 spec amendments and log"
git checkout main && git merge --no-ff posting-schemes-part1 -m "Merge posting schemes part 1: engine, sales invoice and payment" && git push origin main
```

CI го следи ccd_pr/`gh run list` еднаш. Сними во меморија што е готово и што останува (дел 2).
