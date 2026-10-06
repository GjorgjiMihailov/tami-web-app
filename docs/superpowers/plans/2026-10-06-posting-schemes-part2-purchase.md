# Шеми за книжење, дел 2: влезна фактура, увоз и исплата — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Книжењето на влезна фактура (домашна и увозна) и на исплата кон добавувач да оди преку шеми, со подетални конта, без да се скрши ниедна контрола што денес постои.

**Architecture:** Дел 1 (`PostingSchemeEngine`, `PostingContext`, `DefaultPostingSchemes`) се проширува: нов начин на конто `line` (се повторува по сметка од ставките), нова матрица `INPUT_VAT` (по даночна група), `PurchaseInvoicePostingContext`, две нови стандардни шеми (`PURCHASE_INVOICE`, `PURCHASE_PAYMENT`). `PurchaseInvoiceService::confirm` и `recordPayment` ги користат; `BankStatementPoster` ја зема обврската од самата фактура; формата за влезна фактура ја зема залихата за увоз од шемата.

**Tech Stack:** Laravel 13, PHP 8.3, bcmath (низи), SQLite (тест) / MySQL (продукција), PHPUnit.

Спецификација: `docs/superpowers/specs/2026-10-06-posting-schemes-design.md` + дел 1 лог `docs/superpowers/2026-10-06-posting-schemes-part1-log.md`. Дел 1 е веќе во `main` (f059936).

## Global Constraints

- Македонски текстови за корисник (строг македонски); коментари во кодот — македонски.
- Пари: **bcmath низи**, `Bcmath::roundHalfUp($v, 2)`; никогаш float.
- Знаци/текстови што веќе постојат остануваат: налогот е во група 99; опис на налог и на редови `Purchase bill {добавувач} #{број}`; ДДВ ред `Input VAT on …`; уплата `Payment for purchase bill {добавувач} #{број}` — `changeSupplierNumber` ги преименува со `str_replace`.
- Влезната фактура е секогаш во денари (нема девизни колони). Шемата не носи девизни износи.
- Eloquent: `$attributes` се поставува во моделот за секоја колона со DB-default.
- MySQL: имиња на индекси ≤ 64 знаци.
- Не ги допирај: `SalesInvoiceService`, плата, `OfficialChartOfAccounts`, `LandedCostAllocator`, логиката на залиха/landed cost (само книжењето).
- **Начин на конто `line`** (сметка избрана од корисникот на ставката) НЕ се проверува за аналитичност во моторот: постојните влезни фактури книжат на подгрупи (на пр. 462) и тоа мора да продолжи да работи. Тоа е одстапување од „само аналитички“ — се запишува во логот и се враќа на сопственикот како отворено прашање.
- Тестови: `php artisan test <датотека>` само за датотеката што ја менуваш. **Целата серија се пушта еднаш на крај и прво се прашува корисникот.** Не користи `python`; не користи `sed` за додавање `use` редови (ги јаде обратните коси црти) — користи Edit. Не слепувај код од овој план со `sed` преку ограда ``` — земи точни редови.
- Кон секој commit: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Работи на гранка `posting-schemes-part2`, не на `main`.

## Мапа на датотеки

Нови:
- `app/Services/Posting/PurchaseInvoicePostingContext.php`, `PurchasePaymentPostingContext.php`
- `tests/Feature/Posting/{PostingSchemeEngineLineModeTest,PurchaseInvoicePostingContextTest,PurchaseInvoiceSchemePostingTest,PurchasePaymentSchemePostingTest}.php`

Менувани: `app/Support/Posting/PostingContext.php`, `PostingMatrix.php`; `app/Services/Posting/PostingSchemeEngine.php`, `DefaultPostingSchemes.php`, `PostingSchemes.php`, `PostedInvoiceAccounts.php`; `app/Services/Invoicing/PurchaseInvoiceService.php`; `app/Services/Bank/BankStatementPoster.php`; `app/Livewire/Invoicing/PurchaseInvoiceForm.php`; тестови: `tests/Feature/Posting/DefaultPostingSchemesTest.php`, `tests/Unit/PurchaseInvoiceServiceTest.php`, `tests/Feature/PurchaseInvoiceImportLandedCostTest.php`, `tests/Feature/PurchaseInvoiceShowTest.php`, `tests/Feature/Invoicing/PurchaseInvoiceImportScanTest.php`, `tests/Feature/Bank/BankStatementPosterTest.php`.

Мапирање на конта: 130 → 1300 (општа) / 1301 (повластена) / 1302 (увоз); 220 → 2200 (домашна) / 2210 (увоз); 660 → 6600 (домашна) / 6601 (увоз); готово 102 → 1020. Сметките на ставките (трошок) остануваат како што ги избрал корисникот.

---

### Task 1: Мотор — начин `line` и матрица `INPUT_VAT`

**Files:**
- Modify: `app/Support/Posting/PostingContext.php`, `app/Support/Posting/PostingMatrix.php`, `app/Services/Posting/PostingSchemeEngine.php`
- Test: `tests/Feature/Posting/PostingSchemeEngineLineModeTest.php`

**Interfaces:**
- Produces: `PostingContext::$accountBuckets` (`list<array{account: Account, amount: string}>`, последен параметар, default `[]`); `PostingMatrix::INPUT_VAT` (`'input_vat'`, по група, не по вид).
- Правило: ред со `account_mode = 'line'` се повторува по секој елемент од `accountBuckets`; променливите на редот се `totals` плус `ТРОШОК_СТАВКА` = износот на елементот; сметката е на елементот (не се проверува за аналитичност); износ 0 се прескокнува.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Posting/PostingSchemeEngineLineModeTest.php`:

```php
<?php

namespace Tests\Feature\Posting;

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

class PostingSchemeEngineLineModeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private PostingScheme $scheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->scheme = PostingScheme::create(['company_id' => $this->company->id, 'doc_type' => PostingDocType::PURCHASE_INVOICE, 'name' => 't']);
    }

    private function account(string $code): Account
    {
        return Account::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function row(int $position, string $mode, string $side, string $formula, array $extra = []): void
    {
        PostingSchemeRow::create(array_merge([
            'posting_scheme_id' => $this->scheme->id, 'position' => $position, 'account_mode' => $mode,
            'side' => $side, 'formula' => $formula,
        ], $extra));
    }

    private function context(array $totals, array $extra = []): PostingContext
    {
        return new PostingContext(...array_merge([
            'totals' => $totals,
            'flags' => ['has_goods' => false, 'cash' => false, 'import' => false],
            'partnerId' => 9,
            'documentLabel' => 'Purchase bill X #1',
        ], $extra));
    }

    public function test_a_line_row_repeats_per_account_bucket_even_on_a_heading_account(): void
    {
        $this->row(1, 'line', 'debit', 'ТРОШОК_СТАВКА', ['with_partner' => true, 'description' => '{фактура}']);
        $this->row(2, 'fixed', 'credit', 'ВКУПНО', ['account_id' => $this->account('2200')->id, 'with_partner' => true]);

        $lines = (new PostingSchemeEngine)->lines($this->scheme->fresh(), $this->context(
            ['ВКУПНО' => '1500.00'],
            ['accountBuckets' => [
                ['account' => $this->account('4620'), 'amount' => '1000.00'],
                ['account' => $this->account('462'), 'amount' => '500.00'], // подгрупа: корисникот ја избрал
            ]]
        ));

        $this->assertCount(3, $lines);
        $this->assertSame('4620', $lines[0]->account->code);
        $this->assertSame('1000.00', $lines[0]->amount);
        $this->assertSame('debit', $lines[0]->side);
        $this->assertSame(9, $lines[0]->partnerId);
        $this->assertSame('Purchase bill X #1', $lines[0]->description);
        $this->assertSame('462', $lines[1]->account->code);
        $this->assertSame('500.00', $lines[1]->amount);
    }

    public function test_a_line_row_with_no_buckets_posts_nothing(): void
    {
        $this->row(1, 'line', 'debit', 'ТРОШОК_СТАВКА');
        $this->row(2, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('6600')->id]);
        $this->row(3, 'fixed', 'credit', 'ВКУПНО', ['account_id' => $this->account('2200')->id]);

        $lines = (new PostingSchemeEngine)->lines($this->scheme->fresh(), $this->context(['ВКУПНО' => '10.00']));

        $this->assertCount(2, $lines);
    }

    public function test_the_input_vat_matrix_is_read_by_group_and_zero_vat_needs_no_account(): void
    {
        $this->row(1, 'fixed', 'debit', 'ВКУПНО - ДДВ', ['account_id' => $this->account('6600')->id]);
        $this->row(2, 'matrix', 'debit', 'ДДВ', ['matrix_key' => PostingMatrix::INPUT_VAT]);
        $this->row(3, 'fixed', 'credit', 'ВКУПНО', ['account_id' => $this->account('2200')->id]);
        PostingSchemeMatrixAccount::create(['posting_scheme_id' => $this->scheme->id, 'matrix_key' => PostingMatrix::INPUT_VAT, 'item_kind' => null, 'vat_group' => 'general', 'account_id' => $this->account('1300')->id]);
        PostingSchemeMatrixAccount::create(['posting_scheme_id' => $this->scheme->id, 'matrix_key' => PostingMatrix::INPUT_VAT, 'item_kind' => null, 'vat_group' => 'reduced', 'account_id' => $this->account('1301')->id]);

        // 300 (ВКУПНО − ДДВ) + 36 + 5 = 341 = ВКУПНО. Во редот 1 `ДДВ` е вкупниот од `totals`,
        // во редот 2 е ДДВ на кришката.
        $lines = (new PostingSchemeEngine)->lines($this->scheme->fresh(), $this->context(
            ['ВКУПНО' => '341.00', 'ДДВ' => '41.00'],
            ['slices' => [
                new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '200.00', '36.00'),
                new PostingSlice(ItemKind::GOODS, VatGroup::REDUCED, '100.00', '5.00'),
                new PostingSlice(ItemKind::GOODS, VatGroup::EXEMPT_WITH_CREDIT, '9.00', '0.00'),
            ]]
        ));

        $byAccount = collect($lines)->keyBy(fn ($l) => $l->account->code);
        $this->assertSame('36.00', $byAccount['1300']->amount);
        $this->assertSame('5.00', $byAccount['1301']->amount);
        $this->assertCount(4, $lines); // 6600, 1300, 1301, 2200 — ослободената кришка е со ДДВ 0 и нема конто
    }
}
```

- [ ] **Step 2: Run — FAIL.** `php artisan test tests/Feature/Posting/PostingSchemeEngineLineModeTest.php`

- [ ] **Step 3: Implement**

`app/Support/Posting/PostingMatrix.php` — додај по `OUTPUT_VAT`:

```php
    /** Влезен ДДВ (одбивлив): конто само по даночна група. */
    public const INPUT_VAT = 'input_vat';
```

`app/Support/Posting/PostingContext.php` — додај параметар на крај од конструкторот (по `invoiceAccount`) и ажурирај го коментарот:

```php
        public readonly ?Account $invoiceAccount = null,
        /** @var list<array{account: Account, amount: string}> сметки од ставките на документот и збир по сметка */
        public readonly array $accountBuckets = [],
```

`app/Services/Posting/PostingSchemeEngine.php` — во `expand()`, веднаш по блокот за `invoice` режим (по неговиот `return`), додај:

```php
        if ($row->account_mode === 'line') {
            // Сметката ја избрал корисникот на ставката на документот; не се
            // проверува за аналитичност — постојните документи книжат и на подгрупи.
            $expanded = [];

            foreach ($context->accountBuckets as $bucket) {
                $expanded[] = [$bucket['account'], $context->totals + ['ТРОШОК_СТАВКА' => $bucket['amount']], $context->foreignTotals];
            }

            return $expanded;
        }
```

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Feature/Posting`

- [ ] **Step 5: Commit**

```bash
git checkout -b posting-schemes-part2
git add app tests
git commit -m "Posting engine: line-account mode and the input VAT matrix"
```

---

### Task 2: Контекст за влезна фактура

**Files:**
- Create: `app/Services/Posting/PurchaseInvoicePostingContext.php`
- Test: `tests/Feature/Posting/PurchaseInvoicePostingContextTest.php`

**Interfaces:**
- Produces: `PurchaseInvoicePostingContext::build(PurchaseInvoice $invoice): PostingContext`. `$invoice` мора да има вчитано `lines.item`, `lines.account`, `partner`, `company`. Променливи (денари): `ВКУПНО` (збир на сите дебити = обврската), `ЗАЛИХА` (ставки со артикл од залиха), `ТРОШОК_СТАВКА` (збир на сметките од ставките), `ОДБИВЛИВ_ДДВ`. `accountBuckets`: една ставка по различна сметка, по редослед на прво појавување. `slices`: одбивлив ДДВ по вид (стока ако ставката е од залиха) × група (`VatGroup::forLine('standard', $rate)`); основица = нето, ДДВ = одбивлив ДДВ. Знамиња: `import` = `$invoice->is_import`, `has_goods` = има ставка од залиха, `cash` = false. `documentLabel` = `Purchase bill {добавувач} #{број}`.
- Правило за износ на ставка: нето; ако ДДВ не е одбивлив (или фирмата не е ДДВ-обврзаник → ДДВ = 0) ставката носи нето + неодбивлив ДДВ.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Posting;

use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Services\Posting\PurchaseInvoicePostingContext;
use App\Support\Posting\ItemKind;
use App\Support\Posting\VatGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoicePostingContextTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(Company $company, array $attrs = []): PurchaseInvoice
    {
        $partner = Partner::factory()->for($company)->create(['name' => 'Добавувач']);

        return PurchaseInvoice::factory()->for($company)->create(array_merge(['partner_id' => $partner->id, 'supplier_invoice_number' => '77', 'invoice_date' => '2026-03-01'], $attrs));
    }

    private function expense(Company $company, string $code = '462'): Account
    {
        return Account::where('company_id', $company->id)->where('code', $code)->firstOrFail();
    }

    private function build(PurchaseInvoice $invoice)
    {
        return PurchaseInvoicePostingContext::build($invoice->load('lines.item', 'lines.account', 'partner', 'company'));
    }

    public function test_an_expense_line_with_deductible_vat(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->invoice($company);
        $invoice->lines()->create(['account_id' => $this->expense($company)->id, 'description' => 'Консалтинг', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $context = $this->build($invoice);

        $this->assertSame('1180.00', $context->totals['ВКУПНО']);
        $this->assertSame('0.00', $context->totals['ЗАЛИХА']);
        $this->assertSame('1000.00', $context->totals['ТРОШОК_СТАВКА']);
        $this->assertSame('180.00', $context->totals['ОДБИВЛИВ_ДДВ']);
        $this->assertCount(1, $context->accountBuckets);
        $this->assertSame('462', $context->accountBuckets[0]['account']->code);
        $this->assertSame('1000.00', $context->accountBuckets[0]['amount']);
        $this->assertCount(1, $context->slices);
        $this->assertSame(ItemKind::SERVICE, $context->slices[0]->itemKind);
        $this->assertSame(VatGroup::GENERAL, $context->slices[0]->vatGroup);
        $this->assertSame('180.00', $context->slices[0]->vat);
        $this->assertFalse($context->flags['has_goods']);
        $this->assertFalse($context->flags['import']);
        $this->assertSame('Purchase bill Добавувач #77', $context->documentLabel);
        $this->assertSame($invoice->partner_id, $context->partnerId);
    }

    public function test_a_stock_line_goes_to_the_stock_variable_and_two_lines_on_one_account_merge(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $item = Item::factory()->for($company)->create(['type' => 'product']);
        $invoice = $this->invoice($company, ['is_import' => true]);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => 'Стока', 'quantity' => '10', 'unit_price' => '50.00', 'vat_rate' => '5.00']);
        $invoice->lines()->create(['account_id' => $this->expense($company)->id, 'description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $invoice->lines()->create(['account_id' => $this->expense($company)->id, 'description' => 'B', 'quantity' => '1', 'unit_price' => '40.00', 'vat_rate' => '0']);

        $context = $this->build($invoice);

        $this->assertSame('500.00', $context->totals['ЗАЛИХА']);
        $this->assertSame('140.00', $context->totals['ТРОШОК_СТАВКА']);
        $this->assertSame('25.00', $context->totals['ОДБИВЛИВ_ДДВ']);
        $this->assertSame('665.00', $context->totals['ВКУПНО']);
        $this->assertCount(1, $context->accountBuckets);
        $this->assertSame('140.00', $context->accountBuckets[0]['amount']);
        $this->assertSame(ItemKind::GOODS, $context->slices[0]->itemKind);
        $this->assertSame(VatGroup::REDUCED, $context->slices[0]->vatGroup);
        $this->assertTrue($context->flags['has_goods']);
        $this->assertTrue($context->flags['import']);
    }

    public function test_non_deductible_vat_is_part_of_the_cost(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->invoice($company);
        $invoice->lines()->create(['account_id' => $this->expense($company)->id, 'description' => 'Репрезентација', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00', 'vat_deductible' => false]);

        $context = $this->build($invoice);

        $this->assertSame('1180.00', $context->accountBuckets[0]['amount']);
        $this->assertSame('0.00', $context->totals['ОДБИВЛИВ_ДДВ']);
        $this->assertSame('1180.00', $context->totals['ВКУПНО']);
        $this->assertSame([], $context->slices);
    }

    public function test_a_company_that_is_not_vat_registered_has_no_vat(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => false]);
        $invoice = $this->invoice($company);
        $invoice->lines()->create(['account_id' => $this->expense($company)->id, 'description' => 'Услуга', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $context = $this->build($invoice);

        $this->assertSame('1000.00', $context->totals['ВКУПНО']);
        $this->assertSame('0.00', $context->totals['ОДБИВЛИВ_ДДВ']);
        $this->assertSame([], $context->slices);
    }
}
```

> Ако колоната `vat_deductible` има DB-default `true` а моделот не го поставува во `$attributes`, `create()` без вредност дава `null` кај свеж модел, но од DB се чита `true` — тестовите читаат од DB (`$invoice->load(...)`), па е во ред. Ако првиот тест падне на `vat_deductible`, провери го default-от во миграцијата на `purchase_invoice_lines` и поправи ја претпоставката во тестот, не во кодот.

- [ ] **Step 2: Run — FAIL.** `php artisan test tests/Feature/Posting/PurchaseInvoicePostingContextTest.php`

- [ ] **Step 3: Implement** `app/Services/Posting/PurchaseInvoicePostingContext.php`:

```php
<?php

namespace App\Services\Posting;

use App\Exceptions\PostingSchemeException;
use App\Models\PurchaseInvoice;
use App\Support\Posting\ItemKind;
use App\Support\Posting\PostingContext;
use App\Support\Posting\PostingSlice;
use App\Support\Posting\VatGroup;

/**
 * Ги пресметува износите на влезна фактура за шемите за книжење. Истите
 * правила како досегашното книжење: ставка од залиха оди на залиха, останатите
 * на сметката што ја избрал корисникот, одбивливиот ДДВ посебно, а
 * неодбивливиот е дел од трошокот на ставката.
 */
final class PurchaseInvoicePostingContext
{
    public static function build(PurchaseInvoice $invoice): PostingContext
    {
        $vatRegistered = (bool) $invoice->company->is_vat_registered;

        $stock = '0.00';
        $hasGoods = false;
        $buckets = [];
        $slices = [];
        $deductibleVat = '0.00';

        foreach ($invoice->lines as $line) {
            $net = $line->lineTotal();
            $vat = $vatRegistered ? $line->vatAmount() : '0.00';
            $deductible = $vatRegistered && $line->vat_deductible;
            $cost = $deductible ? $net : bcadd($net, $vat, 2);
            $movesStock = $line->item_id !== null && ! $line->item->isService();

            if ($movesStock) {
                $hasGoods = true;
                $stock = bcadd($stock, $cost, 2);
            } else {
                if ($line->account === null) {
                    throw new PostingSchemeException('Ставка што не е артикл од залиха нема сметка за трошок.');
                }

                $buckets[$line->account_id] ??= ['account' => $line->account, 'amount' => '0.00'];
                $buckets[$line->account_id]['amount'] = bcadd($buckets[$line->account_id]['amount'], $cost, 2);
            }

            if ($deductible && bccomp($vat, '0', 2) !== 0) {
                $kind = $movesStock ? ItemKind::GOODS : ItemKind::SERVICE;
                $group = VatGroup::forLine('standard', (string) $line->vat_rate);
                $key = $kind->value.'|'.$group->value;

                $slices[$key] ??= new PostingSlice($kind, $group, '0.00', '0.00');
                $slices[$key]->base = bcadd($slices[$key]->base, $net, 2);
                $slices[$key]->vat = bcadd($slices[$key]->vat, $vat, 2);
                $deductibleVat = bcadd($deductibleVat, $vat, 2);
            }
        }

        $buckets = array_values($buckets);
        $costTotal = array_reduce($buckets, fn (string $carry, array $b) => bcadd($carry, $b['amount'], 2), '0.00');

        return new PostingContext(
            totals: [
                'ВКУПНО' => bcadd(bcadd($stock, $costTotal, 2), $deductibleVat, 2),
                'ЗАЛИХА' => $stock,
                'ТРОШОК_СТАВКА' => $costTotal,
                'ОДБИВЛИВ_ДДВ' => $deductibleVat,
            ],
            slices: array_values($slices),
            flags: ['has_goods' => $hasGoods, 'cash' => false, 'import' => (bool) $invoice->is_import],
            partnerId: $invoice->partner_id,
            documentLabel: "Purchase bill {$invoice->partner->name} #{$invoice->supplier_invoice_number}",
            accountBuckets: $buckets,
        );
    }
}
```

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Feature/Posting/PurchaseInvoicePostingContextTest.php`

- [ ] **Step 5: Commit**

```bash
git add app tests
git commit -m "Posting schemes: purchase invoice amounts builder"
```

---

### Task 3: Стандардни шеми за влезна фактура и исплата

**Files:**
- Modify: `app/Services/Posting/DefaultPostingSchemes.php`, `app/Services/Posting/PostingSchemes.php`
- Modify test: `tests/Feature/Posting/DefaultPostingSchemesTest.php`

**Interfaces:**
- Produces: `DefaultPostingSchemes::definition()` за сите четири вида. Влезна фактура (7 реда, 2 матрични): `line` Должи `ТРОШОК_СТАВКА`; 6600 Должи `ЗАЛИХА` (`not_import`); 6601 Должи `ЗАЛИХА` (`import`); матрица `INPUT_VAT` Должи `ДДВ` (`not_import`, опис `Input VAT on {фактура}`); 1302 Должи `ОДБИВЛИВ_ДДВ` (`import`, истиот опис); 2200 Побарува `ВКУПНО` (`not_import`); 2210 Побарува `ВКУПНО` (`import`). Сите со партнер и опис `{фактура}`. Матрица `INPUT_VAT`: општа 1300, повластена 1301. Исплата кон добавувач (3 реда): `invoice` Должи `ИЗНОС`; 1000 Побарува `ИЗНОС` (`not_cash`); 1020 Побарува `ИЗНОС` (`cash`); сите со партнер и опис `{фактура}`.
- Produces: `PostingSchemes::importStockAccount(Company $company): ?Account` — сметката на редот со услов `import` и формула `ЗАЛИХА` од шемата на влезна фактура (ја користи формата).

- [ ] **Step 1: Update tests** во `tests/Feature/Posting/DefaultPostingSchemesTest.php`:

1. Во `test_every_default_account_exists_and_is_analytical_in_the_chart` замени ја листата на видови со сите четири: `foreach (PostingDocType::cases() as $type)`.
2. **Избриши** `test_a_type_without_defaults_is_refused` (сите видови веќе имаат стандардна шема).
3. Додај:

```php
    public function test_the_purchase_schemes_are_created_lazily_with_their_rows(): void
    {
        $company = Company::factory()->create();

        $invoice = PostingSchemes::for($company, PostingDocType::PURCHASE_INVOICE);
        $payment = PostingSchemes::for($company, PostingDocType::PURCHASE_PAYMENT);

        $this->assertSame(7, $invoice->rows()->count());
        $this->assertSame(2, $invoice->matrixAccounts()->count());
        $this->assertSame(3, $payment->rows()->count());
    }

    public function test_the_import_stock_account_is_read_from_the_scheme(): void
    {
        $company = Company::factory()->create();

        $this->assertSame('6601', PostingSchemes::importStockAccount($company)->code);
    }

    public function test_the_default_purchase_scheme_balances_for_domestic_and_import_documents(): void
    {
        $company = Company::factory()->create();
        $scheme = PostingSchemes::for($company, PostingDocType::PURCHASE_INVOICE);
        $expense = Account::where('company_id', $company->id)->where('code', '4620')->firstOrFail();
        $slices = [
            new PostingSlice(ItemKind::GOODS, VatGroup::REDUCED, '500.00', '25.00'),
            new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '100.00', '18.00'),
        ];

        foreach ([false, true] as $import) {
            $context = new PostingContext(
                totals: ['ВКУПНО' => '643.00', 'ЗАЛИХА' => '500.00', 'ТРОШОК_СТАВКА' => '100.00', 'ОДБИВЛИВ_ДДВ' => '43.00'],
                slices: $slices,
                flags: ['has_goods' => true, 'cash' => false, 'import' => $import],
                partnerId: 1,
                documentLabel: 'Purchase bill X #1',
                accountBuckets: [['account' => $expense, 'amount' => '100.00']],
            );

            $codes = collect((new PostingSchemeEngine)->lines($scheme, $context))->map(fn ($l) => $l->account->code.($l->side === 'debit' ? ' D ' : ' C ').$l->amount)->all();

            $expected = $import
                ? ['4620 D 100.00', '6601 D 500.00', '1302 D 43.00', '2210 C 643.00']
                : ['4620 D 100.00', '6600 D 500.00', '1300 D 18.00', '1301 D 25.00', '2200 C 643.00'];
            $this->assertEqualsCanonicalizing($expected, $codes);
        }
    }
```

- [ ] **Step 2: Run — FAIL.** `php artisan test tests/Feature/Posting/DefaultPostingSchemesTest.php`

- [ ] **Step 3: Implement**

`DefaultPostingSchemes::definition` — замени го `default => throw …` со:

```php
            PostingDocType::PURCHASE_INVOICE => self::purchaseInvoice(),
            PostingDocType::PURCHASE_PAYMENT => self::purchasePayment(),
```
(тргни го `default` — `match` е исцрпен.) Додај методи:

```php
    private static function purchaseInvoice(): array
    {
        $vat = PostingMatrix::INPUT_VAT;

        return [
            'name' => 'Влезна фактура',
            'rows' => [
                ['mode' => 'line', 'side' => 'debit', 'formula' => 'ТРОШОК_СТАВКА', 'partner' => true, 'description' => '{фактура}'],
                ['mode' => 'fixed', 'account' => '6600', 'side' => 'debit', 'formula' => 'ЗАЛИХА', 'partner' => true, 'description' => '{фактура}', 'condition' => 'not_import'],
                ['mode' => 'fixed', 'account' => '6601', 'side' => 'debit', 'formula' => 'ЗАЛИХА', 'partner' => true, 'description' => '{фактура}', 'condition' => 'import'],
                ['mode' => 'matrix', 'matrix' => $vat, 'side' => 'debit', 'formula' => 'ДДВ', 'partner' => true, 'description' => 'Input VAT on {фактура}', 'condition' => 'not_import'],
                ['mode' => 'fixed', 'account' => '1302', 'side' => 'debit', 'formula' => 'ОДБИВЛИВ_ДДВ', 'partner' => true, 'description' => 'Input VAT on {фактура}', 'condition' => 'import'],
                ['mode' => 'fixed', 'account' => '2200', 'side' => 'credit', 'formula' => 'ВКУПНО', 'partner' => true, 'description' => '{фактура}', 'condition' => 'not_import'],
                ['mode' => 'fixed', 'account' => '2210', 'side' => 'credit', 'formula' => 'ВКУПНО', 'partner' => true, 'description' => '{фактура}', 'condition' => 'import'],
            ],
            'matrix' => [
                ['key' => $vat, 'kind' => null, 'group' => 'general', 'account' => '1300'],
                ['key' => $vat, 'kind' => null, 'group' => 'reduced', 'account' => '1301'],
            ],
        ];
    }

    private static function purchasePayment(): array
    {
        return [
            'name' => 'Исплата кон добавувач',
            'rows' => [
                ['mode' => 'invoice', 'side' => 'debit', 'formula' => 'ИЗНОС', 'partner' => true, 'description' => '{фактура}'],
                ['mode' => 'fixed', 'account' => '1000', 'side' => 'credit', 'formula' => 'ИЗНОС', 'partner' => true, 'description' => '{фактура}', 'condition' => 'not_cash'],
                ['mode' => 'fixed', 'account' => '1020', 'side' => 'credit', 'formula' => 'ИЗНОС', 'partner' => true, 'description' => '{фактура}', 'condition' => 'cash'],
            ],
            'matrix' => [],
        ];
    }
```

`PostingSchemes.php` — додај `use App\Models\Account;` и метод:

```php
    /** Залихата за увоз според шемата на влезна фактура (за ставки што се книжат како залиха при увоз). */
    public static function importStockAccount(Company $company): ?Account
    {
        return self::for($company, PostingDocType::PURCHASE_INVOICE)->rows()
            ->where('account_mode', 'fixed')
            ->where('condition', 'import')
            ->where('formula', 'ЗАЛИХА')
            ->with('account')
            ->first()?->account;
    }
```

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Feature/Posting`

- [ ] **Step 5: Commit**

```bash
git add app tests
git commit -m "Posting schemes: default purchase invoice and purchase payment schemes"
```

---

### Task 4: Влезната фактура се книжи преку шема

**Files:**
- Modify: `app/Services/Invoicing/PurchaseInvoiceService.php` (конструктор, `confirm`)
- Test: `tests/Feature/Posting/PurchaseInvoiceSchemePostingTest.php`; ажурирај `tests/Unit/PurchaseInvoiceServiceTest.php`, `tests/Feature/PurchaseInvoiceImportLandedCostTest.php`, `tests/Feature/PurchaseInvoiceShowTest.php`

**Interfaces:**
- Consumes: `PurchaseInvoicePostingContext::build`, `PostingSchemes::for`, `PostingSchemeEngine::lines`, `PostingLine::journalColumns`.
- Produces: `confirm()` создава налог со ставки од шемата. Залихата (`stockMovementService->receipt`, landed cost) и проверките пред транзакцијата остануваат неменети.

- [ ] **Step 1: Write the failing test** `tests/Feature/Posting/PurchaseInvoiceSchemePostingTest.php`:

```php
<?php

namespace Tests\Feature\Posting;

use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Posting\PostingSchemes;
use App\Support\Posting\PostingDocType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoiceSchemePostingTest extends TestCase
{
    use RefreshDatabase;

    private function draft(Company $company, array $attrs = []): PurchaseInvoice
    {
        $partner = Partner::factory()->for($company)->create(['name' => 'Добавувач']);

        return PurchaseInvoice::factory()->for($company)->create(array_merge([
            'partner_id' => $partner->id, 'supplier_invoice_number' => '77', 'invoice_date' => '2026-03-01',
        ], $attrs));
    }

    private function expenseId(Company $company): int
    {
        return Account::where('company_id', $company->id)->where('code', '4620')->value('id');
    }

    private function codes(PurchaseInvoice $confirmed): array
    {
        return $confirmed->journalEntry->lines()->with('account')->get()
            ->map(fn ($l) => $l->account->code.($l->debit > 0 ? ' D ' : ' C ').($l->debit > 0 ? $l->debit : $l->credit))
            ->all();
    }

    private function confirm(PurchaseInvoice $invoice): PurchaseInvoice
    {
        return app(PurchaseInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);
    }

    public function test_an_expense_bill_posts_to_the_chosen_account_1300_and_2200(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->draft($company);
        $invoice->lines()->create(['account_id' => $this->expenseId($company), 'description' => 'Консалтинг', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $this->assertEqualsCanonicalizing(['4620 D 1000.00', '1300 D 180.00', '2200 C 1180.00'], $this->codes($this->confirm($invoice)));
    }

    public function test_the_reduced_rate_goes_to_1301(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->draft($company);
        $invoice->lines()->create(['account_id' => $this->expenseId($company), 'description' => 'X', 'quantity' => '1', 'unit_price' => '200.00', 'vat_rate' => '5.00']);

        $this->assertEqualsCanonicalizing(['4620 D 200.00', '1301 D 10.00', '2200 C 210.00'], $this->codes($this->confirm($invoice)));
    }

    public function test_domestic_goods_go_to_6600(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create(['type' => 'product']);
        $invoice = $this->draft($company, ['warehouse_id' => $warehouse->id]);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => 'Стока', 'quantity' => '10', 'unit_price' => '50.00', 'vat_rate' => '18.00']);

        $this->assertEqualsCanonicalizing(['6600 D 500.00', '1300 D 90.00', '2200 C 590.00'], $this->codes($this->confirm($invoice)));
    }

    public function test_import_goods_go_to_6601_vat_to_1302_and_the_debt_to_2210(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create(['type' => 'product']);
        $invoice = $this->draft($company, ['warehouse_id' => $warehouse->id, 'is_import' => true]);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => 'Стока', 'quantity' => '10', 'unit_price' => '50.00', 'vat_rate' => '18.00']);

        $this->assertEqualsCanonicalizing(['6601 D 500.00', '1302 D 90.00', '2210 C 590.00'], $this->codes($this->confirm($invoice)));
    }

    public function test_non_deductible_vat_stays_in_the_cost(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->draft($company);
        $invoice->lines()->create(['account_id' => $this->expenseId($company), 'description' => 'Репрезентација', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00', 'vat_deductible' => false]);

        $this->assertEqualsCanonicalizing(['4620 D 1180.00', '2200 C 1180.00'], $this->codes($this->confirm($invoice)));
    }

    public function test_the_entry_keeps_group_99_the_description_the_partner_and_the_labels(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->draft($company);
        $invoice->lines()->create(['account_id' => $this->expenseId($company), 'description' => 'X', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $confirmed = $this->confirm($invoice);
        $entry = $confirmed->journalEntry()->with('lines', 'journalGroup')->first();

        $this->assertSame('99', $entry->journalGroup->code);
        $this->assertSame('Purchase bill Добавувач #77', $entry->description);
        $this->assertTrue($entry->lines->every(fn ($l) => $l->partner_id === $invoice->partner_id));
        $this->assertTrue($entry->lines->contains(fn ($l) => $l->description === 'Input VAT on Purchase bill Добавувач #77'));
    }

    public function test_changing_the_supplier_number_still_renames_the_lines(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->draft($company);
        $invoice->lines()->create(['account_id' => $this->expenseId($company), 'description' => 'X', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);
        $service = app(PurchaseInvoiceService::class);
        $confirmed = $service->confirm($invoice->fresh(), User::factory()->create()->id);

        $service->changeSupplierNumber($confirmed, '99', User::factory()->create()->id);

        $this->assertTrue($confirmed->fresh()->journalEntry->lines->every(fn ($l) => str_contains($l->description, '#99')));
    }

    public function test_a_changed_scheme_changes_the_next_posting_only(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $first = $this->draft($company);
        $first->lines()->create(['account_id' => $this->expenseId($company), 'description' => 'X', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);
        $confirmedFirst = $this->confirm($first);
        $scheme = PostingSchemes::for($company, PostingDocType::PURCHASE_INVOICE);
        $other = Account::where('company_id', $company->id)->where('code', '1301')->firstOrFail();
        $scheme->matrixAccounts()->where('matrix_key', 'input_vat')->where('vat_group', 'general')->update(['account_id' => $other->id]);
        $second = $this->draft($company, ['supplier_invoice_number' => '78']);
        $second->lines()->create(['account_id' => $this->expenseId($company), 'description' => 'X', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $this->assertContains('1300 D 18.00', $this->codes($confirmedFirst->fresh()));
        $this->assertContains('1301 D 18.00', $this->codes($this->confirm($second)));
    }
}
```

- [ ] **Step 2: Run — FAIL.** `php artisan test tests/Feature/Posting/PurchaseInvoiceSchemePostingTest.php`

- [ ] **Step 3: Implement** во `app/Services/Invoicing/PurchaseInvoiceService.php`:

Конструктор:

```php
    public function __construct(
        private StockMovementService $stockMovementService,
        private PostingSchemeEngine $postingEngine,
    ) {}
```
(+ `use App\Services\Posting\PostingSchemeEngine; use App\Services\Posting\PostingSchemes; use App\Services\Posting\PurchaseInvoicePostingContext; use App\Support\Posting\PostingDocType;` преку Edit.) Провери: `grep -rn "new PurchaseInvoiceService" app tests` — ако има, додај втор аргумент `new PostingSchemeEngine`.

Во `confirm()` во транзакцијата:
1. Во `foreach ($invoice->lines as $line)` задржи ја само залихата: остави `$movement = …receipt(…)` и `$line->update(['stock_movement_id' => $movement->id]);` под `if ($this->movesStock($line))`; избриши ги `$lineNet`, `$lineVat`, `$deductible`, `$targetAccount`, `$debitAmount`, `$debitsByAccountId`, `$vatTotal` и целиот `else { $targetAccount = $line->account; }`. Од променливите горе остави само `$landedCosts` (избриши `$vatRegistered`, `$vatTotal`, `$debitsByAccountId`).
2. Од `$supplierRef = …` до вториот `$entry->lines()->create` (за 220) замени со:

```php
            $context = PurchaseInvoicePostingContext::build($invoice);
            $label = $context->documentLabel;

            $entry = JournalEntry::create([
                'company_id' => $invoice->company_id,
                'journal_group_id' => $this->systemJournalGroup($invoice->company)->id,
                'entry_date' => $invoice->invoice_date,
                'description' => $label,
                'created_by' => $userId,
            ]);

            foreach ($this->postingEngine->lines(PostingSchemes::for($invoice->company, PostingDocType::PURCHASE_INVOICE), $context) as $line) {
                $entry->lines()->create($line->journalColumns($invoice->invoice_date));
            }
```
Важно: контекстот се гради ПО циклусот за залиха и ПОСЛЕ `loadMissing(['lines.account', …])` — веќе се вчитани. `$invoice->update([...journal_entry_id, status])` и `return` остануваат.

- [ ] **Step 4: Run — новиот тест PASS.** `php artisan test tests/Feature/Posting/PurchaseInvoiceSchemePostingTest.php`

- [ ] **Step 5: Ажурирај ги постојните тестови.** Пушти: `php artisan test tests/Unit/PurchaseInvoiceServiceTest.php tests/Feature/PurchaseInvoiceImportLandedCostTest.php tests/Feature/PurchaseInvoiceShowTest.php`. Падовите се само од стари конта: `130` → `1300` (`1301` за 5%/10%, `1302` за увозна фактура), `220` → `2200` (`2210` за увозна), `660` → `6600` (`6601` за увозна), `102` → `1020`. Тестови за **плаќање** (`recordPayment`) уште очекуваат `220`/`102` — тоа е Task 5; остави ги црвени до тогаш. **Износите не се менуваат** — ако износ падне, значи грешка во кодот, не во тестот.

- [ ] **Step 6: Commit**

```bash
git add app tests
git commit -m "Purchase invoice posting goes through the posting scheme"
```

---

### Task 5: Исплата преку шема + изводот ја следи сметката на фактурата

**Files:**
- Create: `app/Services/Posting/PurchasePaymentPostingContext.php`
- Modify: `app/Services/Posting/PostedInvoiceAccounts.php`, `app/Services/Invoicing/PurchaseInvoiceService.php` (`recordPayment`), `app/Services/Bank/BankStatementPoster.php`
- Test: `tests/Feature/Posting/PurchasePaymentSchemePostingTest.php`; ажурирај `tests/Unit/PurchaseInvoiceServiceTest.php`, `tests/Feature/Bank/BankStatementPosterTest.php`

**Interfaces:**
- Produces: `PostedInvoiceAccounts::payable(PurchaseInvoice $invoice): Account` — сметката на којашто фактурата ја отворила обврската (првата ставка Побарува на нејзиниот налог со партнерот на фактурата); без налог → аналитичко `2210` ако е увоз, инаку `2200`.
- Produces: `PurchasePaymentPostingContext::build(PurchaseInvoice $invoice, string $amount, bool $cash, string $label, Account $payable): PostingContext` (променлива `ИЗНОС`; знаме `cash`; `invoiceAccount` = `$payable`; `documentLabel` = `$label`; без девизни износи).
- `BankStatementPoster`: за исплата (`OUT`) спротивното конто е `PostedInvoiceAccounts::payable($invoice)` наместо `220`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Posting/PurchasePaymentSchemePostingTest.php`:

```php
<?php

namespace Tests\Feature\Posting;

use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Posting\PostedInvoiceAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchasePaymentSchemePostingTest extends TestCase
{
    use RefreshDatabase;

    private function confirmed(Company $company, array $attrs = []): PurchaseInvoice
    {
        $partner = Partner::factory()->for($company)->create(['name' => 'Добавувач']);
        $invoice = PurchaseInvoice::factory()->for($company)->create(array_merge(['partner_id' => $partner->id, 'supplier_invoice_number' => '77', 'invoice_date' => '2026-03-01'], $attrs));
        $invoice->lines()->create(['account_id' => Account::where('company_id', $company->id)->where('code', '4620')->value('id'), 'description' => 'X', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);

        return app(PurchaseInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);
    }

    private function paymentEntry(PurchaseInvoice $invoice): JournalEntry
    {
        return JournalEntry::where('company_id', $invoice->company_id)->where('id', '!=', $invoice->journal_entry_id)->with('lines.account')->latest('id')->firstOrFail();
    }

    public function test_a_bank_payment_posts_2200_against_1000(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->confirmed($company);

        app(PurchaseInvoiceService::class)->recordPayment($invoice, '60.00', '2026-03-10', 'bank', User::factory()->create()->id);

        $entry = $this->paymentEntry($invoice);
        $this->assertSame('60.00', (string) $entry->lines->firstWhere('account.code', '2200')->debit);
        $this->assertSame('60.00', (string) $entry->lines->firstWhere('account.code', '1000')->credit);
        $this->assertSame('Payment for purchase bill Добавувач #77', $entry->description);
        $this->assertTrue($entry->lines->every(fn ($l) => $l->partner_id === $invoice->partner_id));
    }

    public function test_a_cash_payment_posts_to_1020(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->confirmed($company);

        app(PurchaseInvoiceService::class)->recordPayment($invoice, '100.00', '2026-03-10', 'cash', User::factory()->create()->id);

        $this->assertNotNull($this->paymentEntry($invoice)->lines->firstWhere('account.code', '1020'));
    }

    public function test_an_import_invoice_is_paid_against_2210(): void
    {
        $company = Company::factory()->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $invoice = $this->confirmed($company, ['is_import' => true, 'warehouse_id' => $warehouse->id]);

        $this->assertSame('2210', PostedInvoiceAccounts::payable($invoice->fresh())->code);

        app(PurchaseInvoiceService::class)->recordPayment($invoice, '100.00', '2026-03-10', 'bank', User::factory()->create()->id);

        $this->assertSame('100.00', (string) $this->paymentEntry($invoice)->lines->firstWhere('account.code', '2210')->debit);
    }

    public function test_a_payment_on_an_invoice_booked_on_220_before_the_schemes_closes_220(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Стар']);
        $legacy = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-01-10', 'status' => 'confirmed', 'supplier_invoice_number' => '5']);
        $legacy->lines()->create(['account_id' => Account::where('company_id', $company->id)->where('code', '4620')->value('id'), 'description' => 'Стара', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $group = JournalGroup::firstOrCreate(['company_id' => $company->id, 'code' => '99'], ['name' => 'Автоматски', 'sort_order' => 99]);
        $entry = JournalEntry::create(['company_id' => $company->id, 'journal_group_id' => $group->id, 'entry_date' => '2026-01-10', 'description' => 'Purchase bill Стар #5', 'created_by' => User::factory()->create()->id]);
        $entry->lines()->create(['account_id' => Account::where('company_id', $company->id)->where('code', '4620')->value('id'), 'partner_id' => $partner->id, 'line_date' => '2026-01-10', 'debit' => '100.00', 'credit' => '0', 'description' => 'Purchase bill Стар #5']);
        $entry->lines()->create(['account_id' => Account::where('company_id', $company->id)->where('code', '220')->value('id'), 'partner_id' => $partner->id, 'line_date' => '2026-01-10', 'debit' => '0', 'credit' => '100.00', 'description' => 'Purchase bill Стар #5']);
        $legacy->update(['journal_entry_id' => $entry->id]);

        $this->assertSame('220', PostedInvoiceAccounts::payable($legacy->fresh())->code);

        app(PurchaseInvoiceService::class)->recordPayment($legacy->fresh(), '100.00', '2026-02-01', 'bank', User::factory()->create()->id);

        $payment = $this->paymentEntry($legacy);
        $this->assertSame('100.00', (string) $payment->lines->firstWhere('account.code', '220')->debit);
        $this->assertNull($payment->lines->firstWhere('account.code', '2200'));
    }

    public function test_a_draft_invoice_falls_back_to_2200(): void
    {
        $company = Company::factory()->create();
        $draft = PurchaseInvoice::factory()->for($company)->create();

        $this->assertSame('2200', PostedInvoiceAccounts::payable($draft)->code);
    }
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement**

`PostedInvoiceAccounts.php` — додај `use App\Models\PurchaseInvoice;` и метод:

```php
    /** Сметката на којашто влезната фактура ја отворила обврската кон добавувачот. */
    public static function payable(PurchaseInvoice $invoice): Account
    {
        $entry = $invoice->journalEntry()->with('lines.account')->first();

        $line = $entry?->lines
            ->first(fn ($l) => bccomp((string) $l->credit, '0', 2) > 0 && $l->partner_id === $invoice->partner_id);

        if ($line?->account !== null) {
            return $line->account;
        }

        return Account::where('company_id', $invoice->company_id)->analytical()->where('code', $invoice->is_import ? '2210' : '2200')->firstOrFail();
    }
```

`app/Services/Posting/PurchasePaymentPostingContext.php`:

```php
<?php

namespace App\Services\Posting;

use App\Models\Account;
use App\Support\Posting\PostingContext;
use App\Models\PurchaseInvoice;

/** Износи на една исплата кон добавувач за шемата PURCHASE_PAYMENT. */
final class PurchasePaymentPostingContext
{
    public static function build(PurchaseInvoice $invoice, string $amount, bool $cash, string $label, Account $payable): PostingContext
    {
        return new PostingContext(
            totals: ['ИЗНОС' => $amount],
            flags: ['has_goods' => false, 'cash' => $cash, 'import' => (bool) $invoice->is_import],
            partnerId: $invoice->partner_id,
            documentLabel: $label,
            invoiceAccount: $payable,
        );
    }
}
```

`PurchaseInvoiceService::recordPayment` — од `$cashOrBankCode = …` до вториот `$entry->lines()->create` (за готово/банка) замени со:

```php
            $label = "Payment for purchase bill {$invoice->partner->name} #{$invoice->supplier_invoice_number}";

            $entry = JournalEntry::create([
                'company_id' => $invoice->company_id,
                'journal_group_id' => $this->systemJournalGroup($invoice->company)->id,
                'entry_date' => $paymentDate,
                'description' => $label,
                'created_by' => $userId,
            ]);

            $context = PurchasePaymentPostingContext::build($invoice, $amount, $paymentMethod === 'cash', $label, PostedInvoiceAccounts::payable($invoice));

            foreach ($this->postingEngine->lines(PostingSchemes::for($invoice->company, PostingDocType::PURCHASE_PAYMENT), $context) as $line) {
                $entry->lines()->create($line->journalColumns($paymentDate));
            }
```
(+ `use App\Services\Posting\PostedInvoiceAccounts; use App\Services\Posting\PurchasePaymentPostingContext;`). Провери дали `Account` и `Company` уште се користат (`account()` метод) — ако `account()` е неупотребен, избриши го заедно со нивните `use`.

`BankStatementPoster::postLine` — гранката со `$isIn ? receivable($invoice) : $this->account($statement, '220')` стане:

```php
            $counter = $isIn
                ? PostedInvoiceAccounts::receivable($invoice)
                : PostedInvoiceAccounts::payable($invoice);
```
(и ажурирај го коментарот над него: „…обврската (2200/2210, или 220 за стара фактура)“).

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Feature/Posting/PurchasePaymentSchemePostingTest.php`

- [ ] **Step 5: Ажурирај ги постојните тестови.** Пушти: `php artisan test tests/Unit/PurchaseInvoiceServiceTest.php tests/Feature/PurchaseInvoiceImportLandedCostTest.php tests/Feature/PurchaseInvoiceShowTest.php tests/Feature/Bank tests/Feature/Posting`. Мапирање за исплати: `220` → `2200`, готово `102` → `1020`. Износите не се менуваат.

- [ ] **Step 6: Commit**

```bash
git add app tests
git commit -m "Purchase payments go through the scheme and follow the invoice's payable account"
```

---

### Task 6: Формата — залихата за увозни трошоци од шемата

**Files:**
- Modify: `app/Livewire/Invoicing/PurchaseInvoiceForm.php` (околу ред 816)
- Modify test: `tests/Feature/Invoicing/PurchaseInvoiceImportScanTest.php`

- [ ] **Step 1:** Пушти го тестот и најди ги очекувањата на `'660'`: `php artisan test tests/Feature/Invoicing/PurchaseInvoiceImportScanTest.php` (тие што проверуваат дека ставката „транспорт/осигурување“ на увозна фактура е на сметка 660). Ажурирај ги на `6601`.

- [ ] **Step 2: Implement.** Во `PurchaseInvoiceForm` замени:

```php
            $chargeAccountId = (string) (Account::where('company_id', $this->company->id)->where('code', '660')->value('id') ?? '');
```
со:

```php
            $chargeAccountId = (string) (PostingSchemes::importStockAccount($this->company)?->id ?? '');
```
и ажурирај го коментарот над него („…на сметка 660“ → „…на залихата за увоз од шемата (6601)“). Додај `use App\Services\Posting\PostingSchemes;` ако нема; ако `Account` повеќе не се користи во датотеката, тргни го `use`.

- [ ] **Step 3: Run — PASS.** `php artisan test tests/Feature/Invoicing/PurchaseInvoiceImportScanTest.php tests/Feature/PurchaseInvoiceImportLandedCostTest.php`

- [ ] **Step 4: Commit**

```bash
git add app tests
git commit -m "Purchase form: import transport charge goes to the scheme's import stock account"
```

---

### Task 7: Документација, целосна серија, спојување

**Files:**
- Modify: `docs/superpowers/specs/2026-10-06-posting-schemes-design.md` (под „Одстапувања при градењето“ додај „дел 2“)
- Create: `docs/superpowers/2026-10-06-posting-schemes-part2-log.md`

- [ ] **Step 1: Одстапувања (дел 2)** — запиши:
  1. Начин на конто `line`: не се проверува аналитичност (корисникот ја избира сметката на ставката; постојните влезни фактури книжат на подгрупи како 462). **Отворено прашање за сопственикот:** да се ограничи ли изборот на ставката на аналитички конта во формата?
  2. Променливи за влезна: `ВКУПНО`, `ЗАЛИХА`, `ТРОШОК_СТАВКА`, `ОДБИВЛИВ_ДДВ` (не `ЦАРИНА`/`УВОЗЕН ДДВ`: царината и увозниот ДДВ од ЕЦД не се книжат на фактурата — сопственикот одлучи да ги внесува шпедитерските/царинските фактури како обична влезна фактура; не се менува).
  3. Увоз: ставка со артикл → 6601, ДДВ на фактурата → 1302, обврска → 2210 (условот `import` = `purchase_invoices.is_import`).
  4. Обврската при исплата е онаму каде што е отворена (`PostedInvoiceAccounts::payable`), 220 за стари фактури.
  5. Исплата на готово 1020; банка 1000.

- [ ] **Step 2: Лог** `docs/superpowers/2026-10-06-posting-schemes-part2-log.md`: што е направено (6 задачи), мапирање на конта, дефекти во планот фатени при градењето (ако ги има), отворено прашање (1), ненаправено (дел 3 екран, дел 4 комплет; „ставки од извод по конто“ не е во обем).

- [ ] **Step 3: Целосна серија** — **прво прашај го корисникот** („пуштам целосна серија, ~14 мин“), па `php artisan test` на гранката. Очекувано: сè зелено, 3 прескокнати. Не ја пушти во позадина ако ќе заврши твојот ред — чекај.

- [ ] **Step 4: Commit, спој и пушти** (зелена серија → спој во `main` и пушти, без прашање за спојот):

```bash
git add docs
git commit -m "Docs: posting schemes part 2 spec amendments and log"
git checkout main && git merge --no-ff posting-schemes-part2 -m "Merge posting schemes part 2: purchase invoice, import and payment" && git push origin main
```

CI се следи еднаш (`gh run list`). Сними во меморија што е готово и што останува.
