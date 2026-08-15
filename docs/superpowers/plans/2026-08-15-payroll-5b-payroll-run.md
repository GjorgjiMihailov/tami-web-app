# Payroll 5b — Monthly Payroll Run Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the monthly payroll run — hours and allowances per employee, contributions and tax via the existing 5a calculator, deductions, two PDFs, and an automatic journal entry on confirmation.

**Architecture:** Three tables mirror the three levels of `mpin.xsd` — `payroll_runs` (one company-month), `payroll_run_employees` (one employee), `payroll_run_lines` (one `SifraTipRabotenCas` line). A pure calculator turns lines into money; a service opens, recalculates, confirms and reverses runs. Nothing recalculates a confirmed run: parameters and the hour fund are frozen onto the run when it opens.

**Tech Stack:** Laravel 12, Livewire 3, Tailwind, dompdf (`barryvdh/laravel-dompdf`), PHPUnit, SQLite locally / MySQL in CI.

**Spec:** `docs/superpowers/specs/2026-08-15-payroll-5b-payroll-run-design.md`. Read it before Task 5 — the calculator is the part where the spec's reasoning matters most.

## Global Constraints

- **Two decimals through the whole chain.** Rounding to a whole denar is МПИН's *write* rule, never a step in the calculation. `SalaryBreakdown::whole()` exists for display. Never feed a rounded figure into the next step.
- **Hours are whole numbers.** `BrojCasovi` is `xs:int` in `mpin.xsd`. Validation must reject `7.5`.
- **All user-facing text is Macedonian.** Column names, class names and comments are English, matching what 5a actually built.
- **Data tables use `py-1 px-3` cells and `hover:bg-orange-50` rows.** `TableDensityTest` scans Blade sources and fails if a new table disagrees with the other 15.
- **dompdf does not support flexbox.** PDF layouts use tables.
- **MySQL index names must be explicit and short** where the generated name would approach 64 characters. Local SQLite does not catch this; CI does.
- **Livewire components must not read `request()` in `render()`.** State is captured in `mount()` as public properties.
- **After all Blade changes land, run `npm run build`** — Tailwind JIT only emits classes it has seen.
- Money is `float` inside payroll (5a's choice, so `SalaryCalculator` stays usable) and is written to journal lines as `number_format($value, 2, '.', '')`.

## File Structure

**Codebooks and parameters**
- `database/data/payroll-codes/rab_cas.json`, `vid_nadomestoci.json` — committed conversions of the УЈП `.xls` files
- `database/migrations/2026_08_15_100000_seed_rab_cas_and_nadomestoci_payroll_codes.php`
- `database/migrations/2026_08_15_100100_create_payroll_month_hours_table.php`
- `app/Models/PayrollMonthHours.php` — lookup by year and month

**Employee change**
- `database/migrations/2026_08_15_100200_add_prior_service_months_to_employees_table.php`
- `app/Models/Employee.php` — add `seniorityYearsOn()`

**The run**
- `database/migrations/2026_08_15_100300_create_payroll_runs_table.php`
- `database/migrations/2026_08_15_100400_create_payroll_run_employees_table.php`
- `database/migrations/2026_08_15_100500_create_payroll_run_lines_table.php`
- `app/Models/PayrollRun.php`, `PayrollRunEmployee.php`, `PayrollRunLine.php`

**Calculation — pure, no database**
- `app/Support/Payroll/LineType.php` — the offered codes, their default percents, and who bears them
- `app/Support/Payroll/PayrollRunLineResult.php`
- `app/Support/Payroll/PayrollRunResult.php`
- `app/Support/Payroll/PayrollRunCalculator.php`

**Orchestration — database and ledger**
- `app/Services/Payroll/PayrollRunService.php`

**Screens**
- `app/Livewire/Payroll/PayrollRunIndex.php` + `resources/views/livewire/payroll/payroll-run-index.blade.php`
- `app/Livewire/Payroll/PayrollRunShow.php` + `resources/views/livewire/payroll/payroll-run-show.blade.php`
- `app/Livewire/PayrollParameterIndex.php` — gains an hour-fund section
- `app/Support/Menu.php`, `routes/web.php`

**PDFs**
- `app/Http/Controllers/PayslipPdfController.php` + `resources/views/pdf/payslip.blade.php`
- `app/Http/Controllers/PayrollRecapPdfController.php` + `resources/views/pdf/payroll-recap.blade.php`

The calculator is deliberately separate from the service: it is the part with the delicate arithmetic, and it must be testable without touching a database, a company, or a logged-in user.

---

### Task 1: The two remaining УЈП codebooks

`payroll_codes` already holds `opstina`, `vid_staz`, `sifra_dviz` and `osloboduvanje` from 5a. 5b adds the two that feed the line editor. Both `.xls` sources live in `ujp_mpin_xml/`, which is **not** in git, so they are converted once to committed JSON exactly as 5a did.

**Files:**
- Create: `database/data/payroll-codes/rab_cas.json`, `database/data/payroll-codes/vid_nadomestoci.json`
- Create: `database/migrations/2026_08_15_100000_seed_rab_cas_and_nadomestoci_payroll_codes.php`
- Modify: `app/Models/PayrollCode.php` (the `TYPES` constant)
- Test: `tests/Feature/PayrollCodeTest.php`

**Interfaces:**
- Consumes: `PayrollCode::ofType(string $type): Collection` (from 5a)
- Produces: `PayrollCode::TYPES` gains `'rab_cas'` and `'vid_nadomestoci'`; both types are seeded and queryable by `ofType()`.

- [ ] **Step 1: Convert the two .xls files to JSON**

Write this throwaway script to `storage/app/private/convert-5b-codes.php`, run it, then delete it. It uses PhpSpreadsheet, already present via `maatwebsite/excel`.

```php
<?php

require __DIR__.'/../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$root = dirname(__DIR__, 3);

$map = [
    'rab_cas.xls' => 'rab_cas',
    'VID_NADOMESTOCI.xls' => 'vid_nadomestoci',
];

foreach ($map as $file => $type) {
    $rows = IOFactory::load($root.'/ujp_mpin_xml/'.$file)->getActiveSheet()->toArray(null, true, false, false);

    $codes = [];

    foreach ($rows as $i => $row) {
        if ($i === 0) {
            continue; // header: Kod | Opis  /  MTR_CODE | MTR_DESC
        }

        $code = trim((string) ($row[0] ?? ''));
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($row[1] ?? '')));

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

Run: `php storage/app/private/convert-5b-codes.php`

Expected output, exactly:
```
rab_cas: 60 codes
vid_nadomestoci: 17 codes
```

Then delete the script: `rm storage/app/private/convert-5b-codes.php`

Spot-check that `rab_cas.json` contains `{"code": "001", "name": "Редовни работни часови"}` and that `vid_nadomestoci.json` contains code `129`. **Do not hand-edit these files** — codes such as `001` and `009` are zero-padded strings and must stay strings. The `preg_replace` collapses the runs of spaces that code 129's description contains in the source.

- [ ] **Step 2: Write the failing test**

Add to `tests/Feature/PayrollCodeTest.php`:

```php
public function test_it_seeds_the_working_hour_types(): void
{
    $codes = PayrollCode::ofType('rab_cas');

    $this->assertCount(60, $codes);
    $this->assertSame('Редовни работни часови', $codes->firstWhere('code', '001')->name);
    $this->assertSame('Годишен одмор', $codes->firstWhere('code', '009')->name);
}

public function test_it_seeds_the_compensation_types(): void
{
    $codes = PayrollCode::ofType('vid_nadomestoci');

    $this->assertCount(17, $codes);
    $this->assertStringContainsString('ФЗО', $codes->firstWhere('code', '129')->name);
}

public function test_the_two_codebooks_share_no_code(): void
{
    // The line editor puts both codebooks into one SifraTipRabotenCas
    // dropdown. That is only sound while they do not collide.
    $hours = PayrollCode::ofType('rab_cas')->pluck('code');
    $compensations = PayrollCode::ofType('vid_nadomestoci')->pluck('code');

    $this->assertEmpty($hours->intersect($compensations));
}
```

- [ ] **Step 3: Run it to make sure it fails**

Run: `php artisan test --filter=PayrollCodeTest`
Expected: FAIL — the new types return empty collections.

- [ ] **Step 4: Write the migration**

`database/migrations/2026_08_15_100000_seed_rab_cas_and_nadomestoci_payroll_codes.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TYPES = ['rab_cas', 'vid_nadomestoci'];

    public function up(): void
    {
        foreach (self::TYPES as $type) {
            $path = database_path("data/payroll-codes/{$type}.json");
            $codes = json_decode(file_get_contents($path), true);

            $rows = array_map(fn (array $c) => [
                'type' => $type,
                'code' => $c['code'],
                'name' => $c['name'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $codes);

            // Chunked because MySQL's max_allowed_packet is the one thing that
            // makes a 60-row insert fail in CI but not locally.
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('payroll_codes')->insert($chunk);
            }
        }
    }

    public function down(): void
    {
        DB::table('payroll_codes')->whereIn('type', self::TYPES)->delete();
    }
};
```

- [ ] **Step 5: Extend the model's type list**

In `app/Models/PayrollCode.php`, change the constant:

```php
public const TYPES = ['opstina', 'vid_staz', 'sifra_dviz', 'osloboduvanje', 'rab_cas', 'vid_nadomestoci'];
```

- [ ] **Step 6: Run the tests and make sure they pass**

Run: `php artisan test --filter=PayrollCodeTest`
Expected: PASS, all tests including the four from 5a.

- [ ] **Step 7: Commit**

```bash
git add database/data/payroll-codes database/migrations app/Models/PayrollCode.php tests/Feature/PayrollCodeTest.php
git commit -m "feat(payroll): seed the working-hour and compensation codebooks"
```

---

### Task 2: The monthly hour fund

A state-level fact, shared by every company, entered once a year by the admin. Without it there is no hourly rate, so it comes before anything that calculates.

**Files:**
- Create: `database/migrations/2026_08_15_100100_create_payroll_month_hours_table.php`
- Create: `app/Models/PayrollMonthHours.php`
- Create: `database/factories/PayrollMonthHoursFactory.php`
- Test: `tests/Feature/Payroll/PayrollMonthHoursTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `PayrollMonthHours::forMonth(int $year, int $month): self` — returns the row or throws `RuntimeException` with a Macedonian message. Property `hours` is `int`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Payroll/PayrollMonthHoursTest.php`:

```php
<?php

namespace Tests\Feature\Payroll;

use App\Models\PayrollMonthHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PayrollMonthHoursTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_finds_the_fund_for_a_month(): void
    {
        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);

        $this->assertSame(184, PayrollMonthHours::forMonth(2026, 7)->hours);
    }

    public function test_it_refuses_a_month_that_was_never_entered(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Нема внесен фонд на часови за 8/2026.');

        PayrollMonthHours::forMonth(2026, 8);
    }

    public function test_a_month_cannot_be_entered_twice(): void
    {
        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 176]);
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=PayrollMonthHoursTest`
Expected: FAIL — class `App\Models\PayrollMonthHours` not found.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_08_15_100100_create_payroll_month_hours_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_month_hours', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('hours');
            $table->timestamps();

            $table->unique(['year', 'month'], 'payroll_month_hours_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_month_hours');
    }
};
```

- [ ] **Step 4: Write the model**

`app/Models/PayrollMonthHours.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * The month's fund of working hours. A national fact, not a per-company one:
 * the working days in July are the same for every client of the firm.
 *
 * Entered once a year by the admin rather than derived from a calendar. Two of
 * the Macedonian public holidays move with the Orthodox and Muslim calendars,
 * so a built-in calendar would be a maintenance obligation every December in
 * exchange for saving twelve numbers a year.
 */
class PayrollMonthHours extends Model
{
    use HasFactory;

    protected $table = 'payroll_month_hours';

    protected $fillable = ['year', 'month', 'hours'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'hours' => 'integer',
        ];
    }

    public static function forMonth(int $year, int $month): self
    {
        $fund = static::where('year', $year)->where('month', $month)->first();

        if ($fund === null) {
            throw new RuntimeException("Нема внесен фонд на часови за {$month}/{$year}.");
        }

        return $fund;
    }
}
```

- [ ] **Step 5: Write the factory**

`database/factories/PayrollMonthHoursFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\PayrollMonthHours;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PayrollMonthHours> */
class PayrollMonthHoursFactory extends Factory
{
    protected $model = PayrollMonthHours::class;

    public function definition(): array
    {
        return [
            'year' => 2026,
            'month' => 7,
            'hours' => 184,
        ];
    }
}
```

- [ ] **Step 6: Run the tests and make sure they pass**

Run: `php artisan test --filter=PayrollMonthHoursTest`
Expected: PASS, 3 tests.

- [ ] **Step 7: Commit**

```bash
git add database/migrations database/factories/PayrollMonthHoursFactory.php app/Models/PayrollMonthHours.php tests/Feature/Payroll/PayrollMonthHoursTest.php
git commit -m "feat(payroll): monthly fund of working hours"
```

---

### Task 3: Prior service on the employee card

Минат труд is 0,5% per completed year of total service. Total service is the time since `employed_on` plus whatever the person brought with them.

**Files:**
- Create: `database/migrations/2026_08_15_100200_add_prior_service_months_to_employees_table.php`
- Modify: `app/Models/Employee.php`
- Modify: `app/Livewire/EmployeeForm.php`, `resources/views/livewire/employee-form.blade.php`
- Modify: `database/factories/EmployeeFactory.php`
- Test: `tests/Feature/EmployeeModelTest.php`, `tests/Feature/EmployeeFormTest.php`

**Interfaces:**
- Consumes: `Employee::$employed_on` (Carbon date, from 5a).
- Produces: `Employee::seniorityYearsOn(string $date): int` — completed years of total service on that date. `Employee::$prior_service_months` is an `int`, default `0`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/EmployeeModelTest.php`:

```php
public function test_seniority_counts_completed_years_since_employment(): void
{
    $employee = Employee::factory()->create([
        'employed_on' => '2016-07-01',
        'prior_service_months' => 0,
    ]);

    // 2026-06-30 is one day short of ten full years.
    $this->assertSame(9, $employee->seniorityYearsOn('2026-06-30'));
    $this->assertSame(10, $employee->seniorityYearsOn('2026-07-01'));
}

public function test_seniority_adds_the_service_brought_from_elsewhere(): void
{
    $employee = Employee::factory()->create([
        'employed_on' => '2024-07-01',
        'prior_service_months' => 90, // seven and a half years
    ]);

    // Two years here plus seven and a half brought along is nine full years.
    $this->assertSame(9, $employee->seniorityYearsOn('2026-07-01'));
}

public function test_seniority_is_zero_before_the_employment_date(): void
{
    $employee = Employee::factory()->create([
        'employed_on' => '2026-07-01',
        'prior_service_months' => 0,
    ]);

    $this->assertSame(0, $employee->seniorityYearsOn('2026-01-31'));
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=EmployeeModelTest`
Expected: FAIL — unknown column `prior_service_months`.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_08_15_100200_add_prior_service_months_to_employees_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Months, not years: someone joining with 7 years and 6 months of
            // service would otherwise cross a минат труд threshold half a year
            // late.
            $table->unsignedSmallInteger('prior_service_months')->default(0)->after('weekly_hours');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('prior_service_months');
        });
    }
};
```

- [ ] **Step 4: Add the method to the model**

In `app/Models/Employee.php`, add `'prior_service_months'` to `$fillable`, add `'prior_service_months' => 'integer'` to `casts()`, and add:

```php
    /**
     * Completed years of total service on the given date: time here plus the
     * service brought from previous employers.
     *
     * Completed years, not fractional ones — минат труд steps up on the
     * anniversary, it does not accrue daily.
     *
     * The calendar diff is deliberate. Carbon's diffInMonths() returns a float
     * derived from an average days-per-month constant, so it only lands on the
     * right whole month once the (int) cast truncates it — correct today, but
     * correct by luck rather than by construction. diff()->y and ->m are exact.
     */
    public function seniorityYearsOn(string $date): int
    {
        $on = Carbon::parse($date);

        if ($this->employed_on->gt($on)) {
            return 0;
        }

        $diff = $this->employed_on->diff($on);
        $months = $diff->y * 12 + $diff->m + $this->prior_service_months;

        return intdiv($months, 12);
    }
```

- [ ] **Step 5: Add the field to the form**

In `app/Livewire/EmployeeForm.php`: add `public int $priorServiceMonths = 0;` to the properties, include it in whatever `mount()` fills from the model and in the array passed to `update()`/`create()`, and add to the rules, in the pipe-delimited style every sibling rule in that call uses:

```php
'priorServiceMonths' => 'required|integer|min:0|max:720',
```

The property is camelCase while the column stays `prior_service_months` — that split is this file's established pattern (`weeklyHours` maps to `weekly_hours`). Add the Macedonian attribute name where the others already live, `lang/mk/validation.php`:

```php
'priorServiceMonths' => 'претходен стаж',
```

In `resources/views/livewire/employee-form.blade.php`, next to the `weekly_hours` field, add:

Use the `<x-input-label>` / `<x-text-input>` components the rest of that form already uses rather than raw `<label>` and `<input>` — match the neighbouring `weeklyHours` field exactly, with `wire:model="priorServiceMonths"`, the label „Претходен стаж (месеци)", and the hint „Стаж кај претходни работодавачи, за пресметка на минат труд." under it.

Bind the error to the camelCase key: `@error('priorServiceMonths')`.

In `database/factories/EmployeeFactory.php`, add `'prior_service_months' => 0,` to `definition()`.

- [ ] **Step 6: Add the form test**

Add to `tests/Feature/EmployeeFormTest.php`:

```php
public function test_it_stores_prior_service(): void
{
    $company = Company::factory()->create();
    $this->admin();

    Livewire::test(EmployeeForm::class, ['company' => $company])
        ->set('embg', '0101990450012')
        ->set('first_name', 'Марко')
        ->set('last_name', 'Марковски')
        ->set('bank_account', '300000000000000')
        ->set('insurance_type_code', '0010')
        ->set('employed_on', '2026-01-01')
        ->set('priorServiceMonths', 24)
        ->call('save');

    $this->assertSame(24, Employee::where('embg', '0101990450012')->first()->prior_service_months);
}
```

If the existing tests in this file set a different minimal set of fields to make `save()` pass, copy that set rather than the one above — the point of the test is the new column, not the rest of the form.

- [ ] **Step 7: Run the tests and make sure they pass**

Run: `php artisan test --filter="EmployeeModelTest|EmployeeFormTest"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations database/factories/EmployeeFactory.php app/Models/Employee.php app/Livewire/EmployeeForm.php resources/views/livewire/employee-form.blade.php tests/Feature/EmployeeModelTest.php tests/Feature/EmployeeFormTest.php
git commit -m "feat(payroll): record prior service for the seniority bonus"
```

---

### Task 4: The three run tables

The shape comes straight from `mpin.xsd`: calculation → employee → detail line. No calculation happens in this task; only storage.

**Files:**
- Create: `database/migrations/2026_08_15_100300_create_payroll_runs_table.php`
- Create: `database/migrations/2026_08_15_100400_create_payroll_run_employees_table.php`
- Create: `database/migrations/2026_08_15_100500_create_payroll_run_lines_table.php`
- Create: `app/Models/PayrollRun.php`, `app/Models/PayrollRunEmployee.php`, `app/Models/PayrollRunLine.php`
- Create: `database/factories/PayrollRunFactory.php`
- Test: `tests/Feature/Payroll/PayrollRunModelTest.php`

**Interfaces:**
- Consumes: `Company`, `Employee`, `PayrollParameter`, `JournalEntry`.
- Produces:
  - `PayrollRun` with `$company_id, $year, $month, $status, $month_hours, $payroll_parameter_id, $journal_entry_id, $confirmed_by, $confirmed_at`; relations `company()`, `parameter()`, `employees()`, `journalEntry()`; `PayrollRun::DRAFT = 'draft'`, `PayrollRun::CONFIRMED = 'confirmed'`; `isDraft(): bool`; `endOfMonth(): string`.
  - `PayrollRunEmployee` with the frozen figures and relations `run()`, `employee()`, `lines()`.
  - `PayrollRunLine` with `$kind, $code, $description, $hours, $percent, $amount, $borne_by, $is_automatic`; constants `KIND_HOURS = 'hours'`, `KIND_AMOUNT = 'amount'`, `KIND_DEDUCTION = 'deduction'`, `BORNE_EMPLOYER = 'employer'`, `BORNE_FZO = 'fzo'`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Payroll/PayrollRunModelTest.php`:

```php
<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollParameter;
use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use App\Models\PayrollRunLine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollRunModelTest extends TestCase
{
    use RefreshDatabase;

    private function parameter(): PayrollParameter
    {
        return PayrollParameter::forDate('2026-07-31');
    }

    public function test_a_company_has_one_run_per_month(): void
    {
        $company = Company::factory()->create();
        $parameter = $this->parameter();

        PayrollRun::create([
            'company_id' => $company->id, 'year' => 2026, 'month' => 7,
            'status' => PayrollRun::DRAFT, 'month_hours' => 184,
            'payroll_parameter_id' => $parameter->id,
        ]);

        $this->expectException(QueryException::class);

        PayrollRun::create([
            'company_id' => $company->id, 'year' => 2026, 'month' => 7,
            'status' => PayrollRun::DRAFT, 'month_hours' => 184,
            'payroll_parameter_id' => $parameter->id,
        ]);
    }

    public function test_it_walks_from_run_to_lines(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();

        $run = PayrollRun::create([
            'company_id' => $company->id, 'year' => 2026, 'month' => 7,
            'status' => PayrollRun::DRAFT, 'month_hours' => 184,
            'payroll_parameter_id' => $this->parameter()->id,
        ]);

        $runEmployee = PayrollRunEmployee::create([
            'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
            'gross' => 38507, 'pension' => 7663, 'health' => 2888, 'injury' => 193,
            'unemployment' => 39, 'contributions' => 10783, 'tax_base' => 16792,
            'tax' => 1679, 'net' => 26045, 'deductions_total' => 0,
            'effective_net' => 26045, 'top_up_pension' => 0, 'top_up_health' => 0,
            'top_up_injury' => 0, 'top_up_unemployment' => 0, 'top_up' => 0,
            'hourly_rate' => 209.28, 'seniority_years' => 0, 'full_month_gross' => 38507,
        ]);

        PayrollRunLine::create([
            'payroll_run_employee_id' => $runEmployee->id,
            'kind' => PayrollRunLine::KIND_HOURS, 'code' => '001',
            'description' => 'Редовни работни часови', 'hours' => 184,
            'percent' => 100, 'amount' => 38507,
            'borne_by' => PayrollRunLine::BORNE_EMPLOYER, 'is_automatic' => false,
        ]);

        $this->assertSame('001', $run->fresh()->employees->first()->lines->first()->code);
    }

    public function test_end_of_month_is_the_entry_date(): void
    {
        $run = new PayrollRun(['year' => 2026, 'month' => 2]);

        $this->assertSame('2026-02-28', $run->endOfMonth());
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=PayrollRunModelTest`
Expected: FAIL — class `App\Models\PayrollRun` not found.

- [ ] **Step 3: Write the runs migration**

`database/migrations/2026_08_15_100300_create_payroll_runs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status', 16)->default('draft');

            // Frozen when the run opens. A parameter change next January must
            // not silently restate a July run that has already been filed.
            $table->unsignedSmallInteger('month_hours');
            $table->foreignId('payroll_parameter_id')->constrained();

            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'year', 'month'], 'payroll_runs_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
```

- [ ] **Step 4: Write the run-employees migration**

`database/migrations/2026_08_15_100400_create_payroll_run_employees_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_run_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained();

            foreach ([
                'gross', 'pension', 'health', 'injury', 'unemployment', 'contributions',
                'tax_base', 'tax', 'net', 'deductions_total', 'effective_net',
                'top_up_pension', 'top_up_health', 'top_up_injury', 'top_up_unemployment',
                'top_up', 'hourly_rate', 'full_month_gross',
            ] as $column) {
                $table->decimal($column, 15, 2)->default(0);
            }

            $table->unsignedSmallInteger('seniority_years')->default(0);
            $table->timestamps();

            // Short explicit name: the generated one would be
            // payroll_run_employees_payroll_run_id_employee_id_unique at 58
            // characters, close enough to MySQL's 64-character limit to be
            // worth not relying on.
            $table->unique(['payroll_run_id', 'employee_id'], 'payroll_run_employee_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_run_employees');
    }
};
```

- [ ] **Step 5: Write the run-lines migration**

`database/migrations/2026_08_15_100500_create_payroll_run_lines_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_run_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_employee_id')->constrained('payroll_run_employees', 'id', 'payroll_run_lines_employee_fk')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('code', 16)->nullable();     // SifraTipRabotenCas
            $table->string('description');
            // Whole hours: BrojCasovi is xs:int in mpin.xsd.
            $table->unsignedSmallInteger('hours')->nullable();
            $table->decimal('percent', 8, 2)->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('borne_by', 16)->default('employer');
            $table->boolean('is_automatic')->default(false);
            $table->timestamps();

            $table->index('payroll_run_employee_id', 'payroll_run_lines_employee_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_run_lines');
    }
};
```

- [ ] **Step 6: Write the models**

`app/Models/PayrollRun.php`:

```php
<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    use HasFactory;

    public const DRAFT = 'draft';

    public const CONFIRMED = 'confirmed';

    protected $fillable = [
        'company_id', 'year', 'month', 'status', 'month_hours',
        'payroll_parameter_id', 'journal_entry_id', 'confirmed_by', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'month_hours' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parameter(): BelongsTo
    {
        return $this->belongsTo(PayrollParameter::class, 'payroll_parameter_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(PayrollRunEmployee::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /** The date the run is booked on and the date its salaries are read at. */
    public function endOfMonth(): string
    {
        return Carbon::create($this->year, $this->month, 1)->endOfMonth()->toDateString();
    }

    public function monthName(): string
    {
        return [
            1 => 'Јануари', 2 => 'Февруари', 3 => 'Март', 4 => 'Април',
            5 => 'Мај', 6 => 'Јуни', 7 => 'Јули', 8 => 'Август',
            9 => 'Септември', 10 => 'Октомври', 11 => 'Ноември', 12 => 'Декември',
        ][$this->month];
    }
}
```

`app/Models/PayrollRunEmployee.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRunEmployee extends Model
{
    protected $fillable = [
        'payroll_run_id', 'employee_id', 'gross', 'pension', 'health', 'injury',
        'unemployment', 'contributions', 'tax_base', 'tax', 'net',
        'deductions_total', 'effective_net', 'top_up_pension', 'top_up_health',
        'top_up_injury', 'top_up_unemployment', 'top_up', 'hourly_rate',
        'seniority_years', 'full_month_gross',
    ];

    protected function casts(): array
    {
        return [
            'gross' => 'float', 'pension' => 'float', 'health' => 'float',
            'injury' => 'float', 'unemployment' => 'float', 'contributions' => 'float',
            'tax_base' => 'float', 'tax' => 'float', 'net' => 'float',
            'deductions_total' => 'float', 'effective_net' => 'float',
            'top_up_pension' => 'float', 'top_up_health' => 'float',
            'top_up_injury' => 'float', 'top_up_unemployment' => 'float',
            'top_up' => 'float', 'hourly_rate' => 'float',
            'full_month_gross' => 'float', 'seniority_years' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollRunLine::class, 'payroll_run_employee_id')->orderBy('id');
    }
}
```

`app/Models/PayrollRunLine.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRunLine extends Model
{
    public const KIND_HOURS = 'hours';

    public const KIND_AMOUNT = 'amount';

    public const KIND_DEDUCTION = 'deduction';

    public const BORNE_EMPLOYER = 'employer';

    public const BORNE_FZO = 'fzo';

    protected $fillable = [
        'payroll_run_employee_id', 'kind', 'code', 'description',
        'hours', 'percent', 'amount', 'borne_by', 'is_automatic',
    ];

    protected function casts(): array
    {
        return [
            'hours' => 'integer',
            'percent' => 'float',
            'amount' => 'float',
            'is_automatic' => 'boolean',
        ];
    }

    public function runEmployee(): BelongsTo
    {
        return $this->belongsTo(PayrollRunEmployee::class, 'payroll_run_employee_id');
    }
}
```

- [ ] **Step 7: Write the run factory**

`database/factories/PayrollRunFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PayrollParameter;
use App\Models\PayrollRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PayrollRun> */
class PayrollRunFactory extends Factory
{
    protected $model = PayrollRun::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'year' => 2026,
            'month' => 7,
            'status' => PayrollRun::DRAFT,
            'month_hours' => 184,
            'payroll_parameter_id' => fn () => PayrollParameter::forDate('2026-07-31')->id,
        ];
    }
}
```

**Do not create a `PayrollParameterFactory`.** `payroll_parameters.effective_from` is globally unique and the 5a migration already seeds 2026-01-01, 2026-03-01 and 2026-07-01, so a factory would either collide with those rows or have to invent a date in some far-future year carrying 2026's rates — a period that means nothing. Reading the seeded row is both simpler and keeps every payroll test on the same published figures.

- [ ] **Step 8: Run the tests and make sure they pass**

Run: `php artisan test --filter=PayrollRunModelTest`
Expected: PASS, 3 tests.

- [ ] **Step 9: Commit**

```bash
git add database/migrations database/factories app/Models/PayrollRun.php app/Models/PayrollRunEmployee.php app/Models/PayrollRunLine.php tests/Feature/Payroll/PayrollRunModelTest.php
git commit -m "feat(payroll): tables for the monthly run, its employees and their lines"
```

---

### Task 5: The calculator

**Read the spec's "Пресметката" and "Сразмерната поделба" sections before writing this.** This is the task where being clever produces wrong money.

Pure functions over plain arrays: no models, no database, no company. That is what makes the two invariants testable in isolation.

**Files:**
- Create: `app/Support/Payroll/LineType.php`
- Create: `app/Support/Payroll/PayrollRunLineResult.php`
- Create: `app/Support/Payroll/PayrollRunResult.php`
- Create: `app/Support/Payroll/PayrollRunCalculator.php`
- Test: `tests/Feature/Payroll/PayrollRunCalculatorTest.php`, `tests/Unit/Payroll/LineTypeTest.php`

The calculator's own test is a **Feature** test even though the calculator touches no database. That is 5a's precedent — `tests/Feature/Payroll/SalaryCalculatorTest.php` reads its parameters through `PayrollParameter::forDate()` against the seeded 2026 periods rather than hand-building a model. Following it keeps both calculators verified against the same seeded figures, and avoids instantiating an Eloquent model with no booted application. `LineTypeTest` stays a plain unit test, like `tests/Unit/Support/EmbgTest.php`: it reads class constants and nothing else.

**Interfaces:**
- Consumes: `SalaryCalculator::fromGross(float, PayrollParameter): SalaryBreakdown` and `::fromNet(float, PayrollParameter): SalaryBreakdown` (5a).
- Produces:
  - `LineType::OFFERED: array<string, array{label: string, percent: float, kind: string}>`, `LineType::BASE_CODES: list<string>`, `LineType::FZO_CODES: list<string>`, `LineType::SENIORITY_CODE: string`, `LineType::SENIORITY_PERCENT_PER_YEAR: float`, `LineType::borneBy(?string $code): string`, `LineType::defaultPercent(string $code): float`, `LineType::label(string $code): string`, `LineType::isBase(?string $code): bool`
  - `PayrollRunCalculator::fullMonthGross(float $amount, string $basis, PayrollParameter $parameters): float`
  - `PayrollRunCalculator::calculate(float $fullMonthGross, int $monthHours, int $seniorityYears, array $inputLines, PayrollParameter $parameters): PayrollRunResult`, where each input line is `['kind' => string, 'code' => ?string, 'description' => string, 'hours' => ?int, 'percent' => ?float, 'amount' => ?float, 'borne_by' => string]`

The parameter is named `parameters`, not `p` — every call site in this plan uses named arguments, so the name is part of the contract.
  - `PayrollRunResult` readonly properties: `hourlyRate, lines (list<PayrollRunLineResult>), gross, breakdown (SalaryBreakdown), deductionsTotal, effectiveNet, employerGross, employerContributions, employerTax, employerNet`

- [ ] **Step 1: Write the failing test for the code map**

`tests/Unit/Payroll/LineTypeTest.php`:

```php
<?php

namespace Tests\Unit\Payroll;

use App\Models\PayrollRunLine;
use App\Support\Payroll\LineType;
use PHPUnit\Framework\TestCase;

class LineTypeTest extends TestCase
{
    public function test_ordinary_sick_leave_is_borne_by_the_employer(): void
    {
        foreach (['125', '126', '127', '128'] as $code) {
            $this->assertSame(PayrollRunLine::BORNE_EMPLOYER, LineType::borneBy($code));
        }
    }

    public function test_the_fund_bears_its_own_sick_leave(): void
    {
        // 129 is "Надоместок на плата за боледување што го исплатува ФЗО".
        // The company calculates it and declares it; the Fund carries it.
        $this->assertSame(PayrollRunLine::BORNE_FZO, LineType::borneBy('129'));
    }

    public function test_other_state_bodies_bear_their_own_allowances(): void
    {
        foreach (['132', '138', '139'] as $code) {
            $this->assertSame(PayrollRunLine::BORNE_FZO, LineType::borneBy($code));
        }
    }

    public function test_everything_else_falls_on_the_employer(): void
    {
        $this->assertSame(PayrollRunLine::BORNE_EMPLOYER, LineType::borneBy('001'));
        $this->assertSame(PayrollRunLine::BORNE_EMPLOYER, LineType::borneBy('005'));
        $this->assertSame(PayrollRunLine::BORNE_EMPLOYER, LineType::borneBy(null));
    }

    public function test_the_statutory_uplifts(): void
    {
        $this->assertSame(135.0, LineType::defaultPercent('005')); // overtime
        $this->assertSame(135.0, LineType::defaultPercent('003')); // night work
        $this->assertSame(150.0, LineType::defaultPercent('007')); // public holiday work
        $this->assertSame(100.0, LineType::defaultPercent('001')); // ordinary hours
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=LineTypeTest`
Expected: FAIL — class `App\Support\Payroll\LineType` not found.

- [ ] **Step 3: Write LineType**

`app/Support/Payroll/LineType.php`:

```php
<?php

namespace App\Support\Payroll;

use App\Models\PayrollRunLine;

/**
 * The subset of УЈП's codebooks the line editor offers, and what the app
 * assumes about each code.
 *
 * All 60 rab_cas plus 17 vid_nadomestoci codes live in `payroll_codes` because
 * 5c needs them. This list is the shortlist a private-sector payroll actually
 * uses. Extending it is an edit here, not a migration.
 */
final class LineType
{
    /**
     * The codes that make up the base for the seniority bonus. Deliberately an
     * explicit list rather than a rule like "ordinary hours and paid absence":
     * a rule invites argument about sick leave, which is already a percentage
     * of the salary and must not be uplifted a second time.
     *
     * @var list<string>
     */
    public const BASE_CODES = ['001', '009', '010', '012'];

    /**
     * Codes whose cost a state body carries, not the employer. The company
     * still calculates and declares them — they simply never reach its ledger.
     *
     * @var list<string>
     */
    public const FZO_CODES = ['129', '132', '138', '139'];

    /** The seniority bonus, half a percent per completed year. */
    public const SENIORITY_CODE = '037';

    public const SENIORITY_PERCENT_PER_YEAR = 0.5;

    /**
     * code => [label, percent, kind]
     *
     * @var array<string, array{label: string, percent: float, kind: string}>
     */
    public const OFFERED = [
        '001' => ['label' => 'Редовни работни часови', 'percent' => 100.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '009' => ['label' => 'Годишен одмор', 'percent' => 100.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '010' => ['label' => 'Државен празник', 'percent' => 100.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '012' => ['label' => 'Платено отсуство', 'percent' => 100.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '003' => ['label' => 'Ноќна работа', 'percent' => 135.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '005' => ['label' => 'Прекувремена работа', 'percent' => 135.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '007' => ['label' => 'Работа на државен празник', 'percent' => 150.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '023' => ['label' => 'Неплатено отсуство', 'percent' => 0.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '125' => ['label' => 'Боледување 70%', 'percent' => 70.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '126' => ['label' => 'Боледување 80%', 'percent' => 80.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '127' => ['label' => 'Боледување 90%', 'percent' => 90.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '128' => ['label' => 'Боледување 100%', 'percent' => 100.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '129' => ['label' => 'Боледување на товар на ФЗО', 'percent' => 70.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '037' => ['label' => 'Минат труд', 'percent' => 100.0, 'kind' => PayrollRunLine::KIND_AMOUNT],
        '029' => ['label' => 'Храна', 'percent' => 100.0, 'kind' => PayrollRunLine::KIND_AMOUNT],
        '030' => ['label' => 'Превоз', 'percent' => 100.0, 'kind' => PayrollRunLine::KIND_AMOUNT],
        '034' => ['label' => 'Награда', 'percent' => 100.0, 'kind' => PayrollRunLine::KIND_AMOUNT],
        '062' => ['label' => 'Бонус за успешност', 'percent' => 100.0, 'kind' => PayrollRunLine::KIND_AMOUNT],
    ];

    public static function borneBy(?string $code): string
    {
        return $code !== null && in_array($code, self::FZO_CODES, true)
            ? PayrollRunLine::BORNE_FZO
            : PayrollRunLine::BORNE_EMPLOYER;
    }

    public static function defaultPercent(string $code): float
    {
        return self::OFFERED[$code]['percent'] ?? 100.0;
    }

    public static function label(string $code): string
    {
        return self::OFFERED[$code]['label'] ?? $code;
    }

    public static function isBase(?string $code): bool
    {
        return $code !== null && in_array($code, self::BASE_CODES, true);
    }
}
```

- [ ] **Step 4: Run the LineType test and make sure it passes**

Run: `php artisan test --filter=LineTypeTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Write the failing calculator test**

`tests/Feature/Payroll/PayrollRunCalculatorTest.php`:

```php
<?php

namespace Tests\Feature\Payroll;

use App\Models\PayrollParameter;
use App\Models\PayrollRunLine;
use App\Support\Payroll\PayrollRunCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollRunCalculatorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * July–December 2026, read from the seeded periods rather than built by
     * hand — the same source 5a's calculator is verified against, so the two
     * can never drift apart in a way a test would hide.
     */
    private function july2026(): PayrollParameter
    {
        return PayrollParameter::forDate('2026-07-31');
    }

    /** @return array{kind: string, code: ?string, description: string, hours: ?int, percent: ?float, amount: ?float, borne_by: string} */
    private function hoursLine(string $code, int $hours, float $percent): array
    {
        return [
            'kind' => PayrollRunLine::KIND_HOURS, 'code' => $code,
            'description' => 'ставка', 'hours' => $hours, 'percent' => $percent,
            'amount' => null, 'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
        ];
    }

    public function test_a_full_month_of_ordinary_hours_reproduces_the_agreed_gross(): void
    {
        $result = PayrollRunCalculator::calculate(
            fullMonthGross: 38507.0,
            monthHours: 184,
            seniorityYears: 0,
            inputLines: [$this->hoursLine('001', 184, 100.0)],
            parameters: $this->july2026(),
        );

        $this->assertSame(38507.0, round($result->gross, 2));
        $this->assertSame(26046, (int) round($result->breakdown->net));
    }

    /**
     * The invariant that ties 5b back to 5a. An employee agreed at a net
     * figure, working a plain full month, must be paid exactly that net —
     * otherwise the hourly split silently rewrites what was agreed.
     */
    public function test_an_agreed_net_survives_a_full_month_untouched(): void
    {
        $parameters = $this->july2026();

        $fullMonthGross = PayrollRunCalculator::fullMonthGross(30000.0, 'net', $parameters);

        $result = PayrollRunCalculator::calculate(
            fullMonthGross: $fullMonthGross,
            monthHours: 176,
            seniorityYears: 0,
            inputLines: [$this->hoursLine('001', 176, 100.0)],
            parameters: $parameters,
        );

        $this->assertSame(30000, (int) round($result->effectiveNet));
    }

    public function test_the_lines_always_sum_to_the_gross(): void
    {
        $result = PayrollRunCalculator::calculate(
            fullMonthGross: 40000.0,
            monthHours: 184,
            seniorityYears: 12,
            inputLines: [
                $this->hoursLine('001', 160, 100.0),
                $this->hoursLine('125', 16, 70.0),
                $this->hoursLine('005', 8, 135.0),
                [
                    'kind' => PayrollRunLine::KIND_AMOUNT, 'code' => '029',
                    'description' => 'Храна', 'hours' => null, 'percent' => null,
                    'amount' => 3000.0, 'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
                ],
            ],
            parameters: $this->july2026(),
        );

        $sum = 0.0;

        foreach ($result->lines as $line) {
            if ($line->kind !== PayrollRunLine::KIND_DEDUCTION) {
                $sum += $line->amount;
            }
        }

        $this->assertSame(round($result->gross, 2), round($sum, 2));
    }

    public function test_the_seniority_bonus_is_half_a_percent_of_the_base_lines_per_year(): void
    {
        $result = PayrollRunCalculator::calculate(
            fullMonthGross: 36800.0,
            monthHours: 184,
            seniorityYears: 20,
            inputLines: [
                $this->hoursLine('001', 184, 100.0),
                // Overtime and food must not be uplifted by seniority.
                $this->hoursLine('005', 10, 135.0),
                [
                    'kind' => PayrollRunLine::KIND_AMOUNT, 'code' => '029',
                    'description' => 'Храна', 'hours' => null, 'percent' => null,
                    'amount' => 3000.0, 'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
                ],
            ],
            parameters: $this->july2026(),
        );

        $seniority = null;

        foreach ($result->lines as $line) {
            if ($line->isAutomatic) {
                $seniority = $line;
            }
        }

        $this->assertNotNull($seniority);
        // 20 years × 0,5% = 10% of the 36 800 of base lines.
        $this->assertSame(3680.0, round($seniority->amount, 2));
    }

    public function test_deductions_lower_the_effective_net_but_not_the_net(): void
    {
        $result = PayrollRunCalculator::calculate(
            fullMonthGross: 38507.0,
            monthHours: 184,
            seniorityYears: 0,
            inputLines: [
                $this->hoursLine('001', 184, 100.0),
                [
                    'kind' => PayrollRunLine::KIND_DEDUCTION, 'code' => null,
                    'description' => 'Кредит', 'hours' => null, 'percent' => null,
                    'amount' => 5000.0, 'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
                ],
            ],
            parameters: $this->july2026(),
        );

        $this->assertSame(26046, (int) round($result->breakdown->net));
        $this->assertSame(21046, (int) round($result->effectiveNet));
        $this->assertSame(5000.0, round($result->deductionsTotal, 2));
    }

    public function test_the_fund_borne_share_is_kept_out_of_the_employers_figures(): void
    {
        $fzoLine = $this->hoursLine('129', 92, 100.0);
        $fzoLine['borne_by'] = PayrollRunLine::BORNE_FZO;

        $result = PayrollRunCalculator::calculate(
            fullMonthGross: 38400.0,
            monthHours: 184,
            seniorityYears: 0,
            inputLines: [$this->hoursLine('001', 92, 100.0), $fzoLine],
            parameters: $this->july2026(),
        );

        // Half the hours are the Fund's, so half the gross is.
        $this->assertSame(19200.0, round($result->employerGross, 2));
        $this->assertSame(
            round($result->breakdown->contributions / 2, 2),
            round($result->employerContributions, 2)
        );
        $this->assertSame(round($result->breakdown->tax / 2, 2), round($result->employerTax, 2));
    }

    public function test_the_employers_figures_balance(): void
    {
        $fzoLine = $this->hoursLine('129', 40, 100.0);
        $fzoLine['borne_by'] = PayrollRunLine::BORNE_FZO;

        $result = PayrollRunCalculator::calculate(
            fullMonthGross: 45000.0,
            monthHours: 184,
            seniorityYears: 7,
            inputLines: [
                $this->hoursLine('001', 144, 100.0),
                $fzoLine,
                [
                    'kind' => PayrollRunLine::KIND_DEDUCTION, 'code' => null,
                    'description' => 'Административна забрана', 'hours' => null,
                    'percent' => null, 'amount' => 2500.0,
                    'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
                ],
            ],
            parameters: $this->july2026(),
        );

        // What the ledger will debit against what it will credit.
        $debit = $result->employerGross + $result->breakdown->topUp;
        $credit = ($result->employerContributions + $result->breakdown->topUp)
            + $result->employerTax
            + $result->deductionsTotal
            + $result->employerNet;

        $this->assertSame(round($debit, 2), round($credit, 2));
    }

    public function test_a_month_entirely_on_the_fund_leaves_the_employer_with_nothing(): void
    {
        $fzoLine = $this->hoursLine('129', 184, 100.0);
        $fzoLine['borne_by'] = PayrollRunLine::BORNE_FZO;

        $result = PayrollRunCalculator::calculate(
            fullMonthGross: 38507.0,
            monthHours: 184,
            seniorityYears: 0,
            inputLines: [$fzoLine],
            parameters: $this->july2026(),
        );

        $this->assertSame(0.0, round($result->employerGross, 2));
        $this->assertSame(0.0, round($result->employerContributions, 2));
        $this->assertSame(0.0, round($result->employerTax, 2));
    }

    public function test_a_net_agreement_gives_a_different_gross_in_january_and_july(): void
    {
        // January's pension rate is 18,8% and unemployment 1,2%; July's are
        // 19,9% and 0,1%. An agreement in net therefore costs the employer a
        // different gross in each half of the year — the deliberate
        // consequence recorded in 5a's spec.
        $january = PayrollParameter::forDate('2026-01-31');

        $januaryGross = PayrollRunCalculator::fullMonthGross(30000.0, 'net', $january);
        $julyGross = PayrollRunCalculator::fullMonthGross(30000.0, 'net', $this->july2026());

        $this->assertNotSame((int) round($januaryGross), (int) round($julyGross));
    }
}
```

- [ ] **Step 6: Run it to make sure it fails**

Run: `php artisan test --filter=PayrollRunCalculatorTest`
Expected: FAIL — class `App\Support\Payroll\PayrollRunCalculator` not found.

- [ ] **Step 7: Write the two result objects**

`app/Support/Payroll/PayrollRunLineResult.php`:

```php
<?php

namespace App\Support\Payroll;

/** One calculated line. `amount` is always filled, whatever the kind. */
readonly class PayrollRunLineResult
{
    public function __construct(
        public string $kind,
        public ?string $code,
        public string $description,
        public ?int $hours,
        public ?float $percent,
        public float $amount,
        public string $borneBy,
        public bool $isAutomatic,
    ) {}
}
```

`app/Support/Payroll/PayrollRunResult.php`:

```php
<?php

namespace App\Support\Payroll;

/**
 * One employee's month.
 *
 * The `employer*` figures are the subset that reaches the company's ledger.
 * They are not a second calculation: contributions and tax are computed once,
 * on the whole gross, then apportioned. See the spec's "Сразмерната поделба".
 */
readonly class PayrollRunResult
{
    /** @param list<PayrollRunLineResult> $lines */
    public function __construct(
        public float $hourlyRate,
        public array $lines,
        public float $gross,
        public SalaryBreakdown $breakdown,
        public float $deductionsTotal,
        public float $effectiveNet,
        public float $employerGross,
        public float $employerContributions,
        public float $employerTax,
        public float $employerNet,
    ) {}
}
```

- [ ] **Step 8: Write the calculator**

`app/Support/Payroll/PayrollRunCalculator.php`:

```php
<?php

namespace App\Support\Payroll;

use App\Models\PayrollParameter;
use App\Models\PayrollRunLine;

/**
 * Turns a month's lines into money.
 *
 * Deliberately free of models, database and request state: the two invariants
 * this must satisfy — an agreed net survives a plain month untouched, and the
 * lines sum to the gross — are properties of the arithmetic alone.
 *
 * Two decimals throughout. Rounding to whole denars is МПИН's write rule, not
 * a step here; chaining rounded figures produces 26 045 where the published
 * table says 26 046.
 */
final class PayrollRunCalculator
{
    /**
     * The gross a full month would pay. For a net agreement this is where the
     * agreement is converted, once, before hours enter the picture — so the
     * hourly rate divides a gross that already honours the agreed net.
     */
    public static function fullMonthGross(float $amount, string $basis, PayrollParameter $parameters): float
    {
        return $basis === 'net'
            ? SalaryCalculator::fromNet($amount, $parameters)->gross
            : round($amount, 2);
    }

    /**
     * @param  list<array{kind: string, code: ?string, description: string, hours: ?int, percent: ?float, amount: ?float, borne_by: string}>  $inputLines
     */
    public static function calculate(
        float $fullMonthGross,
        int $monthHours,
        int $seniorityYears,
        array $inputLines,
        PayrollParameter $parameters,
    ): PayrollRunResult {
        $hourlyRate = $monthHours > 0 ? round($fullMonthGross / $monthHours, 2) : 0.0;

        $lines = [];
        $baseTotal = 0.0;

        foreach ($inputLines as $input) {
            $amount = match ($input['kind']) {
                PayrollRunLine::KIND_HOURS => round(
                    $hourlyRate * (int) $input['hours'] * ((float) $input['percent']) / 100,
                    2
                ),
                default => round((float) ($input['amount'] ?? 0), 2),
            };

            $lines[] = new PayrollRunLineResult(
                kind: $input['kind'],
                code: $input['code'],
                description: $input['description'],
                hours: $input['hours'] ?? null,
                percent: $input['percent'] ?? null,
                amount: $amount,
                borneBy: $input['borne_by'],
                isAutomatic: false,
            );

            if ($input['kind'] === PayrollRunLine::KIND_HOURS && LineType::isBase($input['code'])) {
                $baseTotal += $amount;
            }
        }

        // The seniority bonus is derived, so it is appended rather than
        // entered. It rides on the base lines only: sick leave is already a
        // percentage of the salary, and uplifting overtime or a meal allowance
        // by length of service is not what минат труд is.
        $seniorityAmount = round($baseTotal * LineType::SENIORITY_PERCENT_PER_YEAR * $seniorityYears / 100, 2);

        if ($seniorityAmount > 0) {
            $lines[] = new PayrollRunLineResult(
                kind: PayrollRunLine::KIND_AMOUNT,
                code: LineType::SENIORITY_CODE,
                description: LineType::label(LineType::SENIORITY_CODE),
                hours: null,
                percent: null,
                amount: $seniorityAmount,
                borneBy: PayrollRunLine::BORNE_EMPLOYER,
                isAutomatic: true,
            );
        }

        $gross = 0.0;
        $employerGross = 0.0;
        $deductionsTotal = 0.0;

        foreach ($lines as $line) {
            if ($line->kind === PayrollRunLine::KIND_DEDUCTION) {
                $deductionsTotal += $line->amount;

                continue;
            }

            $gross += $line->amount;

            if ($line->borneBy === PayrollRunLine::BORNE_EMPLOYER) {
                $employerGross += $line->amount;
            }
        }

        $gross = round($gross, 2);
        $employerGross = round($employerGross, 2);
        $deductionsTotal = round($deductionsTotal, 2);

        $breakdown = SalaryCalculator::fromGross($gross, $parameters);

        // Contributions and tax are charged on the whole salary — the personal
        // allowance is deducted once, not per line — so the employer's share
        // can only be apportioned, never recomputed. The share is the
        // employer's part of the gross.
        $share = $gross > 0 ? $employerGross / $gross : 0.0;

        $employerContributions = round($breakdown->contributions * $share, 2);
        $employerTax = round($breakdown->tax * $share, 2);

        // The remainder, not an eighth figure of its own. Whatever the rounding
        // of the two lines above leaves behind lands here, which is what keeps
        // the journal entry balanced to the denar.
        $employerNet = round($employerGross - $employerContributions - $employerTax - $deductionsTotal, 2);

        return new PayrollRunResult(
            hourlyRate: $hourlyRate,
            lines: $lines,
            gross: $gross,
            breakdown: $breakdown,
            deductionsTotal: $deductionsTotal,
            effectiveNet: round($breakdown->net - $deductionsTotal, 2),
            employerGross: $employerGross,
            employerContributions: $employerContributions,
            employerTax: $employerTax,
            employerNet: $employerNet,
        );
    }
}
```

- [ ] **Step 9: Run the tests and make sure they pass**

Run: `php artisan test --filter="PayrollRunCalculatorTest|LineTypeTest"`
Expected: PASS, 14 tests.

If `july2026()` throws "Нема параметри за пресметка што важат на 2026-07-31", the 5a migration that seeds the 2026 periods did not run — check `database/migrations/2026_08_13_090100_create_payroll_parameters_table.php` rather than working around it by hand-building a parameter set.

If `test_an_agreed_net_survives_a_full_month_untouched` fails by one denar, do **not** add a rounding step to the chain. The likely cause is `fromNet` returning a gross that `fullMonthGross` then rounds a second time — check that `fromNet` is called once and its `gross` used as-is.

- [ ] **Step 10: Commit**

```bash
git add app/Support/Payroll tests/Unit/Payroll tests/Feature/Payroll/PayrollRunCalculatorTest.php
git commit -m "feat(payroll): calculator turning a month of lines into gross, net and the employer's share"
```

---

### Task 6: Opening and recalculating a run

The service owns everything the calculator refuses to know about: companies, employees, the frozen parameter set, and persistence.

**Files:**
- Create: `app/Services/Payroll/PayrollRunService.php`
- Test: `tests/Feature/Payroll/PayrollRunServiceTest.php`

**Interfaces:**
- Consumes: `PayrollRunCalculator::calculate()`, `PayrollRunCalculator::fullMonthGross()`, `PayrollMonthHours::forMonth()`, `PayrollParameter::forDate()`, `Employee::salaryOn()`, `Employee::isActiveOn()`, `Employee::seniorityYearsOn()`.
- Produces: `PayrollRunService::open(Company $company, int $year, int $month): PayrollRun` and `PayrollRunService::recalculate(PayrollRun $run): PayrollRun`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Payroll/PayrollRunServiceTest.php`:

```php
<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollMonthHours;
use App\Models\PayrollParameter;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Services\Payroll\PayrollRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PayrollRunServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedParameters(): PayrollParameter
    {
        return PayrollParameter::forDate('2026-07-31');
    }

    private function employeeOn(Company $company, float $amount, string $basis): Employee
    {
        $employee = Employee::factory()->for($company)->create([
            'employed_on' => '2020-01-01',
            'prior_service_months' => 0,
        ]);

        EmployeeSalary::create([
            'employee_id' => $employee->id,
            'effective_from' => '2020-01-01',
            'amount' => $amount,
            'basis' => $basis,
        ]);

        return $employee;
    }

    public function test_it_opens_a_run_with_every_active_employee_on_full_hours(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);
        $this->employeeOn($company, 38507, 'gross');

        $run = app(PayrollRunService::class)->open($company, 2026, 7);

        $this->assertSame(PayrollRun::DRAFT, $run->status);
        $this->assertSame(184, $run->month_hours);
        $this->assertCount(1, $run->employees);

        $line = $run->employees->first()->lines->first();
        $this->assertSame('001', $line->code);
        $this->assertSame(184, $line->hours);
        $this->assertSame(38507.0, round($run->employees->first()->gross, 2));
    }

    public function test_it_leaves_out_someone_who_had_already_left(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);

        $gone = $this->employeeOn($company, 38507, 'gross');
        $gone->update(['terminated_on' => '2026-03-31']);

        $run = app(PayrollRunService::class)->open($company, 2026, 7);

        $this->assertCount(0, $run->employees);
    }

    public function test_it_refuses_a_month_with_no_hour_fund(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Нема внесен фонд на часови за 7/2026.');

        app(PayrollRunService::class)->open($company, 2026, 7);
    }

    public function test_recalculating_keeps_typed_lines_and_refreshes_the_automatic_one(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);
        $employee = $this->employeeOn($company, 36800, 'gross');
        $employee->update(['employed_on' => '2006-07-01']); // 20 years of service

        $service = app(PayrollRunService::class);
        $run = $service->open($company, 2026, 7);
        $runEmployee = $run->employees->first();

        PayrollRunLine::create([
            'payroll_run_employee_id' => $runEmployee->id,
            'kind' => PayrollRunLine::KIND_DEDUCTION, 'code' => null,
            'description' => 'Кредит', 'hours' => null, 'percent' => null,
            'amount' => 4000, 'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
            'is_automatic' => false,
        ]);

        $run = $service->recalculate($run->fresh());
        $runEmployee = $run->employees->first();

        $this->assertTrue($runEmployee->lines->contains(fn ($l) => $l->description === 'Кредит'));
        $this->assertTrue($runEmployee->lines->contains(fn ($l) => $l->is_automatic && $l->code === '037'));
        $this->assertSame(4000.0, round($runEmployee->deductions_total, 2));
    }

    public function test_a_parameter_change_does_not_touch_a_confirmed_run(): void
    {
        $company = Company::factory()->create();
        $this->seedParameters();
        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);
        $this->employeeOn($company, 38507, 'gross');

        $run = app(PayrollRunService::class)->open($company, 2026, 7);
        $frozenParameterId = $run->payroll_parameter_id;

        PayrollParameter::create([
            'effective_from' => '2026-07-15',
            'rate_pension' => 25.0, 'rate_health' => 9.0, 'rate_injury' => 0.5,
            'rate_unemployment' => 0.1, 'rate_tax' => 12.0,
            'personal_allowance' => 10932, 'average_salary' => 69141,
            'min_base' => 34571, 'max_base' => 1106256, 'minimum_wage' => 38507,
        ]);

        $this->assertSame($frozenParameterId, $run->fresh()->payroll_parameter_id);
    }
}
```

If `EmployeeSalary`'s columns differ from `effective_from` / `amount` / `basis`, use the real ones — check `app/Models/EmployeeSalary.php` first.

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=PayrollRunServiceTest`
Expected: FAIL — class `App\Services\Payroll\PayrollRunService` not found.

- [ ] **Step 3: Write the service**

`app/Services/Payroll/PayrollRunService.php`:

```php
<?php

namespace App\Services\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollMonthHours;
use App\Models\PayrollParameter;
use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use App\Models\PayrollRunLine;
use App\Support\Payroll\LineType;
use App\Support\Payroll\PayrollRunCalculator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PayrollRunService
{
    /**
     * Opens the month and fills it in: every employee still working at the end
     * of the month, each on a full month of ordinary hours. An unremarkable
     * month is then one button, not one row of typing per person.
     */
    public function open(Company $company, int $year, int $month): PayrollRun
    {
        return DB::transaction(function () use ($company, $year, $month) {
            $fund = PayrollMonthHours::forMonth($year, $month);

            $run = PayrollRun::create([
                'company_id' => $company->id,
                'year' => $year,
                'month' => $month,
                'status' => PayrollRun::DRAFT,
                'month_hours' => $fund->hours,
                'payroll_parameter_id' => PayrollParameter::forDate(
                    $this->endOfMonth($year, $month)
                )->id,
            ]);

            $asOf = $run->endOfMonth();

            $employees = Employee::where('company_id', $company->id)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get()
                ->filter(fn (Employee $e) => $e->isActiveOn($asOf));

            foreach ($employees as $employee) {
                $runEmployee = PayrollRunEmployee::create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                ]);

                PayrollRunLine::create([
                    'payroll_run_employee_id' => $runEmployee->id,
                    'kind' => PayrollRunLine::KIND_HOURS,
                    'code' => '001',
                    'description' => LineType::label('001'),
                    'hours' => $fund->hours,
                    'percent' => 100,
                    'amount' => 0,
                    'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
                    'is_automatic' => false,
                ]);
            }

            return $this->recalculate($run->fresh());
        });
    }

    /**
     * Recomputes every employee in the run from their lines.
     *
     * Automatic lines are thrown away and rebuilt rather than updated: they are
     * derived, so treating them as stored state is how a stale seniority bonus
     * survives a change to the hours it was derived from.
     */
    public function recalculate(PayrollRun $run): PayrollRun
    {
        if (! $run->isDraft()) {
            throw new RuntimeException('Потврдена пресметка не се пресметува повторно.');
        }

        return DB::transaction(function () use ($run) {
            $parameters = $run->parameter;
            $asOf = $run->endOfMonth();

            foreach ($run->employees()->with(['employee', 'lines'])->get() as $runEmployee) {
                $employee = $runEmployee->employee;
                $salary = $employee->salaryOn($asOf);

                $fullMonthGross = $salary === null
                    ? 0.0
                    : PayrollRunCalculator::fullMonthGross((float) $salary->amount, $salary->basis, $parameters);

                $inputLines = $runEmployee->lines
                    ->reject(fn (PayrollRunLine $line) => $line->is_automatic)
                    ->map(fn (PayrollRunLine $line) => [
                        'kind' => $line->kind,
                        'code' => $line->code,
                        'description' => $line->description,
                        'hours' => $line->hours,
                        'percent' => $line->percent,
                        'amount' => $line->amount,
                        'borne_by' => $line->borne_by,
                    ])
                    ->values()
                    ->all();

                $result = PayrollRunCalculator::calculate(
                    fullMonthGross: $fullMonthGross,
                    monthHours: $run->month_hours,
                    seniorityYears: $employee->seniorityYearsOn($asOf),
                    inputLines: $inputLines,
                    parameters: $parameters,
                );

                $runEmployee->lines()->delete();

                foreach ($result->lines as $line) {
                    PayrollRunLine::create([
                        'payroll_run_employee_id' => $runEmployee->id,
                        'kind' => $line->kind,
                        'code' => $line->code,
                        'description' => $line->description,
                        'hours' => $line->hours,
                        'percent' => $line->percent,
                        'amount' => $line->amount,
                        'borne_by' => $line->borneBy,
                        'is_automatic' => $line->isAutomatic,
                    ]);
                }

                $runEmployee->update([
                    'gross' => $result->gross,
                    'pension' => $result->breakdown->pension,
                    'health' => $result->breakdown->health,
                    'injury' => $result->breakdown->injury,
                    'unemployment' => $result->breakdown->unemployment,
                    'contributions' => $result->breakdown->contributions,
                    'tax_base' => $result->breakdown->taxBase,
                    'tax' => $result->breakdown->tax,
                    'net' => $result->breakdown->net,
                    'deductions_total' => $result->deductionsTotal,
                    'effective_net' => $result->effectiveNet,
                    'top_up_pension' => $result->breakdown->topUpPension,
                    'top_up_health' => $result->breakdown->topUpHealth,
                    'top_up_injury' => $result->breakdown->topUpInjury,
                    'top_up_unemployment' => $result->breakdown->topUpUnemployment,
                    'top_up' => $result->breakdown->topUp,
                    'hourly_rate' => $result->hourlyRate,
                    'seniority_years' => $employee->seniorityYearsOn($asOf),
                    'full_month_gross' => $fullMonthGross,
                ]);
            }

            return $run->fresh(['employees.lines', 'employees.employee']);
        });
    }

    private function endOfMonth(int $year, int $month): string
    {
        return \Carbon\Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
    }
}
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `php artisan test --filter=PayrollRunServiceTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Payroll/PayrollRunService.php tests/Feature/Payroll/PayrollRunServiceTest.php
git commit -m "feat(payroll): open a month and recalculate it from its lines"
```

---

### Task 7: Confirming and reversing

Confirmation posts the entry and locks the run. Returning to draft reverses the entry and unlocks it. Only `employer` lines reach the ledger.

**Files:**
- Modify: `app/Services/Payroll/PayrollRunService.php`
- Test: `tests/Feature/Payroll/PayrollRunPostingTest.php`

**Interfaces:**
- Consumes: `Account`, `JournalEntry`, `JournalGroup` — same lookup pattern as `SalesInvoiceService`.
- Produces: `PayrollRunService::confirm(PayrollRun $run, int $userId): PayrollRun` and `PayrollRunService::returnToDraft(PayrollRun $run, int $userId): PayrollRun`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Payroll/PayrollRunPostingTest.php`:

```php
<?php

namespace Tests\Feature\Payroll;

use App\Models\Account;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollMonthHours;
use App\Models\PayrollParameter;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Models\User;
use App\Services\Payroll\PayrollRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollRunPostingTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        $company = Company::factory()->create();

        foreach (['421' => 'Плата — бруто', '240' => 'Обврски за плата',
            '249' => 'Останати обврски спрема вработените',
            '234' => 'Обврски за придонеси', '235' => 'Персонален данок'] as $code => $name) {
            Account::create(['company_id' => $company->id, 'code' => $code, 'name' => $name]);
        }

        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);

        return $company;
    }

    private function employeeOn(Company $company, float $amount): Employee
    {
        $employee = Employee::factory()->for($company)->create([
            'employed_on' => '2020-01-01', 'prior_service_months' => 0,
        ]);

        EmployeeSalary::create([
            'employee_id' => $employee->id, 'effective_from' => '2020-01-01',
            'amount' => $amount, 'basis' => 'gross',
        ]);

        return $employee;
    }

    public function test_confirming_posts_a_balanced_entry(): void
    {
        $company = $this->company();
        $this->employeeOn($company, 38507);
        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        $run = $service->confirm($service->open($company, 2026, 7), $user->id);

        $this->assertSame(PayrollRun::CONFIRMED, $run->status);
        $this->assertNotNull($run->journal_entry_id);

        $lines = $run->journalEntry->lines;
        $this->assertSame(
            round($lines->sum(fn ($l) => (float) $l->debit), 2),
            round($lines->sum(fn ($l) => (float) $l->credit), 2)
        );
        $this->assertSame('2026-07-31', $run->journalEntry->entry_date->toDateString());
    }

    public function test_the_entry_hits_the_expected_accounts(): void
    {
        $company = $this->company();
        $this->employeeOn($company, 38507);
        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        $run = $service->confirm($service->open($company, 2026, 7), $user->id);

        $codes = $run->journalEntry->lines
            ->map(fn ($l) => Account::find($l->account_id)->code)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['234', '235', '240', '421'], $codes);
    }

    public function test_the_funds_share_never_reaches_the_ledger(): void
    {
        $company = $this->company();
        $this->employeeOn($company, 38400);
        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        $run = $service->open($company, 2026, 7);
        $runEmployee = $run->employees->first();

        // Half the month on the Fund: 92 ordinary hours, 92 on code 129.
        $runEmployee->lines()->update(['hours' => 92]);
        PayrollRunLine::create([
            'payroll_run_employee_id' => $runEmployee->id,
            'kind' => PayrollRunLine::KIND_HOURS, 'code' => '129',
            'description' => 'Боледување на товар на ФЗО', 'hours' => 92,
            'percent' => 100, 'amount' => 0,
            'borne_by' => PayrollRunLine::BORNE_FZO, 'is_automatic' => false,
        ]);

        $run = $service->confirm($service->recalculate($run->fresh()), $user->id);

        $debit = $run->journalEntry->lines->sum(fn ($l) => (float) $l->debit);
        $gross = $run->employees->first()->gross;

        $this->assertSame(round($gross / 2, 2), round($debit, 2));
    }

    public function test_an_employee_wholly_on_the_fund_adds_nothing(): void
    {
        $company = $this->company();
        $this->employeeOn($company, 38507);
        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        $run = $service->open($company, 2026, 7);
        $runEmployee = $run->employees->first();
        $runEmployee->lines()->update([
            'code' => '129', 'borne_by' => PayrollRunLine::BORNE_FZO,
        ]);

        $run = $service->confirm($service->recalculate($run->fresh()), $user->id);

        $this->assertCount(0, $run->journalEntry->lines);
    }

    public function test_a_deduction_reaches_the_ledger_whole(): void
    {
        $company = $this->company();
        $this->employeeOn($company, 38507);
        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        $run = $service->open($company, 2026, 7);
        PayrollRunLine::create([
            'payroll_run_employee_id' => $run->employees->first()->id,
            'kind' => PayrollRunLine::KIND_DEDUCTION, 'code' => null,
            'description' => 'Кредит', 'hours' => null, 'percent' => null,
            'amount' => 5000, 'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
            'is_automatic' => false,
        ]);

        $run = $service->confirm($service->recalculate($run->fresh()), $user->id);

        $deductionLine = $run->journalEntry->lines
            ->first(fn ($l) => Account::find($l->account_id)->code === '249');

        $this->assertSame(5000.0, round((float) $deductionLine->credit, 2));
    }

    public function test_returning_to_draft_reverses_the_entry_and_reopens_the_run(): void
    {
        $company = $this->company();
        $this->employeeOn($company, 38507);
        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        $run = $service->confirm($service->open($company, 2026, 7), $user->id);
        $originalEntryId = $run->journal_entry_id;

        $run = $service->returnToDraft($run, $user->id);

        $this->assertSame(PayrollRun::DRAFT, $run->status);
        $this->assertNull($run->journal_entry_id);
        $this->assertNull($run->confirmed_at);

        // The original entry stays; a mirror of it now cancels it out.
        $original = \App\Models\JournalEntry::find($originalEntryId);
        $reversal = \App\Models\JournalEntry::where('company_id', $company->id)
            ->where('id', '!=', $originalEntryId)
            ->latest('id')
            ->first();

        $this->assertNotNull($reversal);
        $this->assertSame(
            round($original->lines->sum(fn ($l) => (float) $l->debit), 2),
            round($reversal->lines->sum(fn ($l) => (float) $l->credit), 2)
        );
    }
}
```

If `Account::create` needs more columns than `company_id`, `code` and `name`, copy the shape used by an existing test such as `tests/Feature/` sales-invoice posting tests.

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=PayrollRunPostingTest`
Expected: FAIL — `Call to undefined method ...::confirm()`.

- [ ] **Step 3: Add confirm and returnToDraft**

Add to `app/Services/Payroll/PayrollRunService.php` (and add `use App\Models\Account; use App\Models\JournalEntry; use App\Models\JournalGroup;` at the top):

```php
    /**
     * Posts the month and locks it.
     *
     * Only lines the employer bears reach the ledger. The Fund's share is
     * calculated, declared and shown on the payslip, but it is not the
     * company's cost and not its liability — the same parallel track the
     * minimum-base top-up already runs on.
     */
    public function confirm(PayrollRun $run, int $userId): PayrollRun
    {
        if (! $run->isDraft()) {
            throw new RuntimeException('Пресметката е веќе потврдена.');
        }

        return DB::transaction(function () use ($run, $userId) {
            $run = $this->recalculate($run);

            $gross = 0.0;
            $contributions = 0.0;
            $tax = 0.0;
            $deductions = 0.0;
            $net = 0.0;
            $topUp = 0.0;

            foreach ($run->employees as $runEmployee) {
                $share = $runEmployee->gross > 0
                    ? $this->employerGross($runEmployee) / $runEmployee->gross
                    : 0.0;

                if ($share <= 0) {
                    continue;
                }

                $employerGross = $this->employerGross($runEmployee);
                $employerContributions = round($runEmployee->contributions * $share, 2);
                $employerTax = round($runEmployee->tax * $share, 2);

                $gross += $employerGross;
                $contributions += $employerContributions;
                $tax += $employerTax;
                $deductions += $runEmployee->deductions_total;
                $topUp += $runEmployee->top_up;
                $net += round(
                    $employerGross - $employerContributions - $employerTax - $runEmployee->deductions_total,
                    2
                );
            }

            $entry = null;

            if (round($gross + $topUp, 2) > 0) {
                $label = "Плата {$run->month}/{$run->year}";

                $entry = JournalEntry::create([
                    'company_id' => $run->company_id,
                    'journal_group_id' => $this->systemJournalGroup($run->company)->id,
                    'entry_date' => $run->endOfMonth(),
                    'description' => $label,
                    'created_by' => $userId,
                ]);

                $this->line($entry, $run, '421', $label, round($gross + $topUp, 2), 0.0);
                $this->line($entry, $run, '234', $label, 0.0, round($contributions + $topUp, 2));
                $this->line($entry, $run, '235', $label, 0.0, round($tax, 2));
                $this->line($entry, $run, '249', $label, 0.0, round($deductions, 2));
                $this->line($entry, $run, '240', $label, 0.0, round($net, 2));
            }

            $run->update([
                'status' => PayrollRun::CONFIRMED,
                'journal_entry_id' => $entry?->id,
                'confirmed_by' => $userId,
                'confirmed_at' => now(),
            ]);

            return $run->fresh(['employees.lines', 'journalEntry.lines']);
        });
    }

    public function returnToDraft(PayrollRun $run, int $userId): PayrollRun
    {
        if ($run->isDraft()) {
            throw new RuntimeException('Пресметката е веќе нацрт.');
        }

        return DB::transaction(function () use ($run, $userId) {
            $original = $run->journalEntry;

            if ($original !== null) {
                $reversal = JournalEntry::create([
                    'company_id' => $run->company_id,
                    'journal_group_id' => $original->journal_group_id,
                    'entry_date' => $run->endOfMonth(),
                    'description' => "Сторно: {$original->description}",
                    'created_by' => $userId,
                ]);

                foreach ($original->lines as $line) {
                    $reversal->lines()->create([
                        'account_id' => $line->account_id,
                        'description' => $line->description,
                        'line_date' => $line->line_date,
                        'debit' => $line->credit,
                        'credit' => $line->debit,
                    ]);
                }
            }

            $run->update([
                'status' => PayrollRun::DRAFT,
                'journal_entry_id' => null,
                'confirmed_by' => null,
                'confirmed_at' => null,
            ]);

            return $run->fresh(['employees.lines']);
        });
    }

    private function employerGross(PayrollRunEmployee $runEmployee): float
    {
        return round(
            $runEmployee->lines
                ->where('kind', '!=', PayrollRunLine::KIND_DEDUCTION)
                ->where('borne_by', PayrollRunLine::BORNE_EMPLOYER)
                ->sum('amount'),
            2
        );
    }

    /** Zero-value lines are skipped: an empty row in the ledger is noise. */
    private function line(JournalEntry $entry, PayrollRun $run, string $code, string $label, float $debit, float $credit): void
    {
        if (round($debit, 2) <= 0 && round($credit, 2) <= 0) {
            return;
        }

        $entry->lines()->create([
            'account_id' => $this->account($run->company, $code)->id,
            'description' => $label,
            'line_date' => $run->endOfMonth(),
            'debit' => number_format($debit, 2, '.', ''),
            'credit' => number_format($credit, 2, '.', ''),
        ]);
    }

    private function account(Company $company, string $code): Account
    {
        return Account::where('company_id', $company->id)->where('code', $code)->firstOrFail();
    }

    private function systemJournalGroup(Company $company): JournalGroup
    {
        return JournalGroup::firstOrCreate(
            ['company_id' => $company->id, 'code' => '99'],
            ['name' => 'Автоматски (фактури)', 'sort_order' => 99]
        );
    }
```

In `recalculate()`, make the `employees()` eager load include lines so `employerGross()` can read them without a query per employee — it already does via `->with(['employee', 'lines'])`.

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `php artisan test --filter=PayrollRunPostingTest`
Expected: PASS, 6 tests.

If the balance test fails by a denar, the fault is in `confirm()` recomputing a figure the calculator already produced — the credit to 240 must be the *remainder*, never an independently rounded number.

- [ ] **Step 5: Run the whole suite**

Run: `php artisan test`
Expected: PASS. Note the count; it should be the 5a total plus everything added so far.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Payroll/PayrollRunService.php tests/Feature/Payroll/PayrollRunPostingTest.php
git commit -m "feat(payroll): post the employer's share on confirmation and reverse it on reopening"
```

---

### Task 8: Menu, routes and the closed door

`'plata-mpin'` becomes real. The client must not reach it — and the point of this task is that the URL is closed, not just the menu item.

**Files:**
- Modify: `app/Support/Menu.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Payroll/PayrollAccessTest.php`, `tests/Feature/MenuTest.php`

**Interfaces:**
- Consumes: `EnsureAccountingAccess` middleware, `PayrollRunIndex` and `PayrollRunShow` (created in Tasks 9 and 10).
- Produces: routes `payroll-runs.index` and `payroll-runs.show`, both scoped to `companies/{company}`.

Because the route targets do not exist until Tasks 9 and 10, this task creates them as minimal placeholder components and the later tasks fill them in. That order is deliberate: the access test is what this task is for, and it needs something to point at.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Payroll/PayrollAccessTest.php`:

```php
<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayrollAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'client'] as $role) {
            Role::findOrCreate($role);
        }
    }

    private function userWith(string $role, Company $company): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->companies()->attach($company);

        return $user;
    }

    public function test_a_client_cannot_reach_the_payroll_run_url(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->userWith('client', $company))
            ->get(route('payroll-runs.index', $company))
            ->assertForbidden();
    }

    public function test_an_accountant_can(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->userWith('accountant', $company))
            ->get(route('payroll-runs.index', $company))
            ->assertOk();
    }

    public function test_an_admin_can(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->userWith('admin', $company))
            ->get(route('payroll-runs.index', $company))
            ->assertOk();
    }
}
```

If attaching a user to a company is done differently in this codebase, copy the helper from `tests/Feature/EmployeeAccessTest.php` — that file already solves exactly this.

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=PayrollAccessTest`
Expected: FAIL — route `payroll-runs.index` is not defined.

- [ ] **Step 3: Create placeholder components so the routes resolve**

`app/Livewire/Payroll/PayrollRunIndex.php`:

```php
<?php

namespace App\Livewire\Payroll;

use App\Models\Company;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PayrollRunIndex extends Component
{
    public Company $company;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        return view('livewire.payroll.payroll-run-index');
    }
}
```

`resources/views/livewire/payroll/payroll-run-index.blade.php`:

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800">Плата — {{ $company->name }}</h1>
</div>
```

`app/Livewire/Payroll/PayrollRunShow.php`:

```php
<?php

namespace App\Livewire\Payroll;

use App\Models\Company;
use App\Models\PayrollRun;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PayrollRunShow extends Component
{
    public Company $company;

    public PayrollRun $run;

    public function mount(Company $company, PayrollRun $run): void
    {
        Gate::authorize('view', $company);
        abort_unless($run->company_id === $company->id, 404);

        $this->company = $company;
        $this->run = $run;
    }

    public function render()
    {
        return view('livewire.payroll.payroll-run-show');
    }
}
```

`resources/views/livewire/payroll/payroll-run-show.blade.php`:

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800">Пресметка {{ $run->month }}/{{ $run->year }}</h1>
</div>
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, add the imports `use App\Livewire\Payroll\PayrollRunIndex;` and `use App\Livewire\Payroll\PayrollRunShow;`, and add this group next to the `payroll-parameters.` group:

```php
// EnsureAccountingAccess, not a policy: payroll is the firm's work, not the
// client's, and a group-level gate covers screens added later by default
// instead of by remembering.
Route::middleware(['auth', EnsureAccountingAccess::class])->prefix('companies/{company}')->name('payroll-runs.')->group(function () {
    Route::get('/payroll-runs', [PayrollRunIndex::class, '__invoke'])->name('index');
    Route::get('/payroll-runs/{run}', [PayrollRunShow::class, '__invoke'])->name('show');
});
```

`EnsureAccountingAccess` is already imported in this file for the `accounting.` group; if not, add `use App\Http\Middleware\EnsureAccountingAccess;`.

- [ ] **Step 5: Make the menu item real**

In `app/Support/Menu.php`, remove the `'plata-mpin'` entry from `SOON_FEATURES` and replace `self::soon($company, 'plata-mpin')` in the payroll group with:

```php
['label' => 'Плата (МПИН)', 'url' => route('payroll-runs.index', $company), 'pattern' => 'payroll-runs.*', 'roles' => ['admin', 'accountant']],
```

- [ ] **Step 6: Update MenuTest**

`tests/Feature/MenuTest.php` asserts on the "soon" features. Find the assertions naming `plata-mpin` and change them so that the item is expected as a real link for admin and accountant and absent for a client. Run the file to see exactly which assertions break rather than guessing:

Run: `php artisan test --filter=MenuTest`

- [ ] **Step 7: Run the tests and make sure they pass**

Run: `php artisan test --filter="PayrollAccessTest|MenuTest"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Payroll resources/views/livewire/payroll app/Support/Menu.php routes/web.php tests/Feature/Payroll/PayrollAccessTest.php tests/Feature/MenuTest.php
git commit -m "feat(payroll): real routes for the payroll run, closed to clients server-side"
```

---

### Task 9: The list of runs

**Files:**
- Modify: `app/Livewire/Payroll/PayrollRunIndex.php`
- Modify: `resources/views/livewire/payroll/payroll-run-index.blade.php`
- Test: `tests/Feature/Payroll/PayrollRunIndexTest.php`

**Interfaces:**
- Consumes: `PayrollRunService::open()`, `WorkingYear::for()`.
- Produces: a `createRun(int $month)` action that opens a run and redirects to `payroll-runs.show`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Payroll/PayrollRunIndexTest.php`:

```php
<?php

namespace Tests\Feature\Payroll;

use App\Livewire\Payroll\PayrollRunIndex;
use App\Models\Company;
use App\Models\PayrollMonthHours;
use App\Models\PayrollParameter;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\WorkingYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayrollRunIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
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

    private function parameter(): PayrollParameter
    {
        return PayrollParameter::forDate('2026-01-31');
    }

    public function test_it_lists_the_runs_of_the_working_year(): void
    {
        $company = Company::factory()->create();
        $parameter = $this->parameter();
        $this->admin();
        WorkingYear::set($company, 2026);

        PayrollRun::create([
            'company_id' => $company->id, 'year' => 2026, 'month' => 7,
            'status' => PayrollRun::DRAFT, 'month_hours' => 184,
            'payroll_parameter_id' => $parameter->id,
        ]);

        PayrollRun::create([
            'company_id' => $company->id, 'year' => 2025, 'month' => 3,
            'status' => PayrollRun::DRAFT, 'month_hours' => 168,
            'payroll_parameter_id' => $parameter->id,
        ]);

        Livewire::test(PayrollRunIndex::class, ['company' => $company])
            ->assertSee('Јули')
            ->assertDontSee('Март');
    }

    public function test_it_does_not_list_another_companys_runs(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $parameter = $this->parameter();
        $this->admin();
        WorkingYear::set($company, 2026);

        PayrollRun::create([
            'company_id' => $other->id, 'year' => 2026, 'month' => 5,
            'status' => PayrollRun::DRAFT, 'month_hours' => 168,
            'payroll_parameter_id' => $parameter->id,
        ]);

        Livewire::test(PayrollRunIndex::class, ['company' => $company])
            ->assertDontSee('Мај');
    }

    public function test_it_opens_a_new_month(): void
    {
        $company = Company::factory()->create();
        $this->parameter();
        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);
        $this->admin();
        WorkingYear::set($company, 2026);

        Livewire::test(PayrollRunIndex::class, ['company' => $company])
            ->set('newMonth', 7)
            ->call('createRun')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('payroll_runs', [
            'company_id' => $company->id, 'year' => 2026, 'month' => 7,
        ]);
    }

    public function test_it_reports_a_missing_hour_fund_as_a_form_error(): void
    {
        $company = Company::factory()->create();
        $this->parameter();
        $this->admin();
        WorkingYear::set($company, 2026);

        Livewire::test(PayrollRunIndex::class, ['company' => $company])
            ->set('newMonth', 9)
            ->call('createRun')
            ->assertHasErrors('newMonth');
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=PayrollRunIndexTest`
Expected: FAIL — `newMonth` is not a property of the component.

- [ ] **Step 3: Write the component**

Replace `app/Livewire/Payroll/PayrollRunIndex.php` with:

```php
<?php

namespace App\Livewire\Payroll;

use App\Models\Company;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollRunService;
use App\Support\WorkingYear;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

#[Layout('layouts.app')]
class PayrollRunIndex extends Component
{
    public Company $company;

    public ?int $newMonth = null;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function createRun(PayrollRunService $service): mixed
    {
        $this->validate([
            'newMonth' => ['required', 'integer', 'min:1', 'max:12'],
        ], attributes: ['newMonth' => 'месец']);

        $year = WorkingYear::for($this->company);

        // A missing hour fund and a month opened twice are both ordinary
        // mistakes, not faults — they belong on the field, not in a 500.
        try {
            $run = $service->open($this->company, $year, $this->newMonth);
        } catch (RuntimeException $e) {
            $this->addError('newMonth', $e->getMessage());

            return null;
        } catch (\Illuminate\Database\QueryException $e) {
            $this->addError('newMonth', 'За тој месец веќе постои пресметка.');

            return null;
        }

        return $this->redirect(route('payroll-runs.show', [$this->company, $run]), navigate: true);
    }

    public function render()
    {
        $year = WorkingYear::for($this->company);

        $runs = PayrollRun::where('company_id', $this->company->id)
            ->where('year', $year)
            ->withCount('employees')
            ->with('employees')
            ->orderBy('month')
            ->get();

        return view('livewire.payroll.payroll-run-index', [
            'runs' => $runs,
            'year' => $year,
        ]);
    }
}
```

- [ ] **Step 4: Write the view**

Replace `resources/views/livewire/payroll/payroll-run-index.blade.php` with:

```blade
<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Плата — {{ $company->name }}</h1>
        <div class="flex items-end gap-2">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Нова пресметка за {{ $year }}</label>
                <select wire:model="newMonth" class="rounded border-gray-300 text-sm">
                    <option value="">Избери месец</option>
                    @foreach (['Јануари', 'Февруари', 'Март', 'Април', 'Мај', 'Јуни', 'Јули', 'Август', 'Септември', 'Октомври', 'Ноември', 'Декември'] as $i => $name)
                        <option value="{{ $i + 1 }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <button wire:click="createRun" class="rounded bg-brand px-3 py-2 text-sm text-white">Отвори</button>
        </div>
    </div>

    @error('newMonth') <p class="text-sm text-red-600 mb-4">{{ $message }}</p> @enderror

    <x-card padding="p-0" class="overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-1 px-3">Месец</th>
                    <th class="py-1 px-3">Вработени</th>
                    <th class="py-1 px-3 text-right">Бруто</th>
                    <th class="py-1 px-3 text-right">За исплата</th>
                    <th class="py-1 px-3">Статус</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($runs as $run)
                    <tr class="text-sm hover:bg-orange-50">
                        <td class="py-1 px-3">
                            <a href="{{ route('payroll-runs.show', [$company, $run]) }}" class="text-brand hover:underline font-medium">{{ $run->monthName() }}</a>
                        </td>
                        <td class="py-1 px-3">{{ $run->employees_count }}</td>
                        <td class="py-1 px-3 text-right">{{ number_format($run->employees->sum('gross'), 2, ',', '.') }}</td>
                        <td class="py-1 px-3 text-right">{{ number_format($run->employees->sum('effective_net'), 2, ',', '.') }}</td>
                        <td class="py-1 px-3">
                            @if ($run->isDraft())
                                <span class="text-xs font-medium text-gray-600 bg-gray-100 rounded-full px-2 py-0.5">Нацрт</span>
                            @else
                                <span class="text-xs font-medium text-green-700 bg-green-100 rounded-full px-2 py-0.5">Потврдена</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-4 px-3 text-sm text-gray-400">Нема пресметки за {{ $year }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>
</div>
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `php artisan test --filter=PayrollRunIndexTest`
Expected: PASS, 4 tests.

- [ ] **Step 6: Run the density test**

Run: `php artisan test --filter=TableDensityTest`
Expected: PASS — the new table uses `py-1 px-3` and `hover:bg-orange-50`.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Payroll/PayrollRunIndex.php resources/views/livewire/payroll/payroll-run-index.blade.php tests/Feature/Payroll/PayrollRunIndexTest.php
git commit -m "feat(payroll): list of monthly runs with a month opener"
```

---

### Task 10: The run screen and its line editor

**Files:**
- Modify: `app/Livewire/Payroll/PayrollRunShow.php`
- Modify: `resources/views/livewire/payroll/payroll-run-show.blade.php`
- Test: `tests/Feature/Payroll/PayrollRunShowTest.php`

**Interfaces:**
- Consumes: `PayrollRunService::recalculate()`, `::confirm()`, `::returnToDraft()`, `LineType::OFFERED`.
- Produces: actions `selectEmployee(int $id)`, `addLine()`, `saveLine()`, `deleteLine(int $id)`, `confirm()`, `returnToDraft()`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Payroll/PayrollRunShowTest.php`:

```php
<?php

namespace Tests\Feature\Payroll;

use App\Livewire\Payroll\PayrollRunShow;
use App\Models\Account;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollMonthHours;
use App\Models\PayrollParameter;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Models\User;
use App\Services\Payroll\PayrollRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayrollRunShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
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

    private function openRun(): PayrollRun
    {
        $company = Company::factory()->create();

        foreach (['421', '240', '249', '234', '235'] as $code) {
            Account::create(['company_id' => $company->id, 'code' => $code, 'name' => "Конто {$code}"]);
        }

        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);

        $employee = Employee::factory()->for($company)->create([
            'first_name' => 'Ана', 'last_name' => 'Николовска',
            'employed_on' => '2020-01-01', 'prior_service_months' => 0,
        ]);

        EmployeeSalary::create([
            'employee_id' => $employee->id, 'effective_from' => '2020-01-01',
            'amount' => 38507, 'basis' => 'gross',
        ]);

        return app(PayrollRunService::class)->open($company, 2026, 7);
    }

    public function test_it_shows_every_employee_with_their_figures(): void
    {
        $run = $this->openRun();
        $this->admin();

        Livewire::test(PayrollRunShow::class, ['company' => $run->company, 'run' => $run])
            ->assertSee('Николовска')
            ->assertSee('26.046');
    }

    public function test_adding_a_deduction_lowers_the_amount_for_payment(): void
    {
        $run = $this->openRun();
        $this->admin();
        $runEmployeeId = $run->employees->first()->id;

        Livewire::test(PayrollRunShow::class, ['company' => $run->company, 'run' => $run])
            ->call('selectEmployee', $runEmployeeId)
            ->set('lineKind', PayrollRunLine::KIND_DEDUCTION)
            ->set('lineDescription', 'Кредит')
            ->set('lineAmount', 5000)
            ->call('saveLine')
            ->assertHasNoErrors();

        $this->assertSame(
            21046,
            (int) round($run->fresh()->employees->first()->effective_net)
        );
    }

    public function test_it_refuses_fractional_hours(): void
    {
        $run = $this->openRun();
        $this->admin();

        Livewire::test(PayrollRunShow::class, ['company' => $run->company, 'run' => $run])
            ->call('selectEmployee', $run->employees->first()->id)
            ->set('lineKind', PayrollRunLine::KIND_HOURS)
            ->set('lineCode', '005')
            ->set('lineHours', '7.5')
            ->set('linePercent', 135)
            ->call('saveLine')
            ->assertHasErrors('lineHours');
    }

    public function test_the_automatic_line_cannot_be_deleted(): void
    {
        $run = $this->openRun();
        $run->employees->first()->employee->update(['employed_on' => '2006-07-01']);
        app(PayrollRunService::class)->recalculate($run->fresh());
        $this->admin();

        $automatic = $run->fresh()->employees->first()->lines->firstWhere('is_automatic', true);
        $this->assertNotNull($automatic);

        Livewire::test(PayrollRunShow::class, ['company' => $run->company, 'run' => $run])
            ->call('selectEmployee', $run->employees->first()->id)
            ->call('deleteLine', $automatic->id)
            ->assertHasErrors('lineKind');

        $this->assertDatabaseHas('payroll_run_lines', ['id' => $automatic->id]);
    }

    public function test_confirming_locks_the_run(): void
    {
        $run = $this->openRun();
        $this->admin();

        Livewire::test(PayrollRunShow::class, ['company' => $run->company, 'run' => $run])
            ->call('confirm');

        $this->assertSame(PayrollRun::CONFIRMED, $run->fresh()->status);
    }

    public function test_a_confirmed_run_refuses_edits(): void
    {
        $run = $this->openRun();
        $user = $this->admin();
        app(PayrollRunService::class)->confirm($run, $user->id);

        Livewire::test(PayrollRunShow::class, ['company' => $run->company, 'run' => $run->fresh()])
            ->call('selectEmployee', $run->employees->first()->id)
            ->set('lineKind', PayrollRunLine::KIND_DEDUCTION)
            ->set('lineDescription', 'Доцна')
            ->set('lineAmount', 100)
            ->call('saveLine')
            ->assertHasErrors('lineKind');
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=PayrollRunShowTest`
Expected: FAIL — `selectEmployee` is not a method of the component.

- [ ] **Step 3: Write the component**

Replace `app/Livewire/Payroll/PayrollRunShow.php` with:

```php
<?php

namespace App\Livewire\Payroll;

use App\Models\Company;
use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use App\Models\PayrollRunLine;
use App\Services\Payroll\PayrollRunService;
use App\Support\Payroll\LineType;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PayrollRunShow extends Component
{
    public Company $company;

    public PayrollRun $run;

    public ?int $selectedEmployeeId = null;

    public string $lineKind = PayrollRunLine::KIND_HOURS;

    public ?string $lineCode = '001';

    public string $lineDescription = '';

    public ?string $lineHours = null;

    public ?string $linePercent = null;

    public ?string $lineAmount = null;

    public function mount(Company $company, PayrollRun $run): void
    {
        Gate::authorize('view', $company);
        abort_unless($run->company_id === $company->id, 404);

        $this->company = $company;
        $this->run = $run;
    }

    public function selectEmployee(int $id): void
    {
        $this->selectedEmployeeId = $id;
        $this->resetLineForm();
    }

    /** Keeps the percent in step with the chosen code without overriding a typed one. */
    public function updatedLineCode(?string $value): void
    {
        if ($value !== null && isset(LineType::OFFERED[$value])) {
            $this->linePercent = (string) LineType::defaultPercent($value);
            $this->lineKind = LineType::OFFERED[$value]['kind'];
            $this->lineDescription = LineType::label($value);
        }
    }

    public function saveLine(PayrollRunService $service): void
    {
        if (! $this->guardDraft()) {
            return;
        }

        $rules = [
            'lineKind' => ['required', 'in:hours,amount,deduction'],
            'lineDescription' => ['required', 'string', 'max:255'],
        ];

        if ($this->lineKind === PayrollRunLine::KIND_HOURS) {
            // integer, not numeric: BrojCasovi is xs:int in mpin.xsd and a
            // fractional hour would only fail in 5c, at УЈП.
            $rules['lineCode'] = ['required', 'string'];
            $rules['lineHours'] = ['required', 'integer', 'min:0', 'max:744'];
            $rules['linePercent'] = ['required', 'numeric', 'min:0', 'max:500'];
        } else {
            $rules['lineAmount'] = ['required', 'numeric', 'min:0'];
        }

        $this->validate($rules, attributes: [
            'lineKind' => 'вид', 'lineCode' => 'шифра', 'lineDescription' => 'опис',
            'lineHours' => 'часови', 'linePercent' => 'процент', 'lineAmount' => 'износ',
        ]);

        $runEmployee = $this->selectedEmployee();

        PayrollRunLine::create([
            'payroll_run_employee_id' => $runEmployee->id,
            'kind' => $this->lineKind,
            'code' => $this->lineKind === PayrollRunLine::KIND_DEDUCTION ? null : $this->lineCode,
            'description' => $this->lineDescription,
            'hours' => $this->lineKind === PayrollRunLine::KIND_HOURS ? (int) $this->lineHours : null,
            'percent' => $this->lineKind === PayrollRunLine::KIND_HOURS ? (float) $this->linePercent : null,
            'amount' => $this->lineKind === PayrollRunLine::KIND_HOURS ? 0 : (float) $this->lineAmount,
            'borne_by' => $this->lineKind === PayrollRunLine::KIND_DEDUCTION
                ? PayrollRunLine::BORNE_EMPLOYER
                : LineType::borneBy($this->lineCode),
            'is_automatic' => false,
        ]);

        $this->run = $service->recalculate($this->run->fresh());
        $this->resetLineForm();
    }

    public function deleteLine(int $id, PayrollRunService $service): void
    {
        if (! $this->guardDraft()) {
            return;
        }

        $line = PayrollRunLine::findOrFail($id);

        if ($line->is_automatic) {
            $this->addError('lineKind', 'Минатиот труд се пресметува автоматски и не се брише.');

            return;
        }

        $line->delete();

        $this->run = $service->recalculate($this->run->fresh());
    }

    public function confirm(PayrollRunService $service): void
    {
        if (! $this->guardDraft()) {
            return;
        }

        $this->run = $service->confirm($this->run, (int) auth()->id());
    }

    public function returnToDraft(PayrollRunService $service): void
    {
        if ($this->run->isDraft()) {
            return;
        }

        $this->run = $service->returnToDraft($this->run, (int) auth()->id());
    }

    private function guardDraft(): bool
    {
        if ($this->run->isDraft()) {
            return true;
        }

        $this->addError('lineKind', 'Потврдена пресметка не се менува. Прво врати ја во нацрт.');

        return false;
    }

    private function selectedEmployee(): PayrollRunEmployee
    {
        return PayrollRunEmployee::where('payroll_run_id', $this->run->id)
            ->whereKey($this->selectedEmployeeId)
            ->firstOrFail();
    }

    private function resetLineForm(): void
    {
        $this->lineKind = PayrollRunLine::KIND_HOURS;
        $this->lineCode = '001';
        $this->lineDescription = LineType::label('001');
        $this->lineHours = null;
        $this->linePercent = '100';
        $this->lineAmount = null;
    }

    public function render()
    {
        $rows = $this->run->employees()->with(['employee', 'lines'])->get()
            ->sortBy(fn (PayrollRunEmployee $e) => $e->employee->last_name)
            ->values();

        $selected = $this->selectedEmployeeId === null
            ? null
            : $rows->firstWhere('id', $this->selectedEmployeeId);

        return view('livewire.payroll.payroll-run-show', [
            'rows' => $rows,
            'selected' => $selected,
            'offered' => LineType::OFFERED,
        ]);
    }
}
```

- [ ] **Step 4: Write the view**

Replace `resources/views/livewire/payroll/payroll-run-show.blade.php` with:

```blade
<div>
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">{{ $run->monthName() }} {{ $run->year }} — {{ $company->name }}</h1>
            <p class="text-sm text-gray-500">Фонд на часови: {{ $run->month_hours }}</p>
        </div>
        <div class="flex items-center gap-2">
            {{-- The two PDF links belong here, but the routes do not exist until
                 Task 11. Task 11 adds them; adding them now would make every
                 test in this task fail on RouteNotFoundException. --}}
            @if ($run->isDraft())
                <button wire:click="confirm" class="rounded bg-brand px-3 py-2 text-sm text-white">Потврди</button>
            @else
                <span class="text-xs font-medium text-green-700 bg-green-100 rounded-full px-2 py-0.5">Потврдена</span>
                <button wire:click="returnToDraft" class="rounded border border-gray-300 px-3 py-2 text-sm">Врати во нацрт</button>
            @endif
        </div>
    </div>

    @error('lineKind') <p class="text-sm text-red-600 mb-4">{{ $message }}</p> @enderror

    <x-card padding="p-0" class="overflow-hidden mb-6">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-1 px-3">Вработен</th>
                    <th class="py-1 px-3 text-right">Часови</th>
                    <th class="py-1 px-3 text-right">Бруто</th>
                    <th class="py-1 px-3 text-right">Придонеси</th>
                    <th class="py-1 px-3 text-right">Данок</th>
                    <th class="py-1 px-3 text-right">Задршки</th>
                    <th class="py-1 px-3 text-right">За исплата</th>
                    <th class="py-1 px-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($rows as $row)
                    @php $hours = $row->lines->sum('hours'); @endphp
                    <tr class="text-sm hover:bg-orange-50">
                        <td class="py-1 px-3">
                            <button wire:click="selectEmployee({{ $row->id }})" class="text-brand hover:underline font-medium">{{ $row->employee->full_name }}</button>
                        </td>
                        <td class="py-1 px-3 text-right">
                            {{ $hours }}
                            @if ($hours != $run->month_hours)
                                <span class="text-xs text-amber-700 bg-amber-100 rounded-full px-2 py-0.5">не го затвора фондот</span>
                            @endif
                        </td>
                        <td class="py-1 px-3 text-right">{{ number_format($row->gross, 2, ',', '.') }}</td>
                        <td class="py-1 px-3 text-right">{{ number_format($row->contributions, 2, ',', '.') }}</td>
                        <td class="py-1 px-3 text-right">{{ number_format($row->tax, 2, ',', '.') }}</td>
                        <td class="py-1 px-3 text-right">{{ number_format($row->deductions_total, 2, ',', '.') }}</td>
                        <td class="py-1 px-3 text-right font-medium">{{ number_format($row->effective_net, 2, ',', '.') }}</td>
                        <td class="py-1 px-3 text-right">{{-- Task 11 puts the payslip link here --}}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-gray-50 text-sm font-medium">
                <tr>
                    <td class="py-1 px-3" colspan="2">Вкупно</td>
                    <td class="py-1 px-3 text-right">{{ number_format($rows->sum('gross'), 2, ',', '.') }}</td>
                    <td class="py-1 px-3 text-right">{{ number_format($rows->sum('contributions'), 2, ',', '.') }}</td>
                    <td class="py-1 px-3 text-right">{{ number_format($rows->sum('tax'), 2, ',', '.') }}</td>
                    <td class="py-1 px-3 text-right">{{ number_format($rows->sum('deductions_total'), 2, ',', '.') }}</td>
                    <td class="py-1 px-3 text-right">{{ number_format($rows->sum('effective_net'), 2, ',', '.') }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </x-card>

    @if ($selected)
        <x-card>
            <h2 class="text-lg font-semibold text-gray-800 mb-3">Ставки — {{ $selected->employee->full_name }}</h2>

            <table class="min-w-full divide-y divide-gray-200 mb-4">
                <thead>
                    <tr class="text-left text-sm text-gray-500 bg-gray-50">
                        <th class="py-1 px-3">Ставка</th>
                        <th class="py-1 px-3 text-right">Часови</th>
                        <th class="py-1 px-3 text-right">%</th>
                        <th class="py-1 px-3 text-right">Износ</th>
                        <th class="py-1 px-3">Товар</th>
                        <th class="py-1 px-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($selected->lines as $line)
                        <tr class="text-sm hover:bg-orange-50">
                            <td class="py-1 px-3">{{ $line->description }}</td>
                            <td class="py-1 px-3 text-right">{{ $line->hours }}</td>
                            <td class="py-1 px-3 text-right">{{ $line->percent ? number_format($line->percent, 0) : '' }}</td>
                            <td class="py-1 px-3 text-right">{{ number_format($line->amount, 2, ',', '.') }}</td>
                            <td class="py-1 px-3">{{ $line->borne_by === 'fzo' ? 'ФЗО' : 'Работодавач' }}</td>
                            <td class="py-1 px-3 text-right">
                                @if (! $line->is_automatic && $run->isDraft())
                                    <button wire:click="deleteLine({{ $line->id }})" class="text-red-600 hover:underline text-xs">Избриши</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($run->isDraft())
                <div class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
                    <div class="md:col-span-2">
                        <label class="block text-xs text-gray-500 mb-1">Ставка</label>
                        <select wire:model.live="lineCode" class="w-full rounded border-gray-300 text-sm">
                            @foreach ($offered as $code => $type)
                                <option value="{{ $code }}">{{ $type['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Вид</label>
                        <select wire:model.live="lineKind" class="w-full rounded border-gray-300 text-sm">
                            <option value="hours">Часови</option>
                            <option value="amount">Износ</option>
                            <option value="deduction">Задршка</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Часови</label>
                        <input type="number" step="1" wire:model="lineHours" class="w-full rounded border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Процент</label>
                        <input type="number" step="0.01" wire:model="linePercent" class="w-full rounded border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Износ</label>
                        <input type="number" step="0.01" wire:model="lineAmount" class="w-full rounded border-gray-300 text-sm">
                    </div>
                    <div class="md:col-span-5">
                        <label class="block text-xs text-gray-500 mb-1">Опис</label>
                        <input type="text" wire:model="lineDescription" class="w-full rounded border-gray-300 text-sm">
                    </div>
                    <div>
                        <button wire:click="saveLine" class="w-full rounded bg-brand px-3 py-2 text-sm text-white">Додади</button>
                    </div>
                </div>

                @error('lineHours') <p class="text-sm text-red-600 mt-2">{{ $message }}</p> @enderror
                @error('lineAmount') <p class="text-sm text-red-600 mt-2">{{ $message }}</p> @enderror
                @error('lineDescription') <p class="text-sm text-red-600 mt-2">{{ $message }}</p> @enderror
            @endif
        </x-card>
    @endif
</div>
```

The two placeholder comments where the PDF links belong are deliberate. `route()` throws on an unknown name, so a link written before Task 11 defines the route would fail every test in this task. Task 11 replaces both comments once the routes exist.

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `php artisan test --filter=PayrollRunShowTest`
Expected: PASS, 6 tests.

- [ ] **Step 6: Run the density test**

Run: `php artisan test --filter=TableDensityTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Payroll/PayrollRunShow.php resources/views/livewire/payroll/payroll-run-show.blade.php tests/Feature/Payroll/PayrollRunShowTest.php
git commit -m "feat(payroll): run screen with a per-employee line editor"
```

---

### Task 11: The two PDFs

**Files:**
- Create: `app/Http/Controllers/PayslipPdfController.php`, `app/Http/Controllers/PayrollRecapPdfController.php`
- Create: `resources/views/pdf/payslip.blade.php`, `resources/views/pdf/payroll-recap.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/livewire/payroll/payroll-run-show.blade.php` (the two link placeholders Task 10 left)
- Test: `tests/Feature/Payroll/PayrollPdfTest.php`

**Interfaces:**
- Consumes: `PayrollRun`, `PayrollRunEmployee`, `PayrollRunLine`.
- Produces: routes `payroll.payslip-pdf` (company, run, runEmployee) and `payroll.recap-pdf` (company, run).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Payroll/PayrollPdfTest.php`:

```php
<?php

namespace Tests\Feature\Payroll;

use App\Models\Account;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollMonthHours;
use App\Models\PayrollParameter;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\PayrollRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayrollPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'client'] as $role) {
            Role::findOrCreate($role);
        }
    }

    private function openRun(Company $company): PayrollRun
    {
        foreach (['421', '240', '249', '234', '235'] as $code) {
            Account::create(['company_id' => $company->id, 'code' => $code, 'name' => "Конто {$code}"]);
        }

        PayrollMonthHours::create(['year' => 2026, 'month' => 7, 'hours' => 184]);

        $employee = Employee::factory()->for($company)->create([
            'first_name' => 'Ана', 'last_name' => 'Николовска',
            'employed_on' => '2020-01-01', 'prior_service_months' => 0,
        ]);

        EmployeeSalary::create([
            'employee_id' => $employee->id, 'effective_from' => '2020-01-01',
            'amount' => 38507, 'basis' => 'gross',
        ]);

        return app(PayrollRunService::class)->open($company, 2026, 7);
    }

    private function admin(Company $company): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->companies()->attach($company);

        return $admin;
    }

    public function test_the_payslip_renders(): void
    {
        $company = Company::factory()->create();
        $run = $this->openRun($company);

        $response = $this->actingAs($this->admin($company))
            ->get(route('payroll.payslip-pdf', [$company, $run, $run->employees->first()]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_recap_renders(): void
    {
        $company = Company::factory()->create();
        $run = $this->openRun($company);

        $response = $this->actingAs($this->admin($company))
            ->get(route('payroll.recap-pdf', [$company, $run]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_client_cannot_download_a_payslip(): void
    {
        $company = Company::factory()->create();
        $run = $this->openRun($company);

        $client = User::factory()->create();
        $client->assignRole('client');
        $client->companies()->attach($company);

        $this->actingAs($client)
            ->get(route('payroll.recap-pdf', [$company, $run]))
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=PayrollPdfTest`
Expected: FAIL — route `payroll.payslip-pdf` is not defined.

- [ ] **Step 3: Write the payslip controller**

`app/Http/Controllers/PayslipPdfController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class PayslipPdfController extends Controller
{
    public function __invoke(Company $company, PayrollRun $run, PayrollRunEmployee $runEmployee): Response
    {
        Gate::authorize('view', $company);
        abort_unless($run->company_id === $company->id, 404);
        abort_unless($runEmployee->payroll_run_id === $run->id, 404);

        $runEmployee->load(['employee', 'lines']);

        $pdf = Pdf::loadView('pdf.payslip', [
            'company' => $company,
            'run' => $run,
            'runEmployee' => $runEmployee,
        ]);

        return $pdf->stream("isplatna-lista-{$run->year}-{$run->month}-{$runEmployee->employee->last_name}.pdf");
    }
}
```

Check an existing controller such as `app/Http/Controllers/StockOnHandPdfController.php` for the exact `Pdf` facade import and whether the project calls `stream()` or `download()`; match it rather than the above if they differ.

- [ ] **Step 4: Write the payslip view**

`resources/views/pdf/payslip.blade.php`. **Tables only — dompdf has no flexbox.**

```blade
<!DOCTYPE html>
<html lang="mk">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 15px; margin: 0 0 2px 0; }
        .muted { color: #666; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border-bottom: 1px solid #ddd; padding: 4px 6px; text-align: left; }
        th { background: #f5f5f5; font-weight: bold; }
        .right { text-align: right; }
        .total td { border-top: 2px solid #333; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Исплатна листа</h1>
    <div class="muted">
        {{ $company->name }} — {{ $run->monthName() }} {{ $run->year }}<br>
        {{ $runEmployee->employee->full_name }}, ЕМБГ {{ $runEmployee->employee->embg }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Ставка</th>
                <th class="right">Часови</th>
                <th class="right">%</th>
                <th class="right">Износ</th>
                <th>Товар</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($runEmployee->lines->where('kind', '!=', 'deduction') as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="right">{{ $line->hours }}</td>
                    <td class="right">{{ $line->percent ? number_format($line->percent, 0) : '' }}</td>
                    <td class="right">{{ number_format($line->amount, 2, ',', '.') }}</td>
                    <td>{{ $line->borne_by === 'fzo' ? 'ФЗО' : 'Работодавач' }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="3">Бруто</td>
                <td class="right">{{ number_format($runEmployee->gross, 2, ',', '.') }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <table>
        <tbody>
            <tr><td>Придонес ПИО</td><td class="right">{{ number_format($runEmployee->pension, 2, ',', '.') }}</td></tr>
            <tr><td>Придонес ФЗО</td><td class="right">{{ number_format($runEmployee->health, 2, ',', '.') }}</td></tr>
            <tr><td>Придонес повреда</td><td class="right">{{ number_format($runEmployee->injury, 2, ',', '.') }}</td></tr>
            <tr><td>Придонес невработеност</td><td class="right">{{ number_format($runEmployee->unemployment, 2, ',', '.') }}</td></tr>
            <tr><td>Даночна основица</td><td class="right">{{ number_format($runEmployee->tax_base, 2, ',', '.') }}</td></tr>
            <tr><td>Персонален данок</td><td class="right">{{ number_format($runEmployee->tax, 2, ',', '.') }}</td></tr>
            <tr><td>Нето</td><td class="right">{{ number_format($runEmployee->net, 2, ',', '.') }}</td></tr>
            @foreach ($runEmployee->lines->where('kind', 'deduction') as $line)
                <tr><td>Задршка — {{ $line->description }}</td><td class="right">−{{ number_format($line->amount, 2, ',', '.') }}</td></tr>
            @endforeach
            <tr class="total"><td>За исплата</td><td class="right">{{ number_format($runEmployee->effective_net, 2, ',', '.') }}</td></tr>
        </tbody>
    </table>

    @if ($runEmployee->top_up > 0)
        <p class="muted">Доплата до најниска основица на товар на работодавачот: {{ number_format($runEmployee->top_up, 2, ',', '.') }}. Не се одзема од платата на работникот.</p>
    @endif
</body>
</html>
```

- [ ] **Step 5: Write the recap controller and view**

`app/Http/Controllers/PayrollRecapPdfController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PayrollRun;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class PayrollRecapPdfController extends Controller
{
    public function __invoke(Company $company, PayrollRun $run): Response
    {
        Gate::authorize('view', $company);
        abort_unless($run->company_id === $company->id, 404);

        $run->load(['employees.employee', 'employees.lines']);

        $pdf = Pdf::loadView('pdf.payroll-recap', [
            'company' => $company,
            'run' => $run,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream("rekapitular-{$run->year}-{$run->month}.pdf");
    }
}
```

`resources/views/pdf/payroll-recap.blade.php`:

```blade
<!DOCTYPE html>
<html lang="mk">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 14px; margin: 0 0 2px 0; }
        .muted { color: #666; font-size: 9px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border-bottom: 1px solid #ddd; padding: 3px 5px; text-align: left; }
        th { background: #f5f5f5; font-weight: bold; }
        .right { text-align: right; }
        .total td { border-top: 2px solid #333; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Рекапитулар на плата</h1>
    <div class="muted">{{ $company->name }} — {{ $run->monthName() }} {{ $run->year }}, фонд {{ $run->month_hours }} часа</div>

    <table>
        <thead>
            <tr>
                <th>Вработен</th>
                <th class="right">Бруто</th>
                <th class="right">ПИО</th>
                <th class="right">ФЗО</th>
                <th class="right">Повреда</th>
                <th class="right">Невработеност</th>
                <th class="right">Данок</th>
                <th class="right">Нето</th>
                <th class="right">Задршки</th>
                <th class="right">За исплата</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($run->employees as $row)
                <tr>
                    <td>{{ $row->employee->full_name }}</td>
                    <td class="right">{{ number_format($row->gross, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format($row->pension, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format($row->health, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format($row->injury, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format($row->unemployment, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format($row->tax, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format($row->net, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format($row->deductions_total, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format($row->effective_net, 2, ',', '.') }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Вкупно</td>
                <td class="right">{{ number_format($run->employees->sum('gross'), 2, ',', '.') }}</td>
                <td class="right">{{ number_format($run->employees->sum('pension'), 2, ',', '.') }}</td>
                <td class="right">{{ number_format($run->employees->sum('health'), 2, ',', '.') }}</td>
                <td class="right">{{ number_format($run->employees->sum('injury'), 2, ',', '.') }}</td>
                <td class="right">{{ number_format($run->employees->sum('unemployment'), 2, ',', '.') }}</td>
                <td class="right">{{ number_format($run->employees->sum('tax'), 2, ',', '.') }}</td>
                <td class="right">{{ number_format($run->employees->sum('net'), 2, ',', '.') }}</td>
                <td class="right">{{ number_format($run->employees->sum('deductions_total'), 2, ',', '.') }}</td>
                <td class="right">{{ number_format($run->employees->sum('effective_net'), 2, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
```

- [ ] **Step 6: Add the routes**

In `routes/web.php`, inside the same `EnsureAccountingAccess` group added in Task 8 — but under the `payroll.` name prefix so the names match the view:

```php
Route::middleware(['auth', EnsureAccountingAccess::class])->prefix('companies/{company}')->name('payroll.')->group(function () {
    Route::get('/payroll-runs/{run}/recap.pdf', PayrollRecapPdfController::class)->name('recap-pdf');
    Route::get('/payroll-runs/{run}/payslip/{runEmployee}.pdf', PayslipPdfController::class)->name('payslip-pdf');
});
```

Add the two `use App\Http\Controllers\...` imports. Register this group **before** the `payroll-runs.` group so `/payroll-runs/{run}/recap.pdf` is not swallowed by `/payroll-runs/{run}`.

- [ ] **Step 7: Put the links back on the run screen**

Task 10 left two placeholder comments in `resources/views/livewire/payroll/payroll-run-show.blade.php` because `route()` throws on a name that does not exist yet. The routes exist now.

Replace the comment block above `@if ($run->isDraft())` in the header with:

```blade
            <a href="{{ route('payroll.recap-pdf', [$company, $run]) }}" class="text-brand hover:underline text-sm">Рекапитулар (PDF)</a>
```

Replace the placeholder cell in the employee row with:

```blade
                        <td class="py-1 px-3 text-right">
                            <a href="{{ route('payroll.payslip-pdf', [$company, $run, $row]) }}" class="text-brand hover:underline text-xs">Исплатна листа</a>
                        </td>
```

- [ ] **Step 8: Run the tests and make sure they pass**

Run: `php artisan test --filter="PayrollPdfTest|PayrollRunShowTest"`
Expected: PASS, 9 tests. `PayrollRunShowTest` is included because this step changed the view it renders.

If Cyrillic renders as blank boxes, the font is the cause: dompdf needs `DejaVu Sans`, which the stylesheet above already sets. Compare with `resources/views/pdf/sales-invoice.blade.php`, which already solves this.

- [ ] **Step 9: Run the whole suite and rebuild the assets**

```bash
php artisan test
npm run build
```

Expected: all tests pass. `npm run build` is not optional — Tailwind only emits classes it has seen, and Tasks 9 and 10 introduced new ones.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/PayslipPdfController.php app/Http/Controllers/PayrollRecapPdfController.php resources/views/pdf/payslip.blade.php resources/views/pdf/payroll-recap.blade.php resources/views/livewire/payroll/payroll-run-show.blade.php routes/web.php tests/Feature/Payroll/PayrollPdfTest.php public/build
git commit -m "feat(payroll): payslip and company recap as PDFs"
```

---

### Task 12: The hour-fund screen

The last piece: the admin needs somewhere to type the twelve numbers.

**Files:**
- Modify: `app/Livewire/PayrollParameterIndex.php`
- Modify: `resources/views/livewire/payroll-parameter-index.blade.php`
- Test: `tests/Feature/PayrollParameterIndexTest.php`

**Interfaces:**
- Consumes: `PayrollMonthHours`.
- Produces: a `saveMonthHours()` action on the existing parameters screen.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/PayrollParameterIndexTest.php`:

```php
public function test_an_admin_can_enter_a_months_hour_fund(): void
{
    $company = Company::factory()->create();
    $this->admin();

    Livewire::test(PayrollParameterIndex::class, ['company' => $company])
        ->set('fundYear', 2027)
        ->set('fundMonth', 1)
        ->set('fundHours', 176)
        ->call('saveMonthHours')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('payroll_month_hours', [
        'year' => 2027, 'month' => 1, 'hours' => 176,
    ]);
}

public function test_entering_the_same_month_twice_updates_it(): void
{
    $company = Company::factory()->create();
    $this->admin();
    PayrollMonthHours::create(['year' => 2027, 'month' => 1, 'hours' => 176]);

    Livewire::test(PayrollParameterIndex::class, ['company' => $company])
        ->set('fundYear', 2027)
        ->set('fundMonth', 1)
        ->set('fundHours', 168)
        ->call('saveMonthHours')
        ->assertHasNoErrors();

    $this->assertSame(168, PayrollMonthHours::forMonth(2027, 1)->hours);
    $this->assertSame(1, PayrollMonthHours::where('year', 2027)->count());
}
```

Add `use App\Models\PayrollMonthHours;` to the test's imports. If the file's `admin()` helper has a different name, use the existing one.

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=PayrollParameterIndexTest`
Expected: FAIL — `fundYear` is not a property of the component.

- [ ] **Step 3: Extend the component**

In `app/Livewire/PayrollParameterIndex.php`, add `use App\Models\PayrollMonthHours;`, these properties:

```php
    public ?int $fundYear = null;

    public ?int $fundMonth = null;

    public ?int $fundHours = null;
```

set `$this->fundYear = (int) now()->year;` in `mount()`, and add:

```php
    /**
     * The fund is a national fact, so it is updated in place rather than
     * versioned: there is only ever one correct number of working hours in a
     * given month, and correcting a typo should not leave two.
     */
    public function saveMonthHours(): void
    {
        $this->validate([
            'fundYear' => ['required', 'integer', 'min:2000', 'max:2100'],
            'fundMonth' => ['required', 'integer', 'min:1', 'max:12'],
            'fundHours' => ['required', 'integer', 'min:0', 'max:400'],
        ], attributes: [
            'fundYear' => 'година', 'fundMonth' => 'месец', 'fundHours' => 'часови',
        ]);

        PayrollMonthHours::updateOrCreate(
            ['year' => $this->fundYear, 'month' => $this->fundMonth],
            ['hours' => $this->fundHours],
        );

        $this->fundMonth = null;
        $this->fundHours = null;
    }
```

In `render()`, pass the existing funds for the chosen year:

```php
'monthHours' => PayrollMonthHours::where('year', $this->fundYear)->orderBy('month')->get(),
```

- [ ] **Step 4: Extend the view**

Append to `resources/views/livewire/payroll-parameter-index.blade.php`, after the existing parameters card:

```blade
<x-card class="mt-6">
    <h2 class="text-lg font-semibold text-gray-800 mb-1">Фонд на часови по месец</h2>
    <p class="text-sm text-gray-500 mb-3">Внеси ги дванаесетте месеци на почеток на годината. Од овој број се добива цената по час.</p>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end mb-4">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Година</label>
            <input type="number" wire:model.live="fundYear" class="w-full rounded border-gray-300 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Месец</label>
            <select wire:model="fundMonth" class="w-full rounded border-gray-300 text-sm">
                <option value="">Избери</option>
                @foreach (['Јануари', 'Февруари', 'Март', 'Април', 'Мај', 'Јуни', 'Јули', 'Август', 'Септември', 'Октомври', 'Ноември', 'Декември'] as $i => $name)
                    <option value="{{ $i + 1 }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Часови</label>
            <input type="number" wire:model="fundHours" class="w-full rounded border-gray-300 text-sm">
        </div>
        <div>
            <button wire:click="saveMonthHours" class="w-full rounded bg-brand px-3 py-2 text-sm text-white">Зачувај</button>
        </div>
    </div>

    @error('fundYear') <p class="text-sm text-red-600 mb-2">{{ $message }}</p> @enderror
    @error('fundMonth') <p class="text-sm text-red-600 mb-2">{{ $message }}</p> @enderror
    @error('fundHours') <p class="text-sm text-red-600 mb-2">{{ $message }}</p> @enderror

    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500 bg-gray-50">
                <th class="py-1 px-3">Месец</th>
                <th class="py-1 px-3 text-right">Часови</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($monthHours as $fund)
                <tr class="text-sm hover:bg-orange-50">
                    <td class="py-1 px-3">{{ ['', 'Јануари', 'Февруари', 'Март', 'Април', 'Мај', 'Јуни', 'Јули', 'Август', 'Септември', 'Октомври', 'Ноември', 'Декември'][$fund->month] }}</td>
                    <td class="py-1 px-3 text-right">{{ $fund->hours }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="py-4 px-3 text-sm text-gray-400">Нема внесени месеци за оваа година.</td></tr>
            @endforelse
        </tbody>
    </table>
</x-card>
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `php artisan test --filter=PayrollParameterIndexTest`
Expected: PASS.

- [ ] **Step 6: Run the whole suite, the density test, and rebuild**

```bash
php artisan test
npm run build
```

Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/PayrollParameterIndex.php resources/views/livewire/payroll-parameter-index.blade.php tests/Feature/PayrollParameterIndexTest.php public/build
git commit -m "feat(payroll): admin screen for the monthly hour fund"
```

---

## After the last task

- [ ] **Push and watch CI.** The spec warns that MySQL migration faults are invisible locally. Three new tables with foreign keys is exactly the shape that has failed four times in this project. Expect one fix cycle visible only in CI; index-name length and the order of an index under a foreign key are the usual causes.
- [ ] **Verify in the browser** that opening a month, adding a sick-leave line, confirming, and downloading both PDFs all work against real data. `artisan serve` is too slow for this project — use the static harness.
- [ ] **Check the ledger** after a confirmation: the entry must balance and must not contain the Fund's share.
