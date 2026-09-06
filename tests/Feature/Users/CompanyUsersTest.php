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
