# Книжење на денарски изводи (фаза 1) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Денарски извод од банка да се внесе рачно како ставки и да се прокнижи како еден налог (група 10, 11, 12… по банкарска сметка) на конто 1000, со контроли на состојбите.

**Architecture:** Постоечкиот `BankStatement` (само фајл + број + датум) добива почетна/крајна состојба, статус и врска кон налог; нова табела `bank_statement_lines` ги држи ставките. Еден сервис `BankStatementPoster` го создава налогот во една трансакција; чиста класа `StatementControls` ги дава блокирачките проблеми; еден Livewire екран ги уредува ставките. Банкарските плаќања од фактури се префрлаат од конто 100 на 1000.

**Tech Stack:** Laravel 13, Livewire 3/4 (страници како `[Class::class, '__invoke']`), SQLite (тест) и MySQL (продукција), bcmath низи за пари, PHPUnit.

Спецификација: `docs/superpowers/specs/2026-10-05-bank-statement-posting-design.md`. Прочитај ја прво.

## Global Constraints

- Сите текстови што ги гледа корисник — **строг македонски** (не бугарски зборови). Коментарите во кодот — македонски, како околу нив.
- Пари: **bcmath низи**, никогаш float. Заокружување: `App\Support\Bcmath::roundHalfUp($v, 2)`. `decimal(15,2)` во база.
- Книжење **само на аналитички конта** (`accounts.is_analytical = true`). Банката е секогаш конто **1000** (`Account::BANK_CODE`).
- Исклучок оваа фаза: страната на фактурите останува **120** (излезна) и **220** (влезна).
- Налози на изводи: група со код **10, 11, 12…** (по една по банкарска сметка; кодот е 2 знаци, `journal_groups.code`). Не се користи група 99 (автоматски) ниту 00 (почетна состојба).
- Знак на состојби: од **наша** гледна точка (позитивно = пари на сметка). Изводот е од гледна точка на банката: „Побарува“ на изводот = плус кај нас, „Долгува“ = минус.
- Почетната состојба на изводот **не се книжи**; се книжи само движењето. Годишната почетна состојба е налог 00-0001 (рачно, надвор од овој план).
- Само **денарски** изводи (`BankStatementKind::DENAR`). Девизни изводи — не се допираат.
- Eloquent: **`$attributes` мора да се постави во моделот** за секоја колона со DB-default (DB-default не го полни свеж модел во меморија).
- MySQL: имињата на индекси ≤ 64 знаци; зададувај кратки имиња на композитни/unique индекси.
- Тестови: `php artisan test <датотека>` само за датотеката што ја менуваш. **Целата серија се пушта еднаш на крај и прво се прашува корисникот** (трае ~14 мин).
- Коментари `// NOTE`/логика на постоечки код не се бришат без причина. Не се менуваат автоматските книжења на фактури освен 100→1000 за банкарско плаќање.
- Кон секој commit: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

---

### Task 1: Банкарските плаќања од фактури одат на 1000

**Files:**
- Modify: `app/Models/Account.php`
- Modify: `app/Services/Invoicing/SalesInvoiceService.php:352`
- Modify: `app/Services/Invoicing/PurchaseInvoiceService.php:279`
- Modify: `tests/Unit/SalesInvoiceServiceTest.php:332` (и `seedAccounts`)
- Modify: `tests/Unit/PurchaseInvoiceServiceTest.php:542` (и `seedAccounts`)
- Create: `tests/Feature/Bank/BankPaymentAccountTest.php`

**Interfaces:**
- Produces: `Account::BANK_CODE` (`'1000'`), `Account::scopeAnalytical()`; банкарско плаќање од фактура книжи на 1000.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Bank/BankPaymentAccountTest.php`:

```php
<?php

namespace Tests\Feature\Bank;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankPaymentAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bank_payment_on_a_sales_invoice_posts_to_1000_not_100(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $service = app(SalesInvoiceService::class);
        $confirmed = $service->confirm($invoice->fresh(), $user->id);

        $service->recordPayment($confirmed, '100.00', '2026-03-10', 'bank', $user->id);

        $entry = JournalEntry::where('company_id', $company->id)->where('id', '!=', $confirmed->journal_entry_id)->with('lines.account')->first();
        $this->assertNotNull($entry->lines->firstWhere('account.code', '1000'));
        $this->assertNull($entry->lines->firstWhere('account.code', '100'));
    }

    public function test_a_bank_payment_on_a_purchase_invoice_posts_to_1000_not_100(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $expense = Account::where('company_id', $company->id)->where('code', '462')->firstOrFail();
        $user = User::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => $expense->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $service = app(PurchaseInvoiceService::class);
        $confirmed = $service->confirm($invoice->fresh(), $user->id);

        $service->recordPayment($confirmed, '100.00', '2026-03-10', 'bank', $user->id);

        $entry = JournalEntry::where('company_id', $company->id)->where('id', '!=', $confirmed->journal_entry_id)->with('lines.account')->first();
        $this->assertNotNull($entry->lines->firstWhere('account.code', '1000'));
        $this->assertNull($entry->lines->firstWhere('account.code', '100'));
    }

    public function test_cash_payments_stay_on_102(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $service = app(SalesInvoiceService::class);
        $confirmed = $service->confirm($invoice->fresh(), $user->id);

        $service->recordPayment($confirmed, '100.00', '2026-03-10', 'cash', $user->id);

        $entry = JournalEntry::where('company_id', $company->id)->where('id', '!=', $confirmed->journal_entry_id)->with('lines.account')->first();
        $this->assertNotNull($entry->lines->firstWhere('account.code', '102'));
    }

    public function test_the_account_scope_keeps_only_analytical_accounts(): void
    {
        $company = Company::factory()->create();

        $this->assertTrue(Account::where('company_id', $company->id)->analytical()->where('code', '1000')->exists());
        $this->assertFalse(Account::where('company_id', $company->id)->analytical()->where('code', '100')->exists());
        $this->assertSame('1000', Account::BANK_CODE);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Bank/BankPaymentAccountTest.php`
Expected: FAIL (`Account::BANK_CODE` недефинирана / нема 1000 во налогот).

- [ ] **Step 3: Implement**

`app/Models/Account.php` — додај по `LEVEL_ACCOUNT`:

```php
    /** Конто на кое се книжат сите банкарски движења (трансакциска сметка во денари). */
    public const BANK_CODE = '1000';
```

и по `scopePostable`:

```php
    /** Само аналитичките конта (листовите) примаат книжење од изводи. */
    public function scopeAnalytical(Builder $query): void
    {
        $query->where('is_analytical', true);
    }
```

`SalesInvoiceService.php:352` и `PurchaseInvoiceService.php:279`:

```php
            $cashOrBankCode = $paymentMethod === 'cash' ? '102' : Account::BANK_CODE;
```

(увези `App\Models\Account` ако фали.)

- [ ] **Step 4: Fix the two old unit tests**

`tests/Unit/SalesInvoiceServiceTest.php:332`: `firstWhere('account.code', '100')` → `'1000'`. Во `seedAccounts` ставката `['code' => '100', 'name' => 'Bank']` → `['code' => '1000', 'name' => 'Bank']`.
`tests/Unit/PurchaseInvoiceServiceTest.php:542` — исто. Во `tests/Feature/SalesInvoiceShowTest.php:32` додај `'1000'` во листата.

- [ ] **Step 5: Run all four files**

Run: `php artisan test tests/Feature/Bank/BankPaymentAccountTest.php tests/Unit/SalesInvoiceServiceTest.php tests/Unit/PurchaseInvoiceServiceTest.php tests/Feature/SalesInvoiceShowTest.php tests/Feature/ForeignCurrencyInvoiceTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Account.php app/Services/Invoicing/SalesInvoiceService.php app/Services/Invoicing/PurchaseInvoiceService.php tests
git commit -m "Bank payments from invoices post to 1000 instead of the 100 heading"
```

---

### Task 2: Еднократно преместување на старите плаќања од 100 на 1000

**Files:**
- Create: `app/Console/Commands/MoveBankPaymentsTo1000.php`
- Create: `tests/Feature/Bank/MoveBankPaymentsTo1000Test.php`

**Interfaces:**
- Produces: `php artisan bank:move-payments-to-1000 [--apply]` — без `--apply` само брои и прикажува; со `--apply` ги преместува.

Преместува **само** редови на конто 100 кои припаѓаат на автоматски налози за плаќање од фактури: налог во група со код `99` и опис што почнува со `Payment for`. Сите други редови на 100 (рачни налози) **се пријавуваат, не се допираат**.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Bank;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoveBankPaymentsTo1000Test extends TestCase
{
    use RefreshDatabase;

    private function entry(Company $company, string $groupCode, string $description, string $accountCode): JournalEntry
    {
        $group = JournalGroup::firstOrCreate(['company_id' => $company->id, 'code' => $groupCode], ['name' => 'G', 'sort_order' => 1]);
        $entry = JournalEntry::create([
            'company_id' => $company->id,
            'journal_group_id' => $group->id,
            'entry_date' => '2026-03-10',
            'description' => $description,
        ]);
        $account = Account::where('company_id', $company->id)->where('code', $accountCode)->firstOrFail();
        $entry->lines()->create(['account_id' => $account->id, 'line_date' => '2026-03-10', 'debit' => '50.00', 'credit' => '0']);

        return $entry;
    }

    public function test_dry_run_changes_nothing(): void
    {
        $company = Company::factory()->create();
        $entry = $this->entry($company, '99', 'Payment for invoice 5', '100');

        $this->artisan('bank:move-payments-to-1000')->assertSuccessful();

        $this->assertSame('100', $entry->lines()->first()->account->code);
    }

    public function test_apply_moves_only_automatic_payment_lines(): void
    {
        $company = Company::factory()->create();
        $auto = $this->entry($company, '99', 'Payment for invoice 5', '100');
        $manual = $this->entry($company, '05', 'Рачен налог', '100');
        $other = $this->entry($company, '99', 'Sales invoice 5', '120');

        $this->artisan('bank:move-payments-to-1000', ['--apply' => true])->assertSuccessful();

        $this->assertSame('1000', $auto->lines()->first()->account->code);
        $this->assertSame('100', $manual->lines()->first()->account->code);
        $this->assertSame('120', $other->lines()->first()->account->code);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Bank/MoveBankPaymentsTo1000Test.php`
Expected: FAIL (командата не постои).

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntryLine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Еднократно: автоматските банкарски плаќања од фактури беа книжени на 100
 * (наслов), а од сега одат на 1000. Рачните налози не се допираат — само се
 * пријавуваат, за сметководителот да реши.
 */
class MoveBankPaymentsTo1000 extends Command
{
    protected $signature = 'bank:move-payments-to-1000 {--apply : Изврши ја промената (без ова само брои)}';

    protected $description = 'Ги преместува автоматските банкарски плаќања од конто 100 на 1000';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        foreach (Company::query()->cursor() as $company) {
            $heading = Account::where('company_id', $company->id)->where('code', '100')->first();
            $bank = Account::where('company_id', $company->id)->where('code', Account::BANK_CODE)->first();

            if ($heading === null || $bank === null) {
                continue;
            }

            $onHeading = JournalEntryLine::where('account_id', $heading->id);
            $automatic = (clone $onHeading)->whereHas('journalEntry', fn ($entry) => $entry
                ->where('description', 'like', 'Payment for%')
                ->whereHas('journalGroup', fn ($group) => $group->where('code', '99')));

            $moving = (clone $automatic)->count();
            $manual = (clone $onHeading)->count() - $moving;

            if ($moving === 0 && $manual === 0) {
                continue;
            }

            $this->line("{$company->name}: за преместување {$moving}, рачни редови на 100 (не се допираат) {$manual}");

            if ($apply && $moving > 0) {
                DB::transaction(fn () => $automatic->update(['account_id' => $bank->id]));
            }
        }

        $this->info($apply ? 'Готово.' : 'Пробно извршување — ништо не е променето. Додај --apply за вистинска промена.');

        return self::SUCCESS;
    }
}
```

`whereHas('journalEntry'…)` бара релација `journalEntry()` на `JournalEntryLine` (постои — види `app/Models/JournalEntryLine.php`).

- [ ] **Step 4: Run — PASS**

Run: `php artisan test tests/Feature/Bank/MoveBankPaymentsTo1000Test.php`

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/MoveBankPaymentsTo1000.php tests/Feature/Bank/MoveBankPaymentsTo1000Test.php
git commit -m "Command to move automatic bank payment lines from 100 to 1000"
```

> **Не се пушта во продукција во овој план.** По спојувањето, корисникот одлучува кога да се изврши `--apply` (прво пробно).

---

### Task 3: Шема, модели и фабрики

**Files:**
- Create: `database/migrations/2026_10_06_100000_add_booking_to_bank_statements_table.php`
- Create: `database/migrations/2026_10_06_100100_create_bank_statement_lines_table.php`
- Create: `app/Support/Bank/LineDirection.php`, `app/Support/Bank/LineKind.php`
- Create: `app/Models/BankStatementLine.php`
- Create: `database/factories/BankStatementLineFactory.php`
- Modify: `app/Models/BankStatement.php`, `app/Models/CompanyBankAccount.php`
- Modify: `tests/Feature/Bank/BankStatementTest.php` (тестот „carries no balances“)
- Create: `tests/Feature/Bank/BankStatementLineTest.php`

**Interfaces:**
- Produces:
  - `BankStatement::STATUS_DRAFT`/`STATUS_BOOKED`, колони `opening_balance`, `closing_balance` (decimal string|null), `status`, `journal_entry_id`; методи `lines(): HasMany` (по `position, id`), `journalEntry(): BelongsTo`, `isBooked(): bool`, `movement(): string` (збир уплати − исплати, 2 децимали).
  - `BankStatementLine` — колони: `bank_statement_id, position, line_date, direction (LineDirection), amount, partner_id, description, reference_number, purpose_code, kind (LineKind), account_id, sales_invoice_id, purchase_invoice_id, sales_invoice_payment_id, purchase_invoice_payment_id, created_payment (bool)`; `signedAmount(): string` (+ за IN, − за OUT); релации `partner, account, salesInvoice, purchaseInvoice, salesInvoicePayment, purchaseInvoicePayment`.
  - `LineDirection::IN|OUT` (`'in'|'out'`, `label()`), `LineKind::INVOICE_PAYMENT|ACCOUNT|UNCLEAR` (`'invoice_payment'|'account'|'unclear'`, `label()`).
  - `CompanyBankAccount.journal_group_id` + `journalGroup()`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Bank/BankStatementLineTest.php`:

```php
<?php

namespace Tests\Feature\Bank;

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankStatementLineTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_statement_is_a_draft_with_no_balances(): void
    {
        $statement = BankStatement::factory()->create()->fresh();

        $this->assertSame(BankStatement::STATUS_DRAFT, $statement->status);
        $this->assertFalse($statement->isBooked());
        $this->assertNull($statement->opening_balance);
        $this->assertNull($statement->closing_balance);
        $this->assertNull($statement->journal_entry_id);
    }

    public function test_the_movement_is_inflows_minus_outflows(): void
    {
        $statement = BankStatement::factory()->create();
        BankStatementLine::factory()->for($statement)->create(['direction' => LineDirection::IN, 'amount' => '1000.50', 'position' => 1]);
        BankStatementLine::factory()->for($statement)->create(['direction' => LineDirection::OUT, 'amount' => '300.25', 'position' => 2]);

        $this->assertSame('700.25', $statement->fresh()->movement());
        $this->assertSame(['1000.50', '-300.25'], $statement->fresh()->lines->map->signedAmount()->all());
    }

    public function test_an_empty_statement_has_a_zero_movement(): void
    {
        $this->assertSame('0.00', BankStatement::factory()->create()->movement());
    }

    public function test_a_line_defaults_to_not_having_created_a_payment(): void
    {
        $line = BankStatementLine::factory()->for(BankStatement::factory()->create())->create(['kind' => LineKind::UNCLEAR])->fresh();

        $this->assertFalse($line->created_payment);
        $this->assertSame('Неразјаснето', LineKind::UNCLEAR->label());
        $this->assertSame('Уплата', LineDirection::IN->label());
    }
}
```

В `tests/Feature/Bank/BankStatementTest.php` **замени** го тестот `test_a_statement_carries_no_balances_or_turnover` (одлуката е свесно сменета — состојбите се внесуваат за контрола):

```php
    public function test_a_statement_carries_balances_only_for_the_check(): void
    {
        $statement = BankStatement::factory()->create(['opening_balance' => '1000.00', 'closing_balance' => '1500.00'])->fresh();

        $this->assertSame('1000.00', $statement->opening_balance);
        $this->assertSame('1500.00', $statement->closing_balance);
    }
```

- [ ] **Step 2: Run — FAIL**

Run: `php artisan test tests/Feature/Bank/BankStatementLineTest.php tests/Feature/Bank/BankStatementTest.php`

- [ ] **Step 3: Migrations**

`2026_10_06_100000_add_booking_to_bank_statements_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statements', function (Blueprint $table) {
            // Од наша гледна точка: позитивно = пари на сметка. Се внесуваат само
            // за контрола, никогаш не се книжат.
            $table->decimal('opening_balance', 15, 2)->nullable();
            $table->decimal('closing_balance', 15, 2)->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
        });

        Schema::table('company_bank_accounts', function (Blueprint $table) {
            $table->foreignId('journal_group_id')->nullable()->constrained('journal_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('company_bank_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_group_id');
        });

        Schema::table('bank_statements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropColumn(['opening_balance', 'closing_balance', 'status']);
        });
    }
};
```

`2026_10_06_100100_create_bank_statement_lines_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->date('line_date');
            $table->string('direction', 3);
            $table->decimal('amount', 15, 2);
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->string('reference_number', 64)->nullable();
            $table->string('purpose_code', 16)->nullable();
            $table->string('kind', 16);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('sales_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_invoice_payment_id')->nullable()->constrained('sales_invoice_payments')->nullOnDelete();
            $table->foreignId('purchase_invoice_payment_id')->nullable()->constrained('purchase_invoice_payments')->nullOnDelete();
            // Точно ако плаќањето го создала самата ставка — тогаш „Отвори за измена“ го брише.
            $table->boolean('created_payment')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
```

- [ ] **Step 4: Enums, models, factory**

`app/Support/Bank/LineDirection.php`:

```php
<?php

namespace App\Support\Bank;

/** Од наша гледна точка: уплата = пари влегуваат на сметката. */
enum LineDirection: string
{
    case IN = 'in';
    case OUT = 'out';

    public function label(): string
    {
        return match ($this) {
            self::IN => 'Уплата',
            self::OUT => 'Исплата',
        };
    }
}
```

`app/Support/Bank/LineKind.php`:

```php
<?php

namespace App\Support\Bank;

enum LineKind: string
{
    case INVOICE_PAYMENT = 'invoice_payment';
    case ACCOUNT = 'account';
    case UNCLEAR = 'unclear';

    public function label(): string
    {
        return match ($this) {
            self::INVOICE_PAYMENT => 'Плаќање на фактура',
            self::ACCOUNT => 'Конто',
            self::UNCLEAR => 'Неразјаснето',
        };
    }
}
```

`app/Models/BankStatementLine.php`:

```php
<?php

namespace App\Models;

use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Една ставка од извод: уплата или исплата, и во што се претвора при книжењето. */
class BankStatementLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_statement_id', 'position', 'line_date', 'direction', 'amount', 'partner_id',
        'description', 'reference_number', 'purpose_code', 'kind', 'account_id',
        'sales_invoice_id', 'purchase_invoice_id', 'sales_invoice_payment_id',
        'purchase_invoice_payment_id', 'created_payment',
    ];

    // DB-default не го полни свеж модел во меморија.
    protected $attributes = ['created_payment' => false, 'position' => 0];

    protected function casts(): array
    {
        return [
            'line_date' => 'date',
            'direction' => LineDirection::class,
            'kind' => LineKind::class,
            'amount' => 'decimal:2',
            'created_payment' => 'boolean',
        ];
    }

    /** Со знак од наша гледна точка: + за уплата, − за исплата. */
    public function signedAmount(): string
    {
        $amount = (string) $this->amount;

        return $this->direction === LineDirection::OUT ? bcmul($amount, '-1', 2) : bcadd($amount, '0', 2);
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function salesInvoicePayment(): BelongsTo
    {
        return $this->belongsTo(SalesInvoicePayment::class);
    }

    public function purchaseInvoicePayment(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoicePayment::class);
    }
}
```

`database/factories/BankStatementLineFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\BankStatement;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use Illuminate\Database\Eloquent\Factories\Factory;

class BankStatementLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bank_statement_id' => BankStatement::factory(),
            'position' => 1,
            'line_date' => '2026-01-05',
            'direction' => LineDirection::IN,
            'amount' => '100.00',
            'kind' => LineKind::UNCLEAR,
        ];
    }
}
```

`BankStatement.php` — додај:

```php
    public const STATUS_DRAFT = 'draft';

    public const STATUS_BOOKED = 'booked';

    // DB-default не го полни свеж модел во меморија.
    protected $attributes = ['status' => self::STATUS_DRAFT];
```

`$fillable` дополни со `'opening_balance', 'closing_balance', 'status', 'journal_entry_id'`; `casts()` дополни со `'opening_balance' => 'decimal:2', 'closing_balance' => 'decimal:2'`; релации и методи:

```php
    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class)->orderBy('position')->orderBy('id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function isBooked(): bool
    {
        return $this->status === self::STATUS_BOOKED;
    }

    /** Уплати минус исплати, со знак од наша гледна точка. */
    public function movement(): string
    {
        return $this->lines->reduce(fn (string $carry, BankStatementLine $line) => bcadd($carry, $line->signedAmount(), 2), '0.00');
    }
```

(`use Illuminate\Database\Eloquent\Relations\HasMany;`). Ажурирај го докблокот на класата: состојбите сега се внесуваат за контрола.

`CompanyBankAccount.php`: `'journal_group_id'` во `$fillable` и `public function journalGroup(): BelongsTo { return $this->belongsTo(JournalGroup::class); }`.

- [ ] **Step 5: Run — PASS**

Run: `php artisan test tests/Feature/Bank/BankStatementLineTest.php tests/Feature/Bank/BankStatementTest.php tests/Feature/CompanyBankAccountMigrationTest.php`

- [ ] **Step 6: Commit**

```bash
git add database app tests
git commit -m "Bank statement lines, balances, status and per-account journal group column"
```

---

### Task 4: Група на налози по банкарска сметка

**Files:**
- Create: `app/Support/Bank/BankAccountGroups.php`
- Create: `tests/Feature/Bank/BankAccountGroupsTest.php`

**Interfaces:**
- Consumes: `BankStatement` (`company_id, bank, account`), `CompanyBankAccount`, `JournalGroup`.
- Produces: `BankAccountGroups::groupFor(BankStatement $statement): JournalGroup`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Bank;

use App\Models\BankStatement;
use App\Models\Company;
use App\Models\JournalGroup;
use App\Support\Bank\BankAccountGroups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankAccountGroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_account_gets_group_10_and_the_second_gets_11(): void
    {
        $company = Company::factory()->create();
        $a = BankStatement::factory()->for($company)->create(['account' => '300000000000001', 'bank' => 'Комерцијална']);
        $b = BankStatement::factory()->for($company)->create(['account' => '200000000000002', 'bank' => 'Стопанска']);

        $this->assertSame('10', BankAccountGroups::groupFor($a)->code);
        $this->assertSame('11', BankAccountGroups::groupFor($b)->code);
    }

    public function test_the_same_account_keeps_its_group(): void
    {
        $company = Company::factory()->create();
        $first = BankStatement::factory()->for($company)->create(['account' => '300000000000001', 'number' => 1]);
        $second = BankStatement::factory()->for($company)->create(['account' => '300000000000001', 'number' => 2]);

        $this->assertSame(BankAccountGroups::groupFor($first)->id, BankAccountGroups::groupFor($second)->id);
        $this->assertSame(1, $company->bankAccounts()->count());
        $this->assertSame(1, JournalGroup::where('company_id', $company->id)->count());
    }

    public function test_a_code_already_taken_by_another_group_is_skipped(): void
    {
        $company = Company::factory()->create();
        JournalGroup::create(['company_id' => $company->id, 'code' => '10', 'name' => 'Друго', 'sort_order' => 10]);
        $statement = BankStatement::factory()->for($company)->create();

        $this->assertSame('11', BankAccountGroups::groupFor($statement)->code);
    }

    public function test_an_account_from_the_profile_is_reused_not_duplicated(): void
    {
        $company = Company::factory()->create();
        $company->bankAccounts()->create(['bank_name' => 'Комерцијална', 'account_number' => '300000000000000']);
        $statement = BankStatement::factory()->for($company)->create(['account' => '300000000000000']);

        BankAccountGroups::groupFor($statement);

        $this->assertSame(1, $company->bankAccounts()->count());
        $this->assertNotNull($company->bankAccounts()->first()->journal_group_id);
    }
}
```

- [ ] **Step 2: Run — FAIL**

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Support\Bank;

use App\Models\BankStatement;
use App\Models\CompanyBankAccount;
use App\Models\JournalGroup;
use RuntimeException;

/**
 * Секоја банкарска сметка добива своја група на налози: 10, 11, 12…
 * Сите пари се на конто 1000; сметките се разликуваат само по групата.
 */
class BankAccountGroups
{
    public static function groupFor(BankStatement $statement): JournalGroup
    {
        $account = CompanyBankAccount::firstOrCreate(
            ['company_id' => $statement->company_id, 'account_number' => $statement->account],
            ['bank_name' => $statement->bank],
        );

        if ($account->journal_group_id !== null) {
            return JournalGroup::findOrFail($account->journal_group_id);
        }

        $group = JournalGroup::create([
            'company_id' => $statement->company_id,
            'code' => self::nextCode($statement->company_id),
            'name' => "Извод — {$statement->bank} {$statement->account}",
            'sort_order' => 10,
        ]);

        $account->update(['journal_group_id' => $group->id]);

        return $group;
    }

    private static function nextCode(int $companyId): string
    {
        $taken = JournalGroup::where('company_id', $companyId)->pluck('code')->all();

        // 00 е за почетна состојба и 99 за автоматските налози; кодот е два знака.
        for ($n = 10; $n <= 98; $n++) {
            if (! in_array((string) $n, $taken, true)) {
                return (string) $n;
            }
        }

        throw new RuntimeException('Нема слободна група за налози на изводи.');
    }
}
```

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Feature/Bank/BankAccountGroupsTest.php`

- [ ] **Step 5: Commit**

```bash
git add app/Support/Bank/BankAccountGroups.php tests/Feature/Bank/BankAccountGroupsTest.php
git commit -m "Journal group per bank account (10, 11, 12...)"
```

---

### Task 5: Плаќање без сопствен налог (за ставките од изводи)

**Files:**
- Modify: `app/Services/Invoicing/SalesInvoiceService.php`
- Modify: `app/Services/Invoicing/PurchaseInvoiceService.php`
- Create: `tests/Feature/Bank/CreatePaymentRecordTest.php`

**Interfaces:**
- Produces:
  - `SalesInvoiceService::createPaymentRecord(SalesInvoice $invoice, string $amount, string $paymentDate, int $userId): SalesInvoicePayment`
  - `PurchaseInvoiceService::createPaymentRecord(PurchaseInvoice $invoice, string $amount, string $paymentDate, int $userId): PurchaseInvoicePayment`
  - Двата создаваат ред со `payment_method = 'bank'` и **не** создаваат налог. Валидација како `recordPayment` (потврдена, ≤ салдо). Sales уште одбива девизна фактура.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Bank;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatePaymentRecordTest extends TestCase
{
    use RefreshDatabase;

    private function confirmedSales(Company $company, string $unitPrice = '100.00'): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => $unitPrice, 'vat_rate' => '0']);

        return app(SalesInvoiceService::class)->confirm($invoice->fresh(), $user->id);
    }

    public function test_sales_payment_record_is_created_without_a_journal_entry(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->confirmedSales($company);
        $before = JournalEntry::count();

        $payment = app(SalesInvoiceService::class)->createPaymentRecord($invoice, '40.00', '2026-03-10', User::factory()->create()->id);

        $this->assertSame('40.00', (string) $payment->amount);
        $this->assertSame('bank', $payment->payment_method);
        $this->assertSame($before, JournalEntry::count());
        $this->assertSame('60.00', $invoice->fresh(['lines', 'payments'])->balanceDue());
    }

    public function test_sales_payment_record_cannot_exceed_the_balance(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->confirmedSales($company);

        $this->expectException(InvalidInvoiceStateException::class);

        app(SalesInvoiceService::class)->createPaymentRecord($invoice, '100.01', '2026-03-10', User::factory()->create()->id);
    }

    public function test_sales_payment_record_rejects_a_draft_invoice(): void
    {
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create();

        $this->expectException(InvalidInvoiceStateException::class);

        app(SalesInvoiceService::class)->createPaymentRecord($invoice, '10.00', '2026-03-10', User::factory()->create()->id);
    }

    public function test_purchase_payment_record_is_created_without_a_journal_entry(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $expense = Account::where('company_id', $company->id)->where('code', '462')->firstOrFail();
        $user = User::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => $expense->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $confirmed = app(PurchaseInvoiceService::class)->confirm($invoice->fresh(), $user->id);
        $before = JournalEntry::count();

        $payment = app(PurchaseInvoiceService::class)->createPaymentRecord($confirmed, '100.00', '2026-03-10', $user->id);

        $this->assertSame('bank', $payment->payment_method);
        $this->assertSame($before, JournalEntry::count());
        $this->assertSame('0.00', $confirmed->fresh(['lines', 'payments'])->balanceDue());
    }
}
```

- [ ] **Step 2: Run — FAIL.** `php artisan test tests/Feature/Bank/CreatePaymentRecordTest.php`

- [ ] **Step 3: Implement (SalesInvoiceService)**

Извлечи ја валидацијата од `recordPayment` во приватен метод и користи го на две места. Во `recordPayment` замени го блокот од `if ($invoice->status !== 'confirmed')` до проверката на салдото со `$this->assertPayable($invoice, $amount);` (заокружувањето `$amount = Bcmath::roundHalfUp($amount, 2);` останува прво). Додај:

```php
    /** Заедничка проверка: само потврдена фактура и не повеќе од салдото. */
    private function assertPayable(SalesInvoice $invoice, string $amount): void
    {
        if ($invoice->status !== 'confirmed') {
            throw new InvalidInvoiceStateException("Фактура #{$invoice->id} не е потврдена; плаќања можат да се внесуваат само за потврдени фактури.");
        }

        $invoice->loadMissing(['lines', 'payments', 'company']);

        if (bccomp($amount, $invoice->balanceDue(), 2) > 0) {
            throw new InvalidInvoiceStateException("Плаќањето од {$amount} го надминува преостанатото салдо од {$invoice->balanceDue()}.");
        }
    }

    /**
     * Само редот за плаќање, без налог: налогот го пишува изводот (една ставка
     * од изводот е една страна од неговиот налог). Само денарски фактури —
     * девизна фактура платена од денарски извод бара курсна логика што овде
     * намерно ја нема.
     */
    public function createPaymentRecord(SalesInvoice $invoice, string $amount, string $paymentDate, int $userId): SalesInvoicePayment
    {
        $amount = Bcmath::roundHalfUp($amount, 2);

        $this->assertPayable($invoice, $amount);

        if ($invoice->isForeignCurrency()) {
            throw new InvalidInvoiceStateException('Девизна фактура не може да се плати од денарски извод во оваа верзија.');
        }

        return $invoice->payments()->create([
            'amount' => $amount,
            'payment_date' => $paymentDate,
            'payment_method' => 'bank',
            'created_by' => $userId,
        ]);
    }
```

- [ ] **Step 4: Implement (PurchaseInvoiceService)**

Аналогно: во `recordPayment` замени ги проверките за статус и салдо (од `if ($invoice->status !== 'confirmed')` до крајот на проверката на салдото, **вклучувајќи го** `$invoice->loadMissing(['lines', 'payments', 'company', 'partner']);`) со `$this->assertPayable($invoice, $amount);`, и додај:

```php
    private function assertPayable(PurchaseInvoice $invoice, string $amount): void
    {
        if ($invoice->status !== 'confirmed') {
            throw new InvalidInvoiceStateException("Влезна фактура #{$invoice->id} не е потврдена; плаќања можат да се внесуваат само за потврдени фактури.");
        }

        $invoice->loadMissing(['lines', 'payments', 'company', 'partner']);

        if (bccomp($amount, $invoice->balanceDue(), 2) > 0) {
            throw new InvalidInvoiceStateException("Плаќањето од {$amount} го надминува преостанатото салдо од {$invoice->balanceDue()}.");
        }
    }

    /** Само редот за плаќање, без налог: налогот го пишува изводот. */
    public function createPaymentRecord(PurchaseInvoice $invoice, string $amount, string $paymentDate, int $userId): PurchaseInvoicePayment
    {
        $this->assertPayable($invoice, $amount);

        return $invoice->payments()->create([
            'amount' => $amount,
            'payment_date' => $paymentDate,
            'payment_method' => 'bank',
            'created_by' => $userId,
        ]);
    }
```

- [ ] **Step 5: Run — PASS (и старите плаќања не се скршија)**

Run: `php artisan test tests/Feature/Bank/CreatePaymentRecordTest.php tests/Unit/SalesInvoiceServiceTest.php tests/Unit/PurchaseInvoiceServiceTest.php tests/Feature/ForeignCurrencyInvoiceTest.php`

- [ ] **Step 6: Commit**

```bash
git add app/Services/Invoicing tests/Feature/Bank/CreatePaymentRecordTest.php
git commit -m "createPaymentRecord: invoice payment row without its own journal entry"
```

---

### Task 6: Контроли на изводот

**Files:**
- Create: `app/Support/Bank/StatementControls.php`
- Create: `tests/Feature/Bank/StatementControlsTest.php`

**Interfaces:**
- Consumes: `BankStatement` (+`lines`), `Account::analytical`, `SalesInvoice/PurchaseInvoice::balanceDue()`.
- Produces:
  - `StatementControls::difference(?string $opening, ?string $closing, iterable $signedAmounts): ?string` — `opening + Σ signed − closing`; `null` ако состојба фали. (Чисто; го користи и екранот за лента во живо.)
  - `StatementControls::problems(BankStatement $statement): array<int, string>` — празно = може да се потврди.

Проблеми (текстовите се македонски, по ред): нема ставки; фали почетна/крајна состојба; разлика ≠ 0; верига (почетна ≠ крајна од претходниот книжен извод на истата сметка во истата година); ставка со износ ≤ 0; датум на ставка во друга година од изводот; `ACCOUNT`/`UNCLEAR` без конто или конто што не е аналитичко; `INVOICE_PAYMENT` без фактура од вистинскиот вид (уплата → излезна, исплата → влезна) или надвор од салдото (ако нема врзано постоечко плаќање).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Bank;

use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use App\Support\Bank\StatementControls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatementControlsTest extends TestCase
{
    use RefreshDatabase;

    private function statement(array $attrs = []): BankStatement
    {
        return BankStatement::factory()->create(array_merge([
            'opening_balance' => '1000.00',
            'closing_balance' => '1500.00',
            'statement_date' => '2026-03-05',
        ], $attrs));
    }

    private function line(BankStatement $statement, array $attrs = []): BankStatementLine
    {
        $company = $statement->company;
        $account = Account::where('company_id', $company->id)->where('code', '7400')->first()
            ?? Account::where('company_id', $company->id)->analytical()->first();

        return BankStatementLine::factory()->for($statement)->create(array_merge([
            'line_date' => '2026-03-05',
            'direction' => LineDirection::IN,
            'amount' => '500.00',
            'kind' => LineKind::ACCOUNT,
            'account_id' => $account->id,
        ], $attrs));
    }

    public function test_difference_is_opening_plus_movement_minus_closing(): void
    {
        $this->assertSame('0.00', StatementControls::difference('1000.00', '1500.00', ['500.00']));
        $this->assertSame('-50.00', StatementControls::difference('1000.00', '1500.00', ['450.00']));
        $this->assertSame('0.00', StatementControls::difference('1000.00', '700.00', ['-300.00']));
        $this->assertNull(StatementControls::difference(null, '1500.00', ['500.00']));
        $this->assertNull(StatementControls::difference('1000.00', null, ['500.00']));
    }

    public function test_a_balanced_statement_has_no_problems(): void
    {
        $statement = $this->statement();
        $this->line($statement);

        $this->assertSame([], StatementControls::problems($statement->fresh('lines')));
    }

    public function test_an_empty_statement_is_a_problem(): void
    {
        $this->assertContains('Изводот нема ставки.', StatementControls::problems($this->statement()->fresh('lines')));
    }

    public function test_missing_balances_are_a_problem(): void
    {
        $statement = $this->statement(['opening_balance' => null]);
        $this->line($statement);

        $this->assertContains('Внесете почетна и крајна состојба.', StatementControls::problems($statement->fresh('lines')));
    }

    public function test_a_difference_is_a_problem(): void
    {
        $statement = $this->statement();
        $this->line($statement, ['amount' => '450.00']);

        $problems = StatementControls::problems($statement->fresh('lines'));

        $this->assertContains('Почетна состојба + движење не е еднакво на крајната состојба (разлика −50,00).', $problems);
    }

    public function test_the_opening_must_equal_the_previous_booked_statements_closing(): void
    {
        $company = Company::factory()->create();
        BankStatement::factory()->for($company)->create([
            'number' => 1, 'statement_date' => '2026-03-04', 'closing_balance' => '900.00',
            'opening_balance' => '0.00', 'status' => BankStatement::STATUS_BOOKED,
        ]);
        $statement = $this->statement(['company_id' => $company->id, 'number' => 2]);
        $this->line($statement);

        $problems = StatementControls::problems($statement->fresh('lines'));

        $this->assertContains('Почетната состојба (1.000,00) не е еднаква на крајната од извод 1 (900,00).', $problems);
    }

    public function test_the_chain_ignores_drafts_and_other_accounts_and_other_years(): void
    {
        $company = Company::factory()->create();
        BankStatement::factory()->for($company)->create(['number' => 1, 'closing_balance' => '1.00', 'status' => BankStatement::STATUS_DRAFT]);
        BankStatement::factory()->for($company)->create(['number' => 1, 'account' => '999', 'closing_balance' => '2.00', 'status' => BankStatement::STATUS_BOOKED]);
        BankStatement::factory()->for($company)->create(['number' => 1, 'statement_date' => '2025-12-30', 'closing_balance' => '3.00', 'status' => BankStatement::STATUS_BOOKED]);
        $statement = $this->statement(['company_id' => $company->id, 'number' => 2]);
        $this->line($statement);

        $this->assertSame([], StatementControls::problems($statement->fresh('lines')));
    }

    public function test_a_line_in_another_year_is_a_problem(): void
    {
        $statement = $this->statement();
        $this->line($statement, ['line_date' => '2025-12-31']);

        $this->assertContains('Ставка 1: датумот е од друга година од изводот.', StatementControls::problems($statement->fresh('lines')));
    }

    public function test_an_unclear_line_needs_an_account(): void
    {
        $statement = $this->statement();
        $this->line($statement, ['kind' => LineKind::UNCLEAR, 'account_id' => null]);

        $this->assertContains('Ставка 1: изберете конто.', StatementControls::problems($statement->fresh('lines')));
    }

    public function test_a_heading_account_is_not_allowed(): void
    {
        $statement = $this->statement();
        $heading = Account::where('company_id', $statement->company_id)->where('code', '120')->firstOrFail();
        $this->line($statement, ['account_id' => $heading->id]);

        $this->assertContains('Ставка 1: контото 120 не е аналитичко.', StatementControls::problems($statement->fresh('lines')));
    }

    public function test_an_invoice_payment_needs_an_invoice_within_its_balance(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '300.00', 'vat_rate' => '0']);
        app(SalesInvoiceService::class)->confirm($invoice->fresh(), $user->id);
        $statement = $this->statement(['company_id' => $company->id]);

        $this->line($statement, ['kind' => LineKind::INVOICE_PAYMENT, 'account_id' => null, 'sales_invoice_id' => null]);
        $this->assertContains('Ставка 1: изберете фактура.', StatementControls::problems($statement->fresh('lines')));

        $statement->lines()->delete();
        $this->line($statement, ['kind' => LineKind::INVOICE_PAYMENT, 'account_id' => null, 'sales_invoice_id' => $invoice->id, 'amount' => '500.00']);
        $this->assertContains('Ставка 1: износот го надминува салдото на фактурата (300,00).', StatementControls::problems($statement->fresh('lines')));
    }
}
```

> `Format`-те со запирка за илјади/децимали: користи `number_format($v, 2, ',', '.')` во пораките, истото како `App\Support\Format` ако има метод за пари — провери `app/Support/Format.php` и користи го ако постои (тестот горе очекува `1.000,00` и `−50,00`; ако `Format::money` дава друг облик, усогласи ги и тестот и пораките).

- [ ] **Step 2: Run — FAIL.** `php artisan test tests/Feature/Bank/StatementControlsTest.php`

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Support\Bank;

use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;

/**
 * Проверки што мора да поминат пред изводот да се прокнижи. Чиста логика: ништо
 * не запишува.
 */
class StatementControls
{
    /**
     * Почетна + движење − крајна. Нула значи дека изводот се совпаѓа.
     *
     * @param  iterable<string>  $signedAmounts  уплата +, исплата − (од наша гледна точка)
     */
    public static function difference(?string $opening, ?string $closing, iterable $signedAmounts): ?string
    {
        if ($opening === null || $closing === null || $opening === '' || $closing === '') {
            return null;
        }

        $movement = '0.00';
        foreach ($signedAmounts as $amount) {
            $movement = bcadd($movement, $amount, 2);
        }

        return bcsub(bcadd($opening, $movement, 2), $closing, 2);
    }

    /** @return array<int, string> празно = може да се потврди */
    public static function problems(BankStatement $statement): array
    {
        $statement->loadMissing('lines');
        $problems = [];

        if ($statement->lines->isEmpty()) {
            $problems[] = 'Изводот нема ставки.';
        }

        $opening = $statement->opening_balance;
        $closing = $statement->closing_balance;

        if ($opening === null || $closing === null) {
            $problems[] = 'Внесете почетна и крајна состојба.';
        } else {
            $difference = self::difference((string) $opening, (string) $closing, $statement->lines->map->signedAmount());

            if (bccomp($difference, '0', 2) !== 0) {
                $problems[] = 'Почетна состојба + движење не е еднакво на крајната состојба (разлика '.self::money($difference).').';
            }

            $previous = self::previousBooked($statement);

            if ($previous !== null && bccomp((string) $previous->closing_balance, (string) $opening, 2) !== 0) {
                $problems[] = 'Почетната состојба ('.self::money((string) $opening).') не е еднаква на крајната од извод '
                    .$previous->number.' ('.self::money((string) $previous->closing_balance).').';
            }
        }

        foreach ($statement->lines as $index => $line) {
            foreach (self::lineProblems($statement, $line) as $problem) {
                $problems[] = 'Ставка '.($index + 1).': '.$problem;
            }
        }

        return $problems;
    }

    /** Претходниот книжен извод на истата сметка во истата година. */
    private static function previousBooked(BankStatement $statement): ?BankStatement
    {
        return BankStatement::where('company_id', $statement->company_id)
            ->where('account', $statement->account)
            ->where('kind', $statement->kind)
            ->where('status', BankStatement::STATUS_BOOKED)
            ->whereYear('statement_date', $statement->statement_date->year)
            ->where('number', '<', $statement->number)
            ->whereKeyNot($statement->id)
            ->orderByDesc('number')
            ->first();
    }

    /** @return array<int, string> */
    private static function lineProblems(BankStatement $statement, BankStatementLine $line): array
    {
        $problems = [];

        if (bccomp((string) $line->amount, '0', 2) <= 0) {
            $problems[] = 'износот мора да е поголем од нула.';
        }

        if ($line->line_date->year !== $statement->statement_date->year) {
            $problems[] = 'датумот е од друга година од изводот.';
        }

        if ($line->kind === LineKind::INVOICE_PAYMENT) {
            return array_merge($problems, self::invoiceProblems($line));
        }

        if ($line->account_id === null) {
            $problems[] = 'изберете конто.';

            return $problems;
        }

        $account = Account::where('company_id', $statement->company_id)->find($line->account_id);

        if ($account === null) {
            $problems[] = 'контото не постои во оваа фирма.';
        } elseif (! $account->is_analytical) {
            $problems[] = "контото {$account->code} не е аналитичко.";
        }

        return $problems;
    }

    /** @return array<int, string> */
    private static function invoiceProblems(BankStatementLine $line): array
    {
        $isIn = $line->direction === LineDirection::IN;
        $invoice = $isIn ? $line->salesInvoice : $line->purchaseInvoice;
        $existing = $isIn ? $line->sales_invoice_payment_id : $line->purchase_invoice_payment_id;

        if ($invoice === null) {
            return ['изберете фактура.'];
        }

        if ($existing !== null) {
            return [];
        }

        $invoice->loadMissing(['lines', 'payments']);

        if ($invoice->status !== 'confirmed') {
            return ['фактурата не е потврдена.'];
        }

        if (bccomp((string) $line->amount, $invoice->balanceDue(), 2) > 0) {
            return ['износот го надминува салдото на фактурата ('.self::money($invoice->balanceDue()).').'];
        }

        return [];
    }

    private static function money(string $value): string
    {
        return str_replace('-', '−', number_format((float) $value, 2, ',', '.'));
    }
}
```

> Фаќа и случај каде `lineProblems` бара `$line->line_date` да е Carbon — каста во моделот го гарантира.

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Feature/Bank/StatementControlsTest.php`

- [ ] **Step 5: Commit**

```bash
git add app/Support/Bank/StatementControls.php tests/Feature/Bank/StatementControlsTest.php
git commit -m "Statement controls: balance difference, chain, per-line checks"
```

---

### Task 7: Книжење и отворање на изводот (BankStatementPoster)

**Files:**
- Create: `app/Exceptions/InvalidBankStatementException.php`
- Create: `app/Services/Bank/BankStatementPoster.php`
- Create: `tests/Feature/Bank/BankStatementPosterTest.php`

**Interfaces:**
- Consumes: `StatementControls::problems()`, `BankAccountGroups::groupFor()`, `SalesInvoiceService::createPaymentRecord()`, `PurchaseInvoiceService::createPaymentRecord()`, `Account::BANK_CODE`.
- Produces:
  - `BankStatementPoster::post(BankStatement $statement, int $userId): JournalEntry` — фрла `InvalidBankStatementException` (порака = проблемите, споени со нов ред) ако изводот не поминува или е веќе книжен.
  - `BankStatementPoster::reopen(BankStatement $statement): void` — фрла ако не е книжен или има подоцнежен книжен извод на истата сметка во истата година.

Правила на книжење (налог во групата на сметката; датум = датум на изводот; опис `Извод бр. {број} — {банка} {сметка}`):
- Уплата: 1000 **Долгува**, спротивното **Побарува**. Исплата: спротивното **Долгува**, 1000 **Побарува**. На двете страни `partner_id` од ставката, `line_date` од ставката.
- `INVOICE_PAYMENT` без врзано постоечко плаќање: се создава плаќањето (`createPaymentRecord`), `created_payment=true`, ставката го чува id; спротивно конто 120 (излезна) / 220 (влезна), партнер = партнерот на фактурата.
- `INVOICE_PAYMENT` со врзано постоечко плаќање: **ништо не се книжи** (има свој налог).
- `ACCOUNT`/`UNCLEAR`: спротивно конто = `account_id`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Bank;

use App\Exceptions\InvalidBankStatementException;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Bank\BankStatementPoster;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Invoicing\SalesInvoiceService;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankStatementPosterTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->user = User::factory()->create();
    }

    private function account(string $code): Account
    {
        return Account::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function statement(string $opening, string $closing, array $attrs = []): BankStatement
    {
        return BankStatement::factory()->for($this->company)->create(array_merge([
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'statement_date' => '2026-03-05',
        ], $attrs));
    }

    private function line(BankStatement $statement, LineDirection $direction, string $amount, array $attrs = []): BankStatementLine
    {
        return BankStatementLine::factory()->for($statement)->create(array_merge([
            'line_date' => '2026-03-05',
            'direction' => $direction,
            'amount' => $amount,
            'kind' => LineKind::ACCOUNT,
        ], $attrs));
    }

    private function poster(): BankStatementPoster
    {
        return app(BankStatementPoster::class);
    }

    public function test_one_entry_per_statement_with_inflows_debiting_1000_and_outflows_crediting_it(): void
    {
        $statement = $this->statement('1000.00', '1300.00');
        $this->line($statement, LineDirection::IN, '500.00', ['account_id' => $this->account('7400')->id]);
        $this->line($statement, LineDirection::OUT, '200.00', ['account_id' => $this->account('4200')->id]);

        $entry = $this->poster()->post($statement, $this->user->id)->load('lines.account', 'journalGroup');

        $this->assertSame('10', $entry->journalGroup->code);
        $this->assertSame('2026-03-05', $entry->entry_date->toDateString());
        $this->assertCount(4, $entry->lines);
        $bankLines = $entry->lines->where('account.code', '1000');
        $this->assertSame('500.00', (string) $bankLines->firstWhere('debit', '500.00')->debit);
        $this->assertSame('200.00', (string) $bankLines->firstWhere('credit', '200.00')->credit);
        $this->assertSame('500.00', (string) $entry->lines->firstWhere('account.code', '7400')->credit);
        $this->assertSame('200.00', (string) $entry->lines->firstWhere('account.code', '4200')->debit);
        $this->assertSame('0.00', bcsub((string) $entry->lines->sum('debit'), (string) $entry->lines->sum('credit'), 2));

        $fresh = $statement->fresh();
        $this->assertTrue($fresh->isBooked());
        $this->assertSame($entry->id, $fresh->journal_entry_id);
    }

    public function test_an_unbalanced_statement_is_refused_and_posts_nothing(): void
    {
        $statement = $this->statement('1000.00', '9999.00');
        $this->line($statement, LineDirection::IN, '500.00', ['account_id' => $this->account('7400')->id]);

        try {
            $this->poster()->post($statement, $this->user->id);
            $this->fail('Очекувана е InvalidBankStatementException.');
        } catch (InvalidBankStatementException $e) {
            $this->assertStringContainsString('разлика', $e->getMessage());
        }

        $this->assertFalse($statement->fresh()->isBooked());
        $this->assertSame(0, \App\Models\JournalEntry::where('company_id', $this->company->id)->count());
    }

    public function test_a_booked_statement_cannot_be_booked_twice(): void
    {
        $statement = $this->statement('0.00', '500.00');
        $this->line($statement, LineDirection::IN, '500.00', ['account_id' => $this->account('7400')->id]);
        $this->poster()->post($statement, $this->user->id);

        $this->expectException(InvalidBankStatementException::class);

        $this->poster()->post($statement->fresh(), $this->user->id);
    }

    public function test_an_invoice_line_creates_the_payment_and_posts_against_120(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = SalesInvoice::factory()->for($this->company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '300.00', 'vat_rate' => '0']);
        app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->user->id);
        $statement = $this->statement('0.00', '300.00');
        $line = $this->line($statement, LineDirection::IN, '300.00', ['kind' => LineKind::INVOICE_PAYMENT, 'sales_invoice_id' => $invoice->id, 'partner_id' => $partner->id]);

        $entry = $this->poster()->post($statement, $this->user->id)->load('lines.account');

        $this->assertSame('300.00', (string) $entry->lines->firstWhere('account.code', '120')->credit);
        $this->assertSame($partner->id, $entry->lines->firstWhere('account.code', '120')->partner_id);
        $line = $line->fresh();
        $this->assertTrue($line->created_payment);
        $this->assertNotNull($line->sales_invoice_payment_id);
        $this->assertSame('paid', $invoice->fresh(['lines', 'payments'])->paymentStatus());
    }

    public function test_a_purchase_invoice_line_posts_against_220(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = PurchaseInvoice::factory()->for($this->company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => $this->account('462')->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => '200.00', 'vat_rate' => '0']);
        app(PurchaseInvoiceService::class)->confirm($invoice->fresh(), $this->user->id);
        $statement = $this->statement('500.00', '300.00');
        $this->line($statement, LineDirection::OUT, '200.00', ['kind' => LineKind::INVOICE_PAYMENT, 'purchase_invoice_id' => $invoice->id, 'partner_id' => $partner->id]);

        $entry = $this->poster()->post($statement, $this->user->id)->load('lines.account');

        $this->assertSame('200.00', (string) $entry->lines->firstWhere('account.code', '220')->debit);
        $this->assertSame('200.00', (string) $entry->lines->firstWhere('account.code', '1000')->credit);
    }

    public function test_a_line_linked_to_an_existing_payment_posts_nothing_again(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = SalesInvoice::factory()->for($this->company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '300.00', 'vat_rate' => '0']);
        $confirmed = app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->user->id);
        $payment = app(SalesInvoiceService::class)->recordPayment($confirmed, '300.00', '2026-03-04', 'bank', $this->user->id);
        $statement = $this->statement('0.00', '300.00');
        $this->line($statement, LineDirection::IN, '300.00', ['kind' => LineKind::INVOICE_PAYMENT, 'sales_invoice_id' => $invoice->id, 'sales_invoice_payment_id' => $payment->id]);
        $paymentsBefore = $invoice->payments()->count();

        $entry = $this->poster()->post($statement, $this->user->id)->load('lines');

        $this->assertCount(0, $entry->lines);
        $this->assertSame($paymentsBefore, $invoice->payments()->count());
        $this->assertFalse($statement->lines()->first()->created_payment);
    }

    public function test_reopening_deletes_the_entry_and_only_the_payments_the_statement_created(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = SalesInvoice::factory()->for($this->company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '300.00', 'vat_rate' => '0']);
        app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->user->id);
        $statement = $this->statement('0.00', '300.00');
        $this->line($statement, LineDirection::IN, '300.00', ['kind' => LineKind::INVOICE_PAYMENT, 'sales_invoice_id' => $invoice->id, 'partner_id' => $partner->id]);
        $entry = $this->poster()->post($statement, $this->user->id);

        $this->poster()->reopen($statement->fresh());

        $fresh = $statement->fresh();
        $this->assertFalse($fresh->isBooked());
        $this->assertNull($fresh->journal_entry_id);
        $this->assertNull(\App\Models\JournalEntry::find($entry->id));
        $this->assertSame(0, $invoice->payments()->count());
        $line = $fresh->lines()->first();
        $this->assertFalse($line->created_payment);
        $this->assertNull($line->sales_invoice_payment_id);
    }

    public function test_a_statement_with_a_later_booked_statement_cannot_be_reopened(): void
    {
        $first = $this->statement('0.00', '500.00', ['number' => 1]);
        $this->line($first, LineDirection::IN, '500.00', ['account_id' => $this->account('7400')->id]);
        $this->poster()->post($first, $this->user->id);
        $second = $this->statement('500.00', '800.00', ['number' => 2, 'statement_date' => '2026-03-06']);
        $this->line($second, LineDirection::IN, '300.00', ['account_id' => $this->account('7400')->id, 'line_date' => '2026-03-06']);
        $this->poster()->post($second, $this->user->id);

        $this->expectException(InvalidBankStatementException::class);

        $this->poster()->reopen($first->fresh());
    }
}
```

> Конта `7400` и `4200` мора да се аналитички во официјалниот план; ако некое не е (провери `docs/reference/official-chart-of-accounts.json`), замени со друго аналитичко конто и во тестот и во `StatementControlsTest`.

- [ ] **Step 2: Run — FAIL.** `php artisan test tests/Feature/Bank/BankStatementPosterTest.php`

- [ ] **Step 3: Implement**

`app/Exceptions/InvalidBankStatementException.php`:

```php
<?php

namespace App\Exceptions;

class InvalidBankStatementException extends \RuntimeException {}
```

`app/Services/Bank/BankStatementPoster.php`:

```php
<?php

namespace App\Services\Bank;

use App\Exceptions\InvalidBankStatementException;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\JournalEntry;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Invoicing\SalesInvoiceService;
use App\Support\Bank\BankAccountGroups;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use App\Support\Bank\StatementControls;
use Illuminate\Support\Facades\DB;

/**
 * Еден потврден извод е еден налог. Почетната состојба не се книжи — само
 * движењето; контролата на состојбите е во StatementControls.
 */
class BankStatementPoster
{
    public function __construct(
        private readonly SalesInvoiceService $sales,
        private readonly PurchaseInvoiceService $purchases,
    ) {}

    public function post(BankStatement $statement, int $userId): JournalEntry
    {
        return DB::transaction(function () use ($statement, $userId) {
            $statement = BankStatement::whereKey($statement->id)->lockForUpdate()->with('lines')->firstOrFail();

            if ($statement->isBooked()) {
                throw new InvalidBankStatementException('Изводот е веќе прокнижен.');
            }

            $problems = StatementControls::problems($statement);

            if ($problems !== []) {
                throw new InvalidBankStatementException(implode("\n", $problems));
            }

            $bank = $this->account($statement, Account::BANK_CODE);

            $entry = JournalEntry::create([
                'company_id' => $statement->company_id,
                'journal_group_id' => BankAccountGroups::groupFor($statement)->id,
                'entry_date' => $statement->statement_date,
                'description' => "Извод бр. {$statement->number} — {$statement->bank} {$statement->account}",
                'created_by' => $userId,
            ]);

            foreach ($statement->lines as $line) {
                $this->postLine($statement, $entry, $bank, $line, $userId);
            }

            $statement->update(['status' => BankStatement::STATUS_BOOKED, 'journal_entry_id' => $entry->id]);

            return $entry;
        });
    }

    /**
     * Го враќа изводот во нацрт: го брише налогот и плаќањата што ги создале
     * неговите ставки (врзаните претходни плаќања остануваат). Само најновиот
     * книжен извод на сметката — инаку би се скршила низата на состојби.
     */
    public function reopen(BankStatement $statement): void
    {
        DB::transaction(function () use ($statement) {
            $statement = BankStatement::whereKey($statement->id)->lockForUpdate()->with('lines')->firstOrFail();

            if (! $statement->isBooked()) {
                throw new InvalidBankStatementException('Изводот не е прокнижен.');
            }

            $later = BankStatement::where('company_id', $statement->company_id)
                ->where('account', $statement->account)
                ->where('kind', $statement->kind)
                ->where('status', BankStatement::STATUS_BOOKED)
                ->whereYear('statement_date', $statement->statement_date->year)
                ->where('number', '>', $statement->number)
                ->exists();

            if ($later) {
                throw new InvalidBankStatementException('Прво отворете ги подоцнежните изводи на оваа сметка.');
            }

            foreach ($statement->lines as $line) {
                if (! $line->created_payment) {
                    continue;
                }

                $payment = $line->salesInvoicePayment ?? $line->purchaseInvoicePayment;
                $line->update(['sales_invoice_payment_id' => null, 'purchase_invoice_payment_id' => null, 'created_payment' => false]);
                $payment?->delete();
            }

            $entry = $statement->journalEntry;
            $statement->update(['status' => BankStatement::STATUS_DRAFT, 'journal_entry_id' => null]);
            $entry?->delete();
        });
    }

    private function postLine(BankStatement $statement, JournalEntry $entry, Account $bank, BankStatementLine $line, int $userId): void
    {
        $isIn = $line->direction === LineDirection::IN;
        $partnerId = $line->partner_id;

        if ($line->kind === LineKind::INVOICE_PAYMENT) {
            $existing = $isIn ? $line->sales_invoice_payment_id : $line->purchase_invoice_payment_id;

            if ($existing !== null) {
                // Веќе книжено со свој налог (на 1000) — второ книжење би го удвоило.
                return;
            }

            $invoice = $isIn ? $line->salesInvoice : $line->purchaseInvoice;
            $partnerId = $invoice->partner_id;
            $date = $line->line_date->toDateString();

            $payment = $isIn
                ? $this->sales->createPaymentRecord($invoice, (string) $line->amount, $date, $userId)
                : $this->purchases->createPaymentRecord($invoice, (string) $line->amount, $date, $userId);

            $line->update($isIn
                ? ['sales_invoice_payment_id' => $payment->id, 'created_payment' => true]
                : ['purchase_invoice_payment_id' => $payment->id, 'created_payment' => true]);

            $counter = $this->account($statement, $isIn ? '120' : '220');
        } else {
            $counter = Account::where('company_id', $statement->company_id)->findOrFail($line->account_id);
        }

        $label = $line->description ?: "Извод бр. {$statement->number}";
        $amount = (string) $line->amount;
        $common = ['partner_id' => $partnerId, 'description' => $label, 'line_date' => $line->line_date];

        $entry->lines()->create($common + ['account_id' => $bank->id, 'debit' => $isIn ? $amount : '0', 'credit' => $isIn ? '0' : $amount]);
        $entry->lines()->create($common + ['account_id' => $counter->id, 'debit' => $isIn ? '0' : $amount, 'credit' => $isIn ? $amount : '0']);
    }

    private function account(BankStatement $statement, string $code): Account
    {
        return Account::where('company_id', $statement->company_id)->where('code', $code)->firstOrFail();
    }
}
```

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Feature/Bank/BankStatementPosterTest.php`

- [ ] **Step 5: Commit**

```bash
git add app/Exceptions/InvalidBankStatementException.php app/Services/Bank tests/Feature/Bank/BankStatementPosterTest.php
git commit -m "BankStatementPoster: one entry per statement, reopen"
```

---

### Task 8: Екран „Книжи извод“ (Livewire)

**Files:**
- Create: `app/Livewire/Bank/BankStatementBook.php`
- Create: `resources/views/livewire/bank/bank-statement-book.blade.php`
- Modify: `routes/web.php` (внатре во групата `bank-statements.`)
- Create: `tests/Feature/Bank/BankStatementBookTest.php`

**Interfaces:**
- Consumes: `BankStatementPoster`, `StatementControls::difference/problems`, `Account::analytical`.
- Produces: рута `bank-statements.book` (`/companies/{company}/izvodi/{statement}/knizenje`); јавни својства `openingBalance`, `closingBalance`, `lines` (низа од редови: `id, line_date, direction, amount, partner_id, description, kind, account_id, invoice_id, existing_payment_id`); акции `addLine()`, `removeLine(int $index)`, `save()`, `post()`, `reopen()`; пресметано `difference`.

Авторизација: `Gate::authorize('create', JournalEntry::class)` (admin/accountant) + `Gate::authorize('view', $company)`; изводот мора да е на таа фирма и денарски (`abort(404)` инаку).

- [ ] **Step 1: Route**

Во групата `bank-statements.` додај (по `index`):

```php
        Route::get('/izvodi/{statement}/knizenje', [BankStatementBook::class, '__invoke'])->name('book');
```

и `use App\Livewire\Bank\BankStatementBook;`.

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementBook;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BankStatementBookTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
    }

    private function component(BankStatement $statement)
    {
        return Livewire::test(BankStatementBook::class, ['company' => $this->company, 'statement' => $statement]);
    }

    private function account(string $code): Account
    {
        return Account::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    public function test_the_screen_loads_for_an_admin(): void
    {
        $statement = BankStatement::factory()->for($this->company)->create();

        $this->get(route('bank-statements.book', [$this->company, $statement]))->assertOk();
    }

    public function test_a_client_cannot_open_it(): void
    {
        $client = User::factory()->create();
        $client->assignRole('internal_client');
        $statement = BankStatement::factory()->for($this->company)->create();

        Livewire::actingAs($client)->test(BankStatementBook::class, ['company' => $this->company, 'statement' => $statement])->assertForbidden();
    }

    public function test_a_statement_of_another_company_is_not_found(): void
    {
        $statement = BankStatement::factory()->create();

        Livewire::test(BankStatementBook::class, ['company' => $this->company, 'statement' => $statement])->assertNotFound();
    }

    public function test_a_foreign_statement_is_not_found(): void
    {
        $statement = BankStatement::factory()->foreign()->for($this->company)->create();

        Livewire::test(BankStatementBook::class, ['company' => $this->company, 'statement' => $statement])->assertNotFound();
    }

    public function test_lines_and_balances_are_saved_and_the_difference_updates_live(): void
    {
        $statement = BankStatement::factory()->for($this->company)->create(['statement_date' => '2026-03-05']);

        $this->component($statement)
            ->set('openingBalance', '1000.00')
            ->set('closingBalance', '1500.00')
            ->call('addLine')
            ->set('lines.0.amount', '450.00')
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->account('7400')->id)
            ->assertSet('difference', '-50.00')
            ->set('lines.0.amount', '500.00')
            ->assertSet('difference', '0.00')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $statement->fresh(['lines']);
        $this->assertSame('1000.00', $fresh->opening_balance);
        $this->assertCount(1, $fresh->lines);
        $this->assertSame('500.00', (string) $fresh->lines->first()->amount);
        $this->assertSame('2026-03-05', $fresh->lines->first()->line_date->toDateString());
    }

    public function test_save_replaces_the_lines_and_removing_one_deletes_it(): void
    {
        $statement = BankStatement::factory()->for($this->company)->create();
        BankStatementLine::factory()->for($statement)->count(2)->create();

        $this->component($statement)
            ->call('removeLine', 0)
            ->call('save');

        $this->assertSame(1, $statement->lines()->count());
    }

    public function test_post_books_the_statement(): void
    {
        $statement = BankStatement::factory()->for($this->company)->create(['statement_date' => '2026-03-05']);

        $this->component($statement)
            ->set('openingBalance', '0.00')
            ->set('closingBalance', '500.00')
            ->call('addLine')
            ->set('lines.0.amount', '500.00')
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->account('7400')->id)
            ->call('post')
            ->assertHasNoErrors();

        $this->assertTrue($statement->fresh()->isBooked());
    }

    public function test_post_shows_the_problems_instead_of_booking(): void
    {
        $statement = BankStatement::factory()->for($this->company)->create(['statement_date' => '2026-03-05']);

        $this->component($statement)
            ->set('openingBalance', '0.00')
            ->set('closingBalance', '999.00')
            ->call('addLine')
            ->set('lines.0.amount', '500.00')
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->account('7400')->id)
            ->call('post')
            ->assertHasErrors('post');

        $this->assertFalse($statement->fresh()->isBooked());
    }

    public function test_choosing_a_partner_offers_their_open_invoices_and_picking_one_fills_the_account_side(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = SalesInvoice::factory()->for($this->company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '300.00', 'vat_rate' => '0']);
        app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->admin->id);
        $statement = BankStatement::factory()->for($this->company)->create(['statement_date' => '2026-03-05']);

        $component = $this->component($statement)
            ->call('addLine')
            ->set('lines.0.direction', 'in')
            ->set('lines.0.kind', 'invoice_payment')
            ->set('lines.0.partner_id', $partner->id);

        $options = $component->instance()->invoiceOptions(0);
        $this->assertCount(1, $options);
        $this->assertSame($invoice->id, $options[0]['id']);

        $component->set('lines.0.invoice_id', $invoice->id)
            ->set('lines.0.amount', '300.00')
            ->set('openingBalance', '0.00')
            ->set('closingBalance', '300.00')
            ->call('post')
            ->assertHasNoErrors();

        $this->assertSame('paid', $invoice->fresh(['lines', 'payments'])->paymentStatus());
    }

    public function test_reopen_returns_the_statement_to_a_draft(): void
    {
        $statement = BankStatement::factory()->for($this->company)->create(['statement_date' => '2026-03-05']);
        $this->component($statement)
            ->set('openingBalance', '0.00')->set('closingBalance', '500.00')
            ->call('addLine')->set('lines.0.amount', '500.00')->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->account('7400')->id)
            ->call('post');

        $this->component($statement->fresh())->call('reopen');

        $this->assertFalse($statement->fresh()->isBooked());
    }

    public function test_a_booked_statement_cannot_be_edited(): void
    {
        $statement = BankStatement::factory()->for($this->company)->create(['status' => BankStatement::STATUS_BOOKED]);

        $this->component($statement)->call('addLine')->assertSet('lines', []);
    }
}
```

- [ ] **Step 3: Run — FAIL.** `php artisan test tests/Feature/Bank/BankStatementBookTest.php`

- [ ] **Step 4: Implement the component**

```php
<?php

namespace App\Livewire\Bank;

use App\Exceptions\InvalidBankStatementException;
use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Services\Bank\BankStatementPoster;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use App\Support\Bank\StatementControls;
use App\Support\Bcmath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Рачно внесување на ставките на еден денарски извод и негово книжење.
 *
 * Состојбите се од наша гледна точка: „Побарува“ на изводот е плус, „Долгува“ е
 * минус. Лентата за разлика се пресметува во живо од тоа што е на екранот;
 * вистинската проверка е StatementControls::problems при потврда.
 */
#[Layout('layouts.app')]
class BankStatementBook extends Component
{
    public Company $company;

    public BankStatement $statement;

    public string $openingBalance = '';

    public string $closingBalance = '';

    /** @var array<int, array<string, mixed>> */
    public array $lines = [];

    public function mount(Company $company, BankStatement $statement): void
    {
        Gate::authorize('view', $company);
        Gate::authorize('create', JournalEntry::class);

        if ($statement->company_id !== $company->id || ! $statement->kind->isDenar()) {
            abort(404);
        }

        $this->company = $company;
        $this->statement = $statement;
        $this->openingBalance = $statement->opening_balance === null ? '' : (string) $statement->opening_balance;
        $this->closingBalance = $statement->closing_balance === null ? '' : (string) $statement->closing_balance;
        $this->lines = $statement->lines->map(fn ($line) => [
            'id' => $line->id,
            'line_date' => $line->line_date->toDateString(),
            'direction' => $line->direction->value,
            'amount' => (string) $line->amount,
            'partner_id' => $line->partner_id,
            'description' => (string) $line->description,
            'kind' => $line->kind->value,
            'account_id' => $line->account_id,
            'invoice_id' => $line->sales_invoice_id ?? $line->purchase_invoice_id,
            'existing_payment_id' => $line->sales_invoice_payment_id ?? $line->purchase_invoice_payment_id,
        ])->all();
    }

    public function addLine(): void
    {
        if ($this->statement->isBooked()) {
            return;
        }

        $last = end($this->lines) ?: null;

        $this->lines[] = [
            'id' => null,
            'line_date' => $last['line_date'] ?? $this->statement->statement_date->toDateString(),
            'direction' => LineDirection::IN->value,
            'amount' => '',
            'partner_id' => null,
            'description' => '',
            'kind' => LineKind::ACCOUNT->value,
            'account_id' => null,
            'invoice_id' => null,
            'existing_payment_id' => null,
        ];
    }

    public function removeLine(int $index): void
    {
        if ($this->statement->isBooked()) {
            return;
        }

        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    /** Разлика во живо: почетна + движење − крајна. */
    #[Computed]
    public function difference(): ?string
    {
        $signed = collect($this->lines)
            ->filter(fn (array $line) => Bcmath::isPlainNumber((string) $line['amount']))
            ->map(fn (array $line) => $line['direction'] === LineDirection::OUT->value
                ? bcmul((string) $line['amount'], '-1', 2)
                : bcadd((string) $line['amount'], '0', 2));

        $opening = Bcmath::isPlainNumber($this->openingBalance) ? bcadd($this->openingBalance, '0', 2) : null;
        $closing = Bcmath::isPlainNumber($this->closingBalance) ? bcadd($this->closingBalance, '0', 2) : null;

        return StatementControls::difference($opening, $closing, $signed);
    }

    /**
     * Отворени потврдени фактури на партнерот на ставката: излезни за уплата,
     * влезни за исплата. Само денарски излезни.
     *
     * @return array<int, array{id: int, label: string, balance: string}>
     */
    public function invoiceOptions(int $index): array
    {
        $line = $this->lines[$index] ?? null;

        if ($line === null || empty($line['partner_id'])) {
            return [];
        }

        $query = $line['direction'] === LineDirection::IN->value
            ? SalesInvoice::where('currency', 'MKD')
            : PurchaseInvoice::query();

        return $query->where('company_id', $this->company->id)
            ->where('partner_id', $line['partner_id'])
            ->where('status', 'confirmed')
            ->with(['lines', 'payments'])
            ->get()
            ->filter(fn ($invoice) => bccomp($invoice->balanceDue(), '0', 2) > 0)
            ->map(fn ($invoice) => [
                'id' => $invoice->id,
                'label' => ($invoice instanceof SalesInvoice ? $invoice->formattedNumber() : $invoice->supplier_invoice_number).' — салдо '.$invoice->balanceDue(),
                'balance' => $invoice->balanceDue(),
            ])
            ->values()
            ->all();
    }

    public function updated(string $name, mixed $value): void
    {
        // Фактура: износот се нуди сам кога е празен; избор на фактура ја гасне
        // можноста за стара врска со плаќање.
        if (preg_match('/^lines\.(\d+)\.invoice_id$/', $name, $m) && $value) {
            $index = (int) $m[1];
            $this->lines[$index]['existing_payment_id'] = null;

            foreach ($this->invoiceOptions($index) as $option) {
                if ($option['id'] === (int) $value && $this->lines[$index]['amount'] === '') {
                    $this->lines[$index]['amount'] = $option['balance'];
                }
            }
        }
    }

    public function save(): void
    {
        $this->persist();
    }

    public function post(BankStatementPoster $poster): void
    {
        Gate::authorize('create', JournalEntry::class);

        if (! $this->persist()) {
            return;
        }

        try {
            $poster->post($this->statement->fresh(), auth()->id());
        } catch (InvalidBankStatementException|InvalidInvoiceStateException $e) {
            $this->addError('post', $e->getMessage());

            return;
        }

        $this->statement = $this->statement->fresh();
    }

    public function reopen(BankStatementPoster $poster): void
    {
        Gate::authorize('create', JournalEntry::class);

        try {
            $poster->reopen($this->statement->fresh());
        } catch (InvalidBankStatementException $e) {
            $this->addError('post', $e->getMessage());

            return;
        }

        $this->statement = $this->statement->fresh();
    }

    private function persist(): bool
    {
        Gate::authorize('create', JournalEntry::class);

        if ($this->statement->isBooked()) {
            return false;
        }

        $this->validate([
            'openingBalance' => ['nullable', 'regex:/^-?\d+(\.\d{1,2})?$/'],
            'closingBalance' => ['nullable', 'regex:/^-?\d+(\.\d{1,2})?$/'],
            'lines.*.line_date' => ['required', 'date'],
            'lines.*.direction' => ['required', 'in:in,out'],
            'lines.*.amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'lines.*.kind' => ['required', 'in:invoice_payment,account,unclear'],
        ], [
            'openingBalance.regex' => 'Почетната состојба е број (пример 1000.50 или −200).',
            'closingBalance.regex' => 'Крајната состојба е број (пример 1000.50 или −200).',
            'lines.*.amount.required' => 'Внесете износ на секоја ставка.',
            'lines.*.amount.regex' => 'Износот е број со најмногу две децимали.',
        ]);

        DB::transaction(function () {
            $this->statement->update([
                'opening_balance' => $this->openingBalance === '' ? null : $this->openingBalance,
                'closing_balance' => $this->closingBalance === '' ? null : $this->closingBalance,
            ]);

            $this->statement->lines()->delete();

            foreach (array_values($this->lines) as $position => $line) {
                $isIn = $line['direction'] === LineDirection::IN->value;
                $isInvoice = $line['kind'] === LineKind::INVOICE_PAYMENT->value;

                $this->statement->lines()->create([
                    'position' => $position + 1,
                    'line_date' => $line['line_date'],
                    'direction' => $line['direction'],
                    'amount' => $line['amount'],
                    'partner_id' => $line['partner_id'] ?: null,
                    'description' => $line['description'] ?: null,
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

        return true;
    }

    public function render()
    {
        return view('livewire.bank.bank-statement-book', [
            'accounts' => Account::where('company_id', $this->company->id)->analytical()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'partners' => Partner::where('company_id', $this->company->id)->orderBy('name')->get(['id', 'name']),
            'problems' => $this->statement->isBooked() ? [] : $this->liveProblems(),
        ]);
    }

    /** Проблеми од зачуваното (пред потврда се зачувува автоматски). */
    private function liveProblems(): array
    {
        return $this->statement->exists ? StatementControls::problems($this->statement->fresh('lines')) : [];
    }
}
```

Забелешки за имплементаторот:
- `SalesInvoice::formattedNumber()` постои (се користи во `recordPayment`); колоната за валута е `currency` (`isForeignCurrency()` ја користи).
- Постоечките врзани плаќања („врзи за постоечко плаќање“) се бираат во погледот: кога е избрана фактура, прикажи `select` со `existing_payment_id` од плаќањата на таа фактура со `payment_method = 'bank'` што **не** се врзани за друга ставка (`BankStatementLine::where('sales_invoice_payment_id', …)->doesntExist()`). Додади го како `public function paymentOptions(int $index): array` со истата структура (`id`, `label` = датум + износ) и тест (`test_an_existing_bank_payment_can_be_linked`: врзи → `post` → нема нов ред за плаќање и нема нови редови во налогот).
- `render()` собира `problems` од зачуваното; лентата „разлика“ е од екранот (`difference`).

- [ ] **Step 5: View `resources/views/livewire/bank/bank-statement-book.blade.php`**

Следи ги класите од `bank-statement-index.blade.php` (`x-card`, `x-input-label`, `x-text-input`, `x-primary-button`). Содржина:

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">Книжи извод бр. {{ $statement->number }} — {{ $company->name }}</h1>
    <p class="text-sm text-gray-500 mb-4">
        {{ $statement->bank }} / {{ $statement->account }} / {{ \App\Support\Format::date($statement->statement_date) }}
        @if ($statement->isBooked()) <span class="ml-2 text-green-700 font-medium">Прокнижен</span> @endif
    </p>

    @error('post')
        <div class="mb-4 p-3 rounded bg-red-50 text-red-700 text-sm whitespace-pre-line">{{ $message }}</div>
    @enderror

    <x-card class="mb-4">
        <div class="flex flex-wrap gap-4">
            <div class="w-56">
                <x-input-label for="openingBalance" value="Почетна состојба (Побарува на изводот = +, Долгува = −)" />
                <x-text-input id="openingBalance" wire:model.live.debounce.300ms="openingBalance" class="w-full text-right" :disabled="$statement->isBooked()" />
                @error('openingBalance') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div class="w-56">
                <x-input-label for="closingBalance" value="Крајна состојба (Побарува = +, Долгува = −)" />
                <x-text-input id="closingBalance" wire:model.live.debounce.300ms="closingBalance" class="w-full text-right" :disabled="$statement->isBooked()" />
                @error('closingBalance') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
        </div>
    </x-card>

    <x-card class="mb-4">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 bg-gray-50">
                    <th class="py-1 w-6">#</th><th class="py-1 w-36">Датум</th><th class="py-1 w-28">Вид</th>
                    <th class="py-1 w-32 text-right">Износ</th><th class="py-1 w-48">Партнер</th>
                    <th class="py-1 w-40">Намена</th><th class="py-1">Конто / фактура</th><th class="py-1">Опис</th><th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $i => $line)
                    <tr wire:key="line-{{ $i }}">
                        <td class="py-1">{{ $i + 1 }}</td>
                        <td><x-text-input type="date" wire:model="lines.{{ $i }}.line_date" class="w-full" :disabled="$statement->isBooked()" /></td>
                        <td>
                            <select wire:model.live="lines.{{ $i }}.direction" class="border-gray-300 rounded-md text-sm w-full" @disabled($statement->isBooked())>
                                @foreach (\App\Support\Bank\LineDirection::cases() as $d)<option value="{{ $d->value }}">{{ $d->label() }}</option>@endforeach
                            </select>
                        </td>
                        <td><x-text-input wire:model.live.debounce.300ms="lines.{{ $i }}.amount" class="w-full text-right" :disabled="$statement->isBooked()" />
                            @error("lines.$i.amount") <span class="text-red-600 text-xs">{{ $message }}</span> @enderror</td>
                        <td>
                            <select wire:model.live="lines.{{ $i }}.partner_id" class="border-gray-300 rounded-md text-sm w-full" @disabled($statement->isBooked())>
                                <option value="">—</option>
                                @foreach ($partners as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                            </select>
                        </td>
                        <td>
                            <select wire:model.live="lines.{{ $i }}.kind" class="border-gray-300 rounded-md text-sm w-full" @disabled($statement->isBooked())>
                                @foreach (\App\Support\Bank\LineKind::cases() as $k)<option value="{{ $k->value }}">{{ $k->label() }}</option>@endforeach
                            </select>
                        </td>
                        <td>
                            @if ($line['kind'] === 'invoice_payment')
                                <select wire:model.live="lines.{{ $i }}.invoice_id" class="border-gray-300 rounded-md text-sm w-full" @disabled($statement->isBooked())>
                                    <option value="">— фактура —</option>
                                    @foreach ($this->invoiceOptions($i) as $o)<option value="{{ $o['id'] }}">{{ $o['label'] }}</option>@endforeach
                                </select>
                            @else
                                <select wire:model="lines.{{ $i }}.account_id" class="border-gray-300 rounded-md text-sm w-full" @disabled($statement->isBooked())>
                                    <option value="">— конто —</option>
                                    @foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->code }} {{ $a->name }}</option>@endforeach
                                </select>
                            @endif
                        </td>
                        <td><x-text-input wire:model="lines.{{ $i }}.description" class="w-full" :disabled="$statement->isBooked()" /></td>
                        <td>@unless ($statement->isBooked())<button type="button" wire:click="removeLine({{ $i }})" class="text-red-600 text-sm">Бриши</button>@endunless</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @unless ($statement->isBooked())
            <button type="button" wire:click="addLine" class="mt-3 text-brand text-sm hover:underline">+ Додај ставка</button>
        @endunless
    </x-card>

    <x-card class="mb-4">
        @php($diff = $this->difference)
        <p class="text-sm">
            Разлика (почетна + движење − крајна):
            @if ($diff === null)
                <span class="text-gray-500">внесете ги состојбите</span>
            @elseif (bccomp($diff, '0', 2) === 0)
                <span class="text-green-700 font-semibold">0,00 — се совпаѓа</span>
            @else
                <span class="text-red-700 font-semibold">{{ number_format((float) $diff, 2, ',', '.') }}</span>
            @endif
        </p>
        @if ($problems !== [])
            <ul class="mt-2 text-sm text-red-700 list-disc pl-5">
                @foreach ($problems as $problem)<li>{{ $problem }}</li>@endforeach
            </ul>
        @endif
    </x-card>

    <div class="flex gap-3">
        @if ($statement->isBooked())
            <x-secondary-button wire:click="reopen" wire:confirm="Отворање на изводот го брише неговиот налог. Продолжи?">Отвори за измена</x-secondary-button>
        @else
            <x-secondary-button wire:click="save">Зачувај нацрт</x-secondary-button>
            <x-primary-button wire:click="post">Потврди и прокнижи</x-primary-button>
        @endif
        <a href="{{ route('bank-statements.index', $company) }}" class="text-sm text-gray-600 self-center hover:underline">Назад</a>
    </div>
</div>
```

(Ако `x-secondary-button` не постои — користи го компонентот за секундарно копче што го има проектот; провери `resources/views/components`.)

- [ ] **Step 6: Run — PASS.** `php artisan test tests/Feature/Bank/BankStatementBookTest.php`

- [ ] **Step 7: Browser check** (статичка проверка според меморијата — `artisan serve` е премногу бавен): отвори ја страницата преку Browser pane/харнес и потврди дека табелата се црта и лентата за разлика се менува; ако не е можно, опиши го што е проверено само со тестовите.

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Bank/BankStatementBook.php resources/views/livewire/bank/bank-statement-book.blade.php routes/web.php tests/Feature/Bank/BankStatementBookTest.php
git commit -m "Screen to enter and book the lines of a denar bank statement"
```

---

### Task 9: Список на изводи — статус, врска „Книжи“, контрола вкупно

**Files:**
- Create: `app/Support/Bank/BankLedgerTotals.php`
- Modify: `app/Livewire/Bank/BankStatementIndex.php` (render)
- Modify: `resources/views/livewire/bank/bank-statement-index.blade.php`
- Create: `tests/Feature/Bank/BankLedgerTotalsTest.php`
- Modify: `tests/Feature/Bank/BankStatementIndexTest.php`

**Interfaces:**
- Produces: `BankLedgerTotals::forCompany(Company $company, int $year): array{ledger: string, closings: string, difference: string}`:
  - `ledger` = Σ(дебит − кредит) на редовите на конто 1000 во налози со `fiscal_year = $year`;
  - `closings` = Σ `closing_balance` на најновиот **книжен** денарски извод по сметка во таа година;
  - `difference = ledger − closings`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Bank;

use App\Models\Account;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Support\Bank\BankLedgerTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankLedgerTotalsTest extends TestCase
{
    use RefreshDatabase;

    private function post1000(Company $company, string $debit, string $credit, string $date = '2026-03-05'): void
    {
        $group = JournalGroup::firstOrCreate(['company_id' => $company->id, 'code' => '00'], ['name' => 'Почетна', 'sort_order' => 0]);
        $entry = JournalEntry::create(['company_id' => $company->id, 'journal_group_id' => $group->id, 'entry_date' => $date, 'description' => 't']);
        $entry->lines()->create([
            'account_id' => Account::where('company_id', $company->id)->where('code', '1000')->value('id'),
            'line_date' => $date, 'debit' => $debit, 'credit' => $credit,
        ]);
    }

    public function test_ledger_matches_the_sum_of_the_latest_closings_per_account(): void
    {
        $company = Company::factory()->create();
        $this->post1000($company, '1000.00', '0');
        $this->post1000($company, '500.00', '0');
        BankStatement::factory()->for($company)->create(['number' => 1, 'closing_balance' => '1200.00', 'opening_balance' => '1000.00', 'status' => 'booked']);
        BankStatement::factory()->for($company)->create(['number' => 2, 'closing_balance' => '1500.00', 'opening_balance' => '1200.00', 'status' => 'booked', 'statement_date' => '2026-03-06']);

        $totals = BankLedgerTotals::forCompany($company, 2026);

        $this->assertSame('1500.00', $totals['ledger']);
        $this->assertSame('1500.00', $totals['closings']);
        $this->assertSame('0.00', $totals['difference']);
    }

    public function test_a_gap_shows_as_a_difference(): void
    {
        $company = Company::factory()->create();
        $this->post1000($company, '1000.00', '0');
        BankStatement::factory()->for($company)->create(['closing_balance' => '1700.00', 'opening_balance' => '1000.00', 'status' => 'booked']);

        $this->assertSame('-700.00', BankLedgerTotals::forCompany($company, 2026)['difference']);
    }

    public function test_drafts_and_other_years_are_ignored(): void
    {
        $company = Company::factory()->create();
        $this->post1000($company, '300.00', '0', '2025-12-30');
        BankStatement::factory()->for($company)->create(['closing_balance' => '999.00', 'status' => 'draft']);

        $totals = BankLedgerTotals::forCompany($company, 2026);

        $this->assertSame('0.00', $totals['ledger']);
        $this->assertSame('0.00', $totals['closings']);
    }
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Support\Bank;

use App\Models\Account;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\JournalEntryLine;

/**
 * Контрола „вкупно“: салдото на 1000 мора да е збир на крајните состојби од
 * последниот книжен извод на секоја сметка. На 31.12. тоа се парите во банка.
 */
class BankLedgerTotals
{
    /** @return array{ledger: string, closings: string, difference: string} */
    public static function forCompany(Company $company, int $year): array
    {
        $bankId = Account::where('company_id', $company->id)->where('code', Account::BANK_CODE)->value('id');

        $lines = JournalEntryLine::where('account_id', $bankId)
            ->whereHas('journalEntry', fn ($entry) => $entry->where('company_id', $company->id)->where('fiscal_year', $year))
            ->get(['debit', 'credit']);

        $ledger = $lines->reduce(
            fn (string $carry, $line) => bcsub(bcadd($carry, (string) $line->debit, 2), (string) $line->credit, 2),
            '0.00'
        );

        $closings = BankStatement::where('company_id', $company->id)
            ->where('kind', BankStatementKind::DENAR)
            ->where('status', BankStatement::STATUS_BOOKED)
            ->whereYear('statement_date', $year)
            ->orderBy('number')
            ->get()
            ->groupBy('account')
            ->reduce(fn (string $carry, $group) => bcadd($carry, (string) $group->last()->closing_balance, 2), '0.00');

        return ['ledger' => $ledger, 'closings' => $closings, 'difference' => bcsub($ledger, $closings, 2)];
    }
}
```

`journal_entries.fiscal_year` се полни при `creating` (види `JournalEntry::booted`).

- [ ] **Step 4: List page**

Во `BankStatementIndex::render()` додај:

```php
        $year = \App\Support\WorkingYear::for($this->company);
        $canBook = auth()->user()?->hasAnyRole(['admin', 'accountant']) ?? false;
```

и ги пренеси `'canBook' => $canBook`, `'totals' => $canBook ? BankLedgerTotals::forCompany($this->company, $year) : null`, `'year' => $year`. Во погледот: нова колона **Состојба** (Нацрт / Прокнижен) и во неа за `$canBook` и денарски извод врска `Книжи` (или `Отвори` ако е прокнижен) кон `route('bank-statements.book', [$company, $statement])`. Над листата, за `$canBook` картичка:

> Салдо на конто 1000 за {{ $year }}: X — збир на крајни состојби: Y — разлика: Z (црвено ако ≠ 0, зелено „се совпаѓа“ ако = 0).

Во `BankStatementIndexTest` додај тестови: админ гледа врска „Книжи“ за денарски извод; клиент (внатрешен) **не** ја гледа; девизен извод нема врска „Книжи“; картичката ја покажува разликата.

- [ ] **Step 5: Run — PASS.** `php artisan test tests/Feature/Bank/BankLedgerTotalsTest.php tests/Feature/Bank/BankStatementIndexTest.php tests/Feature/Bank/BankDocumentAccessTest.php`

- [ ] **Step 6: Commit**

```bash
git add app resources tests
git commit -m "Statement list: status, Book link, and the 1000 vs closings total check"
```

---

### Task 10: Документација, целосна серија, спојување

**Files:**
- Modify: `docs/superpowers/specs/2026-10-05-bank-statement-posting-design.md` (одстапувања)
- Create: `docs/superpowers/2026-10-05-bank-statement-posting-log.md`

- [ ] **Step 1: Ажурирај ја спецификацијата** со одстапувањата од планот:
  1. Проверката „почетна = износ во 00-0001 за таа сметка“ не може да се направи (00-0001 е еден налог без поделба по сметка) → кај првиот извод на сметката во годината почетната се прифаќа како внесена, а се проверува само **вкупната контрола** (1000 наспроти збир на крајни состојби) на листата.
  2. Банкарската сметка од извод автоматски се додава во профилот (`company_bank_accounts`) кога првпат се книжи, ако ја нема.
  3. „Отвори за измена“ само за најновиот книжен извод на сметката.
  4. Девизна фактура не може да се плати од денарски извод во фаза 1.
  5. Командата `bank:move-payments-to-1000` за стари плаќања (пробно, потоа `--apply`).

- [ ] **Step 2: Целосна серија** — **прво прашај го корисникот** („пуштам целосна серија, ~14 мин“). Потоа, во позадина: `php artisan test` (или `vendor/bin/phpunit`). Очекувано: сè зелено освен веќе познатиот прескокнат/MarketingScreensTest.

- [ ] **Step 3: Лог** `docs/superpowers/2026-10-05-bank-statement-posting-log.md`: што е направено, одлуките, одстапувањата, отворени работи (екран за почетна состојба 00-0001; правила што се учат; читање од датотека/скен; девизни изводи; префрлање 120/220 на аналитика; **непроверено:** дали продукциските фирми го имаат 1000 и колку стари плаќања се на 100 — пробно извршување на командата).

- [ ] **Step 4: Commit, спој и пушти** (корисникот претходно рече „зелена серија → спој во main и пушти, без прашање“; ова важи САМО ако целата серија е зелена):

```bash
git add docs
git commit -m "Docs: bank statement posting spec amendments and log"
```

Потоа во `main` (без worktree — на оваа машина `composer install` виси) и `git push`; следи го CI преку ccd_pr алатките (не `gh` поллинг). **Не** го извршувај `bank:move-payments-to-1000 --apply` во продукција — само кажи му на корисникот дека може прво пробно.
