<?php

namespace Tests\Feature;

use App\Livewire\Accounting\AccountIndex;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Services\OfficialChartOfAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Предложениот контен план на канцеларијата: фирмата сама одлучува кога да го
 * преземе. Пуштањето повеќе не го наметнува.
 */
class ChartAdoptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function outOfDate(Company $company): Company
    {
        $company->forceFill(['chart_version' => null])->saveQuietly();

        return $company->fresh();
    }

    public function test_a_new_company_is_already_on_the_current_suggested_chart(): void
    {
        $company = Company::factory()->create();

        $this->assertSame(OfficialChartOfAccounts::version(), $company->fresh()->chart_version);
        $this->assertTrue(OfficialChartOfAccounts::isCurrent($company->fresh()));
    }

    public function test_the_preview_counts_missing_and_changed_accounts(): void
    {
        $company = $this->outOfDate(Company::factory()->create());
        Account::where('company_id', $company->id)->where('code', '1000')->delete();
        Account::where('company_id', $company->id)->where('code', '120')->update(['name' => 'Друго име']);

        $this->assertSame(['added' => 1, 'changed' => 1], OfficialChartOfAccounts::preview($company));
    }

    public function test_the_preview_of_an_up_to_date_chart_is_empty(): void
    {
        $company = Company::factory()->create();

        $this->assertSame(['added' => 0, 'changed' => 0], OfficialChartOfAccounts::preview($company));
    }

    public function test_the_screen_offers_the_chart_only_when_the_company_is_behind(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        Livewire::test(AccountIndex::class, ['company' => $company])
            ->assertDontSee('Преземи го предложениот план');

        $company = $this->outOfDate($company);
        Account::where('company_id', $company->id)->where('code', '1000')->delete();

        Livewire::test(AccountIndex::class, ['company' => $company])
            ->assertSee('Преземи го предложениот план')
            ->assertSee('1 конто');
    }

    public function test_adopting_adds_updates_keeps_the_rest_and_marks_the_company_current(): void
    {
        $company = $this->outOfDate(Company::factory()->create());
        Account::where('company_id', $company->id)->where('code', '1000')->delete();
        Account::where('company_id', $company->id)->where('code', '120')->update(['name' => 'Друго име']);
        Account::where('company_id', $company->id)->where('code', '1020')->update(['is_active' => false]);
        Account::create([
            'company_id' => $company->id, 'code' => '1200999', 'name' => 'Мојата аналитика',
            'parent_code' => '1200', 'level' => Account::LEVEL_ACCOUNT, 'is_analytical' => true, 'is_active' => true,
        ]);
        $this->actingAs($this->admin());

        Livewire::test(AccountIndex::class, ['company' => $company])
            ->call('adoptSuggestedChart')
            ->assertDontSee('Преземи го предложениот план');

        $this->assertNotNull(Account::where('company_id', $company->id)->where('code', '1000')->first());
        $this->assertSame('Побарувања од купувачи во земјата', Account::where('company_id', $company->id)->where('code', '120')->value('name'));
        $this->assertFalse((bool) Account::where('company_id', $company->id)->where('code', '1020')->value('is_active'));
        $this->assertNotNull(Account::where('company_id', $company->id)->where('code', '1200999')->first());
        $this->assertTrue(OfficialChartOfAccounts::isCurrent($company->fresh()));
    }

    public function test_an_assigned_accountant_can_adopt(): void
    {
        $company = $this->outOfDate(Company::factory()->create());
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($company);
        $this->actingAs($accountant);

        Livewire::test(AccountIndex::class, ['company' => $company])->call('adoptSuggestedChart');

        $this->assertTrue(OfficialChartOfAccounts::isCurrent($company->fresh()));
    }

    public function test_a_client_cannot_adopt_and_does_not_see_the_offer(): void
    {
        $company = $this->outOfDate(Company::factory()->create());
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');
        $this->actingAs($client);

        Livewire::test(AccountIndex::class, ['company' => $company])
            ->assertDontSee('Преземи го предложениот план')
            ->call('adoptSuggestedChart')
            ->assertForbidden();

        $this->assertFalse(OfficialChartOfAccounts::isCurrent($company->fresh()));
    }

    public function test_the_manual_command_also_marks_companies_current(): void
    {
        $company = $this->outOfDate(Company::factory()->create());

        $this->artisan('accounts:sync-official')->assertSuccessful();

        $this->assertTrue(OfficialChartOfAccounts::isCurrent($company->fresh()));
    }

    public function test_deploy_no_longer_forces_the_chart_on_every_company(): void
    {
        $deploy = file_get_contents(base_path('.github/workflows/deploy.yml'));

        $this->assertStringNotContainsString('php artisan accounts:sync-official', $deploy);
    }
}
