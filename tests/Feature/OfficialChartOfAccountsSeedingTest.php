<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Services\OfficialChartOfAccounts;
use Illuminate\Database\QueryException;
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

    public function test_seeding_rolls_back_completely_when_an_insert_fails_partway(): void
    {
        $company = Company::factory()->create();

        // Wipe the auto-seeded accounts and leave a single account behind whose
        // code will collide with the first row of the official chart, forcing
        // seedForCompany() to fail partway through the loop.
        Account::where('company_id', $company->id)->delete();

        Account::create([
            'company_id' => $company->id,
            'code' => '000',
            'name' => 'Pre-existing colliding account',
            'parent_code' => null,
            'is_analytical' => false,
            'is_active' => true,
        ]);

        try {
            OfficialChartOfAccounts::seedForCompany($company);
            $this->fail('Expected a QueryException due to the duplicate account code.');
        } catch (QueryException $e) {
            // Expected: the unique constraint on (company_id, code) collides on '000'.
        }

        $this->assertSame(1, Account::where('company_id', $company->id)->count());
    }
}
