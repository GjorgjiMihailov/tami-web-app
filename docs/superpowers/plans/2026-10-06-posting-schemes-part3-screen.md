# Шеми за книжење, дел 3: екран за менување и пробно книжење — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сметководител (и админ) на фирмата да ги гледа шемите за книжење, да ги менува (редови, формули, конта, матрици), да ги пробува врз постоечка фактура без запис и да ги враќа на предложено — со проверка што не дозволува шема што не балансира.

**Architecture:** Шемите и моторот од дел 1–2 остануваат. Се додаваат: `PostingVocabulary` (променливи, услови, начини, матрици по вид документ), `PostingSampleContexts` (готови пробни документи), `PostingSchemeEditor` (работна копија → проверка → запис во трансакција; „врати на предложено“), `PostingSchemeTrial` (пробно книжење врз вистинска фактура) и два Livewire екрана (список и уредување). Менувањето работи на **работна копија во меморија** и се запишува одеднаш со „Зачувај“ — така меѓучекорите не мора да балансираат.

**Tech Stack:** Laravel 13, PHP 8.3, Livewire, Blade/Tailwind, bcmath (низи), SQLite (тест) / MySQL.

Спецификација: `docs/superpowers/specs/2026-10-06-posting-schemes-design.md` (разделот „Екран“ + „Проверки при зачувување“) и логовите `…-part1-log.md`, `…-part2-log.md`. Дел 1–2 се во `main` (8d96835).

## Global Constraints

- Македонски текстови за корисник (строг македонски); коментари во кодот — македонски.
- Пари: bcmath низи; никогаш float.
- Екранот е за админ и сметководител на фирмата: рутата е во групата со `EnsureAccountingAccess`, а компонентите прават `Gate::authorize('update', $company)` (сметководител само за своја фирма). Клиентот (internal_client) добива 403.
- Промена на шема важи само за нови документи; веќе книжените налози не се допираат. Пробното книжење НИКОГАШ не запишува.
- Шемата се запишува само ако поминат проверките: парсира, познати променливи за видот документ, дозволен начин/услов/матрица за видот, конто постои во фирмата + аналитичко + активно, матрична ќелија дозволена, **збирот должи = побарува на сите пробни документи**.
- Начин на конто `line` и `invoice` не бараат конто (сметката доаѓа од документот).
- Имиња на Livewire методи: не користи резервирани (`upload`, `get`, `set`, `call`, …) — користи `saveScheme`, `restoreDefault`, `runTrial` итн.; во проектот има гард-тест.
- MySQL: нови табели нема во овој дел.
- Тестови: `php artisan test <датотека>` само за датотеката што ја менуваш. **Целата серија се пушта еднаш на крај и прво се прашува корисникот.** Не користи `python`; не користи `sed` за додавање `use` редови — користи Edit. Не слепувај код од овој план со `sed` преку ограда ``` — земи точни редови и провери `php -l` по секое слепување.
- Кон секој commit: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Работи на гранка `posting-schemes-part3`, не на `main`.

## Работна копија (формат)

Редови: `list<array{account_mode: string, account_code: ?string, matrix_key: ?string, side: string, formula: string, with_partner: bool, description: ?string, condition: ?string}>`.
Матрица: `list<array{matrix_key: string, item_kind: ?string, vat_group: string, account_code: string}>`.
Контото се бира со **шифра** (текст), не со id — ги нема ни илјадниците опции во листа; екранот го покажува името под шифрата.

## Мапа на датотеки

Нови:
- `app/Support/Posting/PostingVocabulary.php`
- `app/Services/Posting/{PostingSampleContexts,PostingSchemeEditor,PostingSchemeTrial}.php`
- `app/Livewire/Accounting/{PostingSchemeIndex,PostingSchemeEdit}.php`
- `resources/views/livewire/accounting/{posting-scheme-index,posting-scheme-edit}.blade.php`
- `tests/Unit/PostingVocabularyTest.php`, `tests/Feature/Posting/{PostingSampleContextsTest,PostingSchemeEditorTest,PostingSchemeTrialTest,PostingSchemeScreenTest}.php`

Менувани: `app/Services/Posting/DefaultPostingSchemes.php` (издвојува `populate`), `app/Support/Menu.php` (ставка), `routes/web.php` (две рути), `tests/Feature/AccountingAccessTest.php` (две листи).

---

### Task 1: Речник (променливи, услови, начини, матрици)

**Files:**
- Create: `app/Support/Posting/PostingVocabulary.php`
- Test: `tests/Unit/PostingVocabularyTest.php`

**Interfaces:**
- Produces (сите статични, за `PostingDocType $type`):
  - `variables($type): array<string,string>` — име → објаснување (променливи на документот).
  - `matrixVariables(): array<string,string>` — `ОСНОВИЦА`, `ДДВ`, `ВКУПНО` (по кришка, само кај ред со матрица).
  - `lineVariable(): string` = `'ТРОШОК_СТАВКА'` (само кај ред со начин `line`).
  - `allowedVariables($type, string $mode): list<string>` — имиња дозволени во формула на ред со тој начин.
  - `modes($type): array<string,string>` — `fixed|matrix|line|invoice` → назив.
  - `conditions($type): array<string,string>` — вредност → назив (вклучува `not_` форми).
  - `matrices($type): array<string,string>` — `PostingMatrix::*` → назив.
  - `matrixCells(string $matrixKey): list<array{matrix_key: string, item_kind: ?string, vat_group: string, label: string}>`.

- [ ] **Step 1: Write the failing test** `tests/Unit/PostingVocabularyTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\Posting\PostingDocType;
use App\Support\Posting\PostingMatrix;
use App\Support\Posting\PostingVocabulary;
use PHPUnit\Framework\TestCase;

class PostingVocabularyTest extends TestCase
{
    public function test_every_document_type_has_variables_and_modes(): void
    {
        foreach (PostingDocType::cases() as $type) {
            $this->assertNotEmpty(PostingVocabulary::variables($type), $type->value);
            $this->assertNotEmpty(PostingVocabulary::modes($type), $type->value);
        }
    }

    public function test_the_sales_invoice_variables_and_modes(): void
    {
        $type = PostingDocType::SALES_INVOICE;

        $this->assertSame(['ВКУПНО', 'ОСНОВИЦА', 'ДДВ', 'НАБАВНА_ВРЕДНОСТ'], array_keys(PostingVocabulary::variables($type)));
        $this->assertSame(['fixed', 'matrix'], array_keys(PostingVocabulary::modes($type)));
        $this->assertSame([PostingMatrix::REVENUE, PostingMatrix::OUTPUT_VAT], array_keys(PostingVocabulary::matrices($type)));
    }

    public function test_allowed_variables_depend_on_the_row_mode(): void
    {
        $purchase = PostingDocType::PURCHASE_INVOICE;

        $this->assertNotContains('ТРОШОК_СТАВКА', PostingVocabulary::allowedVariables(PostingDocType::SALES_INVOICE, 'fixed'));
        $this->assertContains('ТРОШОК_СТАВКА', PostingVocabulary::allowedVariables($purchase, 'line'));
        $this->assertContains('ДДВ', PostingVocabulary::allowedVariables($purchase, 'matrix'));
        $this->assertContains('ЗАЛИХА', PostingVocabulary::allowedVariables($purchase, 'fixed'));
        $this->assertSame(['ИЗНОС'], PostingVocabulary::allowedVariables(PostingDocType::SALES_PAYMENT, 'invoice'));
    }

    public function test_payments_have_the_cash_condition_and_the_not_forms(): void
    {
        $conditions = PostingVocabulary::conditions(PostingDocType::PURCHASE_PAYMENT);

        $this->assertArrayHasKey('cash', $conditions);
        $this->assertArrayHasKey('not_cash', $conditions);
        $this->assertArrayNotHasKey('import', $conditions);
    }

    public function test_the_matrix_cells(): void
    {
        $revenue = PostingVocabulary::matrixCells(PostingMatrix::REVENUE);
        $vat = PostingVocabulary::matrixCells(PostingMatrix::INPUT_VAT);

        $this->assertCount(10, $revenue); // 2 вида × 5 групи
        $this->assertSame(['goods', 'service'], array_values(array_unique(array_column($revenue, 'item_kind'))));
        $this->assertCount(2, $vat);
        $this->assertNull($vat[0]['item_kind']);
        $this->assertSame('general', $vat[0]['vat_group']);
    }
}
```

- [ ] **Step 2: Run — FAIL.** `php artisan test tests/Unit/PostingVocabularyTest.php`

- [ ] **Step 3: Implement** `app/Support/Posting/PostingVocabulary.php`:

```php
<?php

namespace App\Support\Posting;

/**
 * Што смее да стои во шема за кој вид документ: променливи, услови, начини на
 * конто и матрици. Екранот ги прикажува како помош, а проверката на шема ги
 * користи како дозволена листа — формулата не може да се повика на променлива
 * што документот не ја дава.
 */
final class PostingVocabulary
{
    /** @return array<string, string> */
    public static function variables(PostingDocType $type): array
    {
        return match ($type) {
            PostingDocType::SALES_INVOICE => [
                'ВКУПНО' => 'Вкупно со ДДВ, во денари',
                'ОСНОВИЦА' => 'Вкупно без ДДВ (приход)',
                'ДДВ' => 'Вкупен ДДВ',
                'НАБАВНА_ВРЕДНОСТ' => 'Набавна вредност на продадената стока (од залиха)',
            ],
            PostingDocType::PURCHASE_INVOICE => [
                'ВКУПНО' => 'Вкупно за плаќање (обврската кон добавувачот)',
                'ЗАЛИХА' => 'Вредност на ставките со артикл од залиха',
                'ТРОШОК_СТАВКА' => 'Трошок на ставките што не се залиха (по сметка од ставката)',
                'ОДБИВЛИВ_ДДВ' => 'Одбивлив влезен ДДВ',
            ],
            PostingDocType::SALES_PAYMENT, PostingDocType::PURCHASE_PAYMENT => [
                'ИЗНОС' => 'Износ на уплатата/исплатата, во денари',
            ],
        };
    }

    /** @return array<string, string> */
    public static function matrixVariables(): array
    {
        return [
            'ОСНОВИЦА' => 'Основица на кришката (вид × даночна група)',
            'ДДВ' => 'ДДВ на кришката',
            'ВКУПНО' => 'Основица + ДДВ на кришката',
        ];
    }

    public static function lineVariable(): string
    {
        return 'ТРОШОК_СТАВКА';
    }

    /** @return list<string> */
    public static function allowedVariables(PostingDocType $type, string $mode): array
    {
        $names = array_keys(self::variables($type));

        if ($mode === 'matrix') {
            $names = array_merge($names, array_keys(self::matrixVariables()));
        }

        if ($mode === 'line') {
            $names[] = self::lineVariable();
        }

        return array_values(array_unique($names));
    }

    /** @return array<string, string> */
    public static function modes(PostingDocType $type): array
    {
        return match ($type) {
            PostingDocType::SALES_INVOICE => ['fixed' => 'Точно конто', 'matrix' => 'Конто од матрица'],
            PostingDocType::PURCHASE_INVOICE => ['fixed' => 'Точно конто', 'matrix' => 'Конто од матрица', 'line' => 'Сметка од ставката'],
            PostingDocType::SALES_PAYMENT, PostingDocType::PURCHASE_PAYMENT => ['fixed' => 'Точно конто', 'invoice' => 'Сметка на фактурата'],
        };
    }

    /** @return array<string, string> */
    public static function conditions(PostingDocType $type): array
    {
        $pairs = match ($type) {
            PostingDocType::SALES_INVOICE => ['has_goods' => 'има стока од залиха'],
            PostingDocType::PURCHASE_INVOICE => ['has_goods' => 'има стока од залиха', 'import' => 'увозна фактура'],
            PostingDocType::SALES_PAYMENT, PostingDocType::PURCHASE_PAYMENT => ['cash' => 'готовинска уплата/исплата'],
        };

        $conditions = [];

        foreach ($pairs as $key => $label) {
            $conditions[$key] = 'Само ако: '.$label;
            $conditions['not_'.$key] = 'Само ако НЕ: '.$label;
        }

        return $conditions;
    }

    /** @return array<string, string> */
    public static function matrices(PostingDocType $type): array
    {
        return match ($type) {
            PostingDocType::SALES_INVOICE => [
                PostingMatrix::REVENUE => 'Приход (вид × даночна група)',
                PostingMatrix::OUTPUT_VAT => 'Излезен ДДВ (даночна група)',
            ],
            PostingDocType::PURCHASE_INVOICE => [PostingMatrix::INPUT_VAT => 'Влезен ДДВ (даночна група)'],
            default => [],
        };
    }

    /** @return list<array{matrix_key: string, item_kind: ?string, vat_group: string, label: string}> */
    public static function matrixCells(string $matrixKey): array
    {
        $cells = [];

        if (PostingMatrix::byKind($matrixKey)) {
            foreach (ItemKind::cases() as $kind) {
                foreach (VatGroup::cases() as $group) {
                    $cells[] = ['matrix_key' => $matrixKey, 'item_kind' => $kind->value, 'vat_group' => $group->value, 'label' => $kind->label().' — '.$group->label()];
                }
            }

            return $cells;
        }

        foreach ([VatGroup::GENERAL, VatGroup::REDUCED] as $group) {
            $cells[] = ['matrix_key' => $matrixKey, 'item_kind' => null, 'vat_group' => $group->value, 'label' => $group->label()];
        }

        return $cells;
    }
}
```

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Unit/PostingVocabularyTest.php`

- [ ] **Step 5: Commit**

```bash
git checkout -b posting-schemes-part3
git add app tests docs
git commit -m "Posting schemes: vocabulary of variables, conditions, modes and matrices; plan for part 3"
```

---

### Task 2: Пробни документи

**Files:**
- Create: `app/Services/Posting/PostingSampleContexts.php`
- Test: `tests/Feature/Posting/PostingSampleContextsTest.php`

**Interfaces:**
- Produces: `PostingSampleContexts::for(PostingDocType $type, Company $company): array<string, PostingContext>` — име на пробен документ → контекст. Сметките за `accountBuckets` и `invoiceAccount` се вистински аналитички конта од фирмата (трошок: прво активно аналитичко со шифра на `4`; фактура: `1200` за продажба, `2200` за набавка, резерва — првото аналитичко).
- Правило: стандардните шеми мора да балансираат на СИТЕ пробни документи (тестот го докажува).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Posting;

use App\Models\Company;
use App\Services\Posting\PostingSampleContexts;
use App\Services\Posting\PostingSchemeEngine;
use App\Services\Posting\PostingSchemes;
use App\Support\Posting\PostingDocType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostingSampleContextsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_type_has_several_samples(): void
    {
        $company = Company::factory()->create();

        $this->assertGreaterThanOrEqual(4, count(PostingSampleContexts::for(PostingDocType::SALES_INVOICE, $company)));
        $this->assertGreaterThanOrEqual(4, count(PostingSampleContexts::for(PostingDocType::PURCHASE_INVOICE, $company)));
        $this->assertCount(2, PostingSampleContexts::for(PostingDocType::SALES_PAYMENT, $company));
        $this->assertCount(2, PostingSampleContexts::for(PostingDocType::PURCHASE_PAYMENT, $company));
    }

    public function test_the_default_schemes_balance_on_every_sample(): void
    {
        $company = Company::factory()->create();
        $engine = new PostingSchemeEngine;

        foreach (PostingDocType::cases() as $type) {
            $scheme = PostingSchemes::for($company, $type);

            foreach (PostingSampleContexts::for($type, $company) as $name => $context) {
                $lines = $engine->lines($scheme, $context);

                $this->assertNotEmpty($lines, "{$type->value}: {$name}");
            }
        }
    }

    public function test_the_samples_use_real_analytical_accounts_of_the_company(): void
    {
        $company = Company::factory()->create();

        $purchase = PostingSampleContexts::for(PostingDocType::PURCHASE_INVOICE, $company);
        $bucket = reset($purchase)->accountBuckets[0]['account'];
        $payment = PostingSampleContexts::for(PostingDocType::SALES_PAYMENT, $company);

        $this->assertSame($company->id, $bucket->company_id);
        $this->assertTrue($bucket->is_analytical);
        $this->assertSame('1200', reset($payment)->invoiceAccount->code);
    }
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement** `app/Services/Posting/PostingSampleContexts.php`:

```php
<?php

namespace App\Services\Posting;

use App\Models\Account;
use App\Models\Company;
use App\Support\Posting\ItemKind;
use App\Support\Posting\PostingContext;
use App\Support\Posting\PostingDocType;
use App\Support\Posting\PostingSlice;
use App\Support\Posting\VatGroup;

/**
 * Готови пробни документи за проверка на шема: типични случаи (стока/услуга,
 * 18%/5%/извоз/ослободено, готово, увоз). Шема се зачувува само ако
 * балансира на сите нив. Износите се измислени, но внатрешно усогласени
 * (вкупно = основица + ДДВ).
 */
final class PostingSampleContexts
{
    /** @return array<string, PostingContext> */
    public static function for(PostingDocType $type, Company $company): array
    {
        return match ($type) {
            PostingDocType::SALES_INVOICE => self::salesInvoice(),
            PostingDocType::PURCHASE_INVOICE => self::purchaseInvoice($company),
            PostingDocType::SALES_PAYMENT => self::payment($company, '1200', 'Payment for invoice 1'),
            PostingDocType::PURCHASE_PAYMENT => self::payment($company, '2200', 'Payment for purchase bill X #1'),
        };
    }

    /** @return array<string, PostingContext> */
    private static function salesInvoice(): array
    {
        $make = fn (array $totals, array $slices, bool $goods) => new PostingContext(
            totals: $totals,
            slices: $slices,
            flags: ['has_goods' => $goods, 'cash' => false, 'import' => false],
            partnerId: 1,
            documentLabel: 'Invoice 1',
        );

        return [
            'Услуга со 18% ДДВ' => $make(
                ['ВКУПНО' => '1180.00', 'ОСНОВИЦА' => '1000.00', 'ДДВ' => '180.00', 'НАБАВНА_ВРЕДНОСТ' => '0.00'],
                [new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '1000.00', '180.00')],
                false
            ),
            'Стока со 18% ДДВ и залиха' => $make(
                ['ВКУПНО' => '1180.00', 'ОСНОВИЦА' => '1000.00', 'ДДВ' => '180.00', 'НАБАВНА_ВРЕДНОСТ' => '600.00'],
                [new PostingSlice(ItemKind::GOODS, VatGroup::GENERAL, '1000.00', '180.00')],
                true
            ),
            'Мешана фактура (5%, 18%, извоз)' => $make(
                ['ВКУПНО' => '614.00', 'ОСНОВИЦА' => '550.00', 'ДДВ' => '64.00', 'НАБАВНА_ВРЕДНОСТ' => '120.00'],
                [
                    new PostingSlice(ItemKind::GOODS, VatGroup::REDUCED, '200.00', '10.00'),
                    new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '300.00', '54.00'),
                    new PostingSlice(ItemKind::SERVICE, VatGroup::EXPORT, '50.00', '0.00'),
                ],
                true
            ),
            'Ослободено од ДДВ' => $make(
                ['ВКУПНО' => '100.00', 'ОСНОВИЦА' => '100.00', 'ДДВ' => '0.00', 'НАБАВНА_ВРЕДНОСТ' => '0.00'],
                [
                    new PostingSlice(ItemKind::GOODS, VatGroup::EXEMPT_WITH_CREDIT, '70.00', '0.00'),
                    new PostingSlice(ItemKind::SERVICE, VatGroup::EXEMPT_WITHOUT_CREDIT, '30.00', '0.00'),
                ],
                false
            ),
        ];
    }

    /** @return array<string, PostingContext> */
    private static function purchaseInvoice(Company $company): array
    {
        $expense = self::anyAccount($company, 'expense');

        $make = fn (array $totals, array $slices, array $buckets, bool $goods, bool $import) => new PostingContext(
            totals: $totals,
            slices: $slices,
            flags: ['has_goods' => $goods, 'cash' => false, 'import' => $import],
            partnerId: 1,
            documentLabel: 'Purchase bill X #1',
            accountBuckets: array_map(fn (string $amount) => ['account' => $expense, 'amount' => $amount], $buckets),
        );

        return [
            'Трошок со 18% ДДВ' => $make(
                ['ВКУПНО' => '1180.00', 'ЗАЛИХА' => '0.00', 'ТРОШОК_СТАВКА' => '1000.00', 'ОДБИВЛИВ_ДДВ' => '180.00'],
                [new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '1000.00', '180.00')],
                ['1000.00'], false, false
            ),
            'Домашна стока со 5% ДДВ' => $make(
                ['ВКУПНО' => '525.00', 'ЗАЛИХА' => '500.00', 'ТРОШОК_СТАВКА' => '0.00', 'ОДБИВЛИВ_ДДВ' => '25.00'],
                [new PostingSlice(ItemKind::GOODS, VatGroup::REDUCED, '500.00', '25.00')],
                [], true, false
            ),
            'Увозна стока и транспорт' => $make(
                ['ВКУПНО' => '618.00', 'ЗАЛИХА' => '500.00', 'ТРОШОК_СТАВКА' => '100.00', 'ОДБИВЛИВ_ДДВ' => '18.00'],
                [new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '100.00', '18.00')],
                ['100.00'], true, true
            ),
            'Неодбивлив ДДВ' => $make(
                ['ВКУПНО' => '1180.00', 'ЗАЛИХА' => '0.00', 'ТРОШОК_СТАВКА' => '1180.00', 'ОДБИВЛИВ_ДДВ' => '0.00'],
                [],
                ['1180.00'], false, false
            ),
        ];
    }

    /** @return array<string, PostingContext> */
    private static function payment(Company $company, string $code, string $label): array
    {
        $account = self::anyAccount($company, $code);

        $make = fn (bool $cash) => new PostingContext(
            totals: ['ИЗНОС' => '100.00'],
            flags: ['has_goods' => false, 'cash' => $cash, 'import' => false],
            partnerId: 1,
            documentLabel: $label,
            invoiceAccount: $account,
        );

        return ['Плаќање преку банка' => $make(false), 'Плаќање во готово' => $make(true)];
    }

    /** Вистинско аналитичко конто на фирмата: по шифра, или (трошок) првото на 4, или кое било. */
    private static function anyAccount(Company $company, string $codeOrExpense): Account
    {
        $base = Account::where('company_id', $company->id)->analytical()->where('is_active', true)->orderBy('code');

        $found = $codeOrExpense === 'expense'
            ? (clone $base)->where('code', 'like', '4%')->first()
            : (clone $base)->where('code', $codeOrExpense)->first();

        return $found ?? $base->firstOrFail();
    }
}
```

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Feature/Posting/PostingSampleContextsTest.php`

- [ ] **Step 5: Commit**

```bash
git add app tests
git commit -m "Posting schemes: sample documents for validating a scheme"
```

---

### Task 3: Уредник — работна копија, проверка, запис, „врати на предложено“

**Files:**
- Modify: `app/Services/Posting/DefaultPostingSchemes.php` (издвој `populate`)
- Create: `app/Services/Posting/PostingSchemeEditor.php`
- Test: `tests/Feature/Posting/PostingSchemeEditorTest.php`

**Interfaces:**
- Produces: `DefaultPostingSchemes::populate(PostingScheme $scheme, Company $company, array $definition): void` (создава редови и матрични сметки од дефиниција).
- Produces (`PostingSchemeEditor`, без состојба, `app(PostingSchemeEditor::class)`):
  - `draftOf(PostingScheme $scheme): array{rows: list<array>, matrix: list<array>}` — работна копија (формат погоре; `account_code` од релацијата).
  - `validate(Company $company, PostingDocType $type, string $name, array $rows, array $matrix): list<string>` — македонски пораки; празна листа = ОК.
  - `save(PostingScheme $scheme, array $rows, array $matrix): list<string>` — ако `validate` врати грешки ги враќа и не запишува; инаку во трансакција ги заменува редовите и матричните сметки (position = индекс + 1) и враќа `[]`.
  - `resetToDefault(PostingScheme $scheme): void` — во трансакција ги заменува со стандардната дефиниција.
  - `transientScheme(Company $company, PostingDocType $type, string $name, array $rows, array $matrix): PostingScheme` — незапишана шема за мотор/пробно (контата се решени по шифра; непозната шифра → релација `null`).

- [ ] **Step 1: Write the failing test** `tests/Feature/Posting/PostingSchemeEditorTest.php`:

```php
<?php

namespace Tests\Feature\Posting;

use App\Models\Company;
use App\Models\PostingScheme;
use App\Services\Posting\PostingSchemeEditor;
use App\Services\Posting\PostingSchemes;
use App\Support\Posting\PostingDocType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostingSchemeEditorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private PostingSchemeEditor $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->editor = app(PostingSchemeEditor::class);
    }

    private function scheme(PostingDocType $type = PostingDocType::SALES_INVOICE): PostingScheme
    {
        return PostingSchemes::for($this->company, $type);
    }

    public function test_the_draft_of_the_default_scheme_has_codes_and_validates_clean(): void
    {
        $scheme = $this->scheme();

        $draft = $this->editor->draftOf($scheme);

        $this->assertCount(5, $draft['rows']);
        $this->assertSame('1200', $draft['rows'][0]['account_code']);
        $this->assertSame('ВКУПНО', $draft['rows'][0]['formula']);
        $this->assertTrue($draft['rows'][0]['with_partner']);
        $this->assertCount(12, $draft['matrix']);
        $this->assertSame([], $this->editor->validate($this->company, PostingDocType::SALES_INVOICE, $scheme->name, $draft['rows'], $draft['matrix']));
    }

    public function test_every_default_scheme_validates_clean(): void
    {
        foreach (PostingDocType::cases() as $type) {
            $scheme = $this->scheme($type);
            $draft = $this->editor->draftOf($scheme);

            $this->assertSame([], $this->editor->validate($this->company, $type, $scheme->name, $draft['rows'], $draft['matrix']), $type->value);
        }
    }

    public function test_an_unknown_variable_and_bad_syntax_are_reported_with_the_row_number(): void
    {
        $draft = $this->editor->draftOf($this->scheme());
        $draft['rows'][0]['formula'] = 'ВКУПНО + ЦАРИНА';
        $draft['rows'][1]['formula'] = 'ОСНОВИЦА +';

        $errors = implode(' | ', $this->editor->validate($this->company, PostingDocType::SALES_INVOICE, 'x', $draft['rows'], $draft['matrix']));

        $this->assertStringContainsString('Ред 1', $errors);
        $this->assertStringContainsString('ЦАРИНА', $errors);
        $this->assertStringContainsString('Ред 2', $errors);
    }

    public function test_a_heading_and_an_unknown_account_are_refused(): void
    {
        $draft = $this->editor->draftOf($this->scheme());

        foreach (['120' => 'не е аналитичко', '9999999' => 'не постои'] as $code => $expected) {
            $draft['rows'][0]['account_code'] = $code;
            $errors = implode(' | ', $this->editor->validate($this->company, PostingDocType::SALES_INVOICE, 'x', $draft['rows'], $draft['matrix']));
            $this->assertStringContainsString($expected, $errors, $code);
        }
    }

    public function test_an_inactive_account_is_refused(): void
    {
        $draft = $this->editor->draftOf($this->scheme());
        \App\Models\Account::where('company_id', $this->company->id)->where('code', '1200')->update(['is_active' => false]);

        $errors = implode(' | ', $this->editor->validate($this->company, PostingDocType::SALES_INVOICE, 'x', $draft['rows'], $draft['matrix']));

        $this->assertStringContainsString('не е активно', $errors);
    }

    public function test_an_unbalanced_scheme_is_refused_and_names_the_sample(): void
    {
        $draft = $this->editor->draftOf($this->scheme());
        $draft['rows'][0]['formula'] = 'ВКУПНО + 1';

        $errors = $this->editor->validate($this->company, PostingDocType::SALES_INVOICE, 'Излезна фактура', $draft['rows'], $draft['matrix']);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('не се балансира', implode(' | ', $errors));
        $this->assertStringContainsString('Услуга со 18% ДДВ', implode(' | ', $errors));
    }

    public function test_a_missing_matrix_account_for_a_slice_is_reported(): void
    {
        $draft = $this->editor->draftOf($this->scheme());
        $draft['matrix'] = array_values(array_filter($draft['matrix'], fn ($m) => ! ($m['matrix_key'] === 'revenue' && $m['item_kind'] === 'service' && $m['vat_group'] === 'general')));

        $errors = implode(' | ', $this->editor->validate($this->company, PostingDocType::SALES_INVOICE, 'x', $draft['rows'], $draft['matrix']));

        $this->assertStringContainsString('нема конто', $errors);
    }

    public function test_a_mode_a_condition_and_a_matrix_not_allowed_for_the_type_are_refused(): void
    {
        $draft = $this->editor->draftOf($this->scheme(PostingDocType::SALES_PAYMENT));
        $draft['rows'][0]['account_mode'] = 'line';
        $draft['rows'][1]['condition'] = 'import';

        $errors = implode(' | ', $this->editor->validate($this->company, PostingDocType::SALES_PAYMENT, 'x', $draft['rows'], $draft['matrix']));

        $this->assertStringContainsString('Ред 1', $errors);
        $this->assertStringContainsString('Ред 2', $errors);
    }

    public function test_save_replaces_the_rows_in_order_and_refuses_a_bad_draft_without_touching_the_scheme(): void
    {
        $scheme = $this->scheme();
        $draft = $this->editor->draftOf($scheme);

        $bad = $draft['rows'];
        $bad[0]['formula'] = 'ВКУПНО + 1';
        $this->assertNotEmpty($this->editor->save($scheme, $bad, $draft['matrix']));
        $this->assertSame('ВКУПНО', $scheme->rows()->first()->formula);

        $good = $draft['rows'];
        $good[0]['description'] = 'Нов опис {фактура}';
        $this->assertSame([], $this->editor->save($scheme, $good, $draft['matrix']));
        $fresh = $scheme->fresh()->rows;
        $this->assertCount(5, $fresh);
        $this->assertSame([1, 2, 3, 4, 5], $fresh->pluck('position')->all());
        $this->assertSame('Нов опис {фактура}', $fresh->first()->description);
        $this->assertSame(12, $scheme->matrixAccounts()->count());
    }

    public function test_reset_to_default_restores_the_suggested_scheme(): void
    {
        $scheme = $this->scheme();
        $draft = $this->editor->draftOf($scheme);
        $draft['rows'][0]['description'] = 'Променет';
        $this->editor->save($scheme, $draft['rows'], $draft['matrix']);

        $this->editor->resetToDefault($scheme);

        $this->assertSame('{фактура}', $scheme->fresh()->rows->first()->description);
        $this->assertSame(5, $scheme->rows()->count());
        $this->assertSame(12, $scheme->matrixAccounts()->count());
    }
}
```

- [ ] **Step 2: Run — FAIL.** `php artisan test tests/Feature/Posting/PostingSchemeEditorTest.php`

- [ ] **Step 3: Implement**

`DefaultPostingSchemes` — замени го телото на `create()` со две методи (содржината на циклусите се пресели во `populate`):

```php
    public static function create(Company $company, PostingDocType $type): PostingScheme
    {
        $definition = self::definition($type);

        return DB::transaction(function () use ($company, $type, $definition) {
            $scheme = PostingScheme::create(['company_id' => $company->id, 'doc_type' => $type, 'name' => $definition['name']]);
            self::populate($scheme, $company, $definition);

            return $scheme;
        });
    }

    /** Ги создава редовите и матричните сметки на шемата од дефиниција (стандардна). */
    public static function populate(PostingScheme $scheme, Company $company, array $definition): void
    {
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
    }
```

`app/Services/Posting/PostingSchemeEditor.php`:

```php
<?php

namespace App\Services\Posting;

use App\Exceptions\PostingFormulaException;
use App\Exceptions\PostingSchemeException;
use App\Models\Account;
use App\Models\Company;
use App\Models\PostingScheme;
use App\Models\PostingSchemeMatrixAccount;
use App\Models\PostingSchemeRow;
use App\Support\Posting\FormulaEvaluator;
use App\Support\Posting\PostingDocType;
use App\Support\Posting\PostingVocabulary;
use Illuminate\Support\Facades\DB;

/**
 * Менување на шема: работна копија (низи) → проверка → запис одеднаш. Шемата
 * се запишува само ако е исправна и балансира на сите пробни документи;
 * налозите што веќе се книжени не се допираат.
 */
class PostingSchemeEditor
{
    /** @return array{rows: list<array<string, mixed>>, matrix: list<array<string, mixed>>} */
    public function draftOf(PostingScheme $scheme): array
    {
        $scheme->load(['rows.account', 'matrixAccounts.account']);

        return [
            'rows' => $scheme->rows->map(fn (PostingSchemeRow $row) => [
                'account_mode' => $row->account_mode,
                'account_code' => $row->account?->code,
                'matrix_key' => $row->matrix_key,
                'side' => $row->side,
                'formula' => $row->formula,
                'with_partner' => (bool) $row->with_partner,
                'description' => $row->description,
                'condition' => $row->condition,
            ])->values()->all(),
            'matrix' => $scheme->matrixAccounts->map(fn (PostingSchemeMatrixAccount $m) => [
                'matrix_key' => $m->matrix_key,
                'item_kind' => $m->item_kind,
                'vat_group' => $m->vat_group,
                'account_code' => $m->account->code,
            ])->values()->all(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $matrix
     * @return list<string>
     */
    public function validate(Company $company, PostingDocType $type, string $name, array $rows, array $matrix): array
    {
        $errors = [];

        if ($rows === []) {
            return ['Шемата нема ниту еден ред.'];
        }

        $accounts = $this->accountsByCode($company, $rows, $matrix);
        $modes = PostingVocabulary::modes($type);
        $conditions = PostingVocabulary::conditions($type);
        $matrices = PostingVocabulary::matrices($type);

        foreach ($rows as $index => $row) {
            $n = $index + 1;
            $mode = (string) ($row['account_mode'] ?? '');

            if (! array_key_exists($mode, $modes)) {
                $errors[] = "Ред {$n}: начинот на конто „{$mode}“ не е дозволен за „{$type->label()}“.";

                continue;
            }

            if (! in_array($row['side'] ?? null, ['debit', 'credit'], true)) {
                $errors[] = "Ред {$n}: страната мора да биде Должи или Побарува.";
            }

            $condition = (string) ($row['condition'] ?? '');
            if ($condition !== '' && ! array_key_exists($condition, $conditions)) {
                $errors[] = "Ред {$n}: условот „{$condition}“ не е дозволен за „{$type->label()}“.";
            }

            if ($mode === 'fixed') {
                $errors = array_merge($errors, $this->accountErrors($accounts, (string) ($row['account_code'] ?? ''), "Ред {$n}"));
            }

            if ($mode === 'matrix') {
                $key = (string) ($row['matrix_key'] ?? '');
                if (! array_key_exists($key, $matrices)) {
                    $errors[] = "Ред {$n}: матрицата „{$key}“ не постои за „{$type->label()}“.";
                }
            }

            $errors = array_merge($errors, $this->formulaErrors($type, $mode, (string) ($row['formula'] ?? ''), $n));
        }

        $allowedCells = [];
        foreach (array_keys($matrices) as $key) {
            foreach (PostingVocabulary::matrixCells($key) as $cell) {
                $allowedCells[$cell['matrix_key'].'|'.($cell['item_kind'] ?? '').'|'.$cell['vat_group']] = true;
            }
        }

        $seen = [];
        foreach ($matrix as $entry) {
            $cell = $entry['matrix_key'].'|'.($entry['item_kind'] ?? '').'|'.$entry['vat_group'];
            $where = "Матрица „{$entry['matrix_key']}“ ({$entry['vat_group']})";

            if (! isset($allowedCells[$cell])) {
                $errors[] = "{$where}: ќелијата не е дозволена за „{$type->label()}“.";
            } elseif (isset($seen[$cell])) {
                $errors[] = "{$where}: ќелијата е внесена двапати.";
            } else {
                $errors = array_merge($errors, $this->accountErrors($accounts, (string) $entry['account_code'], $where));
            }

            $seen[$cell] = true;
        }

        if ($errors !== []) {
            return $errors;
        }

        $scheme = $this->transientScheme($company, $type, $name, $rows, $matrix);
        $engine = new PostingSchemeEngine;

        foreach (PostingSampleContexts::for($type, $company) as $sample => $context) {
            try {
                $engine->lines($scheme, $context);
            } catch (PostingSchemeException|PostingFormulaException $e) {
                $errors[] = "Пробен документ „{$sample}“: ".$e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $matrix
     * @return list<string>
     */
    public function save(PostingScheme $scheme, array $rows, array $matrix): array
    {
        $company = $scheme->company;
        $errors = $this->validate($company, $scheme->doc_type, $scheme->name, $rows, $matrix);

        if ($errors !== []) {
            return $errors;
        }

        $accounts = $this->accountsByCode($company, $rows, $matrix);

        DB::transaction(function () use ($scheme, $rows, $matrix, $accounts) {
            $scheme->rows()->delete();
            $scheme->matrixAccounts()->delete();

            foreach ($rows as $index => $row) {
                $scheme->rows()->create([
                    'position' => $index + 1,
                    'account_mode' => $row['account_mode'],
                    'account_id' => $row['account_mode'] === 'fixed' ? $accounts[$row['account_code']]->id : null,
                    'matrix_key' => $row['account_mode'] === 'matrix' ? $row['matrix_key'] : null,
                    'side' => $row['side'],
                    'formula' => $row['formula'],
                    'with_partner' => (bool) ($row['with_partner'] ?? false),
                    'description' => filled($row['description'] ?? null) ? $row['description'] : null,
                    'condition' => filled($row['condition'] ?? null) ? $row['condition'] : null,
                ]);
            }

            foreach ($matrix as $entry) {
                $scheme->matrixAccounts()->create([
                    'matrix_key' => $entry['matrix_key'],
                    'item_kind' => $entry['item_kind'] ?? null,
                    'vat_group' => $entry['vat_group'],
                    'account_id' => $accounts[$entry['account_code']]->id,
                ]);
            }
        });

        return [];
    }

    public function resetToDefault(PostingScheme $scheme): void
    {
        $definition = DefaultPostingSchemes::definition($scheme->doc_type);

        DB::transaction(function () use ($scheme, $definition) {
            $scheme->rows()->delete();
            $scheme->matrixAccounts()->delete();
            DefaultPostingSchemes::populate($scheme, $scheme->company, $definition);
        });
    }

    /**
     * Незапишана шема од работна копија — за проверка и пробно книжење.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $matrix
     */
    public function transientScheme(Company $company, PostingDocType $type, string $name, array $rows, array $matrix): PostingScheme
    {
        $accounts = $this->accountsByCode($company, $rows, $matrix);

        $scheme = new PostingScheme(['company_id' => $company->id, 'doc_type' => $type, 'name' => $name]);

        $scheme->setRelation('rows', collect($rows)->map(function (array $row, int $index) use ($accounts) {
            $model = new PostingSchemeRow([
                'position' => $index + 1,
                'account_mode' => $row['account_mode'] ?? '',
                'matrix_key' => $row['matrix_key'] ?? null,
                'side' => $row['side'] ?? '',
                'formula' => $row['formula'] ?? '',
                'with_partner' => (bool) ($row['with_partner'] ?? false),
                'description' => $row['description'] ?? null,
                'condition' => filled($row['condition'] ?? null) ? $row['condition'] : null,
            ]);
            $model->setRelation('account', $accounts[(string) ($row['account_code'] ?? '')] ?? null);

            return $model;
        })->values());

        $scheme->setRelation('matrixAccounts', collect($matrix)->map(function (array $entry) use ($accounts) {
            $model = new PostingSchemeMatrixAccount([
                'matrix_key' => $entry['matrix_key'],
                'item_kind' => $entry['item_kind'] ?? null,
                'vat_group' => $entry['vat_group'],
            ]);
            $model->setRelation('account', $accounts[(string) $entry['account_code']] ?? null);

            return $model;
        })->values());

        return $scheme;
    }

    /** @return array<string, Account> */
    private function accountsByCode(Company $company, array $rows, array $matrix): array
    {
        $codes = array_values(array_unique(array_filter(array_merge(
            array_map(fn ($r) => (string) ($r['account_code'] ?? ''), $rows),
            array_map(fn ($m) => (string) ($m['account_code'] ?? ''), $matrix),
        ))));

        return Account::where('company_id', $company->id)->whereIn('code', $codes)->get()->keyBy('code')->all();
    }

    /** @return list<string> */
    private function accountErrors(array $accounts, string $code, string $where): array
    {
        if ($code === '') {
            return ["{$where}: не е избрано конто."];
        }

        $account = $accounts[$code] ?? null;

        if ($account === null) {
            return ["{$where}: контото {$code} не постои во планот на фирмата."];
        }

        if (! $account->is_analytical) {
            return ["{$where}: контото {$code} не е аналитичко — книжењето оди само на аналитички конта."];
        }

        if (! $account->is_active) {
            return ["{$where}: контото {$code} не е активно."];
        }

        return [];
    }

    /** @return list<string> */
    private function formulaErrors(PostingDocType $type, string $mode, string $formula, int $n): array
    {
        if (trim($formula) === '') {
            return ["Ред {$n}: формулата е празна."];
        }

        $allowed = PostingVocabulary::allowedVariables($type, $mode);

        try {
            $unknown = array_diff(FormulaEvaluator::variablesIn($formula), $allowed);

            if ($unknown !== []) {
                return ["Ред {$n}: променливата „".implode('“, „', $unknown).'“ не постои за овој документ.'];
            }

            FormulaEvaluator::evaluate($formula, array_fill_keys($allowed, '1.00'));
        } catch (PostingFormulaException $e) {
            return ["Ред {$n}: ".$e->getMessage()];
        }

        return [];
    }
}
```

> `$scheme->company` во `save()`/`resetToDefault()`: релацијата `company()` постои на `PostingScheme`. `doc_type` е cast во `PostingDocType`.

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Feature/Posting/PostingSchemeEditorTest.php tests/Feature/Posting/DefaultPostingSchemesTest.php`

> Ако `test_a_heading_a_foreign_and_an_unknown_account_are_refused` падне на `'120'` бидејќи шифрата 120 е подгрупа — токму тоа се проверува (`не е аналитичко`). Ако падне поради друга причина, прочитај ја пораката; не го менувај очекувањето.

- [ ] **Step 5: Commit**

```bash
git add app tests
git commit -m "Posting schemes: editor with draft, validation over sample documents, save and reset"
```

---

### Task 4: Рути, мени и екран „список“

**Files:**
- Create: `app/Livewire/Accounting/PostingSchemeIndex.php`, `resources/views/livewire/accounting/posting-scheme-index.blade.php`
- Modify: `app/Support/Menu.php`, `routes/web.php`, `tests/Feature/AccountingAccessTest.php`
- Test: `tests/Feature/Posting/PostingSchemeScreenTest.php` (се дополнува во Task 5 и 6)

**Interfaces:**
- Produces: рути `accounting.posting-schemes.index` (`GET /companies/{company}/posting-schemes`) и `accounting.posting-schemes.edit` (`GET /companies/{company}/posting-schemes/{type}`, `{type}` = `PostingDocType` вредност). Мени „Шеми за книжење“ во групата `finance-settings` (само админ/сметководител, модул Финансии).
- `PostingSchemeIndex`: `mount(Company $company)` → `Gate::authorize('update', $company)`; ги материјализира (`PostingSchemes::for`) сите четири шеми и ги прикажува: назив, број редови, линк „Отвори“.

- [ ] **Step 1: Write the failing test** `tests/Feature/Posting/PostingSchemeScreenTest.php`:

```php
<?php

namespace Tests\Feature\Posting;

use App\Livewire\Accounting\PostingSchemeIndex;
use App\Models\Company;
use App\Models\PostingScheme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PostingSchemeScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
    }

    private function accountantOf(Company $company): User
    {
        $user = User::factory()->create();
        $user->assignRole('accountant');
        $user->assignedCompanies()->attach($company);

        return $user;
    }

    public function test_the_index_lists_all_four_schemes_and_creates_the_missing_ones(): void
    {
        $company = Company::factory()->create();
        $this->assertSame(0, PostingScheme::where('company_id', $company->id)->count());

        Livewire::actingAs($this->accountantOf($company))
            ->test(PostingSchemeIndex::class, ['company' => $company])
            ->assertSee('Излезна фактура')
            ->assertSee('Уплата од купувач')
            ->assertSee('Влезна фактура')
            ->assertSee('Исплата кон добавувач');

        $this->assertSame(4, PostingScheme::where('company_id', $company->id)->count());
    }

    public function test_the_route_is_open_to_an_accountant_of_the_company_and_closed_to_others(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');
        $stranger = $this->accountantOf(Company::factory()->create());

        $this->actingAs($this->accountantOf($company))->get(route('accounting.posting-schemes.index', $company))->assertOk();
        $this->actingAs($client)->get(route('accounting.posting-schemes.index', $company))->assertForbidden();
        $this->actingAs($stranger)->get(route('accounting.posting-schemes.index', $company))->assertForbidden();
    }

    public function test_the_menu_links_the_screen_for_an_accountant(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->accountantOf($company))->get(route('accounting.accounts.index', $company))
            ->assertSee('Шеми за книжење');
    }
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement**

`routes/web.php` — во првата група (`EnsureAccountingAccess`, `name('accounting.')`), по рутата за `accounts.index`:

```php
        Route::get('/posting-schemes', [PostingSchemeIndex::class, '__invoke'])->name('posting-schemes.index');
        Route::get('/posting-schemes/{type}', [PostingSchemeEdit::class, '__invoke'])->name('posting-schemes.edit');
```
+ `use App\Livewire\Accounting\PostingSchemeEdit; use App\Livewire\Accounting\PostingSchemeIndex;` (преку Edit, по другите `use App\Livewire\Accounting\…`).

`app/Support/Menu.php` — во `finance-settings` по „Контен план“:

```php
                    ['label' => 'Шеми за книжење', 'url' => route('accounting.posting-schemes.index', $company), 'pattern' => 'accounting.posting-schemes.*', 'roles' => ['admin', 'accountant'], 'module' => CompanyModule::FINANCE],
```

`app/Livewire/Accounting/PostingSchemeIndex.php`:

```php
<?php

namespace App\Livewire\Accounting;

use App\Models\Company;
use App\Services\Posting\PostingSchemes;
use App\Support\Posting\PostingDocType;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PostingSchemeIndex extends Component
{
    public Company $company;

    public function mount(Company $company): void
    {
        Gate::authorize('update', $company);
        $this->company = $company;
    }

    public function render()
    {
        // Шемите се создаваат лено при прво книжење; овде ги материјализираме
        // за да може сметководителот да ги види и пред тоа.
        $schemes = collect(PostingDocType::cases())->map(fn (PostingDocType $type) => [
            'type' => $type,
            'scheme' => PostingSchemes::for($this->company, $type)->loadCount('rows'),
        ]);

        return view('livewire.accounting.posting-scheme-index', ['schemes' => $schemes]);
    }
}
```

`resources/views/livewire/accounting/posting-scheme-index.blade.php`:

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">Шеми за книжење — {{ $company->name }}</h1>
    <p class="text-sm text-gray-600 mb-4 max-w-3xl">
        Шемата одредува на кои конта се книжи секој вид документ. Промената важи само за нови документи — веќе книжените налози остануваат како што се.
    </p>

    <x-card padding="p-0" class="overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-1 px-3">Документ</th>
                    <th class="py-1 px-3">Редови</th>
                    <th class="py-1 px-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($schemes as $entry)
                    <tr class="text-sm hover:bg-orange-50">
                        <td class="py-1 px-3 font-medium">{{ $entry['type']->label() }}</td>
                        <td class="py-1 px-3">{{ $entry['scheme']->rows_count }}</td>
                        <td class="py-1 px-3 text-right">
                            <a href="{{ route('accounting.posting-schemes.edit', [$company, $entry['type']->value]) }}" class="text-brand hover:underline">Отвори</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-card>
</div>
```

`tests/Feature/AccountingAccessTest.php` — додај во `accountingRoutes()` и `stillClosedAccountingRoutes()`:

```php
            'posting schemes' => ['accounting.posting-schemes.index'],
```

> `PostingSchemeEdit` уште не постои — рутата со `[Class::class, '__invoke']` се решава при повик, па `index` работи. Создај празна класа во Task 5. Ако `route()` падне заради непостоечка класа, прескочи до Task 5 Step 3 и врати се.

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Feature/Posting/PostingSchemeScreenTest.php tests/Feature/AccountingAccessTest.php tests/Feature/SidebarTest.php`
  Ако `SidebarTest` падне заради нова ставка во групата (број ставки/ред), ажурирај го очекувањето — само ако падот е од новата ставка.

- [ ] **Step 5: Commit**

```bash
git add app resources routes tests
git commit -m "Posting schemes: routes, menu item and the list screen"
```

---

### Task 5: Екран „уредување“ (редови, матрици, зачувај, врати на предложено)

**Files:**
- Create: `app/Livewire/Accounting/PostingSchemeEdit.php`, `resources/views/livewire/accounting/posting-scheme-edit.blade.php`
- Test: `tests/Feature/Posting/PostingSchemeScreenTest.php` (дополни)

**Interfaces:**
- `PostingSchemeEdit` (јавни својства): `Company $company`, `string $type`, `array $rows`, `array $cells` (матрични ќелии: `matrix_key,item_kind,vat_group,label,account_code`), `?int $editing` (индекс на ред што се менува; `-1` = нов), `array $form`, `array $problems`, `bool $saved`.
- Методи: `addRow()`, `editRow(int)`, `cancelRow()`, `saveRow()`, `deleteRow(int)`, `moveRow(int $index, int $direction)` (−1 горе, +1 долу), `saveScheme()`, `restoreDefault()`.
- `saveRow()` ја менува само работната копија; во базата се пишува само `saveScheme()` (преку `PostingSchemeEditor::save`).

- [ ] **Step 1: Write the failing tests** — додај во `PostingSchemeScreenTest` (+ `use App\Livewire\Accounting\PostingSchemeEdit;`):

```php
    private function edit(Company $company, string $type = 'sales_invoice')
    {
        return Livewire::actingAs($this->accountantOf($company))->test(PostingSchemeEdit::class, ['company' => $company, 'type' => $type]);
    }

    public function test_the_edit_screen_shows_the_rows_the_matrix_and_the_help(): void
    {
        $company = Company::factory()->create();

        $this->edit($company)
            ->assertSee('Излезна фактура')
            ->assertSee('1200')
            ->assertSee('НАБАВНА_ВРЕДНОСТ')
            ->assertSee('74000')
            ->assertSee('Само ако: има стока од залиха'); // условот се прикажува со назив, не со клуч
    }

    public function test_an_unknown_scheme_type_is_a_404(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->accountantOf($company))->get(route('accounting.posting-schemes.edit', [$company, 'nesto']))->assertNotFound();
    }

    public function test_a_row_can_be_edited_and_the_scheme_saved(): void
    {
        $company = Company::factory()->create();

        $component = $this->edit($company)
            ->call('editRow', 0)
            ->set('form.description', 'Нов опис {фактура}')
            ->call('saveRow')
            ->assertSet('editing', null)
            ->call('saveScheme')
            ->assertSet('problems', [])
            ->assertSet('saved', true);

        $scheme = PostingScheme::where('company_id', $company->id)->where('doc_type', 'sales_invoice')->firstOrFail();
        $this->assertSame('Нов опис {фактура}', $scheme->rows->first()->description);
    }

    public function test_a_bad_formula_is_reported_and_nothing_is_saved(): void
    {
        $company = Company::factory()->create();

        $this->edit($company)
            ->call('editRow', 0)
            ->set('form.formula', 'ВКУПНО + 1')
            ->call('saveRow')
            ->call('saveScheme')
            ->assertSet('saved', false)
            ->assertSee('не се балансира');

        $scheme = PostingScheme::where('company_id', $company->id)->where('doc_type', 'sales_invoice')->firstOrFail();
        $this->assertSame('ВКУПНО', $scheme->rows->first()->formula);
    }

    public function test_a_row_can_be_added_moved_and_deleted_in_the_draft(): void
    {
        $company = Company::factory()->create();

        $component = $this->edit($company)->assertCount('rows', 5);

        $component->call('addRow')
            ->set('form.account_mode', 'fixed')
            ->set('form.account_code', '1000')
            ->set('form.side', 'debit')
            ->set('form.formula', 'ВКУПНО')
            ->call('saveRow')
            ->assertCount('rows', 6);

        $component->call('moveRow', 5, -1);
        $this->assertSame('1000', $component->get('rows')[4]['account_code']);

        $component->call('deleteRow', 4)->assertCount('rows', 5);
    }

    public function test_the_row_form_refuses_an_empty_formula(): void
    {
        $company = Company::factory()->create();

        $this->edit($company)
            ->call('addRow')
            ->set('form.formula', '')
            ->call('saveRow')
            ->assertHasErrors(['form.formula'])
            ->assertCount('rows', 5);
    }

    public function test_a_matrix_account_can_be_changed(): void
    {
        $company = Company::factory()->create();
        $component = $this->edit($company);
        $index = collect($component->get('cells'))->search(fn ($c) => $c['matrix_key'] === 'revenue' && $c['item_kind'] === 'service' && $c['vat_group'] === 'general');

        $component->set("cells.{$index}.account_code", '74001')->call('saveScheme')->assertSet('problems', []);

        $scheme = PostingScheme::where('company_id', $company->id)->where('doc_type', 'sales_invoice')->firstOrFail();
        $entry = $scheme->matrixAccounts()->where('matrix_key', 'revenue')->where('item_kind', 'service')->where('vat_group', 'general')->with('account')->firstOrFail();
        $this->assertSame('74001', $entry->account->code);
    }

    public function test_restore_default_brings_back_the_suggested_scheme(): void
    {
        $company = Company::factory()->create();

        $this->edit($company)
            ->call('editRow', 0)->set('form.description', 'Променет')->call('saveRow')->call('saveScheme')
            ->call('restoreDefault')
            ->assertSet('saved', false);

        $scheme = PostingScheme::where('company_id', $company->id)->where('doc_type', 'sales_invoice')->firstOrFail();
        $this->assertSame('{фактура}', $scheme->rows->first()->description);
    }

    public function test_a_scheme_of_another_company_is_not_reachable(): void
    {
        $company = Company::factory()->create();
        $stranger = $this->accountantOf(Company::factory()->create());

        Livewire::actingAs($stranger)->test(PostingSchemeEdit::class, ['company' => $company, 'type' => 'sales_invoice'])->assertForbidden();
    }
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement** `app/Livewire/Accounting/PostingSchemeEdit.php`:

```php
<?php

namespace App\Livewire\Accounting;

use App\Models\Account;
use App\Models\Company;
use App\Services\Posting\PostingSchemeEditor;
use App\Services\Posting\PostingSchemes;
use App\Support\Posting\PostingDocType;
use App\Support\Posting\PostingVocabulary;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PostingSchemeEdit extends Component
{
    public Company $company;

    public string $type = '';

    /** @var list<array<string, mixed>> работна копија на редовите */
    public array $rows = [];

    /** @var list<array<string, mixed>> матрични ќелии: matrix_key, item_kind, vat_group, label, account_code */
    public array $cells = [];

    /** Индекс на ред што се менува; -1 = нов; null = нема отворена форма. */
    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var list<string> */
    public array $problems = [];

    public bool $saved = false;

    public function mount(Company $company, string $type): void
    {
        Gate::authorize('update', $company);
        $this->company = $company;
        $this->type = (PostingDocType::tryFrom($type) ?? abort(404))->value;
        $this->loadDraft();
    }

    private function docType(): PostingDocType
    {
        return PostingDocType::from($this->type);
    }

    private function loadDraft(): void
    {
        $scheme = PostingSchemes::for($this->company, $this->docType());
        $draft = app(PostingSchemeEditor::class)->draftOf($scheme);

        $this->rows = $draft['rows'];

        $existing = collect($draft['matrix'])->keyBy(fn ($m) => $m['matrix_key'].'|'.($m['item_kind'] ?? '').'|'.$m['vat_group']);
        $this->cells = [];

        foreach (array_keys(PostingVocabulary::matrices($this->docType())) as $key) {
            foreach (PostingVocabulary::matrixCells($key) as $cell) {
                $cell['account_code'] = $existing[$cell['matrix_key'].'|'.($cell['item_kind'] ?? '').'|'.$cell['vat_group']]['account_code'] ?? '';
                $this->cells[] = $cell;
            }
        }

        $this->editing = null;
        $this->form = [];
        $this->problems = [];
        $this->saved = false;
    }

    public function addRow(): void
    {
        $this->editing = -1;
        $this->form = ['account_mode' => 'fixed', 'account_code' => '', 'matrix_key' => '', 'side' => 'debit', 'formula' => '', 'with_partner' => false, 'description' => '', 'condition' => ''];
    }

    public function editRow(int $index): void
    {
        abort_unless(isset($this->rows[$index]), 404);

        $this->editing = $index;
        $this->form = array_merge($this->rows[$index], [
            'account_code' => (string) ($this->rows[$index]['account_code'] ?? ''),
            'matrix_key' => (string) ($this->rows[$index]['matrix_key'] ?? ''),
            'description' => (string) ($this->rows[$index]['description'] ?? ''),
            'condition' => (string) ($this->rows[$index]['condition'] ?? ''),
        ]);
    }

    public function cancelRow(): void
    {
        $this->editing = null;
        $this->form = [];
    }

    public function saveRow(): void
    {
        $type = $this->docType();

        $this->validate([
            'form.account_mode' => ['required', 'in:'.implode(',', array_keys(PostingVocabulary::modes($type)))],
            'form.side' => ['required', 'in:debit,credit'],
            'form.formula' => ['required', 'string', 'max:255'],
            'form.description' => ['nullable', 'string', 'max:255'],
            'form.account_code' => ['nullable', 'string', 'max:10'],
        ], [], ['form.formula' => 'формулата', 'form.account_mode' => 'начинот на конто', 'form.side' => 'страната']);

        $mode = $this->form['account_mode'];
        $row = [
            'account_mode' => $mode,
            'account_code' => $mode === 'fixed' ? trim((string) $this->form['account_code']) : null,
            'matrix_key' => $mode === 'matrix' ? (string) $this->form['matrix_key'] : null,
            'side' => $this->form['side'],
            'formula' => trim((string) $this->form['formula']),
            'with_partner' => (bool) ($this->form['with_partner'] ?? false),
            'description' => filled($this->form['description'] ?? null) ? $this->form['description'] : null,
            'condition' => filled($this->form['condition'] ?? null) ? $this->form['condition'] : null,
        ];

        if ($this->editing === -1) {
            $this->rows[] = $row;
        } else {
            $this->rows[$this->editing] = $row;
        }

        $this->editing = null;
        $this->form = [];
        $this->saved = false;
    }

    public function deleteRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
        $this->editing = null;
        $this->saved = false;
    }

    public function moveRow(int $index, int $direction): void
    {
        $target = $index + ($direction < 0 ? -1 : 1);

        if (! isset($this->rows[$index], $this->rows[$target])) {
            return;
        }

        [$this->rows[$index], $this->rows[$target]] = [$this->rows[$target], $this->rows[$index]];
        $this->editing = null;
        $this->saved = false;
    }

    public function saveScheme(): void
    {
        Gate::authorize('update', $this->company);

        $matrix = collect($this->cells)
            ->filter(fn ($c) => trim((string) $c['account_code']) !== '')
            ->map(fn ($c) => ['matrix_key' => $c['matrix_key'], 'item_kind' => $c['item_kind'], 'vat_group' => $c['vat_group'], 'account_code' => trim((string) $c['account_code'])])
            ->values()->all();

        $scheme = PostingSchemes::for($this->company, $this->docType());
        $this->problems = app(PostingSchemeEditor::class)->save($scheme, $this->rows, $matrix);
        $this->saved = $this->problems === [];
    }

    public function restoreDefault(): void
    {
        Gate::authorize('update', $this->company);

        app(PostingSchemeEditor::class)->resetToDefault(PostingSchemes::for($this->company, $this->docType()));
        $this->loadDraft();
    }

    public function render()
    {
        $type = $this->docType();

        $codes = collect($this->rows)->pluck('account_code')
            ->merge(collect($this->cells)->pluck('account_code'))
            ->push($this->form['account_code'] ?? null)
            ->filter()->unique()->values();

        return view('livewire.accounting.posting-scheme-edit', [
            'docType' => $type,
            'modes' => PostingVocabulary::modes($type),
            'conditions' => PostingVocabulary::conditions($type),
            'variables' => PostingVocabulary::variables($type),
            'matrices' => PostingVocabulary::matrices($type),
            'accountNames' => Account::where('company_id', $this->company->id)->whereIn('code', $codes)->pluck('name', 'code'),
        ]);
    }
}
```

`resources/views/livewire/accounting/posting-scheme-edit.blade.php`:

```blade
<div>
    <div class="mb-3">
        <a href="{{ route('accounting.posting-schemes.index', $company) }}" class="text-sm text-brand hover:underline">← Шеми за книжење</a>
    </div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">{{ $docType->label() }} — {{ $company->name }}</h1>
    <p class="text-sm text-gray-600 mb-4 max-w-3xl">
        Менувате работна копија. Нема да се примени додека не притиснете „Зачувај“, а тогаш важи само за нови документи.
    </p>

    @if ($saved)
        <div class="mb-4 p-3 rounded bg-green-50 text-green-800 text-sm">Шемата е зачувана. Важи за нови документи.</div>
    @endif

    @if ($problems !== [])
        <div class="mb-4 p-3 rounded bg-red-50 text-red-800 text-sm">
            <p class="font-semibold mb-1">Шемата не е зачувана:</p>
            <ul class="list-disc ml-5 space-y-0.5">
                @foreach ($problems as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <x-card padding="p-0" class="overflow-hidden mb-4">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-1 px-3">#</th>
                    <th class="py-1 px-3">Конто</th>
                    <th class="py-1 px-3">Страна</th>
                    <th class="py-1 px-3">Износ (формула)</th>
                    <th class="py-1 px-3">Партнер</th>
                    <th class="py-1 px-3">Услов</th>
                    <th class="py-1 px-3">Опис</th>
                    <th class="py-1 px-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($rows as $i => $row)
                    <tr class="text-sm hover:bg-orange-50">
                        <td class="py-1 px-3 text-gray-400">{{ $i + 1 }}</td>
                        <td class="py-1 px-3">
                            @if ($row['account_mode'] === 'fixed')
                                <span class="font-mono">{{ $row['account_code'] }}</span>
                                <span class="text-xs text-gray-500">{{ $accountNames[$row['account_code']] ?? '' }}</span>
                            @elseif ($row['account_mode'] === 'matrix')
                                <span class="text-gray-700">{{ $matrices[$row['matrix_key']] ?? $row['matrix_key'] }}</span>
                            @else
                                <span class="text-gray-700">{{ $modes[$row['account_mode']] ?? $row['account_mode'] }}</span>
                            @endif
                        </td>
                        <td class="py-1 px-3">{{ $row['side'] === 'debit' ? 'Должи' : 'Побарува' }}</td>
                        <td class="py-1 px-3 font-mono">{{ $row['formula'] }}</td>
                        <td class="py-1 px-3">{{ $row['with_partner'] ? 'Да' : '—' }}</td>
                        <td class="py-1 px-3 text-xs">{{ filled($row['condition'] ?? null) ? ($conditions[$row['condition']] ?? $row['condition']) : '' }}</td>
                        <td class="py-1 px-3 text-xs text-gray-500">{{ $row['description'] }}</td>
                        <td class="py-1 px-3 text-right whitespace-nowrap">
                            <button type="button" wire:click="moveRow({{ $i }}, -1)" class="text-gray-500 hover:text-gray-800" title="Горе">↑</button>
                            <button type="button" wire:click="moveRow({{ $i }}, 1)" class="text-gray-500 hover:text-gray-800" title="Долу">↓</button>
                            <button type="button" wire:click="editRow({{ $i }})" class="text-brand hover:underline ml-2">Измени</button>
                            <button type="button" wire:click="deleteRow({{ $i }})" wire:confirm="Да се избрише редот?" class="text-red-600 hover:underline ml-2">Избриши</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-card>

    @if ($editing === null)
        <div class="mb-6">
            <x-secondary-button type="button" wire:click="addRow">+ Нов ред</x-secondary-button>
        </div>
    @else
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-3">{{ $editing === -1 ? 'Нов ред' : 'Ред '.($editing + 1) }}</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <x-input-label for="form_mode" value="Конто" />
                    <select id="form_mode" wire:model.live="form.account_mode" class="border-gray-300 rounded-md text-sm w-full">
                        @foreach ($modes as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if (($form['account_mode'] ?? '') === 'fixed')
                    <div>
                        <x-input-label for="form_code" value="Шифра на конто (аналитичко)" />
                        <x-text-input id="form_code" wire:model.live.debounce.400ms="form.account_code" class="w-full font-mono" />
                        <p class="text-xs text-gray-500 mt-1">{{ $accountNames[$form['account_code'] ?? ''] ?? (filled($form['account_code'] ?? '') ? 'Непозната шифра.' : '') }}</p>
                    </div>
                @elseif (($form['account_mode'] ?? '') === 'matrix')
                    <div>
                        <x-input-label for="form_matrix" value="Матрица" />
                        <select id="form_matrix" wire:model="form.matrix_key" class="border-gray-300 rounded-md text-sm w-full">
                            <option value="">— изберете —</option>
                            @foreach ($matrices as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <div></div>
                @endif
                <div>
                    <x-input-label for="form_side" value="Страна" />
                    <select id="form_side" wire:model="form.side" class="border-gray-300 rounded-md text-sm w-full">
                        <option value="debit">Должи</option>
                        <option value="credit">Побарува</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="form_formula" value="Износ (формула)" />
                    <x-text-input id="form_formula" wire:model="form.formula" class="w-full font-mono" />
                    @error('form.formula') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="form_condition" value="Услов" />
                    <select id="form_condition" wire:model="form.condition" class="border-gray-300 rounded-md text-sm w-full">
                        <option value="">— секогаш —</option>
                        @foreach ($conditions as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="form_description" value="Опис на ставката" />
                    <x-text-input id="form_description" wire:model="form.description" class="w-full" />
                </div>
            </div>
            <label class="inline-flex items-center gap-2 mt-3 text-sm">
                <input type="checkbox" wire:model="form.with_partner" class="rounded border-gray-300">
                Врзано за партнерот
            </label>
            <div class="mt-3 flex gap-2">
                <x-primary-button type="button" wire:click="saveRow">Прифати ред</x-primary-button>
                <x-secondary-button type="button" wire:click="cancelRow">Откажи</x-secondary-button>
            </div>
        </x-card>
    @endif

    @foreach ($matrices as $matrixKey => $matrixLabel)
        <div class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Матрица: {{ $matrixLabel }}</h2>
            <x-card padding="p-0" class="overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr class="text-left text-sm text-gray-500 bg-gray-50">
                            <th class="py-1 px-3">За</th>
                            <th class="py-1 px-3">Конто (шифра)</th>
                            <th class="py-1 px-3">Назив</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($cells as $i => $cell)
                            @if ($cell['matrix_key'] === $matrixKey)
                                <tr class="text-sm">
                                    <td class="py-1 px-3">{{ $cell['label'] }}</td>
                                    <td class="py-1 px-3"><x-text-input wire:model.live.debounce.400ms="cells.{{ $i }}.account_code" class="w-28 font-mono !py-0.5 text-sm" /></td>
                                    <td class="py-1 px-3 text-xs text-gray-500">{{ $accountNames[$cell['account_code']] ?? (filled($cell['account_code']) ? 'Непозната шифра.' : '') }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        </div>
    @endforeach

    <div class="flex flex-wrap gap-2 mb-8">
        <x-primary-button type="button" wire:click="saveScheme">Зачувај шема</x-primary-button>
        <x-secondary-button type="button" wire:click="restoreDefault" wire:confirm="Да се врати предложената шема? Вашите измени за овој документ ќе се изгубат.">Врати на предложено</x-secondary-button>
    </div>

    <x-card class="mb-6">
        <h2 class="font-semibold text-gray-700 mb-2">Помош</h2>
        <p class="text-sm text-gray-600 mb-2">Во формулата може да се користат броеви, <span class="font-mono">+ − *</span>, загради и променливите:</p>
        <ul class="text-sm space-y-0.5 mb-3">
            @foreach ($variables as $name => $description)
                <li><span class="font-mono">{{ $name }}</span> — {{ $description }}</li>
            @endforeach
        </ul>
        <p class="text-xs text-gray-500">
            Во описот <span class="font-mono">{фактура}</span> се заменува со документот. Негативен износ ја менува страната (Должи ↔ Побарува).
            Износ нула не се книжи. Збирот Должи мора да е еднаков на Побарува.
        </p>
    </x-card>
</div>
```

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Feature/Posting/PostingSchemeScreenTest.php`

- [ ] **Step 5: Commit**

```bash
git add app resources tests
git commit -m "Posting schemes: the edit screen with draft rows, matrices, save and reset"
```

---

### Task 6: Пробно книжење

**Files:**
- Create: `app/Services/Posting/PostingSchemeTrial.php`
- Modify: `app/Livewire/Accounting/PostingSchemeEdit.php`, `resources/views/livewire/accounting/posting-scheme-edit.blade.php`
- Test: `tests/Feature/Posting/PostingSchemeTrialTest.php`; дополни `PostingSchemeScreenTest`

**Interfaces:**
- Produces: `PostingSchemeTrial::documents(Company $company, PostingDocType $type): Collection<int, array{id: int, label: string}>` — потврдени фактури на фирмата (најновите 30), за продажни видови излезни, за набавни влезни.
- Produces: `PostingSchemeTrial::run(Company $company, PostingDocType $type, int $documentId, PostingScheme $scheme, bool $cash = false): list<PostingLine>` — го гради контекстот на избраната фактура и го пушта моторот со дадената (работна) шема. **Нема запис.** Фрла `PostingSchemeException`/`PostingFormulaException` како моторот.
  - `sales_invoice`: `SalesInvoicePostingContext::build($invoice, $invoice->invoice_number_formatted, $cogs)`; `$cogs` = збир `round(количина × unit_cost на движењето, 2)` по ставките со `stock_movement_id`.
  - `sales_payment`: износ = преостаната сума (`balanceDue()`), ако е 0 — вкупно (`grandTotal()`); денари = износ × курс ако е девизна; `invoiceAccount` = `PostedInvoiceAccounts::receivable`; ознака `Payment for invoice N`.
  - `purchase_invoice`: `PurchaseInvoicePostingContext::build`.
  - `purchase_payment`: износ како погоре; `PostedInvoiceAccounts::payable`; ознака `Payment for purchase bill {добавувач} #{број}`.
- `PostingSchemeEdit`: својства `string $trialDocument = ''`, `bool $trialCash = false`, `?array $trial`; метод `runTrial()` — пробува ја РАБОТНАТА копија (вклучувајќи незачувани измени).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Posting/PostingSchemeTrialTest.php`:

```php
<?php

namespace Tests\Feature\Posting;

use App\Exceptions\PostingSchemeException;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Invoicing\SalesInvoiceService;
use App\Services\Posting\PostingSchemeEditor;
use App\Services\Posting\PostingSchemes;
use App\Services\Posting\PostingSchemeTrial;
use App\Support\Posting\PostingDocType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostingSchemeTrialTest extends TestCase
{
    use RefreshDatabase;

    private function confirmedSale(Company $company): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        return app(SalesInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);
    }

    private function confirmedPurchase(Company $company): PurchaseInvoice
    {
        $partner = Partner::factory()->for($company)->create(['name' => 'Добавувач']);
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'supplier_invoice_number' => '77', 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => \App\Models\Account::where('company_id', $company->id)->where('code', '4620')->value('id'), 'description' => 'X', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);

        return app(PurchaseInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);
    }

    private function draftScheme(Company $company, PostingDocType $type)
    {
        $scheme = PostingSchemes::for($company, $type);
        $draft = app(PostingSchemeEditor::class)->draftOf($scheme);

        return [app(PostingSchemeEditor::class)->transientScheme($company, $type, $scheme->name, $draft['rows'], $draft['matrix']), $draft];
    }

    public function test_the_documents_are_the_confirmed_invoices_of_the_company(): void
    {
        $company = Company::factory()->create();
        $sale = $this->confirmedSale($company);
        $this->confirmedSale(Company::factory()->create());
        SalesInvoice::factory()->for($company)->create(); // нацрт

        $documents = app(PostingSchemeTrial::class)->documents($company, PostingDocType::SALES_INVOICE);

        $this->assertCount(1, $documents);
        $this->assertSame($sale->id, $documents->first()['id']);
    }

    public function test_a_sales_invoice_trial_reproduces_the_posting_without_writing(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $sale = $this->confirmedSale($company);
        [$scheme] = $this->draftScheme($company, PostingDocType::SALES_INVOICE);
        $before = JournalEntry::count();

        $lines = app(PostingSchemeTrial::class)->run($company, PostingDocType::SALES_INVOICE, $sale->id, $scheme);

        $codes = collect($lines)->map(fn ($l) => $l->account->code.($l->side === 'debit' ? ' D ' : ' C ').$l->amount)->all();
        $this->assertEqualsCanonicalizing(['1200 D 1180.00', '74000 C 1000.00', '2300 C 180.00'], $codes);
        $this->assertSame($before, JournalEntry::count());
    }

    public function test_the_trial_uses_the_given_draft_not_the_saved_scheme(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $sale = $this->confirmedSale($company);
        [, $draft] = $this->draftScheme($company, PostingDocType::SALES_INVOICE);
        $draft['matrix'] = array_map(function ($m) {
            if ($m['matrix_key'] === 'revenue' && $m['item_kind'] === 'service' && $m['vat_group'] === 'general') {
                $m['account_code'] = '74001';
            }

            return $m;
        }, $draft['matrix']);
        $scheme = app(PostingSchemeEditor::class)->transientScheme($company, PostingDocType::SALES_INVOICE, 'x', $draft['rows'], $draft['matrix']);

        $lines = app(PostingSchemeTrial::class)->run($company, PostingDocType::SALES_INVOICE, $sale->id, $scheme);

        $this->assertContains('74001', collect($lines)->map(fn ($l) => $l->account->code)->all());
    }

    public function test_a_broken_draft_throws_the_engine_message(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $sale = $this->confirmedSale($company);
        [, $draft] = $this->draftScheme($company, PostingDocType::SALES_INVOICE);
        $draft['rows'][0]['formula'] = 'ВКУПНО + 1';
        $scheme = app(PostingSchemeEditor::class)->transientScheme($company, PostingDocType::SALES_INVOICE, 'x', $draft['rows'], $draft['matrix']);

        $this->expectException(PostingSchemeException::class);
        $this->expectExceptionMessage('не се балансира');

        app(PostingSchemeTrial::class)->run($company, PostingDocType::SALES_INVOICE, $sale->id, $scheme);
    }

    public function test_payments_and_purchase_invoices_can_be_tried(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $sale = $this->confirmedSale($company);
        $purchase = $this->confirmedPurchase($company);
        $trial = app(PostingSchemeTrial::class);

        [$salesPayment] = $this->draftScheme($company, PostingDocType::SALES_PAYMENT);
        $lines = $trial->run($company, PostingDocType::SALES_PAYMENT, $sale->id, $salesPayment);
        $this->assertEqualsCanonicalizing(['1000 D 1180.00', '1200 C 1180.00'], collect($lines)->map(fn ($l) => $l->account->code.($l->side === 'debit' ? ' D ' : ' C ').$l->amount)->all());

        [$purchaseScheme] = $this->draftScheme($company, PostingDocType::PURCHASE_INVOICE);
        $lines = $trial->run($company, PostingDocType::PURCHASE_INVOICE, $purchase->id, $purchaseScheme);
        $this->assertEqualsCanonicalizing(['4620 D 100.00', '2200 C 100.00'], collect($lines)->map(fn ($l) => $l->account->code.($l->side === 'debit' ? ' D ' : ' C ').$l->amount)->all());

        [$purchasePayment] = $this->draftScheme($company, PostingDocType::PURCHASE_PAYMENT);
        $lines = $trial->run($company, PostingDocType::PURCHASE_PAYMENT, $purchase->id, $purchasePayment, true);
        $this->assertEqualsCanonicalizing(['2200 D 100.00', '1020 C 100.00'], collect($lines)->map(fn ($l) => $l->account->code.($l->side === 'debit' ? ' D ' : ' C ').$l->amount)->all());
    }

    public function test_a_document_of_another_company_is_not_found(): void
    {
        $company = Company::factory()->create();
        $foreign = $this->confirmedSale(Company::factory()->create());
        [$scheme] = $this->draftScheme($company, PostingDocType::SALES_INVOICE);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        app(PostingSchemeTrial::class)->run($company, PostingDocType::SALES_INVOICE, $foreign->id, $scheme);
    }
}
```

Додај во `PostingSchemeScreenTest`:

```php
    public function test_the_trial_on_the_edit_screen_shows_the_lines_of_a_chosen_invoice(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $partner = \App\Models\Partner::factory()->for($company)->create();
        $invoice = \App\Models\SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);
        $confirmed = app(\App\Services\Invoicing\SalesInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);
        $entries = \App\Models\JournalEntry::count();

        $this->edit($company)
            ->set('trialDocument', (string) $confirmed->id)
            ->call('runTrial')
            ->assertSee('74000')
            ->assertSee('1180.00');

        $this->assertSame($entries, \App\Models\JournalEntry::count());
    }

    public function test_the_trial_reports_a_broken_draft_instead_of_failing(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $partner = \App\Models\Partner::factory()->for($company)->create();
        $invoice = \App\Models\SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);
        $confirmed = app(\App\Services\Invoicing\SalesInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);

        $this->edit($company)
            ->call('editRow', 0)->set('form.formula', 'ВКУПНО + 1')->call('saveRow')
            ->set('trialDocument', (string) $confirmed->id)
            ->call('runTrial')
            ->assertSee('не се балансира');
    }
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement** `app/Services/Posting/PostingSchemeTrial.php`:

```php
<?php

namespace App\Services\Posting;

use App\Models\Company;
use App\Models\PostingScheme;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Support\Bcmath;
use App\Support\Posting\PostingContext;
use App\Support\Posting\PostingDocType;
use App\Support\Posting\PostingLine;
use Illuminate\Support\Collection;

/**
 * Пробно книжење: избрана вистинска фактура се пушта низ (работна) шема и се
 * покажуваат ставките што би настанале. Ништо не се запишува.
 */
class PostingSchemeTrial
{
    public function __construct(private PostingSchemeEngine $engine) {}

    /** @return Collection<int, array{id: int, label: string}> */
    public function documents(Company $company, PostingDocType $type): Collection
    {
        if ($this->isSales($type)) {
            return SalesInvoice::where('company_id', $company->id)->where('status', 'confirmed')
                ->with('partner')->orderByDesc('invoice_date')->orderByDesc('id')->limit(30)->get()
                ->map(fn (SalesInvoice $i) => ['id' => $i->id, 'label' => "{$i->invoice_number_formatted} — {$i->partner?->name} ({$i->invoice_date->format('d.m.Y')})"]);
        }

        return PurchaseInvoice::where('company_id', $company->id)->where('status', 'confirmed')
            ->with('partner')->orderByDesc('invoice_date')->orderByDesc('id')->limit(30)->get()
            ->map(fn (PurchaseInvoice $i) => ['id' => $i->id, 'label' => "{$i->partner?->name} #{$i->supplier_invoice_number} ({$i->invoice_date->format('d.m.Y')})"]);
    }

    /** @return list<PostingLine> */
    public function run(Company $company, PostingDocType $type, int $documentId, PostingScheme $scheme, bool $cash = false): array
    {
        return $this->engine->lines($scheme, $this->context($company, $type, $documentId, $cash));
    }

    private function context(Company $company, PostingDocType $type, int $documentId, bool $cash): PostingContext
    {
        if ($this->isSales($type)) {
            $invoice = SalesInvoice::where('company_id', $company->id)->with('lines.item', 'lines.stockMovement', 'company')->findOrFail($documentId);

            if ($type === PostingDocType::SALES_INVOICE) {
                return SalesInvoicePostingContext::build($invoice, (string) $invoice->invoice_number_formatted, $this->cogs($invoice));
            }

            $amount = $this->paymentAmount($invoice);
            $mkd = $invoice->isForeignCurrency() ? Bcmath::roundHalfUp(bcmul($amount, (string) $invoice->exchange_rate, 10), 2) : $amount;

            return SalesPaymentPostingContext::build($invoice, $mkd, $amount, $cash, "Payment for invoice {$invoice->invoice_number_formatted}", PostedInvoiceAccounts::receivable($invoice));
        }

        $invoice = PurchaseInvoice::where('company_id', $company->id)->with('lines.item', 'lines.account', 'partner', 'company', 'payments')->findOrFail($documentId);

        if ($type === PostingDocType::PURCHASE_INVOICE) {
            return PurchaseInvoicePostingContext::build($invoice);
        }

        return PurchasePaymentPostingContext::build(
            $invoice,
            $this->paymentAmount($invoice),
            $cash,
            "Payment for purchase bill {$invoice->partner->name} #{$invoice->supplier_invoice_number}",
            PostedInvoiceAccounts::payable($invoice)
        );
    }

    private function isSales(PostingDocType $type): bool
    {
        return in_array($type, [PostingDocType::SALES_INVOICE, PostingDocType::SALES_PAYMENT], true);
    }

    /** Преостаната сума за плаќање; ако е веќе платена — вкупниот износ (за да има што да се пробува). */
    private function paymentAmount(SalesInvoice|PurchaseInvoice $invoice): string
    {
        $invoice->loadMissing(['lines', 'payments']);
        $due = $invoice->balanceDue();

        return bccomp($due, '0', 2) > 0 ? $due : $invoice->grandTotal();
    }

    /** Набавна вредност на продадената стока: количина × цена на движењето, по ставка. */
    private function cogs(SalesInvoice $invoice): string
    {
        $total = '0.00';

        foreach ($invoice->lines as $line) {
            if ($line->stockMovement !== null) {
                $total = bcadd($total, Bcmath::roundHalfUp(bcmul((string) $line->quantity, (string) $line->stockMovement->unit_cost, 10), 2), 2);
            }
        }

        return $total;
    }
}
```

`PostingSchemeEdit` — додај (по `$saved`):

```php
    public string $trialDocument = '';

    public bool $trialCash = false;

    /** @var array{lines: list<array<string, string>>, error: ?string}|null */
    public ?array $trial = null;
```
и метод:

```php
    public function runTrial(): void
    {
        Gate::authorize('update', $this->company);
        $this->trial = null;

        if ($this->trialDocument === '') {
            return;
        }

        $editor = app(PostingSchemeEditor::class);
        $type = $this->docType();
        $scheme = PostingSchemes::for($this->company, $type);
        $matrix = collect($this->cells)
            ->filter(fn ($c) => trim((string) $c['account_code']) !== '')
            ->map(fn ($c) => ['matrix_key' => $c['matrix_key'], 'item_kind' => $c['item_kind'], 'vat_group' => $c['vat_group'], 'account_code' => trim((string) $c['account_code'])])
            ->values()->all();
        $draft = $editor->transientScheme($this->company, $type, $scheme->name, $this->rows, $matrix);

        try {
            $lines = app(PostingSchemeTrial::class)->run($this->company, $type, (int) $this->trialDocument, $draft, $this->trialCash);

            $this->trial = [
                'error' => null,
                'lines' => array_map(fn ($l) => [
                    'account' => $l->account->code.' — '.$l->account->name,
                    'debit' => $l->side === 'debit' ? $l->amount : '',
                    'credit' => $l->side === 'credit' ? $l->amount : '',
                    'description' => $l->description,
                ], $lines),
            ];
        } catch (PostingSchemeException|PostingFormulaException $e) {
            $this->trial = ['lines' => [], 'error' => $e->getMessage()];
        }
    }
```
(+ `use App\Exceptions\PostingFormulaException; use App\Exceptions\PostingSchemeException; use App\Services\Posting\PostingSchemeTrial;`). Исфактори го создавањето на `$matrix` од `$this->cells` во приватен метод `matrixFromCells(): array` и користи го и во `saveScheme()` и во `runTrial()` (без дупликат). Во `render()` додај `'trialDocuments' => app(PostingSchemeTrial::class)->documents($this->company, $type),` и `'canCash' => array_key_exists('cash', $conditions)` (= дали видот има услов `cash`).

Во blade пред „Помош“ додај картичка:

```blade
    <x-card class="mb-6">
        <h2 class="font-semibold text-gray-700 mb-1">Пробај</h2>
        <p class="text-sm text-gray-600 mb-3">Избери постоечка фактура и види ги ставките што би настанале со шемата како што е сега (и со незачуваните измени). Ништо не се книжи.</p>
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-[18rem]">
                <x-input-label for="trial_document" value="Документ" />
                <select id="trial_document" wire:model="trialDocument" class="border-gray-300 rounded-md text-sm w-full">
                    <option value="">— изберете —</option>
                    @foreach ($trialDocuments as $document)
                        <option value="{{ $document['id'] }}">{{ $document['label'] }}</option>
                    @endforeach
                </select>
            </div>
            @if ($canCash)
                <label class="inline-flex items-center gap-2 text-sm pb-2">
                    <input type="checkbox" wire:model="trialCash" class="rounded border-gray-300"> Готовинско
                </label>
            @endif
            <x-secondary-button type="button" wire:click="runTrial">Пробај</x-secondary-button>
        </div>
        @if ($trialDocuments->isEmpty())
            <p class="text-xs text-gray-500 mt-2">Нема потврдена фактура за пробање.</p>
        @endif

        @if ($trial !== null)
            @if ($trial['error'])
                <div class="mt-3 p-3 rounded bg-red-50 text-red-800 text-sm">{{ $trial['error'] }}</div>
            @else
                <table class="min-w-full divide-y divide-gray-200 mt-3">
                    <thead>
                        <tr class="text-left text-sm text-gray-500 bg-gray-50">
                            <th class="py-1 px-3">Конто</th>
                            <th class="py-1 px-3 text-right">Должи</th>
                            <th class="py-1 px-3 text-right">Побарува</th>
                            <th class="py-1 px-3">Опис</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($trial['lines'] as $line)
                            <tr class="text-sm">
                                <td class="py-1 px-3">{{ $line['account'] }}</td>
                                <td class="py-1 px-3 text-right font-mono">{{ $line['debit'] }}</td>
                                <td class="py-1 px-3 text-right font-mono">{{ $line['credit'] }}</td>
                                <td class="py-1 px-3 text-xs text-gray-500">{{ $line['description'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif
    </x-card>
```

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Feature/Posting/PostingSchemeTrialTest.php tests/Feature/Posting/PostingSchemeScreenTest.php`

- [ ] **Step 5: Commit**

```bash
git add app resources tests
git commit -m "Posting schemes: trial posting against a real invoice, without writing"
```

---

### Task 7: Документација, целосна серија, спојување

**Files:**
- Create: `docs/superpowers/2026-10-06-posting-schemes-part3-log.md`
- Modify: `docs/superpowers/specs/2026-10-06-posting-schemes-design.md` (под „Одстапувања при градењето“ додај „дел 3“)

- [ ] **Step 1: Одстапувања (дел 3)** — запиши: (1) менувањето е работна копија со „Зачувај“ (не се запишува ред-по-ред) за меѓучекорите да не мора да балансираат; (2) контото се внесува со шифра, не од листа; (3) пробното книжење работи врз потврдени фактури на фирмата (најновите 30), не врз произволен „документ за тест“; (4) „Врати на предложено“ ја заменува целата шема на тој вид документ; (5) пробните документи за проверка се во `PostingSampleContexts` — нов случај се додава таму.

- [ ] **Step 2: Лог** `docs/superpowers/2026-10-06-posting-schemes-part3-log.md`: што е направено (6 задачи), одлуки, ненаправено (дел 4 канцелариски комплет; шеми не се враќаат автоматски; шема не се прикажува на ниво на канцеларија), и **како да се пробаат рачно**: Финансии → ПОСТАВКИ → Шеми за книжење → Излезна фактура → смени опис → „Зачувај“ → потврди нова фактура → провери го налогот.

- [ ] **Step 3: Целосна серија** — **прво прашај го корисникот** („пуштам целосна серија, ~14 мин“), па `php artisan test` на гранката. Очекувано: сè зелено, 3 прескокнати.

- [ ] **Step 4: Commit, спој и пушти** (зелена серија → спој во `main` и пушти):

```bash
git add docs
git commit -m "Docs: posting schemes part 3 spec amendments and log"
git checkout main && git merge --no-ff posting-schemes-part3 -m "Merge posting schemes part 3: edit screen and trial posting" && git push origin main
```

CI се следи еднаш (`gh run list`). Сними во меморија што е готово и што останува (дел 4).
