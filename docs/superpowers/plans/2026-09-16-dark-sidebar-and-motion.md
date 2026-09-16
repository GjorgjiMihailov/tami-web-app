# Темна странична лента и меко движење — план за изведба

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Страничната лента станува темна, содржината добива контраст, а менито и копчињата се движат меко наместо да скокаат.

**Architecture:** Сè е боја и разметка. Единствената промена во логиката е што отворањето на групите во менито се преместува од серверот во прелистувачот (Alpine `x-collapse`), додека почетно отворената група и понатаму ја одредува серверот според тековниот екран.

**Tech Stack:** Laravel 12/13, Livewire 3 (го носи Alpine со приклучокот `collapse` вграден — не се додава ништо), Tailwind преку Vite, PHPUnit.

**Спец:** `docs/superpowers/specs/2026-09-16-dark-sidebar-and-motion-design.md`

## Global Constraints

- Сиот видлив текст е на **строг македонски** (кирилица).
- Не се менуваат податоци, рути, имиња на рути, правила за пристап ниту пресметки.
- Tailwind е JIT: **секоја класа мора да стои како цела низа во изворот**, никогаш составена во време на извршување.
- По секоја измена на Blade или на `tailwind.config.js` се пушта `npm run build`. **`public/build` НЕ се комитира** — во `.gitignore` е, деплојот гради на серверот.
- Тестовите се PHPUnit класи (`test_*`), пораки на македонски, стил на `tests/Feature/CompanyModulesTest.php`. Улогата мора да постои пред да се додели: `Role::findOrCreate('admin')` во `setUp()`.
- Тестовите се пуштаат со `php artisan test --filter=...` — **никогаш** `composer test` (се задавува на 300s лимитот и враќа `exit 0` иако паднал).
- Целата серија (~9 мин) се пушта **еднаш**, во последната задача.
- Комит по задача, порака на македонски, со `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- Готча од претходната гранка: **Livewire сече текст што има `@if` меѓу две `{{ }}` ехоа** со HTML коментари, па `assertSee` паѓа. Ако треба услов среде текст, тој се пресметува во компонентата.

## Преглед на фајлови

**Се менуваат:**
- `tailwind.config.js` — вредноста на `canvas`, нови имиња за тоновите на лентата.
- `resources/css/app.css` — преоди и `prefers-reduced-motion`.
- `resources/views/livewire/layout/sidebar.blade.php` — темни бои, лизгање на групите.
- `app/Livewire/Layout/Sidebar.php` — се вади `toggleGroup()`.
- `resources/views/livewire/layout/navigation.blade.php` — посилна долна рамка, преоди на копчињата.
- `resources/views/livewire/company-dashboard.blade.php` — преод на плочките.
- 24 места со `border-gray-100` / `border-gray-200` низ `resources/views`.
- `tests/Feature/SidebarTest.php` — двата теста што висат на `toggleGroup()`.

**Се создава:**
- `tests/Feature/SidebarLookTest.php`

---

### Task 1: Контраст во содржината

**Files:**
- Modify: `tailwind.config.js`
- Modify: сите погледи со `border-gray-100` / `border-gray-200` (24 места)
- Create: `tests/Feature/SidebarLookTest.php`

**Interfaces:**
- Produces: `canvas` = `#F5F1EA`; класата `border-sand` како единствена рамка на картички и табели.

- [ ] **Step 1: Write the failing test**

`tests/Feature/SidebarLookTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Изгледот на рамката: подлога со тежина, темна странична лента и групи што се
 * отвораат во прелистувачот. Се проверува преку класите во HTML — тоа е она што
 * стварно стигнува до прелистувачот.
 */
class SidebarLookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');
    }

    private function dashboard(): string
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $this->actingAs($admin)
            ->get(route('companies.dashboard', $company))
            ->getContent();
    }

    public function test_no_screen_uses_the_faint_gray_borders_any_more(): void
    {
        $html = $this->dashboard();

        $this->assertStringNotContainsString('border-gray-100', $html, 'Рамките одат на sand.');
        $this->assertStringNotContainsString('border-gray-200', $html, 'Рамките одат на sand.');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SidebarLookTest`
Expected: FAIL — таблата сè уште носи `border-gray-100`.

- [ ] **Step 3: Deepen the canvas and name the sidebar tones**

Во `tailwind.config.js`, во `colors`:

```js
                canvas: {
                    DEFAULT: '#F5F1EA',
                },
                // Тоновите на темната странична лента. Именувани затоа што се
                // користат на десетина места во sidebar.blade.php, а Tailwind JIT
                // бара цела низа — `bg-rail` е читливо, `bg-[#1C1A17]` не е.
                rail: {
                    DEFAULT: '#1C1A17',
                    soft: '#2A2724',
                    line: '#3A352E',
                    text: '#C9C2B8',
                    muted: '#8A8177',
                },
```

`ink`, `sand`, `paper`, `brand` и останатите остануваат непроменети.

- [ ] **Step 4: Replace the faint borders**

Во `resources/views`, секое `border-gray-100` и `border-gray-200` станува `border-sand`. Има 24 такви места; ниедно друго нешто во тие класи не се менува.

Пронаоѓање: `grep -rn "border-gray-100\|border-gray-200" resources/views`

**Внимание:** ова не важи за `resources/views/livewire/layout/sidebar.blade.php` — таа рамка станува темна во Task 2 и таму рамките одат на `border-rail-line`. Ако Task 1 ја допре, Task 2 ќе ја менува двапати.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=SidebarLookTest`
Expected: PASS.

- [ ] **Step 6: Rebuild**

Run: `npm run build`
Expected: завршува без грешка.

- [ ] **Step 7: Commit**

```bash
git add tailwind.config.js resources/views tests/Feature/SidebarLookTest.php
git commit -m "feat: потемна подлога и повидливи рамки"
```

---

### Task 2: Темна странична лента

**Files:**
- Modify: `resources/views/livewire/layout/sidebar.blade.php`
- Modify: `tests/Feature/SidebarLookTest.php`

**Interfaces:**
- Consumes: боите `rail.*` од Task 1.
- Produces: сајдбар на темна подлога; ништо во однесувањето не се менува.

- [ ] **Step 1: Write the failing test**

Додај во `tests/Feature/SidebarLookTest.php`:

```php
    public function test_the_sidebar_is_dark(): void
    {
        $html = $this->dashboard();

        $this->assertStringContainsString('bg-rail', $html, 'Страничната лента е темна.');
        $this->assertStringContainsString('text-rail-text', $html);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SidebarLookTest`
Expected: FAIL — нема `bg-rail` во HTML.

- [ ] **Step 3: Recolour the sidebar**

Во `resources/views/livewire/layout/sidebar.blade.php`:

1. Надворешниот сад: `bg-white border-r border-gray-100 text-gray-700` → `bg-rail border-r border-rail-line text-rail-text`.
2. Главата (редот со дворедниот наслов): `border-b border-gray-100` → `border-b border-rail-line`; вториот ред `text-gray-400` → `text-rail-muted`. Првиот ред останува со `$this->app()->accent()`.
3. Копчето за затворање на фиоката: `text-gray-400 hover:text-gray-600 hover:bg-gray-100` → `text-rail-muted hover:text-rail-text hover:bg-rail-soft`.
4. Трите портални врски (Почетна, Фирми, 743 обрасци) и врската Документи: неактивна состојба `text-gray-600 hover:bg-orange-50` → `text-rail-text hover:bg-rail-soft`; активната останува `bg-brand text-white`.
5. Насловите на групите: `text-gray-600 hover:bg-orange-50` → `text-rail-muted hover:bg-rail-soft`.
6. Ставките во групите: неактивна `text-gray-500 hover:text-gray-800` → `text-rail-text hover:text-white`; „наскоро“ `text-gray-400 hover:text-gray-600` → `text-rail-muted hover:text-rail-text`; активната останува `text-brand font-medium`.
7. Двата избирача (Фирма, Година) и нивните натписи: натписот `text-gray-500` → `text-rail-muted`; самиот `<select>`: `border-gray-200 text-gray-700` → `bg-rail-soft border-rail-line text-rail-text`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SidebarLookTest`
Expected: PASS.

- [ ] **Step 5: Check the neighbours**

Run: `php artisan test --filter="Sidebar|Navigation|AppShell|AppSwitcher"`
Expected: PASS. `SidebarTest` бара одредени класи на активната ставка (`bg-brand`, `text-brand`) — тие намерно не се менуваат. Ако некој тест бара стара сива класа, се менува очекувањето во тестот.

- [ ] **Step 6: Rebuild and commit**

```bash
npm run build
git add resources/views/livewire/layout/sidebar.blade.php tests/Feature/SidebarLookTest.php
git commit -m "feat: темна странична лента"
```

---

### Task 3: Групите се отвораат меко

**Files:**
- Modify: `resources/views/livewire/layout/sidebar.blade.php`
- Modify: `app/Livewire/Layout/Sidebar.php`
- Modify: `tests/Feature/SidebarTest.php`
- Modify: `tests/Feature/SidebarLookTest.php`

**Interfaces:**
- Consumes: `$expandedGroup` (останува, како ПОЧЕТНА состојба), `$menu`.
- Produces: отворањето живее во Alpine; `Sidebar::toggleGroup()` веќе не постои.

**Зошто:** денес секој клик на „+“ е цело барање до серверот. Каква и да е анимацијата врз тоа, ќе се гледа како трепкање.

- [ ] **Step 1: Write the failing test**

Додај во `tests/Feature/SidebarLookTest.php`:

```php
    public function test_a_group_opens_without_going_to_the_server(): void
    {
        $html = $this->dashboard();

        $this->assertStringNotContainsString('wire:click="toggleGroup', $html, 'Отворањето е во прелистувачот.');
        $this->assertStringContainsString('x-collapse', $html, 'Групата се отвора со лизгање.');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SidebarLookTest`
Expected: FAIL — сè уште стои `wire:click="toggleGroup`.

- [ ] **Step 3: Move the toggle into the browser**

Во `sidebar.blade.php`, блокот `@foreach ($menu as $group)` станува:

```blade
                @foreach ($menu as $group)
                    <div x-data="{ open: @js($expandedGroup === $group['key']) }">
                        <button type="button" @click="open = ! open"
                                class="w-full text-left flex items-center justify-between px-4 py-2 text-sm font-medium rounded-lg mx-3 text-rail-muted hover:bg-rail-soft transition-colors duration-150"
                                style="width: calc(100% - 1.5rem);">
                            <span>{{ $group['label'] }}</span>
                            <span x-text="open ? '−' : '+'"></span>
                        </button>
                        <div x-show="open" x-collapse class="pl-6">
                            @foreach ($group['items'] as $item)
                                <a href="{{ $item['url'] }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-1.5 text-sm {{ $this->isActive($item['pattern']) ? 'text-brand font-medium' : ($item['soon'] ? 'text-rail-muted hover:text-rail-text' : 'text-rail-text hover:text-white') }}">
                                    <span>{{ $item['label'] }}</span>
                                    @if ($item['soon'])
                                        <span class="text-[10px] uppercase tracking-wide text-rail-muted">наскоро</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
```

`x-collapse` доаѓа со Alpine што Livewire 3 сам го вклучува — не се додава ниту пакет ниту скрипта. Провери дека лизга во прелистувач; ако директивата не постои, застани и прашај наместо да додаваш нова зависност.

Знакот „−/+“ сега го пишува Alpine (`x-text`), не Blade — инаку би останал заглавен на почетната вредност.

- [ ] **Step 4: Remove the server method**

Во `app/Livewire/Layout/Sidebar.php` избриши го целиот метод `toggleGroup()`. `$expandedGroup` и `groupMatchingCurrentRoute()` ОСТАНУВААТ — тие ја одредуваат групата што е отворена при влегување.

- [ ] **Step 5: Rework the two tests that hang on the method**

Во `tests/Feature/SidebarTest.php`:

`test_clicking_a_different_group_collapses_the_previous_one` веќе не опишува нешто што серверот го прави. Замени го со тест дека почетно отворената група е онаа на тековниот екран:

```php
    public function test_the_group_of_the_current_screen_starts_open(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        Livewire::test(Sidebar::class, ['company' => $company])
            ->assertSet('expandedGroup', null);

        $this->get(route('inventory.items.index', $company))
            ->assertSee('x-data="{ open: true }"', false);
    }
```

`test_toggling_a_group_via_livewire_still_shows_the_company_after_the_request` е регресивен тест за вистински дефект: фирмата се губеше преку `/livewire/update`, па линковите излегуваа скршени. Дефектот е сè уште можен — изборот на **работна година** и понатаму оди преку тоа барање. Затоа тестот НЕ се брише, туку се пренасочува: истото барање, но наместо `calls` со `toggleGroup` праќа `updates` со `workingYear`:

```php
                    'updates' => ['workingYear' => (string) now()->year],
                    'calls' => [],
```

Името и коментарот се дотеруваат да зборуваат за работната година. Тврдењата на крајот остануваат исти — сајдбарот и понатаму мора да ги содржи адресите на фирмата.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter="SidebarLookTest|SidebarTest"`
Expected: PASS.

- [ ] **Step 7: Rebuild and commit**

```bash
npm run build
git add resources/views/livewire/layout/sidebar.blade.php app/Livewire/Layout/Sidebar.php tests/
git commit -m "feat: групите во менито се отвораат со лизгање"
```

---

### Task 4: Преоди на копчињата и почит кон исклучени анимации

**Files:**
- Modify: `resources/css/app.css`
- Modify: `resources/views/livewire/layout/navigation.blade.php`
- Modify: `resources/views/livewire/company-dashboard.blade.php`
- Modify: `tests/Feature/SidebarLookTest.php`

**Interfaces:**
- Produces: класата `.press` за лесно стискање при клик; правило за `prefers-reduced-motion`.

- [ ] **Step 1: Write the failing test**

Додај во `tests/Feature/SidebarLookTest.php`:

```php
    public function test_the_app_tiles_react_to_the_pointer(): void
    {
        $html = $this->dashboard();

        $this->assertStringContainsString('press', $html, 'Плочките реагираат на клик.');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SidebarLookTest`
Expected: FAIL.

- [ ] **Step 3: Add the motion rules**

Во `resources/css/app.css`, на крајот:

```css
/*
 * Лесно стискање при клик. Пишано како обична класа, а не како Tailwind
 * помошни класи, за да стои на едно место и да се гаси со едно правило подолу.
 */
.press {
    transition: transform 120ms ease-out, background-color 150ms ease-out, border-color 150ms ease-out;
}

.press:active {
    transform: scale(0.98);
}

/*
 * Кој исклучил анимации во системот, ги добива екраните без нив. Ова важи и за
 * фиоката и за лизгањето на групите во менито.
 */
@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
        scroll-behavior: auto !important;
    }
}
```

- [ ] **Step 4: Use it where things are clicked**

1. `navigation.blade.php`: долната рамка на лентата `border-gray-100` → `border-sand`; копчето АПЛИКАЦИИ и врските во панелот добиваат `press` покрај постојните класи.
2. `company-dashboard.blade.php`: секоја плочка со апликација добива `press`.
3. `sidebar.blade.php`: копчињата на групите веќе добија `transition-colors duration-150` во Task 3; додај `press` и на врската Документи и на трите портални врски.

Не се менува ниту една боја во овој чекор — само се додава класа.

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter="SidebarLookTest|Navigation|CompanyDashboard"`
Expected: PASS.

- [ ] **Step 6: Rebuild and commit**

```bash
npm run build
git add resources/css/app.css resources/views tests/Feature/SidebarLookTest.php
git commit -m "feat: копчињата реагираат меко на клик"
```

---

### Task 5: Цела серија

**Files:**
- Ништо ново; поправки само ако серијата најде.

- [ ] **Step 1: Run the whole suite**

Run: `php artisan test`
Expected: PASS, со еден прескокнат тест (`MarketingScreensTest`, се пушта рачно со `MARKETING_SCREENS=1`). Точната основа земи ја од последната зелена серија на `main` пред оваа гранка — бројот расте со `SidebarLookTest`.

- [ ] **Step 2: Fix what it finds**

Поправај само она што го скрши оваа гранка. Најверојатни: тестови што бараат стара сива класа во сајдбарот, или `wire:click="toggleGroup`. Секој друг пад се пријавува, не се поправа.

- [ ] **Step 3: Final rebuild**

Run: `npm run build`

- [ ] **Step 4: Commit if anything changed**

```bash
git add -A
git commit -m "test: дотерување по промената на изгледот"
```

---

## Проверка пред спојување

- [ ] Целата серија е зелена.
- [ ] `npm run build` е пуштен последен по сите измени на Blade.
- [ ] Во прелистувач: сајдбарот е темен на сите три апликации и на порталот; групите лизгаат; активната ставка се гледа; избирачите за фирма и година се читливи на темна подлога.
- [ ] Мобилната фиока сè уште се отвора и затвора (таа е посебен CSS во `app.css`, не смее да настрада).
- [ ] Сопственикот ги видел екраните и се согласил со бојата.
