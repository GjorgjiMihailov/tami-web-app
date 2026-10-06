# Шеми за книжење, дел 4: личен предложен комплет на сметководителот — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сметководител (или админ) да го дотера комплетот шеми во вистинска фирма (со „Пробај“), да го зачува како **свој предлог**, а секоја нова фирма што ја создава да го добие тој комплет. „Врати на предложено“ во фирма го враќа неговиот предлог.

**Architecture:** Нова табела `user_posting_schemes` (по корисник и вид документ; дефиниција како JSON во истиот формат како `DefaultPostingSchemes::definition`, конта по ШИФРА). Услугата `PostingSchemeSets` го зачувува/чита/брише предлогот и го применува при создавање фирма (`CompanyObserver`). `PostingSchemeEditor::resetToDefault` зема и корисник. Екранот за фирма добива „Зачувај и постави како мој предлог“. Страницата „Шеми за книжење“ на порталот (сега „наскоро“) станува список на личните предлози со „Врати на стандарден“.

**Одлука на сопственикот (2026-10-06):** предлогот е **по сметководител** (не еден за цела канцеларија). Ако фирмата има двајца сметководители, нова фирма го добива предлогот на оној што ја создава; „Врати на предложено“ го враќа предлогот на оној што го притиска. Без личен предлог → стандардното од кодот.

**Tech Stack:** Laravel 13, PHP 8.3, Livewire, SQLite (тест) / MySQL.

Спецификација: `docs/superpowers/specs/2026-10-06-posting-schemes-design.md` (разделот „Канцелариско ниво“) + логови part1–3. Дел 1–3 се во `main` (c3a69d6).

## Global Constraints

- Македонски текстови за корисник (строг македонски); коментари — македонски.
- Предлогот се применува САМО при создавање нова фирма. Постојните фирми НЕ се допираат (шемите не се враќаат автоматски).
- Конто во предлогот е по шифра. Ако при создавање фирма некое конто од предлогот го нема во планот на фирмата (аналитичко), за тој вид документ остануваат стандардните (се создаваат лено при прво книжење) — нова фирма никогаш не се блокира.
- Предлогот се зема од шема што веќе е СОЧУВАНА низ проверките (аналитичко, активно, балансира) — нема втора проверка.
- Корисник со предлог е само админ или сметководител.
- Имиња на Livewire методи: не користи резервирани (`upload`, `get`, `set`, `call`, …) — во проектот има гард-тест.
- Тестови: `php artisan test <датотека>` само за датотеката што ја менуваш. **Целата серија се пушта еднаш на крај и прво се прашува корисникот.** Не користи `python`; не користи `sed` за додавање `use` — користи Edit. Не слепувај код од овој план преку ограда ``` — земи точни редови и провери `php -l`.
- Кон секој commit: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Гранка `posting-schemes-part4`, не `main`.
- MySQL: имиња на индекси ≤ 64 знаци.

## Мапа на датотеки

Нови:
- `database/migrations/2026_10_08_100000_create_user_posting_schemes_table.php`
- `app/Models/UserPostingScheme.php`
- `app/Services/Posting/PostingSchemeSets.php`
- `app/Livewire/OfficePostingSchemes.php`, `resources/views/livewire/office-posting-schemes.blade.php`
- `tests/Feature/Posting/{PostingSchemeSetsTest,OfficePostingSchemesTest}.php`

Менувани: `app/Observers/CompanyObserver.php`, `app/Services/Posting/PostingSchemeEditor.php` (`resetToDefault`), `app/Livewire/Accounting/PostingSchemeEdit.php` + `resources/views/livewire/accounting/posting-scheme-edit.blade.php`, `routes/web.php`, `resources/views/livewire/layout/sidebar.blade.php`, `app/Livewire/Layout/Sidebar.php`, `tests/Feature/SidebarTest.php`, `tests/Feature/Posting/PostingSchemeScreenTest.php`.
Бришат: `app/Livewire/OfficeComingSoon.php`, `resources/views/livewire/office-coming-soon.blade.php`.

---

### Task 1: Табела, модел и услуга `PostingSchemeSets`

**Files:**
- Create: `database/migrations/2026_10_08_100000_create_user_posting_schemes_table.php`, `app/Models/UserPostingScheme.php`, `app/Services/Posting/PostingSchemeSets.php`
- Test: `tests/Feature/Posting/PostingSchemeSetsTest.php`

**Interfaces:**
- Produces: `UserPostingScheme` (`user_id, doc_type (PostingDocType), name, definition (array)`; `user()`).
- Produces (`PostingSchemeSets`, статични):
  - `remember(User $user, PostingScheme $scheme): UserPostingScheme` — ја зачувува ШЕМАТА на фирмата како предлог на корисникот (заменува постоечки за тој вид).
  - `has(User $user, PostingDocType $type): bool`.
  - `forget(User $user, PostingDocType $type): void`.
  - `definitionFor(?User $user, PostingDocType $type): array` — предлогот на корисникот, или `DefaultPostingSchemes::definition($type)`.
  - `seedCompany(Company $company, ?User $user): void` — за секој вид за кој корисникот има предлог создава шема на фирмата од него (во трансакција); ако некое конто го нема — тој вид се прескокнува (останува лено стандардно).
- Дефиниција (ист формат како `DefaultPostingSchemes`): `['name' => string, 'rows' => list<['mode','account'?,'matrix'?,'side','formula','partner','description'?,'condition'?]>, 'matrix' => list<['key','kind','group','account']>]`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Posting/PostingSchemeSetsTest.php`:

```php
<?php

namespace Tests\Feature\Posting;

use App\Models\Company;
use App\Models\PostingScheme;
use App\Models\User;
use App\Services\Posting\DefaultPostingSchemes;
use App\Services\Posting\PostingSchemeEditor;
use App\Services\Posting\PostingSchemes;
use App\Services\Posting\PostingSchemeSets;
use App\Support\Posting\PostingDocType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PostingSchemeSetsTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        $this->accountant = User::factory()->create();
        $this->accountant->assignRole('accountant');
    }

    /** Шема на фирма со сменет опис на првиот ред — за да се познае дека е „мојата“. */
    private function changedScheme(Company $company, PostingDocType $type = PostingDocType::SALES_INVOICE, string $description = 'Мој опис {фактура}'): PostingScheme
    {
        $scheme = PostingSchemes::for($company, $type);
        $draft = app(PostingSchemeEditor::class)->draftOf($scheme);
        $draft['rows'][0]['description'] = $description;
        $this->assertSame([], app(PostingSchemeEditor::class)->save($scheme, $draft['rows'], $draft['matrix']));

        return $scheme->fresh();
    }

    public function test_without_a_personal_set_the_standard_definition_is_used(): void
    {
        $this->assertFalse(PostingSchemeSets::has($this->accountant, PostingDocType::SALES_INVOICE));
        $this->assertSame(
            DefaultPostingSchemes::definition(PostingDocType::SALES_INVOICE),
            PostingSchemeSets::definitionFor($this->accountant, PostingDocType::SALES_INVOICE)
        );
        $this->assertSame(
            DefaultPostingSchemes::definition(PostingDocType::SALES_INVOICE),
            PostingSchemeSets::definitionFor(null, PostingDocType::SALES_INVOICE)
        );
    }

    public function test_a_scheme_can_be_remembered_and_read_back_in_the_standard_format(): void
    {
        $company = Company::factory()->create();
        $scheme = $this->changedScheme($company);

        PostingSchemeSets::remember($this->accountant, $scheme);

        $this->assertTrue(PostingSchemeSets::has($this->accountant, PostingDocType::SALES_INVOICE));
        $definition = PostingSchemeSets::definitionFor($this->accountant, PostingDocType::SALES_INVOICE);
        $this->assertSame('Мој опис {фактура}', $definition['rows'][0]['description']);
        $this->assertSame('1200', $definition['rows'][0]['account']);
        $this->assertSame('ВКУПНО', $definition['rows'][0]['formula']);
        $this->assertTrue($definition['rows'][0]['partner']);
        $this->assertCount(5, $definition['rows']);
        $this->assertCount(12, $definition['matrix']);
        $this->assertSame('74000', collect($definition['matrix'])->firstWhere(fn ($m) => $m['key'] === 'revenue' && $m['kind'] === 'service' && $m['group'] === 'general')['account']);
    }

    public function test_remembering_again_replaces_and_forget_removes(): void
    {
        $company = Company::factory()->create();
        PostingSchemeSets::remember($this->accountant, $this->changedScheme($company, description: 'Прв'));
        PostingSchemeSets::remember($this->accountant, $this->changedScheme($company, description: 'Втор'));

        $this->assertSame('Втор', PostingSchemeSets::definitionFor($this->accountant, PostingDocType::SALES_INVOICE)['rows'][0]['description']);
        $this->assertSame(1, $this->accountant->hasMany(\App\Models\UserPostingScheme::class)->count());

        PostingSchemeSets::forget($this->accountant, PostingDocType::SALES_INVOICE);

        $this->assertFalse(PostingSchemeSets::has($this->accountant, PostingDocType::SALES_INVOICE));
    }

    public function test_one_accountants_set_does_not_leak_to_another(): void
    {
        $other = User::factory()->create();
        $other->assignRole('accountant');
        PostingSchemeSets::remember($this->accountant, $this->changedScheme(Company::factory()->create()));

        $this->assertFalse(PostingSchemeSets::has($other, PostingDocType::SALES_INVOICE));
    }

    public function test_seeding_a_company_creates_only_the_types_the_user_has_a_set_for(): void
    {
        PostingSchemeSets::remember($this->accountant, $this->changedScheme(Company::factory()->create()));
        $fresh = Company::factory()->create();

        PostingSchemeSets::seedCompany($fresh, $this->accountant);

        $this->assertSame(1, PostingScheme::where('company_id', $fresh->id)->count());
        $scheme = PostingScheme::where('company_id', $fresh->id)->where('doc_type', 'sales_invoice')->firstOrFail();
        $this->assertSame('Мој опис {фактура}', $scheme->rows->first()->description);
        $this->assertSame(12, $scheme->matrixAccounts()->count());
    }

    public function test_seeding_without_a_user_or_a_set_creates_nothing(): void
    {
        $company = Company::factory()->create();

        PostingSchemeSets::seedCompany($company, null);
        PostingSchemeSets::seedCompany($company, $this->accountant);

        $this->assertSame(0, PostingScheme::where('company_id', $company->id)->count());
    }

    public function test_a_missing_account_leaves_that_type_to_the_standard_lazy_scheme(): void
    {
        $company = Company::factory()->create();
        PostingSchemeSets::remember($this->accountant, $this->changedScheme($company));
        $fresh = Company::factory()->create();
        \App\Models\Account::where('company_id', $fresh->id)->where('code', '74000')->delete();

        PostingSchemeSets::seedCompany($fresh, $this->accountant);

        $this->assertSame(0, PostingScheme::where('company_id', $fresh->id)->count());
    }
}
```

> Тестот `test_a_missing_account…` брише конто на нова фирма (нема шеми што го држат); ако бришењето е блокирано од странски клуч, користи `->update(['is_analytical' => false])` наместо `delete()`.

- [ ] **Step 2: Run — FAIL.** `php artisan test tests/Feature/Posting/PostingSchemeSetsTest.php`

- [ ] **Step 3: Implement**

`database/migrations/2026_10_08_100000_create_user_posting_schemes_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_posting_schemes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('doc_type', 24);
            $table->string('name');
            $table->json('definition');
            $table->timestamps();

            $table->unique(['user_id', 'doc_type'], 'upsch_user_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_posting_schemes');
    }
};
```

`app/Models/UserPostingScheme.php`:

```php
<?php

namespace App\Models;

use App\Support\Posting\PostingDocType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Личен предложен комплет шеми на сметководител: еден запис по вид документ, конта по шифра. */
class UserPostingScheme extends Model
{
    protected $fillable = ['user_id', 'doc_type', 'name', 'definition'];

    protected function casts(): array
    {
        return ['doc_type' => PostingDocType::class, 'definition' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

`app/Services/Posting/PostingSchemeSets.php`:

```php
<?php

namespace App\Services\Posting;

use App\Models\Company;
use App\Models\PostingScheme;
use App\Models\User;
use App\Models\UserPostingScheme;
use App\Support\Posting\PostingDocType;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Личен предложен комплет шеми по сметководител. Се полни од шема на вистинска
 * фирма (веќе проверена), а се применува само при создавање нова фирма.
 */
class PostingSchemeSets
{
    public static function remember(User $user, PostingScheme $scheme): UserPostingScheme
    {
        $draft = app(PostingSchemeEditor::class)->draftOf($scheme);

        $definition = [
            'name' => $scheme->name,
            'rows' => array_map(function (array $row) {
                $mode = $row['account_mode'];

                return array_filter([
                    'mode' => $mode,
                    'account' => $mode === 'fixed' ? $row['account_code'] : null,
                    'matrix' => $mode === 'matrix' ? $row['matrix_key'] : null,
                    'side' => $row['side'],
                    'formula' => $row['formula'],
                    'partner' => (bool) $row['with_partner'],
                    'description' => $row['description'],
                    'condition' => $row['condition'],
                ], fn ($value) => $value !== null);
            }, $draft['rows']),
            'matrix' => array_map(fn (array $m) => [
                'key' => $m['matrix_key'],
                'kind' => $m['item_kind'],
                'group' => $m['vat_group'],
                'account' => $m['account_code'],
            ], $draft['matrix']),
        ];

        return UserPostingScheme::updateOrCreate(
            ['user_id' => $user->id, 'doc_type' => $scheme->doc_type->value],
            ['name' => $scheme->name, 'definition' => $definition]
        );
    }

    public static function has(User $user, PostingDocType $type): bool
    {
        return UserPostingScheme::where('user_id', $user->id)->where('doc_type', $type->value)->exists();
    }

    public static function forget(User $user, PostingDocType $type): void
    {
        UserPostingScheme::where('user_id', $user->id)->where('doc_type', $type->value)->delete();
    }

    /** @return array{name: string, rows: list<array<string, mixed>>, matrix: list<array<string, mixed>>} */
    public static function definitionFor(?User $user, PostingDocType $type): array
    {
        $mine = $user === null ? null : UserPostingScheme::where('user_id', $user->id)->where('doc_type', $type->value)->first();

        return $mine?->definition ?? DefaultPostingSchemes::definition($type);
    }

    /**
     * Нова фирма го добива предлогот на корисникот што ја создава — само за
     * видовите за кои има предлог. Ако некое конто го нема во планот на
     * фирмата, тој вид се прескокнува (останува стандардната, лено при прво
     * книжење): создавањето фирма никогаш не се блокира.
     */
    public static function seedCompany(Company $company, ?User $user): void
    {
        if ($user === null) {
            return;
        }

        foreach (UserPostingScheme::where('user_id', $user->id)->get() as $mine) {
            try {
                DB::transaction(function () use ($company, $mine) {
                    $scheme = PostingScheme::create(['company_id' => $company->id, 'doc_type' => $mine->doc_type, 'name' => $mine->definition['name']]);
                    DefaultPostingSchemes::populate($scheme, $company, $mine->definition);
                });
            } catch (ModelNotFoundException) {
                // Конто од предлогот го нема (аналитичко) во планот на оваа фирма.
            }
        }
    }
}
```

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Feature/Posting/PostingSchemeSetsTest.php`

- [ ] **Step 5: Commit**

```bash
git checkout -b posting-schemes-part4
git add app database tests docs
git commit -m "Posting schemes: personal suggested set per accountant; plan for part 4"
```

---

### Task 2: Нова фирма го добива предлогот; „Врати на предложено“ го користи

**Files:**
- Modify: `app/Observers/CompanyObserver.php`, `app/Services/Posting/PostingSchemeEditor.php`
- Test: `tests/Feature/Posting/PostingSchemeSetsTest.php` (дополни)

**Interfaces:**
- `CompanyObserver::created` по `seedForCompany` вика `PostingSchemeSets::seedCompany($company, $creator)` каде `$creator` = најавениот корисник ако е админ/сметководител, инаку `null`.
- `PostingSchemeEditor::resetToDefault(PostingScheme $scheme, ?User $user = null): void` — дефиницијата е `PostingSchemeSets::definitionFor($user, $type)`; ако предлогот има конто што го нема во фирмата, `ModelNotFoundException` се пропушта (трансакцијата ја враќа шемата неменета).

- [ ] **Step 1: Add failing tests** во `PostingSchemeSetsTest` (пред крајната `}`):

```php
    public function test_a_company_created_by_an_accountant_gets_the_accountants_set(): void
    {
        PostingSchemeSets::remember($this->accountant, $this->changedScheme(Company::factory()->create()));

        $this->actingAs($this->accountant);
        $created = Company::factory()->create();

        $scheme = PostingScheme::where('company_id', $created->id)->where('doc_type', 'sales_invoice')->first();
        $this->assertNotNull($scheme);
        $this->assertSame('Мој опис {фактура}', $scheme->rows->first()->description);
    }

    public function test_a_company_created_by_someone_without_a_set_gets_nothing_up_front(): void
    {
        $other = User::factory()->create();
        $other->assignRole('accountant');
        PostingSchemeSets::remember($this->accountant, $this->changedScheme(Company::factory()->create()));

        $this->actingAs($other);
        $created = Company::factory()->create();

        $this->assertSame(0, PostingScheme::where('company_id', $created->id)->count());
    }

    public function test_reset_to_default_uses_the_users_set_and_falls_back_to_the_standard(): void
    {
        $source = Company::factory()->create();
        PostingSchemeSets::remember($this->accountant, $this->changedScheme($source));
        $company = Company::factory()->create();
        $scheme = PostingSchemes::for($company, PostingDocType::SALES_INVOICE);
        $editor = app(PostingSchemeEditor::class);

        $editor->resetToDefault($scheme, $this->accountant);
        $this->assertSame('Мој опис {фактура}', $scheme->fresh()->rows->first()->description);

        $editor->resetToDefault($scheme);
        $this->assertSame('{фактура}', $scheme->fresh()->rows->first()->description);
    }

    public function test_reset_with_a_set_whose_account_is_missing_changes_nothing(): void
    {
        PostingSchemeSets::remember($this->accountant, $this->changedScheme(Company::factory()->create()));
        $company = Company::factory()->create();
        $scheme = PostingSchemes::for($company, PostingDocType::SALES_INVOICE);
        \App\Models\Account::where('company_id', $company->id)->where('code', '74001')->update(['is_analytical' => false]);
        $before = $scheme->rows()->count();

        try {
            app(PostingSchemeEditor::class)->resetToDefault($scheme, $this->accountant);
            $this->fail('Очекуван ModelNotFoundException.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertSame($before, $scheme->rows()->count());
        }
    }
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement**

`app/Observers/CompanyObserver.php`:

```php
<?php

namespace App\Observers;

use App\Models\Company;
use App\Services\OfficialChartOfAccounts;
use App\Services\Posting\PostingSchemeSets;

class CompanyObserver
{
    public function created(Company $company): void
    {
        OfficialChartOfAccounts::seedForCompany($company);

        // Предлогот на сметководителот што ја создава фирмата (ако има). Без
        // најавен корисник (команда, тест) — ништо; шемите се лено стандардни.
        $creator = auth()->user();

        if ($creator !== null && $creator->hasAnyRole(['admin', 'accountant'])) {
            PostingSchemeSets::seedCompany($company, $creator);
        }
    }
}
```

`PostingSchemeEditor::resetToDefault`:

```php
    public function resetToDefault(PostingScheme $scheme, ?User $user = null): void
    {
        $definition = PostingSchemeSets::definitionFor($user, $scheme->doc_type);

        DB::transaction(function () use ($scheme, $definition) {
            $scheme->rows()->delete();
            $scheme->matrixAccounts()->delete();
            DefaultPostingSchemes::populate($scheme, $scheme->company, $definition);
        });
    }
```
(+ `use App\Models\User;`.) Ако `populate` фрли `ModelNotFoundException`, трансакцијата се враќа — шемата останува како што била.

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Feature/Posting`

> Ако некој постојен тест создава фирма додека е најавен админ/сметководител со личен предлог — нема такви (предлози не постоеја). Ако падне нешто со `auth()` во конзола, `auth()->user()` е `null` и гранката се прескокнува.

- [ ] **Step 5: Commit**

```bash
git add app tests
git commit -m "Posting schemes: a new company gets the creator's set; reset uses the user's set"
```

---

### Task 3: Екран за фирма — „Зачувај и постави како мој предлог“

**Files:**
- Modify: `app/Livewire/Accounting/PostingSchemeEdit.php`, `resources/views/livewire/accounting/posting-scheme-edit.blade.php`
- Test: `tests/Feature/Posting/PostingSchemeScreenTest.php` (дополни)

**Interfaces:**
- Нови јавни својства: `bool $mineSaved = false`.
- Нов метод `saveAsMine()` — прво `saveScheme()`; ако нема проблеми, `PostingSchemeSets::remember(auth()->user(), шемата)` и `$mineSaved = true`.
- `restoreDefault()` го враќа предлогот на најавениот корисник (или стандардното); ако предлогот бара конто што го нема во фирмата → `problems` со порака, шемата неменета.
- Во `render()`: `'hasMine' => PostingSchemeSets::has(auth()->user(), $type)`.

- [ ] **Step 1: Add failing tests** во `PostingSchemeScreenTest`:

```php
    public function test_save_as_mine_saves_the_scheme_and_remembers_it_for_the_accountant(): void
    {
        $company = Company::factory()->create();
        $accountant = $this->accountantOf($company);

        Livewire::actingAs($accountant)->test(PostingSchemeEdit::class, ['company' => $company, 'type' => 'sales_invoice'])
            ->call('editRow', 0)->set('form.description', 'Мој опис {фактура}')->call('saveRow')
            ->call('saveAsMine')
            ->assertSet('problems', [])
            ->assertSet('mineSaved', true);

        $this->assertTrue(\App\Services\Posting\PostingSchemeSets::has($accountant, \App\Support\Posting\PostingDocType::SALES_INVOICE));
        $scheme = PostingScheme::where('company_id', $company->id)->where('doc_type', 'sales_invoice')->firstOrFail();
        $this->assertSame('Мој опис {фактура}', $scheme->rows->first()->description);
    }

    public function test_a_bad_draft_is_neither_saved_nor_remembered(): void
    {
        $company = Company::factory()->create();
        $accountant = $this->accountantOf($company);

        Livewire::actingAs($accountant)->test(PostingSchemeEdit::class, ['company' => $company, 'type' => 'sales_invoice'])
            ->call('editRow', 0)->set('form.formula', 'ВКУПНО + 1')->call('saveRow')
            ->call('saveAsMine')
            ->assertSet('mineSaved', false)
            ->assertSee('не се балансира');

        $this->assertFalse(\App\Services\Posting\PostingSchemeSets::has($accountant, \App\Support\Posting\PostingDocType::SALES_INVOICE));
    }

    public function test_restore_brings_back_my_set_when_i_have_one(): void
    {
        $company = Company::factory()->create();
        $accountant = $this->accountantOf($company);
        $component = Livewire::actingAs($accountant)->test(PostingSchemeEdit::class, ['company' => $company, 'type' => 'sales_invoice'])
            ->call('editRow', 0)->set('form.description', 'Мој опис {фактура}')->call('saveRow')->call('saveAsMine')
            ->call('editRow', 0)->set('form.description', 'Друго')->call('saveRow')->call('saveScheme');

        $component->call('restoreDefault');

        $scheme = PostingScheme::where('company_id', $company->id)->where('doc_type', 'sales_invoice')->firstOrFail();
        $this->assertSame('Мој опис {фактура}', $scheme->rows->first()->description);
    }

    public function test_restore_reports_a_missing_account_of_my_set_instead_of_failing(): void
    {
        $source = Company::factory()->create();
        $accountant = $this->accountantOf($source);
        Livewire::actingAs($accountant)->test(PostingSchemeEdit::class, ['company' => $source, 'type' => 'sales_invoice'])->call('saveAsMine');
        $company = Company::factory()->create();
        $accountant->assignedCompanies()->attach($company);
        \App\Models\Account::where('company_id', $company->id)->where('code', '74001')->update(['is_analytical' => false]);

        Livewire::actingAs($accountant)->test(PostingSchemeEdit::class, ['company' => $company, 'type' => 'sales_invoice'])
            ->call('restoreDefault')
            ->assertSee('нема во планот на оваа фирма');
    }
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement** во `PostingSchemeEdit`:

Додај својство по `$saved`:

```php
    public bool $mineSaved = false;
```
Метод (по `saveScheme`):

```php
    /** Зачувај ја шемата (низ сите проверки) и запомни ја како мој предлог за нови фирми. */
    public function saveAsMine(): void
    {
        $this->saveScheme();
        $this->mineSaved = false;

        if ($this->problems !== []) {
            return;
        }

        PostingSchemeSets::remember(auth()->user(), PostingSchemes::for($this->company, $this->docType()));
        $this->mineSaved = true;
    }
```
`restoreDefault()` замени го со:

```php
    public function restoreDefault(): void
    {
        Gate::authorize('update', $this->company);

        try {
            app(PostingSchemeEditor::class)->resetToDefault(PostingSchemes::for($this->company, $this->docType()), auth()->user());
        } catch (ModelNotFoundException) {
            $this->problems = ['Некое конто од вашиот предлог го нема во планот на оваа фирма — шемата не е променета.'];

            return;
        }

        $this->loadDraft();
        $this->mineSaved = false;
    }
```
Во `loadDraft()` додај `$this->mineSaved = false;`. Во `render()` додај `'hasMine' => PostingSchemeSets::has(auth()->user(), $type),`. Додај `use Illuminate\Database\Eloquent\ModelNotFoundException;` и `use App\Services\Posting\PostingSchemeSets;`.

Blade — замени го блокот со копчињата „Зачувај шема / Врати на предложено“ со:

```blade
    @if ($mineSaved)
        <div class="mb-3 p-3 rounded bg-green-50 text-green-800 text-sm">Шемата е зачувана и е ваш предлог — ќе ја добива секоја нова фирма што ја создавате.</div>
    @endif

    <div class="flex flex-wrap gap-2 mb-2">
        <x-primary-button type="button" wire:click="saveScheme">Зачувај шема</x-primary-button>
        <x-secondary-button type="button" wire:click="saveAsMine" wire:confirm="Да се зачува шемата и да стане ваш предлог за нови фирми? Постојните фирми не се менуваат.">Зачувај и постави како мој предлог</x-secondary-button>
        <x-secondary-button type="button" wire:click="restoreDefault" wire:confirm="Да се врати предложената шема ({{ $hasMine ? 'вашиот личен предлог' : 'стандардната' }})? Вашите измени за овој документ ќе се изгубат.">Врати на предложено</x-secondary-button>
    </div>
    <p class="text-xs text-gray-500 mb-8">
        {{ $hasMine ? 'Имате личен предлог за овој документ.' : 'Немате личен предлог — „Врати на предложено“ ја враќа стандардната шема.' }}
        Предлогот важи само за нови фирми што ги создавате.
    </p>
```
(наместо претходниот `<div class="flex flex-wrap gap-2 mb-8">…</div>`).

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Feature/Posting/PostingSchemeScreenTest.php`

- [ ] **Step 5: Commit**

```bash
git add app resources tests
git commit -m "Posting schemes: save the scheme as my suggestion for new companies"
```

---

### Task 4: Страница „Шеми за книжење“ на порталот (личните предлози)

**Files:**
- Create: `app/Livewire/OfficePostingSchemes.php`, `resources/views/livewire/office-posting-schemes.blade.php`
- Modify: `routes/web.php`, `resources/views/livewire/layout/sidebar.blade.php`, `app/Livewire/Layout/Sidebar.php`, `tests/Feature/SidebarTest.php`
- Delete: `app/Livewire/OfficeComingSoon.php`, `resources/views/livewire/office-coming-soon.blade.php`
- Test: `tests/Feature/Posting/OfficePostingSchemesTest.php`

**Interfaces:**
- Рута `office.posting-schemes` (`GET /podesuvanja/semi-za-knizenje`), `auth`, само админ/сметководител (403 за другите). Ја заменува „наскоро“ страницата `office.settings`.
- `OfficePostingSchemes`: список на четирите вида со статус „Личен предлог“ / „Стандарден“; `forgetSet(string $type)` (враќа на стандарден); објаснување како се прави предлог.

- [ ] **Step 1: Write the failing test** `tests/Feature/Posting/OfficePostingSchemesTest.php`:

```php
<?php

namespace Tests\Feature\Posting;

use App\Livewire\OfficePostingSchemes;
use App\Models\Company;
use App\Models\User;
use App\Services\Posting\PostingSchemes;
use App\Services\Posting\PostingSchemeSets;
use App\Support\Posting\PostingDocType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OfficePostingSchemesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
    }

    private function accountant(): User
    {
        $user = User::factory()->create();
        $user->assignRole('accountant');

        return $user;
    }

    public function test_the_page_opens_for_an_accountant_and_an_admin_but_not_a_client(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $client = User::factory()->create();
        $client->assignRole('internal_client');

        $this->actingAs($this->accountant())->get(route('office.posting-schemes'))->assertOk();
        $this->actingAs($admin)->get(route('office.posting-schemes'))->assertOk();
        $this->actingAs($client)->get(route('office.posting-schemes'))->assertForbidden();
    }

    public function test_it_shows_which_documents_have_a_personal_suggestion(): void
    {
        $accountant = $this->accountant();
        PostingSchemeSets::remember($accountant, PostingSchemes::for(Company::factory()->create(), PostingDocType::SALES_INVOICE));

        Livewire::actingAs($accountant)->test(OfficePostingSchemes::class)
            ->assertSee('Излезна фактура')
            ->assertSee('Личен предлог')
            ->assertSee('Стандарден');
    }

    public function test_a_suggestion_can_be_reset_to_the_standard(): void
    {
        $accountant = $this->accountant();
        PostingSchemeSets::remember($accountant, PostingSchemes::for(Company::factory()->create(), PostingDocType::SALES_INVOICE));

        Livewire::actingAs($accountant)->test(OfficePostingSchemes::class)->call('forgetSet', 'sales_invoice');

        $this->assertFalse(PostingSchemeSets::has($accountant, PostingDocType::SALES_INVOICE));
    }

    public function test_an_unknown_type_is_a_404(): void
    {
        Livewire::actingAs($this->accountant())->test(OfficePostingSchemes::class)->call('forgetSet', 'nesto')->assertNotFound();
    }
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement**

`app/Livewire/OfficePostingSchemes.php`:

```php
<?php

namespace App\Livewire;

use App\Services\Posting\PostingSchemeSets;
use App\Support\Posting\PostingDocType;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Личниот предложен комплет шеми на сметководителот. Не се уредува овде —
 * комплетот се прави во вистинска фирма (со „Пробај“) и таму се поставува како
 * личен предлог; овде само се гледа и се враќа на стандарден.
 */
#[Layout('layouts.app')]
class OfficePostingSchemes extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
    }

    public function forgetSet(string $type): void
    {
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
        $docType = PostingDocType::tryFrom($type) ?? abort(404);

        PostingSchemeSets::forget(auth()->user(), $docType);
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.office-posting-schemes', [
            'entries' => collect(PostingDocType::cases())->map(fn (PostingDocType $type) => [
                'type' => $type,
                'mine' => PostingSchemeSets::has($user, $type),
            ]),
        ]);
    }
}
```

`resources/views/livewire/office-posting-schemes.blade.php`:

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">Шеми за книжење — мој предлог</h1>
    <p class="text-sm text-gray-600 mb-4 max-w-3xl">
        Предлогот е комплетот шеми што ќе го добива секоја нова фирма што ја создавате. Постојните фирми не се менуваат.
        Како се прави: отворете „Шеми за книжење“ во некоја ваша фирма, дотерајте ја шемата, пробајте ја со „Пробај“ и притиснете „Зачувај и постави како мој предлог“.
    </p>

    <x-card padding="p-0" class="overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-1 px-3">Документ</th>
                    <th class="py-1 px-3">Нова фирма добива</th>
                    <th class="py-1 px-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($entries as $entry)
                    <tr class="text-sm hover:bg-orange-50">
                        <td class="py-1 px-3 font-medium">{{ $entry['type']->label() }}</td>
                        <td class="py-1 px-3">{{ $entry['mine'] ? 'Личен предлог' : 'Стандарден' }}</td>
                        <td class="py-1 px-3 text-right">
                            @if ($entry['mine'])
                                <button type="button" wire:click="forgetSet('{{ $entry['type']->value }}')" wire:confirm="Да се врати на стандарден предлог за „{{ $entry['type']->label() }}“?" class="text-red-600 hover:underline">Врати на стандарден</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-card>
</div>
```

`routes/web.php` — замени ја рутата `office.settings` (редови со `OfficeComingSoon`) со:

```php
    Route::middleware(['auth'])->get('/podesuvanja/semi-za-knizenje', [OfficePostingSchemes::class, '__invoke'])->name('office.posting-schemes');
```
и замени `use App\Livewire\OfficeComingSoon;` со `use App\Livewire\OfficePostingSchemes;`.

`resources/views/livewire/layout/sidebar.blade.php` (ред ~49–51): `href="{{ route('office.posting-schemes') }}"`, а условот за активна врска: `$currentRoute === 'office.posting-schemes'`.

`app/Livewire/Layout/Sidebar.php`: тргни го `$currentFeature` (својство, коментар на ред ~42 и сета во `render`/`mount` — провери со `grep -n currentFeature app resources`); ако некој друг шаблон го користи, остави го само ако е потребно.

Избриши: `app/Livewire/OfficeComingSoon.php` и `resources/views/livewire/office-coming-soon.blade.php`.

`tests/Feature/SidebarTest.php` (околу 492–501): замени `route('office.settings', 'semi-za-knizenje')` со `route('office.posting-schemes')`; тестот „само за сметководител“ сега важи и за админ — ако асертира `assertForbidden` за админ, ажурирај: админ → `assertOk()`, клиент → `assertForbidden()`; преименувај го тестот на `test_the_office_posting_schemes_page_opens_for_the_office_only`.

- [ ] **Step 4: Run — PASS.** `php artisan test tests/Feature/Posting tests/Feature/SidebarTest.php tests/Unit/Support/MenuTest.php`

- [ ] **Step 5: Commit**

```bash
git add -A app resources routes tests
git commit -m "Posting schemes: the accountant's personal suggestions page replaces the coming-soon placeholder"
```

---

### Task 5: Документација, целосна серија, спојување

**Files:**
- Modify: `docs/superpowers/specs/2026-10-06-posting-schemes-design.md` (под „Одстапувања при градењето“ додај „дел 4“)
- Create: `docs/superpowers/2026-10-06-posting-schemes-part4-log.md`

- [ ] **Step 1: Одстапувања (дел 4)** — запиши: (1) предлогот е **по сметководител** (изречна одлука на сопственикот), не еден за цела канцеларија; (2) предлогот се прави во вистинска фирма и се поставува со „Зачувај и постави како мој предлог“ — нема посебен канцелариски уредник (проверките и „Пробај“ се веќе таму); (3) предлогот се применува само при создавање фирма, и само за видовите за кои има предлог; ако некое конто го нема во новата фирма, за тој вид останува стандардното; (4) „Врати на предложено“ во фирма го враќа предлогот на корисникот што го притиска; (5) страницата на порталот „Шеми за книжење“ (сега за админ и сметководител) ја замени „наскоро“ страницата.

- [ ] **Step 2: Лог** `docs/superpowers/2026-10-06-posting-schemes-part4-log.md`: направено (4 задачи), мапа, одлуки, ненаправено (синхронизација на постојни фирми со предлог; предлог за цела канцеларија; „примени предлог врз постојна фирма“ освен рачно „Врати на предложено“), како да се пробаат рачно: фирма → Шеми за книжење → смени → „Зачувај и постави како мој предлог“ → создај нова фирма → Шеми за книжење → ги гледаш твоите редови.

- [ ] **Step 3: Целосна серија** — **прво прашај го корисникот**, па `php artisan test`. Очекувано: сè зелено, 3 прескокнати.

- [ ] **Step 4: Commit, спој и пушти:**

```bash
git add docs
git commit -m "Docs: posting schemes part 4 spec amendments and log"
git checkout main && git merge --no-ff posting-schemes-part4 -m "Merge posting schemes part 4: personal suggested set per accountant" && git push origin main
```

CI се следи еднаш (`gh run list`, од директориумот на проектот). Сними во меморија што е готово. Со тоа проектот „шеми за книжење“ е завршен (делови 1–4).
