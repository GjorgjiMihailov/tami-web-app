<?php

namespace Tests\Feature;

use App\Livewire\CompanyIndex;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    private function actAsAdmin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
    }

    public function test_only_an_admin_may_open_the_companies_screen(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $this->actingAs($client)->get(route('companies.index'))->assertForbidden();
        $this->actingAs($accountant)->get(route('companies.index'))->assertForbidden();
    }

    public function test_admin_sees_all_companies(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Company::factory()->create(['name' => 'Alpha Ltd']);
        Company::factory()->create(['name' => 'Beta Ltd']);

        $this->actingAs($admin);

        Livewire::test(CompanyIndex::class)
            ->assertSee('Alpha Ltd')
            ->assertSee('Beta Ltd');
    }

    // The two per-role list-filtering tests that stood here are gone: Фирми is
    // an admin-only screen now, so a client or accountant never sees a filtered
    // list — they are refused outright. test_only_an_admin_may_open_the_companies_screen
    // above is the replacement, and the accountant's own multi-company chooser
    // is covered by DashboardTest.

    public function test_the_route_requires_authentication(): void
    {
        $this->get('/companies')->assertRedirect('/login');
    }

    public function test_the_companies_page_renders_successfully_over_http(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get('/companies')
            ->assertOk()
            ->assertSee('Фирми');
    }

    public function test_admin_can_add_a_company(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(CompanyIndex::class)
            ->set('newName', 'New Client DOO')
            ->set('newType', 'legal')
            ->set('newTaxId', '4012345678901')
            ->set('newEmail', 'contact@newclient.mk')
            ->call('addCompany')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('companies', [
            'name' => 'New Client DOO',
            'tax_id' => '4012345678901',
            'email' => 'contact@newclient.mk',
        ]);
    }

    public function test_adding_a_company_requires_a_name(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(CompanyIndex::class)
            ->set('newName', '')
            ->call('addCompany')
            ->assertHasErrors(['newName' => 'required']);
    }

    public function test_client_cannot_add_a_company(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        // Refused at mount now — a client cannot even open the screen, let
        // alone submit the form.
        Livewire::test(CompanyIndex::class)->assertForbidden();

        $this->assertDatabaseMissing('companies', ['name' => 'Sneaky DOO']);
    }

    public function test_add_company_form_is_not_shown_to_non_admins(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        // The whole screen is refused to a non-admin, which subsumes hiding
        // the form on it.
        Livewire::test(CompanyIndex::class)->assertForbidden();
    }

    public function test_the_companies_list_no_longer_shows_per_company_module_links(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['name' => 'Alpha Ltd']);

        $this->actingAs($admin);

        Livewire::test(CompanyIndex::class)
            ->assertDontSeeHtml(route('accounting.accounts.index', $company))
            ->assertDontSeeHtml(route('inventory.warehouses.index', $company))
            ->assertDontSeeHtml(route('sales-invoices.index', $company))
            ->assertDontSeeHtml(route('inventory.stock-movements.create', [$company, 'receipt']));
    }

    /**
     * Миграцијата (2026_08_21_100000_add_type_to_companies_table) го поставува
     * default-от на колоната `type` на 'legal'. Тој default е безбеден само
     * затоа што оваа форма секогаш испраќа изречно избран тип во
     * Company::create() — никогаш не остава Eloquent да не го спомене полето
     * и да падне на default-от од базата. Овој тест токму тоа го докажува:
     * избраниот тип ('individual') завршува зачуван, не default-от ('legal').
     */
    public function test_a_new_profile_records_the_chosen_type_not_the_database_default(): void
    {
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newName', 'Марко Марковски')
            ->set('newType', 'individual')
            ->call('addCompany')
            ->assertHasNoErrors();

        $type = Company::where('name', 'Марко Марковски')->first()->type;

        $this->assertSame(\App\Support\CompanyType::INDIVIDUAL, $type);
        $this->assertNotSame(\App\Support\CompanyType::LEGAL, $type);
    }

    /**
     * И 'required', и Rule::enum(CompanyType::class) сами по себе го отфрлаат
     * празниот string: CompanyType::tryFrom('') враќа null, па Enum-правилото
     * веќе го отфрла празниот избор без 'required'. Овој тест не изолира кое
     * од двете правила е причина за грешката — тоа не е ниту целта. Целта е
     * да докаже дека формата не може да заврши без избран тип, без разлика
     * кое правило застанува на пат.
     */
    public function test_the_form_refuses_to_complete_with_no_type_chosen(): void
    {
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newName', 'ТЕСТ ДООЕЛ')
            ->set('newType', '')
            ->call('addCompany')
            ->assertHasErrors('newType');

        $this->assertDatabaseMissing('companies', ['name' => 'ТЕСТ ДООЕЛ']);
    }

    public function test_an_unknown_type_is_rejected(): void
    {
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newName', 'ТЕСТ ДООЕЛ')
            ->set('newType', 'something-else')
            ->call('addCompany')
            ->assertHasErrors('newType');
    }
}
