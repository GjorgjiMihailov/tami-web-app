<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficialChartOfAccountsSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_company_seeds_the_full_official_chart_of_accounts(): void
    {
        $company = Company::factory()->create();

        $this->assertSame(428, Account::where('company_id', $company->id)->count());
    }

    public function test_seeded_accounts_are_synthetic_and_active_by_default(): void
    {
        $company = Company::factory()->create();

        $account = Account::where('company_id', $company->id)->where('code', '120')->first();

        $this->assertNotNull($account);
        $this->assertSame('Побарувања од купувачи во земјата', $account->name);
        $this->assertFalse($account->is_analytical);
        $this->assertTrue($account->is_active);
        $this->assertSame('1', $account->class);
        $this->assertSame('12', $account->group);
    }

    public function test_two_companies_each_get_their_own_independent_copy(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        Account::where('company_id', $companyA->id)->where('code', '120')->first()
            ->update(['is_active' => false]);

        $this->assertFalse(Account::where('company_id', $companyA->id)->where('code', '120')->first()->is_active);
        $this->assertTrue(Account::where('company_id', $companyB->id)->where('code', '120')->first()->is_active);
    }
}
