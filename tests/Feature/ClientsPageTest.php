<?php

namespace Tests\Feature;

use App\Livewire\ClientCreate;
use App\Livewire\ClientIndex;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'internal_client', 'freelancer_client'] as $role) {
            Role::findOrCreate($role);
        }
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_the_admin_menu_is_home_clients_and_settings_only(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->get(route('dashboard'))
            ->assertSeeHtml(route('clients.index'))
            ->assertSeeHtml(route('profile'))
            ->assertDontSeeHtml(route('companies.index'))
            ->assertDontSeeHtml(route('companies.office'))
            ->assertDontSeeHtml(route('form743.worklist'))
            ->assertDontSeeHtml(route('efaktura.pending'));
    }

    public function test_the_list_shows_accountants_with_their_clients_and_clients_with_their_accountant(): void
    {
        $accountant = $this->userWithRole('accountant');
        $accountant->update(['name' => 'Ana Sметководител']);
        $company = Company::factory()->create(['name' => 'Alpha Ltd']);
        $company->accountants()->attach($accountant);
        $lonely = Company::factory()->create(['name' => 'Lonely Ltd']);
        // Само фирма со своја сметка е клиент на порталот.
        foreach ([$company, $lonely] as $c) {
            User::factory()->create(['company_id' => $c->id])->assignRole('internal_client');
        }

        $this->actingAs($this->userWithRole('admin'));

        Livewire::test(ClientIndex::class)
            ->assertSee('Сметководител')
            ->assertSee('Работи за:')
            ->assertSee('Alpha Ltd')
            ->assertSee('Го води: Ana Sметководител')
            ->assertSee('Без сметководител')
            ->assertSee('Нов сметководител')
            ->assertSee('Ново правно лице')
            ->assertSee('Ново физичко лице');
    }

    public function test_a_company_without_its_own_account_is_not_a_portal_client(): void
    {
        $accountant = $this->userWithRole('accountant');
        $worked = Company::factory()->create(['name' => 'Worked Ltd']);
        $worked->accountants()->attach($accountant);

        $this->actingAs($this->userWithRole('admin'));

        // Се гледа само под сметководителот, не како посебен клиент.
        Livewire::test(ClientIndex::class)
            ->assertSee('Worked Ltd')
            ->assertDontSee('Без сметководител')
            ->assertDontSee('Го води:');
    }

    public function test_an_accountant_can_be_created_with_an_optional_firm_name(): void
    {
        $this->actingAs($this->userWithRole('admin'));

        Livewire::test(ClientCreate::class, ['kind' => 'smetkovoditel'])
            ->set('name', 'Ana Anevska')
            ->set('firmName', 'Ana Consulting DOO')
            ->set('email', 'ana@firm.test')
            ->call('save')
            ->assertHasNoErrors();

        $accountant = User::where('email', 'ana@firm.test')->firstOrFail();
        $this->assertSame('Ana Consulting DOO', $accountant->firm_name);
        $this->assertSame(0, Company::count());

        Livewire::test(ClientCreate::class, ['kind' => 'smetkovoditel'])
            ->set('name', 'Bojan Bojanov')
            ->set('email', 'bojan@firm.test')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(User::where('email', 'bojan@firm.test')->firstOrFail()->firm_name);

        Livewire::test(ClientIndex::class)->assertSee('Ana Consulting DOO');
    }

    public function test_only_the_admin_may_open_the_pages(): void
    {
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->get(route('clients.index'))->assertForbidden();
        $this->actingAs($accountant)->get(route('clients.create', 'pravno-lice'))->assertForbidden();
        $this->actingAs($this->userWithRole('admin'))->get(route('clients.create', 'nesto'))->assertNotFound();
    }

    public function test_creating_a_legal_client_makes_company_login_and_assignment_in_one_step(): void
    {
        $accountant = $this->userWithRole('accountant');
        $this->actingAs($this->userWithRole('admin'));

        Livewire::test(ClientCreate::class, ['kind' => 'pravno-lice'])
            ->set('name', 'Beta DOO')
            ->set('taxId', '4030000000000')
            ->set('contactName', 'Petar Petrov')
            ->set('email', 'petar@beta.test')
            ->set('accountantId', (string) $accountant->id)
            ->call('save')
            ->assertHasNoErrors();

        $company = Company::where('name', 'Beta DOO')->firstOrFail();
        $this->assertSame(CompanyType::LEGAL, $company->type);
        $this->assertTrue($company->accountants->contains($accountant));

        $user = User::where('email', 'petar@beta.test')->firstOrFail();
        $this->assertSame($company->id, $user->company_id);
        $this->assertTrue($user->hasRole('internal_client'));
        $this->assertSame('Petar Petrov', $user->name);
        $this->assertNotNull($user->latestInvitation);
    }

    public function test_creating_an_individual_needs_no_accountant_and_uses_one_name(): void
    {
        $this->actingAs($this->userWithRole('admin'));

        Livewire::test(ClientCreate::class, ['kind' => 'fizicko-lice'])
            ->set('name', 'Marija Petrova')
            ->set('email', 'marija@test.test')
            ->call('save')
            ->assertHasNoErrors();

        $company = Company::where('name', 'Marija Petrova')->firstOrFail();
        $this->assertSame(CompanyType::INDIVIDUAL, $company->type);
        $this->assertCount(0, $company->accountants);

        $user = User::where('email', 'marija@test.test')->firstOrFail();
        $this->assertTrue($user->hasRole('freelancer_client'));
        $this->assertSame('Marija Petrova', $user->name);
    }

    public function test_creating_an_accountant(): void
    {
        $this->actingAs($this->userWithRole('admin'));

        Livewire::test(ClientCreate::class, ['kind' => 'smetkovoditel'])
            ->set('name', 'Nov Smetkovoditel')
            ->set('email', 'nov@test.test')
            ->call('save')
            ->assertHasNoErrors();

        $user = User::where('email', 'nov@test.test')->firstOrFail();
        $this->assertTrue($user->hasRole('accountant'));
        $this->assertNull($user->company_id);
    }

    public function test_a_duplicate_email_creates_nothing(): void
    {
        User::factory()->create(['email' => 'taken@test.test']);
        $this->actingAs($this->userWithRole('admin'));

        Livewire::test(ClientCreate::class, ['kind' => 'fizicko-lice'])
            ->set('name', 'Dupe Person')
            ->set('email', 'taken@test.test')
            ->call('save')
            ->assertHasErrors(['email']);

        $this->assertDatabaseMissing('companies', ['name' => 'Dupe Person']);
    }

    public function test_an_accountant_cannot_call_save_directly(): void
    {
        $this->actingAs($this->userWithRole('accountant'));

        Livewire::test(ClientCreate::class, ['kind' => 'smetkovoditel'])->assertForbidden();
    }
}
