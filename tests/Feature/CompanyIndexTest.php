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

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
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
            ->call('addCompany')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('companies', [
            'name' => 'New Client DOO',
            'tax_id' => '4012345678901',
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

    /**
     * Физичко лице нема ЕДБ, па не е во ДДВ систем — види ја табелата
     * „Полиња по тип" во
     * docs/superpowers/specs/2026-08-21-client-profile-types-design.md.
     *
     * Колоната `is_vat_registered` има default `true` во базата (миграцијата
     * 2026_07_20_100000_add_invoicing_fields_to_companies_table), а формата за
     * уредување на профил ја запишува само за правно лице. Значи ако оваа
     * форма не ја постави изречно при создавање, физичкото лице засекогаш
     * останува ДДВ обврзник — нема екран во апликацијата што тоа може да го
     * поправи, а бројката оди право на фактурата што ја добива закупецот.
     *
     * Затоа тестот мора да оди низ формата. Фабрика на која ѝ се предава
     * 'is_vat_registered' => false докажува состојба што продукцијата никогаш
     * ја нема.
     */
    public function test_a_new_individual_profile_is_not_in_the_vat_system(): void
    {
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newName', 'Марко Марковски')
            ->set('newType', 'individual')
            ->call('addCompany')
            ->assertHasNoErrors();

        $this->assertFalse(Company::where('name', 'Марко Марковски')->first()->is_vat_registered);
    }

    public function test_a_new_legal_profile_stays_in_the_vat_system(): void
    {
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newName', 'ТЕСТ ДООЕЛ')
            ->set('newType', 'legal')
            ->call('addCompany')
            ->assertHasNoErrors();

        $this->assertTrue(Company::where('name', 'ТЕСТ ДООЕЛ')->first()->is_vat_registered);
    }

    /**
     * Режимот на е-Фактура акредитиви е NOT NULL колона со default 'firm'.
     * За физичко лице тој поим не важи (е-Фактура бара ЕДБ), но вредноста
     * сепак се запишува изречно за да не зависи ниту еден профил од default
     * на базата. Она што мора да важи е дека новосоздадено физичко лице нема
     * пристап до е-Фактура.
     */
    public function test_a_new_individual_profile_has_no_efaktura_access(): void
    {
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newName', 'Ана Анастасова')
            ->set('newType', 'individual')
            ->call('addCompany')
            ->assertHasNoErrors();

        $company = Company::where('name', 'Ана Анастасова')->first();

        $this->assertFalse($company->hasEfakturaAccess());
        $this->assertSame(Company::EFAKTURA_STATUS_NONE, $company->efaktura_firm_access_status);
    }

    public function test_the_form_asks_an_individual_for_an_embg_and_not_for_an_edb(): void
    {
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newType', 'individual')
            ->assertSee('ЕМБГ')
            ->assertDontSee('ЕДБ');
    }

    public function test_the_form_asks_a_company_for_an_edb_and_not_for_an_embg(): void
    {
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newType', 'legal')
            ->assertSee('ЕДБ')
            ->assertDontSee('ЕМБГ');
    }

    public function test_a_new_individual_profile_stores_its_embg(): void
    {
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newName', 'Марко Марковски')
            ->set('newType', 'individual')
            ->set('newEmbg', '3101980455019')
            ->call('addCompany')
            ->assertHasNoErrors();

        $company = Company::where('name', 'Марко Марковски')->first();

        $this->assertSame('3101980455019', $company->embg);
        $this->assertNull($company->tax_id);
    }

    public function test_an_invalid_embg_is_refused_when_adding_an_individual(): void
    {
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newName', 'Марко Марковски')
            ->set('newType', 'individual')
            ->set('newEmbg', '1234567890123')
            ->call('addCompany')
            ->assertHasErrors('newEmbg');

        $this->assertDatabaseMissing('companies', ['name' => 'Марко Марковски']);
    }

    /**
     * Формата се менува кога ќе се смени типот, но веќе внесената вредност
     * останува во компонентата. ЕДБ впишан пред да се избере „физичко лице" не
     * смее да заврши на профилот: полето ЕДБ потоа е скриено во формата за
     * уредување, па таква вредност никогаш не би можела да се поправи или
     * избрише.
     */
    public function test_an_edb_typed_before_choosing_individual_never_lands_on_the_profile(): void
    {
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newName', 'Марко Марковски')
            ->set('newType', 'legal')
            ->set('newTaxId', '4012345678901')
            ->set('newType', 'individual')
            ->call('addCompany')
            ->assertHasNoErrors();

        $this->assertNull(Company::where('name', 'Марко Марковски')->first()->tax_id);
    }

    public function test_an_embg_typed_before_choosing_a_company_never_lands_on_the_profile(): void
    {
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newName', 'ТЕСТ ДООЕЛ')
            ->set('newType', 'individual')
            ->set('newEmbg', '3101980455019')
            ->set('newType', 'legal')
            ->call('addCompany')
            ->assertHasNoErrors();

        $this->assertNull(Company::where('name', 'ТЕСТ ДООЕЛ')->first()->embg);
    }

    public function test_a_new_company_is_created_with_the_ticked_modules(): void
    {
        // Формата веќе не штиклира модули (тие се преселени на картичката
        // „Модули" на профилот) — сите модули излегуваат вклучени по
        // создавање, без разлика на типот.
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newType', \App\Support\CompanyType::LEGAL->value)
            ->set('newName', 'Тест ДООЕЛ')
            ->call('addCompany')
            ->assertHasNoErrors();

        $company = Company::where('name', 'Тест ДООЕЛ')->sole();

        $this->assertTrue($company->uses_material);
        $this->assertTrue($company->uses_finance);
        $this->assertTrue($company->uses_payroll);
        $this->assertTrue($company->uses_stock);
    }

    public function test_stock_is_written_off_when_material_is_not_ticked(): void
    {
        // Истата причина: штиклирањата за Материјално/Залиха ги нема веќе на
        // оваа форма, па нова фирма секогаш излегува со двете вклучени.
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newType', \App\Support\CompanyType::LEGAL->value)
            ->set('newName', 'Без материјално ДОО')
            ->call('addCompany')
            ->assertHasNoErrors();

        $company = Company::where('name', 'Без материјално ДОО')->sole();

        $this->assertTrue($company->uses_material);
        $this->assertTrue($company->uses_stock);
    }

    public function test_an_individual_profile_is_created_with_every_module_on(): void
    {
        // Кутиите не се појавуваат за физичко лице, па што и да останало во
        // компонентата од претходен избор не смее да го затвори профилот.
        $this->actAsAdmin();

        Livewire::test(CompanyIndex::class)
            ->set('newType', \App\Support\CompanyType::LEGAL->value)
            ->set('newType', \App\Support\CompanyType::INDIVIDUAL->value)
            ->set('newName', 'Петар Петров')
            ->call('addCompany')
            ->assertHasNoErrors();

        $company = Company::where('name', 'Петар Петров')->sole();

        $this->assertTrue($company->uses_material);
        $this->assertTrue($company->uses_stock);
        $this->assertTrue($company->uses_payroll);
        $this->assertTrue($company->uses_finance);
    }

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
}
