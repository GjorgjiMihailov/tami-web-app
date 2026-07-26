# Macedonian Localization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace all English user-facing text in tami-web-app with hardcoded Macedonian (Cyrillic), and switch date/money display to Macedonian formatting conventions, per the approved design at `docs/superpowers/specs/2026-07-26-macedonian-localization-design.md`.

**Architecture:** A new `App\Support\Format` static helper class (mirrors the existing `App\Support\Bcmath`) centralizes date formatting (`d.m.Y`), money formatting (comma-decimal/dot-thousands + currency suffix), and the handful of English-enum-to-Macedonian-label mappings discovered during planning (invoice/payment status, stock movement type, VAT treatment, payment method, document category) that a plain find/replace can't handle because they're driven by `ucfirst()`/`str_replace()` on English database values. Everything else is a direct text swap: English strings hardcoded in Blade views become Macedonian strings hardcoded in the same spot — no `__()`/lang-file indirection, except Laravel's own validation messages (`lang/mk/validation.php`) and the six Breeze auth pages, which already use `__()` and get that wrapper *removed* in favor of hardcoded text, to stay consistent with the rest of the app.

**Tech Stack:** Laravel 13 + Livewire 3 (Volt for auth pages) + Blade + PHPUnit, SQLite for tests.

## Global Constraints

- No `__()`/translation-key indirection anywhere except `lang/mk/validation.php` (framework-generated messages) — every other string is hardcoded Macedonian text directly in its view/PHP file, per the approved design.
- `App\Support\Format::money()` defaults to a `'ден'` currency suffix, but dense report tables (ledger card, trial balance, stock reports, ДДВ-04) call it with `currency: ''` to get just the punctuated number — repeating "ден" on every cell of a 30-row report table is visual noise standard Macedonian accounting reports don't have; the invoicing screens and PDF (client-facing documents) keep the `'ден'` suffix. This is a small implementation-level refinement of the approved design's `Format::money($amount, $currency = 'ден')` signature — flagging it here since it wasn't asked about explicitly during brainstorming.
- Enum/status values stored in the database or in fixed PHP constants (invoice `status`, `payment_method`, stock movement `type`, `vat_treatment`, `Document::CATEGORIES`) are **never translated at the source** — only their *display* label is translated, via a `Format::` mapper method. Changing the stored values would require a data migration and would break the fixed-value comparisons the codebase already relies on (`$invoice->status === 'confirmed'`, etc.) for no benefit.
- Every task that changes visible text must also fix any existing test that asserts on the old English string (see each task's "Tests to update" list — these were found by exact grep/agent search of `tests/`, not guessed).
- Run `php artisan test` after each task; all tests must pass (with updated assertions) before committing.
- Commit after each task, following existing repo convention (see recent `git log` messages for style — imperative, one paragraph, no marketing language).

---

### Task 1: `App\Support\Format` helper

**Files:**
- Create: `app/Support/Format.php`
- Test: `tests/Unit/FormatTest.php`

**Interfaces:**
- Produces (used by every later task): `App\Support\Format::date(mixed $value): string`, `Format::money(string|float|int $amount, string $currency = 'ден', int $decimals = 2): string`, `Format::invoiceStatus(string $status): string`, `Format::paymentStatus(string $status): string`, `Format::movementType(string $type): string`, `Format::vatTreatment(string $treatment): string`, `Format::paymentMethod(string $method): string`, `Format::documentCategory(string $category): string`.

- [ ] **Step 1: Write the failing test file**

```php
<?php

namespace Tests\Unit;

use App\Support\Format;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    public function test_date_formats_a_carbon_instance(): void
    {
        $this->assertSame('26.07.2026', Format::date(Carbon::create(2026, 7, 26)));
    }

    public function test_date_formats_a_date_string(): void
    {
        $this->assertSame('01.01.2026', Format::date('2026-01-01'));
    }

    public function test_money_formats_with_default_currency_and_decimals(): void
    {
        $this->assertSame('1.234,56 ден', Format::money('1234.56'));
    }

    public function test_money_formats_with_empty_currency(): void
    {
        $this->assertSame('1.234,56', Format::money('1234.56', currency: ''));
    }

    public function test_money_formats_with_custom_decimals(): void
    {
        $this->assertSame('12,3400', Format::money('12.34', currency: '', decimals: 4));
    }

    public function test_money_formats_negative_amounts(): void
    {
        $this->assertSame('-500,00 ден', Format::money('-500'));
    }

    public function test_money_formats_zero(): void
    {
        $this->assertSame('0,00 ден', Format::money(0));
    }

    public function test_invoice_status_maps_known_values(): void
    {
        $this->assertSame('Нацрт', Format::invoiceStatus('draft'));
        $this->assertSame('Потврдена', Format::invoiceStatus('confirmed'));
        $this->assertSame('Откажана', Format::invoiceStatus('cancelled'));
    }

    public function test_payment_status_maps_known_values(): void
    {
        $this->assertSame('Платена', Format::paymentStatus('paid'));
        $this->assertSame('Неплатена', Format::paymentStatus('unpaid'));
        $this->assertSame('Делумно платена', Format::paymentStatus('partially_paid'));
    }

    public function test_movement_type_maps_known_values(): void
    {
        $this->assertSame('Прием', Format::movementType('receipt'));
        $this->assertSame('Издавање', Format::movementType('issue'));
        $this->assertSame('Трансфер', Format::movementType('transfer'));
        $this->assertSame('Корекција', Format::movementType('adjustment'));
    }

    public function test_vat_treatment_maps_known_values(): void
    {
        $this->assertSame('Стандардна', Format::vatTreatment('standard'));
        $this->assertSame('Извоз', Format::vatTreatment('export'));
        $this->assertSame('ослободено со право на одбивка', Format::vatTreatment('exempt_with_credit'));
        $this->assertSame('ослободено без право на одбивка', Format::vatTreatment('exempt_without_credit'));
    }

    public function test_payment_method_maps_known_values(): void
    {
        $this->assertSame('Банка', Format::paymentMethod('bank'));
        $this->assertSame('Готовина', Format::paymentMethod('cash'));
    }

    public function test_document_category_maps_known_values(): void
    {
        $this->assertSame('Фактура', Format::documentCategory('Invoice'));
        $this->assertSame('Договор', Format::documentCategory('Contract'));
        $this->assertSame('Извод од банка', Format::documentCategory('Bank Statement'));
        $this->assertSame('Сметка', Format::documentCategory('Receipt'));
        $this->assertSame('Документ за регистрација', Format::documentCategory('ID/Registration'));
        $this->assertSame('Друго', Format::documentCategory('Other'));
    }

    public function test_unmapped_enum_values_fall_back_to_ucfirst_or_original(): void
    {
        $this->assertSame('Somethingnew', Format::invoiceStatus('somethingnew'));
        $this->assertSame('Unknown', Format::documentCategory('Unknown'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=FormatTest`
Expected: FAIL — `Class "App\Support\Format" not found`

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class Format
{
    public static function date(mixed $value): string
    {
        return Carbon::parse($value)->format('d.m.Y');
    }

    public static function money(string|float|int $amount, string $currency = 'ден', int $decimals = 2): string
    {
        $number = number_format((float) $amount, $decimals, ',', '.');

        return $currency === '' ? $number : "{$number} {$currency}";
    }

    public static function invoiceStatus(string $status): string
    {
        return match ($status) {
            'draft' => 'Нацрт',
            'confirmed' => 'Потврдена',
            'cancelled' => 'Откажана',
            default => ucfirst($status),
        };
    }

    public static function paymentStatus(string $status): string
    {
        return match ($status) {
            'paid' => 'Платена',
            'unpaid' => 'Неплатена',
            'partially_paid' => 'Делумно платена',
            default => ucfirst($status),
        };
    }

    public static function movementType(string $type): string
    {
        return match ($type) {
            'receipt' => 'Прием',
            'issue' => 'Издавање',
            'transfer' => 'Трансфер',
            'adjustment' => 'Корекција',
            default => ucfirst($type),
        };
    }

    public static function vatTreatment(string $treatment): string
    {
        return match ($treatment) {
            'standard' => 'Стандардна',
            'export' => 'Извоз',
            'exempt_with_credit' => 'ослободено со право на одбивка',
            'exempt_without_credit' => 'ослободено без право на одбивка',
            default => str_replace('_', ' ', $treatment),
        };
    }

    public static function paymentMethod(string $method): string
    {
        return match ($method) {
            'bank' => 'Банка',
            'cash' => 'Готовина',
            default => ucfirst($method),
        };
    }

    public static function documentCategory(string $category): string
    {
        return match ($category) {
            'Invoice' => 'Фактура',
            'Contract' => 'Договор',
            'Bank Statement' => 'Извод од банка',
            'Receipt' => 'Сметка',
            'ID/Registration' => 'Документ за регистрација',
            'Other' => 'Друго',
            default => $category,
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=FormatTest`
Expected: PASS, 15 tests

- [ ] **Step 5: Commit**

```bash
git add app/Support/Format.php tests/Unit/FormatTest.php
git commit -m "Add Format helper for Macedonian date/money display and enum labels"
```

---

### Task 2: Locale config, validation messages, error pages

**Files:**
- Modify: `.env.example`
- Create: `lang/mk/validation.php`
- Create: `resources/views/errors/403.blade.php`
- Create: `resources/views/errors/404.blade.php`
- Create: `resources/views/errors/419.blade.php`
- Create: `resources/views/errors/500.blade.php`
- Test: `tests/Feature/ErrorPagesTest.php`

**Interfaces:**
- Consumes: none.
- Produces: `APP_LOCALE=mk` (drives `<html lang>` automatically via existing `app()->getLocale()` calls in `layouts/app.blade.php:2` and `layouts/guest.blade.php:2` — no template edit needed there), `APP_NAME=Тами` (drives the `<title>` tag the same way).

- [ ] **Step 1: Update `.env.example`**

Change line 1 from:
```
APP_NAME=Laravel
```
to:
```
APP_NAME=Тами
```

Add after the `APP_LOCALE` area (find the existing locale-related lines and set):
```
APP_LOCALE=mk
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=mk_MK
```

- [ ] **Step 2: Update local `.env`** (not tracked in git — do this manually, same three keys as above)

- [ ] **Step 3: Create `lang/mk/validation.php`**

```php
<?php

return [
    'accepted' => ':attribute мора да биде прифатено.',
    'accepted_if' => ':attribute мора да биде прифатено кога :other е :value.',
    'active_url' => ':attribute не е валидна интернет адреса.',
    'after' => ':attribute мора да биде датум по :date.',
    'after_or_equal' => ':attribute мора да биде датум по или еднаков на :date.',
    'alpha' => ':attribute смее да содржи само букви.',
    'alpha_dash' => ':attribute смее да содржи само букви, бројки, црти и долни црти.',
    'alpha_num' => ':attribute смее да содржи само букви и бројки.',
    'array' => ':attribute мора да биде низа.',
    'before' => ':attribute мора да биде датум пред :date.',
    'before_or_equal' => ':attribute мора да биде датум пред или еднаков на :date.',
    'between' => [
        'array' => ':attribute мора да има помеѓу :min и :max ставки.',
        'file' => ':attribute мора да биде помеѓу :min и :max килобајти.',
        'numeric' => ':attribute мора да биде помеѓу :min и :max.',
        'string' => ':attribute мора да има помеѓу :min и :max карактери.',
    ],
    'boolean' => ':attribute полето мора да биде точно или неточно.',
    'confirmed' => 'Потврдата на :attribute не се совпаѓа.',
    'current_password' => 'Лозинката не е точна.',
    'date' => ':attribute не е валиден датум.',
    'date_equals' => ':attribute мора да биде датум еднаков на :date.',
    'date_format' => ':attribute не одговара на форматот :format.',
    'different' => ':attribute и :other мора да бидат различни.',
    'digits' => ':attribute мора да содржи :digits цифри.',
    'digits_between' => ':attribute мора да содржи помеѓу :min и :max цифри.',
    'email' => ':attribute мора да биде валидна е-пошта.',
    'ends_with' => ':attribute мора да завршува со едно од следново: :values.',
    'exists' => 'Избраниот :attribute не е валиден.',
    'file' => ':attribute мора да биде датотека.',
    'filled' => ':attribute полето мора да има вредност.',
    'gt' => [
        'array' => ':attribute мора да има повеќе од :value ставки.',
        'file' => ':attribute мора да биде поголемо од :value килобајти.',
        'numeric' => ':attribute мора да биде поголемо од :value.',
        'string' => ':attribute мора да има повеќе од :value карактери.',
    ],
    'gte' => [
        'array' => ':attribute мора да има :value ставки или повеќе.',
        'file' => ':attribute мора да биде поголемо или еднакво на :value килобајти.',
        'numeric' => ':attribute мора да биде поголемо или еднакво на :value.',
        'string' => ':attribute мора да има :value карактери или повеќе.',
    ],
    'image' => ':attribute мора да биде слика.',
    'in' => 'Избраниот :attribute не е валиден.',
    'in_array' => ':attribute полето не постои во :other.',
    'integer' => ':attribute мора да биде цел број.',
    'ip' => ':attribute мора да биде валидна IP адреса.',
    'lt' => [
        'array' => ':attribute мора да има помалку од :value ставки.',
        'file' => ':attribute мора да биде помало од :value килобајти.',
        'numeric' => ':attribute мора да биде помало од :value.',
        'string' => ':attribute мора да има помалку од :value карактери.',
    ],
    'lte' => [
        'array' => ':attribute не смее да има повеќе од :value ставки.',
        'file' => ':attribute мора да биде помало или еднакво на :value килобајти.',
        'numeric' => ':attribute мора да биде помало или еднакво на :value.',
        'string' => ':attribute мора да има :value карактери или помалку.',
    ],
    'max' => [
        'array' => ':attribute не смее да има повеќе од :max ставки.',
        'file' => ':attribute не смее да биде поголемо од :max килобајти.',
        'numeric' => ':attribute не смее да биде поголемо од :max.',
        'string' => ':attribute не смее да има повеќе од :max карактери.',
    ],
    'mimes' => ':attribute мора да биде датотека од тип: :values.',
    'min' => [
        'array' => ':attribute мора да има барем :min ставки.',
        'file' => ':attribute мора да биде барем :min килобајти.',
        'numeric' => ':attribute мора да биде барем :min.',
        'string' => ':attribute мора да има барем :min карактери.',
    ],
    'not_in' => 'Избраниот :attribute не е валиден.',
    'numeric' => ':attribute мора да биде број.',
    'present' => ':attribute полето мора да биде присутно.',
    'regex' => ':attribute форматот не е валиден.',
    'required' => ':attribute полето е задолжително.',
    'required_if' => ':attribute полето е задолжително кога :other е :value.',
    'required_unless' => ':attribute полето е задолжително освен ако :other е во :values.',
    'required_with' => ':attribute полето е задолжително кога присутно е :values.',
    'required_with_all' => ':attribute полето е задолжително кога присутни се :values.',
    'required_without' => ':attribute полето е задолжително кога отсутно е :values.',
    'required_without_all' => ':attribute полето е задолжително кога ниту едно од :values не е присутно.',
    'same' => ':attribute и :other мора да се совпаѓаат.',
    'size' => [
        'array' => ':attribute мора да содржи :size ставки.',
        'file' => ':attribute мора да биде :size килобајти.',
        'numeric' => ':attribute мора да биде :size.',
        'string' => ':attribute мора да има :size карактери.',
    ],
    'starts_with' => ':attribute мора да започнува со едно од следново: :values.',
    'string' => ':attribute мора да биде текст.',
    'unique' => ':attribute веќе постои.',
    'uploaded' => ':attribute не успеа да се прикачи.',
    'url' => ':attribute форматот не е валиден.',

    'custom' => [
        //
    ],

    'attributes' => [
        'name' => 'назив',
        'email' => 'е-пошта',
        'password' => 'лозинка',
        'tax_id' => 'ЕДБ',
        'phone' => 'телефон',
        'address' => 'адреса',
        'code' => 'шифра',
        'quantity' => 'количина',
        'unit_price' => 'единечна цена',
        'unit_cost' => 'единечна цена',
        'vat_rate' => 'стапка на ДДВ',
        'description' => 'опис',
        'amount' => 'износ',
        'date' => 'датум',
        'movementDate' => 'датум',
        'paymentDate' => 'датум',
        'paymentAmount' => 'износ',
        'paymentMethod' => 'начин на плаќање',
        'itemId' => 'артикл',
        'warehouseId' => 'магацин',
        'toWarehouseId' => 'магацин',
        'accountId' => 'сметка',
        'partnerId' => 'партнер',
        'newName' => 'назив',
        'newCode' => 'шифра',
        'reason' => 'причина',
    ],
];
```

- [ ] **Step 4: Create the four error pages**

`resources/views/errors/403.blade.php`:
```blade
@php $title = 'Забранет пристап'; $message = 'Немате дозвола за пристап до оваа страница.'; @endphp
@include('errors._layout')
```

`resources/views/errors/404.blade.php`:
```blade
@php $title = 'Страницата не постои'; $message = 'Бараната страница не е пронајдена.'; @endphp
@include('errors._layout')
```

`resources/views/errors/419.blade.php`:
```blade
@php $title = 'Сесијата истече'; $message = 'Страницата истече. Ве молиме обидете се повторно.'; @endphp
@include('errors._layout')
```

`resources/views/errors/500.blade.php`:
```blade
@php $title = 'Грешка на серверот'; $message = 'Настана грешка на серверот. Ве молиме обидете се подоцна.'; @endphp
@include('errors._layout')
```

Create `resources/views/errors/_layout.blade.php` (shared markup for all four, avoids repeating the same page shell four times):
```blade
<!DOCTYPE html>
<html lang="mk">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — Тами</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center">
    <div class="text-center px-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-2">{{ $title }}</h1>
        <p class="text-gray-500 mb-6">{{ $message }}</p>
        <a href="{{ url('/') }}" class="inline-block bg-brand text-white px-4 py-2 rounded-md text-sm">Кон почетна страница</a>
    </div>
</body>
</html>
```

- [ ] **Step 5: Write a test confirming the pages render in Macedonian**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    public function test_404_page_renders_in_macedonian(): void
    {
        $this->get('/this-route-does-not-exist')
            ->assertStatus(404)
            ->assertSee('Бараната страница не е пронајдена.');
    }

    public function test_403_page_renders_in_macedonian(): void
    {
        $response = $this->withoutExceptionHandling(false)->get('/');
        // 403 is exercised indirectly by policy tests elsewhere; here we
        // confirm the view itself renders correctly when invoked directly.
        $view = view('errors.403');

        $this->assertStringContainsString('Немате дозвола за пристап до оваа страница.', $view->render());
    }
}
```

- [ ] **Step 6: Run tests**

Run: `php artisan test --filter=ErrorPagesTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add .env.example lang/mk/validation.php resources/views/errors tests/Feature/ErrorPagesTest.php
git commit -m "Set Macedonian locale, add validation translations and custom error pages"
```

**Note for deployment:** production `.env` on the droplet also needs `APP_NAME=Тами`, `APP_LOCALE=mk`, `APP_FALLBACK_LOCALE=en`, `APP_FAKER_LOCALE=mk_MK` added manually via SSH after this deploys — `.env` is not part of the deployed git repo (same pattern as every other production-only config value in this project).

---

### Task 3: Auth pages + navigation dropdown

**Files:**
- Modify: `resources/views/livewire/pages/auth/login.blade.php`
- Modify: `resources/views/livewire/pages/auth/register.blade.php`
- Modify: `resources/views/livewire/pages/auth/forgot-password.blade.php`
- Modify: `resources/views/livewire/pages/auth/reset-password.blade.php`
- Modify: `resources/views/livewire/pages/auth/confirm-password.blade.php`
- Modify: `resources/views/livewire/pages/auth/verify-email.blade.php`
- Modify: `resources/views/livewire/layout/navigation.blade.php`

**Interfaces:** none (leaf views).

These six auth pages already wrap every label in `__('...')`. Per the Global Constraints, replace each `__('English text')` call with the literal Macedonian string directly (dropping the `__()` wrapper), matching how the rest of the app is done.

- [ ] **Step 1: Edit `login.blade.php`**

| Line | Old | New |
|---|---|---|
| 34 | `:value="__('Email')"` | `value="Е-пошта"` |
| 41 | `:value="__('Password')"` | `value="Лозинка"` |
| 55 | `Remember me` | `Запомни ме` |
| 62 | `Forgot your password?` | `Ја заборавивте лозинката?` |
| 67 | `Log in` | `Најави се` |

- [ ] **Step 2: Edit `register.blade.php`**

| Line | Old | New |
|---|---|---|
| 43 | `:value="__('Name')"` | `value="Име"` |
| 50 | `:value="__('Email')"` | `value="Е-пошта"` |
| 57 | `:value="__('Password')"` | `value="Лозинка"` |
| 69 | `:value="__('Confirm Password')"` | `value="Потврди лозинка"` |
| 80 | `Already registered?` | `Веќе имате профил?` |
| 84 | `Register` | `Регистрирај се` |

- [ ] **Step 3: Edit `forgot-password.blade.php`**

| Line | Old | New |
|---|---|---|
| 41 | `Forgot your password? No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.` | `Ја заборавивте лозинката? Нема проблем. Внесете ја вашата е-пошта и ќе ви испратиме линк за ресетирање на лозинката.` |
| 50 | `:value="__('Email')"` | `value="Е-пошта"` |
| 57 | `Email Password Reset Link` | `Испрати линк за ресетирање` |

- [ ] **Step 4: Edit `reset-password.blade.php`**

| Line | Old | New |
|---|---|---|
| 76 | `:value="__('Email')"` | `value="Е-пошта"` |
| 83 | `:value="__('Password')"` | `value="Лозинка"` |
| 90 | `:value="__('Confirm Password')"` | `value="Потврди лозинка"` |
| 101 | `Reset Password` | `Ресетирај лозинка` |

- [ ] **Step 5: Edit `confirm-password.blade.php`**

| Line | Old | New |
|---|---|---|
| 38 | `This is a secure area of the application. Please confirm your password before continuing.` | `Ова е безбеден дел од апликацијата. Ве молиме потврдете ја вашата лозинка пред да продолжите.` |
| 44 | `:value="__('Password')"` | `value="Лозинка"` |
| 58 | `Confirm` | `Потврди` |

(Line 26's `__('auth.password')` is Laravel's own `auth.php` language line, out of scope for this task — see Task 2's note; `auth.php` translations are not currently exercised by any test and can be added later if needed. Skip.)

- [ ] **Step 6: Edit `verify-email.blade.php`**

| Line | Old | New |
|---|---|---|
| 40 | `Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you didn't receive the email, we will gladly send you another.` | `Ви благодариме за регистрацијата! Пред да започнете, потврдете ја вашата е-пошта со кликнување на линкот што штотуку ви го испративме. Доколку не ја добивте пораката, со задоволство ќе ви испратиме нова.` |
| 45 | `A new verification link has been sent to the email address you provided during registration.` | `Нов линк за потврда е испратен на е-поштата наведена при регистрацијата.` |
| 51 | `Resend Verification Email` | `Испрати повторно линк за потврда` |
| 55 | `Log Out` | `Одјави се` |

- [ ] **Step 7: Edit `navigation.blade.php`**

| Line | Old | New |
|---|---|---|
| 39 | `{{ __('Profile') }}` | `Профил` |
| 45 | `{{ __('Log Out') }}` | `Одјави се` |
| 74 | `{{ __('Profile') }}` | `Профил` |
| 80 | `{{ __('Log Out') }}` | `Одјави се` |

- [ ] **Step 8: Run the full auth test suite**

Run: `php artisan test --filter=Auth`
Expected: PASS — per the earlier inventory, none of `AuthenticationTest`, `RegistrationTest`, `EmailVerificationTest`, `PasswordConfirmationTest`, `PasswordResetTest` assert on literal English text (they use `assertSeeVolt(...)`, which checks component identity, not copy), so no test changes are needed here.

- [ ] **Step 9: Commit**

```bash
git add resources/views/livewire/pages/auth resources/views/livewire/layout/navigation.blade.php
git commit -m "Translate auth pages and nav dropdown to Macedonian"
```

---

### Task 4: Accounting module

**Files:**
- Modify: `resources/views/livewire/accounting/account-index.blade.php`
- Modify: `resources/views/livewire/accounting/journal-entry-index.blade.php`
- Modify: `resources/views/livewire/accounting/journal-entry-form.blade.php`
- Modify: `app/Livewire/Accounting/JournalEntryForm.php`
- Modify: `resources/views/livewire/accounting/ledger-card-report.blade.php`
- Modify: `resources/views/livewire/accounting/trial-balance-report.blade.php`
- Modify: `tests/Feature/JournalEntryIndexTest.php`
- Modify: `tests/Feature/JournalEntryFormTest.php`

**Interfaces:**
- Consumes: `App\Support\Format::date()`, `Format::money()` (Task 1).

- [ ] **Step 1: Edit `account-index.blade.php`**

| Line | Old | New |
|---|---|---|
| 2 | `Chart of Accounts — ` | `Контен план — ` |
| 6 | `Add analytical account` | `Додади аналитичка сметка` |
| 9 | `Parent synthetic code (3 digits)` | `Синтетичка сметка (3 цифри)` |
| 14 | `New code (4+ digits)` | `Нова шифра (4+ цифри)` |
| 19 | `Name` | `Назив` |
| 23 | `Add` | `Додади` |
| 34 | `Code` | `Шифра` |
| 35 | `Name` | `Назив` |
| 36 | `Active` | `Активна` |
| 45 | `$account->is_active ? 'Yes' : 'No'` | `$account->is_active ? 'Да' : 'Не'` |
| 49 | `$account->is_active ? 'Deactivate' : 'Activate'` | `$account->is_active ? 'Деактивирај' : 'Активирај'` |

- [ ] **Step 2: Edit `journal-entry-index.blade.php`**

| Line | Old | New |
|---|---|---|
| 3 | `Journal Entries — ` | `Налози за книжење — ` |
| 6 | `New Entry` | `Нов налог` |
| 16 | `Date` | `Датум` |
| 17 | `Description` | `Опис` |
| 25 | `{{ $entry->entry_date->format('d.m.Y') }}` | `{{ \App\Support\Format::date($entry->entry_date) }}` |
| 29 | `@can('update', $entry) Edit @else View @endcan` | `@can('update', $entry) Измени @else Прегледај @endcan` |
| 34 | `No journal entries yet.` | `Нема внесени налози.` |

- [ ] **Step 3: Edit `journal-entry-form.blade.php`**

| Line | Old | New |
|---|---|---|
| 10 | `'Edit Journal Entry #'.$journalEntry->entry_number : 'New Journal Entry'` | `'Измени налог #'.$journalEntry->entry_number : 'Нов налог'` |
| 14 | `You have read-only access to this entry.` | `Имате пристап само за преглед на овој налог.` |
| 21 | `Date` | `Датум` |
| 26 | `Description` | `Опис` |
| 36 | `Account` | `Сметка` |
| 37 | `Partner` | `Партнер` |
| 38 | `Description` | `Опис` |
| 39 | `Debit` | `Должи` |
| 40 | `Credit` | `Побарува` |
| 41 | `Currency` | `Валута` |
| 42 | `Foreign amt.` | `Износ во валута` |
| 43 | `Rate` | `Курс` |
| 82 | `NBRM` | `НБРМ` |
| 96 | `+ Add line` | `+ Додади ставка` |
| 99 | `Save` | `Зачувај` |

- [ ] **Step 4: Edit `app/Livewire/Accounting/JournalEntryForm.php`**

| Line | Old | New |
|---|---|---|
| 148 | `'A line cannot have both a debit and a credit amount — use one or the other.'` | `'Ставката не може да има истовремено износ за должи и побарува — внесете само едно.'` |
| 158 | `'The entry does not balance — total debit must equal total credit.'` | `'Налогот не е балансиран — вкупното должи мора да е еднакво на вкупното побарува.'` |

- [ ] **Step 5: Edit `ledger-card-report.blade.php`**

| Line | Old | New |
|---|---|---|
| 6 | `Account` | `Сметка` |
| 15 | `Partner` | `Партнер` |
| 24 | `From` | `Од` |
| 28 | `To` | `До` |
| 38 | `Date` | `Датум` |
| 39 | `Description` | `Опис` |
| 40 | `Partner` | `Партнер` |
| 41 | `Debit` | `Должи` |
| 42 | `Credit` | `Побарува` |
| 43 | `Balance` | `Салдо` |
| 49 | `{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d.m.y') }}` | `{{ \App\Support\Format::date($row['date']) }}` |
| 52 | `{{ number_format($row['debit'], 2) }}` | `{{ \App\Support\Format::money($row['debit'], currency: '') }}` |
| 53 | `{{ number_format($row['credit'], 2) }}` | `{{ \App\Support\Format::money($row['credit'], currency: '') }}` |
| 54 | `{{ number_format($row['balance'], 2) }}` | `{{ \App\Support\Format::money($row['balance'], currency: '') }}` |
| 57 | `No transactions in this range.` | `Нема трансакции во овој период.` |
| 63 | `Select an account and/or a partner to see the ledger card.` | `Изберете сметка и/или партнер за да ја видите аналитичката картица.` |

- [ ] **Step 6: Edit `trial-balance-report.blade.php`**

| Line | Old | New |
|---|---|---|
| 6 | `Group by` | `Групирај по` |
| 8 | `Full account (по конта)` | `По аналитички конта` |
| 9 | `Synthetic account only (по синтетики)` | `По синтетички конта` |
| 10 | `Partner (по фирми)` | `По партнери` |
| 11 | `Account + partner (Кумулатив по аналитички конта и фирми)` | `Кумулатив по аналитички конта и партнери` |
| 15 | `From` | `Од` |
| 19 | `To` | `До` |
| 28 | `Code` | `Шифра` |
| 29 | `Name` | `Назив` |
| 30 | `Opening balance` | `Почетно салдо` |
| 31 | `Movement debit` | `Промет должи` |
| 32 | `Movement credit` | `Промет побарува` |
| 33 | `Closing balance` | `Крајно салдо` |
| 41 | `{{ number_format($row['opening_balance'], 2) }}` | `{{ \App\Support\Format::money($row['opening_balance'], currency: '') }}` |
| 42 | `{{ number_format($row['movement_debit'], 2) }}` | `{{ \App\Support\Format::money($row['movement_debit'], currency: '') }}` |
| 43 | `{{ number_format($row['movement_credit'], 2) }}` | `{{ \App\Support\Format::money($row['movement_credit'], currency: '') }}` |
| 44 | `{{ number_format($row['closing_balance'], 2) }}` | `{{ \App\Support\Format::money($row['closing_balance'], currency: '') }}` |
| 47 | `No activity in this range.` | `Нема промет во овој период.` |

- [ ] **Step 7: Update tests**

In `tests/Feature/JournalEntryIndexTest.php:50`, change `->assertDontSee('New Entry')` to `->assertDontSee('Нов налог')`.

In `tests/Feature/JournalEntryFormTest.php:132`, change `->assertSee('Edit Journal Entry #'.$entry->entry_number)` to `->assertSee('Измени налог #'.$entry->entry_number)`.

- [ ] **Step 8: Run tests**

Run: `php artisan test --filter=Account --filter=JournalEntry --filter=LedgerCard --filter=TrialBalance`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add resources/views/livewire/accounting app/Livewire/Accounting/JournalEntryForm.php tests/Feature/JournalEntryIndexTest.php tests/Feature/JournalEntryFormTest.php
git commit -m "Translate Accounting module to Macedonian"
```

---

### Task 5: Inventory module

**Files:**
- Modify: `resources/views/livewire/inventory/warehouse-index.blade.php`
- Modify: `resources/views/livewire/inventory/item-index.blade.php`
- Modify: `resources/views/livewire/inventory/stock-movement-form.blade.php`
- Modify: `app/Livewire/Inventory/StockMovementForm.php`
- Modify: `app/Services/Inventory/StockMovementService.php`
- Modify: `resources/views/livewire/inventory/stock-on-hand-report.blade.php`
- Modify: `resources/views/livewire/inventory/stock-valuation-report.blade.php`
- Modify: `app/Services/Inventory/StockLevelQuery.php`
- Modify: `resources/views/livewire/inventory/item-movement-card-report.blade.php`
- Modify: `tests/Feature/ItemMovementCardReportTest.php`
- Modify: `tests/Feature/StockValuationReportTest.php`
- Modify: `tests/Feature/StockOnHandReportTest.php`

**Interfaces:**
- Consumes: `Format::date()`, `Format::money()`, `Format::movementType()` (Task 1).

- [ ] **Step 1: Edit `warehouse-index.blade.php`**

| Line | Old | New |
|---|---|---|
| 2 | `Warehouses — ` | `Магацини — ` |
| 8 | `Warehouse name` | `Назив на магацин` |
| 12 | `Add` | `Додади` |
| 21 | `Name` | `Назив` |
| 22 | `Active` | `Активен` |
| 30 | `$account->is_active ? 'Yes' : 'No'` (per-warehouse ternary) | `? 'Да' : 'Не'` |
| 34 | `? 'Deactivate' : 'Activate'` | `? 'Деактивирај' : 'Активирај'` |
| 40 | `No warehouses yet.` | `Нема додадено магацини.` |

- [ ] **Step 2: Edit `item-index.blade.php`**

| Line | Old | New |
|---|---|---|
| 2 | `Items — ` | `Артикли — ` |
| 6 | `Add item` | `Додади артикл` |
| 9 | `Code / barcode` | `Шифра / баркод` |
| 14 | `Name` | `Назив` |
| 19 | `Unit` | `Мерна единица` |
| 23 | `Category` | `Категорија` |
| 27 | `VAT %` | `Стапка на ДДВ` |
| 31 | `Preferred supplier` | `Основен добавувач` |
| 39 | `Add` | `Додади` |
| 45 | `Search by name or code` | `Пребарувај по назив или шифра` |
| 52 | `Code` | `Шифра` |
| 53 | `Name` | `Назив` |
| 54 | `Unit` | `Мерна единица` |
| 55 | `Category` | `Категорија` |
| 56 | `VAT %` | `ДДВ %` |
| 57 | `Active` | `Активен` |
| 69 | `? 'Yes' : 'No'` | `? 'Да' : 'Не'` |
| 73 | `? 'Deactivate' : 'Activate'` | `? 'Деактивирај' : 'Активирај'` |
| 79 | `No items yet.` | `Нема додадено артикли.` |

- [ ] **Step 3: Edit `stock-movement-form.blade.php`**

| Line | Old | New |
|---|---|---|
| 2-3 | `Record {{ ucfirst($type) }} — {{ $company->name }}` | `{{ \App\Support\Format::movementType($type) }} — {{ $company->name }}` |
| 10 | `Scan barcode` | `Скенирај баркод` |
| 13 | `Stop scanning` | `Стопирај скенирање` |
| 19 | `Item` | `Артикл` |
| 31 | `$type === 'transfer' ? 'From warehouse' : 'Warehouse'` | `$type === 'transfer' ? 'Од магацин' : 'Магацин'` |
| 43 | `To warehouse` | `До магацин` |
| 56 | `Direction` | `Насока` |
| 58 | `Increase` | `Зголемување` |
| 59 | `Decrease` | `Намалување` |
| 65 | `Quantity` | `Количина` |
| 72 | `Unit cost` | `Единечна цена` |
| 80 | `Reason` | `Причина` |
| 87 | `Date` | `Датум` |
| 92 | `Save` | `Зачувај` |

- [ ] **Step 4: Edit `app/Livewire/Inventory/StockMovementForm.php`**

Line 59, change:
```php
$this->addError('scannedCode', "No item found with code \"{$code}\".");
```
to:
```php
$this->addError('scannedCode', "Не е пронајден артикл со шифра \"{$code}\".");
```

- [ ] **Step 5: Edit `app/Services/Inventory/StockMovementService.php`**

| Line | Old | New |
|---|---|---|
| 56-58 | `"Cannot issue {$quantity} of item #{$item->id} from warehouse #{$warehouse->id}: only {$level->quantity_on_hand} on hand."` | `"Не може да се издаде {$quantity} од артикл #{$item->id} од магацин #{$warehouse->id}: на залиха има само {$level->quantity_on_hand}."` |
| 80 | `'Cannot transfer stock to the same warehouse.'` | `'Не може да се трансферира стока во истиот магацин.'` |
| 102-104 | `"Cannot transfer {$quantity} of item #{$item->id} from warehouse #{$fromWarehouse->id}: only {$fromLevel->quantity_on_hand} on hand."` | `"Не може да се трансферира {$quantity} од артикл #{$item->id} од магацин #{$fromWarehouse->id}: на залиха има само {$fromLevel->quantity_on_hand}."` |
| 141-143 | `"Cannot adjust item #{$item->id} at warehouse #{$warehouse->id} by {$quantityDelta}: only {$level->quantity_on_hand} on hand."` | `"Не може да се корегира артикл #{$item->id} во магацин #{$warehouse->id} за {$quantityDelta}: на залиха има само {$level->quantity_on_hand}."` |
| 183 | `'Item and warehouse must belong to the same company.'` | `'Артиклот и магацинот мора да припаѓаат на иста фирма.'` |

- [ ] **Step 6: Edit `stock-on-hand-report.blade.php`**

| Line | Old | New |
|---|---|---|
| 2 | `Stock On Hand — ` | `Залиха — ` |
| 6 | `Warehouse` | `Магацин` |
| 8 | `All warehouses (totals)` | `Сите магацини (вкупно)` |
| 21 | `Code` | `Шифра` |
| 22 | `Item` | `Артикл` |
| 23 | `Quantity` | `Количина` |
| 24 | `Avg. Cost` | `Просечна цена` |
| 25 | `Value` | `Вредност` |
| 34 | `{{ number_format($row['average_cost'], 4) }}` | `{{ \App\Support\Format::money($row['average_cost'], currency: '', decimals: 4) }}` |
| 35 | `{{ number_format($row['value'], 2) }}` | `{{ \App\Support\Format::money($row['value'], currency: '') }}` |
| 38 | `No stock in this warehouse.` | `Нема залиха во овој магацин.` |
| 48 | `Code` | `Шифра` |
| 49 | `Item` | `Артикл` |
| 50 | `Total Quantity` | `Вкупна количина` |
| 51 | `Total Value` | `Вкупна вредност` |
| 60 | `{{ number_format($row['total_value'], 2) }}` | `{{ \App\Support\Format::money($row['total_value'], currency: '') }}` |
| 63 | `No stock recorded yet.` | `Нема евидентирана залиха.` |

- [ ] **Step 7: Edit `stock-valuation-report.blade.php`**

| Line | Old | New |
|---|---|---|
| 2 | `Stock Valuation — ` | `Вреднување на залихи — ` |
| 6 | `Break down by` | `Групирај по` |
| 8 | `Total only` | `Само вкупно` |
| 9 | `Warehouse` | `Магацин` |
| 10 | `Category` | `Категорија` |
| 20 | `Total Value` | `Вкупна вредност` |
| 27 | `{{ number_format($row['total_value'], 2) }}` | `{{ \App\Support\Format::money($row['total_value'], currency: '') }}` |
| 30 | `No stock recorded yet.` | `Нема евидентирана залиха.` |

- [ ] **Step 8: Edit `app/Services/Inventory/StockLevelQuery.php`**

| Line | Old | New |
|---|---|---|
| 83 | `'Uncategorized'` | `'Без категорија'` |
| 93 | `'label' => 'Total'` | `'label' => 'Вкупно'` |

- [ ] **Step 9: Edit `item-movement-card-report.blade.php`**

| Line | Old | New |
|---|---|---|
| 2 | `Item Movement Card — ` | `Картица на движење — ` |
| 6 | `Item` | `Артикл` |
| 15 | `Warehouse` | `Магацин` |
| 24 | `From` | `Од` |
| 28 | `To` | `До` |
| 38 | `Date` | `Датум` |
| 39 | `Type` | `Тип` |
| 40 | `Counterpart` | `Спротивна страна` |
| 41 | `Quantity` | `Количина` |
| 42 | `Unit Cost` | `Единечна цена` |
| 43 | `Running Qty` | `Тековна количина` |
| 49 | `{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d.m.y') }}` | `{{ \App\Support\Format::date($row['date']) }}` |
| 50 | `{{ ucfirst($row['type']) }}` | `{{ \App\Support\Format::movementType($row['type']) }}` |
| 53 | `{{ number_format($row['unit_cost'], 4) }}` | `{{ \App\Support\Format::money($row['unit_cost'], currency: '', decimals: 4) }}` |
| 57 | `No movements in this range.` | `Нема движења во овој период.` |
| 63 | `Select an item and a warehouse to see the movement card.` | `Изберете артикл и магацин за да ја видите картицата на движење.` |

- [ ] **Step 10: Update tests**

In `tests/Feature/ItemMovementCardReportTest.php:51`, change `assertSee('Receipt')` to `assertSee('Прием')`.

In `tests/Feature/StockValuationReportTest.php:38`, change `assertSee('Total')` to `assertSee('Вкупно')`.

In `tests/Feature/StockValuationReportTest.php:39`, change `assertSee('500.00')` to `assertSee('500,00')` (same root cause as Task 4's addendum: `Format::money(currency: '')` renders Macedonian comma-decimal, breaking this pre-existing literal-format assertion — found during Task 5 pre-flight, added here rather than left for the implementer to hit as a surprise BLOCKED).

In `tests/Feature/StockOnHandReportTest.php:39`, change `assertSee('500.00')` to `assertSee('500,00')` (same reason).

- [ ] **Step 11: Run tests**

Run: `php artisan test --filter=Warehouse --filter=Item --filter=StockMovement --filter=StockOnHand --filter=StockValuation --filter=ItemMovementCard`
Expected: PASS

- [ ] **Step 12: Commit**

```bash
git add resources/views/livewire/inventory app/Livewire/Inventory/StockMovementForm.php app/Services/Inventory/StockMovementService.php app/Services/Inventory/StockLevelQuery.php tests/Feature/ItemMovementCardReportTest.php tests/Feature/StockValuationReportTest.php
git commit -m "Translate Inventory module to Macedonian"
```

---

### Task 6: Invoicing module

**Files:**
- Modify: `resources/views/livewire/partner-index.blade.php`
- Modify: `resources/views/livewire/partner-show.blade.php`
- Modify: `resources/views/livewire/invoicing/sales-invoice-form.blade.php`
- Modify: `resources/views/livewire/invoicing/sales-invoice-index.blade.php`
- Modify: `resources/views/livewire/invoicing/sales-invoice-show.blade.php`
- Modify: `app/Livewire/Invoicing/SalesInvoiceForm.php`
- Modify: `app/Livewire/Invoicing/SalesInvoiceShow.php`
- Modify: `app/Services/Invoicing/SalesInvoiceService.php`
- Modify: `resources/views/livewire/invoicing/purchase-invoice-form.blade.php`
- Modify: `resources/views/livewire/invoicing/purchase-invoice-index.blade.php`
- Modify: `resources/views/livewire/invoicing/purchase-invoice-show.blade.php`
- Modify: `app/Livewire/Invoicing/PurchaseInvoiceForm.php`
- Modify: `app/Services/Invoicing/PurchaseInvoiceService.php`

**Interfaces:**
- Consumes: `Format::date()`, `Format::money()`, `Format::invoiceStatus()`, `Format::paymentStatus()`, `Format::vatTreatment()`, `Format::paymentMethod()` (Task 1).

- [ ] **Step 1: Edit `partner-index.blade.php`**

| Line | Old | New |
|---|---|---|
| 2 | `Partners — ` | `Партнери — ` |
| 6 | `Add partner` | `Додади партнер` |
| 9 | `Name` | `Назив` |
| 14 | `Tax ID` | `ЕДБ` |
| 18 | `Email` | `Е-пошта` |
| 23 | `Phone` | `Телефон` |
| 27 | `Address` | `Адреса` |
| 30 | `Add` | `Додади` |
| 39 | `Name` | `Назив` |
| 40 | `Tax ID` | `ЕДБ` |
| 41 | `Email` | `Е-пошта` |
| 42 | `Phone` | `Телефон` |
| 53 | `Documents` | `Документи` |
| 56 | `No partners yet.` | `Нема додадено партнери.` |

- [ ] **Step 2: Edit `partner-show.blade.php`**

| Line | Old | New |
|---|---|---|
| 6 | `Tax ID: ` | `ЕДБ: ` |
| 7 | `Email: ` | `Е-пошта: ` |
| 8 | `Phone: ` | `Телефон: ` |
| 9 | `Address: ` | `Адреса: ` |

- [ ] **Step 3: Edit `sales-invoice-form.blade.php`**

| Line | Old | New |
|---|---|---|
| 3 | `'Edit draft invoice' : 'New sales invoice'` | `'Измени нацрт фактура' : 'Нова излезна фактура'` |
| 9 | `Customer` | `Купувач` |
| 11 | `Select a customer` | `Изберете купувач` |
| 19 | `Warehouse (if any line has an item)` | `Магацин (доколку некоја ставка содржи артикл)` |
| 29 | `Invoice date` | `Датум на фактура` |
| 34 | `Due date` | `Датум на доспевање` |
| 41 | `Lines` | `Ставки` |
| 45 | `Item (optional)` | `Артикл (опционално)` |
| 47 | `— free text —` | `— слободен текст —` |
| 54 | `Description` | `Опис` |
| 59 | `Qty` | `Кол.` |
| 63 | `Unit price` | `Ед. цена` |
| 67 | `VAT %` | `ДДВ %` |
| 71 | `VAT treatment` | `Третман на ДДВ` |
| 73 | `Standard` | `Стандарден` |
| 74 | `Export` | `Извоз` |
| 75 | `Exempt (with credit)` | `Ослободено (со право на одбивка)` |
| 76 | `Exempt (without credit)` | `Ослободено (без право на одбивка)` |
| 79 | `Remove` | `Отстрани` |
| 83 | `+ Add line` | `+ Додади ставка` |
| 87 | `Notes` | `Забелешки` |
| 91 | `Save draft` | `Зачувај нацрт` |

- [ ] **Step 4: Edit `sales-invoice-index.blade.php`**

| Line | Old | New |
|---|---|---|
| 3 | `Sales Invoices — ` | `Излезни фактури — ` |
| 4 | `New invoice` | `Нова фактура` |
| 9 | `All statuses` | `Сите статуси` |
| 10 | `Draft` | `Нацрт` |
| 11 | `Confirmed` | `Потврдена` |
| 12 | `Cancelled` | `Откажана` |
| 20 | `Number` | `Број` |
| 21 | `Customer` | `Купувач` |
| 22 | `Date` | `Датум` |
| 23 | `Status` | `Статус` |
| 24 | `Total` | `Вкупно` |
| 33 | `{{ $invoice->invoice_date->toDateString() }}` | `{{ \App\Support\Format::date($invoice->invoice_date) }}` |
| 35 | `{{ $invoice->grandTotal() }}` | `{{ \App\Support\Format::money($invoice->grandTotal()) }}` |
| 37 | `View` | `Прегледај` |
| 41 | `No invoices yet.` | `Нема издадено фактури.` |

- [ ] **Step 5: Edit `sales-invoice-show.blade.php`**

| Line | Old | New |
|---|---|---|
| 3 | `"Invoice {$invoice->fiscal_year}/{$invoice->invoice_number}" : 'Draft invoice'` | `"Фактура бр. {$invoice->fiscal_year}/{$invoice->invoice_number}" : 'Нацрт фактура'` |
| 7 | `{{ ucfirst($invoice->status) }}` | `{{ \App\Support\Format::invoiceStatus($invoice->status) }}` |
| 10 | `{{ $invoice->isOverdue() ? 'Overdue' : ucfirst($invoice->paymentStatus()) }}` | `{{ $invoice->isOverdue() ? 'Задоцнета' : \App\Support\Format::paymentStatus($invoice->paymentStatus()) }}` |
| 22 | `Description` | `Опис` |
| 23 | `Qty` | `Кол.` |
| 24 | `Unit price` | `Ед. цена` |
| 25 | `VAT %` | `ДДВ %` |
| 26 | `Line total` | `Вкупно за ставка` |
| 34 | `{{ $line->unit_price }}` | `{{ \App\Support\Format::money($line->unit_price) }}` |
| 35 | `' ('.ucwords(str_replace('_', ' ', $line->vat_treatment)).')'` | `' ('.\App\Support\Format::vatTreatment($line->vat_treatment).')'` |
| 36 | `{{ $line->lineTotal() }}` | `{{ \App\Support\Format::money($line->lineTotal()) }}` |
| 42 | `Subtotal: {{ $invoice->subtotal() }}` | `Основа: {{ \App\Support\Format::money($invoice->subtotal()) }}` |
| 43 | `VAT: {{ $invoice->vatTotal() }}` | `ДДВ: {{ \App\Support\Format::money($invoice->vatTotal()) }}` |
| 44 | `Total: {{ $invoice->grandTotal() }}` | `Вкупно: {{ \App\Support\Format::money($invoice->grandTotal()) }}` |
| 46 | `Balance due: {{ $invoice->balanceDue() }}` | `За доплата: {{ \App\Support\Format::money($invoice->balanceDue()) }}` |
| 53 | `Edit` | `Измени` |
| 54 | `Confirm` | `Потврди` |
| 57 | `Download PDF` | `Преземи PDF` |
| 59 | `Mark as sent` | `Означи како испратена` |
| 62 | `Cancel invoice` | `Откажи фактура` |
| 69 | `Payments` | `Плаќања` |
| 74 | `{{ $payment->payment_date->toDateString() }}` | `{{ \App\Support\Format::date($payment->payment_date) }}` |
| 75 | `{{ ucfirst($payment->payment_method) }}` | `{{ \App\Support\Format::paymentMethod($payment->payment_method) }}` |
| 76 | `{{ $payment->amount }}` | `{{ \App\Support\Format::money($payment->amount) }}` |
| 85 | `value="Amount"` | `value="Износ"` |
| 90 | `value="Date"` | `value="Датум"` |
| 94 | `value="Method"` | `value="Начин"` |
| 96 | `<option value="bank">Bank</option>` | `<option value="bank">Банка</option>` |
| 97 | `<option value="cash">Cash</option>` | `<option value="cash">Готовина</option>` |
| 100 | `Record payment` | `Внеси плаќање` |

- [ ] **Step 6: Edit `app/Livewire/Invoicing/SalesInvoiceForm.php`**

| Line | Old | New |
|---|---|---|
| 50 | `abort(403, 'Only draft invoices can be edited.');` | `abort(403, 'Можат да се менуваат само нацрт фактури.');` |
| 151 | `'Each line needs an item or a description.'` | `'Секоја ставка мора да содржи артикл или опис.'` |
| 160 | `'A warehouse is required when any line references an item.'` | `'Потребен е магацин кога некоја ставка содржи артикл.'` |

- [ ] **Step 7: Edit `app/Livewire/Invoicing/SalesInvoiceShow.php`**

Line 97:
```php
$this->addError('markSent', 'Only confirmed invoices can be marked as sent.');
```
becomes:
```php
$this->addError('markSent', 'Само потврдени фактури можат да се означат како испратени.');
```

- [ ] **Step 8: Edit `app/Services/Invoicing/SalesInvoiceService.php`**

| Line | Old | New |
|---|---|---|
| 24 | `"Invoice #{$invoice->id} is not a draft and cannot be confirmed."` | `"Фактура #{$invoice->id} не е нацрт и не може да се потврди."` |
| 30 | `'An invoice needs at least one line before it can be confirmed.'` | `'Фактурата мора да содржи барем една ставка пред да се потврди.'` |
| 36 | `'A warehouse is required to confirm an invoice with item lines.'` | `'Потребен е магацин за потврдување фактура со ставки со артикли.'` |
| 135 | `"Invoice #{$invoice->id} is not confirmed and cannot be cancelled."` | `"Фактура #{$invoice->id} не е потврдена и не може да се откаже."` |
| 139 | `'An invoice with recorded payments cannot be cancelled.'` | `'Фактура со евидентирани плаќања не може да се откаже.'` |
| 186 | `"Invoice #{$invoice->id} is not confirmed; payments can only be recorded against confirmed invoices."` | `"Фактура #{$invoice->id} не е потврдена; плаќања можат да се внесуваат само за потврдени фактури."` |
| 192 | `"Payment of {$amount} exceeds the remaining balance of {$invoice->balanceDue()}."` | `"Плаќањето од {$amount} го надминува преостанатото салдо од {$invoice->balanceDue()}."` |

- [ ] **Step 9: Edit `purchase-invoice-form.blade.php`**

| Line | Old | New |
|---|---|---|
| 3 | `'Edit draft purchase invoice' : 'New purchase invoice'` | `'Измени нацрт влезна фактура' : 'Нова влезна фактура'` |
| 9 | `Supplier` | `Добавувач` |
| 11 | `Select a supplier` | `Изберете добавувач` |
| 19 | `Supplier invoice number` | `Број на фактура од добавувач` |
| 24 | `Warehouse (if any line has an item)` | `Магацин (доколку некоја ставка содржи артикл)` |
| 34 | `Bill date` | `Датум на фактура` |
| 39 | `Due date` | `Датум на доспевање` |
| 46 | `Lines` | `Ставки` |
| 50 | `Item (optional)` | `Артикл (опционално)` |
| 52 | `— expense/service —` | `— трошок/услуга —` |
| 60 | `Expense account` | `Сметка за трошок` |
| 62 | `Select account` | `Изберете сметка` |
| 71 | `Description` | `Опис` |
| 75 | `Qty` | `Кол.` |
| 79 | `Unit price` | `Ед. цена` |
| 83 | `VAT %` | `ДДВ %` |
| 88 | `VAT deductible` | `ДДВ за одбивка` |
| 90 | `Remove` | `Отстрани` |
| 94 | `+ Add line` | `+ Додади ставка` |
| 98 | `Notes` | `Забелешки` |
| 102 | `Save draft` | `Зачувај нацрт` |

- [ ] **Step 10: Edit `purchase-invoice-index.blade.php`**

| Line | Old | New |
|---|---|---|
| 3 | `Purchase Invoices — ` | `Влезни фактури — ` |
| 4 | `New purchase invoice` | `Нова влезна фактура` |
| 9 | `All statuses` | `Сите статуси` |
| 10 | `Draft` | `Нацрт` |
| 11 | `Confirmed` | `Потврдена` |
| 12 | `Cancelled` | `Откажана` |
| 20 | `Supplier #` | `Бр. кај добавувач` |
| 21 | `Supplier` | `Добавувач` |
| 22 | `Date` | `Датум` |
| 23 | `Status` | `Статус` |
| 24 | `Total` | `Вкупно` |
| 33 | `{{ $invoice->invoice_date->toDateString() }}` | `{{ \App\Support\Format::date($invoice->invoice_date) }}` |
| 35 | `{{ $invoice->grandTotal() }}` | `{{ \App\Support\Format::money($invoice->grandTotal()) }}` |
| 37 | `View` | `Прегледај` |
| 41 | `No purchase invoices yet.` | `Нема внесено влезни фактури.` |

- [ ] **Step 11: Edit `purchase-invoice-show.blade.php`**

| Line | Old | New |
|---|---|---|
| 3 | `Purchase bill — {{ $invoice->partner->name }} #{{ $invoice->supplier_invoice_number }}` | `Влезна фактура — {{ $invoice->partner->name }} #{{ $invoice->supplier_invoice_number }}` |
| 6 | `{{ ucfirst($invoice->status) }}` | `{{ \App\Support\Format::invoiceStatus($invoice->status) }}` |
| 9 | `{{ $invoice->isOverdue() ? 'Overdue' : ucfirst($invoice->paymentStatus()) }}` | `{{ $invoice->isOverdue() ? 'Задоцнета' : \App\Support\Format::paymentStatus($invoice->paymentStatus()) }}` |
| 21 | `Description` | `Опис` |
| 22 | `Item/Account` | `Артикл/Сметка` |
| 23 | `Qty` | `Кол.` |
| 24 | `Unit price` | `Ед. цена` |
| 25 | `VAT %` | `ДДВ %` |
| 26 | `Line total` | `Вкупно за ставка` |
| 35 | `{{ $line->unit_price }}` | `{{ \App\Support\Format::money($line->unit_price) }}` |
| 36 | `' (non-ded.)'` | `' (не се одбива)'` |
| 37 | `{{ $line->lineTotal() }}` | `{{ \App\Support\Format::money($line->lineTotal()) }}` |
| 43 | `Subtotal: {{ $invoice->subtotal() }}` | `Основа: {{ \App\Support\Format::money($invoice->subtotal()) }}` |
| 44 | `VAT: {{ $invoice->vatTotal() }}` | `ДДВ: {{ \App\Support\Format::money($invoice->vatTotal()) }}` |
| 45 | `Total: {{ $invoice->grandTotal() }}` | `Вкупно: {{ \App\Support\Format::money($invoice->grandTotal()) }}` |
| 47 | `Balance due: {{ $invoice->balanceDue() }}` | `За доплата: {{ \App\Support\Format::money($invoice->balanceDue()) }}` |
| 54 | `Edit` | `Измени` |
| 55 | `Confirm` | `Потврди` |
| 58 | `Cancel invoice` | `Откажи фактура` |
| 64 | `Payments` | `Плаќања` |
| 69 | `{{ $payment->payment_date->toDateString() }}` | `{{ \App\Support\Format::date($payment->payment_date) }}` |
| 70 | `{{ ucfirst($payment->payment_method) }}` | `{{ \App\Support\Format::paymentMethod($payment->payment_method) }}` |
| 71 | `{{ $payment->amount }}` | `{{ \App\Support\Format::money($payment->amount) }}` |
| 80 | `value="Amount"` | `value="Износ"` |
| 85 | `value="Date"` | `value="Датум"` |
| 89 | `value="Method"` | `value="Начин"` |
| 91 | `<option value="bank">Bank</option>` | `<option value="bank">Банка</option>` |
| 92 | `<option value="cash">Cash</option>` | `<option value="cash">Готовина</option>` |
| 95 | `Record payment` | `Внеси плаќање` |

- [ ] **Step 12: Edit `app/Livewire/Invoicing/PurchaseInvoiceForm.php`**

| Line | Old | New |
|---|---|---|
| 52 | `abort(403, 'Only draft purchase invoices can be edited.');` | `abort(403, 'Можат да се менуваат само нацрт влезни фактури.');` |
| 150 | `'Each non-item line needs an expense account.'` | `'Секоја ставка без артикл мора да содржи сметка за трошок.'` |
| 159 | `'A warehouse is required when any line references an item.'` | `'Потребен е магацин кога некоја ставка содржи артикл.'` |

- [ ] **Step 13: Edit `app/Services/Invoicing/PurchaseInvoiceService.php`**

| Line | Old | New |
|---|---|---|
| 24 | `"Purchase invoice #{$invoice->id} is not a draft and cannot be confirmed."` | `"Влезна фактура #{$invoice->id} не е нацрт и не може да се потврди."` |
| 30 | `'A purchase invoice needs at least one line before it can be confirmed.'` | `'Влезната фактура мора да содржи барем една ставка пред да се потврди.'` |
| 36 | `'A warehouse is required to confirm a purchase invoice with item lines.'` | `'Потребен е магацин за потврдување влезна фактура со ставки со артикли.'` |
| 43 | `"Non-deductible VAT is not supported on stock-item lines (item line at position {$position})."` | `"ДДВ без право на одбивка не е поддржано за ставки со артикл од залиха (ставка на позиција {$position})."` |
| 47 | `"A non-item line requires an expense account (line at position {$position})."` | `"Ставка без артикл мора да содржи сметка за трошок (ставка на позиција {$position})."` |
| 141 | `"Purchase invoice #{$invoice->id} is not confirmed and cannot be cancelled."` | `"Влезна фактура #{$invoice->id} не е потврдена и не може да се откаже."` |
| 145 | `'A purchase invoice with recorded payments cannot be cancelled.'` | `'Влезна фактура со евидентирани плаќања не може да се откаже.'` |
| 166 | `"Cannot cancel purchase invoice #{$invoice->id}: stock received against it has already been used elsewhere ({$e->getMessage()})."` | `"Не може да се откаже влезна фактура #{$invoice->id}: примената стока веќе е искористена на друго место ({$e->getMessage()})."` |
| 197 | `"Purchase invoice #{$invoice->id} is not confirmed; payments can only be recorded against confirmed invoices."` | `"Влезна фактура #{$invoice->id} не е потврдена; плаќања можат да се внесуваат само за потврдени фактури."` |
| 203 | `"Payment of {$amount} exceeds the remaining balance of {$invoice->balanceDue()}."` | `"Плаќањето од {$amount} го надминува преостанатото салдо од {$invoice->balanceDue()}."` |

- [ ] **Step 14: Run tests**

Run: `php artisan test --filter=Partner --filter=SalesInvoice --filter=PurchaseInvoice`
Expected: PASS — per the earlier inventory, no test in this module asserts literal English UI copy, so no test-file edits are needed here (only fixture data assertions, which are unaffected).

- [ ] **Step 15: Commit**

```bash
git add resources/views/livewire/partner-index.blade.php resources/views/livewire/partner-show.blade.php resources/views/livewire/invoicing app/Livewire/Invoicing app/Services/Invoicing
git commit -m "Translate Invoicing module to Macedonian"
```

---

### Task 7: Documents module + ДДВ-04 report chrome

**Files:**
- Modify: `resources/views/livewire/document-manager.blade.php`
- Modify: `resources/views/livewire/document-index.blade.php`
- Modify: `app/Livewire/DocumentIndex.php`
- Modify: `resources/views/livewire/reports/ddv04-report.blade.php`
- Modify: `tests/Feature/Ddv04ReportTest.php`

**Interfaces:**
- Consumes: `Format::date()`, `Format::money()`, `Format::documentCategory()` (Task 1).

- [ ] **Step 1: Edit `document-manager.blade.php`**

| Line | Old | New |
|---|---|---|
| 2 | `Documents` | `Документи` |
| 7 | `File` | `Датотека` |
| 12 | `Category` | `Категорија` |
| 20 | `Note` | `Белешка` |
| 23 | `Upload` | `Прикачи` |
| 30 | `File` | `Датотека` |
| 31 | `Category` | `Категорија` |
| 32 | `Note` | `Белешка` |
| 33 | `Uploaded by` | `Прикачено од` |
| 34 | `Date` | `Датум` |
| 49 | `{{ $document->created_at->toDateString() }}` | `{{ \App\Support\Format::date($document->created_at) }}` |
| 52 | `wire:confirm="Delete this document?"` | `wire:confirm="Да се избрише документот?"` |
| 52 | `Delete` | `Избриши` |
| 57 | `No documents attached.` | `Нема прикачени документи.` |

Also find the `<select>` that loops `Document::CATEGORIES` to populate the category dropdown (feeds from `$categories`/`Document::CATEGORIES` — near the "Category" label at line 12) and wrap the option label in `Format::documentCategory()` while keeping the `value` attribute as the raw English constant:
```blade
@foreach (\App\Models\Document::CATEGORIES as $cat)
    <option value="{{ $cat }}">{{ \App\Support\Format::documentCategory($cat) }}</option>
@endforeach
```

- [ ] **Step 2: Edit `document-index.blade.php`**

| Line | Old | New |
|---|---|---|
| 2 | `Documents — ` | `Документи — ` |
| 6 | `Category` | `Категорија` |
| 8 | `All` | `Сите` |
| 15 | `Record type` | `Тип на запис` |
| 17 | `All` | `Сите` |
| 24 | `From` | `Од` |
| 28 | `To` | `До` |
| 37 | `File` | `Датотека` |
| 38 | `Category` | `Категорија` |
| 39 | `Record` | `Запис` |
| 40 | `Uploaded by` | `Прикачено од` |
| 41 | `Date` | `Датум` |
| 66 | `{{ $document->created_at->toDateString() }}` | `{{ \App\Support\Format::date($document->created_at) }}` |
| 69 | `No documents yet.` | `Нема прикачени документи.` |

Same as Task 7 Step 1: wrap the category filter's `<option>` labels in `Format::documentCategory($category)`, keeping the stored value untranslated. For the "Record" column (line 39's data cells, which render `$types[$document->documentable_type]` or similar using the labels from `DocumentIndex.php`), no view change is needed beyond Step 3 below — the label text comes entirely from the PHP array.

- [ ] **Step 3: Edit `app/Livewire/DocumentIndex.php`**

Line 54-59, change:
```php
'types' => [
    'purchase_invoice' => 'Purchase Invoice',
    'sales_invoice' => 'Sales Invoice',
    'journal_entry' => 'Journal Entry',
    'partner' => 'Partner',
],
```
to:
```php
'types' => [
    'purchase_invoice' => 'Влезна фактура',
    'sales_invoice' => 'Излезна фактура',
    'journal_entry' => 'Налог',
    'partner' => 'Партнер',
],
```

(The array keys — used in the `where('documentable_type', $this->typeFilter)` query — are unchanged; only the display-label values change, so filtering behavior is unaffected.)

- [ ] **Step 4: Edit `ddv04-report.blade.php`**

| Line | Old | New |
|---|---|---|
| 6 | `From` | `Од` |
| 10 | `To` | `До` |

Everything else in this file is already Macedonian from Phase 4b — do not touch lines 2, 16, 20-56, 64, 68-84.

Also replace all 15 `number_format($fields['XX'], 2)` calls (lines 21, 25, 29, 33, 37, 41, 45, 49, 53, 57, 69, 73, 77, 81, 85) with `\App\Support\Format::money($fields['XX'], currency: '')` (same field key per line, e.g. line 21's `$fields['01']` becomes `\App\Support\Format::money($fields['01'], currency: '')`).

- [ ] **Step 5: Update test**

In `tests/Feature/Ddv04ReportTest.php:37`, change `->assertSee('1,000.00')` to `->assertSee('1.000,00')`.
In `tests/Feature/Ddv04ReportTest.php:38`, change `->assertSee('180.00')` to `->assertSee('180,00')`.

(Same root cause as Tasks 4-5's addenda: `Format::money(currency: '')` renders Macedonian comma-decimal/dot-thousands, breaking this pre-existing literal-format assertion — found during Task 7 pre-flight and added here rather than left for the implementer to hit as a surprise.)

- [ ] **Step 6: Run tests**

Run: `php artisan test --filter=Document --filter=Ddv04`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add resources/views/livewire/document-manager.blade.php resources/views/livewire/document-index.blade.php app/Livewire/DocumentIndex.php resources/views/livewire/reports/ddv04-report.blade.php tests/Feature/Ddv04ReportTest.php
git commit -m "Translate Documents module and DDV-04 report chrome to Macedonian"
```

---

### Task 8: Settings + shell

**Files:**
- Modify: `resources/views/livewire/company-index.blade.php`
- Modify: `resources/views/livewire/dashboard.blade.php`
- Modify: `resources/views/livewire/company-dashboard.blade.php`
- Modify: `tests/Feature/CompanyIndexTest.php`
- Modify: `tests/Feature/DashboardTest.php`

**Interfaces:** none (leaf views; `<html lang>` and `<title>` already handled by Task 2's `.env` change).

- [ ] **Step 1: Edit `company-index.blade.php`**

| Line | Old | New |
|---|---|---|
| 2 | `Companies` | `Фирми` |
| 6 | `Add company` | `Додади фирма` |
| 9 | `Name` | `Назив` |
| 14 | `Tax ID` | `ЕДБ` |
| 19 | `Email` | `Е-пошта` |
| 24 | `Phone` | `Телефон` |
| 29 | `Address` | `Адреса` |
| 33 | `Add company` | `Додади фирма` |
| 39 | `No companies to show.` | `Нема додадено фирми.` |
| 48 | `Edit settings` | `Измени поставки` |
| 57 | `Bank account (IBAN)` | `Трансакциска сметка (IBAN)` |
| 62 | `VAT registered` | `Во ДДВ систем` |
| 64 | `Save` | `Зачувај` |

- [ ] **Step 2: Edit `dashboard.blade.php`**

| Line | Old | New |
|---|---|---|
| 4 | `Select a company` | `Изберете фирма` |
| 5 | `Choose which company you want to work on.` | `Изберете на која фирма сакате да работите.` |
| 8 | `You don't have access to any companies yet.` | `Немате пристап до ниту една фирма засега.` |
| 9 | `Manage companies` | `Управувај со фирми` |

- [ ] **Step 3: Edit `company-dashboard.blade.php`**

| Line | Old | New |
|---|---|---|
| 2 | `Working on: ` | `Работите на: ` |
| 3 | `Pick a module below to get started.` | `Изберете модул подолу за да започнете.` |
| 9 | `Accounts, journal, ledger, trial balance` | `Контен план, налози, картици, биланс` |
| 15 | `Warehouses, items, stock reports` | `Магацини, артикли, извештаи за залихи` |
| 21 | `Partners, sales and purchase invoices` | `Партнери, излезни и влезни фактури` |
| 27 | `Uploaded and generated documents` | `Прикачени и генерирани документи` |
| 33 | `Statutory reports` | `Законски извештаи` |

(Lines 8, 14, 20, 26, 32 — the card titles Сметководство/Магацин/Фактури/Документи/Извештаи — are already Macedonian; do not touch.)

- [ ] **Step 4: Update tests**

In `tests/Feature/CompanyIndexTest.php:83`, change `assertSee('Companies')` to `assertSee('Фирми')`.

In `tests/Feature/DashboardTest.php:46`, change `assertSee('Select a company')` to `assertSee('Изберете фирма')`.

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter=CompanyIndex --filter=CompanyDashboard --filter=DashboardTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add resources/views/livewire/company-index.blade.php resources/views/livewire/dashboard.blade.php resources/views/livewire/company-dashboard.blade.php tests/Feature/CompanyIndexTest.php tests/Feature/DashboardTest.php
git commit -m "Translate Settings and shell screens to Macedonian"
```

---

### Task 9: PDF invoice

**Files:**
- Modify: `resources/views/pdf/sales-invoice.blade.php`

**Interfaces:**
- Consumes: `Format::date()`, `Format::money()` (Task 1).

- [ ] **Step 1: Fix Cyrillic font rendering**

Line 6, change:
```css
body { font-family: sans-serif; font-size: 12px; color: #1f2937; }
```
to:
```css
body { font-family: 'DejaVu Sans'; font-size: 12px; color: #1f2937; }
```

- [ ] **Step 2: Translate labels and wire up `Format`**

| Line | Old | New |
|---|---|---|
| 17 | `Invoice ` | `Фактура ` |
| 23 | `Tax ID: ` | `ЕДБ: ` |
| 25 | `Bank account: ` | `Трансакциска сметка: ` |
| 29 | `Bill to:` | `Купувач:` |
| 33 | `Tax ID: ` | `ЕДБ: ` |
| 37 | `Invoice date: {{ $invoice->invoice_date->toDateString() }}<br>` | `Датум на фактура: {{ \App\Support\Format::date($invoice->invoice_date) }}<br>` |
| 38 | `Due date: {{ $invoice->due_date->toDateString() }}` | `Датум на доспевање: {{ \App\Support\Format::date($invoice->due_date) }}` |
| 45 | `Description` | `Опис` |
| 46 | `Qty` | `Кол.` |
| 47 | `Unit price` | `Ед. цена` |
| 48 | `VAT %` | `ДДВ %` |
| 49 | `Line total` | `Вкупно` |
| 57 | `<td>{{ $line->unit_price }}</td>` | `<td>{{ \App\Support\Format::money($line->unit_price) }}</td>` |
| 59 | `<td>{{ $line->lineTotal() }}</td>` | `<td>{{ \App\Support\Format::money($line->lineTotal()) }}</td>` |
| 66 | `Subtotal: {{ $invoice->subtotal() }}` | `Основа: {{ \App\Support\Format::money($invoice->subtotal()) }}` |
| 67 | `VAT: {{ $invoice->vatTotal() }}` | `ДДВ: {{ \App\Support\Format::money($invoice->vatTotal()) }}` |
| 68 | `Total: {{ $invoice->grandTotal() }}` | `Вкупно: {{ \App\Support\Format::money($invoice->grandTotal()) }}` |

- [ ] **Step 3: Run the PDF test**

Run: `php artisan test --filter=SalesInvoicePdfTest`
Expected: PASS (no literal-English assertions per the earlier inventory)

- [ ] **Step 4: Manual verification (cannot be automated)**

Generate a real invoice PDF via the browser preview (log in, confirm a sales invoice with Cyrillic partner/item names, click "Преземи PDF") and visually confirm every label and amount renders as Macedonian Cyrillic text — not boxes/tofu characters. This is the one check in the whole plan that automated tests cannot catch (a font misconfiguration still passes a text-content assertion while rendering garbage).

- [ ] **Step 5: Commit**

```bash
git add resources/views/pdf/sales-invoice.blade.php
git commit -m "Translate PDF invoice to Macedonian and fix Cyrillic font rendering"
```

---

### Task 10: Final sweep and whole-app verification

**Files:** none created; read/verify only, with fixes applied wherever found.

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: PASS, all tests (should be in the low 400s given the tests added across Tasks 1-9)

- [ ] **Step 2: Fix three known leftover-English spots found during Tasks 5-7's reviews**

These were flagged as Minor findings during earlier task reviews, out of scope for those specific tasks' briefs, and deferred here:

**(a) `resources/views/livewire/inventory/stock-valuation-report.blade.php`** — the dynamic group-by column header renders `{{ $groupBy ? ucfirst($groupBy) : '' }}`, which shows the raw English value of `$groupBy` (`warehouse`/`category`) since that's what the underlying `groupBy` select option values are. Fix by mapping the display label without changing the stored/compared `$groupBy` value:
```blade
{{ $groupBy === 'warehouse' ? 'Магацин' : ($groupBy === 'category' ? 'Категорија' : '') }}
```

**(b) `resources/views/livewire/invoicing/sales-invoice-index.blade.php` and `purchase-invoice-index.blade.php`** — the list-view status badge still renders `{{ ucfirst($invoice->status) }}` in English (only the show-page badges were translated in Task 6). Change to `{{ \App\Support\Format::invoiceStatus($invoice->status) }}` in both files (the `:status="$invoice->status"` binding driving the badge color stays unchanged).

**(c) `resources/views/livewire/document-manager.blade.php` and `document-index.blade.php`** — the per-row document list table still renders the category cell as raw `{{ $document->category }}` (only the category filter's dropdown options were translated in Task 7). Change to `{{ \App\Support\Format::documentCategory($document->category) }}` in both files.

Run `php artisan test --filter="StockValuation|SalesInvoiceIndex|PurchaseInvoiceIndex|Document"` after these three fixes and confirm all pass.

- [ ] **Step 3: Grep sweep for other leftover English chrome**

Run each of the following and inspect any hits — a hit means a string this plan's inventory missed:

```bash
grep -rn "Save\b\|Cancel\b\|>Edit<\|>Delete<\|>Add<" resources/views/livewire resources/views/pdf --include="*.blade.php"
grep -rn ">Yes<\|>No<\|>View<" resources/views/livewire --include="*.blade.php"
```

Fix any genuine leftover English UI copy found (translate in place, following the same glossary used throughout this plan: Save→Зачувај, Cancel→Откажи, Edit→Измени, Delete→Избриши, Add→Додади, Yes→Да, No→Не, View→Прегледај). Some hits will be false positives (e.g. `wire:model` attribute names containing "date", CSS classes) — skip those.

- [ ] **Step 4: Manual click-through per module**

Using the browser preview (`preview_start` against this project's dev server), log in and click through: Companies → pick a company → each of the 5 sidebar modules (Сметководство/Магацин/Фактури/Документи/Извештаи) and their sub-screens. Confirm no English text remains on any screen reached this way, including empty-state messages (create a fresh company with no data to see all the "No X yet" states at once).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Fix remaining English strings found in final localization sweep"
```

(This commit covers Step 2's three known fixes plus anything found in Steps 3-4. If Steps 3-4 found nothing beyond Step 2's known fixes, the commit message and content still apply — Step 2 alone guarantees this step has something to commit.)

- [ ] **Step 6: Deploy**

Push to `main` per the project's established CI/CD flow (GitHub Actions auto-deploys on push). After deploy, SSH in and add the four `.env` keys noted in Task 2 (`APP_NAME`, `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE`) to production `.env`, since `.env` is not part of the deployed repo.
