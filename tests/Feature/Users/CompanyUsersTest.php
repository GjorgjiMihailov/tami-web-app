<?php

namespace Tests\Feature\Users;

use App\Livewire\CompanyUsers;
use App\Models\Company;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use App\Support\UserInvitations;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    /**
     * Регресија за наодот „cross-tenant user-existence enumeration": пред
     * поправката, `disable()` бараше корисник ГЛОБАЛНО (без опсег по фирма),
     * па дури потоа проверуваше правило и припадност на фирма. Тоа значеше
     * дека клиент што праќа ID на корисник од ТУЃА фирма добива поинаков
     * исход (403 — правилото пропаѓа, но корисникот е најден) отколку кога
     * праќа ID што воопшто не постои (404) — со тоа откривајќи дали некоја
     * сметка постои некаде во порталот. Двата исходи мора да бидат исти.
     */
    public function test_a_client_disabling_a_stranger_from_another_company_looks_like_disabling_a_nonexistent_id(): void
    {
        $mine = Company::factory()->create();
        $client = $this->userWithRole('client', $mine);
        $stranger = $this->userWithRole('client', Company::factory()->create());
        $nonexistentId = (int) User::query()->max('id') + 1000;

        $outcomeForStranger = $this->exceptionShapeOf(fn () => Livewire::actingAs($client)
            ->test(CompanyUsers::class, ['company' => $mine])
            ->call('disable', $stranger->id));

        $outcomeForNonexistent = $this->exceptionShapeOf(fn () => Livewire::actingAs($client)
            ->test(CompanyUsers::class, ['company' => $mine])
            ->call('disable', $nonexistentId));

        $this->assertSame(ModelNotFoundException::class, $outcomeForStranger);
        $this->assertSame($outcomeForNonexistent, $outcomeForStranger);
    }

    public function test_an_admin_can_reinvite_a_user_and_the_previous_link_stops_working(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $admin = $this->userWithRole('admin');
        $client = $this->userWithRole('client', $company);

        $oldLink = UserInvitations::issue($client, $admin);
        $oldToken = basename(parse_url($oldLink, PHP_URL_PATH));
        $this->assertNotNull(UserInvitations::find($oldToken));

        Livewire::actingAs($admin)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('reinvite', $client->id)
            ->assertHasNoErrors();

        $this->assertNull(UserInvitations::find($oldToken));
        Notification::assertSentTo($client, UserInvitationNotification::class);
    }

    /**
     * Регресија (наод I1): покана за исклучена сметка `UserInvitations::accept()`
     * секогаш ја одбива (disabled_at !== null), додека екранот и пораката
     * тврдат дека линкот важи 7 дена — а издавањето уште и ја брише
     * претходната сѐ уште важечка покана. Затоа `invite` мора да пропадне
     * пред да се стигне до `sendInvitation()`.
     */
    public function test_reinviting_a_disabled_user_is_refused_and_nothing_is_sent(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $client = $this->userWithRole('client', $company);
        $client->forceFill(['disabled_at' => now()])->save();

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('reinvite', $client->id)
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_a_client_cannot_reinvite_a_user(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $client = $this->userWithRole('client', $company);
        $other = $this->userWithRole('client', $company);

        Livewire::actingAs($client)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('reinvite', $other->id)
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_reinviting_a_user_in_another_company_does_not_reach_that_user(): void
    {
        Notification::fake();

        $mine = Company::factory()->create();
        $admin = $this->userWithRole('admin');
        $stranger = $this->userWithRole('client', Company::factory()->create());

        $outcome = $this->exceptionShapeOf(fn () => Livewire::actingAs($admin)
            ->test(CompanyUsers::class, ['company' => $mine])
            ->call('reinvite', $stranger->id));

        $this->assertSame(ModelNotFoundException::class, $outcome);
        Notification::assertNothingSent();
    }

    /**
     * Наод #3: откако #1 е поправен, `disable`/`enable`/`reinvite` мора да
     * се согласуваат по обликот на одбивање кога ID-то е од друга фирма —
     * сите три фрлаат `ModelNotFoundException` (опсегот по фирма во
     * companyUser() не го наоѓа), никој не смее да врати поинаков облик.
     */
    public function test_disable_enable_and_reinvite_agree_on_the_shape_of_an_out_of_company_id(): void
    {
        $mine = Company::factory()->create();
        $admin = $this->userWithRole('admin');
        $stranger = $this->userWithRole('client', Company::factory()->create());

        $shapes = [
            'disable' => $this->exceptionShapeOf(fn () => Livewire::actingAs($admin)
                ->test(CompanyUsers::class, ['company' => $mine])
                ->call('disable', $stranger->id)),
            'enable' => $this->exceptionShapeOf(fn () => Livewire::actingAs($admin)
                ->test(CompanyUsers::class, ['company' => $mine])
                ->call('enable', $stranger->id)),
            'reinvite' => $this->exceptionShapeOf(fn () => Livewire::actingAs($admin)
                ->test(CompanyUsers::class, ['company' => $mine])
                ->call('reinvite', $stranger->id)),
        ];

        $this->assertSame(
            ['disable' => ModelNotFoundException::class, 'enable' => ModelNotFoundException::class, 'reinvite' => ModelNotFoundException::class],
            $shapes,
        );
    }

    /**
     * `disable`/`enable`/`reinvite` фрлаат `ModelNotFoundException` за ID
     * надвор од фирмата — Livewire во тест не го рендира тоа исклучение како
     * обичен HTTP одговор (само `HttpException`/`AuthorizationException`
     * минуваат нормално), туку го проследува сурово. Затоа исходот овде се
     * чита преку класата на исклучението, а не преку assertForbidden/assertNotFound.
     */
    private function exceptionShapeOf(\Closure $callback): string
    {
        try {
            $callback();

            return 'no-exception';
        } catch (\Throwable $e) {
            return get_class($e);
        }
    }
}
