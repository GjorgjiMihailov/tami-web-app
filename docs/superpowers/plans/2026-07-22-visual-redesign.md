# Visual Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refresh the entire app's visual appearance — sidebar navigation, brand colors, Manrope font, rounded/pill component style, and status badges — without changing any business logic, routes, permissions, or data.

**Architecture:** Most interactive elements (buttons, inputs, dropdowns, nav links) already funnel through a small set of shared Blade components in `resources/views/components/`; restyling those cascades the new look everywhere. Two new shared components (`<x-card>`, `<x-badge>`) replace patterns currently duplicated inline across ~25 views. A new sidebar Livewire component replaces the top-bar-only navigation, reading the current route's `{company}` parameter to render company-scoped module links.

**Tech Stack:** Laravel 13 + Livewire 3 + Tailwind CSS (existing app), no new dependencies.

## Global Constraints

- Brand color `#ff6600` (already registered as `brand.DEFAULT`/`brand.light`/`brand.dark` in `tailwind.config.js` since Phase 0a) is the primary action/accent/active-nav color. Sidebar background is `bg-gray-800` (Tailwind's built-in dark gray, not near-black) — no new color tokens are needed since all other colors used (grays, green/amber/red status colors) are Tailwind defaults already available.
- Font: Manrope (weights 400/500/600/700), loaded via bunny.net exactly like the current Figtree setup.
- Component style: `rounded-2xl` cards with a soft shadow and no border (`shadow-sm`), pill-shaped (`rounded-full`) buttons and badges.
- Status badges use muted semantic colors (`green-100`/`green-800` confirmed/paid, `amber-100`/`amber-800` draft/pending/unpaid, `red-100`/`red-800` cancelled/overdue) — everywhere else stays within the brand/gray palette.
- This is a presentation-layer-only change: no route, policy, model, or migration changes anywhere in this plan.
- Scope note on language: the 5 sidebar module labels (Сметководство, Магацин, Фактури, Документи, Извештаи) are Macedonian, exactly as approved in the design spec — this is a narrow, deliberate exception, not a start of full localization. Every other label/string in the app (buttons, table headers, form labels) stays in English; full Macedonian localization is a separate, later effort.
- No PDF/file-export changes beyond brand colors on the existing invoice PDF template.
- Out of scope: `resources/views/welcome.blade.php` and `resources/views/livewire/welcome/navigation.blade.php` — Laravel's default unbranded landing splash (unused marketing boilerplate, not real app content), shown only to logged-out visitors at `/`. Redesigning it meaningfully would require new copy/content decisions, not just style application, so it's left as-is this pass.
- Existing tests assert on visible text and Livewire behavior, not CSS classes, so pure restyling tasks are verified by "existing suite still green" rather than new assertions — new assertions are only written for genuinely new logic (the sidebar's company-scoping, the badge's status-to-color mapping).

---

### Task 1: Font, brand colors, and shared interactive components

**Files:**
- Modify: `tailwind.config.js`
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `resources/views/layouts/guest.blade.php`
- Modify: `resources/views/components/primary-button.blade.php`
- Modify: `resources/views/components/secondary-button.blade.php`
- Modify: `resources/views/components/danger-button.blade.php`
- Modify: `resources/views/components/text-input.blade.php`
- Modify: `resources/views/components/dropdown-link.blade.php`
- Modify: `resources/views/components/nav-link.blade.php`
- Modify: `resources/views/components/responsive-nav-link.blade.php`

**Interfaces:**
- No new PHP interfaces — this task only changes Tailwind utility classes and the font `<link>` tag. Later tasks depend on these components' classes being brand-colored (buttons orange, focus rings orange) but not on any new method/prop.

- [ ] **Step 1: Change the font in the Tailwind config**

Modify `tailwind.config.js` — change `'Figtree'` to `'Manrope'`:

```js
import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

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
            },
        },
    },

    plugins: [forms],
};
```

- [ ] **Step 2: Load Manrope and restyle the app layout shell**

Modify `resources/views/layouts/app.blade.php` (full file):

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=manrope:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen flex bg-gray-50">
            <livewire:layout.sidebar />

            <div class="flex-1 flex flex-col min-w-0">
                <livewire:layout.navigation />

                <!-- Page Heading -->
                @if (isset($header))
                    <header class="bg-white border-b border-gray-100">
                        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endif

                <!-- Page Content -->
                <main class="flex-1 p-4 sm:p-6">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
```

(`<livewire:layout.sidebar />` doesn't exist yet — it's built in Task 3. Referencing it here now is fine since Blade/Livewire resolves component tags at render time, not at file-parse time; nothing in this task renders a page yet.)

- [ ] **Step 3: Load Manrope in the guest layout**

Modify `resources/views/layouts/guest.blade.php` — change the font `<link>` line:

```blade
        <link href="https://fonts.bunny.net/css?family=manrope:400,500,600&display=swap" rel="stylesheet" />
```

(replaces the existing `figtree:400,500,600` line — rest of the file is unchanged.)

- [ ] **Step 4: Restyle buttons to brand orange and pill shape**

Modify `resources/views/components/primary-button.blade.php` (full file):

```blade
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-brand border border-transparent rounded-full font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-dark focus:bg-brand-dark active:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
```

Modify `resources/views/components/secondary-button.blade.php` (full file):

```blade
<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-full font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
```

Modify `resources/views/components/danger-button.blade.php` (full file — unchanged red semantics, just pill shape and brand-colored focus ring):

```blade
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-full font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
```

- [ ] **Step 5: Restyle inputs, dropdown links, and nav links to brand orange**

Modify `resources/views/components/text-input.blade.php` (full file):

```blade
@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-300 focus:border-brand focus:ring-brand rounded-lg shadow-sm']) }}>
```

Modify `resources/views/components/dropdown-link.blade.php` (full file — unchanged, no brand color used here, no edit needed). Skip this file — leave as-is (plain gray hover, no brand color appropriate for a dropdown list item).

Modify `resources/views/components/nav-link.blade.php` (full file):

```blade
@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex items-center px-1 pt-1 border-b-2 border-brand text-sm font-medium leading-5 text-gray-900 focus:outline-none focus:border-brand-dark transition duration-150 ease-in-out'
            : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 hover:text-gray-700 hover:border-gray-300 focus:outline-none focus:text-gray-700 focus:border-gray-300 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
```

Modify `resources/views/components/responsive-nav-link.blade.php` (full file):

```blade
@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-brand text-start text-base font-medium text-brand-dark bg-orange-50 focus:outline-none focus:text-brand-dark focus:bg-orange-100 focus:border-brand-dark transition duration-150 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-gray-600 hover:text-gray-800 hover:bg-gray-50 hover:border-gray-300 focus:outline-none focus:text-gray-800 focus:bg-gray-50 focus:border-gray-300 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
```

- [ ] **Step 6: Run the full test suite to confirm no regressions**

Run: `php artisan test`
Expected: full suite PASS — these are pure Tailwind-class changes to shared components; no test in this codebase asserts on CSS classes, so nothing should break. (Note: `<livewire:layout.sidebar />` referenced in Step 2 doesn't exist until Task 3 — any test that renders a full page through `layouts.app` will fail at this point with "Unable to find component". This is expected and resolved by Task 3, not this task. If you want a green suite at the end of this task specifically, you may temporarily comment out that one line and restore it in Task 3 — otherwise proceed directly to Task 2 and 3 before running the suite.)

- [ ] **Step 7: Commit**

```bash
git add tailwind.config.js resources/views/layouts/app.blade.php resources/views/layouts/guest.blade.php resources/views/components/primary-button.blade.php resources/views/components/secondary-button.blade.php resources/views/components/danger-button.blade.php resources/views/components/text-input.blade.php resources/views/components/nav-link.blade.php resources/views/components/responsive-nav-link.blade.php
git commit -m "Switch to Manrope font, brand-colored components, and the new layout shell"
```

---

### Task 2: `<x-card>` and `<x-badge>` shared components

**Files:**
- Create: `resources/views/components/card.blade.php`
- Create: `resources/views/components/badge.blade.php`
- Test: `tests/Feature/CardAndBadgeComponentTest.php`

**Interfaces:**
- Produces: `<x-card>{{ $slot }}</x-card>` (accepts a normal `class` attribute merge). `<x-badge status="...">{{ $slot }}</x-badge>` — maps `status` to a color class; the slot controls the displayed text (the component doesn't hardcode labels, since those will need translation later).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/CardAndBadgeComponentTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class CardAndBadgeComponentTest extends TestCase
{
    public function test_card_renders_its_slot_with_rounded_style(): void
    {
        $html = Blade::render('<x-card>Hello</x-card>');

        $this->assertStringContainsString('Hello', $html);
        $this->assertStringContainsString('rounded-2xl', $html);
    }

    public function test_badge_maps_confirmed_and_paid_to_green(): void
    {
        $confirmed = Blade::render('<x-badge status="confirmed">Confirmed</x-badge>');
        $paid = Blade::render('<x-badge status="paid">Paid</x-badge>');

        $this->assertStringContainsString('bg-green-100', $confirmed);
        $this->assertStringContainsString('bg-green-100', $paid);
    }

    public function test_badge_maps_draft_and_unpaid_to_amber(): void
    {
        $draft = Blade::render('<x-badge status="draft">Draft</x-badge>');
        $unpaid = Blade::render('<x-badge status="unpaid">Unpaid</x-badge>');

        $this->assertStringContainsString('bg-amber-100', $draft);
        $this->assertStringContainsString('bg-amber-100', $unpaid);
    }

    public function test_badge_maps_cancelled_and_overdue_to_red(): void
    {
        $cancelled = Blade::render('<x-badge status="cancelled">Cancelled</x-badge>');
        $overdue = Blade::render('<x-badge status="overdue">Overdue</x-badge>');

        $this->assertStringContainsString('bg-red-100', $cancelled);
        $this->assertStringContainsString('bg-red-100', $overdue);
    }

    public function test_badge_falls_back_to_gray_for_an_unknown_status(): void
    {
        $html = Blade::render('<x-badge status="something-else">Something</x-badge>');

        $this->assertStringContainsString('bg-gray-100', $html);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=CardAndBadgeComponentTest`
Expected: FAIL — `Unable to locate a class or view for component [card]` (or `[badge]`).

- [ ] **Step 3: Create the `<x-card>` component**

Create `resources/views/components/card.blade.php`:

```blade
<div {{ $attributes->merge(['class' => 'bg-white rounded-2xl shadow-sm p-4']) }}>
    {{ $slot }}
</div>
```

- [ ] **Step 4: Create the `<x-badge>` component**

Create `resources/views/components/badge.blade.php`:

```blade
@props(['status'])

@php
$classes = match ($status) {
    'confirmed', 'paid', 'active' => 'bg-green-100 text-green-800',
    'draft', 'pending', 'unpaid' => 'bg-amber-100 text-amber-800',
    'cancelled', 'overdue' => 'bg-red-100 text-red-800',
    default => 'bg-gray-100 text-gray-700',
};
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold {$classes}"]) }}>
    {{ $slot }}
</span>
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=CardAndBadgeComponentTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add resources/views/components/card.blade.php resources/views/components/badge.blade.php tests/Feature/CardAndBadgeComponentTest.php
git commit -m "Add shared x-card and x-badge components"
```

---

### Task 3: Sidebar navigation component

**Files:**
- Create: `app/Livewire/Layout/Sidebar.php`
- Create: `resources/views/livewire/layout/sidebar.blade.php`
- Modify: `resources/views/livewire/layout/navigation.blade.php`
- Test: `tests/Feature/SidebarTest.php`

**Interfaces:**
- Consumes: nothing new — reads `request()->route('company')`, which Laravel's router already resolves via implicit model binding for every company-scoped route (`{company}` parameter) before any component renders.
- Produces: `<livewire:layout.sidebar />`, referenced by `layouts/app.blade.php` (added in Task 1).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SidebarTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SidebarTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_it_shows_no_module_links_when_no_company_is_selected(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Сметководство')
            ->assertDontSee('Магацин');
    }

    public function test_it_shows_module_links_scoped_to_the_current_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('accounting.accounts.index', $company))
            ->assertOk()
            ->assertSee('Сметководство')
            ->assertSee(route('inventory.warehouses.index', $company), false)
            ->assertSee(route('sales-invoices.index', $company), false)
            ->assertSee(route('documents.index', $company), false)
            ->assertSee(route('reports.ddv04', $company), false);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SidebarTest`
Expected: FAIL — `Unable to find component: [App\Livewire\Layout\Sidebar]` (or similar, since `layouts/app.blade.php` already references `<livewire:layout.sidebar />` from Task 1).

- [ ] **Step 3: Create the Sidebar Livewire component**

Create `app/Livewire/Layout/Sidebar.php`:

```php
<?php

namespace App\Livewire\Layout;

use App\Models\Company;
use Livewire\Component;

class Sidebar extends Component
{
    public function render()
    {
        $company = request()->route('company');

        return view('livewire.layout.sidebar', [
            'company' => $company instanceof Company ? $company : null,
        ]);
    }
}
```

- [ ] **Step 4: Create the sidebar view**

Create `resources/views/livewire/layout/sidebar.blade.php`:

```blade
<div class="w-60 shrink-0 bg-gray-800 text-white flex flex-col min-h-screen">
    <div class="px-4 py-4 border-b border-gray-700">
        <a href="{{ route('dashboard') }}" wire:navigate class="font-bold text-brand text-sm">
            {{ config('app.name', 'Laravel') }}
        </a>
    </div>

    <nav class="flex-1 py-3 space-y-1">
        <a href="{{ route('dashboard') }}" wire:navigate
           class="block px-4 py-2 text-sm font-medium {{ request()->routeIs('dashboard') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
            Dashboard
        </a>
        <a href="{{ route('companies.index') }}" wire:navigate
           class="block px-4 py-2 text-sm font-medium {{ request()->routeIs('companies.*') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
            Companies
        </a>

        @if ($company)
            <div class="pt-4 mt-3 border-t border-gray-700">
                <div class="px-4 pb-2 text-xs uppercase tracking-wide text-gray-400">{{ $company->name }}</div>

                <a href="{{ route('accounting.accounts.index', $company) }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium {{ request()->routeIs('accounting.*') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
                    Сметководство
                </a>
                <a href="{{ route('inventory.warehouses.index', $company) }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium {{ request()->routeIs('inventory.*') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
                    Магацин
                </a>
                <a href="{{ route('sales-invoices.index', $company) }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium {{ (request()->routeIs('partners.*') || request()->routeIs('sales-invoices.*') || request()->routeIs('purchase-invoices.*')) ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
                    Фактури
                </a>
                <a href="{{ route('documents.index', $company) }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium {{ request()->routeIs('documents.*') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
                    Документи
                </a>
                <a href="{{ route('reports.ddv04', $company) }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium {{ request()->routeIs('reports.*') ? 'bg-brand text-white rounded-r-full mr-3' : 'text-gray-300 hover:text-white' }}">
                    Извештаи
                </a>
            </div>
        @endif
    </nav>
</div>
```

- [ ] **Step 5: Simplify the top bar — remove the old Dashboard/Companies links and logo (now in the sidebar)**

Modify `resources/views/livewire/layout/navigation.blade.php` (full file):

```blade
<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<nav x-data="{ open: false }" class="bg-white border-b border-gray-100">
    <div class="px-4 sm:px-6 lg:px-8">
        <div class="flex justify-end h-14 items-center">
            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-full text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                            <div x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile')" wire:navigate>
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        <!-- Authentication -->
                        <button wire:click="logout" class="w-full text-start">
                            <x-dropdown-link>
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </button>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-4 pb-1 border-t border-gray-200">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>
                <div class="font-medium text-sm text-gray-500">{{ auth()->user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile')" wire:navigate>
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <!-- Authentication -->
                <button wire:click="logout" class="w-full text-start">
                    <x-responsive-nav-link>
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </button>
            </div>
        </div>
    </div>
</nav>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=SidebarTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: full suite PASS — Tasks 1-3 together give `layouts/app.blade.php` everything it references.

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Layout/Sidebar.php resources/views/livewire/layout/sidebar.blade.php resources/views/livewire/layout/navigation.blade.php tests/Feature/SidebarTest.php
git commit -m "Add sidebar navigation with company-scoped module links"
```

---

### Task 4: Apply card/badge to Accounting views

**Files:**
- Modify: `resources/views/livewire/accounting/account-index.blade.php`
- Modify: `resources/views/livewire/accounting/journal-entry-index.blade.php`
- Modify: `resources/views/livewire/accounting/journal-entry-form.blade.php`
- Modify: `resources/views/livewire/accounting/ledger-card-report.blade.php`
- Modify: `resources/views/livewire/accounting/trial-balance-report.blade.php`

**Interfaces:**
- Consumes: `<x-card>` (Task 2).

- [ ] **Step 1: Wrap the "Add analytical account" form in `account-index.blade.php`**

Modify `resources/views/livewire/accounting/account-index.blade.php` — replace:

```blade
    @can('create', \App\Models\Account::class)
        <div class="bg-white shadow rounded-md p-4 mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Add analytical account</h2>
```

with:

```blade
    @can('create', \App\Models\Account::class)
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Add analytical account</h2>
```

and replace the matching closing tags:

```blade
                <x-primary-button type="submit">Add</x-primary-button>
            </form>
        </div>
    @endcan
```

with:

```blade
                <x-primary-button type="submit">Add</x-primary-button>
            </form>
        </x-card>
    @endcan
```

- [ ] **Step 2: Add the "New Entry" button's card-free table stays as-is; wrap the table in `journal-entry-index.blade.php`**

Modify `resources/views/livewire/accounting/journal-entry-index.blade.php` — replace:

```blade
    <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
```

with:

```blade
    <x-card class="p-0 overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
```

and replace:

```blade
    </table>

    <div class="mt-4">{{ $entries->links() }}</div>
```

with:

```blade
    </table>
    </x-card>

    <div class="mt-4">{{ $entries->links() }}</div>
```

(The card wraps only the table, not the pagination links below it — `p-0` because the table's own cell padding already provides spacing, and `overflow-hidden` keeps the table's square corners from poking out past the card's rounded corners.)

- [ ] **Step 3: Wrap the form in `journal-entry-form.blade.php`**

Modify `resources/views/livewire/accounting/journal-entry-form.blade.php` — replace:

```blade
    <form wire:submit="save" class="bg-white shadow rounded-md p-4">
```

with:

```blade
    <x-card>
    <form wire:submit="save">
```

and replace the very last two lines of the file:

```blade
    </form>
</div>
```

with:

```blade
    </form>
    </x-card>
</div>
```

- [ ] **Step 4: Wrap the filter bar and table in `ledger-card-report.blade.php`**

Modify `resources/views/livewire/accounting/ledger-card-report.blade.php` — replace:

```blade
    <div class="bg-white shadow rounded-md p-4 mb-4 flex flex-wrap gap-4 items-end">
```

with:

```blade
    <x-card class="mb-4 flex flex-wrap gap-4 items-end">
```

replace the matching closing (the `</div>` right before the blank line and `@if ($accountId || $partnerId)`):

```blade
        </div>
    </div>

    @if ($accountId || $partnerId)
        <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
```

with:

```blade
        </div>
    </x-card>

    @if ($accountId || $partnerId)
        <x-card class="p-0 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
```

and replace the closing:

```blade
            </tbody>
        </table>
    @else
```

with:

```blade
            </tbody>
        </table>
        </x-card>
    @else
```

- [ ] **Step 5: Apply the same filter-bar treatment to `trial-balance-report.blade.php`**

Modify `resources/views/livewire/accounting/trial-balance-report.blade.php` — replace:

```blade
    <div class="bg-white shadow rounded-md p-4 mb-4 flex flex-wrap gap-4 items-end">
```

with:

```blade
    <x-card class="mb-4 flex flex-wrap gap-4 items-end">
```

replace the matching closing (three `</div>` in a row before the table):

```blade
        </div>
    </div>

    <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
```

with:

```blade
        </div>
    </x-card>

    <x-card class="p-0 overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
```

and replace the final two lines of the file:

```blade
    </table>
</div>
```

with:

```blade
    </table>
    </x-card>
</div>
```

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test`
Expected: full suite PASS. Also run `php artisan test --filter=AccountIndexTest` and `--filter=LedgerCardReportTest` and `--filter=TrialBalanceReportTest` and `--filter=JournalEntry` specifically to confirm this module's own tests are unaffected (they assert on visible text like account codes and amounts, not on the wrapper markup).

- [ ] **Step 7: Verify no stray old wrapper markup remains in this module**

Run: `grep -rn "bg-white shadow rounded" resources/views/livewire/accounting/`
Expected: no output (empty).

- [ ] **Step 8: Commit**

```bash
git add resources/views/livewire/accounting/
git commit -m "Apply card component to Accounting views"
```

---

### Task 5: Apply card/badge to Inventory views

**Files:**
- Modify: `resources/views/livewire/inventory/warehouse-index.blade.php`
- Modify: `resources/views/livewire/inventory/item-index.blade.php`
- Modify: `resources/views/livewire/inventory/stock-movement-form.blade.php`
- Modify: `resources/views/livewire/inventory/stock-on-hand-report.blade.php`
- Modify: `resources/views/livewire/inventory/stock-valuation-report.blade.php`
- Modify: `resources/views/livewire/inventory/item-movement-card-report.blade.php`

**Interfaces:**
- Consumes: `<x-card>` (Task 2).

- [ ] **Step 1: Wrap the "Add warehouse" form and the table in `warehouse-index.blade.php`**

Modify `resources/views/livewire/inventory/warehouse-index.blade.php` — replace:

```blade
    @can('create', \App\Models\Warehouse::class)
        <div class="bg-white shadow rounded-md p-4 mb-6">
```

with:

```blade
    @can('create', \App\Models\Warehouse::class)
        <x-card class="mb-6">
```

replace:

```blade
                <x-primary-button type="submit">Add</x-primary-button>
            </form>
        </div>
    @endcan

    <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
```

with:

```blade
                <x-primary-button type="submit">Add</x-primary-button>
            </form>
        </x-card>
    @endcan

    <x-card class="p-0 overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
```

and replace the final two lines of the file:

```blade
    </table>
</div>
```

with:

```blade
    </table>
    </x-card>
</div>
```

- [ ] **Step 2: Same treatment for `item-index.blade.php`**

Modify `resources/views/livewire/inventory/item-index.blade.php` — replace:

```blade
    @can('create', \App\Models\Item::class)
        <div class="bg-white shadow rounded-md p-4 mb-6">
```

with:

```blade
    @can('create', \App\Models\Item::class)
        <x-card class="mb-6">
```

replace:

```blade
                <x-primary-button type="submit">Add</x-primary-button>
            </form>
        </div>
    @endcan

    <div class="mb-4">
        <x-text-input wire:model.live="search" placeholder="Search by name or code" class="w-full max-w-sm" />
    </div>

    <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
```

with:

```blade
                <x-primary-button type="submit">Add</x-primary-button>
            </form>
        </x-card>
    @endcan

    <div class="mb-4">
        <x-text-input wire:model.live="search" placeholder="Search by name or code" class="w-full max-w-sm" />
    </div>

    <x-card class="p-0 overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
```

and replace the final two lines of the file:

```blade
    </table>
</div>
```

with:

```blade
    </table>
    </x-card>
</div>
```

- [ ] **Step 3: Wrap the form in `stock-movement-form.blade.php`**

Modify `resources/views/livewire/inventory/stock-movement-form.blade.php` — replace:

```blade
    <form wire:submit="save" class="bg-white shadow rounded-md p-4 flex flex-wrap gap-4 items-end max-w-3xl">
```

with:

```blade
    <x-card class="max-w-3xl">
    <form wire:submit="save" class="flex flex-wrap gap-4 items-end">
```

and replace the final two lines of the file:

```blade
    </form>
</div>
```

with:

```blade
    </form>
    </x-card>
</div>
```

- [ ] **Step 4: Wrap the filter bar and both tables in `stock-on-hand-report.blade.php`**

Modify `resources/views/livewire/inventory/stock-on-hand-report.blade.php` — replace:

```blade
    <div class="bg-white shadow rounded-md p-4 mb-4 flex flex-wrap gap-4 items-end">
```

with:

```blade
    <x-card class="mb-4 flex flex-wrap gap-4 items-end">
```

replace:

```blade
        </div>
    </div>

    @if ($warehouseId)
        <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
```

with:

```blade
        </div>
    </x-card>

    @if ($warehouseId)
        <x-card class="p-0 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
```

replace:

```blade
            </tbody>
        </table>
    @else
        <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
```

with:

```blade
            </tbody>
        </table>
        </x-card>
    @else
        <x-card class="p-0 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
```

and replace the final three lines of the file:

```blade
        </table>
    @endif
</div>
```

with:

```blade
        </table>
        </x-card>
    @endif
</div>
```

- [ ] **Step 5: Wrap the filter bar and table in `stock-valuation-report.blade.php`**

Modify `resources/views/livewire/inventory/stock-valuation-report.blade.php` — replace:

```blade
    <div class="bg-white shadow rounded-md p-4 mb-4 flex flex-wrap gap-4 items-end">
```

with:

```blade
    <x-card class="mb-4 flex flex-wrap gap-4 items-end">
```

replace:

```blade
        </div>
    </div>

    <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
```

with:

```blade
        </div>
    </x-card>

    <x-card class="p-0 overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
```

and replace the final two lines of the file:

```blade
    </table>
</div>
```

with:

```blade
    </table>
    </x-card>
</div>
```

- [ ] **Step 6: Wrap the filter bar and table in `item-movement-card-report.blade.php`**

Modify `resources/views/livewire/inventory/item-movement-card-report.blade.php` — replace:

```blade
    <div class="bg-white shadow rounded-md p-4 mb-4 flex flex-wrap gap-4 items-end">
```

with:

```blade
    <x-card class="mb-4 flex flex-wrap gap-4 items-end">
```

replace:

```blade
        </div>
    </div>

    @if ($itemId && $warehouseId)
        <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
```

with:

```blade
        </div>
    </x-card>

    @if ($itemId && $warehouseId)
        <x-card class="p-0 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
```

and replace:

```blade
            </tbody>
        </table>
    @else
```

with:

```blade
            </tbody>
        </table>
        </x-card>
    @else
```

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: full suite PASS. Also run `--filter=WarehouseIndexTest`, `--filter=ItemIndexTest`, `--filter=StockMovementFormTest`, `--filter=StockOnHandReportTest`, `--filter=StockValuationReportTest`, `--filter=ItemMovementCardReportTest` for this module specifically.

- [ ] **Step 8: Verify no stray old wrapper markup remains**

Run: `grep -rn "bg-white shadow rounded" resources/views/livewire/inventory/`
Expected: no output (empty).

- [ ] **Step 9: Commit**

```bash
git add resources/views/livewire/inventory/
git commit -m "Apply card component to Inventory views"
```

---

### Task 6: Apply card/badge to Invoicing views

**Files:**
- Modify: `resources/views/livewire/invoicing/sales-invoice-form.blade.php`
- Modify: `resources/views/livewire/invoicing/sales-invoice-index.blade.php`
- Modify: `resources/views/livewire/invoicing/sales-invoice-show.blade.php`
- Modify: `resources/views/livewire/invoicing/purchase-invoice-form.blade.php`
- Modify: `resources/views/livewire/invoicing/purchase-invoice-index.blade.php`
- Modify: `resources/views/livewire/invoicing/purchase-invoice-show.blade.php`

**Interfaces:**
- Consumes: `<x-card>`, `<x-badge>` (Task 2).

- [ ] **Step 1: Wrap the three panels in `sales-invoice-form.blade.php`**

Modify `resources/views/livewire/invoicing/sales-invoice-form.blade.php` — replace:

```blade
    <form wire:submit="save" class="space-y-6">
        <div class="bg-white shadow rounded-md p-4 grid grid-cols-1 sm:grid-cols-4 gap-4">
```

with:

```blade
    <form wire:submit="save" class="space-y-6">
        <x-card class="grid grid-cols-1 sm:grid-cols-4 gap-4">
```

replace:

```blade
        </div>

        <div class="bg-white shadow rounded-md p-4">
            <h2 class="font-semibold text-gray-700 mb-3">Lines</h2>
```

with:

```blade
        </x-card>

        <x-card>
            <h2 class="font-semibold text-gray-700 mb-3">Lines</h2>
```

replace:

```blade
            <button type="button" wire:click="addLine" class="text-indigo-600 text-sm hover:underline">+ Add line</button>
        </div>

        <div class="bg-white shadow rounded-md p-4">
            <x-input-label for="notes" value="Notes" />
```

with:

```blade
            <button type="button" wire:click="addLine" class="text-indigo-600 text-sm hover:underline">+ Add line</button>
        </x-card>

        <x-card>
            <x-input-label for="notes" value="Notes" />
```

and replace:

```blade
            <textarea id="notes" wire:model="notes" rows="2" class="w-full border-gray-300 rounded-md text-sm"></textarea>
        </div>

        <x-primary-button type="submit">Save draft</x-primary-button>
```

with:

```blade
            <textarea id="notes" wire:model="notes" rows="2" class="w-full border-gray-300 rounded-md text-sm"></textarea>
        </x-card>

        <x-primary-button type="submit">Save draft</x-primary-button>
```

- [ ] **Step 2: Wrap the table and badge the status column in `sales-invoice-index.blade.php`**

Modify `resources/views/livewire/invoicing/sales-invoice-index.blade.php` — replace:

```blade
    <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
```

with:

```blade
    <x-card class="p-0 overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
```

replace:

```blade
                    <td class="py-2 px-4">{{ $invoice->status }}</td>
```

with:

```blade
                    <td class="py-2 px-4"><x-badge :status="$invoice->status">{{ ucfirst($invoice->status) }}</x-badge></td>
```

and replace the final two lines of the file:

```blade
    </table>
</div>
```

with:

```blade
    </table>
    </x-card>
</div>
```

- [ ] **Step 3: Badge both status lines in `sales-invoice-show.blade.php`**

Modify `resources/views/livewire/invoicing/sales-invoice-show.blade.php` — replace:

```blade
    <p class="text-sm text-gray-500 mb-4">{{ $invoice->partner->name }} — status: {{ $invoice->status }}
        @if ($invoice->status === 'confirmed') ({{ $invoice->paymentStatus() }}@if($invoice->isOverdue()), overdue @endif) @endif
    </p>
```

with:

```blade
    <p class="text-sm text-gray-500 mb-4 flex items-center gap-2">
        {{ $invoice->partner->name }}
        <x-badge :status="$invoice->status">{{ ucfirst($invoice->status) }}</x-badge>
        @if ($invoice->status === 'confirmed')
            <x-badge :status="$invoice->isOverdue() ? 'overdue' : $invoice->paymentStatus()">
                {{ $invoice->isOverdue() ? 'Overdue' : ucfirst($invoice->paymentStatus()) }}
            </x-badge>
        @endif
    </p>
```

- [ ] **Step 4: Same three-panel treatment for `purchase-invoice-form.blade.php`**

Modify `resources/views/livewire/invoicing/purchase-invoice-form.blade.php` — replace:

```blade
    <form wire:submit="save" class="space-y-6">
        <div class="bg-white shadow rounded-md p-4 grid grid-cols-1 sm:grid-cols-4 gap-4">
```

with:

```blade
    <form wire:submit="save" class="space-y-6">
        <x-card class="grid grid-cols-1 sm:grid-cols-4 gap-4">
```

replace:

```blade
        </div>

        <div class="bg-white shadow rounded-md p-4">
            <h2 class="font-semibold text-gray-700 mb-3">Lines</h2>
```

with:

```blade
        </x-card>

        <x-card>
            <h2 class="font-semibold text-gray-700 mb-3">Lines</h2>
```

replace:

```blade
            <button type="button" wire:click="addLine" class="text-indigo-600 text-sm hover:underline">+ Add line</button>
        </div>

        <div class="bg-white shadow rounded-md p-4">
            <x-input-label for="notes" value="Notes" />
```

with:

```blade
            <button type="button" wire:click="addLine" class="text-indigo-600 text-sm hover:underline">+ Add line</button>
        </x-card>

        <x-card>
            <x-input-label for="notes" value="Notes" />
```

and replace:

```blade
            <textarea id="notes" wire:model="notes" rows="2" class="w-full border-gray-300 rounded-md text-sm"></textarea>
        </div>

        <x-primary-button type="submit">Save draft</x-primary-button>
```

with:

```blade
            <textarea id="notes" wire:model="notes" rows="2" class="w-full border-gray-300 rounded-md text-sm"></textarea>
        </x-card>

        <x-primary-button type="submit">Save draft</x-primary-button>
```

- [ ] **Step 5: Wrap the table and badge the status column in `purchase-invoice-index.blade.php`**

Modify `resources/views/livewire/invoicing/purchase-invoice-index.blade.php` — replace:

```blade
    <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
```

with:

```blade
    <x-card class="p-0 overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
```

replace:

```blade
                    <td class="py-2 px-4">{{ $invoice->status }}</td>
```

with:

```blade
                    <td class="py-2 px-4"><x-badge :status="$invoice->status">{{ ucfirst($invoice->status) }}</x-badge></td>
```

and replace the final two lines of the file:

```blade
    </table>
</div>
```

with:

```blade
    </table>
    </x-card>
</div>
```

- [ ] **Step 6: Badge both status lines in `purchase-invoice-show.blade.php`**

Modify `resources/views/livewire/invoicing/purchase-invoice-show.blade.php` — replace:

```blade
    <p class="text-sm text-gray-500 mb-4">status: {{ $invoice->status }}
        @if ($invoice->status === 'confirmed') ({{ $invoice->paymentStatus() }}@if($invoice->isOverdue()), overdue @endif) @endif
    </p>
```

with:

```blade
    <p class="text-sm text-gray-500 mb-4 flex items-center gap-2">
        <x-badge :status="$invoice->status">{{ ucfirst($invoice->status) }}</x-badge>
        @if ($invoice->status === 'confirmed')
            <x-badge :status="$invoice->isOverdue() ? 'overdue' : $invoice->paymentStatus()">
                {{ $invoice->isOverdue() ? 'Overdue' : ucfirst($invoice->paymentStatus()) }}
            </x-badge>
        @endif
    </p>
```

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: full suite PASS. Also run `--filter=SalesInvoiceFormTest`, `--filter=SalesInvoiceIndexTest`, `--filter=SalesInvoiceShowTest`, `--filter=PurchaseInvoiceFormTest`, `--filter=PurchaseInvoiceIndexTest`, `--filter=PurchaseInvoiceShowTest` — check each still asserts correctly. If any existing test does something like `->assertSee($invoice->status)` expecting the raw lowercase status string standing alone, this change may need that assertion updated to match the new `ucfirst()`-cased badge text — read each failing test's exact assertion before editing it, and only adjust the text-casing expectation, never the underlying behavior being tested.

- [ ] **Step 8: Verify no stray old wrapper markup remains**

Run: `grep -rn "bg-white shadow rounded" resources/views/livewire/invoicing/`
Expected: no output (empty).

- [ ] **Step 9: Commit**

```bash
git add resources/views/livewire/invoicing/
git commit -m "Apply card and badge components to Invoicing views"
```

---

### Task 7: Apply card/badge to Documents, Reports, Partners, and Companies views

**Files:**
- Modify: `resources/views/livewire/document-manager.blade.php`
- Modify: `resources/views/livewire/document-index.blade.php`
- Modify: `resources/views/livewire/reports/ddv04-report.blade.php`
- Modify: `resources/views/livewire/partner-index.blade.php`
- Modify: `resources/views/livewire/partner-show.blade.php`
- Modify: `resources/views/livewire/company-index.blade.php`

**Interfaces:**
- Consumes: `<x-card>` (Task 2).

- [ ] **Step 1: Wrap the panel in `document-manager.blade.php`**

Modify `resources/views/livewire/document-manager.blade.php` — replace:

```blade
<div class="bg-white shadow rounded-md p-4 mt-4">
```

with:

```blade
<x-card class="mt-4">
```

and replace the final line of the file:

```blade
</div>
```

with:

```blade
</x-card>
```

- [ ] **Step 2: Wrap the filter bar and table in `document-index.blade.php`**

Modify `resources/views/livewire/document-index.blade.php` — replace:

```blade
    <div class="bg-white shadow rounded-md p-4 mb-4 flex flex-wrap gap-3 items-end">
```

with:

```blade
    <x-card class="mb-4 flex flex-wrap gap-3 items-end">
```

replace:

```blade
    </div>

    <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
```

with:

```blade
    </x-card>

    <x-card class="p-0 overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
```

and replace the final two lines of the file:

```blade
    </table>
</div>
```

with:

```blade
    </table>
    </x-card>
</div>
```

- [ ] **Step 3: Wrap both sections in `ddv04-report.blade.php`**

Modify `resources/views/livewire/reports/ddv04-report.blade.php` — replace:

```blade
    <div class="bg-white shadow rounded-md p-4 mb-4 flex flex-wrap gap-4 items-end">
```

with:

```blade
    <x-card class="mb-4 flex flex-wrap gap-4 items-end">
```

replace:

```blade
        </div>
    </div>

    <div class="bg-white shadow rounded-md p-4 mb-4">
        <h2 class="font-semibold text-gray-700 mb-3">Промет на добра и услуги</h2>
```

with:

```blade
        </div>
    </x-card>

    <x-card class="mb-4">
        <h2 class="font-semibold text-gray-700 mb-3">Промет на добра и услуги</h2>
```

replace:

```blade
        </table>
    </div>

    <div class="bg-white shadow rounded-md p-4 mb-4">
        <h2 class="font-semibold text-gray-700 mb-3">Влезни исполнувања со право на одбивка</h2>
```

with:

```blade
        </table>
    </x-card>

    <x-card class="mb-4">
        <h2 class="font-semibold text-gray-700 mb-3">Влезни исполнувања со право на одбивка</h2>
```

and replace the final two lines of the file:

```blade
    </div>
</div>
```

with:

```blade
    </x-card>
</div>
```

- [ ] **Step 4: Wrap the "Add partner" form and the table in `partner-index.blade.php`**

Modify `resources/views/livewire/partner-index.blade.php` — replace:

```blade
    @can('create', \App\Models\Partner::class)
        <div class="bg-white shadow rounded-md p-4 mb-6">
```

with:

```blade
    @can('create', \App\Models\Partner::class)
        <x-card class="mb-6">
```

replace:

```blade
                <x-primary-button type="submit">Add</x-primary-button>
            </form>
        </div>
    @endcan

    <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
```

with:

```blade
                <x-primary-button type="submit">Add</x-primary-button>
            </form>
        </x-card>
    @endcan

    <x-card class="p-0 overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
```

and replace the final two lines of the file:

```blade
    </table>
</div>
```

with:

```blade
    </table>
    </x-card>
</div>
```

- [ ] **Step 5: Wrap the details panel in `partner-show.blade.php`**

Modify `resources/views/livewire/partner-show.blade.php` — replace:

```blade
    <div class="bg-white shadow rounded-md p-4 mb-4 text-sm space-y-1">
```

with:

```blade
    <x-card class="mb-4 text-sm space-y-1">
```

and replace:

```blade
        <div>Address: {{ $partner->address ?? '—' }}</div>
    </div>

    <livewire:document-manager :documentable="$partner" />
```

with:

```blade
        <div>Address: {{ $partner->address ?? '—' }}</div>
    </x-card>

    <livewire:document-manager :documentable="$partner" />
```

- [ ] **Step 6: Wrap the "Add company" form in `company-index.blade.php`**

Modify `resources/views/livewire/company-index.blade.php` — replace:

```blade
    @can('create', \App\Models\Company::class)
        <div class="bg-white shadow rounded-md p-4 mb-6">
```

with:

```blade
    @can('create', \App\Models\Company::class)
        <x-card class="mb-6">
```

and replace:

```blade
                <x-primary-button type="submit">Add company</x-primary-button>
            </form>
        </div>
    @endcan
```

with:

```blade
                <x-primary-button type="submit">Add company</x-primary-button>
            </form>
        </x-card>
    @endcan
```

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: full suite PASS. Also run `--filter=DocumentManagerTest`, `--filter=DocumentIndexTest`, `--filter=Ddv04ReportTest`, `--filter=PartnerShowTest`, `--filter=CompanyIndexTest` specifically.

- [ ] **Step 8: Verify no stray old wrapper markup remains**

Run: `grep -rln "bg-white shadow rounded" resources/views/livewire/document-manager.blade.php resources/views/livewire/document-index.blade.php resources/views/livewire/reports/ resources/views/livewire/partner-index.blade.php resources/views/livewire/partner-show.blade.php resources/views/livewire/company-index.blade.php`
Expected: no output (empty).

- [ ] **Step 9: Commit**

```bash
git add resources/views/livewire/document-manager.blade.php resources/views/livewire/document-index.blade.php resources/views/livewire/reports/ resources/views/livewire/partner-index.blade.php resources/views/livewire/partner-show.blade.php resources/views/livewire/company-index.blade.php
git commit -m "Apply card component to Documents, Reports, Partners, and Companies views"
```

---

### Task 8: Dashboard, profile, and PDF invoice brand colors

**Files:**
- Modify: `resources/views/dashboard.blade.php`
- Modify: `resources/views/profile.blade.php`
- Modify: `resources/views/pdf/sales-invoice.blade.php`
- Modify: `resources/views/livewire/pages/auth/login.blade.php`
- Modify: `resources/views/livewire/pages/auth/register.blade.php`
- Modify: `resources/views/livewire/pages/auth/verify-email.blade.php`
- Modify: `resources/views/livewire/profile/update-profile-information-form.blade.php`

**Interfaces:**
- Consumes: `<x-card>` (Task 2). The PDF template does not use Blade components (dompdf renders it standalone with its own embedded `<style>` block, not through the Vite/Tailwind pipeline), so it gets plain inline color changes instead.

- [ ] **Step 1: Wrap the dashboard panel in `dashboard.blade.php`**

Modify `resources/views/dashboard.blade.php` (full file):

```blade
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <x-card>
                <div class="text-gray-900">
                    {{ __("You're logged in!") }}
                </div>
            </x-card>
        </div>
    </div>
</x-app-layout>
```

- [ ] **Step 2: Wrap the three panels in `profile.blade.php`**

Modify `resources/views/profile.blade.php` (full file):

```blade
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <x-card class="sm:p-8">
                <div class="max-w-xl">
                    <livewire:profile.update-profile-information-form />
                </div>
            </x-card>

            <x-card class="sm:p-8">
                <div class="max-w-xl">
                    <livewire:profile.update-password-form />
                </div>
            </x-card>

            <x-card class="sm:p-8">
                <div class="max-w-xl">
                    <livewire:profile.delete-user-form />
                </div>
            </x-card>
        </div>
    </div>
</x-app-layout>
```

- [ ] **Step 3: Brand-color the PDF invoice template**

Modify `resources/views/pdf/sales-invoice.blade.php` — replace the `<style>` block:

```blade
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        .totals { text-align: right; margin-top: 12px; }
        .header { display: flex; justify-content: space-between; margin-bottom: 16px; }
    </style>
```

with:

```blade
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; color: #ff6600; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th { padding: 4px 6px; text-align: left; background: #f3f4f6; }
        td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        .totals { text-align: right; margin-top: 12px; }
        .totals strong { color: #ff6600; }
        .header { display: flex; justify-content: space-between; margin-bottom: 16px; }
    </style>
```

(dompdf supports plain CSS but not Tailwind's utility classes or arbitrary-value syntax, so brand color is applied here as a literal hex value, matching the app's `brand.DEFAULT` token — not through a shared component.)

- [ ] **Step 4: Replace the last few stray `indigo` focus-ring classes**

These four files use raw `focus:ring-indigo-500`/`text-indigo-600` classes directly (not through any shared component Task 1 already restyled), left over from Breeze's original scaffold.

Modify `resources/views/livewire/pages/auth/login.blade.php` — replace both occurrences of `indigo-500`/`indigo-600` on lines 54 and 61 (`focus:ring-indigo-500` → `focus:ring-brand`, `text-indigo-600` → `text-brand`):

```blade
                <input wire:model="form.remember" id="remember" type="checkbox" class="rounded border-gray-300 text-brand shadow-sm focus:ring-brand" name="remember">
```

```blade
                <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand" href="{{ route('password.request') }}" wire:navigate>
```

Modify `resources/views/livewire/pages/auth/register.blade.php` — replace line 79's `focus:ring-indigo-500` with `focus:ring-brand`:

```blade
            <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand" href="{{ route('login') }}" wire:navigate>
```

Modify `resources/views/livewire/pages/auth/verify-email.blade.php` — replace line 54's `focus:ring-indigo-500` with `focus:ring-brand`:

```blade
        <button wire:click="logout" type="submit" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand">
```

Modify `resources/views/livewire/profile/update-profile-information-form.blade.php` — replace line 93's `focus:ring-indigo-500` with `focus:ring-brand`:

```blade
                        <button wire:click.prevent="sendVerification" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand">
```

- [ ] **Step 5: Run the full test suite**

Run: `php artisan test`
Expected: full suite PASS, including `--filter=SalesInvoicePdfTest` (or whatever the PDF-download test is named) to confirm the template still renders without error, and the auth tests (`--filter=AuthenticationTest` or similar) to confirm login/register/verify-email still function.

- [ ] **Step 6: Verify no stray indigo remains anywhere in the app**

Run: `grep -rln "indigo" resources/views/`
Expected: no output (empty) — every remaining `indigo-*` class in the whole view tree has now been replaced with `brand`/gray/semantic-status equivalents.

- [ ] **Step 7: Manual browser verification**

Since this is the final task of a purely visual redesign, do a real browser pass before considering the whole plan done:
1. Start the dev server (`npm run dev` in one terminal, `php artisan serve` in another, or whatever this project's existing dev workflow is).
2. Log in, confirm the sidebar renders with the dark-gray background, orange active-state highlighting, and Manrope font.
3. Click into a company and confirm all 5 module links appear and each correctly navigates to that company's own screens.
4. Visit an index page with a status column (e.g. Sales Invoices) and confirm badges render with the right colors (draft = amber, confirmed = green).
5. Confirm forms/buttons/cards show the new rounded, brand-orange styling.
6. Resize to a mobile width and confirm the sidebar still collapses sensibly (or note if a follow-up mobile-specific fix is needed — this plan doesn't include a dedicated mobile-sidebar-collapse task since the design deferred that detail to "matches the existing responsive pattern," which may need a small follow-up once seen in a real narrow viewport).

- [ ] **Step 8: Commit**

```bash
git add resources/views/dashboard.blade.php resources/views/profile.blade.php resources/views/pdf/sales-invoice.blade.php resources/views/livewire/pages/auth/login.blade.php resources/views/livewire/pages/auth/register.blade.php resources/views/livewire/pages/auth/verify-email.blade.php resources/views/livewire/profile/update-profile-information-form.blade.php
git commit -m "Apply card component and brand colors to dashboard, profile, auth pages, and PDF invoice"
```
