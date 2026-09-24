<?php

namespace Tests\Feature;

use App\Livewire\FirstClient;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FirstClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
    }

    private function accountantWithoutCompanies(): User
    {
        $user = User::factory()->create();
        $user->assignRole('accountant');

        return $user;
    }

    public function test_an_accountant_without_companies_lands_here_on_login(): void
    {
        $this->actingAs($this->accountantWithoutCompanies())
            ->get(route('dashboard'))
            ->assertRedirect(route('onboarding.first-client'));
    }

    public function test_the_screen_opens_and_names_what_it_wants(): void
    {
        $this->actingAs($this->accountantWithoutCompanies())
            ->get(route('onboarding.first-client'))
            ->assertOk()
            ->assertSee('прв клиент', false);
    }

    public function test_no_blade_component_is_left_uncompiled(): void
    {
        // Livewire assertSee гледа во исчистен текст и не забележува ни
        // неисцртана компонента. Двапати веќе беше испорачан расипан екран
        // низ зелена серија.
        $this->actingAs($this->accountantWithoutCompanies())
            ->get(route('onboarding.first-client'))
            ->assertDontSee('<x-', false);
    }

    public function test_an_accountant_who_already_has_a_company_is_sent_away(): void
    {
        $accountant = $this->accountantWithoutCompanies();
        $company = Company::factory()->create();
        $company->accountants()->attach($accountant);

        $this->actingAs($accountant)
            ->get(route('onboarding.first-client'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_an_admin_is_sent_away_too(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('onboarding.first-client'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_saving_creates_the_company_and_attaches_the_accountant(): void
    {
        $accountant = $this->accountantWithoutCompanies();
        $this->actingAs($accountant);

        Livewire::test(FirstClient::class)
            ->set('name', 'ПРВ КЛИЕНТ ДООЕЛ')
            ->set('type', CompanyType::LEGAL->value)
            ->set('taxId', '4080012345678')
            ->set('email', 'k'.uniqid().'@klient.test')
            ->call('save')
            ->assertHasNoErrors();

        $company = Company::where('name', 'ПРВ КЛИЕНТ ДООЕЛ')->firstOrFail();

        $this->assertTrue($company->is_vat_registered);
        $this->assertTrue(
            $accountant->fresh()->visibleCompanies()->whereKey($company->id)->exists(),
            'Без закачување сметководителот се враќа на истиот екран во круг.'
        );
    }

    public function test_saving_creates_the_client_login_and_keeps_the_invite_link(): void
    {
        $this->actingAs($this->accountantWithoutCompanies());

        Livewire::test(FirstClient::class)
            ->set('name', 'ПРВ КЛИЕНТ ДООЕЛ')
            ->set('type', CompanyType::LEGAL->value)
            ->set('contactName', 'Петар Петров')
            ->set('email', 'petar@prv.test')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertSet('inviteLink', fn ($link) => filled($link));

        $company = Company::where('name', 'ПРВ КЛИЕНТ ДООЕЛ')->firstOrFail();
        $login = User::where('email', 'petar@prv.test')->firstOrFail();
        $this->assertSame($company->id, $login->company_id);
        $this->assertTrue($login->hasRole('internal_client'));
        $this->assertSame('Петар Петров', $login->name);
    }

    public function test_the_email_is_required(): void
    {
        $this->actingAs($this->accountantWithoutCompanies());

        Livewire::test(FirstClient::class)
            ->set('name', 'ПРВ КЛИЕНТ ДООЕЛ')
            ->set('type', CompanyType::LEGAL->value)
            ->call('save')
            ->assertHasErrors(['email' => 'required']);

        $this->assertDatabaseMissing('companies', ['name' => 'ПРВ КЛИЕНТ ДООЕЛ']);
    }

    public function test_an_individual_is_not_created_as_a_vat_payer(): void
    {
        $this->actingAs($this->accountantWithoutCompanies());

        Livewire::test(FirstClient::class)
            ->set('name', 'Петар Петров')
            ->set('type', CompanyType::INDIVIDUAL->value)
            ->set('embg', '0101990450006')
            ->set('email', 'k'.uniqid().'@klient.test')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(Company::where('name', 'Петар Петров')->firstOrFail()->is_vat_registered);
    }

    public function test_the_name_is_required(): void
    {
        $this->actingAs($this->accountantWithoutCompanies());

        Livewire::test(FirstClient::class)
            ->set('name', '')
            ->set('type', CompanyType::LEGAL->value)
            ->set('email', 'k'.uniqid().'@klient.test')
            ->call('save')
            ->assertHasErrors(['name' => 'required']);
    }

    public function test_an_embg_with_a_wrong_check_digit_is_refused(): void
    {
        // 3101980455019 е точен; последната цифра е сменета. Истиот пар се
        // користи и во tests/Unit/Support/EmbgTest.php.
        $this->actingAs($this->accountantWithoutCompanies());

        Livewire::test(FirstClient::class)
            ->set('name', 'Петар Петров')
            ->set('type', CompanyType::INDIVIDUAL->value)
            ->set('embg', '3101980455018')
            ->set('email', 'k'.uniqid().'@klient.test')
            ->call('save')
            ->assertHasErrors('embg');
    }

    public function test_the_route_requires_authentication(): void
    {
        $this->get(route('onboarding.first-client'))->assertRedirect(route('login'));
    }
}
