# Корисници и админ дел за клиент — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Отворање на кориснички сметки од самата апликација — со покана по линк, со гасење пристап наместо бришење, и со целата работа околу еден клиент на едно место (картички на профилот на фирмата).

**Architecture:** Картичките се одделни рути со свои Livewire компоненти, не состојба во една компонента — така секоја картичка се заклучува на сервер сама за себе, точно како постоечките екрани. Поканата е своја табела и свој јавен екран, независна од ресетирањето лозинка. Логиката за покани седи во `App\Support\UserInvitations` за да ја делат двата екрана (клиент и канцеларија) без препишување.

**Tech Stack:** Laravel 12 + Livewire 3 (класични компоненти за екрани, Volt само за `auth` страниците), Spatie Permission за улоги, Tailwind, PHPUnit.

## Global Constraints

- Спецификација: `docs/superpowers/specs/2026-09-06-users-and-client-admin-design.md`. При секое несогласување помеѓу планот и спецификацијата, спецификацијата важи.
- Целиот текст видлив за корисник е на македонски. Никаква бугарска лексика.
- `App\Support\Menu` и `tests/Unit/Support/MenuTest.php` не се менуваат во ниту една задача. Ако некоја промена ги руши тие тестови, промената е погрешна.
- Ниту еден постоечки тест не смее да се ослабне за да помине. Тестовите што ги менуваат задачите се само оние што изречно тврдат нешто за полиња што задачата ги преместува или брише — тие се преместуваат, не се бришат.
- Улогите се доделуваат само со `assignRole` (Spatie), никогаш со рачно пишување во табелата.
- `users.company_id` не е во `#[Fillable]` на `App\Models\User` — се запишува со `forceFill()`, никогаш со `create([...])`.
- Полниот тест-сет се пушта само еднаш, на крајот од Задача 8. Во текот на работата се пушта само тест-датотеката на тековната задача.
- Секоја задача завршува со свој коммит на гранката `users-and-client-admin`.

---

### Task 0: Гранка

- [ ] **Step 1: Направи ја гранката**

```bash
git checkout main
git pull
git checkout -b users-and-client-admin
```

- [ ] **Step 2: Потврди дека тргнуваш од чиста состојба**

Run: `git status --short`
Expected: нема изменети следени датотеки (неследени PDF-ови во коренот се очекувани и не се допираат).

---

### Task 1: Исклучен пристап

**Files:**
- Create: `database/migrations/2026_09_06_000100_add_disabled_at_to_users_table.php`
- Create: `app/Http/Middleware/EnsureUserIsActive.php`
- Create: `tests/Feature/Users/DisabledAccessTest.php`
- Modify: `app/Models/User.php` (casts)
- Modify: `app/Livewire/Forms/LoginForm.php:33-41`
- Modify: `bootstrap/app.php:14-16`

**Interfaces:**
- Consumes: ништо од претходни задачи.
- Produces: колоната `users.disabled_at` (`?Carbon`), кастирана како `datetime`. Сите подоцнежни задачи проверуваат исклученост со `$user->disabled_at !== null`.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Create `tests/Feature/Users/DisabledAccessTest.php`:

```php
<?php

namespace Tests\Feature\Users;

use App\Livewire\Forms\LoginForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DisabledAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'password' => Hash::make('tajna-lozinka'),
        ], $attributes));
    }

    public function test_an_active_user_can_log_in(): void
    {
        $user = $this->user();

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'tajna-lozinka')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_disabled_user_cannot_log_in(): void
    {
        $user = $this->user(['disabled_at' => now()]);

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'tajna-lozinka')
            ->call('login')
            ->assertHasErrors('form.email');

        $this->assertGuest();
    }

    public function test_a_user_disabled_mid_session_is_logged_out_on_the_next_click(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['disabled_at' => now()])->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
```

- [ ] **Step 2: Пушти ги и потврди дека паѓаат**

Run: `php artisan test --filter=DisabledAccessTest`
Expected: FAIL — колоната `disabled_at` не постои.

- [ ] **Step 3: Додај ја колоната**

Create `database/migrations/2026_09_06_000100_add_disabled_at_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Сметка никогаш не се брише — човек што издавал фактури останува
            // потпишан врз нив. Пристапот се одзема со оваа колона.
            $table->timestamp('disabled_at')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('disabled_at');
        });
    }
};
```

- [ ] **Step 4: Кастирај ја во моделот**

Modify `app/Models/User.php`, во `casts()`:

```php
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'disabled_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
```

- [ ] **Step 5: Затвори ја најавата**

Modify `app/Livewire/Forms/LoginForm.php`, во `authenticate()`, веднаш по успешниот `Auth::attempt` блок и пред `RateLimiter::clear`:

```php
        // Исклучена сметка минува низ Auth::attempt зашто лозинката е точна —
        // проверката мора да дојде по неа, со изречно одјавување.
        if (Auth::user()->disabled_at !== null) {
            Auth::logout();

            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'form.email' => 'Пристапот за оваа сметка е исклучен. Обратете се во канцеларијата.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
```

- [ ] **Step 6: Исфрли ги веќе отворените сесии**

Create `app/Http/Middleware/EnsureUserIsActive.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Исклучувањето важи и за сесија што била отворена пред него. Без ова,
 * корисник кому му е одземен пристапот работи натаму сѐ додека не се одјави сам.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && Auth::user()->disabled_at !== null) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['form.email' => 'Пристапот за оваа сметка е исклучен.']);
        }

        return $next($request);
    }
}
```

Modify `bootstrap/app.php`:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\EnsureUserIsActive::class,
        ]);
    })
```

- [ ] **Step 7: Пушти ги тестовите**

Run: `php artisan test --filter=DisabledAccessTest`
Expected: PASS (3 тестови).

- [ ] **Step 8: Коммит**

```bash
git add database/migrations app/Http/Middleware/EnsureUserIsActive.php app/Models/User.php app/Livewire/Forms/LoginForm.php bootstrap/app.php tests/Feature/Users/DisabledAccessTest.php
git commit -m "feat(users): исклучен пристап наместо бришење сметка"
```

---

### Task 2: Покани — табела, модел и логика

**Files:**
- Create: `database/migrations/2026_09_06_000200_create_user_invitations_table.php`
- Create: `app/Models/UserInvitation.php`
- Create: `app/Support/UserInvitations.php`
- Create: `app/Notifications/UserInvitationNotification.php`
- Create: `tests/Feature/Users/UserInvitationsTest.php`
- Modify: `app/Models/User.php` (нова врска `latestInvitation`, метод `accessStatus`)

**Interfaces:**
- Consumes: `users.disabled_at` од Задача 1.
- Produces:
  - `UserInvitations::issue(User $user, User $issuedBy): string` — враќа целосен URL, ја гаси претходната неискористена покана.
  - `UserInvitations::find(string $plainToken): ?UserInvitation` — само важечка и неискористена.
  - `UserInvitations::accept(string $plainToken, string $password): ?User` — поставува лозинка, ја троши поканата, враќа `null` ако линкот не важи или сметката е исклучена.
  - `UserInvitations::DAYS_VALID` = `7`.
  - `User::accessStatus(): string` — една од `'active' | 'invited' | 'invitation_expired' | 'disabled'`.
  - `User::latestInvitation` — `HasOne` врска, за да не се прави прашалник по ред во списоците.
  - `UserInvitationNotification::__construct(string $url)`.

Рутата `invitation.accept` се создава дури во Задача 3. За да може оваа задача да се тестира сама, `issue()` ја гради адресата со `route('invitation.accept', ...)`, па Задача 3 мора да ја регистрира точно под тоа име. Затоа тестот подолу ја регистрира рутата привремено — а во Задача 3 тој ред се брише.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Create `tests/Feature/Users/UserInvitationsTest.php`:

```php
<?php

namespace Tests\Feature\Users;

use App\Models\User;
use App\Models\UserInvitation;
use App\Support\UserInvitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UserInvitationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Привремено, додека Задача 3 не ја внесе вистинската рута.
        // ТРГНИ ГО ОВОЈ БЛОК во Задача 3.
        if (! Route::has('invitation.accept')) {
            Route::get('invitation/{token}', fn () => '')->name('invitation.accept');
        }
    }

    public function test_a_fresh_invitation_sets_the_password_and_returns_the_user(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create(['password' => Hash::make('nema-vrska')]);

        $url = UserInvitations::issue($user, $admin);
        $token = basename(parse_url($url, PHP_URL_PATH));

        $accepted = UserInvitations::accept($token, 'nova-lozinka-123');

        $this->assertNotNull($accepted);
        $this->assertTrue($accepted->is($user));
        $this->assertTrue(Hash::check('nova-lozinka-123', $user->fresh()->password));
    }

    public function test_the_plain_token_is_never_stored(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();

        $url = UserInvitations::issue($user, $admin);
        $token = basename(parse_url($url, PHP_URL_PATH));

        $this->assertDatabaseMissing('user_invitations', ['token_hash' => $token]);
        $this->assertDatabaseHas('user_invitations', ['token_hash' => hash('sha256', $token)]);
    }

    public function test_a_link_works_only_once(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();

        $url = UserInvitations::issue($user, $admin);
        $token = basename(parse_url($url, PHP_URL_PATH));

        $this->assertNotNull(UserInvitations::accept($token, 'prva-lozinka-123'));
        $this->assertNull(UserInvitations::accept($token, 'vtora-lozinka-123'));
        $this->assertTrue(Hash::check('prva-lozinka-123', $user->fresh()->password));
    }

    public function test_a_link_older_than_seven_days_does_not_work(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();

        $url = UserInvitations::issue($user, $admin);
        $token = basename(parse_url($url, PHP_URL_PATH));

        $this->travel(UserInvitations::DAYS_VALID + 1)->days();

        $this->assertNull(UserInvitations::accept($token, 'nova-lozinka-123'));
    }

    public function test_issuing_a_new_invitation_kills_the_previous_one(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();

        $prv = basename(parse_url(UserInvitations::issue($user, $admin), PHP_URL_PATH));
        $vtor = basename(parse_url(UserInvitations::issue($user, $admin), PHP_URL_PATH));

        $this->assertNull(UserInvitations::accept($prv, 'nova-lozinka-123'));
        $this->assertNotNull(UserInvitations::accept($vtor, 'nova-lozinka-123'));
    }

    public function test_a_disabled_user_cannot_accept_an_invitation(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();

        $token = basename(parse_url(UserInvitations::issue($user, $admin), PHP_URL_PATH));

        $user->forceFill(['disabled_at' => now()])->save();

        $this->assertNull(UserInvitations::accept($token, 'nova-lozinka-123'));
    }

    public function test_access_status_reads_the_latest_invitation(): void
    {
        $admin = User::factory()->create();

        $active = User::factory()->create();
        $this->assertSame('active', $active->accessStatus());

        $disabled = User::factory()->create(['disabled_at' => now()]);
        $this->assertSame('disabled', $disabled->accessStatus());

        $invited = User::factory()->create();
        UserInvitations::issue($invited, $admin);
        $this->assertSame('invited', $invited->fresh()->accessStatus());

        $expired = User::factory()->create();
        UserInvitations::issue($expired, $admin);
        UserInvitation::query()->where('user_id', $expired->id)
            ->update(['expires_at' => now()->subDay()]);
        $this->assertSame('invitation_expired', $expired->fresh()->accessStatus());
    }
}
```

- [ ] **Step 2: Пушти ги и потврди дека паѓаат**

Run: `php artisan test --filter=UserInvitationsTest`
Expected: FAIL — класата `App\Support\UserInvitations` не постои.

- [ ] **Step 3: Направи ја табелата**

Create `database/migrations/2026_09_06_000200_create_user_invitations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Своја табела, не `password_reset_tokens`: рокот е различен (7 дена
        // наспроти 60 минути), а споделена табела клучена по е-пошта значи
        // дека покана и барање за нова лозинка се газат меѓусебно.
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Се чува само отпечаток. Затоа линкот се гледа само еднаш, веднаш
            // по создавањето — подоцна се издава нов.
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
```

- [ ] **Step 4: Направи го моделот**

Create `app/Models/UserInvitation.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserInvitation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
```

- [ ] **Step 5: Напиши ја логиката**

Create `app/Support/UserInvitations.php`:

```php
<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Поканата е еднократен линк со кој корисникот си поставува лозинка. Логиката
 * седи тука, а не во Livewire компонентите, зашто екранот кај клиент и екранот
 * на канцеларијата ја делат.
 */
class UserInvitations
{
    public const DAYS_VALID = 7;

    /**
     * Прави нова покана и го враќа целосниот линк. Секоја претходна
     * неискористена покана за истиот корисник престанува да важи.
     */
    public static function issue(User $user, User $issuedBy): string
    {
        $plain = Str::random(64);

        return DB::transaction(function () use ($user, $issuedBy, $plain) {
            UserInvitation::query()
                ->where('user_id', $user->id)
                ->whereNull('accepted_at')
                ->delete();

            UserInvitation::create([
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $plain),
                'expires_at' => now()->addDays(self::DAYS_VALID),
                'created_by' => $issuedBy->id,
            ]);

            return route('invitation.accept', ['token' => $plain]);
        });
    }

    public static function find(string $plainToken): ?UserInvitation
    {
        return UserInvitation::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Ја троши поканата и ја поставува лозинката. Враќа `null` за секоја
     * причина поради која линкот не важи — екранот потоа кажува една иста
     * порака, за да не открива дали адресата постои.
     */
    public static function accept(string $plainToken, string $password): ?User
    {
        $invitation = self::find($plainToken);

        if ($invitation === null) {
            return null;
        }

        $user = $invitation->user;

        if ($user === null || $user->disabled_at !== null) {
            return null;
        }

        DB::transaction(function () use ($invitation, $user, $password) {
            $user->forceFill(['password' => Hash::make($password)])->save();
            $invitation->forceFill(['accepted_at' => now()])->save();
        });

        return $user;
    }
}
```

- [ ] **Step 6: Додај ги врската и состојбата на моделот**

Modify `app/Models/User.php` — додај ги увозите `Illuminate\Database\Eloquent\Relations\HasOne` и `App\Models\UserInvitation`, па додај ги методите по `visibleCompanies()`:

```php
    public function latestInvitation(): HasOne
    {
        return $this->hasOne(UserInvitation::class)->latestOfMany();
    }

    /**
     * Состојбата што ја гледа канцеларијата во списокот корисници.
     */
    public function accessStatus(): string
    {
        if ($this->disabled_at !== null) {
            return 'disabled';
        }

        $invitation = $this->latestInvitation;

        if ($invitation === null || $invitation->accepted_at !== null) {
            return 'active';
        }

        return $invitation->expires_at->isFuture() ? 'invited' : 'invitation_expired';
    }
```

- [ ] **Step 7: Напиши ја пораката за покана**

Create `app/Notifications/UserInvitationNotification.php`:

```php
<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(private string $url) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Пристап до порталот на FinanceBuddy')
            ->greeting('Здраво '.$notifiable->name.',')
            ->line('Канцеларијата ви отвори пристап до порталот.')
            ->action('Постави лозинка', $this->url)
            ->line('Линкот важи '.\App\Support\UserInvitations::DAYS_VALID.' дена и може да се употреби само еднаш.')
            ->salutation('Поздрав, FinanceBuddy');
    }
}
```

- [ ] **Step 8: Пушти ги тестовите**

Run: `php artisan test --filter=UserInvitationsTest`
Expected: PASS (7 тестови).

- [ ] **Step 9: Коммит**

```bash
git add database/migrations app/Models app/Support/UserInvitations.php app/Notifications tests/Feature/Users/UserInvitationsTest.php
git commit -m "feat(users): покана со еднократен линк што важи 7 дена"
```

---

### Task 3: Јавен екран „Постави лозинка"

**Files:**
- Create: `resources/views/livewire/pages/auth/accept-invitation.blade.php`
- Create: `tests/Feature/Users/AcceptInvitationScreenTest.php`
- Modify: `routes/auth.php:7-25` (во `guest` групата)
- Modify: `tests/Feature/Users/UserInvitationsTest.php` (се брише привремената рута од `setUp`)

**Interfaces:**
- Consumes: `UserInvitations::accept()`, `UserInvitations::find()` од Задача 2.
- Produces: именуваната рута `invitation.accept` со параметар `{token}`. Задачите 4 и 6 не ја викаат директно — само преку `UserInvitations::issue()`.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Create `tests/Feature/Users/AcceptInvitationScreenTest.php`:

```php
<?php

namespace Tests\Feature\Users;

use App\Models\User;
use App\Support\UserInvitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AcceptInvitationScreenTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        $url = UserInvitations::issue($user, User::factory()->create());

        return basename(parse_url($url, PHP_URL_PATH));
    }

    public function test_the_screen_opens_without_being_logged_in(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        $this->get(route('invitation.accept', ['token' => $token]))
            ->assertOk()
            ->assertSee('Постави лозинка');
    }

    public function test_setting_a_password_logs_the_user_in(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        Volt::test('pages.auth.accept-invitation', ['token' => $token])
            ->set('password', 'nova-lozinka-123')
            ->set('password_confirmation', 'nova-lozinka-123')
            ->call('acceptInvitation')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_a_spent_link_shows_a_message_and_does_not_log_anybody_in(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        UserInvitations::accept($token, 'prva-lozinka-123');

        Volt::test('pages.auth.accept-invitation', ['token' => $token])
            ->set('password', 'vtora-lozinka-123')
            ->set('password_confirmation', 'vtora-lozinka-123')
            ->call('acceptInvitation')
            ->assertHasErrors('password');

        $this->assertGuest();
    }

    public function test_an_expired_link_shows_a_message(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        $this->travel(UserInvitations::DAYS_VALID + 1)->days();

        Volt::test('pages.auth.accept-invitation', ['token' => $token])
            ->set('password', 'nova-lozinka-123')
            ->set('password_confirmation', 'nova-lozinka-123')
            ->call('acceptInvitation')
            ->assertHasErrors('password');

        $this->assertGuest();
    }

    public function test_the_password_must_be_confirmed(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        Volt::test('pages.auth.accept-invitation', ['token' => $token])
            ->set('password', 'nova-lozinka-123')
            ->set('password_confirmation', 'druga-lozinka-123')
            ->call('acceptInvitation')
            ->assertHasErrors('password');

        $this->assertGuest();
    }
}
```

- [ ] **Step 2: Пушти ги и потврди дека паѓаат**

Run: `php artisan test --filter=AcceptInvitationScreenTest`
Expected: FAIL — рутата `invitation.accept` не постои.

- [ ] **Step 3: Регистрирај ја рутата**

Modify `routes/auth.php`, во `Route::middleware('guest')` групата, по редот за `login`:

```php
    // Поканата е единствениот начин да се постави лозинка на нова сметка.
    // Јавна е како и екраните за ресетирање — линкот сам по себе е доказот.
    Volt::route('invitation/{token}', 'pages.auth.accept-invitation')
        ->name('invitation.accept');
```

- [ ] **Step 4: Направи го екранот**

Create `resources/views/livewire/pages/auth/accept-invitation.blade.php`:

```blade
<?php

use App\Support\UserInvitations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    #[Locked]
    public string $token = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function acceptInvitation(): void
    {
        $this->validate([
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = UserInvitations::accept($this->token, $this->password);

        // Една иста порака за секоја причина (истечен, употребен, исклучена
        // сметка) — екранот не смее да открива дали адресата постои.
        if ($user === null) {
            $this->addError('password', 'Линкот повеќе не важи. Побарајте нов од канцеларијата.');

            return;
        }

        Auth::login($user);
        Session::regenerate();

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div>
    <h2 class="text-lg font-semibold text-gray-800 mb-1">Постави лозинка</h2>
    <p class="text-sm text-gray-600 mb-4">Изберете лозинка со која ќе влегувате во порталот.</p>

    <form wire:submit="acceptInvitation">
        <div>
            <x-input-label for="password" value="Лозинка" />
            <x-text-input wire:model="password" id="password" class="block mt-1 w-full"
                          type="password" name="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password_confirmation" value="Потврди лозинка" />
            <x-text-input wire:model="password_confirmation" id="password_confirmation" class="block mt-1 w-full"
                          type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button>Зачувај и влези</x-primary-button>
        </div>
    </form>
</div>
```

- [ ] **Step 5: Тргни ја привремената рута од Задача 2**

Modify `tests/Feature/Users/UserInvitationsTest.php` — избриши го целиот `setUp()` метод заедно со коментарот „ТРГНИ ГО ОВОЈ БЛОК", и увозот `Illuminate\Support\Facades\Route` ако останал неупотребен.

- [ ] **Step 6: Пушти ги двете тест-датотеки**

Run: `php artisan test --filter="AcceptInvitationScreenTest|UserInvitationsTest"`
Expected: PASS (12 тестови).

- [ ] **Step 7: Коммит**

```bash
git add routes/auth.php resources/views/livewire/pages/auth/accept-invitation.blade.php tests/Feature/Users
git commit -m "feat(users): јавен екран за поставување лозинка преку покана"
```

---

### Task 4: Правила, картички и екранот „Корисници"

**Files:**
- Create: `app/Policies/UserPolicy.php`
- Create: `app/Support/CompanyTabs.php`
- Create: `resources/views/components/tab-strip.blade.php`
- Create: `app/Livewire/CompanyUsers.php`
- Create: `resources/views/livewire/company-users.blade.php`
- Create: `tests/Feature/Users/CompanyUsersTest.php`
- Create: `tests/Unit/Support/CompanyTabsTest.php`
- Modify: `routes/web.php:87-90` (групата `companies/{company}`)

**Interfaces:**
- Consumes: `UserInvitations::issue()`, `User::accessStatus()`, `UserInvitationNotification`.
- Produces:
  - Рутата `companies.users` (`companies/{company}/users`).
  - `CompanyTabs::for(User $user, Company $company): array` — листа од `['label' => string, 'url' => string, 'active' => bool]`, филтрирана по улога. Задачите 6 и 7 ја користат непроменета.
  - Blade компонентата `<x-tab-strip :tabs="$tabs" />`.
  - `App\Livewire\CompanyUsers` со јавни методи `addUser()`, `reinvite(int $userId)`, `disable(int $userId)`, `enable(int $userId)`.

- [ ] **Step 1: Напиши го тестот за картичките**

Create `tests/Unit/Support/CompanyTabsTest.php`:

```php
<?php

namespace Tests\Unit\Support;

use App\Models\Company;
use App\Models\User;
use App\Support\CompanyTabs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyTabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function labels(User $user, Company $company): array
    {
        return array_column(CompanyTabs::for($user, $company), 'label');
    }

    public function test_an_admin_sees_all_three_tabs(): void
    {
        $this->assertSame(
            ['Профил', 'Модули', 'Корисници'],
            $this->labels($this->userWithRole('admin'), Company::factory()->create()),
        );
    }

    public function test_a_client_does_not_see_the_modules_tab(): void
    {
        $this->assertSame(
            ['Профил', 'Корисници'],
            $this->labels($this->userWithRole('client'), Company::factory()->create()),
        );
    }

    public function test_an_accountant_does_not_see_the_modules_tab(): void
    {
        $this->assertSame(
            ['Профил', 'Корисници'],
            $this->labels($this->userWithRole('accountant'), Company::factory()->create()),
        );
    }
}
```

- [ ] **Step 2: Пушти го и потврди дека паѓа**

Run: `php artisan test --filter=CompanyTabsTest`
Expected: FAIL — класата `App\Support\CompanyTabs` не постои.

- [ ] **Step 3: Напиши ги картичките**

Create `app/Support/CompanyTabs.php`:

```php
<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;

/**
 * Картичките на профилот на клиент. Држени како податок, по истата причина
 * како App\Support\Menu — за да може табелата на улоги да се тврди директно во
 * тест, наместо со чепкање низ исцртан HTML.
 *
 * Никогаш не вика request(). Тековната рута ја дознава преку аргументот.
 */
class CompanyTabs
{
    /**
     * @return list<array{label: string, url: string, active: bool}>
     */
    public static function for(User $user, Company $company, ?string $currentRoute = null): array
    {
        $tabs = [
            ['label' => 'Профил', 'route' => 'companies.profile', 'roles' => null],
            ['label' => 'Модули', 'route' => 'companies.modules', 'roles' => ['admin']],
            ['label' => 'Корисници', 'route' => 'companies.users', 'roles' => null],
        ];

        $visible = [];

        foreach ($tabs as $tab) {
            if ($tab['roles'] !== null && ! $user->hasAnyRole($tab['roles'])) {
                continue;
            }

            $visible[] = [
                'label' => $tab['label'],
                'url' => route($tab['route'], $company),
                'active' => $currentRoute === $tab['route'],
            ];
        }

        return $visible;
    }
}
```

- [ ] **Step 4: Пушти го тестот за картичките**

Run: `php artisan test --filter=CompanyTabsTest`
Expected: PASS (3 тестови).

- [ ] **Step 5: Напиши го тестот за екранот „Корисници"**

Create `tests/Feature/Users/CompanyUsersTest.php`:

```php
<?php

namespace Tests\Feature\Users;

use App\Livewire\CompanyUsers;
use App\Models\Company;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    private function userWithRole(string $role, ?Company $company = null): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        if ($company !== null) {
            $user->forceFill(['company_id' => $company->id])->save();
        }

        return $user;
    }

    public function test_an_admin_opens_a_client_account_and_gets_a_link(): void
    {
        Notification::fake();

        $company = Company::factory()->create();

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(CompanyUsers::class, ['company' => $company])
            ->set('newName', 'Марија Петровска')
            ->set('newEmail', 'marija@primer.mk')
            ->call('addUser')
            ->assertHasNoErrors()
            ->assertSet('inviteMailSent', true);

        $created = User::where('email', 'marija@primer.mk')->firstOrFail();

        $this->assertSame($company->id, $created->company_id);
        $this->assertTrue($created->hasRole('client'));
        $this->assertSame('invited', $created->accessStatus());

        Notification::assertSentTo($created, UserInvitationNotification::class);
    }

    public function test_the_link_is_shown_on_screen_after_creating(): void
    {
        Notification::fake();

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(CompanyUsers::class, ['company' => Company::factory()->create()])
            ->set('newName', 'Марија Петровска')
            ->set('newEmail', 'marija@primer.mk')
            ->call('addUser')
            ->assertSet('inviteLink', fn ($link) => is_string($link) && str_contains($link, '/invitation/'));
    }

    public function test_a_failing_mail_server_does_not_lose_the_account(): void
    {
        // Без Notification::fake() тука — фејкот не фрла. Се заменува самата
        // фасада со мок што фрла, зашто Notifiable::notify() оди преку неа.
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('нема пошта'));

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(CompanyUsers::class, ['company' => Company::factory()->create()])
            ->set('newName', 'Марија Петровска')
            ->set('newEmail', 'marija@primer.mk')
            ->call('addUser')
            ->assertHasNoErrors()
            ->assertSet('inviteMailSent', false)
            ->assertSet('inviteLink', fn ($link) => is_string($link) && $link !== '');

        $this->assertDatabaseHas('users', ['email' => 'marija@primer.mk']);
    }

    public function test_an_existing_email_is_refused_without_naming_the_company(): void
    {
        User::factory()->create(['email' => 'marija@primer.mk']);

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(CompanyUsers::class, ['company' => Company::factory()->create()])
            ->set('newName', 'Марија Петровска')
            ->set('newEmail', 'marija@primer.mk')
            ->call('addUser')
            ->assertHasErrors('newEmail');

        $this->assertSame(1, User::where('email', 'marija@primer.mk')->count());
    }

    public function test_an_admin_disables_and_restores_access(): void
    {
        $company = Company::factory()->create();
        $client = $this->userWithRole('client', $company);

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('disable', $client->id)
            ->assertHasNoErrors();

        $this->assertNotNull($client->fresh()->disabled_at);

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('enable', $client->id)
            ->assertHasNoErrors();

        $this->assertNull($client->fresh()->disabled_at);
    }

    public function test_an_admin_cannot_disable_their_own_account(): void
    {
        $company = Company::factory()->create();
        $admin = $this->userWithRole('admin');

        Livewire::actingAs($admin)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('disable', $admin->id)
            ->assertForbidden();

        $this->assertNull($admin->fresh()->disabled_at);
    }

    public function test_a_client_sees_the_list_but_cannot_open_an_account(): void
    {
        $company = Company::factory()->create();
        $client = $this->userWithRole('client', $company);

        Livewire::actingAs($client)
            ->test(CompanyUsers::class, ['company' => $company])
            ->assertOk()
            ->assertSee($client->email)
            ->set('newName', 'Нов Некој')
            ->set('newEmail', 'nov@primer.mk')
            ->call('addUser')
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'nov@primer.mk']);
    }

    public function test_an_accountant_cannot_open_an_account(): void
    {
        $company = Company::factory()->create();
        $accountant = $this->userWithRole('accountant');
        $company->accountants()->attach($accountant);

        Livewire::actingAs($accountant)
            ->test(CompanyUsers::class, ['company' => $company])
            ->set('newName', 'Нов Некој')
            ->set('newEmail', 'nov@primer.mk')
            ->call('addUser')
            ->assertForbidden();
    }

    public function test_a_client_cannot_reach_another_companys_users(): void
    {
        $mine = Company::factory()->create();
        $others = Company::factory()->create();

        $this->actingAs($this->userWithRole('client', $mine))
            ->get(route('companies.users', $others))
            ->assertForbidden();
    }

    public function test_the_list_shows_only_this_companys_users(): void
    {
        $company = Company::factory()->create();
        $mine = $this->userWithRole('client', $company);
        $stranger = $this->userWithRole('client', Company::factory()->create());

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(CompanyUsers::class, ['company' => $company])
            ->assertSee($mine->email)
            ->assertDontSee($stranger->email);
    }
}
```

- [ ] **Step 6: Пушти го и потврди дека паѓа**

Run: `php artisan test --filter=CompanyUsersTest`
Expected: FAIL — класата `App\Livewire\CompanyUsers` не постои.

- [ ] **Step 7: Напиши го правилото**

Create `app/Policies/UserPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Секој ја гледа листата на својата фирма — самиот екран потоа е ограничен
     * со CompanyPolicy::view врз фирмата.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function invite(User $user, User $target): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * Админ не може да си го одземе сопствениот пристап — тоа е единствениот
     * начин да се остане без ниту една сметка што може да отвора сметки.
     */
    public function disable(User $user, User $target): bool
    {
        return $user->hasRole('admin') && ! $user->is($target);
    }
}
```

- [ ] **Step 8: Направи ја лентата со картички**

Create `resources/views/components/tab-strip.blade.php`:

```blade
@props(['tabs'])

<nav class="flex gap-1 border-b border-gray-200 mb-4">
    @foreach ($tabs as $tab)
        <a href="{{ $tab['url'] }}" wire:navigate
           class="px-4 py-2 text-sm font-medium rounded-t-lg {{ $tab['active']
               ? 'bg-brand text-white'
               : 'text-gray-600 hover:bg-orange-50' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>
```

- [ ] **Step 9: Напиши ја компонентата**

Create `app/Livewire/CompanyUsers.php`:

```php
<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use App\Support\CompanyTabs;
use App\Support\UserInvitations;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CompanyUsers extends Component
{
    public Company $company;

    public string $newName = '';

    public string $newEmail = '';

    /** Линкот се гледа само еднаш, веднаш по создавањето — не се чува. */
    public ?string $inviteLink = null;

    public string $invitedName = '';

    public bool $inviteMailSent = false;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;
    }

    public function addUser(): void
    {
        Gate::authorize('create', User::class);

        $validated = $this->validate([
            'newName' => 'required|string|max:255',
            'newEmail' => 'required|email|max:255|unique:users,email',
        ], [
            // Намерно не кажува во која фирма е таа адреса.
            'newEmail.unique' => 'Оваа е-пошта веќе има сметка во порталот.',
        ]);

        $user = User::create([
            'name' => $validated['newName'],
            'email' => $validated['newEmail'],
            // Со оваа лозинка не може да се влезе. Се поставува вистинска дури
            // преку поканата.
            'password' => Str::random(64),
        ]);

        // company_id не е во #[Fillable] на моделот.
        $user->forceFill(['company_id' => $this->company->id])->save();
        $user->assignRole('client');

        $this->sendInvitation($user);

        $this->reset(['newName', 'newEmail']);
    }

    public function reinvite(int $userId): void
    {
        $user = $this->companyUser($userId);

        Gate::authorize('invite', $user);

        $this->sendInvitation($user);
    }

    public function disable(int $userId): void
    {
        $user = $this->companyUser($userId);

        Gate::authorize('disable', $user);

        $user->forceFill(['disabled_at' => now()])->save();
    }

    public function enable(int $userId): void
    {
        $user = $this->companyUser($userId);

        Gate::authorize('disable', $user);

        $user->forceFill(['disabled_at' => null])->save();
    }

    /**
     * Поканата се прави прва, пораката втора: испраќањето по е-пошта е обид, не
     * услов. Ако поштата на серверот не е поставена, сметката и линкот остануваат.
     */
    private function sendInvitation(User $user): void
    {
        $this->inviteLink = UserInvitations::issue($user, auth()->user());
        $this->invitedName = $user->name;

        try {
            $user->notify(new UserInvitationNotification($this->inviteLink));
            $this->inviteMailSent = true;
        } catch (\Throwable $e) {
            report($e);
            $this->inviteMailSent = false;
        }
    }

    /**
     * Никогаш не се работи со корисник надвор од оваа фирма, ниту кога бројот
     * дојде преку жица.
     */
    private function companyUser(int $userId): User
    {
        return User::where('company_id', $this->company->id)->findOrFail($userId);
    }

    public function render()
    {
        return view('livewire.company-users', [
            'users' => User::with('latestInvitation')
                ->where('company_id', $this->company->id)
                ->orderBy('name')
                ->get(),
            'tabs' => CompanyTabs::for(auth()->user(), $this->company, 'companies.users'),
        ]);
    }
}
```

- [ ] **Step 10: Направи го екранот**

Create `resources/views/livewire/company-users.blade.php`:

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">{{ $company->name }}</h1>

    <x-tab-strip :tabs="$tabs" />

    @if ($inviteLink)
        <x-card class="mb-6 border-2 border-brand">
            <h2 class="font-semibold text-gray-700 mb-1">Покана за {{ $invitedName }}</h2>
            <p class="text-sm text-gray-600 mb-2">
                @if ($inviteMailSent)
                    Пораката е испратена по е-пошта. Линкот важи {{ \App\Support\UserInvitations::DAYS_VALID }} дена.
                @else
                    Пораката не можеше да се испрати по е-пошта. Испратете го линкот рачно — важи
                    {{ \App\Support\UserInvitations::DAYS_VALID }} дена.
                @endif
            </p>
            {{-- x-data е потребно за да работат $refs во Alpine. --}}
            <div x-data class="flex gap-2 items-center">
                <input type="text" readonly value="{{ $inviteLink }}" x-ref="link"
                       class="flex-1 border-gray-300 rounded-md text-sm bg-gray-50">
                <x-secondary-button type="button"
                                    x-on:click="navigator.clipboard.writeText($refs.link.value)">
                    Копирај
                </x-secondary-button>
            </div>
            <p class="text-xs text-gray-500 mt-2">Линкот се прикажува само сега. Подоцна се издава нов.</p>
        </x-card>
    @endif

    @can('create', \App\Models\User::class)
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Отвори сметка</h2>
            <form wire:submit="addUser" class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[14rem]">
                    <x-input-label for="newName" value="Име и презиме" />
                    <x-text-input id="newName" wire:model="newName" class="w-full" />
                    @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="flex-1 min-w-[14rem]">
                    <x-input-label for="newEmail" value="Е-пошта" />
                    <x-text-input id="newEmail" wire:model="newEmail" class="w-full" />
                    @error('newEmail') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <x-primary-button>Отвори и покани</x-primary-button>
            </form>
        </x-card>
    @endcan

    <x-card>
        <h2 class="font-semibold text-gray-700 mb-2">Сметки на оваа фирма</h2>
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr class="border-b">
                    <th class="py-1">Име</th>
                    <th class="py-1">Е-пошта</th>
                    <th class="py-1">Состојба</th>
                    <th class="py-1"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr class="border-b last:border-0">
                        <td class="py-1">{{ $user->name }}</td>
                        <td class="py-1">{{ $user->email }}</td>
                        <td class="py-1">
                            @switch($user->accessStatus())
                                @case('invited')
                                    <x-badge>Поканет — важи до
                                        {{ $user->latestInvitation->expires_at->format('d.m.Y') }}</x-badge>
                                    @break
                                @case('invitation_expired')
                                    <x-badge>Поканата истече</x-badge>
                                    @break
                                @case('disabled')
                                    <x-badge>Исклучен</x-badge>
                                    @break
                                @default
                                    <x-badge>Активен</x-badge>
                            @endswitch
                        </td>
                        <td class="py-1 text-right">
                            @can('disable', $user)
                                <div class="flex gap-2 justify-end">
                                    <button type="button" wire:click="reinvite({{ $user->id }})"
                                            class="text-brand hover:underline">Издај нова покана</button>
                                    @if ($user->disabled_at === null)
                                        <button type="button" wire:click="disable({{ $user->id }})"
                                                class="text-red-600 hover:underline">Исклучи пристап</button>
                                    @else
                                        <button type="button" wire:click="enable({{ $user->id }})"
                                                class="text-brand hover:underline">Врати пристап</button>
                                    @endif
                                </div>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-2 text-gray-500">Нема отворени сметки за оваа фирма.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>
</div>
```

- [ ] **Step 11: Регистрирај ја рутата**

Modify `routes/web.php` — додај го увозот `use App\Livewire\CompanyUsers;` кај останатите и додај ја рутата во постоечката `companies/{company}` група:

```php
    Route::get('/users', [CompanyUsers::class, '__invoke'])->name('companies.users');
```

- [ ] **Step 12: Пушти ги тестовите**

Run: `php artisan test --filter="CompanyUsersTest|CompanyTabsTest"`
Expected: PASS (13 тестови).

- [ ] **Step 13: Коммит**

```bash
git add app/Policies/UserPolicy.php app/Support/CompanyTabs.php app/Livewire/CompanyUsers.php resources/views/components/tab-strip.blade.php resources/views/livewire/company-users.blade.php routes/web.php tests
git commit -m "feat(users): картичка Корисници кај фирма со покана и гасење пристап"
```

---

### Task 5: Доделување сметководител на фирма

**Files:**
- Modify: `app/Livewire/CompanyUsers.php` (два нови методи + `render`)
- Modify: `resources/views/livewire/company-users.blade.php` (нова секција)
- Create: `tests/Feature/Users/CompanyAccountantsTest.php`

**Interfaces:**
- Consumes: `App\Livewire\CompanyUsers` од Задача 4, `Company::accountants()` (постоечка `belongsToMany` врска).
- Produces: методите `assignAccountant(int $userId)` и `removeAccountant(int $userId)` на истата компонента.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Create `tests/Feature/Users/CompanyAccountantsTest.php`:

```php
<?php

namespace Tests\Feature\Users;

use App\Livewire\CompanyUsers;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyAccountantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_an_admin_assigns_and_removes_an_accountant(): void
    {
        $company = Company::factory()->create();
        $accountant = $this->userWithRole('accountant');

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('assignAccountant', $accountant->id)
            ->assertHasNoErrors();

        $this->assertTrue($company->fresh()->accountants->contains($accountant));

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('removeAccountant', $accountant->id)
            ->assertHasNoErrors();

        $this->assertFalse($company->fresh()->accountants->contains($accountant));
    }

    public function test_assigning_twice_does_not_duplicate_the_row(): void
    {
        $company = Company::factory()->create();
        $accountant = $this->userWithRole('accountant');

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('assignAccountant', $accountant->id)
            ->call('assignAccountant', $accountant->id);

        $this->assertSame(1, $company->fresh()->accountants->count());
    }

    public function test_only_accountants_can_be_assigned(): void
    {
        $company = Company::factory()->create();
        $client = $this->userWithRole('client');

        // findOrFail фрла, не враќа одговор — затоа исклучокот се фаќа, а
        // тврдењето за базата останува во finally.
        $this->expectException(ModelNotFoundException::class);

        try {
            Livewire::actingAs($this->userWithRole('admin'))
                ->test(CompanyUsers::class, ['company' => $company])
                ->call('assignAccountant', $client->id);
        } finally {
            $this->assertSame(0, $company->fresh()->accountants->count());
        }
    }

    public function test_a_client_cannot_assign_an_accountant(): void
    {
        $company = Company::factory()->create();
        $client = $this->userWithRole('client');
        $client->forceFill(['company_id' => $company->id])->save();
        $accountant = $this->userWithRole('accountant');

        Livewire::actingAs($client)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('assignAccountant', $accountant->id)
            ->assertForbidden();

        $this->assertSame(0, $company->fresh()->accountants->count());
    }
}
```

- [ ] **Step 2: Пушти ги и потврди дека паѓаат**

Run: `php artisan test --filter=CompanyAccountantsTest`
Expected: FAIL — методот `assignAccountant` не постои.

- [ ] **Step 3: Додај ги методите**

Modify `app/Livewire/CompanyUsers.php` — додај ги по `enable()`:

```php
    public function assignAccountant(int $userId): void
    {
        Gate::authorize('create', User::class);

        $accountant = User::role('accountant')->findOrFail($userId);

        // syncWithoutDetaching, не attach: двоен клик не смее да остави два реда.
        $this->company->accountants()->syncWithoutDetaching([$accountant->id]);
    }

    public function removeAccountant(int $userId): void
    {
        Gate::authorize('create', User::class);

        $this->company->accountants()->detach($userId);
    }
```

- [ ] **Step 4: Дај ги списоците на екранот**

Modify `app/Livewire/CompanyUsers.php` — во `render()`, додај два клуча во низата што се предава на прегледот:

```php
            'assigned' => $this->company->accountants()->orderBy('name')->get(),
            'available' => User::role('accountant')
                ->whereDoesntHave('assignedCompanies', fn ($query) => $query->whereKey($this->company->id))
                ->orderBy('name')
                ->get(),
```

Modify `resources/views/livewire/company-users.blade.php` — додај ја оваа картичка на крајот, пред затворањето на `</div>`:

```blade
    <x-card class="mt-6">
        <h2 class="font-semibold text-gray-700 mb-2">Сметководители на оваа фирма</h2>

        <ul class="text-sm divide-y">
            @forelse ($assigned as $accountant)
                <li class="py-1 flex justify-between items-center">
                    <span>{{ $accountant->name }} <span class="text-gray-500">({{ $accountant->email }})</span></span>
                    @can('create', \App\Models\User::class)
                        <button type="button" wire:click="removeAccountant({{ $accountant->id }})"
                                class="text-red-600 hover:underline">Тргни</button>
                    @endcan
                </li>
            @empty
                <li class="py-1 text-gray-500">Нема доделен сметководител.</li>
            @endforelse
        </ul>

        @can('create', \App\Models\User::class)
            @if ($available->isNotEmpty())
                <div class="mt-3 flex gap-2 items-center">
                    <select wire:model="accountantToAssign" class="border-gray-300 rounded-md text-sm">
                        <option value="">— изберете —</option>
                        @foreach ($available as $accountant)
                            <option value="{{ $accountant->id }}">{{ $accountant->name }}</option>
                        @endforeach
                    </select>
                    {{-- Само еден начин на повикување: бројот доаѓа од
                         својството, не од Blade израз. --}}
                    <div x-data>
                        <x-secondary-button type="button"
                                            x-on:click="$wire.assignAccountant($wire.accountantToAssign)">
                            Додели
                        </x-secondary-button>
                    </div>
                </div>
            @endif
        @endcan
    </x-card>
```

Modify `app/Livewire/CompanyUsers.php` — додај го својството што го користи изборникот, кај останатите:

```php
    public string $accountantToAssign = '';
```

и на крајот од `assignAccountant()` додај `$this->accountantToAssign = '';` за изборникот да се врати на празно по доделувањето.

- [ ] **Step 5: Пушти ги тестовите**

Run: `php artisan test --filter="CompanyAccountantsTest|CompanyUsersTest"`
Expected: PASS (14 тестови).

- [ ] **Step 6: Коммит**

```bash
git add app/Livewire/CompanyUsers.php resources/views/livewire/company-users.blade.php tests/Feature/Users/CompanyAccountantsTest.php
git commit -m "feat(users): доделување сметководител на фирма од картичката Корисници"
```

---

### Task 6: Картичка „Канцеларија"

**Files:**
- Create: `app/Livewire/OfficeUsers.php`
- Create: `resources/views/livewire/office-users.blade.php`
- Create: `tests/Feature/Users/OfficeUsersTest.php`
- Modify: `routes/web.php` (нова рута `companies.office`)
- Modify: `resources/views/livewire/company-index.blade.php` (лента со две картички на врвот)

**Interfaces:**
- Consumes: `UserInvitations::issue()`, `UserInvitationNotification`, `UserPolicy`, `<x-tab-strip>`.
- Produces: рутата `companies.office` (`companies/office`) и `App\Livewire\OfficeUsers` со `addUser()`, `reinvite(int $userId)`, `disable(int $userId)`, `enable(int $userId)`.

Рутата `companies/office` мора да стои **пред** групата `companies/{company}` во `routes/web.php`, инаку `office` би бил фатен како параметар на фирма.

- [ ] **Step 1: Напиши ги тестовите што паѓаат**

Create `tests/Feature/Users/OfficeUsersTest.php`:

```php
<?php

namespace Tests\Feature\Users;

use App\Livewire\OfficeUsers;
use App\Models\Company;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OfficeUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_an_admin_opens_an_accountant_account(): void
    {
        Notification::fake();

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(OfficeUsers::class)
            ->set('newName', 'Ана Стојанова')
            ->set('newEmail', 'ana@financebuddy.mk')
            ->set('newRole', 'accountant')
            ->call('addUser')
            ->assertHasNoErrors();

        $created = User::where('email', 'ana@financebuddy.mk')->firstOrFail();

        $this->assertTrue($created->hasRole('accountant'));
        $this->assertNull($created->company_id);
        $this->assertSame('invited', $created->accessStatus());

        Notification::assertSentTo($created, UserInvitationNotification::class);
    }

    public function test_an_admin_opens_another_admin_account(): void
    {
        Notification::fake();

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(OfficeUsers::class)
            ->set('newName', 'Втор Админ')
            ->set('newEmail', 'admin2@financebuddy.mk')
            ->set('newRole', 'admin')
            ->call('addUser')
            ->assertHasNoErrors();

        $this->assertTrue(User::where('email', 'admin2@financebuddy.mk')->firstOrFail()->hasRole('admin'));
    }

    public function test_the_client_role_cannot_be_opened_here(): void
    {
        Livewire::actingAs($this->userWithRole('admin'))
            ->test(OfficeUsers::class)
            ->set('newName', 'Клиент Некој')
            ->set('newEmail', 'klient@primer.mk')
            ->set('newRole', 'client')
            ->call('addUser')
            ->assertHasErrors('newRole');

        $this->assertDatabaseMissing('users', ['email' => 'klient@primer.mk']);
    }

    public function test_the_list_shows_office_accounts_and_not_clients(): void
    {
        $accountant = $this->userWithRole('accountant');
        $client = $this->userWithRole('client');
        $client->forceFill(['company_id' => Company::factory()->create()->id])->save();

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(OfficeUsers::class)
            ->assertSee($accountant->email)
            ->assertDontSee($client->email);
    }

    public function test_a_client_cannot_reach_the_office_screen(): void
    {
        $client = $this->userWithRole('client');
        $client->forceFill(['company_id' => Company::factory()->create()->id])->save();

        $this->actingAs($client)->get(route('companies.office'))->assertForbidden();
    }

    public function test_an_accountant_cannot_reach_the_office_screen(): void
    {
        $this->actingAs($this->userWithRole('accountant'))
            ->get(route('companies.office'))
            ->assertForbidden();
    }

    public function test_an_admin_disables_an_office_account(): void
    {
        $accountant = $this->userWithRole('accountant');

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(OfficeUsers::class)
            ->call('disable', $accountant->id)
            ->assertHasNoErrors();

        $this->assertNotNull($accountant->fresh()->disabled_at);
    }
}
```

- [ ] **Step 2: Пушти ги и потврди дека паѓаат**

Run: `php artisan test --filter=OfficeUsersTest`
Expected: FAIL — класата `App\Livewire\OfficeUsers` не постои.

- [ ] **Step 3: Напиши ја компонентата**

Create `app/Livewire/OfficeUsers.php`:

```php
<?php

namespace App\Livewire;

use App\Models\User;
use App\Notifications\UserInvitationNotification;
use App\Support\UserInvitations;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Сметките на канцеларијата — админи и сметководители. Тие не припаѓаат на
 * фирма, затоа не седат на картичките кај клиент.
 */
#[Layout('layouts.app')]
class OfficeUsers extends Component
{
    public const ROLES = ['accountant' => 'Сметководител', 'admin' => 'Админ'];

    public string $newName = '';

    public string $newEmail = '';

    public string $newRole = 'accountant';

    public ?string $inviteLink = null;

    public string $invitedName = '';

    public bool $inviteMailSent = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('admin'), 403);
    }

    public function addUser(): void
    {
        Gate::authorize('create', User::class);

        $validated = $this->validate([
            'newName' => 'required|string|max:255',
            'newEmail' => 'required|email|max:255|unique:users,email',
            // Клиентска сметка се отвора само од картичката кај својата фирма,
            // за да не може да настане клиент без фирма.
            'newRole' => ['required', Rule::in(array_keys(self::ROLES))],
        ], [
            'newEmail.unique' => 'Оваа е-пошта веќе има сметка во порталот.',
            'newRole.in' => 'Клиентска сметка се отвора кај самата фирма.',
        ]);

        $user = User::create([
            'name' => $validated['newName'],
            'email' => $validated['newEmail'],
            'password' => Str::random(64),
        ]);

        $user->assignRole($validated['newRole']);

        $this->inviteLink = UserInvitations::issue($user, auth()->user());
        $this->invitedName = $user->name;

        try {
            $user->notify(new UserInvitationNotification($this->inviteLink));
            $this->inviteMailSent = true;
        } catch (\Throwable $e) {
            report($e);
            $this->inviteMailSent = false;
        }

        $this->reset(['newName', 'newEmail']);
    }

    public function reinvite(int $userId): void
    {
        $user = $this->officeUser($userId);

        Gate::authorize('invite', $user);

        $this->inviteLink = UserInvitations::issue($user, auth()->user());
        $this->invitedName = $user->name;

        try {
            $user->notify(new UserInvitationNotification($this->inviteLink));
            $this->inviteMailSent = true;
        } catch (\Throwable $e) {
            report($e);
            $this->inviteMailSent = false;
        }
    }

    public function disable(int $userId): void
    {
        $user = $this->officeUser($userId);

        Gate::authorize('disable', $user);

        $user->forceFill(['disabled_at' => now()])->save();
    }

    public function enable(int $userId): void
    {
        $user = $this->officeUser($userId);

        Gate::authorize('disable', $user);

        $user->forceFill(['disabled_at' => null])->save();
    }

    private function officeUser(int $userId): User
    {
        return User::role(array_keys(self::ROLES))->findOrFail($userId);
    }

    public function render()
    {
        return view('livewire.office-users', [
            'users' => User::with(['latestInvitation', 'roles'])
                ->role(array_keys(self::ROLES))
                ->orderBy('name')
                ->get(),
        ]);
    }
}
```

- [ ] **Step 4: Направи го екранот**

Create `resources/views/livewire/office-users.blade.php`:

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Фирми</h1>

    <x-tab-strip :tabs="[
        ['label' => 'Клиенти', 'url' => route('companies.index'), 'active' => false],
        ['label' => 'Канцеларија', 'url' => route('companies.office'), 'active' => true],
    ]" />

    @if ($inviteLink)
        <x-card class="mb-6 border-2 border-brand">
            <h2 class="font-semibold text-gray-700 mb-1">Покана за {{ $invitedName }}</h2>
            <p class="text-sm text-gray-600 mb-2">
                @if ($inviteMailSent)
                    Пораката е испратена по е-пошта. Линкот важи {{ \App\Support\UserInvitations::DAYS_VALID }} дена.
                @else
                    Пораката не можеше да се испрати по е-пошта. Испратете го линкот рачно — важи
                    {{ \App\Support\UserInvitations::DAYS_VALID }} дена.
                @endif
            </p>
            {{-- x-data е потребно за да работат $refs во Alpine. --}}
            <div x-data class="flex gap-2 items-center">
                <input type="text" readonly value="{{ $inviteLink }}" x-ref="link"
                       class="flex-1 border-gray-300 rounded-md text-sm bg-gray-50">
                <x-secondary-button type="button"
                                    x-on:click="navigator.clipboard.writeText($refs.link.value)">
                    Копирај
                </x-secondary-button>
            </div>
            <p class="text-xs text-gray-500 mt-2">Линкот се прикажува само сега. Подоцна се издава нов.</p>
        </x-card>
    @endif

    <x-card class="mb-6">
        <h2 class="font-semibold text-gray-700 mb-2">Отвори сметка</h2>
        <form wire:submit="addUser" class="flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[14rem]">
                <x-input-label for="newName" value="Име и презиме" />
                <x-text-input id="newName" wire:model="newName" class="w-full" />
                @error('newName') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div class="flex-1 min-w-[14rem]">
                <x-input-label for="newEmail" value="Е-пошта" />
                <x-text-input id="newEmail" wire:model="newEmail" class="w-full" />
                @error('newEmail') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="newRole" value="Улога" />
                <select id="newRole" wire:model="newRole" class="border-gray-300 rounded-md text-sm">
                    @foreach (\App\Livewire\OfficeUsers::ROLES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('newRole') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <x-primary-button>Отвори и покани</x-primary-button>
        </form>
    </x-card>

    <x-card>
        <h2 class="font-semibold text-gray-700 mb-2">Сметки на канцеларијата</h2>
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr class="border-b">
                    <th class="py-1">Име</th>
                    <th class="py-1">Е-пошта</th>
                    <th class="py-1">Улога</th>
                    <th class="py-1">Состојба</th>
                    <th class="py-1"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr class="border-b last:border-0">
                        <td class="py-1">{{ $user->name }}</td>
                        <td class="py-1">{{ $user->email }}</td>
                        <td class="py-1">
                            {{ \App\Livewire\OfficeUsers::ROLES[$user->roles->first()?->name] ?? '' }}
                        </td>
                        <td class="py-1">
                            @switch($user->accessStatus())
                                @case('invited')
                                    <x-badge>Поканет — важи до
                                        {{ $user->latestInvitation->expires_at->format('d.m.Y') }}</x-badge>
                                    @break
                                @case('invitation_expired')
                                    <x-badge>Поканата истече</x-badge>
                                    @break
                                @case('disabled')
                                    <x-badge>Исклучен</x-badge>
                                    @break
                                @default
                                    <x-badge>Активен</x-badge>
                            @endswitch
                        </td>
                        <td class="py-1 text-right">
                            @can('disable', $user)
                                <div class="flex gap-2 justify-end">
                                    <button type="button" wire:click="reinvite({{ $user->id }})"
                                            class="text-brand hover:underline">Издај нова покана</button>
                                    @if ($user->disabled_at === null)
                                        <button type="button" wire:click="disable({{ $user->id }})"
                                                class="text-red-600 hover:underline">Исклучи пристап</button>
                                    @else
                                        <button type="button" wire:click="enable({{ $user->id }})"
                                                class="text-brand hover:underline">Врати пристап</button>
                                    @endif
                                </div>
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-card>
</div>
```

- [ ] **Step 5: Регистрирај ја рутата пред групата со параметар**

Modify `routes/web.php` — додај го увозот `use App\Livewire\OfficeUsers;` и стави ја рутата непосредно **пред** `Route::middleware(['auth'])->prefix('companies/{company}')`:

```php
// Мора да стои пред групата `companies/{company}`, инаку 'office' би бил фатен
// како фирма.
Route::middleware(['auth'])->get('/companies/office', [OfficeUsers::class, '__invoke'])
    ->name('companies.office');
```

- [ ] **Step 6: Стави ја лентата и на екранот „Клиенти"**

Modify `resources/views/livewire/company-index.blade.php` — веднаш по насловот `<h1 ...>Фирми</h1>`:

```blade
    <x-tab-strip :tabs="[
        ['label' => 'Клиенти', 'url' => route('companies.index'), 'active' => true],
        ['label' => 'Канцеларија', 'url' => route('companies.office'), 'active' => false],
    ]" />
```

- [ ] **Step 7: Пушти ги тестовите**

Run: `php artisan test --filter="OfficeUsersTest|CompanyIndexTest"`
Expected: PASS. Ако `CompanyIndexTest` падне поради лентата, причината е реална и се поправа во кодот — не се менува тестот.

- [ ] **Step 8: Коммит**

```bash
git add app/Livewire/OfficeUsers.php resources/views/livewire/office-users.blade.php resources/views/livewire/company-index.blade.php routes/web.php tests/Feature/Users/OfficeUsersTest.php
git commit -m "feat(users): картичка Канцеларија за сметките на админи и сметководители"
```

---

### Task 7: Картичка „Модули"

**Files:**
- Create: `app/Livewire/CompanyModules.php`
- Create: `resources/views/livewire/company-modules.blade.php`
- Modify: `routes/web.php` (рута `companies.modules`)
- Modify: `app/Livewire/CompanyProfile.php` (се вадат четирите својства, редовите во `startEdit`, правилата и запишувањето)
- Modify: `resources/views/livewire/company-profile.blade.php:205-225` (се вади блокот со штиклирања, се додава лентата)
- Modify: `tests/Feature/CompanyProfileModulesTest.php` → преименувано во `tests/Feature/Users/CompanyModulesScreenTest.php`

**Interfaces:**
- Consumes: `CompanyTabs::for()`, `<x-tab-strip>`.
- Produces: рутата `companies.modules` и `App\Livewire\CompanyModules` со `save()`.

Правилото „Залиха без Материјално не постои" се пренесува буквално од `CompanyProfile::save()` — не се измислува одново.

- [ ] **Step 1: Пресели ги постоечките тестови на новиот екран**

```bash
git mv tests/Feature/CompanyProfileModulesTest.php tests/Feature/Users/CompanyModulesScreenTest.php
```

Modify `tests/Feature/Users/CompanyModulesScreenTest.php`:
- namespace → `Tests\Feature\Users`
- класа → `CompanyModulesScreenTest`
- `use App\Livewire\CompanyProfile;` → `use App\Livewire\CompanyModules;`
- секое `->test(CompanyProfile::class, ...)` → `->test(CompanyModules::class, ...)`
- секое `->call('startEdit')` се брише (новиот екран нема режим на уредување)
- `editUsesMaterial` / `editUsesStock` / `editUsesPayroll` / `editUsesFinance` → `usesMaterial` / `usesStock` / `usesPayroll` / `usesFinance`

Тврдењата за состојбата на базата остануваат непроменети — тие се смислата на овие тестови.

- [ ] **Step 2: Додај два тестови за правата**

Додај ги во истата датотека:

```php
    public function test_a_client_cannot_reach_the_modules_tab(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create();
        Role::findOrCreate('client');
        $client->assignRole('client');
        $client->forceFill(['company_id' => $company->id])->save();

        $this->actingAs($client)->get(route('companies.modules', $company))->assertForbidden();
    }

    public function test_an_accountant_cannot_reach_the_modules_tab(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');

        $this->actingAs($accountant)->get(route('companies.modules', $company))->assertForbidden();
    }
```

- [ ] **Step 3: Пушти ги и потврди дека паѓаат**

Run: `php artisan test --filter=CompanyModulesScreenTest`
Expected: FAIL — класата `App\Livewire\CompanyModules` не постои.

- [ ] **Step 4: Напиши ја компонентата**

Create `app/Livewire/CompanyModules.php`:

```php
<?php

namespace App\Livewire;

use App\Models\Company;
use App\Support\CompanyTabs;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Што користи клиентот. Извадено од CompanyProfile: тоа е одлука на
 * канцеларијата за опфатот на услугата, не податок за фирмата, а и единственото
 * во таа форма што го менува менито.
 */
#[Layout('layouts.app')]
class CompanyModules extends Component
{
    public Company $company;

    public bool $usesMaterial = true;

    public bool $usesStock = true;

    public bool $usesPayroll = true;

    public bool $usesFinance = true;

    public bool $saved = false;

    public function mount(Company $company): void
    {
        // Модулите ги менува само админ — истото правило како за профилот.
        Gate::authorize('update', $company);

        $this->company = $company;
        $this->usesMaterial = $company->uses_material;
        $this->usesStock = $company->uses_stock;
        $this->usesPayroll = $company->uses_payroll;
        $this->usesFinance = $company->uses_finance;
    }

    public function save(): void
    {
        Gate::authorize('update', $this->company);

        $validated = $this->validate([
            'usesMaterial' => 'boolean',
            'usesStock' => 'boolean',
            'usesPayroll' => 'boolean',
            'usesFinance' => 'boolean',
        ]);

        $this->company->forceFill([
            'uses_material' => $validated['usesMaterial'],
            // Залиха без Материјално не постои — истото правило како при
            // создавање фирма.
            'uses_stock' => $validated['usesMaterial'] && $validated['usesStock'],
            'uses_payroll' => $validated['usesPayroll'],
            'uses_finance' => $validated['usesFinance'],
        ])->save();

        $this->saved = true;
    }

    public function render()
    {
        return view('livewire.company-modules', [
            'tabs' => CompanyTabs::for(auth()->user(), $this->company, 'companies.modules'),
        ]);
    }
}
```

- [ ] **Step 5: Направи го екранот**

Create `resources/views/livewire/company-modules.blade.php`:

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">{{ $company->name }}</h1>

    <x-tab-strip :tabs="$tabs" />

    <x-card>
        <h2 class="font-semibold text-gray-700 mb-1">Што користи клиентот</h2>
        <p class="text-sm text-gray-600 mb-3">
            Исклучен модул исчезнува од менито и неговите екрани стануваат недостапни.
        </p>

        <form wire:submit="save" class="space-y-2">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model.live="usesMaterial">
                Материјално работење
            </label>
            <label class="flex items-center gap-2 text-sm ms-6 {{ $usesMaterial ? '' : 'text-gray-400' }}">
                <input type="checkbox" wire:model="usesStock" @disabled(! $usesMaterial)>
                Залиха
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="usesPayroll">
                Плата
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="usesFinance">
                Финансии
            </label>

            <div class="pt-3 flex items-center gap-3">
                <x-primary-button>Зачувај</x-primary-button>
                @if ($saved)
                    <span class="text-sm text-gray-600">Зачувано.</span>
                @endif
            </div>
        </form>
    </x-card>
</div>
```

- [ ] **Step 6: Регистрирај ја рутата**

Modify `routes/web.php` — во групата `companies/{company}`, до `companies.users`:

```php
    Route::get('/modules', [CompanyModules::class, '__invoke'])->name('companies.modules');
```

и додај го увозот `use App\Livewire\CompanyModules;`.

- [ ] **Step 7: Извади ги модулите од профилот**

Modify `app/Livewire/CompanyProfile.php` — избриши ги:
- својствата `$editUsesMaterial`, `$editUsesStock`, `$editUsesPayroll`, `$editUsesFinance` (редови 62-68)
- четирите доделувања во `startEdit()` (редови 112-115)
- четирите правила во `save()` (редови 243-246)
- четирите доделувања во `$companyData` (редови 308-313), заедно со коментарот што се однесува само на нив

Modify `resources/views/livewire/company-profile.blade.php`:
- избриши го блокот со штиклирањата за модули (околу редови 205-225), заедно со насловот на таа секција
- по насловот на екранот додај `<x-tab-strip :tabs="$tabs" />`

Modify `app/Livewire/CompanyProfile.php` — во `render()`, додај го клучот `tabs` во низата што се предава:

```php
            'tabs' => CompanyTabs::for(auth()->user(), $this->company, 'companies.profile'),
```

и увозот `use App\Support\CompanyTabs;`.

- [ ] **Step 8: Пушти ги тестовите на екраните околу профилот**

Run: `php artisan test --filter="CompanyModulesScreenTest|CompanyProfile"`
Expected: PASS. Тест што паѓа затоа што сѐ уште поставува `editUses*` врз `CompanyProfile` се преместува на новиот екран; тест што паѓа од друга причина е вистински дефект и се поправа во кодот.

- [ ] **Step 9: Коммит**

```bash
git add app/Livewire/CompanyModules.php app/Livewire/CompanyProfile.php resources/views/livewire/company-modules.blade.php resources/views/livewire/company-profile.blade.php routes/web.php tests
git commit -m "feat(users): модулите добиваат своја картичка, извадени од профилот"
```

---

### Task 8: Скратена форма за нов клиент и полн тест-сет

**Files:**
- Modify: `app/Livewire/CompanyIndex.php:17-128`
- Modify: `resources/views/livewire/company-index.blade.php` (се вадат контакт-полињата)
- Modify: `tests/Feature/CompanyIndexTest.php`, `tests/Feature/CompanyModulesTest.php` (тврдењата за отстранетите полиња)

**Interfaces:**
- Consumes: рутата `companies.profile` (постоечка).
- Produces: ништо ново — ова е последната задача.

- [ ] **Step 1: Напиши го тестот што паѓа**

Додај ги во `tests/Feature/CompanyIndexTest.php`:

```php
    public function test_creating_a_company_lands_on_its_profile(): void
    {
        Livewire::actingAs($this->admin())
            ->test(\App\Livewire\CompanyIndex::class)
            ->set('newType', \App\Support\CompanyType::LEGAL->value)
            ->set('newName', 'ТЕСТ ДООЕЛ')
            ->set('newTaxId', '4080012345678')
            ->call('addCompany')
            ->assertHasNoErrors()
            ->assertRedirect(route('companies.profile', \App\Models\Company::where('name', 'ТЕСТ ДООЕЛ')->firstOrFail()));
    }

    public function test_a_new_company_starts_with_every_module_on(): void
    {
        Livewire::actingAs($this->admin())
            ->test(\App\Livewire\CompanyIndex::class)
            ->set('newType', \App\Support\CompanyType::LEGAL->value)
            ->set('newName', 'ТЕСТ ДООЕЛ')
            ->call('addCompany');

        $company = \App\Models\Company::where('name', 'ТЕСТ ДООЕЛ')->firstOrFail();

        $this->assertTrue($company->uses_material);
        $this->assertTrue($company->uses_stock);
        $this->assertTrue($company->uses_payroll);
        $this->assertTrue($company->uses_finance);
        $this->assertTrue($company->is_vat_registered);
    }

    public function test_a_new_individual_is_not_vat_registered(): void
    {
        Livewire::actingAs($this->admin())
            ->test(\App\Livewire\CompanyIndex::class)
            ->set('newType', \App\Support\CompanyType::INDIVIDUAL->value)
            ->set('newName', 'Петар Петров')
            ->call('addCompany');

        $this->assertFalse(
            \App\Models\Company::where('name', 'Петар Петров')->firstOrFail()->is_vat_registered
        );
    }
```

Ако `CompanyIndexTest` нема помошен метод `admin()`, додај го по образецот од `tests/Feature/CompanyProfileModulesTest.php` (сега `tests/Feature/Users/CompanyModulesScreenTest.php`).

- [ ] **Step 2: Пушти го и потврди дека паѓа**

Run: `php artisan test --filter=CompanyIndexTest`
Expected: FAIL на пренасочувањето — `addCompany()` сѐ уште не пренасочува.

- [ ] **Step 3: Скрати ја компонентата**

Modify `app/Livewire/CompanyIndex.php`:
- избриши ги својствата `$newEmail`, `$newPhone`, `$newAddress`, `$newUsesMaterial`, `$newUsesStock`, `$newUsesPayroll`, `$newUsesFinance`
- избриши ги нивните правила од `validate()`
- замени го создавањето и `reset()` со:

```php
        // Ниту едно поле што зависи од типот не смее да остане на стандардна
        // вредност од базата — inaку физичко лице засекогаш останува ДДВ
        // обврзник и на фактурата излегува ДДВ што не постои. Причината е
        // опишана во docs/superpowers/specs/2026-08-21-client-profile-types-design.md.
        $company = Company::create([
            'name' => $validated['newName'],
            'type' => $type,
            'tax_id' => $isLegal ? ($validated['newTaxId'] ?: null) : null,
            'embg' => $isLegal ? null : ($validated['newEmbg'] ?: null),
            'is_vat_registered' => $isLegal,
            // Сите модули вклучени; се исклучуваат на картичката „Модули".
            'uses_material' => true,
            'uses_stock' => true,
            'uses_payroll' => true,
            'uses_finance' => true,
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM,
        ]);

        $this->reset(['newName', 'newType', 'newTaxId', 'newEmbg']);

        // Остатокот од податоците се дополнува на профилот — таму е и
        // единствената форма за нив.
        $this->redirect(route('companies.profile', $company), navigate: true);
```

Modify `resources/views/livewire/company-index.blade.php` — избриши ги полињата за е-пошта, телефон и адреса и (ако постои) блокот со штиклирања за модули во формата „Додади фирма". Полињата за тип, назив и ЕДБ/ЕМБГ остануваат непроменети, заедно со нивните коментари.

- [ ] **Step 4: Поправи ги затечените тестови**

Run: `php artisan test --filter="CompanyIndexTest|CompanyModulesTest"`

Тест што поставува `newEmail`, `newPhone`, `newAddress` или `newUses*` врз `CompanyIndex` се менува: тврдењето за контактите се брише (тие полиња веќе не се на таа форма), тврдењето за модулите се сведува на „сите се вклучени по создавање". Ниту еден друг тест не се допира.

Expected: PASS.

- [ ] **Step 5: Пушти го полниот тест-сет**

Run: `php artisan test`
Expected: PASS. Ова е првото и единственото пуштање на целиот сет во оваа гранка — пред него, побарај потврда од корисникот дека сега е моментот.

- [ ] **Step 6: Коммит**

```bash
git add app/Livewire/CompanyIndex.php resources/views/livewire/company-index.blade.php tests
git commit -m "feat(users): скратена форма за нов клиент со пренасочување на профилот"
```

---

## Што останува надвор од планот

- Поставување на поштата на серверот (`MAIL_*`). Ниту еден тек тука не зависи од неа, но „Ја заборавивте лозинката?" останува мртво додека не се постави.
- Промена на улога на постоечка сметка.
- Клиент што сам отвора корисници.
