# Плати 5a — Вработени: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the employee register — employee records, salary history, a two-way gross↔net calculator, and the period-dated payroll parameters it runs on — as the first of three sub-phases of ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ.

**Architecture:** Four МПИН codebooks and the state payroll parameters are seeded into two reference tables, both keyed by a validity period so a calculation for a past month reproduces exactly. A pure `SalaryCalculator` service converts gross→net (and net→gross by binary search) against a `PayrollParameter` row; it has no database or Livewire coupling and is tested against УЈП's published 2026 figures. Three Livewire screens sit on top: the employee list, the employee card with a live calculator, and an admin-only parameters screen.

**Tech Stack:** Laravel 13.8, PHP 8.3, Livewire 3.6, Tailwind, spatie/laravel-permission 8.3, maatwebsite/excel 3.1 (PhpSpreadsheet, used once for the codebook conversion), PHPUnit 12.5.

Spec: `docs/superpowers/specs/2026-08-13-payroll-5a-employees-design.md`

## Global Constraints

- **All user-visible text is Macedonian.** No English strings in Blade, validation messages, or labels.
- **Run `vendor/bin/pint --dirty` before every commit.**
- **Tests are PHPUnit classes** (not Pest), use `RefreshDatabase`, run on SQLite locally and MySQL 8 in CI.
- **Migrations regularly fail on MySQL only.** Keep index names short, declare indexes supporting foreign keys before the foreign key, and do not rely on SQLite's loose type coercion. Budget for a CI-only fix cycle.
- **Data tables use the compact density `py-1 px-3`** on every `<th>`/`<td>`, header row `bg-gray-50`, body row `hover:bg-orange-50`. `tests/Feature/TableDensityTest.php` enforces this across all data-table screens; a new screen that deviates fails the whole suite.
- **Livewire: never read `request()` in `render()`.** Capture route state as a public property in `mount()`.
- **Never `git push`.** The user pushes; pushing to `main` deploys to production.
- **Column names are English** (`embg`, `first_name`, `effective_from`), matching every existing table in this codebase, even though the spec's tables name the fields in Macedonian. The spec describes meaning; the codebase convention governs naming. User-visible labels remain Macedonian.
- **Money rounding:** every intermediate is `round($x, 2)`; whole-denar values are produced only by `SalaryBreakdown::whole()`. Assertions must be made on `whole()` values, never on the 2-decimal intermediates — the intermediates are float-sensitive at the cent level (`38507 * 0.075` lands either side of `2888.025` depending on the double), while the whole-denar results are stable.

---

## File Structure

**Created:**

| File | Responsibility |
|---|---|
| `database/data/payroll-codes/*.json` | Four МПИН codebooks converted from the untracked `.xls` files, committed so seeding is reproducible without them |
| `database/migrations/*_create_payroll_codes_table.php` | Reference table + seed from the JSON |
| `app/Models/PayrollCode.php` | Codebook lookups |
| `database/migrations/*_create_payroll_parameters_table.php` | State rates/bases + seed of the 2026 periods |
| `app/Models/PayrollParameter.php` | Parameter set in force on a date |
| `app/Support/Embg.php` | ЕМБГ format + check-digit validation, pure function |
| `app/Rules/ValidEmbg.php` | Laravel rule wrapping `Embg` |
| `app/Support/Payroll/SalaryBreakdown.php` | Immutable result of one calculation |
| `app/Support/Payroll/SalaryCalculator.php` | gross→net and net→gross |
| `database/migrations/*_create_employees_table.php` | Employee card |
| `database/migrations/*_create_employee_salaries_table.php` | Salary history |
| `app/Models/Employee.php`, `app/Models/EmployeeSalary.php` | |
| `database/factories/EmployeeFactory.php`, `EmployeeSalaryFactory.php` | |
| `app/Policies/EmployeePolicy.php` | |
| `app/Livewire/EmployeeIndex.php` + `resources/views/livewire/employee-index.blade.php` | List |
| `app/Livewire/EmployeeForm.php` + `resources/views/livewire/employee-form.blade.php` | Card with live calculator |
| `app/Livewire/PayrollParameterIndex.php` + `resources/views/livewire/payroll-parameter-index.blade.php` | Admin-only parameters |

**Modified:** `app/Support/Menu.php`, `routes/web.php`, `tests/Unit/Support/MenuTest.php`.

---

### Task 1: МПИН codebooks

The employee card has four dropdowns fed by УЈП codebooks. The source `.xls` files live in `ujp_mpin_xml/` and are **not** in git, so they are converted once to committed JSON and the migration seeds from that. All four share the same two-column shape (`Kod`, `Naziv`/`Opis`).

**Files:**
- Create: `database/data/payroll-codes/opstina.json`, `vid_staz.json`, `sifra_dviz.json`, `osloboduvanje.json`
- Create: `database/migrations/2026_08_13_090000_create_payroll_codes_table.php`
- Create: `app/Models/PayrollCode.php`
- Test: `tests/Feature/PayrollCodeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `PayrollCode::ofType(string $type): Collection` returning models with `code` and `name` strings. Valid `$type` values are exactly `opstina`, `vid_staz`, `sifra_dviz`, `osloboduvanje`, available as `PayrollCode::TYPES`.

- [ ] **Step 1: Convert the four .xls files to JSON**

Write this throwaway script to `storage/app/private/convert-codes.php`, run it, then delete it. It uses PhpSpreadsheet, already present via `maatwebsite/excel`.

```php
<?php

require __DIR__.'/../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$root = dirname(__DIR__, 3);

$map = [
    'opshtini.xls' => 'opstina',
    'VID_STAZ.xls' => 'vid_staz',
    'sifra_dviz.xls' => 'sifra_dviz',
    'osloboduvanja.xlsx' => 'osloboduvanje',
];

@mkdir($root.'/database/data/payroll-codes', 0755, true);

foreach ($map as $file => $type) {
    $rows = IOFactory::load($root.'/ujp_mpin_xml/'.$file)->getActiveSheet()->toArray(null, true, false, false);

    $codes = [];

    foreach ($rows as $i => $row) {
        if ($i === 0) {
            continue; // header: Kod | Naziv
        }

        $code = trim((string) ($row[0] ?? ''));
        $name = trim((string) ($row[1] ?? ''));

        if ($code === '' || $name === '') {
            continue;
        }

        $codes[] = ['code' => $code, 'name' => $name];
    }

    file_put_contents(
        $root.'/database/data/payroll-codes/'.$type.'.json',
        json_encode($codes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n"
    );

    echo $type.': '.count($codes)." codes\n";
}
```

Run: `php storage/app/private/convert-codes.php`
Expected output, exactly:
```
opstina: 86 codes
vid_staz: 30 codes
sifra_dviz: 8 codes
osloboduvanje: 10 codes
```
Then: `rm storage/app/private/convert-codes.php`

Spot-check `database/data/payroll-codes/opstina.json` contains `{"code": "175", "name": "АЕРОДРОМ"}` and that the Cyrillic is not escaped. **Do not hand-edit these files** — codes such as `0050` and `0040` in `vid_staz` are zero-padded strings and must stay strings.

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\PayrollCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_seeds_all_four_codebooks(): void
    {
        $this->assertSame(86, PayrollCode::ofType('opstina')->count());
        $this->assertSame(30, PayrollCode::ofType('vid_staz')->count());
        $this->assertSame(8, PayrollCode::ofType('sifra_dviz')->count());
        $this->assertSame(10, PayrollCode::ofType('osloboduvanje')->count());
    }

    public function test_it_keeps_zero_padded_codes_as_strings(): void
    {
        // 0050 "Време поминато во работен однос со полно работно време" is the
        // ordinary full-time code and the one nearly every employee carries.
        // Stored as an integer it would become "50" and МПИН would reject it.
        $code = PayrollCode::ofType('vid_staz')->firstWhere('code', '0050');

        $this->assertNotNull($code, 'The full-time insurance code 0050 is missing.');
        $this->assertSame('Време поминато во работен однос со полно работно време', $code->name);
    }

    public function test_it_exposes_a_known_municipality(): void
    {
        $this->assertSame(
            'АЕРОДРОМ',
            PayrollCode::ofType('opstina')->firstWhere('code', '175')?->name
        );
    }
}
```

- [ ] **Step 3: Run it to make sure it fails**

Run: `php artisan test --filter=PayrollCodeTest`
Expected: FAIL — `Class "App\Models\PayrollCode" not found`.

- [ ] **Step 4: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_codes', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('code', 16);
            $table->string('name');
            $table->timestamps();

            // Short explicit name: MySQL caps index identifiers at 64 chars and
            // the generated one would be close to the limit.
            $table->unique(['type', 'code'], 'payroll_codes_type_code_unique');
        });

        foreach (['opstina', 'vid_staz', 'sifra_dviz', 'osloboduvanje'] as $type) {
            $path = database_path('data/payroll-codes/'.$type.'.json');
            $rows = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            DB::table('payroll_codes')->insert(array_map(fn (array $row) => [
                'type' => $type,
                'code' => $row['code'],
                'name' => $row['name'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $rows));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_codes');
    }
};
```

- [ ] **Step 5: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class PayrollCode extends Model
{
    public const TYPES = ['opstina', 'vid_staz', 'sifra_dviz', 'osloboduvanje'];

    protected $fillable = ['type', 'code', 'name'];

    /** @return Collection<int, self> */
    public static function ofType(string $type): Collection
    {
        return static::where('type', $type)->orderBy('code')->get();
    }
}
```

- [ ] **Step 6: Run the tests and make sure they pass**

Run: `php artisan test --filter=PayrollCodeTest`
Expected: PASS, 3 tests.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add database/data/payroll-codes database/migrations app/Models/PayrollCode.php tests/Feature/PayrollCodeTest.php
git commit -m "feat(payroll): seed the four MPIN codebooks the employee card needs"
```

---

### Task 2: Payroll parameters

State rates, the personal allowance and the contribution bases, each row valid from a date. Values are taken from the spec's source table; **none of them may be recalculated** (the published minimum base 34.571 is not 50% of 69.141 exactly — the rounding is УЈП's).

**Files:**
- Create: `database/migrations/2026_08_13_090100_create_payroll_parameters_table.php`
- Create: `app/Models/PayrollParameter.php`
- Test: `tests/Feature/PayrollParameterTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `PayrollParameter::forDate(string $date): self` — throws `RuntimeException` when no period covers the date. Float columns: `rate_pension`, `rate_health`, `rate_injury`, `rate_unemployment`, `rate_tax`, `personal_allowance`, `average_salary`, `min_base`, `max_base`, `minimum_wage`; date column `effective_from`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\PayrollParameter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PayrollParameterTest extends TestCase
{
    use RefreshDatabase;

    public function test_february_2026_uses_the_original_rates(): void
    {
        $p = PayrollParameter::forDate('2026-02-28');

        $this->assertSame(18.8, $p->rate_pension);
        $this->assertSame(1.2, $p->rate_unemployment);
        $this->assertSame(36037.0, $p->minimum_wage);
    }

    public function test_april_2026_keeps_the_rates_but_raises_the_minimum_wage(): void
    {
        $p = PayrollParameter::forDate('2026-04-15');

        $this->assertSame(18.8, $p->rate_pension);
        $this->assertSame(38507.0, $p->minimum_wage);
    }

    public function test_august_2026_uses_the_new_rates(): void
    {
        // Confirmed by the user: the new rates apply from the JULY salary.
        $p = PayrollParameter::forDate('2026-08-01');

        $this->assertSame(19.9, $p->rate_pension);
        $this->assertSame(0.1, $p->rate_unemployment);
        $this->assertSame(7.5, $p->rate_health);
        $this->assertSame(0.5, $p->rate_injury);
    }

    public function test_the_shared_values_are_the_published_ones(): void
    {
        $p = PayrollParameter::forDate('2026-08-01');

        $this->assertSame(10932.0, $p->personal_allowance);
        $this->assertSame(69141.0, $p->average_salary);
        // Deliberately NOT 50% of the average — 34.570,5 rounds to УЈП's 34.571.
        $this->assertSame(34571.0, $p->min_base);
        $this->assertSame(1106256.0, $p->max_base);
        $this->assertSame(10.0, $p->rate_tax);
    }

    public function test_it_refuses_a_date_before_any_known_period(): void
    {
        $this->expectException(RuntimeException::class);

        PayrollParameter::forDate('2019-01-01');
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=PayrollParameterTest`
Expected: FAIL — `Class "App\Models\PayrollParameter" not found`.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_parameters', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from')->unique();
            $table->decimal('rate_pension', 6, 3);
            $table->decimal('rate_health', 6, 3);
            $table->decimal('rate_injury', 6, 3);
            $table->decimal('rate_unemployment', 6, 3);
            $table->decimal('rate_tax', 6, 3);
            $table->decimal('personal_allowance', 12, 2);
            $table->decimal('average_salary', 12, 2);
            $table->decimal('min_base', 12, 2);
            $table->decimal('max_base', 12, 2);
            $table->decimal('minimum_wage', 12, 2);
            $table->timestamps();
        });

        $shared = [
            'rate_health' => 7.5,
            'rate_injury' => 0.5,
            'rate_tax' => 10,
            'personal_allowance' => 10932,
            'average_salary' => 69141,
            'min_base' => 34571,
            'max_base' => 1106256,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('payroll_parameters')->insert([
            // Rates unchanged Jan–Jun; only the minimum wage moves on 1 March.
            ['effective_from' => '2026-01-01', 'rate_pension' => 18.8, 'rate_unemployment' => 1.2, 'minimum_wage' => 36037] + $shared,
            ['effective_from' => '2026-03-01', 'rate_pension' => 18.8, 'rate_unemployment' => 1.2, 'minimum_wage' => 38507] + $shared,
            // User-confirmed: the new rates apply from the JULY salary. The draft
            // law's "започнувајќи со исплатата на платата за месец јуни" does not
            // refer to the month being calculated for.
            ['effective_from' => '2026-07-01', 'rate_pension' => 19.9, 'rate_unemployment' => 0.1, 'minimum_wage' => 38507] + $shared,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_parameters');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class PayrollParameter extends Model
{
    protected $fillable = [
        'effective_from', 'rate_pension', 'rate_health', 'rate_injury',
        'rate_unemployment', 'rate_tax', 'personal_allowance',
        'average_salary', 'min_base', 'max_base', 'minimum_wage',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'rate_pension' => 'float',
            'rate_health' => 'float',
            'rate_injury' => 'float',
            'rate_unemployment' => 'float',
            'rate_tax' => 'float',
            'personal_allowance' => 'float',
            'average_salary' => 'float',
            'min_base' => 'float',
            'max_base' => 'float',
            'minimum_wage' => 'float',
        ];
    }

    /**
     * The parameter set in force on the given date: the newest period that
     * started on or before it.
     */
    public static function forDate(string $date): self
    {
        $parameter = static::where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->first();

        if ($parameter === null) {
            throw new RuntimeException("Нема параметри за пресметка што важат на {$date}.");
        }

        return $parameter;
    }
}
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `php artisan test --filter=PayrollParameterTest`
Expected: PASS, 5 tests.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add database/migrations app/Models/PayrollParameter.php tests/Feature/PayrollParameterTest.php
git commit -m "feat(payroll): add period-dated payroll parameters with the 2026 values"
```

---

### Task 3: ЕМБГ validation

Catches a typo at entry instead of letting УЈП reject a whole calculation two months later. Whether an ЕМБГ actually exists in УЈП's register cannot be checked from here — only submission reveals that.

**Files:**
- Create: `app/Support/Embg.php`
- Create: `app/Rules/ValidEmbg.php`
- Test: `tests/Unit/Support/EmbgTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Embg::isValid(string $embg): bool`; `App\Rules\ValidEmbg` usable in any Laravel rule array.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Support;

use App\Support\Embg;
use PHPUnit\Framework\TestCase;

class EmbgTest extends TestCase
{
    public function test_it_accepts_a_number_with_a_correct_check_digit(): void
    {
        // Worked example from the algorithm's source: weights 7,6,5,4,3,2
        // repeated over the first 12 digits give a sum of 145; 145 mod 11 = 2;
        // 11 - 2 = 9, the final digit.
        $this->assertTrue(Embg::isValid('3101980455019'));
    }

    public function test_it_rejects_a_wrong_check_digit(): void
    {
        $this->assertFalse(Embg::isValid('3101980455018'));
    }

    public function test_it_rejects_anything_that_is_not_thirteen_digits(): void
    {
        $this->assertFalse(Embg::isValid('310198045501'));
        $this->assertFalse(Embg::isValid('31019804550199'));
        $this->assertFalse(Embg::isValid('310198045501X'));
        $this->assertFalse(Embg::isValid(''));
        $this->assertFalse(Embg::isValid('3101980 455019'));
    }

    public function test_it_rejects_an_impossible_birth_date(): void
    {
        // Day 32 — the first two digits are the day of birth.
        $this->assertFalse(Embg::isValid('3201980455010'));
    }

    public function test_a_remainder_of_one_is_never_a_valid_number(): void
    {
        // For the prefix 010199045009 the weighted sum is
        //   0·7 + 1·6 + 0·5 + 1·4 + 9·3 + 9·2 + 0·7 + 4·6 + 5·5 + 0·4 + 0·3 + 9·2
        //   = 0 + 6 + 0 + 4 + 27 + 18 + 0 + 24 + 25 + 0 + 0 + 18 = 122
        // and 122 mod 11 = 1, so the check digit would have to be 11 - 1 = 10 —
        // impossible in one position. No such ЕМБГ was ever issued, so every
        // one of the ten possible endings must be rejected.
        foreach (range(0, 9) as $digit) {
            $this->assertFalse(
                Embg::isValid('010199045009'.$digit),
                "010199045009{$digit} has a remainder of 1 and cannot be a real ЕМБГ."
            );
        }
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=EmbgTest`
Expected: FAIL — `Class "App\Support\Embg" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Support;

class Embg
{
    /**
     * ДДММГГГРРБББК — 13 digits, the last being a modulo-11 check digit over
     * the first 12 with the weights 7,6,5,4,3,2 repeated twice.
     *
     * A remainder of 1 would require a check digit of 10, which cannot be
     * written in one position, so such numbers were never issued.
     */
    private const WEIGHTS = [7, 6, 5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    public static function isValid(string $embg): bool
    {
        if (preg_match('/^\d{13}$/', $embg) !== 1) {
            return false;
        }

        $day = (int) substr($embg, 0, 2);
        $month = (int) substr($embg, 2, 2);

        if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
            return false;
        }

        $sum = 0;

        foreach (self::WEIGHTS as $position => $weight) {
            $sum += ((int) $embg[$position]) * $weight;
        }

        $remainder = $sum % 11;

        if ($remainder === 1) {
            return false;
        }

        $check = $remainder === 0 ? 0 : 11 - $remainder;

        return $check === (int) $embg[12];
    }
}
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `php artisan test --filter=EmbgTest`
Expected: PASS.

- [ ] **Step 5: Write the rule class**

```php
<?php

namespace App\Rules;

use App\Support\Embg;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidEmbg implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Embg::isValid($value)) {
            $fail('ЕМБГ не е валиден — проверете ги 13-те цифри.');
        }
    }
}
```

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Support/Embg.php app/Rules/ValidEmbg.php tests/Unit/Support/EmbgTest.php
git commit -m "feat(payroll): validate EMBG format and check digit"
```

---

### Task 4: Gross → net

The heart of the phase. **Read the spec's "Пресметувачот" section in full before starting** — two rules there are easy to get backwards and both are load-bearing.

**Files:**
- Create: `app/Support/Payroll/SalaryBreakdown.php`
- Create: `app/Support/Payroll/SalaryCalculator.php`
- Test: `tests/Feature/Payroll/SalaryCalculatorTest.php`

**Interfaces:**
- Consumes: `PayrollParameter` (Task 2).
- Produces: `SalaryCalculator::fromGross(float $gross, PayrollParameter $p): SalaryBreakdown`. `SalaryBreakdown` has public readonly float properties `gross`, `pension`, `health`, `injury`, `unemployment`, `contributions`, `taxBase`, `tax`, `net`, `topUpPension`, `topUpHealth`, `topUpInjury`, `topUpUnemployment`, `topUp`, and the method `whole(): array<string, int>` keyed by those same names.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Payroll;

use App\Models\PayrollParameter;
use App\Support\Payroll\SalaryCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalaryCalculatorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * These are not invented numbers. Every figure below is УЈП's published
     * calculation of the 2026 minimum wage. If the calculator disagrees with
     * any of them by one denar, the calculator is wrong.
     */
    public function test_it_reproduces_the_published_minimum_wage_for_january_2026(): void
    {
        $breakdown = SalaryCalculator::fromGross(36037, PayrollParameter::forDate('2026-01-31'));

        $this->assertSame([
            'gross' => 36037,
            'pension' => 6775,
            'health' => 2703,
            'injury' => 180,
            'unemployment' => 432,
            'tax' => 1501,
            'net' => 24445,
        ], array_intersect_key($breakdown->whole(), array_flip([
            'gross', 'pension', 'health', 'injury', 'unemployment', 'tax', 'net',
        ])));
    }

    public function test_it_reproduces_the_published_minimum_wage_for_july_2026(): void
    {
        $breakdown = SalaryCalculator::fromGross(38507, PayrollParameter::forDate('2026-07-31'));

        $this->assertSame([
            'gross' => 38507,
            'pension' => 7663,
            'health' => 2888,
            'injury' => 193,
            'unemployment' => 39,
            'tax' => 1679,
            'net' => 26046,
        ], array_intersect_key($breakdown->whole(), array_flip([
            'gross', 'pension', 'health', 'injury', 'unemployment', 'tax', 'net',
        ])));
    }

    public function test_rounding_is_a_write_rule_not_a_calculation_step(): void
    {
        // Chaining rounded values instead of carrying two decimals gives 26.045
        // here. The published figure is 26.046. This test exists so that a
        // future "simplification" that rounds each step is caught immediately.
        $breakdown = SalaryCalculator::fromGross(38507, PayrollParameter::forDate('2026-07-31'));

        $this->assertSame(26046, $breakdown->whole()['net']);
        $this->assertNotSame(26045, $breakdown->whole()['net']);
    }

    public function test_the_top_up_to_the_minimum_base_does_not_reduce_the_employees_net(): void
    {
        $parameter = PayrollParameter::forDate('2026-07-31');
        $gross = 20000.0;

        $breakdown = SalaryCalculator::fromGross($gross, $parameter);

        // Contributions are charged on the employee's own gross...
        $this->assertSame(3980, $breakdown->whole()['pension']); // 20000 × 19,9%
        $this->assertSame(1500, $breakdown->whole()['health']);  // 20000 × 7,5%

        // ...and the top-up to 34.571 is reported separately, as employer cost.
        $this->assertGreaterThan(0, $breakdown->whole()['topUp']);
        $this->assertSame(2898, $breakdown->whole()['topUpPension']); // 14571 × 19,9%

        // The employee's net must be exactly what it would be with no minimum
        // base at all. This is the assertion that protects the worker from
        // paying somebody else's obligation.
        $contributions = 3980 + 1500 + 100 + 20;          // 19,9 + 7,5 + 0,5 + 0,1 %
        $taxBase = 20000 - $contributions - 10932;
        $expectedNet = (int) round(20000 - $contributions - $taxBase * 0.10);

        $this->assertSame($expectedNet, $breakdown->whole()['net']);
    }

    public function test_contributions_stop_at_the_maximum_base(): void
    {
        $parameter = PayrollParameter::forDate('2026-07-31');

        $atCap = SalaryCalculator::fromGross(1106256, $parameter);
        $aboveCap = SalaryCalculator::fromGross(1500000, $parameter);

        $this->assertSame($atCap->whole()['pension'], $aboveCap->whole()['pension']);
        $this->assertSame($atCap->whole()['health'], $aboveCap->whole()['health']);
    }

    public function test_the_tax_base_never_goes_below_zero(): void
    {
        // Gross under the personal allowance: no tax, and net is gross minus
        // contributions only.
        $breakdown = SalaryCalculator::fromGross(8000, PayrollParameter::forDate('2026-07-31'));

        $this->assertSame(0, $breakdown->whole()['tax']);
        $this->assertSame(0, $breakdown->whole()['taxBase']);
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=SalaryCalculatorTest`
Expected: FAIL — `Class "App\Support\Payroll\SalaryCalculator" not found`.

- [ ] **Step 3: Write the breakdown object**

```php
<?php

namespace App\Support\Payroll;

/**
 * One salary calculation. All properties carry two decimals; whole denars are
 * produced only by whole(), because rounding to a whole number is МПИН's
 * write rule and not a step in the calculation.
 */
readonly class SalaryBreakdown
{
    public function __construct(
        public float $gross,
        public float $pension,
        public float $health,
        public float $injury,
        public float $unemployment,
        public float $contributions,
        public float $taxBase,
        public float $tax,
        public float $net,
        public float $topUpPension,
        public float $topUpHealth,
        public float $topUpInjury,
        public float $topUpUnemployment,
        public float $topUp,
    ) {}

    /** @return array<string, int> */
    public function whole(): array
    {
        return [
            'gross' => (int) round($this->gross),
            'pension' => (int) round($this->pension),
            'health' => (int) round($this->health),
            'injury' => (int) round($this->injury),
            'unemployment' => (int) round($this->unemployment),
            'contributions' => (int) round($this->contributions),
            'taxBase' => (int) round($this->taxBase),
            'tax' => (int) round($this->tax),
            'net' => (int) round($this->net),
            'topUpPension' => (int) round($this->topUpPension),
            'topUpHealth' => (int) round($this->topUpHealth),
            'topUpInjury' => (int) round($this->topUpInjury),
            'topUpUnemployment' => (int) round($this->topUpUnemployment),
            'topUp' => (int) round($this->topUp),
        ];
    }
}
```

- [ ] **Step 4: Write the calculator**

```php
<?php

namespace App\Support\Payroll;

use App\Models\PayrollParameter;

class SalaryCalculator
{
    public static function fromGross(float $gross, PayrollParameter $p): SalaryBreakdown
    {
        // The employee's own contributions are charged on their gross, capped
        // at the maximum base. The minimum base does NOT raise this — see the
        // top-up below.
        $base = min($gross, $p->max_base);

        $pension = self::share($base, $p->rate_pension);
        $health = self::share($base, $p->rate_health);
        $injury = self::share($base, $p->rate_injury);
        $unemployment = self::share($base, $p->rate_unemployment);
        $contributions = round($pension + $health + $injury + $unemployment, 2);

        $taxBase = round(max($gross - $contributions - $p->personal_allowance, 0), 2);
        $tax = self::share($taxBase, $p->rate_tax);
        $net = round($gross - $contributions - $tax, 2);

        // The top-up to the minimum base is the employer's obligation. It is
        // deliberately outside $contributions, outside $taxBase and outside
        // $net: folding it in would make the employee pay it.
        $shortfall = max($p->min_base - $gross, 0);

        $topUpPension = self::share($shortfall, $p->rate_pension);
        $topUpHealth = self::share($shortfall, $p->rate_health);
        $topUpInjury = self::share($shortfall, $p->rate_injury);
        $topUpUnemployment = self::share($shortfall, $p->rate_unemployment);

        return new SalaryBreakdown(
            gross: round($gross, 2),
            pension: $pension,
            health: $health,
            injury: $injury,
            unemployment: $unemployment,
            contributions: $contributions,
            taxBase: $taxBase,
            tax: $tax,
            net: $net,
            topUpPension: $topUpPension,
            topUpHealth: $topUpHealth,
            topUpInjury: $topUpInjury,
            topUpUnemployment: $topUpUnemployment,
            topUp: round($topUpPension + $topUpHealth + $topUpInjury + $topUpUnemployment, 2),
        );
    }

    private static function share(float $base, float $ratePercent): float
    {
        return round($base * $ratePercent / 100, 2);
    }
}
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `php artisan test --filter=SalaryCalculatorTest`
Expected: PASS, 6 tests.

If `test_the_top_up_to_the_minimum_base_does_not_reduce_the_employees_net` fails on the `topUpPension` figure, recompute `14571 × 19,9% = 2899.629` by hand and correct the expectation — do **not** change the calculator to match a hand-written number without redoing the arithmetic.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Support/Payroll tests/Feature/Payroll/SalaryCalculatorTest.php
git commit -m "feat(payroll): gross to net calculator verified against UJP's published figures"
```

---

### Task 5: Net → gross

Employers think in net. There is no closed formula because the minimum base and the zero floor on the tax base both break linearity, so this is a binary search over the monotone gross→net function.

**Files:**
- Modify: `app/Support/Payroll/SalaryCalculator.php`
- Test: `tests/Feature/Payroll/SalaryCalculatorNetToGrossTest.php`

**Interfaces:**
- Consumes: `SalaryCalculator::fromGross` (Task 4).
- Produces: `SalaryCalculator::fromNet(float $net, PayrollParameter $p): SalaryBreakdown` — the returned breakdown is the result of `fromGross()` on the whole-denar gross it found, so it is internally consistent.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Payroll;

use App\Models\PayrollParameter;
use App\Support\Payroll\SalaryCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalaryCalculatorNetToGrossTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_recovers_the_published_january_minimum_wage_from_its_net(): void
    {
        $breakdown = SalaryCalculator::fromNet(24445, PayrollParameter::forDate('2026-01-31'));

        $this->assertSame(36037, $breakdown->whole()['gross']);
    }

    public function test_it_recovers_the_published_july_minimum_wage_from_its_net(): void
    {
        $breakdown = SalaryCalculator::fromNet(26046, PayrollParameter::forDate('2026-07-31'));

        $this->assertSame(38507, $breakdown->whole()['gross']);
    }

    public function test_a_net_contract_costs_more_gross_after_the_july_rate_change(): void
    {
        $june = SalaryCalculator::fromNet(30000, PayrollParameter::forDate('2026-06-30'));
        $july = SalaryCalculator::fromNet(30000, PayrollParameter::forDate('2026-07-31'));

        // ПИО went 18,8% → 19,9% while unemployment went 1,2% → 0,1%, so the
        // total stayed at 28% — but the tax base moves, so gross is not
        // identical. Whichever way it moves, the employee still gets 30.000.
        $this->assertSame(30000, $june->whole()['net']);
        $this->assertSame(30000, $july->whole()['net']);
    }

    public function test_the_round_trip_is_stable_across_a_range_of_salaries(): void
    {
        $parameter = PayrollParameter::forDate('2026-07-31');

        foreach ([15000, 25000, 40000, 75000, 250000] as $gross) {
            $net = SalaryCalculator::fromGross($gross, $parameter)->whole()['net'];
            $recovered = SalaryCalculator::fromNet($net, $parameter)->whole()['gross'];

            $this->assertSame(
                $gross,
                $recovered,
                "Net {$net} should have recovered a gross of {$gross}, got {$recovered}."
            );
        }
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=SalaryCalculatorNetToGrossTest`
Expected: FAIL — `Call to undefined method ...::fromNet()`.

- [ ] **Step 3: Add the method**

Append to `SalaryCalculator`:

```php
    /**
     * Gross→net is monotone increasing, so a binary search converges. The
     * closed formula does not exist: the minimum base and the zero floor on
     * the tax base each put a kink in the curve.
     */
    public static function fromNet(float $net, PayrollParameter $p): SalaryBreakdown
    {
        $low = 0.0;
        // Contributions plus tax can never take more than half, so twice the
        // net plus a margin is always above the answer.
        $high = $net * 3 + 1000;

        for ($i = 0; $i < 200; $i++) {
            $mid = ($low + $high) / 2;

            if (self::fromGross($mid, $p)->net < $net) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        // Salaries are agreed in whole denars; recompute from the rounded gross
        // so every figure in the breakdown belongs to the same gross.
        return self::fromGross(round($high), $p);
    }
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `php artisan test --filter=SalaryCalculatorNetToGrossTest`
Expected: PASS, 4 tests.

If `test_the_round_trip_is_stable_across_a_range_of_salaries` fails by exactly one denar at some value, that is the rounding boundary, not a broken search: two adjacent gross values can round to the same whole net. Change the assertion for that case to `assertEqualsWithDelta($gross, $recovered, 1)` **and leave a comment naming the value**, rather than loosening the whole loop.

- [ ] **Step 5: Run the whole suite**

Run: `php artisan test`
Expected: PASS — the baseline is 841 tests plus everything added so far.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Support/Payroll/SalaryCalculator.php tests/Feature/Payroll/SalaryCalculatorNetToGrossTest.php
git commit -m "feat(payroll): net to gross by binary search over the gross to net curve"
```

---

### Task 6: Employee and salary-history tables

**Files:**
- Create: `database/migrations/2026_08_13_090200_create_employees_table.php`
- Create: `database/migrations/2026_08_13_090300_create_employee_salaries_table.php`
- Create: `app/Models/Employee.php`, `app/Models/EmployeeSalary.php`
- Create: `database/factories/EmployeeFactory.php`, `database/factories/EmployeeSalaryFactory.php`
- Test: `tests/Feature/EmployeeModelTest.php`

**Interfaces:**
- Consumes: `Company` (existing).
- Produces: `Employee` with `company()`, `salaries()`, `salaryOn(string $date): ?EmployeeSalary`, `isActiveOn(string $date): bool`, and the accessor `fullName`. `EmployeeSalary` has `effective_from` (date), `amount` (float), `basis` (`'gross'`|`'net'`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_salary_in_force_on_a_date(): void
    {
        $employee = Employee::factory()->create();

        EmployeeSalary::factory()->for($employee)->create([
            'effective_from' => '2026-01-01', 'amount' => 30000, 'basis' => 'net',
        ]);
        EmployeeSalary::factory()->for($employee)->create([
            'effective_from' => '2026-07-01', 'amount' => 35000, 'basis' => 'net',
        ]);

        $this->assertSame(30000.0, $employee->salaryOn('2026-06-30')?->amount);
        $this->assertSame(35000.0, $employee->salaryOn('2026-07-01')?->amount);
        $this->assertSame(35000.0, $employee->salaryOn('2026-12-31')?->amount);
    }

    public function test_it_returns_nothing_before_the_first_salary_record(): void
    {
        $employee = Employee::factory()->create();

        EmployeeSalary::factory()->for($employee)->create(['effective_from' => '2026-01-01']);

        $this->assertNull($employee->salaryOn('2025-12-31'));
    }

    public function test_the_same_embg_may_exist_at_two_different_companies(): void
    {
        // One person can be employed by two of the firm's clients. Those are
        // two separate cards, so the uniqueness is per company, not global.
        $first = Company::factory()->create();
        $second = Company::factory()->create();

        Employee::factory()->for($first)->create(['embg' => '3101980455019']);
        Employee::factory()->for($second)->create(['embg' => '3101980455019']);

        $this->assertSame(2, Employee::where('embg', '3101980455019')->count());
    }

    public function test_the_same_embg_may_not_be_entered_twice_at_one_company(): void
    {
        $company = Company::factory()->create();

        Employee::factory()->for($company)->create(['embg' => '3101980455019']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Employee::factory()->for($company)->create(['embg' => '3101980455019']);
    }

    public function test_an_employee_is_active_until_their_termination_date(): void
    {
        $employee = Employee::factory()->create([
            'employed_on' => '2026-02-01',
            'terminated_on' => '2026-08-31',
        ]);

        $this->assertFalse($employee->isActiveOn('2026-01-31'));
        $this->assertTrue($employee->isActiveOn('2026-02-01'));
        $this->assertTrue($employee->isActiveOn('2026-08-31'));
        $this->assertFalse($employee->isActiveOn('2026-09-01'));
    }

    public function test_an_employee_without_a_termination_date_stays_active(): void
    {
        $employee = Employee::factory()->create([
            'employed_on' => '2026-02-01',
            'terminated_on' => null,
        ]);

        $this->assertTrue($employee->isActiveOn('2030-01-01'));
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=EmployeeModelTest`
Expected: FAIL — `Class "App\Models\Employee" not found`.

- [ ] **Step 3: Write the employees migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('embg', 13);
            $table->string('first_name');
            $table->string('last_name');
            $table->string('municipality_code', 16)->nullable();   // SifraOpstina
            $table->string('bank_account', 34);                    // TransakciskaSmetka
            $table->string('insurance_type_code', 16);             // SifraRabotenOdnos
            $table->string('registration_number', 32)->nullable(); // BrojDogovor (М1/М2)
            $table->date('employed_on');                           // DatumPocetok
            $table->date('terminated_on')->nullable();             // DatumZavrsuvanje
            $table->string('movement_code', 16)->nullable();       // SifraDvizenje
            $table->string('exemption_code', 16)->nullable();      // SifraOsloboduvanje
            $table->unsignedSmallInteger('weekly_hours')->default(40);
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();

            // Explicit short name — the generated one would be long enough to
            // risk MySQL's 64-character identifier limit.
            $table->unique(['company_id', 'embg'], 'employees_company_embg_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
```

- [ ] **Step 4: Write the salaries migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->decimal('amount', 12, 2);
            // Only the agreed side is stored. Keeping both gross and net would
            // let them drift apart the moment a rate changes.
            $table->string('basis', 8);
            $table->timestamps();

            $table->index(['employee_id', 'effective_from'], 'employee_salaries_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_salaries');
    }
};
```

- [ ] **Step 5: Write the models**

`app/Models/Employee.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'embg', 'first_name', 'last_name', 'municipality_code',
        'bank_account', 'insurance_type_code', 'registration_number',
        'employed_on', 'terminated_on', 'movement_code', 'exemption_code',
        'weekly_hours', 'address', 'phone', 'email',
    ];

    protected function casts(): array
    {
        return [
            'employed_on' => 'date',
            'terminated_on' => 'date',
            'weekly_hours' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function salaries(): HasMany
    {
        return $this->hasMany(EmployeeSalary::class)->orderByDesc('effective_from');
    }

    /** The agreed salary in force on the given date, or null if none was yet agreed. */
    public function salaryOn(string $date): ?EmployeeSalary
    {
        return $this->salaries()
            ->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->first();
    }

    public function isActiveOn(string $date): bool
    {
        if ($this->employed_on->toDateString() > $date) {
            return false;
        }

        return $this->terminated_on === null || $this->terminated_on->toDateString() >= $date;
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
```

`app/Models/EmployeeSalary.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSalary extends Model
{
    use HasFactory;

    public const BASES = ['gross', 'net'];

    protected $fillable = ['employee_id', 'effective_from', 'amount', 'basis'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'amount' => 'float'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
```

- [ ] **Step 6: Write the factories**

`database/factories/EmployeeFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            // A real, check-digit-valid ЕМБГ, so factory-made records survive
            // the validation rule if a test ever routes them through it.
            'embg' => '3101980455019',
            'first_name' => 'Марко',
            'last_name' => 'Петровски',
            'municipality_code' => '175',
            'bank_account' => '300000000000000',
            'insurance_type_code' => '0050',
            'registration_number' => null,
            'employed_on' => '2026-01-01',
            'terminated_on' => null,
            'movement_code' => null,
            'exemption_code' => null,
            'weekly_hours' => 40,
            'address' => null,
            'phone' => null,
            'email' => null,
        ];
    }
}
```

`database/factories/EmployeeSalaryFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeSalaryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'effective_from' => '2026-01-01',
            'amount' => 30000,
            'basis' => 'net',
        ];
    }
}
```

Note: `test_the_same_embg_may_exist_at_two_different_companies` passes two different companies explicitly, so the fixed factory ЕМБГ is fine. Any test needing two employees at **one** company must set distinct `embg` values itself.

- [ ] **Step 7: Run the tests and make sure they pass**

Run: `php artisan test --filter=EmployeeModelTest`
Expected: PASS, 6 tests.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add database/migrations database/factories app/Models/Employee.php app/Models/EmployeeSalary.php tests/Feature/EmployeeModelTest.php
git commit -m "feat(payroll): employee records with dated salary history"
```

---

### Task 7: Policy, routes and menu entry

Makes «Вработени» a real menu item. The group then appears for clients automatically, carrying only that one entry, because `Menu::itemVisible()` already hides "наскоро" items from clients.

**Files:**
- Create: `app/Policies/EmployeePolicy.php`
- Modify: `app/Support/Menu.php`
- Modify: `routes/web.php`
- Modify: `tests/Unit/Support/MenuTest.php`
- Test: `tests/Feature/EmployeeAccessTest.php`

**Interfaces:**
- Consumes: `Employee` (Task 6).
- Produces: route names `employees.index`, `employees.create`, `employees.edit`; `EmployeePolicy` with `viewAny`, `view`, `create`, `update`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeAccessTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_a_client_sees_the_payroll_group_with_only_employees_in_it(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $groups = collect(Menu::for($client, $company));
        $payroll = $groups->firstWhere('key', 'payroll');

        $this->assertNotNull($payroll, 'A client should now see the ПЛАТИ И ЧР group.');
        $this->assertSame(['Вработени'], array_column($payroll['items'], 'label'));
    }

    public function test_an_admin_still_sees_the_two_unbuilt_entries(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $payroll = collect(Menu::for($admin, $company))->firstWhere('key', 'payroll');

        $this->assertSame(
            ['Вработени', 'Плата (МПИН)', 'е-ПДД'],
            array_column($payroll['items'], 'label')
        );
    }

    public function test_the_employees_menu_item_points_at_a_real_route(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $payroll = collect(Menu::for($admin, $company))->firstWhere('key', 'payroll');
        $employees = collect($payroll['items'])->firstWhere('label', 'Вработени');

        $this->assertFalse($employees['soon'], 'Вработени is built — it must no longer be a "наскоро" item.');
        $this->assertSame(route('employees.index', $company), $employees['url']);
    }

    public function test_every_role_may_open_the_employees_screen(): void
    {
        $company = Company::factory()->create();

        foreach (['admin', 'accountant'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->actingAs($user)->get(route('employees.index', $company))->assertOk();
        }

        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client)->get(route('employees.index', $company))->assertOk();
    }

    public function test_a_client_may_not_open_another_companys_employees(): void
    {
        $own = Company::factory()->create();
        $other = Company::factory()->create();

        $client = User::factory()->create(['company_id' => $own->id]);
        $client->assignRole('client');

        $this->actingAs($client)->get(route('employees.index', $other))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=EmployeeAccessTest`
Expected: FAIL — `Route [employees.index] not defined`.

- [ ] **Step 3: Write the policy**

```php
<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->visibleCompanies()->whereKey($employee->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'client']);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'client'])
            && $user->visibleCompanies()->whereKey($employee->company_id)->exists();
    }
}
```

- [ ] **Step 4: Update the menu**

In `app/Support/Menu.php`, delete the `'vraboteni'` entry from `SOON_FEATURES` entirely, and replace the payroll group's items with:

```php
            [
                'key' => 'payroll',
                'label' => 'ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ',
                'items' => [
                    ['label' => 'Вработени', 'url' => route('employees.index', $company), 'pattern' => 'employees.*', 'roles' => null],
                    self::soon($company, 'plata-mpin'),
                    self::soon($company, 'e-pdd'),
                ],
            ],
```

Leave `'plata-mpin'` and `'e-pdd'` in `SOON_FEATURES` untouched.

- [ ] **Step 5: Add the routes**

In `routes/web.php`, after the `partners.` group, add:

```php
Route::middleware(['auth'])->prefix('companies/{company}')->name('employees.')->group(function () {
    Route::get('/employees', [EmployeeIndex::class, '__invoke'])->name('index');
    Route::get('/employees/create', [EmployeeForm::class, '__invoke'])->name('create');
    Route::get('/employees/{employee}/edit', [EmployeeForm::class, '__invoke'])->name('edit');
});
```

Add `use App\Livewire\EmployeeIndex;` and `use App\Livewire\EmployeeForm;` to the imports.

**The array-callable form matters here**, matching the comment already in this file above the `accounting.` group: `EmployeeIndex` and `EmployeeForm` do not exist until Tasks 8 and 9, and a bare class-string would crash route registration immediately.

- [ ] **Step 6: Create placeholder components so routes resolve**

Create `app/Livewire/EmployeeIndex.php` and `app/Livewire/EmployeeForm.php` as minimal components; Tasks 8 and 9 fill them in.

```php
<?php

namespace App\Livewire;

use App\Models\Company;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class EmployeeIndex extends Component
{
    public Company $company;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        return view('livewire.employee-index');
    }
}
```

Create `resources/views/livewire/employee-index.blade.php` containing `<div>Вработени</div>`, and the equivalent pair for `EmployeeForm` / `employee-form.blade.php` with `<div>Картон на вработен</div>`.

- [ ] **Step 7: Update MenuTest**

Open `tests/Unit/Support/MenuTest.php` and find every assertion that treats `'vraboteni'` as a `SOON_FEATURES` key or expects the payroll group to be absent for clients. Update them to the new reality: the group now has one real item plus two "наскоро" ones, and clients see it. Do **not** change the assertions for `'plata-mpin'` and `'e-pdd'`.

- [ ] **Step 8: Run the tests and make sure they pass**

Run: `php artisan test --filter="EmployeeAccessTest|MenuTest"`
Expected: PASS.

If `test_every_role_may_open_the_employees_screen` fails for the **accountant** with a 403, the cause is `CompanyPolicy::view`, not anything in this task — the accountant in that test owns no company. Check how the existing accountant-facing screens resolve this (`PartnerIndex` uses the same `Gate::authorize('view', $company)` call) and give the test accountant whatever company association those screens assume. Do not weaken `CompanyPolicy`.

- [ ] **Step 9: Run the whole suite**

Run: `php artisan test`
Expected: PASS. Any failure here is most likely a sidebar or menu assertion in another test file that counted menu items or asserted the payroll group's absence — fix the **assertion**, never the feature.

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty
git add app/Policies/EmployeePolicy.php app/Support/Menu.php routes/web.php app/Livewire resources/views/livewire tests
git commit -m "feat(payroll): make Вработени a real menu entry with routes and a policy"
```

---

### Task 8: Employee list screen

**Files:**
- Modify: `app/Livewire/EmployeeIndex.php`
- Modify: `resources/views/livewire/employee-index.blade.php`
- Test: `tests/Feature/EmployeeIndexTest.php`

**Interfaces:**
- Consumes: `Employee`, `EmployeePolicy`, `WorkingYear` (existing).
- Produces: public property `showTerminated` (bool, default `false`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\EmployeeIndex;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
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

    public function test_it_lists_the_companys_employees(): void
    {
        $company = Company::factory()->create();
        Employee::factory()->for($company)->create(['first_name' => 'Ана', 'last_name' => 'Николовска']);
        $this->admin();

        Livewire::test(EmployeeIndex::class, ['company' => $company])
            ->assertSee('Ана')
            ->assertSee('Николовска');
    }

    public function test_it_does_not_list_another_companys_employees(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        Employee::factory()->for($other)->create(['first_name' => 'Туѓа', 'last_name' => 'Фирма']);
        $this->admin();

        Livewire::test(EmployeeIndex::class, ['company' => $company])
            ->assertDontSee('Туѓа');
    }

    public function test_terminated_employees_are_hidden_until_asked_for(): void
    {
        $company = Company::factory()->create();
        Employee::factory()->for($company)->create([
            'embg' => '3101980455019', 'first_name' => 'Стефан', 'last_name' => 'Стар',
            'employed_on' => '2020-01-01', 'terminated_on' => '2024-06-30',
        ]);
        $this->admin();

        Livewire::test(EmployeeIndex::class, ['company' => $company])
            ->assertDontSee('Стефан')
            ->set('showTerminated', true)
            ->assertSee('Стефан');
    }

    public function test_it_shows_the_salary_in_force_in_the_working_year(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();

        EmployeeSalary::factory()->for($employee)->create([
            'effective_from' => '2026-01-01', 'amount' => 30000, 'basis' => 'net',
        ]);

        $this->admin();

        Livewire::test(EmployeeIndex::class, ['company' => $company])
            ->assertSee('30.000');
    }

    public function test_a_salary_from_an_earlier_year_is_marked_as_such(): void
    {
        // The spec's rule: when the figure on screen is not today's, say so,
        // using the same grey pill already used for records outside the year.
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();

        EmployeeSalary::factory()->for($employee)->create([
            'effective_from' => '2024-05-01', 'amount' => 28000, 'basis' => 'net',
        ]);

        $this->admin();

        Livewire::test(EmployeeIndex::class, ['company' => $company])
            ->assertSee('Запис од 2024');
    }

    public function test_a_salary_agreed_in_the_working_year_carries_no_pill(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();

        EmployeeSalary::factory()->for($employee)->create([
            'effective_from' => now()->startOfYear()->toDateString(), 'amount' => 31000, 'basis' => 'net',
        ]);

        $this->admin();

        Livewire::test(EmployeeIndex::class, ['company' => $company])
            ->assertSee('31.000')
            ->assertDontSee('Запис од');
    }

    public function test_the_table_carries_the_shared_data_table_treatment(): void
    {
        $company = Company::factory()->create();
        Employee::factory()->for($company)->create();
        $this->admin();

        Livewire::test(EmployeeIndex::class, ['company' => $company])
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false)
            ->assertSee('py-1 px-3', false);
    }

    public function test_the_page_renders_over_http(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        $this->get(route('employees.index', $company))->assertOk();
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=EmployeeIndexTest`
Expected: FAIL — the placeholder view shows none of the expected content.

- [ ] **Step 3: Write the component**

```php
<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\Employee;
use App\Support\WorkingYear;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class EmployeeIndex extends Component
{
    public Company $company;

    public bool $showTerminated = false;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        $year = WorkingYear::for($this->company);
        $asOf = WorkingYear::defaultDate($year);

        $employees = Employee::where('company_id', $this->company->id)
            ->with('salaries')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->filter(fn (Employee $e) => $this->showTerminated || $e->isActiveOn($asOf))
            ->values();

        return view('livewire.employee-index', [
            'employees' => $employees,
            'asOf' => $asOf,
            'year' => $year,
        ]);
    }
}
```

- [ ] **Step 4: Write the view**

```blade
<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Вработени — {{ $company->name }}</h1>
        @can('create', \App\Models\Employee::class)
            <a href="{{ route('employees.create', $company) }}" class="text-brand hover:underline text-sm">Нов вработен</a>
        @endcan
    </div>

    <label class="flex items-center gap-2 mb-4 text-sm text-gray-600">
        <input type="checkbox" wire:model.live="showTerminated" class="rounded border-gray-300">
        Прикажи ги и исклучените
    </label>

    <x-card padding="p-0" class="overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500 bg-gray-50">
                <th class="py-1 px-3">Име и презиме</th>
                <th class="py-1 px-3">ЕМБГ</th>
                <th class="py-1 px-3">Вработен од</th>
                <th class="py-1 px-3">Плата</th>
                <th class="py-1 px-3">Статус</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($employees as $employee)
                @php $salary = $employee->salaryOn($asOf); @endphp
                <tr class="text-sm hover:bg-orange-50">
                    <td class="py-1 px-3">
                        <a href="{{ route('employees.edit', [$company, $employee]) }}" class="text-brand hover:underline font-medium">{{ $employee->full_name }}</a>
                    </td>
                    <td class="py-1 px-3">{{ $employee->embg }}</td>
                    <td class="py-1 px-3">{{ $employee->employed_on?->format('d.m.Y') }}</td>
                    <td class="py-1 px-3">
                        @if ($salary)
                            {{ number_format($salary->amount, 0, ',', '.') }}
                            <span class="text-gray-400">{{ $salary->basis === 'gross' ? 'бруто' : 'нето' }}</span>
                            @if ($salary->effective_from->year < $year)
                                <span class="ml-1 inline-flex items-center rounded-full bg-gray-100 px-2 text-xs text-gray-600">Запис од {{ $salary->effective_from->year }}</span>
                            @endif
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="py-1 px-3">
                        @if ($employee->isActiveOn($asOf))
                            <span class="text-gray-500">Активен</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 text-xs text-gray-600">Исклучен</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-4 px-3 text-gray-500">Нема внесени вработени.</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>
</div>
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `php artisan test --filter=EmployeeIndexTest`
Expected: PASS, 6 tests.

- [ ] **Step 6: Run the density test**

Run: `php artisan test --filter=TableDensityTest`
Expected: PASS. This screen is now part of the enforced set.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/Livewire/EmployeeIndex.php resources/views/livewire/employee-index.blade.php tests/Feature/EmployeeIndexTest.php
git commit -m "feat(payroll): employee list with working-year salary and terminated filter"
```

---

### Task 9: Employee card with the live calculator

**Files:**
- Modify: `app/Livewire/EmployeeForm.php`
- Modify: `resources/views/livewire/employee-form.blade.php`
- Test: `tests/Feature/EmployeeFormTest.php`

**Interfaces:**
- Consumes: `Employee`, `EmployeeSalary`, `PayrollParameter`, `PayrollCode`, `SalaryCalculator`, `ValidEmbg`.
- Produces: nothing consumed by later tasks in this plan.

The two salary fields are two-way: typing in one recomputes the other. Only the field the user typed into is persisted, together with `basis`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\EmployeeForm;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeFormTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    private function actAsAdmin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
    }

    public function test_typing_a_gross_amount_fills_in_the_net(): void
    {
        $company = Company::factory()->create();
        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company])
            ->set('salaryEffectiveFrom', '2026-07-01')
            ->set('gross', '38507')
            ->assertSet('net', '26046');
    }

    public function test_typing_a_net_amount_fills_in_the_gross(): void
    {
        $company = Company::factory()->create();
        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company])
            ->set('salaryEffectiveFrom', '2026-07-01')
            ->set('net', '26046')
            ->assertSet('gross', '38507');
    }

    public function test_it_stores_only_the_side_that_was_typed(): void
    {
        $company = Company::factory()->create();
        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company])
            ->set('embg', '3101980455019')
            ->set('firstName', 'Ана')
            ->set('lastName', 'Николовска')
            ->set('bankAccount', '300000000000000')
            ->set('insuranceTypeCode', '0050')
            ->set('employedOn', '2026-07-01')
            ->set('salaryEffectiveFrom', '2026-07-01')
            ->set('net', '26046')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('employee_salaries', [
            'amount' => 26046,
            'basis' => 'net',
        ]);

        $this->assertDatabaseMissing('employee_salaries', ['basis' => 'gross']);
    }

    public function test_it_rejects_an_invalid_embg(): void
    {
        $company = Company::factory()->create();
        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company])
            ->set('embg', '3101980455018')
            ->set('firstName', 'Ана')
            ->set('lastName', 'Николовска')
            ->set('bankAccount', '300000000000000')
            ->set('insuranceTypeCode', '0050')
            ->set('employedOn', '2026-07-01')
            ->call('save')
            ->assertHasErrors(['embg']);
    }

    public function test_it_refuses_a_duplicate_embg_within_the_same_company(): void
    {
        $company = Company::factory()->create();
        Employee::factory()->for($company)->create(['embg' => '3101980455019']);
        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company])
            ->set('embg', '3101980455019')
            ->set('firstName', 'Ана')
            ->set('lastName', 'Николовска')
            ->set('bankAccount', '300000000000000')
            ->set('insuranceTypeCode', '0050')
            ->set('employedOn', '2026-07-01')
            ->call('save')
            ->assertHasErrors(['embg']);
    }

    public function test_editing_shows_the_existing_salary_history(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();

        EmployeeSalary::factory()->for($employee)->create([
            'effective_from' => '2026-01-01', 'amount' => 30000, 'basis' => 'net',
        ]);
        EmployeeSalary::factory()->for($employee)->create([
            'effective_from' => '2026-07-01', 'amount' => 35000, 'basis' => 'net',
        ]);

        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company, 'employee' => $employee])
            ->assertSee('30.000')
            ->assertSee('35.000');
    }

    public function test_a_client_may_edit_their_own_companys_employee(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();

        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(EmployeeForm::class, ['company' => $company, 'employee' => $employee])
            ->set('firstName', 'Изменето')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'first_name' => 'Изменето']);
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=EmployeeFormTest`
Expected: FAIL — the placeholder component has none of these properties.

- [ ] **Step 3: Write the component**

```php
<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollCode;
use App\Models\PayrollParameter;
use App\Rules\ValidEmbg;
use App\Support\Payroll\SalaryCalculator;
use App\Support\WorkingYear;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class EmployeeForm extends Component
{
    public Company $company;

    public ?Employee $employee = null;

    public string $embg = '';

    public string $firstName = '';

    public string $lastName = '';

    public string $municipalityCode = '';

    public string $bankAccount = '';

    public string $insuranceTypeCode = '0050';

    public string $registrationNumber = '';

    public string $employedOn = '';

    public string $terminatedOn = '';

    public string $movementCode = '';

    public string $exemptionCode = '';

    public int $weeklyHours = 40;

    public string $address = '';

    public string $phone = '';

    public string $email = '';

    public string $salaryEffectiveFrom = '';

    public string $gross = '';

    public string $net = '';

    /** Which of the two salary fields the user actually typed into. */
    public string $basisTyped = 'gross';

    /** Guards the two-way salary fields against recomputing each other forever. */
    private bool $syncing = false;

    public function mount(Company $company, ?Employee $employee = null): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;

        $year = WorkingYear::for($company);
        $this->employedOn = WorkingYear::defaultDate($year);
        $this->salaryEffectiveFrom = WorkingYear::defaultDate($year);

        if ($employee === null) {
            Gate::authorize('create', Employee::class);

            return;
        }

        Gate::authorize('update', $employee);

        $this->employee = $employee;
        $this->embg = $employee->embg;
        $this->firstName = $employee->first_name;
        $this->lastName = $employee->last_name;
        $this->municipalityCode = (string) $employee->municipality_code;
        $this->bankAccount = $employee->bank_account;
        $this->insuranceTypeCode = $employee->insurance_type_code;
        $this->registrationNumber = (string) $employee->registration_number;
        $this->employedOn = $employee->employed_on->toDateString();
        $this->terminatedOn = $employee->terminated_on?->toDateString() ?? '';
        $this->movementCode = (string) $employee->movement_code;
        $this->exemptionCode = (string) $employee->exemption_code;
        $this->weeklyHours = $employee->weekly_hours;
        $this->address = (string) $employee->address;
        $this->phone = (string) $employee->phone;
        $this->email = (string) $employee->email;
    }

    public function updatedGross(string $value): void
    {
        $this->recompute($value, from: 'gross');
    }

    public function updatedNet(string $value): void
    {
        $this->recompute($value, from: 'net');
    }

    private function recompute(string $value, string $from): void
    {
        if ($this->syncing) {
            return;
        }

        $amount = (float) str_replace([' ', '.'], '', $value);

        if ($amount <= 0) {
            return;
        }

        $parameter = PayrollParameter::forDate($this->salaryEffectiveFrom ?: now()->toDateString());

        $breakdown = $from === 'gross'
            ? SalaryCalculator::fromGross($amount, $parameter)
            : SalaryCalculator::fromNet($amount, $parameter);

        $this->syncing = true;

        if ($from === 'gross') {
            $this->net = (string) $breakdown->whole()['net'];
        } else {
            $this->gross = (string) $breakdown->whole()['gross'];
        }

        $this->syncing = false;

        $this->basisTyped = $from;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'embg' => ['required', 'string', new ValidEmbg],
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'municipalityCode' => 'nullable|string|max:16',
            'bankAccount' => 'required|string|max:34',
            'insuranceTypeCode' => 'required|string|max:16',
            'registrationNumber' => 'nullable|string|max:32',
            'employedOn' => 'required|date',
            'terminatedOn' => 'nullable|date|after_or_equal:employedOn',
            'movementCode' => 'nullable|string|max:16',
            'exemptionCode' => 'nullable|string|max:16',
            'weeklyHours' => 'required|integer|min:1|max:40',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'salaryEffectiveFrom' => 'nullable|date',
        ]);

        $duplicate = Employee::where('company_id', $this->company->id)
            ->where('embg', $validated['embg'])
            ->when($this->employee !== null, fn ($q) => $q->whereKeyNot($this->employee->id))
            ->exists();

        if ($duplicate) {
            $this->addError('embg', 'Веќе постои вработен со овој ЕМБГ во оваа фирма.');

            return;
        }

        $attributes = [
            'company_id' => $this->company->id,
            'embg' => $validated['embg'],
            'first_name' => $validated['firstName'],
            'last_name' => $validated['lastName'],
            'municipality_code' => $validated['municipalityCode'] ?: null,
            'bank_account' => $validated['bankAccount'],
            'insurance_type_code' => $validated['insuranceTypeCode'],
            'registration_number' => $validated['registrationNumber'] ?: null,
            'employed_on' => $validated['employedOn'],
            'terminated_on' => $validated['terminatedOn'] ?: null,
            'movement_code' => $validated['movementCode'] ?: null,
            'exemption_code' => $validated['exemptionCode'] ?: null,
            'weekly_hours' => $validated['weeklyHours'],
            'address' => $validated['address'] ?: null,
            'phone' => $validated['phone'] ?: null,
            'email' => $validated['email'] ?: null,
        ];

        if ($this->employee === null) {
            $this->employee = Employee::create($attributes);
        } else {
            $this->employee->update($attributes);
        }

        // Only the side the user typed is stored. Persisting both would let
        // them drift apart the moment a rate changes.
        $typed = $this->basisTyped === 'gross' ? $this->gross : $this->net;
        $amount = (float) str_replace([' ', '.'], '', $typed);

        if ($amount > 0 && $this->salaryEffectiveFrom !== '') {
            EmployeeSalary::updateOrCreate(
                ['employee_id' => $this->employee->id, 'effective_from' => $this->salaryEffectiveFrom],
                ['amount' => $amount, 'basis' => $this->basisTyped],
            );
        }

        $this->redirectRoute('employees.index', $this->company, navigate: true);
    }

    public function render()
    {
        return view('livewire.employee-form', [
            'municipalities' => PayrollCode::ofType('opstina'),
            'insuranceTypes' => PayrollCode::ofType('vid_staz'),
            'movements' => PayrollCode::ofType('sifra_dviz'),
            'exemptions' => PayrollCode::ofType('osloboduvanje'),
            'history' => $this->employee?->salaries ?? collect(),
        ]);
    }
}
```

Note the `$syncing` guard: without it, `updatedGross()` writes `net`, which fires `updatedNet()`, which writes `gross`, and the two fields oscillate.

- [ ] **Step 4: Write the view**

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">
        {{ $employee ? 'Картон на вработен' : 'Нов вработен' }} — {{ $company->name }}
    </h1>

    <form wire:submit="save">
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-3">Лични податоци</h2>
            <div class="grid gap-3 md:grid-cols-3">
                <div>
                    <x-input-label for="embg" value="ЕМБГ" />
                    <x-text-input id="embg" wire:model="embg" class="w-full" />
                    @error('embg') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="firstName" value="Име" />
                    <x-text-input id="firstName" wire:model="firstName" class="w-full" />
                    @error('firstName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="lastName" value="Презиме" />
                    <x-text-input id="lastName" wire:model="lastName" class="w-full" />
                    @error('lastName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="municipalityCode" value="Општина" />
                    <select id="municipalityCode" wire:model="municipalityCode" class="border-gray-300 rounded-md text-sm w-full">
                        <option value="">—</option>
                        @foreach ($municipalities as $code)
                            <option value="{{ $code->code }}">{{ $code->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="bankAccount" value="Трансакциска сметка" />
                    <x-text-input id="bankAccount" wire:model="bankAccount" class="w-full" />
                    @error('bankAccount') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="address" value="Адреса" />
                    <x-text-input id="address" wire:model="address" class="w-full" />
                </div>
                <div>
                    <x-input-label for="phone" value="Телефон" />
                    <x-text-input id="phone" wire:model="phone" class="w-full" />
                </div>
                <div>
                    <x-input-label for="email" value="Е-пошта" />
                    <x-text-input id="email" wire:model="email" class="w-full" />
                    @error('email') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
            </div>
        </x-card>

        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-3">Работен однос</h2>
            <div class="grid gap-3 md:grid-cols-3">
                <div>
                    <x-input-label for="insuranceTypeCode" value="Вид на стаж" />
                    <select id="insuranceTypeCode" wire:model="insuranceTypeCode" class="border-gray-300 rounded-md text-sm w-full">
                        @foreach ($insuranceTypes as $code)
                            <option value="{{ $code->code }}">{{ $code->code }} — {{ $code->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="registrationNumber" value="Број на пријава (М1/М2)" />
                    <x-text-input id="registrationNumber" wire:model="registrationNumber" class="w-full" />
                </div>
                <div>
                    <x-input-label for="weeklyHours" value="Часови неделно" />
                    <x-text-input id="weeklyHours" type="number" wire:model="weeklyHours" class="w-full" />
                    @error('weeklyHours') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="employedOn" value="Вработен од" />
                    <x-text-input id="employedOn" type="date" wire:model="employedOn" class="w-full" />
                    @error('employedOn') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="terminatedOn" value="Престанок" />
                    <x-text-input id="terminatedOn" type="date" wire:model="terminatedOn" class="w-full" />
                    @error('terminatedOn') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="movementCode" value="Шифра на движење" />
                    <select id="movementCode" wire:model="movementCode" class="border-gray-300 rounded-md text-sm w-full">
                        <option value="">—</option>
                        @foreach ($movements as $code)
                            <option value="{{ $code->code }}">{{ $code->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="exemptionCode" value="Даночно намалување" />
                    <select id="exemptionCode" wire:model="exemptionCode" class="border-gray-300 rounded-md text-sm w-full">
                        <option value="">—</option>
                        @foreach ($exemptions as $code)
                            <option value="{{ $code->code }}">{{ $code->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-card>

        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-1">Договорена плата</h2>
            <p class="text-sm text-gray-500 mb-3">Внесете во едното поле — другото се пресметува автоматски.</p>
            <div class="grid gap-3 md:grid-cols-3">
                <div>
                    <x-input-label for="salaryEffectiveFrom" value="Важи од" />
                    <x-text-input id="salaryEffectiveFrom" type="date" wire:model.live="salaryEffectiveFrom" class="w-full" />
                </div>
                <div>
                    <x-input-label for="gross" value="Бруто" />
                    <x-text-input id="gross" wire:model.live.debounce.500ms="gross" class="w-full" />
                </div>
                <div>
                    <x-input-label for="net" value="Нето" />
                    <x-text-input id="net" wire:model.live.debounce.500ms="net" class="w-full" />
                </div>
            </div>

            @if ($history->isNotEmpty())
                <h3 class="font-semibold text-gray-700 mt-5 mb-2 text-sm">Историја на платата</h3>
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr class="text-left text-sm text-gray-500 bg-gray-50">
                            <th class="py-1 px-3">Важи од</th>
                            <th class="py-1 px-3">Износ</th>
                            <th class="py-1 px-3">Договорено како</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($history as $record)
                            <tr class="text-sm hover:bg-orange-50">
                                <td class="py-1 px-3">{{ $record->effective_from->format('d.m.Y') }}</td>
                                <td class="py-1 px-3">{{ number_format($record->amount, 0, ',', '.') }}</td>
                                <td class="py-1 px-3">{{ $record->basis === 'gross' ? 'бруто' : 'нето' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-card>

        <div class="flex gap-3">
            <x-primary-button type="submit">Зачувај</x-primary-button>
            <a href="{{ route('employees.index', $company) }}" class="text-gray-600 hover:underline text-sm self-center">Откажи</a>
        </div>
    </form>
</div>
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `php artisan test --filter=EmployeeFormTest`
Expected: PASS, 7 tests.

- [ ] **Step 6: Run the density test**

Run: `php artisan test --filter=TableDensityTest`
Expected: PASS — the salary-history table carries the shared treatment, so this file joins the enforced set.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/Livewire/EmployeeForm.php resources/views/livewire/employee-form.blade.php tests/Feature/EmployeeFormTest.php
git commit -m "feat(payroll): employee card with a live two-way gross/net calculator"
```

---

### Task 10: Admin-only parameters screen

The state rates are shared by every company, so a wrong value here corrupts every client's calculation at once. Edit is admin-only, enforced server-side.

**Files:**
- Create: `app/Livewire/PayrollParameterIndex.php`
- Create: `resources/views/livewire/payroll-parameter-index.blade.php`
- Modify: `routes/web.php`, `app/Support/Menu.php`
- Test: `tests/Feature/PayrollParameterIndexTest.php`

**Interfaces:**
- Consumes: `PayrollParameter` (Task 2).
- Produces: route `payroll-parameters.index`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\PayrollParameterIndex;
use App\Models\Company;
use App\Models\PayrollParameter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayrollParameterIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_an_admin_may_open_it(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('payroll-parameters.index', $company))
            ->assertOk()
            ->assertSee('19,9');
    }

    public function test_an_accountant_may_not_open_it(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');

        $this->actingAs($accountant)
            ->get(route('payroll-parameters.index', $company))
            ->assertForbidden();
    }

    public function test_a_client_may_not_open_it(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client)
            ->get(route('payroll-parameters.index', $company))
            ->assertForbidden();
    }

    public function test_an_admin_can_add_a_new_period(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PayrollParameterIndex::class, ['company' => $company])
            ->set('effectiveFrom', '2027-01-01')
            ->set('ratePension', '20.5')
            ->set('rateHealth', '7.5')
            ->set('rateInjury', '0.5')
            ->set('rateUnemployment', '0.1')
            ->set('rateTax', '10')
            ->set('personalAllowance', '11500')
            ->set('averageSalary', '72000')
            ->set('minBase', '36000')
            ->set('maxBase', '1152000')
            ->set('minimumWage', '40000')
            ->call('addPeriod')
            ->assertHasNoErrors();

        $this->assertSame(20.5, PayrollParameter::forDate('2027-06-01')->rate_pension);
    }

    public function test_the_parameters_menu_entry_is_admin_only(): void
    {
        $company = Company::factory()->create();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');

        $labels = fn (User $u) => collect(\App\Support\Menu::for($u, $company))
            ->firstWhere('key', 'settings')['items'] ?? [];

        $this->assertContains('Параметри за плата', array_column($labels($admin), 'label'));
        $this->assertNotContains('Параметри за плата', array_column($labels($accountant), 'label'));
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=PayrollParameterIndexTest`
Expected: FAIL — `Route [payroll-parameters.index] not defined`.

- [ ] **Step 3: Write the component**

```php
<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\PayrollParameter;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PayrollParameterIndex extends Component
{
    public Company $company;

    public string $effectiveFrom = '';

    public string $ratePension = '';

    public string $rateHealth = '7.5';

    public string $rateInjury = '0.5';

    public string $rateUnemployment = '';

    public string $rateTax = '10';

    public string $personalAllowance = '';

    public string $averageSalary = '';

    public string $minBase = '';

    public string $maxBase = '';

    public string $minimumWage = '';

    public function mount(Company $company): void
    {
        // Shared state parameters: a wrong value here breaks every company's
        // calculation, so this is admin-only and enforced on the server.
        abort_unless(auth()->user()->hasRole('admin'), 403);

        Gate::authorize('view', $company);

        $this->company = $company;
    }

    public function addPeriod(): void
    {
        abort_unless(auth()->user()->hasRole('admin'), 403);

        $validated = $this->validate([
            'effectiveFrom' => 'required|date|unique:payroll_parameters,effective_from',
            'ratePension' => 'required|numeric|min:0|max:100',
            'rateHealth' => 'required|numeric|min:0|max:100',
            'rateInjury' => 'required|numeric|min:0|max:100',
            'rateUnemployment' => 'required|numeric|min:0|max:100',
            'rateTax' => 'required|numeric|min:0|max:100',
            'personalAllowance' => 'required|numeric|min:0',
            'averageSalary' => 'required|numeric|min:0',
            'minBase' => 'required|numeric|min:0',
            'maxBase' => 'required|numeric|min:0',
            'minimumWage' => 'required|numeric|min:0',
        ]);

        PayrollParameter::create([
            'effective_from' => $validated['effectiveFrom'],
            'rate_pension' => $validated['ratePension'],
            'rate_health' => $validated['rateHealth'],
            'rate_injury' => $validated['rateInjury'],
            'rate_unemployment' => $validated['rateUnemployment'],
            'rate_tax' => $validated['rateTax'],
            'personal_allowance' => $validated['personalAllowance'],
            'average_salary' => $validated['averageSalary'],
            'min_base' => $validated['minBase'],
            'max_base' => $validated['maxBase'],
            'minimum_wage' => $validated['minimumWage'],
        ]);

        $this->reset([
            'effectiveFrom', 'ratePension', 'rateUnemployment', 'personalAllowance',
            'averageSalary', 'minBase', 'maxBase', 'minimumWage',
        ]);
    }

    public function render()
    {
        return view('livewire.payroll-parameter-index', [
            'parameters' => PayrollParameter::orderByDesc('effective_from')->get(),
        ]);
    }
}
```

- [ ] **Step 4: Write the view**

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">Параметри за пресметка на плата</h1>
    <p class="text-sm text-gray-500 mb-4">
        Стапките и основиците важат за сите фирми. Секоја промена се внесува како нов период —
        постојните периоди остануваат, за да може стара пресметка да се повтори точно.
    </p>

    <x-card class="mb-6">
        <h2 class="font-semibold text-gray-700 mb-3">Нов период</h2>
        <form wire:submit="addPeriod" class="grid gap-3 md:grid-cols-4">
            <div>
                <x-input-label for="effectiveFrom" value="Важи од" />
                <x-text-input id="effectiveFrom" type="date" wire:model="effectiveFrom" class="w-full" />
                @error('effectiveFrom') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="ratePension" value="ПИО %" />
                <x-text-input id="ratePension" wire:model="ratePension" class="w-full" />
                @error('ratePension') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="rateHealth" value="Здравствено %" />
                <x-text-input id="rateHealth" wire:model="rateHealth" class="w-full" />
            </div>
            <div>
                <x-input-label for="rateInjury" value="Повреда %" />
                <x-text-input id="rateInjury" wire:model="rateInjury" class="w-full" />
            </div>
            <div>
                <x-input-label for="rateUnemployment" value="Невработеност %" />
                <x-text-input id="rateUnemployment" wire:model="rateUnemployment" class="w-full" />
                @error('rateUnemployment') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="rateTax" value="ПДД %" />
                <x-text-input id="rateTax" wire:model="rateTax" class="w-full" />
            </div>
            <div>
                <x-input-label for="personalAllowance" value="Лично ослободување" />
                <x-text-input id="personalAllowance" wire:model="personalAllowance" class="w-full" />
                @error('personalAllowance') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="averageSalary" value="Просечна плата" />
                <x-text-input id="averageSalary" wire:model="averageSalary" class="w-full" />
            </div>
            <div>
                <x-input-label for="minBase" value="Најниска основица" />
                <x-text-input id="minBase" wire:model="minBase" class="w-full" />
            </div>
            <div>
                <x-input-label for="maxBase" value="Највисока основица" />
                <x-text-input id="maxBase" wire:model="maxBase" class="w-full" />
            </div>
            <div>
                <x-input-label for="minimumWage" value="Минимална плата" />
                <x-text-input id="minimumWage" wire:model="minimumWage" class="w-full" />
            </div>
            <div class="self-end">
                <x-primary-button type="submit">Додади</x-primary-button>
            </div>
        </form>
    </x-card>

    <x-card padding="p-0" class="overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500 bg-gray-50">
                <th class="py-1 px-3">Важи од</th>
                <th class="py-1 px-3">ПИО</th>
                <th class="py-1 px-3">Здравствено</th>
                <th class="py-1 px-3">Повреда</th>
                <th class="py-1 px-3">Невработеност</th>
                <th class="py-1 px-3">ПДД</th>
                <th class="py-1 px-3">Лично ослоб.</th>
                <th class="py-1 px-3">Најниска</th>
                <th class="py-1 px-3">Највисока</th>
                <th class="py-1 px-3">Мин. плата</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach ($parameters as $p)
                <tr class="text-sm hover:bg-orange-50">
                    <td class="py-1 px-3">{{ $p->effective_from->format('d.m.Y') }}</td>
                    <td class="py-1 px-3">{{ number_format($p->rate_pension, 1, ',', '.') }}</td>
                    <td class="py-1 px-3">{{ number_format($p->rate_health, 1, ',', '.') }}</td>
                    <td class="py-1 px-3">{{ number_format($p->rate_injury, 1, ',', '.') }}</td>
                    <td class="py-1 px-3">{{ number_format($p->rate_unemployment, 1, ',', '.') }}</td>
                    <td class="py-1 px-3">{{ number_format($p->rate_tax, 1, ',', '.') }}</td>
                    <td class="py-1 px-3">{{ number_format($p->personal_allowance, 0, ',', '.') }}</td>
                    <td class="py-1 px-3">{{ number_format($p->min_base, 0, ',', '.') }}</td>
                    <td class="py-1 px-3">{{ number_format($p->max_base, 0, ',', '.') }}</td>
                    <td class="py-1 px-3">{{ number_format($p->minimum_wage, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </x-card>
</div>
```

- [ ] **Step 5: Add the route and menu entry**

In `routes/web.php`, inside the existing `employees.` prefix block's neighbourhood, add a separate group:

```php
Route::middleware(['auth'])->prefix('companies/{company}')->name('payroll-parameters.')->group(function () {
    Route::get('/payroll-parameters', [PayrollParameterIndex::class, '__invoke'])->name('index');
});
```

with `use App\Livewire\PayrollParameterIndex;` added to the imports.

In `app/Support/Menu.php`, append to the `settings` group's items:

```php
                    ['label' => 'Параметри за плата', 'url' => route('payroll-parameters.index', $company), 'pattern' => 'payroll-parameters.*', 'roles' => ['admin']],
```

- [ ] **Step 6: Run the tests and make sure they pass**

Run: `php artisan test --filter=PayrollParameterIndexTest`
Expected: PASS, 5 tests.

- [ ] **Step 7: Run the whole suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add app/Livewire/PayrollParameterIndex.php resources/views/livewire/payroll-parameter-index.blade.php app/Support/Menu.php routes/web.php tests/Feature/PayrollParameterIndexTest.php
git commit -m "feat(payroll): admin-only screen for the state payroll parameters"
```

---

## After the plan

**Do not push.** The user pushes; `main` deploys to production.

**Deployment note for the user when they do:** this plan adds four migrations, so the droplet needs `php artisan migrate` after `git pull`. The codebook and parameter seeding happens inside the migrations, so there is no separate seeder step.

**MySQL watch:** migrations are the known CI-only failure class in this project. If CI fails where local SQLite passed, the likely causes in this plan are the two explicit index names (`employees_company_embg_unique`, `employee_salaries_lookup_index`) and the `decimal` columns being compared against PHP floats in `PayrollParameterTest`. Fix the migration or the cast, not the test's intent.
