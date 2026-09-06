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

    /**
     * Регресија (наод I1): исто како кај CompanyUsers — покана за исклучена
     * канцелариска сметка `UserInvitations::accept()` секогаш ја одбива, па
     * `invite` мора да пропадне пред да се испрати нова.
     */
    public function test_reinviting_a_disabled_office_account_is_refused_and_nothing_is_sent(): void
    {
        Notification::fake();

        $accountant = $this->userWithRole('accountant');
        $accountant->forceFill(['disabled_at' => now()])->save();

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(OfficeUsers::class)
            ->call('reinvite', $accountant->id)
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    /**
     * Истото правило како кај CompanyUsers: админ не смее да си го одземе
     * сопствениот пристап. Тука officeUser() го опфаќа и самиот актер (тој е
     * 'admin', а опсегот е ['accountant', 'admin']), па findOrFail никогаш не
     * фрла пред UserPolicy::disable да пресуди — резултатот е чисто 403, не
     * 404.
     */
    public function test_an_admin_cannot_disable_their_own_account(): void
    {
        $admin = $this->userWithRole('admin');

        Livewire::actingAs($admin)
            ->test(OfficeUsers::class)
            ->call('disable', $admin->id)
            ->assertForbidden();

        $this->assertNull($admin->fresh()->disabled_at);
    }
}
