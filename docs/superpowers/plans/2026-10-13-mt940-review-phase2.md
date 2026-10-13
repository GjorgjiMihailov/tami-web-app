# MT940 увоз — фаза 2 („проверка една по една“) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ставките од увезен извод што не се „сигурни“ се означени, и сметководителот ги минува една по една (потврдува вид, партнер, фактура/конто); книжењето е блокирано додека има непрегледана ставка.

**Architecture:** Три нови колони на `bank_statement_lines` (`match_confidence`, `match_reason`, `reviewed_at`); увозот ги означува сите ставки „нејасно“. `StatementControls` додава правило за непрегледана ставка. `BankStatementBook` добива знаме `reviewed` во формата, ознаки во табелата и режим „проверка една по една“ во истиот компонент (без нова рута). Матчерот (фаза 4) подоцна ќе поставува `sure`/`check` во истите колони.

**Tech Stack:** Laravel 13, Livewire 3, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-13-mt940-import-design.md` (разделите „Екрани → Проверка една по една“ и „Блокада на книжењето“; фаза 2 од „Фази на градење“).

## Global Constraints

- Ништо не се книжи без потврда од човек; `sure` ставките не бараат преглед, `check` и `unclear` бараат.
- Ставка без `match_confidence` (рачно внесена) не е предмет на преглед — постојниот рачен тек не се менува.
- Податоците што доаѓаат од увозот (`match_*`) се пренесуваат по id од досегашните ставки на истиот извод, никогаш од јавниот `$lines`; единствено `reviewed` е од формата.
- Имињата на Livewire акции не смеат да се резервирани (`upload`, `get`, `set`, `call`…).
- Текст на интерфејс и пораки на македонски. Работи се директно во главната папка (не `EnterWorktree`), гранка `mt940-review`.
- `php artisan test` директно, целата серија само еднаш пред спојување и без менување на датотеки додека трча.

## Review Focus

1. **Прекин на сесијата на средина на проверка:** потврдените ставки остануваат потврдени по повторно отворање (зачувани при секое „Потврди“). → Task 3.
2. **Менување на ставка по потврдување** (друго конто) не ја враќа во „непрегледана“, но сметководителот може да ја промени; книжењето користи тековните вредности. → Task 2.
3. **Измислен `reviewIndex`/ставка што не е за преглед** во барање од прелистувач не смее да фрли 500 ни да потврди погрешна ставка. → Task 3.
4. **Изменета ставка во табелата за време на режимот** не се означува како проверена пред „Потврди“. → Task 3.
5. **Извод во нацрт без ознаки** (рачен) и извод со `sure` ставки се книжат без преглед. → Task 1, Task 2.

---

## File Structure

| Датотека | Одговорност |
|---|---|
| `database/migrations/2026_10_13_110000_add_review_to_bank_statement_lines_table.php` | `match_confidence`, `match_reason`, `reviewed_at` |
| `app/Support/Bank/MatchConfidence.php` | enum `sure`/`check`/`unclear`, `label()`, `needsReview()` |
| `app/Models/BankStatementLine.php` (измена) | `fillable`, `casts`, `needsReview()` |
| `app/Services/Bank/Mt940Importer.php` (измена) | ставките се `unclear` со причина |
| `app/Support/Bank/StatementControls.php` (измена) | проблем „чека проверка“ |
| `app/Livewire/Bank/BankStatementBook.php` (измена) | `reviewed` знаме, пренос на `match_*`, режим на проверка |
| `resources/views/livewire/bank/bank-statement-book.blade.php` (измена) | ознака, лента „N чекаат“, панел за проверка |

---

### Task 1: Колони, enum, увоз ги означува, контрола ги блокира

**Files:**
- Create: `database/migrations/2026_10_13_110000_add_review_to_bank_statement_lines_table.php`
- Create: `app/Support/Bank/MatchConfidence.php`
- Modify: `app/Models/BankStatementLine.php`
- Modify: `app/Services/Bank/Mt940Importer.php` (создавањето на ставки)
- Modify: `app/Support/Bank/StatementControls.php` (`lineProblems`)
- Test: `tests/Feature/Bank/LineReviewControlTest.php`
- Test: `tests/Feature/Bank/Mt940ImporterTest.php` (една тврдња)

**Interfaces:**
- Produces:
  - `enum MatchConfidence: string { SURE='sure'; CHECK='check'; UNCLEAR='unclear'; label(): string; needsReview(): bool }`
  - `BankStatementLine::needsReview(): bool` — `match_confidence?->needsReview() === true && reviewed_at === null`
  - колони `match_confidence` (string 8 nullable), `match_reason` (string nullable), `reviewed_at` (timestamp nullable)

- [ ] **Step 1: Падачки тестови** — `tests/Feature/Bank/LineReviewControlTest.php`

```php
<?php

namespace Tests\Feature\Bank;

use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use App\Support\Bank\MatchConfidence;
use App\Support\Bank\StatementControls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LineReviewControlTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
    }

    /** @param  array<string, mixed>  $line */
    private function statementWith(array $line): BankStatement
    {
        $account = Account::where('company_id', $this->company->id)->where('code', '7400')->firstOrFail();

        $statement = BankStatement::factory()->for($this->company)->create([
            'statement_date' => '2026-03-05', 'opening_balance' => '0.00', 'closing_balance' => '10.00',
        ]);

        BankStatementLine::factory()->for($statement)->create(array_merge([
            'line_date' => '2026-03-05',
            'direction' => LineDirection::IN,
            'amount' => '10.00',
            'kind' => LineKind::ACCOUNT,
            'account_id' => $account->id,
        ], $line));

        return $statement->fresh();
    }

    public function test_an_unreviewed_unclear_line_blocks_posting_even_with_an_account(): void
    {
        $statement = $this->statementWith(['match_confidence' => MatchConfidence::UNCLEAR]);

        $problems = StatementControls::problems($statement);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('чека проверка', $problems[0]);
    }

    public function test_an_unreviewed_check_line_blocks_posting(): void
    {
        $statement = $this->statementWith(['match_confidence' => MatchConfidence::CHECK]);

        $this->assertStringContainsString('чека проверка', implode(' ', StatementControls::problems($statement)));
    }

    public function test_a_reviewed_line_no_longer_blocks(): void
    {
        $statement = $this->statementWith(['match_confidence' => MatchConfidence::UNCLEAR, 'reviewed_at' => now()]);

        $this->assertSame([], StatementControls::problems($statement));
    }

    public function test_a_sure_line_needs_no_review(): void
    {
        $statement = $this->statementWith(['match_confidence' => MatchConfidence::SURE]);

        $this->assertSame([], StatementControls::problems($statement));
    }

    public function test_a_hand_entered_line_without_a_mark_is_not_reviewed_at_all(): void
    {
        $statement = $this->statementWith([]);

        $this->assertSame([], StatementControls::problems($statement));
    }
}
```

И во `tests/Feature/Bank/Mt940ImporterTest.php`, во `test_a_statement_is_created_with_its_lines_and_balances`, веднаш по `assertSame(LineKind::UNCLEAR, $lines[0]->kind);` додај:

```php
        $this->assertSame(\App\Support\Bank\MatchConfidence::UNCLEAR, $lines[0]->match_confidence);
        $this->assertNull($lines[0]->reviewed_at);
```

- [ ] **Step 2: Пушти и потврди дека паѓа**

Run: `php artisan test tests/Feature/Bank/LineReviewControlTest.php tests/Feature/Bank/Mt940ImporterTest.php`
Expected: FAIL — `Class "App\Support\Bank\MatchConfidence" not found` / непозната колона.

- [ ] **Step 3: Миграција** — `database/migrations/2026_10_13_110000_add_review_to_bank_statement_lines_table.php`

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
            // Колку програмата е сигурна во ставката и зошто; null = рачно внесена.
            $table->string('match_confidence', 8)->nullable();
            $table->string('match_reason')->nullable();
            // Кога сметководителот ја потврдил ставката при проверка една по една.
            $table->timestamp('reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropColumn(['match_confidence', 'match_reason', 'reviewed_at']);
        });
    }
};
```

- [ ] **Step 4: Enum** — `app/Support/Bank/MatchConfidence.php`

```php
<?php

namespace App\Support\Bank;

/** Колку е сигурно препознаена една ставка од извод. */
enum MatchConfidence: string
{
    case SURE = 'sure';
    case CHECK = 'check';
    case UNCLEAR = 'unclear';

    public function label(): string
    {
        return match ($this) {
            self::SURE => 'Сигурно',
            self::CHECK => 'Проверете',
            self::UNCLEAR => 'Нејасно',
        };
    }

    /** Само „сигурно“ не бара преглед од човек. */
    public function needsReview(): bool
    {
        return $this !== self::SURE;
    }
}
```

- [ ] **Step 5: Модел** — `app/Models/BankStatementLine.php`

Додај го увозот `use App\Support\Bank\MatchConfidence;`, проширување на `$fillable` со `'match_confidence', 'match_reason', 'reviewed_at'`, во `casts()` додај `'match_confidence' => MatchConfidence::class, 'reviewed_at' => 'datetime',`, и метод:

```php
    /** Означена за преглед од човек, а сè уште непрегледана. */
    public function needsReview(): bool
    {
        return $this->match_confidence?->needsReview() === true && $this->reviewed_at === null;
    }
```

- [ ] **Step 6: Увоз** — во `app/Services/Bank/Mt940Importer.php`, во `$statement->lines()->create([...])` додај по `'kind' => LineKind::UNCLEAR,`:

```php
                    'match_confidence' => MatchConfidence::UNCLEAR,
                    'match_reason' => 'Другата страна не е препознаена.',
```

и додај `use App\Support\Bank\MatchConfidence;` меѓу увозите.

- [ ] **Step 7: Контрола** — во `app/Support/Bank/StatementControls.php`, во `lineProblems()` веднаш по `$problems = [];`:

```php
        if ($line->needsReview()) {
            $problems[] = 'ставката чека проверка („Проверка една по една“).';
        }
```

- [ ] **Step 8: Пушти ги тестовите**

Run: `php artisan test tests/Feature/Bank`
Expected: PASS (сите во `tests/Feature/Bank`, вклучително постојните за книжење).

- [ ] **Step 9: Commit**

```bash
git switch -c mt940-review
git add database/migrations app/Support/Bank/MatchConfidence.php app/Models/BankStatementLine.php app/Services/Bank/Mt940Importer.php app/Support/Bank/StatementControls.php tests/Feature/Bank/LineReviewControlTest.php tests/Feature/Bank/Mt940ImporterTest.php
git commit -m "$(cat <<'EOF'
Bank statement lines carry a confidence mark and a reviewed time; unreviewed lines block posting

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Екранот за книжење — знаме „проверено“, ознаки, лента

**Files:**
- Modify: `app/Livewire/Bank/BankStatementBook.php` (`mount`, `addLine`, `updated`, `persist`, `render`)
- Modify: `resources/views/livewire/bank/bank-statement-book.blade.php`
- Test: `tests/Feature/Bank/BankStatementBookReviewFlagsTest.php`

**Interfaces:**
- Consumes: `BankStatementLine::needsReview()`, `MatchConfidence`, колони (Task 1); `Mt940Importer::importMany`, `Mt940Fixture` (фаза 1).
- Produces: форма-знаме `lines.N.reviewed` (bool); `BankStatementBook::pendingIndexes(): list<int>` и `reviewable(): list<int>` (приватни); приказ `pendingCount`.

- [ ] **Step 1: Падачки тестови** — `tests/Feature/Bank/BankStatementBookReviewFlagsTest.php`

```php
<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementBook;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\User;
use App\Services\Bank\Mt940Importer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Mt940Fixture;
use Tests\TestCase;

class BankStatementBookReviewFlagsTest extends TestCase
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

    /** Увезен извод: уплата 10.00 и исплата 4.00, од 0.00 до 6.00. */
    private function importedStatement(): BankStatement
    {
        $outcome = app(Mt940Importer::class)->importMany($this->company, [[
            'name' => 'a.txt',
            'text' => Mt940Fixture::statement([
                'number' => 1, 'date' => '2026-03-05',
                'lines' => [
                    ['dir' => 'in', 'amount' => '10.00', 'name' => 'ONE'],
                    ['dir' => 'out', 'amount' => '4.00', 'name' => 'TWO'],
                ],
            ]),
        ]])[0];

        return BankStatement::findOrFail($outcome->statementId);
    }

    private function screen(BankStatement $statement)
    {
        return Livewire::test(BankStatementBook::class, ['company' => $this->company, 'statement' => $statement]);
    }

    private function konto(): Account
    {
        return Account::where('company_id', $this->company->id)->where('code', '7400')->firstOrFail();
    }

    public function test_imported_lines_show_their_mark_and_the_pending_count(): void
    {
        $this->screen($this->importedStatement())
            ->assertSee('Нејасно')
            ->assertSee('2 ставки чекаат проверка');
    }

    public function test_choosing_an_account_in_the_table_marks_the_line_reviewed(): void
    {
        $statement = $this->importedStatement();

        $this->screen($statement)
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->konto()->id)
            ->call('save')
            ->assertSee('1 ставка чека проверка');

        $lines = $statement->fresh()->lines()->get();
        $this->assertNotNull($lines[0]->reviewed_at);
        $this->assertNull($lines[1]->reviewed_at);
    }

    public function test_the_reviewed_mark_and_the_import_marks_survive_saving_twice(): void
    {
        $statement = $this->importedStatement();

        $this->screen($statement)
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->konto()->id)
            ->call('save')
            ->call('save');

        $first = $statement->fresh()->lines()->firstOrFail();
        $this->assertNotNull($first->reviewed_at);
        $this->assertSame('unclear', $first->match_confidence->value);
        $this->assertSame('Другата страна не е препознаена.', $first->match_reason);
    }

    public function test_posting_is_blocked_while_a_line_is_unreviewed(): void
    {
        $statement = $this->importedStatement();

        $this->screen($statement)
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->konto()->id)
            ->call('post')
            ->assertHasErrors('post');

        $this->assertFalse($statement->fresh()->isBooked());
    }

    public function test_posting_works_once_every_line_is_handled(): void
    {
        $statement = $this->importedStatement();

        $this->screen($statement)
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->konto()->id)
            ->set('lines.1.kind', 'account')
            ->set('lines.1.account_id', $this->konto()->id)
            ->call('post')
            ->assertHasNoErrors();

        $this->assertTrue($statement->fresh()->isBooked());
    }

    public function test_a_hand_entered_statement_has_no_pending_lines(): void
    {
        $statement = BankStatement::factory()->for($this->company)->create(['statement_date' => '2026-03-05']);

        $this->screen($statement)
            ->call('addLine')
            ->assertDontSee('чекаат проверка')
            ->assertDontSee('чека проверка');
    }
}
```

- [ ] **Step 2: Пушти и потврди дека паѓа**

Run: `php artisan test tests/Feature/Bank/BankStatementBookReviewFlagsTest.php`
Expected: FAIL — не се гледа „2 ставки чекаат проверка“ / `reviewed_at` останува `null`.

- [ ] **Step 3: Компонента** — `app/Livewire/Bank/BankStatementBook.php`

3a. Во `mount()`, во низата на секоја ставка додај по `'existing_payment_id' => ...`:

```php
            'reviewed' => $line->reviewed_at !== null,
```

3b. Во `addLine()`, во новата ставка додај `'reviewed' => true,` (рачна ставка не се проверува).

3c. Замени го `updated()` така што ги зачува постојните правила и додава на крај:

```php
        // Сметководителот сам ја одредил ставката (конто, фактура, партнер, вид):
        // тоа е ист преглед како „Потврди“. Во режимот на проверка тоа го прави
        // копчето „Потврди и следна“, не самото менување.
        if (! $this->reviewing && preg_match('/^lines\.(\d+)\.(kind|account_id|partner_id|invoice_id|existing_payment_id)$/', $name, $m)) {
            $this->lines[(int) $m[1]]['reviewed'] = true;
        }
```

(Својството `$reviewing` се воведува во Task 3; додај го овде со `public bool $reviewing = false;` за да не пука. Task 3 го користи.)

3d. Во `persist()`, во `create([...])` по `'counterparty_name' => $kept?->counterparty_name,` додај:

```php
                    'match_confidence' => $kept?->match_confidence,
                    'match_reason' => $kept?->match_reason,
                    'reviewed_at' => ! empty($line['reviewed']) ? ($kept?->reviewed_at ?? now()) : null,
```

3e. Додај приватни помошници (пред `persist()`):

```php
    /**
     * Индекси на ставки означени за преглед (не „сигурно“). Ознаката се чита од
     * базата по id, не од формата.
     *
     * @return list<int>
     */
    private function reviewable(): array
    {
        $saved = $this->statement->lines()->get()->keyBy('id');
        $indexes = [];

        foreach ($this->lines as $i => $line) {
            if ($saved->get($line['id'] ?? 0)?->match_confidence?->needsReview()) {
                $indexes[] = $i;
            }
        }

        return $indexes;
    }

    /** @return list<int> означени за преглед, а сè уште непотврдени */
    private function pendingIndexes(): array
    {
        return array_values(array_filter($this->reviewable(), fn (int $i) => empty($this->lines[$i]['reviewed'])));
    }
```

3f. Во `render()` додај во низата: `'pendingCount' => $this->statement->isBooked() ? 0 : count($this->pendingIndexes()),`

- [ ] **Step 4: Blade** — `resources/views/livewire/bank/bank-statement-book.blade.php`

4a. Пред првата `<x-card class="mb-4">` (состојбите) додај ленти:

```blade
    @if ($pendingCount > 0)
        <div class="mb-4 p-3 rounded bg-amber-50 text-amber-800 text-sm flex items-center gap-3">
            <span>{{ $pendingCount === 1 ? '1 ставка чека проверка' : $pendingCount.' ставки чекаат проверка' }}</span>
        </div>
    @endif
```

4b. Во заглавието на табелата по `<th class="py-1 w-6">#</th>` додај `<th class="py-1 w-24">Ознака</th>`, а во редот по `<td class="py-1">{{ $i + 1 }}</td>` додај ќелија:

```blade
                        <td class="py-1">
                            @php($saved = ! empty($line['id']) ? $lineMeta->get($line['id']) : null)
                            @if ($saved?->match_confidence)
                                @php($confidence = $saved->match_confidence)
                                <span class="px-2 py-0.5 rounded text-xs {{ ['sure' => 'bg-green-100 text-green-800', 'check' => 'bg-amber-100 text-amber-800', 'unclear' => 'bg-red-100 text-red-800'][$confidence->value] }}"
                                      title="{{ $saved->match_reason }}">{{ $confidence->label() }}</span>
                                @if ($confidence->needsReview() && ! empty($line['reviewed']))
                                    <span class="block text-xs text-green-700">✓ проверено</span>
                                @endif
                            @endif
                        </td>
```

- [ ] **Step 5: Пушти ги тестовите**

Run: `php artisan test tests/Feature/Bank/BankStatementBookReviewFlagsTest.php tests/Feature/Bank/BankStatementBookTest.php tests/Feature/Bank/BankStatementBookImportedDataTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Bank/BankStatementBook.php resources/views/livewire/bank/bank-statement-book.blade.php tests/Feature/Bank/BankStatementBookReviewFlagsTest.php
git commit -m "$(cat <<'EOF'
Bank statement book: marks, pending count and a reviewed flag set by the accountant's own edits

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Режим „Проверка една по една“

**Files:**
- Modify: `app/Livewire/Bank/BankStatementBook.php`
- Modify: `resources/views/livewire/bank/bank-statement-book.blade.php`
- Test: `tests/Feature/Bank/BankStatementReviewStepperTest.php`

**Interfaces:**
- Consumes: `reviewable()`, `pendingIndexes()`, знаме `reviewed` (Task 2); `invoiceOptions()`, `paymentOptions()` (постојни).
- Produces: јавни `bool $reviewing`, `?int $reviewIndex`; акции `startReview()`, `confirmAndNext()`, `skipLine()`, `previousLine()`, `stopReview()`; грешка `review`.

- [ ] **Step 1: Падачки тестови** — `tests/Feature/Bank/BankStatementReviewStepperTest.php`

```php
<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementBook;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Bank\Mt940Importer;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Mt940Fixture;
use Tests\TestCase;

class BankStatementReviewStepperTest extends TestCase
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

    /** @param  list<array<string, string>>  $lines */
    private function importedStatement(array $lines): BankStatement
    {
        $outcome = app(Mt940Importer::class)->importMany($this->company, [[
            'name' => 'a.txt',
            'text' => Mt940Fixture::statement(['number' => 1, 'date' => '2026-03-05', 'lines' => $lines]),
        ]])[0];

        return BankStatement::findOrFail($outcome->statementId);
    }

    private function twoLines(): BankStatement
    {
        return $this->importedStatement([
            ['dir' => 'in', 'amount' => '10.00', 'name' => 'ONE', 'refs' => '1111//2222', 'desc' => 'prv opis'],
            ['dir' => 'out', 'amount' => '4.00', 'name' => 'TWO'],
        ]);
    }

    private function screen(BankStatement $statement)
    {
        return Livewire::test(BankStatementBook::class, ['company' => $this->company, 'statement' => $statement]);
    }

    private function konto(): Account
    {
        return Account::where('company_id', $this->company->id)->where('code', '7400')->firstOrFail();
    }

    public function test_starting_opens_the_first_pending_line_and_shows_the_original_text(): void
    {
        $this->screen($this->twoLines())
            ->call('startReview')
            ->assertSet('reviewing', true)
            ->assertSet('reviewIndex', 0)
            ->assertSee('1 од 2')
            ->assertSee('ONE')
            ->assertSee('1111//2222')
            ->assertSee('prv opis');
    }

    public function test_nothing_to_review_does_not_open_the_mode(): void
    {
        $statement = BankStatement::factory()->for($this->company)->create(['statement_date' => '2026-03-05']);

        $this->screen($statement)->call('startReview')->assertSet('reviewing', false);
    }

    public function test_confirming_without_a_choice_shows_an_error_and_stays(): void
    {
        $statement = $this->twoLines();

        $this->screen($statement)
            ->call('startReview')
            ->call('confirmAndNext')
            ->assertHasErrors('review')
            ->assertSet('reviewIndex', 0);

        $this->assertNull($statement->fresh()->lines()->firstOrFail()->reviewed_at);
    }

    public function test_confirming_an_account_line_saves_it_and_moves_on(): void
    {
        $statement = $this->twoLines();

        $this->screen($statement)
            ->call('startReview')
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->konto()->id)
            ->call('confirmAndNext')
            ->assertHasNoErrors()
            ->assertSet('reviewIndex', 1);

        $first = $statement->fresh()->lines()->firstOrFail();
        $this->assertNotNull($first->reviewed_at);
        $this->assertSame('account', $first->kind->value);
        $this->assertSame($this->konto()->id, $first->account_id);
    }

    public function test_editing_inside_the_mode_does_not_confirm_the_line(): void
    {
        $statement = $this->twoLines();

        $this->screen($statement)
            ->call('startReview')
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->konto()->id)
            ->call('skipLine')
            ->assertSet('reviewIndex', 1);

        $this->assertNull($statement->fresh()->lines()->firstOrFail()->reviewed_at);
    }

    public function test_the_last_confirmation_closes_the_mode_and_clears_the_queue(): void
    {
        $statement = $this->twoLines();

        $this->screen($statement)
            ->call('startReview')
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->konto()->id)
            ->call('confirmAndNext')
            ->set('lines.1.kind', 'account')
            ->set('lines.1.account_id', $this->konto()->id)
            ->call('confirmAndNext')
            ->assertSet('reviewing', false)
            ->assertDontSee('чекаат проверка');

        $this->assertSame(0, $statement->fresh()->lines()->whereNull('reviewed_at')->count());
    }

    public function test_after_a_break_the_confirmed_lines_stay_confirmed(): void
    {
        $statement = $this->twoLines();

        $this->screen($statement)
            ->call('startReview')
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->konto()->id)
            ->call('confirmAndNext');

        $this->screen($statement->fresh())
            ->assertSee('1 ставка чека проверка')
            ->call('startReview')
            ->assertSet('reviewIndex', 1);
    }

    public function test_back_returns_to_the_previous_line(): void
    {
        $this->screen($this->twoLines())
            ->call('startReview')
            ->call('skipLine')
            ->assertSet('reviewIndex', 1)
            ->call('previousLine')
            ->assertSet('reviewIndex', 0);
    }

    public function test_an_invoice_line_is_confirmed_with_partner_and_invoice(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        $invoice = SalesInvoice::factory()->for($this->company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '300.00', 'vat_rate' => '0']);
        app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->admin->id);

        $statement = $this->importedStatement([['dir' => 'in', 'amount' => '300.00']]);

        $this->screen($statement)
            ->call('startReview')
            ->set('lines.0.kind', 'invoice_payment')
            ->set('lines.0.partner_id', $partner->id)
            ->set('lines.0.invoice_id', $invoice->id)
            ->call('confirmAndNext')
            ->assertHasNoErrors()
            ->assertSet('reviewing', false);

        $line = $statement->fresh()->lines()->firstOrFail();
        $this->assertSame($invoice->id, $line->sales_invoice_id);
        $this->assertNotNull($line->reviewed_at);
    }

    public function test_a_forged_review_index_does_nothing(): void
    {
        $statement = $this->twoLines();

        $this->screen($statement)
            ->call('startReview')
            ->set('reviewIndex', 99)
            ->call('confirmAndNext')
            ->assertSet('reviewing', false);

        $this->assertSame(0, $statement->fresh()->lines()->whereNotNull('reviewed_at')->count());
    }

    public function test_a_booked_statement_cannot_be_reviewed(): void
    {
        $statement = $this->twoLines();
        $statement->update(['status' => BankStatement::STATUS_BOOKED]);

        $this->screen($statement->fresh())->call('startReview')->assertSet('reviewing', false);
    }

    public function test_a_failed_save_does_not_leave_the_line_marked(): void
    {
        $statement = $this->twoLines();

        $this->screen($statement)
            ->call('startReview')
            ->set('lines.0.kind', 'account')
            ->set('lines.0.account_id', $this->konto()->id)
            ->set('lines.0.amount', 'abc')
            ->call('confirmAndNext')
            ->assertHasErrors('lines.0.amount')
            ->assertSet('lines.0.reviewed', false);
    }
}
```

- [ ] **Step 2: Пушти и потврди дека паѓа**

Run: `php artisan test tests/Feature/Bank/BankStatementReviewStepperTest.php`
Expected: FAIL — `Public method [startReview] not found`.

- [ ] **Step 3: Компонента** — `app/Livewire/Bank/BankStatementBook.php`

Додај увоз `use Illuminate\Validation\ValidationException;` и јавни својства (ако `$reviewing` веќе е додаден во Task 2, не го дуплирај):

```php
    public bool $reviewing = false;

    public ?int $reviewIndex = null;
```

Додај акции (по `reopen()`):

```php
    /** Ја отвора проверката на првата непотврдена ставка. */
    public function startReview(): void
    {
        if ($this->statement->isBooked()) {
            return;
        }

        $pending = $this->pendingIndexes();

        if ($pending === []) {
            $this->stopReview();

            return;
        }

        $this->resetErrorBag('review');
        $this->reviewing = true;
        $this->reviewIndex = $pending[0];
    }

    public function stopReview(): void
    {
        $this->reviewing = false;
        $this->reviewIndex = null;
        $this->resetErrorBag('review');
    }

    /** Ја потврдува тековната ставка (зачувува) и оди на следната непотврдена. */
    public function confirmAndNext(): void
    {
        $i = $this->currentReviewIndex();

        if ($i === null) {
            return;
        }

        $line = $this->lines[$i];

        $error = match (true) {
            $line['kind'] === LineKind::INVOICE_PAYMENT->value && empty($line['invoice_id']) => 'Изберете фактура.',
            $line['kind'] === LineKind::ACCOUNT->value && empty($line['account_id']) => 'Изберете конто.',
            $line['kind'] === LineKind::UNCLEAR->value => 'Изберете „Плаќање на фактура“ или „Конто“.',
            default => null,
        };

        if ($error !== null) {
            $this->addError('review', $error);

            return;
        }

        $this->resetErrorBag('review');
        $this->lines[$i]['reviewed'] = true;

        try {
            $saved = $this->persist();
        } catch (ValidationException $e) {
            $this->lines[$i]['reviewed'] = false;

            throw $e;
        }

        if (! $saved) {
            return;
        }

        $pending = $this->pendingIndexes();
        $after = array_values(array_filter($pending, fn (int $n) => $n > $i));
        $target = $after[0] ?? ($pending[0] ?? null);

        if ($target === null) {
            $this->stopReview();

            return;
        }

        $this->reviewIndex = $target;
    }

    /** Следна ставка за преглед (потврдена или не), кружно. */
    public function skipLine(): void
    {
        $i = $this->currentReviewIndex();

        if ($i === null) {
            return;
        }

        $all = $this->reviewable();
        $after = array_values(array_filter($all, fn (int $n) => $n > $i));
        $this->reviewIndex = $after[0] ?? $all[0];
    }

    public function previousLine(): void
    {
        $i = $this->currentReviewIndex();

        if ($i === null) {
            return;
        }

        $before = array_values(array_filter($this->reviewable(), fn (int $n) => $n < $i));
        $this->reviewIndex = $before === [] ? $i : end($before);
    }

    /** Тековната ставка ако режимот е валиден; инаку го затвора. */
    private function currentReviewIndex(): ?int
    {
        $i = $this->reviewIndex;

        if ($this->reviewing && $i !== null && ! $this->statement->isBooked()
            && isset($this->lines[$i]) && in_array($i, $this->reviewable(), true)) {
            return $i;
        }

        $this->stopReview();

        return null;
    }
```

Во `render()` додај: `'reviewable' => $this->reviewing ? $this->reviewable() : [],`

- [ ] **Step 4: Blade** — `resources/views/livewire/bank/bank-statement-book.blade.php`

4a. Во лентата „чекаат проверка“ (Task 2, 4a) додај копче во `<div>`:

```blade
            <button type="button" wire:click="startReview" class="ml-auto px-3 py-1 rounded bg-amber-500 text-white text-sm hover:bg-amber-600">Проверка една по една</button>
```

4b. Веднаш по лентата додај панел:

```blade
    @if ($reviewing && $reviewIndex !== null && isset($lines[$reviewIndex]))
        @php($i = $reviewIndex)
        @php($line = $lines[$i])
        @php($saved = ! empty($line['id']) ? $lineMeta->get($line['id']) : null)
        @php($position = array_search($i, $reviewable, true) + 1)
        <x-card class="mb-4 border-2 border-amber-400">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-semibold text-gray-800">Проверка: {{ $position }} од {{ count($reviewable) }}</h2>
                <button type="button" wire:click="stopReview" class="text-sm text-gray-600 hover:underline">Затвори</button>
            </div>

            <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm mb-4">
                <div><dt class="text-gray-500">Датум</dt><dd>{{ \App\Support\Format::date($line['line_date']) }}</dd></div>
                <div><dt class="text-gray-500">Насока и износ</dt>
                    <dd class="font-semibold">{{ $line['direction'] === 'in' ? 'Уплата' : 'Исплата' }} {{ \App\Support\Format::money($line['amount'], '') }}</dd></div>
                <div><dt class="text-gray-500">Другата страна</dt>
                    <dd>{{ $saved?->counterparty_name ?? '—' }} @if ($saved?->counterparty_account)<span class="text-gray-500">· {{ $saved->counterparty_account }}</span>@endif</dd></div>
                <div><dt class="text-gray-500">Повикување на број</dt><dd>{{ $saved?->reference_number ?? '—' }}</dd></div>
                <div class="col-span-2"><dt class="text-gray-500">Опис од банката</dt><dd class="whitespace-pre-line">{{ $saved?->description ?? '—' }}</dd></div>
                <div class="col-span-2"><dt class="text-gray-500">Што мисли програмата</dt>
                    <dd>{{ $saved?->match_confidence?->label() }} — {{ $saved?->match_reason }}</dd></div>
            </dl>

            <div class="flex flex-wrap gap-3 items-end mb-3">
                <div class="w-48">
                    <x-input-label value="Вид" />
                    <select wire:model.live="lines.{{ $i }}.kind" class="border-gray-300 rounded-md text-sm w-full">
                        @foreach (\App\Support\Bank\LineKind::cases() as $kind)
                            <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-64">
                    <x-input-label value="Партнер" />
                    <select wire:model.live="lines.{{ $i }}.partner_id" class="border-gray-300 rounded-md text-sm w-full">
                        <option value="">—</option>
                        @foreach ($partners as $partner)
                            <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-72">
                    @if ($line['kind'] === 'invoice_payment')
                        <x-input-label value="Фактура" />
                        <select wire:model.live="lines.{{ $i }}.invoice_id" class="border-gray-300 rounded-md text-sm w-full">
                            <option value="">— фактура —</option>
                            @foreach ($this->invoiceOptions($i) as $option)
                                <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                        @if (! empty($line['invoice_id']))
                            @php($payments = $this->paymentOptions($i))
                            @if ($payments !== [])
                                <select wire:model.live="lines.{{ $i }}.existing_payment_id" class="mt-1 border-gray-300 rounded-md text-sm w-full">
                                    <option value="">— ново плаќање —</option>
                                    @foreach ($payments as $payment)
                                        <option value="{{ $payment['id'] }}">Веќе внесено: {{ $payment['label'] }}</option>
                                    @endforeach
                                </select>
                            @endif
                        @endif
                    @else
                        <x-input-label value="Конто" />
                        <select wire:model.live="lines.{{ $i }}.account_id" class="border-gray-300 rounded-md text-sm w-full">
                            <option value="">— конто —</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->code }} {{ $account->name }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
            </div>

            @error('review') <p class="text-red-600 text-sm mb-2">{{ $message }}</p> @enderror

            <div class="flex gap-3 items-center">
                <x-primary-button type="button" wire:click="confirmAndNext">Потврди и следна</x-primary-button>
                <x-secondary-button type="button" wire:click="skipLine">Прескокни</x-secondary-button>
                <x-secondary-button type="button" wire:click="previousLine">Назад</x-secondary-button>
            </div>
        </x-card>
    @endif
```

- [ ] **Step 5: Пушти ги тестовите**

Run: `php artisan test tests/Feature/Bank tests/Unit/LivewireReservedMethodNamesTest.php`
Expected: PASS. Ако `test_a_failed_save_does_not_leave_the_line_marked` падне, провери дали исклучокот на `validate()` е `ValidationException` и дали `reviewed` се враќа на `false` пред `throw`.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Bank/BankStatementBook.php resources/views/livewire/bank/bank-statement-book.blade.php tests/Feature/Bank/BankStatementReviewStepperTest.php
git commit -m "$(cat <<'EOF'
Bank statement book: review non-sure lines one by one, confirm saves, posting stays blocked until done

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Спецификација, цела серија, спојување

**Files:**
- Modify: `docs/superpowers/specs/2026-10-13-mt940-import-design.md` (блокада на книжењето)
- Create: `docs/superpowers/2026-10-13-mt940-review-phase2-log.md`

- [ ] **Step 1: Усогласи ја спецификацијата** — во „Блокада на книжењето“ замени го текстот со:

```markdown
**Блокада на книжењето:** додека има ставка означена `unclear` или `check` што нема `reviewed_at`, потврдата е блокирана. `reviewed_at` се поставува кога сметководителот ќе ја потврди ставката во „Проверка една по една“ или сам ќе ѝ го определи видот/контото/партнерот/фактурата во табелата. `sure` ставките не бараат преглед, но се видливи и менливи. Рачно внесените ставки (без ознака) не се предмет на преглед.
```

- [ ] **Step 2: Цела серија (еднаш)**

Run: `php artisan test`
Expected: сите поминуваат (основа 2526 поминати + новите). Не менувај датотеки додека трча.

- [ ] **Step 3: Белешка** — `docs/superpowers/2026-10-13-mt940-review-phase2-log.md`: што е споено, одлуки, отворено (матчер, фаза 3–4), поуки. Комитирај.

- [ ] **Step 4: Спој и пушти**

```bash
git switch main && git merge --no-ff mt940-review -m "Merge MT940 import phase 2 (review one by one)" && git push origin main
```

Следи го CI (`gh run list --branch main --limit 1`, па `gh run watch <id> --exit-status`); живо само кога `test` и `deploy` се `success`. Миграцијата ја извршува deploy-от.
