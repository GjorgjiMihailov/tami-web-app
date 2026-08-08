# UI/UX Redesign — Plan A: Tokens, Shared Components & Dashboard Showcase Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the "warm modern SaaS" design-token layer, apply it to every shared Blade component, and prove it out on the CompanyDashboard screen — establishing the foundation the rest of the site's redesign (Plan B and beyond) will mechanically reuse.

**Architecture:** Additive Tailwind theme tokens (new `canvas` background color, `shadow-card`/`shadow-card-hover` shadow scale) layered on top of the existing `brand` color tokens — nothing existing is renamed. Each shared Blade component (`card`, `badge`, buttons, inputs, modal, dropdown, sidebar, navigation) is updated to consume the new tokens. `CompanyDashboard` (the real per-company landing screen — NOT the company-picker screen at `resources/views/livewire/dashboard.blade.php`) is fully restyled last, as the showcase the user signs off on before Plan B rolls the same components out to the remaining ~39 screens.

**Tech Stack:** Laravel 13 + Livewire/Volt, Blade components, Tailwind CSS 3 (JIT) via Vite, PHPUnit/Pest (`Tests\Feature`), no JS framework beyond Alpine.js (already used by existing components).

## Global Constraints

- Visual/UX changes only. Never touch controllers, Livewire component PHP logic, calculations, validations, or the content/structure/required fields of any legal document (е-Фактура, ДДВ-04). (Design spec, "Scope boundary")
- No new business logic or computed data. If a visual idea would require new data/aggregation, it is out of scope for this plan. (Design spec, "Scope boundary")
- Keep Manrope as the typeface — no font change. (Design spec, "Typography")
- Keep the existing inline-SVG icon approach — no new icon package dependency. (Design spec, "Icons")
- Body/label text stays neutral gray, not warm-brown, for legibility during hours-long use. (Design spec, "Deliberate deviations")
- Semantic status colors (success/warning/danger/info) must stay visually distinct from the brand orange — never reuse `brand`/`orange-*` for a status meaning. (Design spec, "Deliberate deviations")
- Existing shared-component tests in `tests/Feature/CardAndBadgeComponentTest.php` must keep passing unless a task explicitly and intentionally changes that exact behavior (it doesn't in this plan — only additive changes are made to `card`/`badge`).

## Deviation from the approved spec (found while mapping files for this plan)

The approved design doc named a new `x-stat-card` component (big number + label + trend) and planned to introduce it on the Dashboard showcase screen. Reading the real `CompanyDashboard` view (`resources/views/livewire/company-dashboard.blade.php`) shows it is actually a **module-launcher screen** (a grid of navigation cards: Сметководство, Магацин, Фактури, Документи, Извештаи) plus conditional е-Фактура/signing-device panels and a company-edit form — it has no revenue/invoice-count/KPI numbers today, and computing any would be new business logic (out of scope per the Global Constraints above). `x-stat-card` is dropped from this plan. It stays a good idea for a screen that already computes real totals (e.g. a Reports screen) — defer it to whichever later Plan B module actually needs it, don't build it speculatively now.

The approved design doc also called for formalizing a named typography scale (heading/subheading/body/caption) to replace ad-hoc per-screen `text-lg`/`text-sm` choices. This plan's only screen (CompanyDashboard) has a single heading level and a couple of card titles — not enough real, varied usage to design a multi-level scale against without guessing. Defining the tokens now with just one trivial consumer would repeat the same mistake `x-stat-card` almost made (a token/component built ahead of a real need). Defer the actual `fontSize` token definition to Plan B's first task, once several real screens with mixed heading levels (page titles, section headers, table headers, card titles) are in view to design the scale against.

---

## Task 1: Design tokens, `x-card`, and `x-badge`

Tokens have no independent, testable effect until something actually uses the class name (Tailwind's JIT compiler only emits CSS for classes it finds referenced in a scanned file) — so this task defines the new tokens *and* wires them into the two smallest shared components in one reviewable step.

**Files:**
- Modify: `tailwind.config.js`
- Modify: `resources/views/components/card.blade.php`
- Modify: `resources/views/components/badge.blade.php`
- Test: `tests/Feature/CardAndBadgeComponentTest.php`

**Interfaces:**
- Produces: Tailwind color token `canvas` (`bg-canvas`, `text-canvas`, etc. — DEFAULT `#FFF8F3`), Tailwind shadow tokens `shadow-card` and `shadow-card-hover`. Later tasks (3, 6, 7) consume `bg-canvas` and `shadow-card-hover`.
- Produces: `<x-badge status="info">`, `<x-badge status="sent">`, `<x-badge status="processing">` now resolve to the blue info style (`bg-blue-100 text-blue-800`), alongside the existing `confirmed/paid/active` (green), `draft/pending/unpaid` (amber), `cancelled/overdue` (red), and default (gray) mappings — unchanged.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/CardAndBadgeComponentTest.php` (inside the existing class, after `test_card_padding_prop_overrides_default_padding_without_conflicting_classes`):

```php
    public function test_card_uses_the_warm_card_shadow_token(): void
    {
        $html = Blade::render('<x-card>Hello</x-card>');

        $this->assertStringContainsString('shadow-card', $html);
        $this->assertStringNotContainsString('shadow-sm', $html);
    }
```

And after `test_badge_falls_back_to_gray_for_an_unknown_status`:

```php
    public function test_badge_maps_info_sent_and_processing_to_blue(): void
    {
        $info = Blade::render('<x-badge status="info">Info</x-badge>');
        $sent = Blade::render('<x-badge status="sent">Sent</x-badge>');
        $processing = Blade::render('<x-badge status="processing">Processing</x-badge>');

        $this->assertStringContainsString('bg-blue-100', $info);
        $this->assertStringContainsString('bg-blue-100', $sent);
        $this->assertStringContainsString('bg-blue-100', $processing);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=CardAndBadgeComponentTest`
Expected: FAIL — `test_card_uses_the_warm_card_shadow_token` fails because the card still renders `shadow-sm`; `test_badge_maps_info_sent_and_processing_to_blue` fails because `info`/`sent`/`processing` currently fall through to the gray default.

- [ ] **Step 3: Add the new tokens to `tailwind.config.js`**

Replace the `colors` block and add a `boxShadow` block inside `theme.extend`:

```js
    theme: {
        extend: {
            fontFamily: {
                sans: ['Manrope', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    DEFAULT: '#ff6600',
                    light: '#ff8533',
                    dark: '#cc5200',
                },
                canvas: {
                    DEFAULT: '#FFF8F3',
                },
            },
            boxShadow: {
                card: '0 1px 3px 0 rgba(15, 23, 42, 0.06)',
                'card-hover': '0 10px 24px -6px rgba(255, 102, 0, 0.22)',
            },
        },
    },
```

- [ ] **Step 4: Update `card.blade.php` to use the new shadow token**

```blade
@props(['padding' => 'p-4'])

<div {{ $attributes->merge(['class' => "bg-white rounded-2xl shadow-card {$padding}"]) }}>
    {{ $slot }}
</div>
```

- [ ] **Step 5: Add the `info`/`sent`/`processing` mapping to `badge.blade.php`**

```blade
@props(['status'])

@php
$classes = match ($status) {
    'confirmed', 'paid', 'active' => 'bg-green-100 text-green-800',
    'draft', 'pending', 'unpaid' => 'bg-amber-100 text-amber-800',
    'cancelled', 'overdue' => 'bg-red-100 text-red-800',
    'info', 'sent', 'processing' => 'bg-blue-100 text-blue-800',
    default => 'bg-gray-100 text-gray-700',
};
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold {$classes}"]) }}>
    {{ $slot }}
</span>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=CardAndBadgeComponentTest`
Expected: PASS (all tests in the file, old and new).

- [ ] **Step 7: Verify the Tailwind build actually emits the new classes**

Run: `npm run build`
Then check the compiled CSS contains the new rules — e.g. `grep -c "shadow-card" public/build/assets/*.css` (git-bash) should report at least 1 match. If it reports 0, the class name has a typo or `tailwind.config.js`'s `content` globs don't cover `resources/views/components/*.blade.php` — check `content: [...]` in `tailwind.config.js` before continuing (it currently includes `./resources/views/**/*.blade.php`, which does cover it).

- [ ] **Step 8: Commit**

```bash
git add tailwind.config.js resources/views/components/card.blade.php resources/views/components/badge.blade.php tests/Feature/CardAndBadgeComponentTest.php
git commit -m "feat(ui): add canvas/shadow design tokens, apply to card and badge"
```

---

## Task 2: Buttons (`x-primary-button`, `x-secondary-button`, `x-danger-button`)

The current buttons use the Laravel-Breeze-default "tiny uppercase tracking-widest" label style, which reads as a dated 2018-era starter-kit look — dropping it for a normal-case, slightly larger label is a concrete, low-risk step toward "looks more expensive." Pill shape (`rounded-full`) and brand color already fit the chosen direction and stay unchanged.

**Files:**
- Modify: `resources/views/components/primary-button.blade.php`
- Modify: `resources/views/components/secondary-button.blade.php`
- Modify: `resources/views/components/danger-button.blade.php`
- Test: `tests/Feature/ButtonComponentTest.php` (new file)

**Interfaces:**
- Produces: all three button components keep their existing prop-free `$attributes->merge(...)` contract (no Blade prop changes) — callers across the codebase (`<x-primary-button type="submit">Зачувај</x-primary-button>` etc.) need zero changes.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ButtonComponentTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ButtonComponentTest extends TestCase
{
    public function test_primary_button_uses_normal_case_medium_weight_label(): void
    {
        $html = Blade::render('<x-primary-button>Зачувај</x-primary-button>');

        $this->assertStringContainsString('font-semibold', $html);
        $this->assertStringContainsString('text-sm', $html);
        $this->assertStringNotContainsString('uppercase', $html);
        $this->assertStringNotContainsString('tracking-widest', $html);
    }

    public function test_secondary_button_uses_normal_case_medium_weight_label(): void
    {
        $html = Blade::render('<x-secondary-button>Откажи</x-secondary-button>');

        $this->assertStringNotContainsString('uppercase', $html);
        $this->assertStringNotContainsString('tracking-widest', $html);
    }

    public function test_danger_button_uses_normal_case_medium_weight_label(): void
    {
        $html = Blade::render('<x-danger-button>Избриши</x-danger-button>');

        $this->assertStringNotContainsString('uppercase', $html);
        $this->assertStringNotContainsString('tracking-widest', $html);
    }

    public function test_all_three_buttons_share_the_same_pill_radius(): void
    {
        $primary = Blade::render('<x-primary-button>A</x-primary-button>');
        $secondary = Blade::render('<x-secondary-button>B</x-secondary-button>');
        $danger = Blade::render('<x-danger-button>C</x-danger-button>');

        $this->assertStringContainsString('rounded-full', $primary);
        $this->assertStringContainsString('rounded-full', $secondary);
        $this->assertStringContainsString('rounded-full', $danger);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ButtonComponentTest`
Expected: FAIL — all three buttons currently render `uppercase tracking-widest text-xs`.

- [ ] **Step 3: Update `primary-button.blade.php`**

```blade
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-brand border border-transparent rounded-full font-semibold text-sm text-white hover:bg-brand-dark focus:bg-brand-dark active:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
```

- [ ] **Step 4: Update `secondary-button.blade.php`**

```blade
<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-full font-semibold text-sm text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
```

- [ ] **Step 5: Update `danger-button.blade.php`**

```blade
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-full font-semibold text-sm text-white hover:bg-red-500 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=ButtonComponentTest`
Expected: PASS.

- [ ] **Step 7: Run the full test suite to check for incidental breakage**

Run: `php artisan test`
Expected: PASS. (Some existing feature tests may assert visible button text like "Зачувај" — those still pass since slot content is unchanged; none are known to assert on `uppercase`/`text-xs` specifically, but read any failure carefully before assuming it's unrelated.)

- [ ] **Step 8: Commit**

```bash
git add resources/views/components/primary-button.blade.php resources/views/components/secondary-button.blade.php resources/views/components/danger-button.blade.php tests/Feature/ButtonComponentTest.php
git commit -m "feat(ui): switch buttons from uppercase tracking-widest to normal-case labels"
```

---

## Task 3: Inputs (`x-text-input`, `x-input-label`, `x-input-error`)

**Files:**
- Modify: `resources/views/components/text-input.blade.php`
- Modify: `resources/views/components/input-label.blade.php`
- Test: `tests/Feature/InputComponentTest.php` (new file)

**Interfaces:**
- Produces: no prop changes — `text-input.blade.php` still accepts `disabled` and passes through `$attributes`; `input-label.blade.php` still accepts `value`. `input-error.blade.php` is left as-is (already just `text-sm text-red-600`, already consistent, no change needed).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/InputComponentTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class InputComponentTest extends TestCase
{
    public function test_text_input_has_a_visible_transition_on_its_focus_ring(): void
    {
        $html = Blade::render('<x-text-input />');

        $this->assertStringContainsString('focus:border-brand', $html);
        $this->assertStringContainsString('focus:ring-brand', $html);
        $this->assertStringContainsString('transition', $html);
    }

    public function test_input_label_uses_medium_gray_for_a_softer_look_than_pure_gray_700(): void
    {
        $html = Blade::render('<x-input-label value="Назив" />');

        $this->assertStringContainsString('text-gray-600', $html);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=InputComponentTest`
Expected: FAIL — `text-input.blade.php` has no `transition` class yet; `input-label.blade.php` currently uses `text-gray-700`.

- [ ] **Step 3: Update `text-input.blade.php`**

```blade
@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-300 focus:border-brand focus:ring-brand rounded-lg shadow-sm transition duration-150 ease-in-out']) }}>
```

- [ ] **Step 4: Update `input-label.blade.php`**

```blade
@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-gray-600']) }}>
    {{ $value ?? $slot }}
</label>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=InputComponentTest`
Expected: PASS.

- [ ] **Step 6: Run the full test suite to check for incidental breakage**

Run: `php artisan test`
Expected: PASS. `input-label.blade.php`'s color change (`gray-700` → `gray-600`) is purely cosmetic and touches every form in the app, so scan the output for any failing test that happens to assert on `text-gray-700` for a label specifically (none are known to, but this is the one change in this task most likely to have an unexpected assertion somewhere).

- [ ] **Step 7: Commit**

```bash
git add resources/views/components/text-input.blade.php resources/views/components/input-label.blade.php tests/Feature/InputComponentTest.php
git commit -m "feat(ui): soften input labels and add focus transition to text inputs"
```

---

## Task 4: Modal & Dropdown (`x-modal`, `x-dropdown`, `x-dropdown-link`)

Aligns their corner radius with `x-card`'s `rounded-2xl` (currently `rounded-lg`/`rounded-md`, inconsistent with the card language), and gives dropdown links a warm hover instead of plain gray.

**Files:**
- Modify: `resources/views/components/modal.blade.php`
- Modify: `resources/views/components/dropdown.blade.php`
- Modify: `resources/views/components/dropdown-link.blade.php`
- Test: `tests/Feature/ModalAndDropdownComponentTest.php` (new file)

**Interfaces:**
- No prop changes to any of the three components.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ModalAndDropdownComponentTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ModalAndDropdownComponentTest extends TestCase
{
    public function test_modal_panel_uses_the_same_rounded_2xl_radius_as_cards(): void
    {
        $html = Blade::render('<x-modal name="test">Content</x-modal>');

        $this->assertStringContainsString('rounded-2xl', $html);
        $this->assertStringNotContainsString('rounded-lg', $html);
    }

    public function test_dropdown_panel_uses_rounded_xl(): void
    {
        $html = Blade::render('<x-dropdown><x-slot name="trigger">T</x-slot><x-slot name="content">C</x-slot></x-dropdown>');

        $this->assertStringContainsString('rounded-xl', $html);
    }

    public function test_dropdown_link_has_a_warm_hover_instead_of_plain_gray(): void
    {
        $html = Blade::render('<x-dropdown-link href="#">Профил</x-dropdown-link>');

        $this->assertStringContainsString('hover:bg-orange-50', $html);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ModalAndDropdownComponentTest`
Expected: FAIL — modal currently uses `rounded-lg`, dropdown panel wrapper has no explicit radius class of its own beyond `rounded-md` on the inner ring div, dropdown-link currently uses `hover:bg-gray-100`.

- [ ] **Step 3: Update `modal.blade.php`'s panel div (line ~68)**

Find:
```blade
        class="mb-6 bg-white rounded-lg overflow-hidden shadow-xl transform transition-all sm:w-full {{ $maxWidth }} sm:mx-auto"
```
Replace with:
```blade
        class="mb-6 bg-white rounded-2xl overflow-hidden shadow-xl transform transition-all sm:w-full {{ $maxWidth }} sm:mx-auto"
```

- [ ] **Step 4: Update `dropdown.blade.php`'s panel wrapper and inner ring div**

Find:
```blade
            class="absolute z-50 mt-2 {{ $width }} rounded-md shadow-lg {{ $alignmentClasses }}"
            style="display: none;"
            @click="open = false">
        <div class="rounded-md ring-1 ring-black ring-opacity-5 {{ $contentClasses }}">
```
Replace with:
```blade
            class="absolute z-50 mt-2 {{ $width }} rounded-xl shadow-lg {{ $alignmentClasses }}"
            style="display: none;"
            @click="open = false">
        <div class="rounded-xl ring-1 ring-black ring-opacity-5 {{ $contentClasses }}">
```

- [ ] **Step 5: Update `dropdown-link.blade.php`**

```blade
<a {{ $attributes->merge(['class' => 'block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-orange-50 focus:outline-none focus:bg-orange-50 transition duration-150 ease-in-out']) }}>{{ $slot }}</a>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=ModalAndDropdownComponentTest`
Expected: PASS.

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add resources/views/components/modal.blade.php resources/views/components/dropdown.blade.php resources/views/components/dropdown-link.blade.php tests/Feature/ModalAndDropdownComponentTest.php
git commit -m "feat(ui): align modal/dropdown radius with cards, warm dropdown-link hover"
```

---

## Task 5: Remove dead `x-nav-link`, update `x-responsive-nav-link`

`nav-link.blade.php` renders a tab-style link with an underline-on-active pattern — grepping the whole `resources/views` tree for `x-nav-link` (not `x-responsive-nav-link`) finds zero usages anywhere. It's leftover Laravel Breeze scaffolding that was never wired into this app's actual sidebar-based navigation. Delete it rather than restyle something nothing renders. `responsive-nav-link.blade.php` **is** used (mobile hamburger menu's "Профил"/"Одјави се" links) and gets the warm-hover treatment to match `dropdown-link`.

**Files:**
- Delete: `resources/views/components/nav-link.blade.php`
- Modify: `resources/views/components/responsive-nav-link.blade.php`
- Test: `tests/Feature/ResponsiveNavLinkComponentTest.php` (new file)

**Interfaces:**
- `x-nav-link` no longer exists as a component — nothing else in the codebase references it (verified below), so nothing breaks.

- [ ] **Step 1: Confirm `x-nav-link` is truly unused before deleting anything**

Run: `grep -rn "x-nav-link" resources/views` (git-bash) — this must match ONLY `resources/views/components/nav-link.blade.php` itself (the component definition file, which doesn't count as a usage) and nothing else. If it matches any other file, STOP — do not delete, that file is a real caller and must be updated instead as part of this task.

- [ ] **Step 2: Write the failing test for the responsive nav link's warm hover**

Create `tests/Feature/ResponsiveNavLinkComponentTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ResponsiveNavLinkComponentTest extends TestCase
{
    public function test_inactive_responsive_nav_link_has_a_warm_hover(): void
    {
        $html = Blade::render('<x-responsive-nav-link href="#">Профил</x-responsive-nav-link>');

        $this->assertStringContainsString('hover:bg-orange-50', $html);
    }

    public function test_active_responsive_nav_link_keeps_its_brand_accent(): void
    {
        $html = Blade::render('<x-responsive-nav-link href="#" :active="true">Профил</x-responsive-nav-link>');

        $this->assertStringContainsString('border-brand', $html);
        $this->assertStringContainsString('bg-orange-50', $html);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=ResponsiveNavLinkComponentTest`
Expected: the inactive-link test FAILS (`hover:bg-gray-50` currently, not `hover:bg-orange-50`); the active-link test PASSES already (it already uses `bg-orange-50`) — that's fine, it documents existing behavior we're keeping.

- [ ] **Step 4: Update `responsive-nav-link.blade.php`'s inactive branch**

Find:
```blade
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-gray-600 hover:text-gray-800 hover:bg-gray-50 hover:border-gray-300 focus:outline-none focus:text-gray-800 focus:bg-gray-50 focus:border-gray-300 transition duration-150 ease-in-out';
```
Replace with:
```blade
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-gray-600 hover:text-gray-800 hover:bg-orange-50 hover:border-gray-300 focus:outline-none focus:text-gray-800 focus:bg-orange-50 focus:border-gray-300 transition duration-150 ease-in-out';
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=ResponsiveNavLinkComponentTest`
Expected: PASS (both tests).

- [ ] **Step 6: Delete the dead component**

```bash
git rm resources/views/components/nav-link.blade.php
```

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: PASS — nothing references `x-nav-link`, confirmed in Step 1.

- [ ] **Step 8: Commit**

```bash
git add resources/views/components/responsive-nav-link.blade.php tests/Feature/ResponsiveNavLinkComponentTest.php
git commit -m "feat(ui): warm hover on responsive-nav-link, remove unused nav-link component"
```

---

## Task 6: App shell — canvas background, light sidebar

This is the biggest visual jump in the plan: the sidebar currently renders dark (`bg-gray-800`, white/gray text) — closer to the rejected "Executive/navy" mockup than the chosen "Топол модерен SaaS" one, which used a **white** sidebar with a solid-orange pill for the active item and a light-peach pill on hover for inactive items. This task converts it.

**Files:**
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `resources/views/livewire/layout/sidebar.blade.php`
- Test: `tests/Feature/AppShellTest.php` (new file)

**Interfaces:**
- No PHP/prop changes — `sidebar.blade.php`'s existing public properties (`$company`, `$expandedModule`, `$recordMovementExpanded`) and methods (`toggleModule`, `toggleRecordMovement`) are untouched; only Blade class strings change.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AppShellTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class AppShellTest extends TestCase
{
    public function test_authenticated_page_body_uses_the_canvas_background(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('bg-canvas', false);
        $response->assertDontSee('bg-gray-50', false);
    }

    public function test_sidebar_is_light_not_dark(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('bg-gray-800', false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AppShellTest`
Expected: FAIL — `layouts/app.blade.php` currently uses `bg-gray-50`, `sidebar.blade.php` currently uses `bg-gray-800`.

- [ ] **Step 3: Update `layouts/app.blade.php`'s outer wrapper (line 18)**

Find:
```blade
        <div class="min-h-screen flex bg-gray-50">
```
Replace with:
```blade
        <div class="min-h-screen flex bg-canvas">
```

- [ ] **Step 4: Rewrite `sidebar.blade.php` for the light style**

Replace the entire file content. The active/inactive class pairs change from dark-on-dark to light-on-white; the accordion sub-links and section divider get lighter equivalents. Structure, routes, `wire:click` handlers, and `@if`/`@foreach` logic are unchanged — only class strings:

```blade
<div class="w-60 shrink-0 bg-white border-r border-gray-100 text-gray-700 flex flex-col min-h-screen">
    <div class="px-4 py-4 border-b border-gray-100">
        <a href="{{ route('dashboard') }}" wire:navigate class="font-bold text-brand text-sm">
            {{ config('app.name', 'Laravel') }}
        </a>
    </div>

    <nav class="flex-1 py-3 space-y-1">
        <a href="{{ route('dashboard') }}" wire:navigate
           class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ request()->routeIs('dashboard') ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}">
            Почетна
        </a>
        <a href="{{ route('companies.index') }}" wire:navigate
           class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ request()->routeIs('companies.*') ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}">
            Фирми
        </a>

        @if (auth()->check() && auth()->user()->hasRole('admin'))
            <a href="{{ route('efaktura.access-requests') }}" wire:navigate
               class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ request()->routeIs('efaktura.access-requests') ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}">
                е-Фактура барања
            </a>
        @endif

        @if ($company)
            <div class="pt-4 mt-3 border-t border-gray-100">
                <div class="px-4 pb-2 text-xs uppercase tracking-wide text-gray-400">{{ $company->name }}</div>

                {{-- Accounting --}}
                <button type="button" wire:click="toggleModule('accounting')"
                        class="w-full text-left flex items-center justify-between px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ request()->routeIs('accounting.*') ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}"
                        style="width: calc(100% - 1.5rem);">
                    <span>Сметководство</span>
                    <span>{{ $expandedModule === 'accounting' ? '−' : '+' }}</span>
                </button>
                @if ($expandedModule === 'accounting')
                    <div class="pl-6">
                        <a href="{{ route('accounting.accounts.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('accounting.accounts.*') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Контен план</a>
                        <a href="{{ route('accounting.journal-groups.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('accounting.journal-groups.*') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Журнали</a>
                        <a href="{{ route('accounting.journal-entries.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('accounting.journal-entries.*') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Налози</a>
                        <a href="{{ route('accounting.reports.ledger-card', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('accounting.reports.ledger-card') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Аналитичка картица</a>
                        <a href="{{ route('accounting.reports.trial-balance', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('accounting.reports.trial-balance') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Бруто биланс</a>
                    </div>
                @endif

                {{-- Inventory --}}
                <button type="button" wire:click="toggleModule('inventory')"
                        class="w-full text-left flex items-center justify-between px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ request()->routeIs('inventory.*') ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}"
                        style="width: calc(100% - 1.5rem);">
                    <span>Магацин</span>
                    <span>{{ $expandedModule === 'inventory' ? '−' : '+' }}</span>
                </button>
                @if ($expandedModule === 'inventory')
                    <div class="pl-6">
                        <a href="{{ route('inventory.warehouses.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.warehouses.*') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Магацини</a>
                        <a href="{{ route('inventory.items.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.items.index') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Артикли</a>
                        <a href="{{ route('inventory.items.bulk-import', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.items.bulk-import') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Масовен внес артикли</a>
                        <a href="{{ route('inventory.reports.stock-on-hand', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.reports.stock-on-hand') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Залиха</a>
                        <a href="{{ route('inventory.reports.item-movement-card', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.reports.item-movement-card') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Картица на движење</a>
                        <a href="{{ route('inventory.reports.stock-valuation', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('inventory.reports.stock-valuation') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Вреднување на залихи</a>

                        <button type="button" wire:click="toggleRecordMovement"
                                class="w-full text-left flex items-center justify-between px-4 py-1.5 text-sm {{ request()->routeIs('inventory.stock-movements.create') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">
                            <span>Движење на залиха</span>
                            <span>{{ $recordMovementExpanded ? '−' : '+' }}</span>
                        </button>
                        @if ($recordMovementExpanded)
                            <div class="pl-4">
                                <a href="{{ route('inventory.stock-movements.create', [$company, 'receipt']) }}" wire:navigate
                                   class="block px-4 py-1 text-sm text-gray-500 hover:text-gray-800">Прием</a>
                                <a href="{{ route('inventory.stock-movements.create', [$company, 'issue']) }}" wire:navigate
                                   class="block px-4 py-1 text-sm text-gray-500 hover:text-gray-800">Издавање</a>
                                <a href="{{ route('inventory.stock-movements.create', [$company, 'transfer']) }}" wire:navigate
                                   class="block px-4 py-1 text-sm text-gray-500 hover:text-gray-800">Трансфер</a>
                                <a href="{{ route('inventory.stock-movements.create', [$company, 'adjustment']) }}" wire:navigate
                                   class="block px-4 py-1 text-sm text-gray-500 hover:text-gray-800">Корекција</a>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Invoicing --}}
                <button type="button" wire:click="toggleModule('invoicing')"
                        class="w-full text-left flex items-center justify-between px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ (request()->routeIs('partners.*') || request()->routeIs('sales-invoices.*') || request()->routeIs('purchase-invoices.*')) ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}"
                        style="width: calc(100% - 1.5rem);">
                    <span>Фактури</span>
                    <span>{{ $expandedModule === 'invoicing' ? '−' : '+' }}</span>
                </button>
                @if ($expandedModule === 'invoicing')
                    <div class="pl-6">
                        <a href="{{ route('partners.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('partners.*') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Партнери</a>
                        <a href="{{ route('sales-invoices.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('sales-invoices.index') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Излезни фактури</a>
                        <a href="{{ route('sales-invoices.create', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('sales-invoices.create') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Нова фактура</a>
                        <a href="{{ route('purchase-invoices.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('purchase-invoices.index') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Влезни фактури</a>
                        <a href="{{ route('purchase-invoices.create', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('purchase-invoices.create') ? 'text-brand font-medium' : 'text-gray-500 hover:text-gray-800' }}">Нова влезна фактура</a>
                    </div>
                @endif

                {{-- Documents (no submenu) --}}
                <a href="{{ route('documents.index', $company) }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ request()->routeIs('documents.*') ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}">
                    Документи
                </a>

                {{-- Reports (no submenu) --}}
                <a href="{{ route('reports.ddv04', $company) }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ request()->routeIs('reports.*') ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}">
                    Извештаи
                </a>
            </div>
        @endif
    </nav>
</div>
```

Note: the top-level items switch from `rounded-r-full mr-3` (pill bleeding to the sidebar's right edge, which only worked visually against a dark background) to `rounded-lg mx-3` (a self-contained rounded rectangle with equal margin on both sides) — this fits a white sidebar where there's no dark edge for a half-pill to blend into.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AppShellTest`
Expected: PASS.

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test`
Expected: PASS. Grep the codebase first for any other test asserting sidebar dark-mode classes: `grep -rln "bg-gray-800\|text-gray-300\|text-gray-400" tests/Feature/*.php` — if any test targets the sidebar specifically (not just incidentally matching those common Tailwind classes elsewhere), read it and update its expectation to match the new light sidebar.

- [ ] **Step 7: Commit**

```bash
git add resources/views/layouts/app.blade.php resources/views/livewire/layout/sidebar.blade.php tests/Feature/AppShellTest.php
git commit -m "feat(ui): warm canvas background, convert sidebar from dark to light"
```

---

## Task 7: CompanyDashboard showcase — apply everything, get sign-off

The showcase screen. Every component task above (1-6) lands here automatically via the shared components; this task's own changes are the screen-specific bits: swapping the generic `hover:shadow-md` overrides for the new `hover:shadow-card-hover` token, and adding a small colored icon to each of the 5 module-launcher cards (pure decoration — no new data).

**Files:**
- Modify: `resources/views/livewire/company-dashboard.blade.php`
- Test: `tests/Feature/CompanyDashboardTest.php` (existing file — add assertions, don't remove any)

**Interfaces:**
- No PHP/Livewire changes — `App\Livewire\CompanyDashboard` (or wherever its backing class lives) is untouched. Only the Blade template's markup changes.

- [ ] **Step 1: Read the existing `CompanyDashboardTest.php` first**

Run: `cat tests/Feature/CompanyDashboardTest.php` (or open it) to see what it already asserts, so the new assertions you add don't duplicate or contradict existing ones.

- [ ] **Step 2: Write the failing test**

Add to `tests/Feature/CompanyDashboardTest.php` (adapt the `actingAs`/company-setup boilerplate to match whatever the existing tests in that file already use — do not invent a different setup pattern):

```php
    public function test_module_launcher_cards_use_the_warm_hover_shadow_token(): void
    {
        $response = $this->actingAs($this->user)->get(route('companies.dashboard', $this->company));

        $response->assertOk();
        $response->assertSee('hover:shadow-card-hover', false);
        $response->assertDontSee('hover:shadow-md', false);
    }
```

(If the file's existing tests build `$this->user`/`$this->company` differently — e.g. local variables inside each test method rather than instance properties — mirror that exact pattern instead of introducing instance properties.)

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=CompanyDashboardTest`
Expected: the new test FAILS (`hover:shadow-md` is still present), existing tests in the file still PASS.

- [ ] **Step 4: Update the 5 module-launcher cards' hover class (lines ~264-293)**

Replace all 5 occurrences of:
```blade
            <x-card class="hover:shadow-md transition-shadow">
```
with:
```blade
            <x-card class="hover:shadow-card-hover transition-shadow">
```

- [ ] **Step 5: Add a small colored icon to each module-launcher card**

Update each of the 5 card bodies to lead with a small icon above the heading — pure decoration, reusing the existing inline-SVG convention already used elsewhere in the app (e.g. `navigation.blade.php`'s chevron), no new dependency. Every icon uses the same `h-6 w-6 text-brand mb-2` sizing/color so the 5 read as one consistent set. Replace the whole grid block (lines ~263-293) with:

```blade
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <a href="{{ route('accounting.accounts.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-card-hover transition-shadow">
                <svg class="h-6 w-6 text-brand mb-2" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                </svg>
                <h2 class="font-semibold text-gray-700">Сметководство</h2>
                <p class="text-sm text-gray-500 mt-1">Контен план, налози, картици, биланс</p>
            </x-card>
        </a>
        <a href="{{ route('inventory.warehouses.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-card-hover transition-shadow">
                <svg class="h-6 w-6 text-brand mb-2" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                </svg>
                <h2 class="font-semibold text-gray-700">Магацин</h2>
                <p class="text-sm text-gray-500 mt-1">Магацини, артикли, извештаи за залихи</p>
            </x-card>
        </a>
        <a href="{{ route('sales-invoices.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-card-hover transition-shadow">
                <svg class="h-6 w-6 text-brand mb-2" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
                <h2 class="font-semibold text-gray-700">Фактури</h2>
                <p class="text-sm text-gray-500 mt-1">Партнери, излезни и влезни фактури</p>
            </x-card>
        </a>
        <a href="{{ route('documents.index', $company) }}" wire:navigate>
            <x-card class="hover:shadow-card-hover transition-shadow">
                <svg class="h-6 w-6 text-brand mb-2" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-19.5 0v6A2.25 2.25 0 004.5 21h15a2.25 2.25 0 002.25-2.25v-6m-19.5 0h19.5M4.5 9.75V6A2.25 2.25 0 016.75 3.75h3.879a1.5 1.5 0 011.06.44l1.882 1.881a1.5 1.5 0 001.06.44H17.25A2.25 2.25 0 0119.5 8.25v1.5" />
                </svg>
                <h2 class="font-semibold text-gray-700">Документи</h2>
                <p class="text-sm text-gray-500 mt-1">Прикачени и генерирани документи</p>
            </x-card>
        </a>
        <a href="{{ route('reports.ddv04', $company) }}" wire:navigate>
            <x-card class="hover:shadow-card-hover transition-shadow">
                <svg class="h-6 w-6 text-brand mb-2" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                </svg>
                <h2 class="font-semibold text-gray-700">Извештаи</h2>
                <p class="text-sm text-gray-500 mt-1">Законски извештаи</p>
            </x-card>
        </a>
    </div>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=CompanyDashboardTest`
Expected: PASS (new test and all pre-existing ones).

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 8: Live browser verification — get the user's real sign-off**

This is the plan's actual goal, not a formality: start the local dev server (`.claude/launch.json`'s `laravel` config, i.e. `php artisan serve` on port 8000), log in as the user's real account, navigate to a real company's dashboard (`/companies/{company}/dashboard`), and take a screenshot. Confirm live in the browser: canvas background visible, sidebar is white with an orange-filled active item, module cards show the new icons and lift with a warm shadow on hover, buttons read in normal case. Show the screenshot to the user and wait for explicit approval before Plan B (rollout to the remaining ~39 screens) is written — per the approved design doc, this is the checkpoint the rest of the redesign depends on.

- [ ] **Step 9: Commit**

```bash
git add resources/views/livewire/company-dashboard.blade.php tests/Feature/CompanyDashboardTest.php
git commit -m "feat(ui): apply warm design system to CompanyDashboard showcase screen"
```

---

## After this plan

Do not write Plan B (the remaining ~39 screens + PDF reports, module by module) until the user has approved the live Task 7 screenshot/browser session. Plan B should be written as its own `writing-plans` pass at that point — not appended here — so its tasks can reference the exact, final class names this plan actually shipped (e.g. if the user asks for a tweak to the sidebar's active-pill color during Task 7's sign-off, Plan B must plan against the corrected version, not this one).
