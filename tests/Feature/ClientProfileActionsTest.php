<?php

namespace Tests\Feature;

use App\Livewire\ClientAccountantShow;
use App\Livewire\ClientIndex;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientProfileActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'internal_client', 'freelancer_client'] as $role) {
            Role::findOrCreate($role);
        }
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function clientWithLogin(string $name = 'Alpha Ltd'): array
    {
        $company = Company::factory()->create(['name' => $name]);
        $login = $this->userWithRole('internal_client', ['company_id' => $company->id]);

        return [$company, $login];
    }

    public function test_admin_can_send_a_password_link_to_an_accountant_and_to_a_client(): void
    {
        $accountant = $this->userWithRole('accountant');
        [, $login] = $this->clientWithLogin();

        $component = Livewire::actingAs($this->userWithRole('admin'))->test(ClientIndex::class);

        $component->call('resetLink', $accountant->id)
            ->assertSet('invitedName', $accountant->name)
            ->assertSet('inviteLink', fn ($link) => filled($link));
        $this->assertNotNull($accountant->fresh()->latestInvitation);

        $component->call('resetLink', $login->id)->assertSet('invitedName', $login->name);
        $this->assertNotNull($login->fresh()->latestInvitation);
    }

    public function test_a_disabled_account_gets_no_link(): void
    {
        $accountant = $this->userWithRole('accountant', ['disabled_at' => now()]);

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(ClientIndex::class)
            ->call('resetLink', $accountant->id)
            ->assertSet('inviteLink', null)
            ->assertSet('profileError', 'Сметката е исклучена. Прво вклучи ја, па испрати линк.');
    }

    public function test_an_admin_account_cannot_be_targeted(): void
    {
        $otherAdmin = $this->userWithRole('admin');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(ClientIndex::class)
            ->call('resetLink', $otherAdmin->id);
    }

    public function test_only_the_admin_may_use_the_actions(): void
    {
        $accountant = $this->userWithRole('accountant');

        Livewire::actingAs($accountant)->test(ClientIndex::class)->assertForbidden();
        $this->assertNull($accountant->fresh()->latestInvitation);
    }

    public function test_deleting_needs_the_exact_name(): void
    {
        [$company] = $this->clientWithLogin('Alpha Ltd');

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(ClientIndex::class)
            ->call('requestDelete', 'company', $company->id)
            ->set('deleteConfirmation', 'alpha')
            ->call('confirmDelete')
            ->assertHasErrors('deleteConfirmation');

        $this->assertNotNull(Company::find($company->id));
    }

    public function test_deleting_a_company_removes_it_and_its_logins(): void
    {
        [$company, $login] = $this->clientWithLogin('Alpha Ltd');
        [$other, $otherLogin] = $this->clientWithLogin('Beta Ltd');

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(ClientIndex::class)
            ->call('requestDelete', 'company', $company->id)
            ->set('deleteConfirmation', 'Alpha Ltd')
            ->call('confirmDelete')
            ->assertHasNoErrors()
            ->assertSet('deleting', null);

        $this->assertNull(Company::find($company->id));
        $this->assertNull(User::find($login->id));
        $this->assertNotNull(Company::find($other->id));
        $this->assertNotNull(User::find($otherLogin->id));
    }

    public function test_deleting_an_accountant_keeps_the_firms_they_worked_on(): void
    {
        $accountant = $this->userWithRole('accountant', ['name' => 'Ana Anevska']);
        $company = Company::factory()->create();
        $company->accountants()->attach($accountant);

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(ClientIndex::class)
            ->call('requestDelete', 'accountant', $accountant->id)
            ->set('deleteConfirmation', 'Ana Anevska')
            ->call('confirmDelete');

        $this->assertNull(User::find($accountant->id));
        $this->assertNotNull(Company::find($company->id));
    }

    public function test_the_accountant_page_shows_details_and_their_firms(): void
    {
        $accountant = $this->userWithRole('accountant', ['name' => 'Ana Anevska', 'firm_name' => 'Ana Consulting DOO', 'email' => 'ana@firm.test']);
        $company = Company::factory()->create(['name' => 'Alpha Ltd']);
        $company->accountants()->attach($accountant);

        $this->actingAs($this->userWithRole('admin'))
            ->get(route('clients.accountant', $accountant))
            ->assertOk()
            ->assertSee('Ana Consulting DOO')
            ->assertSee('ana@firm.test')
            ->assertSee('Alpha Ltd');

        $this->actingAs($accountant)->get(route('clients.accountant', $accountant))->assertForbidden();
    }

    public function test_the_accountant_page_can_disable_and_delete(): void
    {
        $accountant = $this->userWithRole('accountant', ['name' => 'Ana Anevska']);

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(ClientAccountantShow::class, ['user' => $accountant])
            ->call('disableProfile', $accountant->id);
        $this->assertNotNull($accountant->fresh()->disabled_at);

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(ClientAccountantShow::class, ['user' => $accountant])
            ->call('requestDelete', 'accountant', $accountant->id)
            ->set('deleteConfirmation', 'Ana Anevska')
            ->call('confirmDelete')
            ->assertRedirect(route('clients.index'));

        $this->assertNull(User::find($accountant->id));
    }
}
