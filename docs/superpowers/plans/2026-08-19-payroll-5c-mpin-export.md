# Фаза 5c — извоз на МПИН пресметка: план за изведба

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Потврдена месечна пресметка од 5b произведува МПИН XML датотека што се вчитува во МПИН клиентот на УЈП, за видови обврзници 110 и 111.

**Architecture:** Разликата во пресметката за самостоен вршител живее во `SalaryCalculator` преку ново enum `MpinObvrznik`, за да се согласуваат исплатната листа, книжењето и МПИН. Градењето на XML е одвоена класа што чита само од замрзнатата пресметка. Проверките пред извоз се трета одвоена класа, преземена од листата грешки на УЈП. Двете вистински поднесени датотеки, анонимизирани, се еталон-тестови што бараат излез знак по знак ист.

**Tech Stack:** Laravel 12, Livewire 3, PHP `DOMDocument`, Pest/PHPUnit, Tailwind.

**Spec:** `docs/superpowers/specs/2026-08-19-payroll-5c-mpin-export-design.md`

## Global Constraints

- Целиот текст видлив за корисник е на **македонски**. Никогаш бугарски зборови.
- Износите во XML се **цели денари**, без децимална точка.
- Датумите во XML се `j.m.Y` — ден **без** водечка нула, месец **со**: `1.05.2026`.
- Празните елементи се пишуваат `<Tag></Tag>`, никогаш `<Tag/>`. Тоа значи `saveXML(null, LIBXML_NOEMPTYTAG)`.
- Кодирањето е `utf-8`, вовед `<?xml version="1.0" encoding="utf-8"?>`.
- Видот на обврска е константа `101`.
- Вистински ЕМБГ, броеви на сметки и ЕДБ **не смеат** да влезат во репото.
- По секоја измена во Blade оди `npm run build`.
- `php artisan migrate` локално пред секоја рачна проверка во прелистувач.
- Тест-пакетот е SQLite, продукцијата е MySQL. Оваа фаза додава четири колони и два шифрарника.
- Целиот пакет мора да остане зелен: пред фазата е **1063/1063**.

**Стилот на тестовите — прочитај го ова пред да напишеш ниту еден тест.**

Проектот користи **PHPUnit 12.5 со класи**, НЕ Pest. Нема `vendor/bin/pest`. Секој тест изгледа вака:

```php
<?php

namespace Tests\Feature\Payroll;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NestoTest extends TestCase
{
    use RefreshDatabase;

    public function test_snake_case_name_in_english(): void
    {
        $this->assertSame($expected, $actual);
    }
}
```

Правила извлечени од постоечките тестови:

- Имињата на методите се **англиски** `snake_case` со префикс `test_`. Коментарите и пораките видливи за корисник се македонски.
- Тврдењата се `$this->assertSame()`, `assertTrue()`, `assertNotNull()`, `assertStringContainsString()`, `assertContains()`. Никогаш `expect()->toBe()`.
- Unit тестовите што не бараат база extend-аат `PHPUnit\Framework\TestCase`; сѐ што допира база extend-а `Tests\TestCase` со `use RefreshDatabase`.
- Заеднички подготовки се **приватни методи во класата** (види `PayrollRunServiceTest::employeeOn()`), не глобални помошни функции.
- Платата се создава со `EmployeeSalary::create(['employee_id' => …, 'effective_from' => …, 'amount' => …, 'basis' => …])`, не преку фабрика.

**Командата за тестирање.** `php artisan test` паѓа во овој worktree — `laravel/pao`, пакет што го форматира излезот за агенти, останува без меморија пред да се пушти ниту еден тест. Употребувај:

```
php -d memory_limit=1G vendor/bin/phpunit
```

За еден фајл додај ја патеката, за еден тест додај `--filter test_името`.

---

### Task 1: Двата нови шифрарника

**Files:**
- Create: `database/data/payroll-codes/vid_obvrznik.json`
- Create: `database/data/payroll-codes/podracno_zdravstvo.json`
- Create: `database/migrations/2026_08_19_100000_seed_obvrznik_and_zdravstvo_payroll_codes.php`
- Modify: `app/Models/PayrollCode.php:11` (`TYPES`)
- Test: `tests/Feature/PayrollCodeTest.php`

**Interfaces:**
- Consumes: ништо
- Produces: `PayrollCode::ofType('vid_obvrznik')` и `PayrollCode::ofType('podracno_zdravstvo')` враќаат непразни збирки.

- [ ] **Step 1: Изгради ги двата JSON фајла од постоечките .xls**

Изворните таблици се `ujp_mpin_xml/VID_OBVRZNICI.xls` (колони `VID_OBVRZNIK`, `KRATOK_OPIS`, `DOLG_OPIS`, прв ред е заглавие) и `ujp_mpin_xml/plavi_kartoni.xlsx` (колони `Kod`, `Opis`, прв ред е заглавие).

Еднократна скрипта, **не се комитира** — истата постапка како за постоечките шифрарници:

```php
<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$jobs = [
    'vid_obvrznik' => 'ujp_mpin_xml/VID_OBVRZNICI.xls',
    'podracno_zdravstvo' => 'ujp_mpin_xml/plavi_kartoni.xlsx',
];

foreach ($jobs as $type => $path) {
    $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
    array_shift($rows); // заглавие

    $codes = [];
    foreach ($rows as $row) {
        $code = trim((string) ($row[0] ?? ''));
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($row[1] ?? '')));

        if ($code === '' || $name === '') {
            continue;
        }

        $codes[] = ['code' => $code, 'name' => $name];
    }

    file_put_contents(
        "database/data/payroll-codes/{$type}.json",
        json_encode($codes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n"
    );

    echo "{$type}: ".count($codes)." записи\n";
}
```

Очекувано: `vid_obvrznik: 39 записи`, `podracno_zdravstvo: 32 записи` (по одземање на заглавието).

- [ ] **Step 2: Провери дека двете шифри што ги знаеме се внатре**

Run: `php -r "\$c=json_decode(file_get_contents('database/data/payroll-codes/vid_obvrznik.json'),true); foreach(\$c as \$x) if(in_array(\$x['code'],['110','111'])) print_r(\$x);"`

Expected: `110 => Работодавач, правно лице` и `111 => Самостоен вршител - интелектуална дејност`.

Run: `php -r "\$c=json_decode(file_get_contents('database/data/payroll-codes/podracno_zdravstvo.json'),true); foreach(\$c as \$x) if(\$x['code']==='4061') print_r(\$x);"`

Expected: `4061 => Скопје`.

- [ ] **Step 3: Напиши го тестот што паѓа**

Додај во `tests/Feature/PayrollCodeTest.php`, во постоечката класа:

```php
    public function test_the_obvrznik_codebook_is_seeded(): void
    {
        $codes = PayrollCode::ofType('vid_obvrznik');

        $this->assertGreaterThan(0, $codes->count());
        $this->assertSame('Работодавач, правно лице', $codes->firstWhere('code', '110')?->name);
        $this->assertStringContainsString(
            'Самостоен вршител',
            (string) $codes->firstWhere('code', '111')?->name,
        );
    }

    public function test_the_health_area_codebook_is_seeded(): void
    {
        $codes = PayrollCode::ofType('podracno_zdravstvo');

        $this->assertGreaterThan(0, $codes->count());
        $this->assertSame('Скопје', $codes->firstWhere('code', '4061')?->name);
    }
```

- [ ] **Step 4: Пушти го и потврди дека паѓа**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/PayrollCodeTest.php`
Expected: FAIL — збирките се празни.

- [ ] **Step 5: Напиши ја миграцијата**

`database/migrations/2026_08_19_100000_seed_obvrznik_and_zdravstvo_payroll_codes.php` — истиот образец како `2026_08_15_100000_seed_rab_cas_and_nadomestoci_payroll_codes.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TYPES = ['vid_obvrznik', 'podracno_zdravstvo'];

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
            // makes a multi-row insert fail in CI but not locally.
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

- [ ] **Step 6: Прошири го `PayrollCode::TYPES`**

`app/Models/PayrollCode.php`, ред 11:

```php
public const TYPES = [
    'opstina', 'vid_staz', 'sifra_dviz', 'osloboduvanje', 'rab_cas',
    'vid_nadomestoci', 'vid_obvrznik', 'podracno_zdravstvo',
];
```

- [ ] **Step 7: Пушти ги тестовите**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/PayrollCodeTest.php`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add database/data/payroll-codes/vid_obvrznik.json database/data/payroll-codes/podracno_zdravstvo.json database/migrations/2026_08_19_100000_seed_obvrznik_and_zdravstvo_payroll_codes.php app/Models/PayrollCode.php tests/Feature/PayrollCodeTest.php
git commit -m "feat(payroll): seed the obvrznik and health-area codebooks"
```

---

### Task 2: Вид обврзник кај фирмата

**Files:**
- Create: `database/migrations/2026_08_19_100100_add_mpin_obvrznik_code_to_companies_table.php`
- Create: `app/Support/Payroll/MpinObvrznik.php`
- Modify: `app/Models/Company.php` (`$fillable`, `casts()`)
- Modify: `app/Livewire/CompanyDashboard.php`
- Modify: `resources/views/livewire/company-dashboard.blade.php`
- Test: `tests/Unit/Payroll/MpinObvrznikTest.php`, `tests/Feature/CompanyDashboardMpinObvrznikTest.php`

**Interfaces:**
- Consumes: Task 1's `vid_obvrznik` шифрарник (за имињата во паѓачкото мени)
- Produces:
  - `App\Support\Payroll\MpinObvrznik` — backed enum, случаи `EMPLOYER = '110'`, `SELF_EMPLOYED = '111'`, методи `label(): string`, `chargesUnemployment(): bool`, `chargesMonthlyTax(): bool`
  - `Company::$mpin_obvrznik_code` фрлен во `MpinObvrznik` (nullable)

- [ ] **Step 1: Напиши го тестот за enum-от**

`tests/Unit/Payroll/MpinObvrznikTest.php`:

Ова не допира база, па extend-а го чистиот `PHPUnit\Framework\TestCase`, како `tests/Unit/Payroll/MonthCoverageTest.php`:

```php
<?php

namespace Tests\Unit\Payroll;

use App\Support\Payroll\MpinObvrznik;
use PHPUnit\Framework\TestCase;

class MpinObvrznikTest extends TestCase
{
    public function test_an_employer_pays_unemployment_and_monthly_tax(): void
    {
        $this->assertTrue(MpinObvrznik::EMPLOYER->chargesUnemployment());
        $this->assertTrue(MpinObvrznik::EMPLOYER->chargesMonthlyTax());
        $this->assertSame('110', MpinObvrznik::EMPLOYER->value);
    }

    public function test_a_self_employed_person_pays_neither(): void
    {
        $this->assertFalse(MpinObvrznik::SELF_EMPLOYED->chargesUnemployment());
        $this->assertFalse(MpinObvrznik::SELF_EMPLOYED->chargesMonthlyTax());
        $this->assertSame('111', MpinObvrznik::SELF_EMPLOYED->value);
    }

    public function test_every_case_carries_a_macedonian_label(): void
    {
        foreach (MpinObvrznik::cases() as $case) {
            $this->assertNotSame('', $case->label());
        }
    }
}
```

- [ ] **Step 2: Пушти го и потврди дека паѓа**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Unit/Payroll/MpinObvrznikTest.php`
Expected: FAIL — класата не постои.

- [ ] **Step 3: Напиши го enum-от**

`app/Support/Payroll/MpinObvrznik.php`:

```php
<?php

namespace App\Support\Payroll;

/**
 * Видот на обврзник од шифрарникот на УЈП, ограничен на двата што оваа
 * апликација навистина ги пресметува.
 *
 * Целиот шифрарник има 39 записи и сите се вчитани во `payroll_codes` за
 * евиденција, но паѓачкото мени нуди само овие два: секој друг вид носи свои
 * правила за пресметка што не се потврдени од ниту една вистинска датотека.
 */
enum MpinObvrznik: string
{
    case EMPLOYER = '110';
    case SELF_EMPLOYED = '111';

    public function label(): string
    {
        return match ($this) {
            self::EMPLOYER => 'Работодавач, правно лице',
            self::SELF_EMPLOYED => 'Самостоен вршител — интелектуална дејност',
        };
    }

    /**
     * Самостојниот вршител е ослободен од придонесот за вработување. Основа:
     * шифрата за ослободување 001 во шифрарникот на УЈП, „Самостоен вршител
     * ослободен од плаќање на придонес за вработување", потврдена со вистинска
     * поднесена датотека каде тој придонес е нула.
     */
    public function chargesUnemployment(): bool
    {
        return $this === self::EMPLOYER;
    }

    /**
     * Самостојниот вршител го плаќа личниот данок годишно преку годишна даночна
     * пријава, не месечно преку МПИН. Потврдено со вистинска поднесена датотека
     * каде и данокот и даночното намалување се нула, и потврдено од корисникот
     * како сметководител.
     */
    public function chargesMonthlyTax(): bool
    {
        return $this === self::EMPLOYER;
    }
}
```

- [ ] **Step 4: Пушти ги тестовите**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Unit/Payroll/MpinObvrznikTest.php`
Expected: PASS

- [ ] **Step 5: Напиши ја миграцијата**

`database/migrations/2026_08_19_100100_add_mpin_obvrznik_code_to_companies_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Nullable намерно: постоечките фирми немаат вид обврзник додека
            // корисникот не го внесе, а проверката пред извоз го бара тоа —
            // подобро отколку тивко да претпоставиме 110 за сите.
            $table->string('mpin_obvrznik_code', 8)->nullable()->after('tax_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('mpin_obvrznik_code');
        });
    }
};
```

- [ ] **Step 6: Врзи го на моделот**

`app/Models/Company.php` — додај `'mpin_obvrznik_code'` во `$fillable`, и во `casts()`:

```php
'mpin_obvrznik_code' => \App\Support\Payroll\MpinObvrznik::class,
```

- [ ] **Step 7: Напиши го тестот за екранот**

Создај `tests/Feature/CompanyDashboardMpinObvrznikTest.php`. Проектот држи по еден фокусиран фајл за секоја тема на овој екран (`CompanyDashboardStructuredAddressTest`, `CompanyDashboardSigningDeviceTest`) — следи го тоа, не додавај во општиот `CompanyDashboardTest`.

Шаблонот е препишан од `CompanyDashboardStructuredAddressTest`, кој прави точно иста работа за адресните полиња:

```php
<?php

namespace Tests\Feature;

use App\Livewire\CompanyDashboard;
use App\Models\Company;
use App\Models\User;
use App\Support\Payroll\MpinObvrznik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyDashboardMpinObvrznikTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_admin_can_save_the_mpin_obvrznik_type(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['mpin_obvrznik_code' => null]);

        Livewire::actingAs($admin)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('editMpinObvrznikCode', '111')
            ->call('save')
            ->assertHasNoErrors();

        $company->refresh();
        $this->assertSame(MpinObvrznik::SELF_EMPLOYED, $company->mpin_obvrznik_code);
    }

    public function test_an_unsupported_obvrznik_type_is_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create();

        Livewire::actingAs($admin)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('editMpinObvrznikCode', '115')
            ->call('save')
            ->assertHasErrors('editMpinObvrznikCode');
    }
}
```

`startEdit()` и `save()` се вистинските имиња — проверени. Образецот `edit*` својство плус зачувување во `save()` веќе постои за `street_address` и за `editEfakturaMode`; следи го точно него.

Улогите доаѓаат од Spatie Permission. `User::factory()` **нема** состојба `admin()` — улогата се доделува со `assignRole('admin')` откако `Role::findOrCreate('admin')` ја создала во `setUp()`.

- [ ] **Step 8: Пушти го и потврди дека паѓа**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/CompanyDashboardMpinObvrznikTest.php`
Expected: FAIL — својството `editMpinObvrznikCode` не постои.

- [ ] **Step 9: Додај го полето во компонентата**

`app/Livewire/CompanyDashboard.php`:

```php
public string $editMpinObvrznikCode = '';
```

Во методот што ги полни `edit*` својствата (околу ред 90, каде стои `$this->editStreetAddress = ...`):

```php
$this->editMpinObvrznikCode = $this->company->mpin_obvrznik_code?->value ?? '';
```

Во правилата за валидација:

```php
'editMpinObvrznikCode' => ['nullable', Rule::enum(\App\Support\Payroll\MpinObvrznik::class)],
```

Во зачувувањето (околу ред 232, каде стои `'street_address' => ...`):

```php
'mpin_obvrznik_code' => $validated['editMpinObvrznikCode'] ?: null,
```

- [ ] **Step 10: Додај го полето во Blade**

`resources/views/livewire/company-dashboard.blade.php`, во истиот блок каде се уредуваат адресните полиња:

```blade
<label class="block">
    <span class="block text-sm text-gray-700">Вид обврзник (МПИН)</span>
    <select wire:model="editMpinObvrznikCode"
            class="mt-1 w-full rounded border-gray-300 text-sm">
        <option value="">— не е одредено —</option>
        @foreach (\App\Support\Payroll\MpinObvrznik::cases() as $case)
            <option value="{{ $case->value }}">{{ $case->value }} — {{ $case->label() }}</option>
        @endforeach
    </select>
    @error('editMpinObvrznikCode')
        <span class="mt-1 block text-sm text-red-600">{{ $message }}</span>
    @enderror
</label>
```

- [ ] **Step 11: Пушти ги тестовите**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/CompanyDashboardMpinObvrznikTest.php tests/Unit/Payroll/MpinObvrznikTest.php`
Expected: PASS

- [ ] **Step 12: Commit**

```bash
git add app/Support/Payroll/MpinObvrznik.php app/Models/Company.php app/Livewire/CompanyDashboard.php resources/views/livewire/company-dashboard.blade.php database/migrations/2026_08_19_100100_add_mpin_obvrznik_code_to_companies_table.php tests/Unit/Payroll/MpinObvrznikTest.php tests/Feature/CompanyDashboardMpinObvrznikTest.php
git commit -m "feat(payroll): give a company its МПИН obvrznik type"
```

---

### Task 3: Подрачна здравствена служба кај работникот

**Files:**
- Create: `database/migrations/2026_08_19_100200_add_health_area_code_to_employees_table.php`
- Modify: `app/Models/Employee.php` (`$fillable`)
- Modify: `app/Livewire/EmployeeForm.php`
- Modify: `resources/views/livewire/employee-form.blade.php`
- Modify: `database/factories/EmployeeFactory.php`
- Test: `tests/Feature/EmployeeFormTest.php`

**Interfaces:**
- Consumes: Task 1's `podracno_zdravstvo` шифрарник
- Produces: `Employee::$health_area_code` (`?string`)

- [ ] **Step 1: Напиши го тестот што паѓа**

Додај во постоечката класа `tests/Feature/EmployeeFormTest.php`. Таа веќе има `setUp()` со улогите и приватен помошник `actAsAdmin()` — употреби ги, не дуплирај ги:

```php
    public function test_the_health_area_code_is_saved(): void
    {
        $company = Company::factory()->create();
        $this->actAsAdmin();

        Livewire::test(EmployeeForm::class, ['company' => $company])
            ->set('embg', '3101980455019')
            ->set('firstName', 'Марко')
            ->set('lastName', 'Петровски')
            ->set('municipalityCode', '175')
            ->set('bankAccount', '300000000000000')
            ->set('insuranceTypeCode', '0050')
            ->set('healthAreaCode', '4061')
            ->set('employedOn', '2026-01-01')
            ->set('salaryEffectiveFrom', '2026-01-01')
            ->set('gross', '38507')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('4061', Employee::first()->health_area_code);
    }
```

Ако фајлот веќе има помошник што ја пополнува целата форма, употреби го него и постави го само новото поле — не препишувај го пополнувањето.

- [ ] **Step 2: Пушти го и потврди дека паѓа**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/EmployeeFormTest.php`
Expected: FAIL — својството `healthAreaCode` не постои.

- [ ] **Step 3: Напиши ја миграцијата**

`database/migrations/2026_08_19_100200_add_health_area_code_to_employees_table.php`:

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
            $table->string('health_area_code', 16)->nullable()->after('municipality_code');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('health_area_code');
        });
    }
};
```

- [ ] **Step 4: Врзи го на моделот и во фабриката**

`app/Models/Employee.php` — додај `'health_area_code'` во `$fillable`, веднаш по `'municipality_code'`.

`database/factories/EmployeeFactory.php` — додај `'health_area_code' => '4061',` веднаш по `'municipality_code'`.

- [ ] **Step 5: Додај го полето во формата**

`app/Livewire/EmployeeForm.php`:

```php
public string $healthAreaCode = '';
```

Во полнењето од постоечки работник (околу ред 112):

```php
$this->healthAreaCode = (string) $employee->health_area_code;
```

Во правилата (околу ред 214):

```php
'healthAreaCode' => ['nullable', 'string', 'max:16'],
```

Во зачувувањето (околу ред 247):

```php
'health_area_code' => $validated['healthAreaCode'] ?: null,
```

- [ ] **Step 6: Додај го полето во Blade**

`resources/views/livewire/employee-form.blade.php`, веднаш до полето за општина:

```blade
<label class="block">
    <span class="block text-sm text-gray-700">Подрачна здравствена служба</span>
    <select wire:model="healthAreaCode"
            class="mt-1 w-full rounded border-gray-300 text-sm">
        <option value="">— не е одредено —</option>
        @foreach (\App\Models\PayrollCode::ofType('podracno_zdravstvo') as $code)
            <option value="{{ $code->code }}">{{ $code->code }} — {{ $code->name }}</option>
        @endforeach
    </select>
    @error('healthAreaCode')
        <span class="mt-1 block text-sm text-red-600">{{ $message }}</span>
    @enderror
</label>
```

- [ ] **Step 7: Пушти ги тестовите**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/EmployeeFormTest.php`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_19_100200_add_health_area_code_to_employees_table.php app/Models/Employee.php app/Livewire/EmployeeForm.php resources/views/livewire/employee-form.blade.php database/factories/EmployeeFactory.php tests/Feature/EmployeeFormTest.php
git commit -m "feat(payroll): record an employee's health-area code"
```

---

### Task 4: Фонд часови по работник за неполно работно време

**Files:**
- Modify: `app/Models/Employee.php`
- Modify: `app/Services/Payroll/PayrollRunService.php:36` (`open()`), и повикот на `calculate()` во `recalculate()`
- Test: `tests/Feature/Payroll/PayrollRunServiceTest.php`

**Interfaces:**
- Consumes: ништо ново
- Produces: `Employee::monthFund(int $runFund): int` — фондот на месецот сведен на договореното работно време на работникот.

**Зошто:** `employees.weekly_hours` се чува од 5a и никогаш не се употребува. Работник со 20 часа неделно денес добива цел месечен фонд, што за МПИН е противречно со неговата шифра `0047` („неполно работно време") и УЈП го проверува бројот часови. Парите **не** се менуваат: делителот на часовната стапка и бројот часови се менуваат заедно.

- [ ] **Step 1: Напиши го тестот што паѓа**

Додај во постоечката класа `tests/Feature/Payroll/PayrollRunServiceTest.php`. Таа веќе има приватен помошник `employeeOn(Company $company, float $amount, string $basis): Employee` — овие тестови ѝ треба и `weekly_hours`, па додај втор помошник до него наместо да го менуваш постоечкиот:

```php
    private function partTimeEmployeeOn(Company $company, float $amount, int $weeklyHours): Employee
    {
        $employee = Employee::factory()->for($company)->create([
            'employed_on' => '2026-01-01',
            'prior_service_months' => 0,
            'weekly_hours' => $weeklyHours,
        ]);

        EmployeeSalary::create([
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'amount' => $amount,
            'basis' => 'gross',
        ]);

        return $employee;
    }

    public function test_a_half_time_employee_gets_half_the_fund(): void
    {
        $company = Company::factory()->create();
        $this->partTimeEmployeeOn($company, 34571, 20);

        $run = app(PayrollRunService::class)->open($company, 2026, 1);

        // Јануари 2026 има 22 работни дена, значи фонд 176 за полно работно
        // време. Празниците не се одземаат — потврдено со вистинска МПИН
        // датотека каде истиот работник има 88 часа.
        $this->assertSame(176, $run->month_hours);

        $line = $run->employees->first()->lines->firstWhere('code', '001');
        $this->assertSame(88, $line->hours);
    }

    public function test_half_the_fund_does_not_move_the_agreed_gross(): void
    {
        $company = Company::factory()->create();
        $this->partTimeEmployeeOn($company, 34571, 20);

        $run = app(PayrollRunService::class)->open($company, 2026, 1);

        $this->assertSame(34571.0, round((float) $run->employees->first()->gross));
    }

    public function test_a_full_time_employee_is_untouched(): void
    {
        $company = Company::factory()->create();
        $this->partTimeEmployeeOn($company, 38507, 40);

        $run = app(PayrollRunService::class)->open($company, 2026, 5);

        $line = $run->employees->first()->lines->firstWhere('code', '001');
        $this->assertSame(168, $line->hours);
        $this->assertSame(38507.0, round((float) $run->employees->first()->gross));
    }
```

- [ ] **Step 2: Пушти го и потврди дека паѓа**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Payroll/PayrollRunServiceTest.php`
Expected: FAIL — часовите се 176 наместо 88.

- [ ] **Step 3: Додај `monthFund()` на моделот**

`app/Models/Employee.php`:

```php
/**
 * Месечниот фонд часови сведен на договореното работно време.
 *
 * Полно работно време е 40 часа неделно, па за таков работник ова го враќа
 * фондот непроменет и ниту една постоечка пресметка не се поместува. За
 * неполно работно време и делителот на часовната стапка и бројот часови се
 * сведуваат со ист множител, така што договорената бруто плата останува иста
 * — се менува само бројот часови, кој е она што МПИН го пријавува и што мора
 * да се согласува со шифрата за вид на стаж.
 */
public function monthFund(int $runFund): int
{
    return (int) round($runFund * $this->weekly_hours / 40);
}
```

- [ ] **Step 4: Употреби го при отворање на пресметката**

`app/Services/Payroll/PayrollRunService.php`, во `open()`, замени го редот што ги пресметува часовите:

```php
'hours' => $employee->coverageIn($year, $month)
    ->hours($employee->monthFund($fund->hours)),
```

- [ ] **Step 5: Употреби го како делител при пресметка**

Во `recalculate()`, повикот на `PayrollRunCalculator::calculate()` го добива `$run->month_hours` како втор аргумент. Замени го со фондот на тој работник:

```php
$employee->monthFund($run->month_hours),
```

каде `$employee` е моделот `Employee` на тековниот ред. Ако во таа јамка е достапен само `PayrollRunEmployee`, употреби `$runEmployee->employee->monthFund($run->month_hours)` и погрижи се врската да е вчитана однапред, за да не се создаде N+1 барање.

- [ ] **Step 6: Пушти ги тестовите на фазата**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Payroll`
Expected: PASS — сите, вклучувајќи ги постоечките. Ако некој постоечки тест падне, тоа значи дека фабриката некаде поставува `weekly_hours` различно од 40; поправи ја **фабриката или тестот**, никогаш `monthFund()`.

- [ ] **Step 7: Commit**

```bash
git add app/Models/Employee.php app/Services/Payroll/PayrollRunService.php tests/Feature/Payroll/PayrollRunServiceTest.php
git commit -m "fix(payroll): a part-time employee gets a part-time hour fund"
```

---

### Task 5: Профилот на обврзник во пресметувачот

**Files:**
- Modify: `app/Support/Payroll/SalaryCalculator.php`
- Test: `tests/Feature/Payroll/SalaryCalculatorTest.php`

**Interfaces:**
- Consumes: `MpinObvrznik` од Task 2
- Produces:
  - `SalaryCalculator::fromGross(float $gross, PayrollParameter $p, ?float $minBase = null, MpinObvrznik $obvrznik = MpinObvrznik::EMPLOYER): SalaryBreakdown`
  - `SalaryCalculator::fromNet(float $net, PayrollParameter $p, ?float $minBase = null, MpinObvrznik $obvrznik = MpinObvrznik::EMPLOYER): SalaryBreakdown`

- [ ] **Step 1: Напиши го тестот што паѓа**

Додај во постоечката класа `tests/Feature/Payroll/SalaryCalculatorTest.php`:

```php
    public function test_a_self_employed_person_pays_no_unemployment_and_no_monthly_tax(): void
    {
        $p = PayrollParameter::forDate('2026-01-31');

        $whole = SalaryCalculator::fromGross(
            34571, $p, null, MpinObvrznik::SELF_EMPLOYED
        )->whole();

        // Секоја бројка е од вистинска поднесена МПИН датотека за јануари 2026.
        $this->assertSame(6499, $whole['pension']);
        $this->assertSame(2593, $whole['health']);
        $this->assertSame(173, $whole['injury']);
        $this->assertSame(0, $whole['unemployment']);
        $this->assertSame(9265, $whole['contributions']);
        $this->assertSame(0, $whole['taxBase']);
        $this->assertSame(0, $whole['tax']);
        $this->assertSame(25306, $whole['net']);
    }

    public function test_an_employer_is_unchanged_and_is_the_default_profile(): void
    {
        $p = PayrollParameter::forDate('2026-05-31');

        $default = SalaryCalculator::fromGross(38507, $p);
        $explicit = SalaryCalculator::fromGross(38507, $p, null, MpinObvrznik::EMPLOYER);

        $this->assertEquals($explicit, $default);

        // Секоја бројка е од вистинска поднесена МПИН датотека за мај 2026.
        $whole = $default->whole();
        $this->assertSame(462, $whole['unemployment']);
        $this->assertSame(10782, $whole['contributions']);
        $this->assertSame(16793, $whole['taxBase']);
        $this->assertSame(1679, $whole['tax']);
        $this->assertSame(26046, $whole['net']);
    }

    public function test_the_net_to_gross_search_honours_the_profile(): void
    {
        $p = PayrollParameter::forDate('2026-01-31');

        $whole = SalaryCalculator::fromNet(
            25306, $p, null, MpinObvrznik::SELF_EMPLOYED
        )->whole();

        $this->assertSame(34571, $whole['gross']);
        $this->assertSame(0, $whole['tax']);
    }
```

- [ ] **Step 2: Пушти го и потврди дека паѓа**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Payroll/SalaryCalculatorTest.php`
Expected: FAIL — `fromGross()` прима три аргументи.

- [ ] **Step 3: Прошири го `fromGross()`**

`app/Support/Payroll/SalaryCalculator.php`. Додај го четвртиот параметар и употреби го на три места:

```php
public static function fromGross(
    float $gross,
    PayrollParameter $p,
    ?float $minBase = null,
    MpinObvrznik $obvrznik = MpinObvrznik::EMPLOYER,
): SalaryBreakdown {
    $base = min($gross, $p->max_base);

    $pension = self::share($base, $p->rate_pension);
    $health = self::share($base, $p->rate_health);
    $injury = self::share($base, $p->rate_injury);
    $unemployment = $obvrznik->chargesUnemployment()
        ? self::share($base, $p->rate_unemployment)
        : 0.0;
    $contributions = round($pension + $health + $injury + $unemployment, 2);

    // Самостојниот вршител го плаќа личниот данок годишно, не преку МПИН, па
    // и основицата и данокот се нула — не само личното ослободување. Ако беше
    // само отсуство на ослободување, данокот ќе беше 10% од бруто минус
    // придонеси, што вистинската датотека го демантира.
    $taxBase = $obvrznik->chargesMonthlyTax()
        ? round(max($gross - $contributions - $p->personal_allowance, 0), 2)
        : 0.0;
    $tax = $obvrznik->chargesMonthlyTax() ? self::share($taxBase, $p->rate_tax) : 0.0;
    $net = round($gross - $contributions - $tax, 2);
```

Понатаму, во делот за доплата до најниската основица, стапката за вработување исто се гаси:

```php
    $topUpUnemployment = $obvrznik->chargesUnemployment()
        ? self::share($shortfall, $p->rate_unemployment)
        : 0.0;
```

Не заборавај `use App\Support\Payroll\MpinObvrznik;` — истиот именски простор е, па `use` не треба; провери и не додавај непотребен ред.

- [ ] **Step 4: Прошири го `fromNet()`**

Профилот мора да патува низ бинарното пребарување, инаку договорено нето за самостоен вршител дава погрешно бруто:

```php
public static function fromNet(
    float $net,
    PayrollParameter $p,
    ?float $minBase = null,
    MpinObvrznik $obvrznik = MpinObvrznik::EMPLOYER,
): SalaryBreakdown {
```

и на двете места каде внатре се вика `self::fromGross(...)` додај го `$obvrznik` како четврти аргумент.

- [ ] **Step 5: Пушти ги тестовите**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Payroll/SalaryCalculatorTest.php tests/Feature/Payroll/SalaryCalculatorNetToGrossTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Support/Payroll/SalaryCalculator.php tests/Feature/Payroll/SalaryCalculatorTest.php
git commit -m "feat(payroll): teach the calculator what kind of obvrznik pays"
```

---

### Task 6: Профилот патува низ пресметката

**Files:**
- Modify: `app/Support/Payroll/PayrollRunCalculator.php`
- Modify: `app/Services/Payroll/PayrollRunService.php`
- Test: `tests/Feature/Payroll/PayrollRunCalculatorTest.php`

**Interfaces:**
- Consumes: `SalaryCalculator` од Task 5
- Produces:
  - `PayrollRunCalculator::fullMonthGross(float $amount, string $basis, PayrollParameter $parameters, MpinObvrznik $obvrznik = MpinObvrznik::EMPLOYER): float`
  - `PayrollRunCalculator::calculate(..., ?float $minBase = null, MpinObvrznik $obvrznik = MpinObvrznik::EMPLOYER): PayrollRunResult`

- [ ] **Step 1: Напиши го тестот што паѓа**

Додај во постоечката класа `tests/Feature/Payroll/PayrollRunCalculatorTest.php`:

```php
    public function test_a_self_employed_run_has_no_tax_and_no_unemployment(): void
    {
        $whole = PayrollRunCalculator::calculate(
            fullMonthGross: 34571,
            monthHours: 88,
            seniorityYears: 0,
            inputLines: [[
                'kind' => PayrollRunLine::KIND_HOURS,
                'code' => '001',
                'description' => 'Редовни работни часови',
                'hours' => 88,
                'percent' => 100.0,
                'amount' => null,
                'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
            ]],
            parameters: PayrollParameter::forDate('2026-01-31'),
            minBase: null,
            obvrznik: MpinObvrznik::SELF_EMPLOYED,
        )->breakdown->whole();

        // Бројките се од вистинска поднесена МПИН датотека за јануари 2026.
        $this->assertSame(34571, $whole['gross']);
        $this->assertSame(0, $whole['unemployment']);
        $this->assertSame(0, $whole['tax']);
        $this->assertSame(25306, $whole['net']);
    }
```

- [ ] **Step 2: Пушти го и потврди дека паѓа**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Payroll/PayrollRunCalculatorTest.php`
Expected: FAIL — непознат именуван аргумент `obvrznik`.

- [ ] **Step 3: Провлечи го профилот низ `PayrollRunCalculator`**

Додај го параметарот на `fullMonthGross()` и `calculate()` како во блокот „Produces" погоре, и предај го натаму:

- во `fullMonthGross()`: `SalaryCalculator::fromNet($amount, $parameters, null, $obvrznik)`
- во `calculate()`: `SalaryCalculator::fromGross($gross, $parameters, $minBase, $obvrznik)`

- [ ] **Step 4: Предај го од сервисот**

`app/Services/Payroll/PayrollRunService.php`. И во `open()` и во `recalculate()`, профилот доаѓа од фирмата на пресметката:

```php
$obvrznik = $run->company->mpin_obvrznik_code ?? MpinObvrznik::EMPLOYER;
```

Предај го како последен аргумент и на `fullMonthGross()` и на `calculate()`.

Стандардната вредност е `EMPLOYER` намерно: фирма кај која видот обврзник не е внесен се пресметува како досега, а извозот е тој што ќе побара да се внесе.

- [ ] **Step 5: Пушти ги тестовите на фазата**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Payroll`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Support/Payroll/PayrollRunCalculator.php app/Services/Payroll/PayrollRunService.php tests/Feature/Payroll/PayrollRunCalculatorTest.php
git commit -m "feat(payroll): carry the obvrznik profile into the monthly run"
```

---

### Task 7: Еталоните и градењето на XML

**Files:**
- Create: `tests/Fixtures/mpin/obvrznik-110.xml`
- Create: `app/Support/Payroll/Mpin/MpinDocumentBuilder.php`
- Test: `tests/Feature/Payroll/MpinDocumentBuilderTest.php`

**Interfaces:**
- Consumes: сето претходно
- Produces:
  - `MpinDocumentBuilder::build(PayrollRun $run): string` — целиот XML, со завршен нов ред
  - `MpinDocumentBuilder::fileName(PayrollRun $run): string` — на пример `DESIGNIA DOOEL_2026_05_101.xml`

- [ ] **Step 1: Создај го еталонот за обврзник 110**

`tests/Fixtures/mpin/obvrznik-110.xml`. Ова е вистинска поднесена датотека од мај 2026, со **заменети** ЕДБ, ЕМБГ и трансакциска сметка. Ниту едно правило не зависи од нивните вистински вредности.

```xml
<?xml version="1.0" encoding="utf-8"?>
<MpinCalculation xsi:noNamespaceSchemaLocation="schema.xsd" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <EdbIsplatitel>4080000000000</EdbIsplatitel>
  <SifraVidObvrznik>110</SifraVidObvrznik>
  <VoImeNaObvrznikEdb>4080000000000</VoImeNaObvrznikEdb>
  <SifraVidObvrska>101</SifraVidObvrska>
  <MesecPridonesi>05</MesecPridonesi>
  <GodinaPridonesi>2026</GodinaPridonesi>
  <BrojVraboteni>1</BrojVraboteni>
  <BrutoIznosVk>38507</BrutoIznosVk>
  <ZadPIOIznosVk>7239</ZadPIOIznosVk>
  <DopPIOIznosVk>0</DopPIOIznosVk>
  <ZadFZOIznosVk>2888</ZadFZOIznosVk>
  <DopFZOIznosVk>0</DopFZOIznosVk>
  <ZadPovredaRabIznosVk>193</ZadPovredaRabIznosVk>
  <DopPovredaRabIznosVk>0</DopPovredaRabIznosVk>
  <ZadVrabotuvanjeIznosVk>462</ZadVrabotuvanjeIznosVk>
  <DopVrabotuvanjeIznosVk>0</DopVrabotuvanjeIznosVk>
  <ZadBenefStazIznosVk>0</ZadBenefStazIznosVk>
  <DopBenefStazIznosVk>0</DopBenefStazIznosVk>
  <PersonalenDanokIznosVk>1679</PersonalenDanokIznosVk>
  <NetoIznosVk>26046</NetoIznosVk>
  <EfektivnoNetoIznosVk>26046</EfektivnoNetoIznosVk>
  <DanocnoOslobIznosVk>10932</DanocnoOslobIznosVk>
  <Zabeleska></Zabeleska>
  <MpinCalculationSt>
    <RedenBroj>1</RedenBroj>
    <VrabotenEmbg>0101990450006</VrabotenEmbg>
    <SifraOpstina>130</SifraOpstina>
    <TransakciskaSmetka>300000000000000</TransakciskaSmetka>
    <DenoviStazVkVrab>31</DenoviStazVkVrab>
    <BrutoIznosVkVrab>38507</BrutoIznosVkVrab>
    <ZadPIOIznosVkVrab>7239</ZadPIOIznosVkVrab>
    <DopPIOIznosVkVrab>0</DopPIOIznosVkVrab>
    <ZadFZOIznosVkVrab>2888</ZadFZOIznosVkVrab>
    <DopFZOIznosVkVrab>0</DopFZOIznosVkVrab>
    <ZadPovredaRabIznosVkVrab>193</ZadPovredaRabIznosVkVrab>
    <DopPovredaRabIznosVkVrab>0</DopPovredaRabIznosVkVrab>
    <ZadVrabotuvanjeIznosVkVrab>462</ZadVrabotuvanjeIznosVkVrab>
    <DopVrabotuvanjeIznosVkVrab>0</DopVrabotuvanjeIznosVkVrab>
    <ZadBenefStazIznosVkVrab>0</ZadBenefStazIznosVkVrab>
    <DopBenefStazIznosVkVrab>0</DopBenefStazIznosVkVrab>
    <PersonalenDanokIznosVkVrab>1679</PersonalenDanokIznosVkVrab>
    <NetoIznosVkVrab>26046</NetoIznosVkVrab>
    <EfektivnoNetoIznosVkVrab>26046</EfektivnoNetoIznosVkVrab>
    <DanocnoOslobIznosVkVrab>10932</DanocnoOslobIznosVkVrab>
    <RabotniCasoviVkVrab>168</RabotniCasoviVkVrab>
    <MpinCalculationStDetail>
      <RedenBroj>1</RedenBroj>
      <SifraRabotenOdnos>0050</SifraRabotenOdnos>
      <BrojDogovor>1</BrojDogovor>
      <DatumPocetok>1.05.2026</DatumPocetok>
      <DatumZavrsuvanje>31.05.2026</DatumZavrsuvanje>
      <DenoviStaz>31</DenoviStaz>
      <SifraTipRabotenCas>001</SifraTipRabotenCas>
      <SifraDvizenje>1</SifraDvizenje>
      <SifraPodracnoZdravstvo>4061</SifraPodracnoZdravstvo>
      <SifraOsloboduvanje></SifraOsloboduvanje>
      <NadlezenOrganEdb></NadlezenOrganEdb>
      <BrojCasovi>168</BrojCasovi>
      <BrutoIznos>38507</BrutoIznos>
    </MpinCalculationStDetail>
  </MpinCalculationSt>
</MpinCalculation>
```

- [ ] **Step 2: Напиши го еталон-тестот што паѓа**

`tests/Feature/Payroll/MpinDocumentBuilderTest.php`:

```php
<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\PayrollRunService;
use App\Support\Payroll\Mpin\MpinDocumentBuilder;
use App\Support\Payroll\MpinObvrznik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MpinDocumentBuilderTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $employeeOverrides */
    private function confirmedRun(
        Company $company,
        array $employeeOverrides,
        float $gross,
        int $month,
    ): PayrollRun {
        $employee = Employee::factory()->for($company)->create([
            'exemption_code' => null,
            'terminated_on' => null,
            'prior_service_months' => 0,
            ...$employeeOverrides,
        ]);

        EmployeeSalary::create([
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'amount' => $gross,
            'basis' => 'gross',
        ]);

        // confirmed_by е странски клуч кон users, па мора да е вистински корисник.
        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        return $service->confirm($service->open($company, 2026, $month), $user->id)->fresh();
    }

    public function test_an_obvrznik_110_filing_is_reproduced_byte_for_byte(): void
    {
        $company = Company::factory()->create([
            'name' => 'DESIGNIA DOOEL',
            'tax_id' => '4080000000000',
            'mpin_obvrznik_code' => MpinObvrznik::EMPLOYER,
        ]);

        $run = $this->confirmedRun($company, [
            'embg' => '0101990450006',
            'municipality_code' => '130',
            'health_area_code' => '4061',
            'bank_account' => '300000000000000',
            'insurance_type_code' => '0050',
            'movement_code' => '1',
            'weekly_hours' => 40,
            'employed_on' => '2026-01-01',
        ], 38507, 5);

        $this->assertSame(
            file_get_contents(base_path('tests/Fixtures/mpin/obvrznik-110.xml')),
            MpinDocumentBuilder::build($run),
        );
    }

    public function test_the_file_name_matches_what_the_mpin_client_uses(): void
    {
        $company = Company::factory()->create([
            'name' => 'DESIGNIA DOOEL',
            'mpin_obvrznik_code' => MpinObvrznik::EMPLOYER,
        ]);

        $run = PayrollRun::factory()->for($company)->create([
            'year' => 2026,
            'month' => 5,
        ]);

        $this->assertSame(
            'DESIGNIA DOOEL_2026_05_101.xml',
            MpinDocumentBuilder::fileName($run),
        );
    }
}
```

`PayrollRunFactory` веќе постои. Прочитај ги неговите стандардни вредности пред да се потпреш на нив — ако не поставува `payroll_parameter_id` или `month_hours`, постави ги во тестот.

Ако `employed_on` во еталонот мора да даде `DatumPocetok` = `1.05.2026`, тоа значи датумот на вработување е **пред** мај, што овој тест го обезбедува со `2026-01-01`.

- [ ] **Step 3: Пушти го и потврди дека паѓа**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Payroll/MpinDocumentBuilderTest.php`
Expected: FAIL — класата не постои.

- [ ] **Step 4: Напиши го градителот**

`app/Support/Payroll/Mpin/MpinDocumentBuilder.php`:

```php
<?php

namespace App\Support\Payroll\Mpin;

use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use App\Models\PayrollRunLine;
use App\Support\Payroll\MpinObvrznik;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;

/**
 * Гради МПИН XML од потврдена пресметка.
 *
 * Форматите не се измислени: секој е препишан од две вистински датотеки што
 * УЈП ги прифатила, и еталон-тестовите бараат излезот да им биде знак по знак
 * ист. Ниту едно правило овде не смее да се „поправи" затоа што изгледа чудно
 * — денот без водечка нула наспроти месецот со неа изгледа како грешка и не е.
 */
final class MpinDocumentBuilder
{
    /** Видот на обврска за редовна месечна плата. */
    public const VID_OBVRSKA = '101';

    public static function fileName(PayrollRun $run): string
    {
        return sprintf(
            '%s_%d_%02d_%s.xml',
            $run->company->name,
            $run->year,
            $run->month,
            self::VID_OBVRSKA,
        );
    }

    public static function build(PayrollRun $run): string
    {
        $run->loadMissing(['company', 'employees.employee', 'employees.lines']);

        $dom = new DOMDocument('1.0', 'utf-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('MpinCalculation');
        // Редоследот на овие два атрибута е тој од вистинските датотеки.
        // setAttribute го чува редоследот на внесување; setAttributeNS не го чува.
        $root->setAttribute('xsi:noNamespaceSchemaLocation', 'schema.xsd');
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $dom->appendChild($root);

        $obvrznik = $run->company->mpin_obvrznik_code ?? MpinObvrznik::EMPLOYER;
        $rows = $run->employees->values();
        $totals = self::totals($rows, $obvrznik);

        self::add($dom, $root, 'EdbIsplatitel', (string) $run->company->tax_id);
        self::add($dom, $root, 'SifraVidObvrznik', $obvrznik->value);
        self::add($dom, $root, 'VoImeNaObvrznikEdb', (string) $run->company->tax_id);
        self::add($dom, $root, 'SifraVidObvrska', self::VID_OBVRSKA);
        self::add($dom, $root, 'MesecPridonesi', sprintf('%02d', $run->month));
        self::add($dom, $root, 'GodinaPridonesi', (string) $run->year);
        self::add($dom, $root, 'BrojVraboteni', (string) $rows->count());

        foreach (self::AMOUNT_FIELDS as $suffix => $key) {
            self::add($dom, $root, $suffix.'Vk', (string) $totals[$key]);
        }

        self::add($dom, $root, 'Zabeleska', '');

        foreach ($rows as $index => $row) {
            $root->appendChild(self::employeeNode($dom, $run, $row, $index + 1, $obvrznik));
        }

        return $dom->saveXML(null, LIBXML_NOEMPTYTAG);
    }

    /**
     * Име на полето во XML (без наставката) => клуч во нашата пресметка.
     *
     * Редоследот е обврзувачки: шемата е `xs:sequence`, значи редоследот на
     * полињата е дел од валидноста, не козметика.
     *
     * @var array<string, string>
     */
    private const AMOUNT_FIELDS = [
        'BrutoIznos' => 'gross',
        'ZadPIOIznos' => 'pension',
        'DopPIOIznos' => 'zero',
        'ZadFZOIznos' => 'health',
        'DopFZOIznos' => 'zero',
        'ZadPovredaRabIznos' => 'injury',
        'DopPovredaRabIznos' => 'zero',
        'ZadVrabotuvanjeIznos' => 'unemployment',
        'DopVrabotuvanjeIznos' => 'zero',
        'ZadBenefStazIznos' => 'zero',
        'DopBenefStazIznos' => 'zero',
        'PersonalenDanokIznos' => 'tax',
        'NetoIznos' => 'net',
        'EfektivnoNetoIznos' => 'effectiveNet',
        'DanocnoOslobIznos' => 'taxRelief',
    ];

    /** @return array<string, int> */
    private static function amounts(PayrollRunEmployee $row, MpinObvrznik $obvrznik): array
    {
        // Даночното намалување не се чува како колона зашто не мора:
        // taxBase = max(gross − contributions − allowance, 0), па
        // gross − contributions − taxBase е точно искористеното намалување.
        // За обврзник 111 даночната основица е нула по дефиниција, па
        // формулата не важи и се пишува буквална нула.
        $taxRelief = $obvrznik->chargesMonthlyTax()
            ? (int) round($row->gross - $row->contributions - $row->tax_base)
            : 0;

        // Ефективното нето е нето минус задршки — така го дефинира помошта на
        // МПИН клиентот и така стои во вистинската датотека за обврзник 110.
        // Вистинската датотека за 111 пишува нула иако нето-то не е нула;
        // тоа е особеност на таа категорија, потврдена со примерок.
        $effectiveNet = $obvrznik->chargesMonthlyTax()
            ? (int) round($row->effective_net)
            : 0;

        return [
            'gross' => (int) round($row->gross),
            'pension' => (int) round($row->pension),
            'health' => (int) round($row->health),
            'injury' => (int) round($row->injury),
            'unemployment' => (int) round($row->unemployment),
            'tax' => (int) round($row->tax),
            'net' => (int) round($row->net),
            'effectiveNet' => $effectiveNet,
            'taxRelief' => $taxRelief,
            'zero' => 0,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PayrollRunEmployee>  $rows
     * @return array<string, int>
     */
    private static function totals($rows, MpinObvrznik $obvrznik): array
    {
        // Збир на веќе заокружените износи, не заокружен збир на неокружените.
        // Инаку ниво 1 нема да се совпадне со збирот на ниво 2, а УЈП го
        // проверува тоа.
        // Почнува од нули, за да ги испише сите полиња и кога пресметката нема
        // ниту еден работник. Валидаторот ја одбива таквата, но градителот не
        // смее да падне на неа.
        $totals = array_fill_keys(array_values(self::AMOUNT_FIELDS), 0);

        foreach ($rows as $row) {
            foreach (self::amounts($row, $obvrznik) as $key => $value) {
                $totals[$key] += $value;
            }
        }

        $totals['zero'] = 0;

        return $totals;
    }

    private static function employeeNode(
        DOMDocument $dom,
        PayrollRun $run,
        PayrollRunEmployee $row,
        int $ordinal,
        MpinObvrznik $obvrznik,
    ): DOMElement {
        $node = $dom->createElement('MpinCalculationSt');
        $employee = $row->employee;
        $amounts = self::amounts($row, $obvrznik);

        $hourLines = $row->lines
            ->where('kind', PayrollRunLine::KIND_HOURS)
            ->values();

        self::add($dom, $node, 'RedenBroj', (string) $ordinal);
        self::add($dom, $node, 'VrabotenEmbg', (string) $employee->embg);
        self::add($dom, $node, 'SifraOpstina', (string) $employee->municipality_code);
        self::add($dom, $node, 'TransakciskaSmetka', (string) $employee->bank_account);
        self::add($dom, $node, 'DenoviStazVkVrab', (string) $row->staz_days);

        foreach (self::AMOUNT_FIELDS as $suffix => $key) {
            self::add($dom, $node, $suffix.'VkVrab', (string) $amounts[$key]);
        }

        self::add($dom, $node, 'RabotniCasoviVkVrab', (string) $hourLines->sum('hours'));

        foreach ($hourLines as $index => $line) {
            $node->appendChild(self::detailNode(
                $dom,
                $run,
                $row,
                $line,
                $index + 1,
                // Сите денови стаж одат на првата линија со часови, а остатокот
                // добива нула, така што збирот на ниво 3 останува еднаков на
                // ниво 2. Поделбата на повеќе линии не е потврдена од ниту еден
                // примерок — види го отвореното прашање во спецификацијата.
                $index === 0 ? $row->staz_days : 0,
            ));
        }

        return $node;
    }

    private static function detailNode(
        DOMDocument $dom,
        PayrollRun $run,
        PayrollRunEmployee $row,
        PayrollRunLine $line,
        int $ordinal,
        int $stazDays,
    ): DOMElement {
        $node = $dom->createElement('MpinCalculationStDetail');
        $employee = $row->employee;

        $monthStart = CarbonImmutable::create($run->year, $run->month, 1);
        $monthEnd = $monthStart->endOfMonth();

        $from = CarbonImmutable::parse($employee->employed_on)->max($monthStart);
        $to = $employee->terminated_on
            ? CarbonImmutable::parse($employee->terminated_on)->min($monthEnd)
            : $monthEnd;

        self::add($dom, $node, 'RedenBroj', (string) $ordinal);
        self::add($dom, $node, 'SifraRabotenOdnos', (string) $employee->insurance_type_code);
        // Двете вистински датотеки носат 1. Не се пренаменува
        // employees.registration_number, кој значи нешто друго.
        self::add($dom, $node, 'BrojDogovor', '1');
        self::add($dom, $node, 'DatumPocetok', self::date($from));
        self::add($dom, $node, 'DatumZavrsuvanje', self::date($to));
        self::add($dom, $node, 'DenoviStaz', (string) $stazDays);
        self::add($dom, $node, 'SifraTipRabotenCas', (string) $line->code);
        self::add($dom, $node, 'SifraDvizenje', (string) $employee->movement_code);
        self::add($dom, $node, 'SifraPodracnoZdravstvo', (string) $employee->health_area_code);
        self::add($dom, $node, 'SifraOsloboduvanje', (string) $employee->exemption_code);
        self::add($dom, $node, 'NadlezenOrganEdb', '');
        self::add($dom, $node, 'BrojCasovi', (string) $line->hours);
        self::add($dom, $node, 'BrutoIznos', (string) (int) round($line->amount));

        return $node;
    }

    /**
     * Денот е БЕЗ водечка нула, месецот СО неа: `1.05.2026`. Изгледа како
     * недоследност и не е — така пишува МПИН клиентот.
     */
    private static function date(CarbonImmutable $date): string
    {
        return $date->format('j.m.Y');
    }

    private static function add(DOMDocument $dom, DOMElement $parent, string $name, string $value): void
    {
        $parent->appendChild($dom->createElement($name, $value));
    }
}
```

Пресметката патува како аргумент низ `employeeNode()` и `detailNode()`, наместо да се чита назад преку врска од редот. Тоа е една зависност помалку и едно барање помалку.

- [ ] **Step 5: Пушти ги тестовите**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Payroll/MpinDocumentBuilderTest.php`
Expected: PASS

Ако тестот падне со разлика во еден знак, спореди со `diff`: испиши го произведениот XML во `storage/app/private/mpin-debug.xml` и пушти `diff storage/app/private/mpin-debug.xml tests/Fixtures/mpin/obvrznik-110.xml`. Никогаш не менувај го еталонот за да поминe тестот — еталонот е она што УЈП веќе го прифатила.

- [ ] **Step 6: Commit**

```bash
git add tests/Fixtures/mpin/obvrznik-110.xml app/Support/Payroll/Mpin/MpinDocumentBuilder.php tests/Feature/Payroll/MpinDocumentBuilderTest.php
git commit -m "feat(payroll): build the МПИН document, byte for byte"
```

---

### Task 8: Еталонот за самостоен вршител и форматите

**Files:**
- Create: `tests/Fixtures/mpin/obvrznik-111.xml`
- Test: `tests/Feature/Payroll/MpinDocumentBuilderTest.php`

**Interfaces:**
- Consumes: `MpinDocumentBuilder` од Task 7
- Produces: ништо ново — потврдува дека патот за обврзник 111 и форматите се точни.

- [ ] **Step 1: Создај го еталонот за обврзник 111**

`tests/Fixtures/mpin/obvrznik-111.xml`. Вистинска поднесена датотека од јануари 2026, со заменети ЕДБ, ЕМБГ и сметка. Работникот е со **неполно работно време** — 88 часа при фонд од 176, и шифра `0047`.

```xml
<?xml version="1.0" encoding="utf-8"?>
<MpinCalculation xsi:noNamespaceSchemaLocation="schema.xsd" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <EdbIsplatitel>4090000000000</EdbIsplatitel>
  <SifraVidObvrznik>111</SifraVidObvrznik>
  <VoImeNaObvrznikEdb>4090000000000</VoImeNaObvrznikEdb>
  <SifraVidObvrska>101</SifraVidObvrska>
  <MesecPridonesi>01</MesecPridonesi>
  <GodinaPridonesi>2026</GodinaPridonesi>
  <BrojVraboteni>1</BrojVraboteni>
  <BrutoIznosVk>34571</BrutoIznosVk>
  <ZadPIOIznosVk>6499</ZadPIOIznosVk>
  <DopPIOIznosVk>0</DopPIOIznosVk>
  <ZadFZOIznosVk>2593</ZadFZOIznosVk>
  <DopFZOIznosVk>0</DopFZOIznosVk>
  <ZadPovredaRabIznosVk>173</ZadPovredaRabIznosVk>
  <DopPovredaRabIznosVk>0</DopPovredaRabIznosVk>
  <ZadVrabotuvanjeIznosVk>0</ZadVrabotuvanjeIznosVk>
  <DopVrabotuvanjeIznosVk>0</DopVrabotuvanjeIznosVk>
  <ZadBenefStazIznosVk>0</ZadBenefStazIznosVk>
  <DopBenefStazIznosVk>0</DopBenefStazIznosVk>
  <PersonalenDanokIznosVk>0</PersonalenDanokIznosVk>
  <NetoIznosVk>25306</NetoIznosVk>
  <EfektivnoNetoIznosVk>0</EfektivnoNetoIznosVk>
  <DanocnoOslobIznosVk>0</DanocnoOslobIznosVk>
  <Zabeleska></Zabeleska>
  <MpinCalculationSt>
    <RedenBroj>1</RedenBroj>
    <VrabotenEmbg>1503880410003</VrabotenEmbg>
    <SifraOpstina>182</SifraOpstina>
    <TransakciskaSmetka>300000000000001</TransakciskaSmetka>
    <DenoviStazVkVrab>31</DenoviStazVkVrab>
    <BrutoIznosVkVrab>34571</BrutoIznosVkVrab>
    <ZadPIOIznosVkVrab>6499</ZadPIOIznosVkVrab>
    <DopPIOIznosVkVrab>0</DopPIOIznosVkVrab>
    <ZadFZOIznosVkVrab>2593</ZadFZOIznosVkVrab>
    <DopFZOIznosVkVrab>0</DopFZOIznosVkVrab>
    <ZadPovredaRabIznosVkVrab>173</ZadPovredaRabIznosVkVrab>
    <DopPovredaRabIznosVkVrab>0</DopPovredaRabIznosVkVrab>
    <ZadVrabotuvanjeIznosVkVrab>0</ZadVrabotuvanjeIznosVkVrab>
    <DopVrabotuvanjeIznosVkVrab>0</DopVrabotuvanjeIznosVkVrab>
    <ZadBenefStazIznosVkVrab>0</ZadBenefStazIznosVkVrab>
    <DopBenefStazIznosVkVrab>0</DopBenefStazIznosVkVrab>
    <PersonalenDanokIznosVkVrab>0</PersonalenDanokIznosVkVrab>
    <NetoIznosVkVrab>25306</NetoIznosVkVrab>
    <EfektivnoNetoIznosVkVrab>0</EfektivnoNetoIznosVkVrab>
    <DanocnoOslobIznosVkVrab>0</DanocnoOslobIznosVkVrab>
    <RabotniCasoviVkVrab>88</RabotniCasoviVkVrab>
    <MpinCalculationStDetail>
      <RedenBroj>1</RedenBroj>
      <SifraRabotenOdnos>0047</SifraRabotenOdnos>
      <BrojDogovor>1</BrojDogovor>
      <DatumPocetok>1.01.2026</DatumPocetok>
      <DatumZavrsuvanje>31.01.2026</DatumZavrsuvanje>
      <DenoviStaz>31</DenoviStaz>
      <SifraTipRabotenCas>001</SifraTipRabotenCas>
      <SifraDvizenje>1</SifraDvizenje>
      <SifraPodracnoZdravstvo>4061</SifraPodracnoZdravstvo>
      <SifraOsloboduvanje>001</SifraOsloboduvanje>
      <NadlezenOrganEdb></NadlezenOrganEdb>
      <BrojCasovi>88</BrojCasovi>
      <BrutoIznos>34571</BrutoIznos>
    </MpinCalculationStDetail>
  </MpinCalculationSt>
</MpinCalculation>
```

- [ ] **Step 2: Напиши ги тестовите што паѓаат**

Додај во класата `tests/Feature/Payroll/MpinDocumentBuilderTest.php`, употребувајќи го помошникот `confirmedRun()` од Task 7.

Форматните тестови намерно го проверуваат **произведениот** XML, не еталонот: еталон што сам себе се проверува не докажува ништо.

```php
    public function test_an_obvrznik_111_part_time_filing_is_reproduced_byte_for_byte(): void
    {
        $company = Company::factory()->create([
            'name' => 'ADVOKAT STEFAN KOTEV',
            'tax_id' => '4090000000000',
            'mpin_obvrznik_code' => MpinObvrznik::SELF_EMPLOYED,
        ]);

        $run = $this->confirmedRun($company, [
            'embg' => '1503880410003',
            'municipality_code' => '182',
            'health_area_code' => '4061',
            'bank_account' => '300000000000001',
            'insurance_type_code' => '0047',
            'movement_code' => '1',
            'exemption_code' => '001',
            'weekly_hours' => 20,
            'employed_on' => '2020-01-01',
        ], 34571, 1);

        $this->assertSame(
            file_get_contents(base_path('tests/Fixtures/mpin/obvrznik-111.xml')),
            MpinDocumentBuilder::build($run),
        );
    }

    public function test_an_empty_element_is_not_self_closing(): void
    {
        $xml = $this->designiaXml();

        $this->assertStringContainsString('<Zabeleska></Zabeleska>', $xml);
        $this->assertStringContainsString('<NadlezenOrganEdb></NadlezenOrganEdb>', $xml);
        $this->assertStringNotContainsString('/>', $xml);
    }

    public function test_the_day_has_no_leading_zero_but_the_month_does(): void
    {
        $xml = $this->designiaXml();

        $this->assertStringContainsString('<DatumPocetok>1.05.2026</DatumPocetok>', $xml);
        $this->assertStringNotContainsString('<DatumPocetok>01.05.2026</DatumPocetok>', $xml);
    }

    public function test_no_amount_carries_a_decimal(): void
    {
        $this->assertSame(
            0,
            preg_match('/<[A-Za-z]*Iznos[A-Za-z]*>-?[0-9]+\.[0-9]+</', $this->designiaXml()),
        );
    }
```

Помошникот што го гради истиот XML што првиот еталон-тест го проверува — извади го тоа повторување од Task 7 во приватен метод:

```php
    private function designiaXml(): string
    {
        $company = Company::factory()->create([
            'name' => 'DESIGNIA DOOEL',
            'tax_id' => '4080000000000',
            'mpin_obvrznik_code' => MpinObvrznik::EMPLOYER,
        ]);

        return MpinDocumentBuilder::build($this->confirmedRun($company, [
            'embg' => '0101990450006',
            'municipality_code' => '130',
            'health_area_code' => '4061',
            'bank_account' => '300000000000000',
            'insurance_type_code' => '0050',
            'movement_code' => '1',
            'weekly_hours' => 40,
            'employed_on' => '2026-01-01',
        ], 38507, 5));
    }
```

`test_an_obvrznik_110_filing_is_reproduced_byte_for_byte` од Task 7 сега исто го вика `designiaXml()` наместо да го повторува пополнувањето.

- [ ] **Step 3: Пушти ги и потврди дека еталонот за 111 паѓа**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Payroll/MpinDocumentBuilderTest.php`
Expected: тестовите за формат PASS (проверуваат само еталон), еталонот за 111 или PASS ако Tasks 4–6 се точни, или FAIL со јасна разлика.

Ако падне на часовите (176 наместо 88), Task 4 не е завршена. Ако падне на данокот, Task 5 не е завршена.

- [ ] **Step 4: Поправи го она што паѓа, во кодот, никогаш во еталонот**

- [ ] **Step 5: Пушти ги тестовите**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Payroll`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add tests/Fixtures/mpin/obvrznik-111.xml tests/Feature/Payroll/MpinDocumentBuilderTest.php
git commit -m "test(payroll): hold the builder against a real 111 filing"
```

---

### Task 9: Проверките пред извоз

**Files:**
- Create: `app/Support/Payroll/Mpin/MpinValidator.php`
- Test: `tests/Feature/Payroll/MpinValidatorTest.php`

**Interfaces:**
- Consumes: `PayrollRun`
- Produces:
  - `MpinValidator::check(PayrollRun $run): MpinValidationResult`
  - `MpinValidationResult` — `readonly` со јавни `array $errors` и `array $warnings` (обете `list<string>`), плус **метод** `passes(): bool` што е `$errors === []`. Метод, не својство — сите повикувачи го викаат како `$result->passes()`.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Прво заедничкиот помошник, зашто Task 10 му треба истиот. Во PHPUnit тоа е **trait**, не глобална функција.

`tests/Feature/Payroll/Concerns/BuildsMpinRuns.php`:

```php
<?php

namespace Tests\Feature\Payroll\Concerns;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\PayrollRunService;
use App\Support\Payroll\MpinObvrznik;

trait BuildsMpinRuns
{
    /**
     * Потврдена пресметка за мај 2026 што одговара на вистинската поднесена
     * датотека на обврзник 110. Отстапувањата се предаваат како преклопувања,
     * за секој тест да менува точно едно нешто.
     *
     * @param  array<string, mixed>  $companyOverrides
     * @param  array<string, mixed>  $employeeOverrides
     */
    private function mpinRun(array $companyOverrides = [], array $employeeOverrides = []): PayrollRun
    {
        $company = Company::factory()->create([
            'name' => 'DESIGNIA DOOEL',
            'tax_id' => '4080000000000',
            'mpin_obvrznik_code' => MpinObvrznik::EMPLOYER,
            ...$companyOverrides,
        ]);

        $employee = Employee::factory()->for($company)->create([
            'embg' => '0101990450006',
            'municipality_code' => '130',
            'health_area_code' => '4061',
            'bank_account' => '300000000000000',
            'insurance_type_code' => '0050',
            'movement_code' => '1',
            'weekly_hours' => 40,
            'employed_on' => '2026-01-01',
            'terminated_on' => null,
            'prior_service_months' => 0,
            ...$employeeOverrides,
        ]);

        EmployeeSalary::create([
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'amount' => 38507,
            'basis' => 'gross',
        ]);

        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        return $service->confirm($service->open($company, 2026, 5), $user->id)->fresh();
    }
}
```

`tests/Feature/Payroll/MpinValidatorTest.php`:

```php
<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Services\Payroll\PayrollRunService;
use App\Support\Payroll\Mpin\MpinValidator;
use App\Support\Payroll\MpinObvrznik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Payroll\Concerns\BuildsMpinRuns;
use Tests\TestCase;

class MpinValidatorTest extends TestCase
{
    use BuildsMpinRuns, RefreshDatabase;

    public function test_a_sound_run_passes(): void
    {
        $result = MpinValidator::check($this->mpinRun());

        $this->assertTrue($result->passes());
        $this->assertSame([], $result->errors);
    }

    public function test_a_draft_run_is_not_exported(): void
    {
        $company = Company::factory()->create([
            'mpin_obvrznik_code' => MpinObvrznik::EMPLOYER,
        ]);
        $run = app(PayrollRunService::class)->open($company, 2026, 5);

        $result = MpinValidator::check($run);

        $this->assertFalse($result->passes());
        $this->assertContains('Пресметката мора да биде потврдена пред извоз.', $result->errors);
    }

    public function test_a_company_without_an_obvrznik_type_is_not_exported(): void
    {
        $result = MpinValidator::check($this->mpinRun(['mpin_obvrznik_code' => null]));

        $this->assertContains('Фирмата нема внесен вид обврзник за МПИН.', $result->errors);
    }

    public function test_a_company_without_a_tax_id_is_not_exported(): void
    {
        $result = MpinValidator::check($this->mpinRun(['tax_id' => null]));

        $this->assertContains('Фирмата нема внесен ЕДБ.', $result->errors);
    }

    public function test_an_employee_without_a_health_area_is_not_exported(): void
    {
        $result = MpinValidator::check($this->mpinRun([], ['health_area_code' => null]));

        $this->assertContains(
            'Марко Петровски: нема внесена подрачна здравствена служба.',
            $result->errors,
        );
    }

    public function test_zero_days_of_service_blocks_the_export(): void
    {
        $run = $this->mpinRun();
        $run->employees->first()->update(['staz_days' => 0]);

        $result = MpinValidator::check($run->fresh());

        $this->assertContains(
            'Марко Петровски: нула денови стаж — датумите на вработување не го покриваат месецот.',
            $result->errors,
        );
    }

    public function test_a_part_time_code_on_a_full_fund_warns_but_does_not_block(): void
    {
        $result = MpinValidator::check($this->mpinRun([], ['insurance_type_code' => '0047']));

        $this->assertTrue($result->passes());
        $this->assertNotSame([], $result->warnings);
    }
}
```

Името „Марко Петровски" доаѓа од `EmployeeFactory`; ако фабриката се смени, промени го и тука.

- [ ] **Step 2: Пушти ги и потврди дека паѓаат**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Payroll/MpinValidatorTest.php`
Expected: FAIL — класата не постои.

- [ ] **Step 3: Напиши го резултатот**

`app/Support/Payroll/Mpin/MpinValidationResult.php`:

```php
<?php

namespace App\Support\Payroll\Mpin;

readonly class MpinValidationResult
{
    /**
     * @param  list<string>  $errors    блокираат извоз
     * @param  list<string>  $warnings  се прикажуваат, не блокираат
     */
    public function __construct(
        public array $errors,
        public array $warnings,
    ) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }
}
```

`passes()` е метод, не својство. Сите повикувачи низ Task 9, 10 и 11 го викаат како `$result->passes()`.

- [ ] **Step 4: Напиши го валидаторот**

`app/Support/Payroll/Mpin/MpinValidator.php`:

```php
<?php

namespace App\Support\Payroll\Mpin;

use App\Models\PayrollRun;
use App\Models\PayrollRunLine;

/**
 * Проверките што УЈП сама ги врти по поднесување, направени пред симнување.
 *
 * Изворот е `poraki.html` од помошта на МПИН клиентот, каде секоја порака носи
 * тежина: 2 = грешка, 1 = предупредување. Истата поделба се задржува овде, за
 * да не се блокира извоз поради нешто што УЈП само би го предупредила.
 */
final class MpinValidator
{
    public static function check(PayrollRun $run): MpinValidationResult
    {
        $run->loadMissing(['company', 'employees.employee', 'employees.lines']);

        $errors = [];
        $warnings = [];

        if ($run->isDraft()) {
            $errors[] = 'Пресметката мора да биде потврдена пред извоз.';
        }

        if (! $run->company->tax_id) {
            $errors[] = 'Фирмата нема внесен ЕДБ.';
        }

        if (! $run->company->mpin_obvrznik_code) {
            $errors[] = 'Фирмата нема внесен вид обврзник за МПИН.';
        }

        if ($run->employees->isEmpty()) {
            $errors[] = 'Пресметката нема ниту еден работник.';
        }

        foreach ($run->employees as $row) {
            $employee = $row->employee;
            $name = trim($employee->first_name.' '.$employee->last_name);

            foreach ([
                'embg' => 'ЕМБГ',
                'bank_account' => 'трансакциска сметка',
                'municipality_code' => 'шифра на општина',
                'health_area_code' => 'подрачна здравствена служба',
                'insurance_type_code' => 'шифра за вид на стаж',
            ] as $column => $label) {
                if (! $employee->{$column}) {
                    $errors[] = "{$name}: нема внесена {$label}.";
                }
            }

            // Нулата е намерна ознака за аномалија од фазата за делумни месеци:
            // работник чии датуми се сменети откако пресметката е отворена. УЈП
            // фиксира вредност меѓу 1 и бројот денови во месецот, па нулата е
            // нелегална и мора да запре тука.
            if ($row->staz_days < 1) {
                $errors[] = "{$name}: нула денови стаж — датумите на вработување не го покриваат месецот.";
            }

            if (round($row->gross) <= 0) {
                $errors[] = "{$name}: бруто износот е нула, што УЈП не го прифаќа без потврда.";
            }

            $hourLines = $row->lines->where('kind', PayrollRunLine::KIND_HOURS);

            if ($hourLines->isEmpty()) {
                $errors[] = "{$name}: нема ниту една линија со часови.";
            }

            foreach ($hourLines as $line) {
                if ((int) $line->hours > 0 && round($line->amount) <= 0) {
                    $errors[] = "{$name}: линијата „{$line->description}" има часови без износ.";
                }

                if ((int) $line->hours <= 0 && round($line->amount) > 0) {
                    $errors[] = "{$name}: линијата „{$line->description}" има износ без часови.";
                }
            }

            // Предупредување, не грешка: работник со полно работно време што
            // бил на боледување легитимно има помалку часови од фондот.
            $fund = $employee->monthFund($run->month_hours);
            $worked = (int) $hourLines->sum('hours');

            if ($employee->insurance_type_code === '0047' && $worked >= $run->month_hours) {
                $warnings[] = "{$name}: шифрата 0047 значи неполно работно време, а часовите се како за полно.";
            }

            if ($employee->insurance_type_code === '0050' && $worked < $fund) {
                $warnings[] = "{$name}: шифрата 0050 значи полно работно време, а часовите се помалку од фондот.";
            }
        }

        return new MpinValidationResult(array_values($errors), array_values($warnings));
    }
}
```

Внимавај на наводниците во пораките: во PHP низа со двојни наводници, знаците `„` и `"` се обични знаци и не бараат бегство, но проверете дека фајлот е зачуван како UTF-8.

- [ ] **Step 5: Пушти ги тестовите**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Payroll/MpinValidatorTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Support/Payroll/Mpin/MpinValidator.php app/Support/Payroll/Mpin/MpinValidationResult.php tests/Feature/Payroll/MpinValidatorTest.php
git commit -m "feat(payroll): refuse an МПИН export УЈП would reject"
```

---

### Task 10: Симнување и евиденција

**Files:**
- Create: `database/migrations/2026_08_19_100300_add_mpin_export_columns_to_payroll_runs_table.php`
- Create: `app/Http/Controllers/MpinExportController.php`
- Modify: `app/Models/PayrollRun.php` (`$fillable`, `casts()`)
- Modify: `routes/web.php:142` (во групата `payroll.`)
- Test: `tests/Feature/Payroll/MpinExportTest.php`

**Interfaces:**
- Consumes: `MpinDocumentBuilder`, `MpinValidator`
- Produces: рута `payroll.mpin-export` на `/companies/{company}/payroll-runs/{run}/mpin.xml`

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

`tests/Feature/Payroll/MpinExportTest.php`, употребувајќи го истиот trait од Task 9:

```php
<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Feature\Payroll\Concerns\BuildsMpinRuns;
use Tests\TestCase;

class MpinExportTest extends TestCase
{
    use BuildsMpinRuns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_a_confirmed_run_downloads_as_xml(): void
    {
        $run = $this->mpinRun();

        $response = $this->actingAs($this->admin())
            ->get(route('payroll.mpin-export', [$run->company, $run]));

        $response->assertOk();

        $this->assertStringContainsString(
            'DESIGNIA DOOEL_2026_05_101.xml',
            (string) $response->headers->get('content-disposition'),
        );

        $this->assertSame(
            file_get_contents(base_path('tests/Fixtures/mpin/obvrznik-110.xml')),
            $response->getContent(),
        );
    }

    public function test_the_export_is_recorded(): void
    {
        $run = $this->mpinRun();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('payroll.mpin-export', [$run->company, $run]));

        $run->refresh();

        $this->assertNotNull($run->mpin_exported_at);
        $this->assertSame($admin->id, $run->mpin_exported_by);
    }

    public function test_a_run_with_errors_does_not_download(): void
    {
        $run = $this->mpinRun(['mpin_obvrznik_code' => null]);

        $this->actingAs($this->admin())
            ->get(route('payroll.mpin-export', [$run->company, $run]))
            ->assertRedirect();

        $this->assertNull($run->fresh()->mpin_exported_at);
    }

    public function test_a_run_from_another_company_is_not_reachable(): void
    {
        $run = $this->mpinRun();
        $other = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('payroll.mpin-export', [$other, $run]))
            ->assertNotFound();
    }
}
```

Рутата е зад `EnsureAccountingAccess`, па корисникот мора да има улога што ја поминува таа проверка — затоа `admin`. Прочитај го `tests/Feature/Payroll/PayrollAccessTest.php` ако е потребно појаснување за кои улоги поминуваат.

- [ ] **Step 2: Пушти ги и потврди дека паѓаат**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Payroll/MpinExportTest.php`
Expected: FAIL — рутата не постои.

- [ ] **Step 3: Напиши ја миграцијата**

`database/migrations/2026_08_19_100300_add_mpin_export_columns_to_payroll_runs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->timestamp('mpin_exported_at')->nullable();
            $table->foreignId('mpin_exported_by')->nullable()->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mpin_exported_by');
            $table->dropColumn('mpin_exported_at');
        });
    }
};
```

Додај `'mpin_exported_at'` и `'mpin_exported_by'` во `$fillable` на `PayrollRun`, и `'mpin_exported_at' => 'datetime'` во `casts()`.

- [ ] **Step 4: Напиши го контролерот**

`app/Http/Controllers/MpinExportController.php`, по образецот на `PayrollRecapPdfController`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PayrollRun;
use App\Support\Payroll\Mpin\MpinDocumentBuilder;
use App\Support\Payroll\Mpin\MpinValidator;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class MpinExportController extends Controller
{
    public function __invoke(Company $company, PayrollRun $run): Response
    {
        Gate::authorize('view', $company);
        abort_unless($run->company_id === $company->id, 404);

        $result = MpinValidator::check($run);

        if (! $result->passes()) {
            // Грешките се прикажуваат на самиот екран на пресметката, не тука:
            // ова е симнување, а симнувањето не може да рендерира порака.
            return back()->with('mpin_errors', $result->errors);
        }

        $run->forceFill([
            'mpin_exported_at' => now(),
            'mpin_exported_by' => auth()->id(),
        ])->save();

        $xml = MpinDocumentBuilder::build($run);

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.MpinDocumentBuilder::fileName($run).'"',
        ]);
    }
}
```

- [ ] **Step 5: Регистрирај ја рутата**

`routes/web.php`, во групата `payroll.` (околу ред 142), **пред** групата `payroll-runs.`, по истата причина како `recap.pdf` — инаку `/payroll-runs/{run}` го проголтува:

```php
Route::get('/payroll-runs/{run}/mpin.xml', MpinExportController::class)->name('mpin-export');
```

- [ ] **Step 6: Пушти ги тестовите**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Payroll/MpinExportTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_19_100300_add_mpin_export_columns_to_payroll_runs_table.php app/Http/Controllers/MpinExportController.php app/Models/PayrollRun.php routes/web.php tests/Feature/Payroll/MpinExportTest.php tests/Pest.php
git commit -m "feat(payroll): download the МПИН file from a confirmed run"
```

---

### Task 11: Копчето на екранот и затворање на фазата

**Files:**
- Modify: `app/Livewire/Payroll/PayrollRunShow.php`
- Modify: `resources/views/livewire/payroll/payroll-run-show.blade.php`
- Test: `tests/Feature/Payroll/PayrollRunShowTest.php`

**Interfaces:**
- Consumes: сето претходно
- Produces: ништо ново

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Додај во постоечката класа `tests/Feature/Payroll/PayrollRunShowTest.php`, и додај му го trait-от `BuildsMpinRuns` од Task 9:

```php
    public function test_the_mpin_button_shows_only_on_a_confirmed_run(): void
    {
        $run = $this->mpinRun();

        Livewire::actingAs($this->mpinAdmin())
            ->test(PayrollRunShow::class, ['company' => $run->company, 'run' => $run])
            ->assertSee('Извези МПИН');
    }

    public function test_a_draft_run_offers_no_export(): void
    {
        $company = Company::factory()->create();
        $run = app(PayrollRunService::class)->open($company, 2026, 5);

        Livewire::actingAs($this->mpinAdmin())
            ->test(PayrollRunShow::class, ['company' => $company, 'run' => $run])
            ->assertDontSee('Извези МПИН');
    }

    public function test_a_warning_is_shown_without_blocking_the_button(): void
    {
        $run = $this->mpinRun([], ['insurance_type_code' => '0047']);

        Livewire::actingAs($this->mpinAdmin())
            ->test(PayrollRunShow::class, ['company' => $run->company, 'run' => $run])
            ->assertSee('неполно работно време')
            ->assertSee('Извези МПИН');
    }

    private function mpinAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }
```

Провери ги вистинските аргументи на `mount()` во `app/Livewire/Payroll/PayrollRunShow.php`; ако постоечките тестови во фајлот веќе имаат помошник за админ корисник, употреби го него и изостави го `mpinAdmin()`.

**Внимание со `assertSee`:** Livewire го бега HTML-от по стандард. Ако текстот во приказот содржи наводници или тире како „—", употреби `assertSee($text, false)` за да се спореди без бегство, или провери со покус дел од текстот.

- [ ] **Step 2: Пушти ги и потврди дека паѓаат**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Payroll/PayrollRunShowTest.php`
Expected: FAIL — текстот не постои.

- [ ] **Step 3: Изложи ги проверките во компонентата**

`app/Livewire/Payroll/PayrollRunShow.php` — во `render()`, или како пресметано својство:

```php
$mpin = $this->run->isDraft()
    ? null
    : \App\Support\Payroll\Mpin\MpinValidator::check($this->run);
```

и предај го `$mpin` во приказот.

- [ ] **Step 4: Додај го во Blade**

`resources/views/livewire/payroll/payroll-run-show.blade.php`, во истиот ред со постоечките копчиња за PDF:

```blade
@if ($mpin)
    @if ($mpin->errors)
        <div class="mt-3 rounded border border-red-200 bg-red-50 p-3">
            <p class="text-sm font-medium text-red-800">МПИН извозот не е можен:</p>
            <ul class="mt-1 list-disc pl-5 text-sm text-red-700">
                @foreach ($mpin->errors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @else
        <a href="{{ route('payroll.mpin-export', [$company, $run]) }}"
           class="rounded bg-orange-600 px-3 py-1 text-sm font-medium text-white hover:bg-orange-700">
            Извези МПИН
        </a>
    @endif

    @if ($mpin->warnings)
        <div class="mt-3 rounded border border-amber-200 bg-amber-50 p-3">
            <ul class="list-disc pl-5 text-sm text-amber-800">
                @foreach ($mpin->warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($run->mpin_exported_at)
        <p class="mt-2 text-sm text-gray-500">
            Последно извезено: {{ $run->mpin_exported_at->format('d.m.Y H:i') }}
        </p>
    @endif
@endif
```

Задржи ја густината од 28px што важи за екраните со табели — ова е блок над табелата, не ред во неа, па `py-1` не се применува тука.

- [ ] **Step 5: Изгради ги стиловите**

Run: `npm run build`

Задолжително: Tailwind не ги создава новите класи додека Blade не е прегледан по измената.

- [ ] **Step 6: Пушти го целиот пакет**

Run: `php -d memory_limit=1G vendor/bin/phpunit`
Expected: PASS — сѐ. Пред фазата беше 1063; сега мора да е повеќе, и ниту еден пад.

- [ ] **Step 7: Дополни ја спецификацијата ако нешто отстапило**

Ако при изведбата се појавила разлика од спецификацијата, запиши ја во `docs/superpowers/specs/2026-08-19-payroll-5c-mpin-export-design.md` со причината. Спецификацијата е она што следната фаза ќе го чита.

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Payroll/PayrollRunShow.php resources/views/livewire/payroll/payroll-run-show.blade.php tests/Feature/Payroll/PayrollRunShowTest.php public/build docs/superpowers/specs/2026-08-19-payroll-5c-mpin-export-design.md
git commit -m "feat(payroll): offer the МПИН export from the run screen"
```

---

## По планот

Пред спојување во `main`:

1. Цел пакет зелен.
2. Преглед на целата гранка.
3. Првиот вистински извоз го прави корисникот: отвора пресметка за вистинска фирма, ја потврдува, симнува XML и **ја вчитува во МПИН клиентот**. Клиентот ја врти валидацијата на УЈП — тоа е потврдата што ниту еден наш тест не може да ја даде.
